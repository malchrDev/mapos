<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Tenant_manager
 * ----------------------------------------------------------
 * Provisionamento e resolução de tenant (Fase 1 — multi-tenancy,
 * schema-per-tenant).
 *
 * O banco MESTRE (DB_MASTER_DATABASE) guarda `tenants`, `tenant_dominios` e
 * `tenant_usage`:
 * domínio -> schema, status, limites. Cada tenant tem seu próprio schema MySQL
 * no mesmo cluster; backup, restore e remoção total de dados (LGPD) são
 * operações de schema inteiro.
 *
 * Todas as conexões daqui são abertas pela própria biblioteca — uma para o
 * mestre, uma por schema de tenant — a partir do grupo `default` de
 * config/database.php, trocando só o `database`. Nada depende de para onde o
 * `$CI->db` da requisição aponta: em uma requisição de tenant ele aponta para o
 * schema do tenant, e `tenants` não existe lá.
 *
 * O nome do schema é gerado aqui no provisionamento e gravado em `tenants`;
 * nunca é lido de entrada do usuário depois disso.
 */
class Tenant_manager
{
    /** @var object CodeIgniter instance */
    protected $CI;

    /** Prefixo do nome do schema de um tenant (evita colisões com outras bases). */
    const SCHEMA_PREFIX = 'mapos_';

    /** Migrations de negócio: rodam em cada schema de tenant (e na instalação única). */
    const MIGRATIONS_TENANT = 'database/migrations/';

    /** Migrations do banco mestre: só rodam no DB_MASTER_DATABASE. */
    const MIGRATIONS_MESTRE = 'database/migrations_mestre/';

    /** @var object|null Conexão com o banco mestre (aberta sob demanda). */
    protected $mestre;

    /** @var array|null Grupo `default` de config/database.php. */
    protected $configBase;

    public function __construct()
    {
        $this->CI = &get_instance();
    }

    /**
     * Nome do banco mestre, ou exceção se a multi-tenancy está desligada.
     */
    public static function bancoMestre(): string
    {
        $mestre = trim((string) ($_ENV['DB_MASTER_DATABASE'] ?? ''));
        if ($mestre === '') {
            throw new RuntimeException('DB_MASTER_DATABASE não está configurado: a multi-tenancy está desligada.');
        }

        return $mestre;
    }

    /**
     * Domínio-base dos subdomínios da plataforma (SAAS_DOMINIO_BASE), ou ''.
     */
    public static function dominioBase(): string
    {
        return strtolower(trim((string) ($_ENV['SAAS_DOMINIO_BASE'] ?? ''), " .\t\n\r\0\x0B"));
    }

    /**
     * Hostname do painel administrativo da plataforma (SAAS_PAINEL_HOST), ou ''.
     */
    public static function hostPainel(): string
    {
        return strtolower(trim((string) ($_ENV['SAAS_PAINEL_HOST'] ?? '')));
    }

    /**
     * Domínio em minúsculas e sem espaços, ou exceção se não for um hostname.
     */
    public static function normalizarDominio(string $dominio): string
    {
        $dominio = strtolower(trim($dominio));
        if ($dominio === '' || ! str_contains($dominio, '.') || filter_var($dominio, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException("Domínio inválido: {$dominio}.");
        }

        return $dominio;
    }

    /**
     * 'plataforma' para subdomínio de SAAS_DOMINIO_BASE, 'proprio' para o resto.
     */
    public static function tipoDominio(string $dominio): string
    {
        $base = self::dominioBase();

        return $base !== '' && str_ends_with($dominio, '.' . $base) ? 'plataforma' : 'proprio';
    }

    /**
     * Resolve o tenant ativo de um domínio verificado (padrão: hostname da
     * requisição). Retorna tenants.* + dominio, ou null.
     */
    public function resolverPorDominio(?string $dominio = null): ?array
    {
        if (! $dominio) {
            $dominio = $_SERVER['HTTP_HOST'] ?? '';
            $dominio = preg_replace('/:\d+$/', '', $dominio); // remove porta
        }

        $registro = $this->consultaTenantPorDominio(strtolower($dominio))
            ->where('d.verificado_em IS NOT NULL', null, false)
            ->where('t.status', 'ativo')
            ->get()
            ->row_array();

        return $registro ?: null;
    }

    /**
     * Tenant dono do domínio, em qualquer status e verificado ou não.
     */
    public function buscarPorDominio(string $dominio): ?array
    {
        $registro = $this->consultaTenantPorDominio(strtolower(trim($dominio)))->get()->row_array();

        return $registro ?: null;
    }

    /** @return object Query Builder do mestre já com o JOIN e o filtro de domínio */
    private function consultaTenantPorDominio(string $dominio)
    {
        return $this->mestre()
            ->select('t.*, d.dominio, d.tipo AS dominio_tipo, d.verificado_em')
            ->from('tenant_dominios d')
            ->join('tenants t', 't.id = d.tenant_id')
            ->where('d.dominio', $dominio);
    }

    /**
     * Domínios de um tenant.
     */
    public function dominios(int $tenantId): array
    {
        return $this->mestre()->where('tenant_id', $tenantId)->order_by('id')->get('tenant_dominios')->result_array();
    }

    /**
     * Cadastra um domínio próprio do cliente, ainda não verificado. Devolve o
     * registro TXT que o cliente precisa publicar para provar que é o dono.
     *
     * @return array{nome:string, valor:string}
     */
    public function adicionarDominio(int $tenantId, string $dominio): array
    {
        $dominio = self::normalizarDominio($dominio);
        $this->recusarDominioReservado($dominio);

        if (self::tipoDominio($dominio) === 'plataforma') {
            throw new InvalidArgumentException('Subdomínio da plataforma não é cadastrado como domínio próprio.');
        }
        if ($this->buscarPorDominio($dominio) !== null) {
            throw new RuntimeException("O domínio {$dominio} já está cadastrado.");
        }
        if (! $this->mestre()->where('id', $tenantId)->count_all_results('tenants')) {
            throw new RuntimeException("Tenant {$tenantId} não existe.");
        }

        $token = bin2hex(random_bytes(16));
        $ok = $this->mestre()->insert('tenant_dominios', [
            'tenant_id' => $tenantId,
            'dominio' => $dominio,
            'tipo' => 'proprio',
            'verificacao_token' => $token,
            'verificado_em' => null,
            'criado_em' => date('Y-m-d H:i:s'),
        ]);
        if (! $ok) {
            throw new RuntimeException('Não foi possível cadastrar o domínio: ' . ($this->mestre()->error()['message'] ?? ''));
        }

        return self::registroVerificacao($dominio, $token);
    }

    /**
     * Registro TXT que prova a posse de um domínio próprio.
     *
     * @return array{nome:string, valor:string}
     */
    public static function registroVerificacao(string $dominio, string $token): array
    {
        return ['nome' => '_mapos-verificacao.' . $dominio, 'valor' => 'mapos-verificacao=' . $token];
    }

    /**
     * Confere o TXT do domínio próprio e, se bater, marca como verificado — a
     * partir daí o hook passa a resolvê-lo.
     *
     * @param  callable|null  $consultarTxt  fn(string $nome): string[] (padrão: DNS real)
     */
    public function verificarDominio(string $dominio, ?callable $consultarTxt = null): bool
    {
        $dominio = self::normalizarDominio($dominio);
        $registro = $this->mestre()->where('dominio', $dominio)->get('tenant_dominios')->row_array();
        if (! $registro) {
            throw new RuntimeException("O domínio {$dominio} não está cadastrado.");
        }
        if ($registro['verificado_em'] !== null) {
            return true;
        }

        $consultarTxt ??= static function (string $nome): array {
            $registros = @dns_get_record($nome, DNS_TXT) ?: [];

            return array_map(static fn ($r) => (string) ($r['txt'] ?? ''), $registros);
        };

        $esperado = self::registroVerificacao($dominio, (string) $registro['verificacao_token']);
        if (! in_array($esperado['valor'], $consultarTxt($esperado['nome']), true)) {
            return false;
        }

        $this->mestre()->where('id', $registro['id'])->update('tenant_dominios', ['verificado_em' => date('Y-m-d H:i:s')]);
        $this->atualizarRotasTraefik();

        return true;
    }

    /**
     * Hostnames que nunca podem ser de um tenant.
     */
    private function recusarDominioReservado(string $dominio): void
    {
        $reservados = array_filter([self::hostPainel(), self::dominioBase()]);
        if (in_array($dominio, $reservados, true)) {
            throw new InvalidArgumentException("O domínio {$dominio} é reservado da plataforma.");
        }

        $base = self::dominioBase();
        if ($base === '' || ! str_ends_with($dominio, '.' . $base)) {
            return;
        }

        $nome = substr($dominio, 0, -strlen('.' . $base));
        if (str_contains($nome, '.')) {
            throw new InvalidArgumentException("Use um nome só antes de .{$base} (sem pontos): {$dominio}.");
        }
        if (in_array($nome, self::subdominiosReservados(), true)) {
            throw new InvalidArgumentException("O endereço {$dominio} é reservado (usado pela plataforma ou por outro sistema no mesmo domínio).");
        }
    }

    /** Nomes que nunca viram endereço de empresa, além dos de SAAS_SUBDOMINIOS_RESERVADOS. */
    public const SUBDOMINIOS_RESERVADOS = [
        'www', 'app', 'api', 'painel', 'admin', 'mail', 'smtp', 'imap', 'pop', 'webmail', 'ftp',
        'ns1', 'ns2', 'mx', 'status', 'cdn', 'static', 'assets', 'docs', 'blog', 'suporte', 'ajuda',
        'traefik', 'evolution', 'teste', 'staging', 'dev',
    ];

    /**
     * Subdomínios reservados: a lista fixa e os do .env (SAAS_SUBDOMINIOS_RESERVADOS,
     * separados por vírgula), para os nomes de outros sistemas no mesmo domínio.
     *
     * @return list<string>
     */
    public static function subdominiosReservados(): array
    {
        $extras = array_map(static fn ($n) => strtolower(trim($n)), explode(',', (string) ($_ENV['SAAS_SUBDOMINIOS_RESERVADOS'] ?? '')));

        return array_values(array_unique(array_merge(self::SUBDOMINIOS_RESERVADOS, array_filter($extras))));
    }

    /**
     * Todos os tenants registrados (qualquer status), para manutenção em lote.
     */
    public function listar(): array
    {
        if (! $this->mestre()->table_exists('tenants')) {
            throw new RuntimeException('O banco mestre ainda não tem as tabelas: rode php index.php tools tenant_mestre.');
        }

        return $this->mestre()->order_by('id')->get('tenants')->result_array();
    }

    /** Status que um tenant pode ter. */
    const STATUS = ['ativo', 'suspenso', 'cancelado'];

    /**
     * Tenants com o domínio de plataforma e a contagem de domínios, para a
     * listagem do painel. Busca por nome, documento ou qualquer domínio.
     *
     * @return list<array>
     */
    public function listarParaPainel(string $busca = '', ?string $status = null): array
    {
        $mestre = $this->mestre();
        $comPlano = $mestre->table_exists('planos');
        $mestre->select('t.id, t.nome, t.documento, t.`schema`, t.status, t.criado_em, t.data_expiracao,'
            . " MAX(CASE WHEN d.tipo = 'plataforma' THEN d.dominio END) AS dominio,"
            . ($comPlano ? ' MAX(p.nome) AS plano,' : ' NULL AS plano,')
            . ' COUNT(d.id) AS total_dominios', false)
            ->from('tenants t')
            ->join('tenant_dominios d', 'd.tenant_id = t.id', 'left');
        if ($comPlano) {
            $mestre->join('planos p', 'p.id = t.plano_id', 'left');
        }
        $mestre->group_by('t.id')->order_by('t.id', 'DESC');

        if ($status !== null && in_array($status, self::STATUS, true)) {
            $mestre->where('t.status', $status);
        }

        $busca = trim($busca);
        if ($busca !== '') {
            $mestre->group_start()
                ->like('t.nome', $busca)
                ->or_like('t.documento', $busca)
                ->or_where('t.id IN (SELECT tenant_id FROM tenant_dominios WHERE dominio LIKE ' . $mestre->escape('%' . $mestre->escape_like_str($busca) . '%') . " ESCAPE '!')", null, false)
                ->group_end();
        }

        return $mestre->get()->result_array();
    }

    /**
     * Um tenant pelo id (qualquer status), ou null.
     */
    public function buscarPorId(int $id): ?array
    {
        $registro = $this->mestre()->where('id', $id)->get('tenants')->row_array();

        return $registro ?: null;
    }

    /**
     * Muda o status do tenant. Suspenso e cancelado deixam de resolver no hook
     * na próxima requisição; o schema e os dados ficam intactos.
     */
    public function alterarStatus(int $id, string $status): void
    {
        if (! in_array($status, self::STATUS, true)) {
            throw new InvalidArgumentException("Status inválido: {$status}.");
        }
        if ($this->buscarPorId($id) === null) {
            throw new RuntimeException("Tenant {$id} não existe.");
        }

        $dados = ['status' => $status, 'atualizado_em' => date('Y-m-d H:i:s')];
        if ($this->mestre()->field_exists('cancelado_em', 'tenants')) {
            $dados['cancelado_em'] = $status === 'cancelado' ? date('Y-m-d H:i:s') : null;
        }
        $this->mestre()->where('id', $id)->update('tenants', $dados);
    }

    /**
     * Usuários do painel do tenant (do schema dele), sem senha nem segredos.
     *
     * @return list<array{idUsuarios:int, nome:string, email:string, situacao:int, perfil:?string}>
     */
    public function usuariosDoTenant(int $id): array
    {
        $tenant = $this->buscarPorId($id);
        if ($tenant === null) {
            throw new RuntimeException("Tenant {$id} não existe.");
        }

        $db = $this->conexao($tenant['schema']);
        try {
            $linhas = $db->select('u.idUsuarios, u.nome, u.email, u.situacao, p.nome AS perfil')
                ->from('usuarios u')
                ->join('permissoes p', 'p.idPermissao = u.permissoes_id', 'left')
                ->order_by('u.idUsuarios')
                ->get()
                ->result_array();
        } finally {
            $db->close();
        }

        return array_map(static fn ($l) => [
            'idUsuarios' => (int) $l['idUsuarios'],
            'nome' => (string) $l['nome'],
            'email' => (string) $l['email'],
            'situacao' => (int) $l['situacao'],
            'perfil' => $l['perfil'] !== null ? (string) $l['perfil'] : null,
        ], $linhas);
    }

    /**
     * Senha nova e aleatória para um usuário do tenant; devolve a senha. As
     * sessões e os tokens da API abertos por ele deixam de valer.
     */
    public function redefinirSenhaUsuario(int $id, int $idUsuario): string
    {
        $tenant = $this->buscarPorId($id);
        if ($tenant === null) {
            throw new RuntimeException("Tenant {$id} não existe.");
        }

        $senha = self::gerarSenha();
        $db = $this->conexao($tenant['schema']);
        try {
            if (! $db->where('idUsuarios', $idUsuario)->count_all_results('usuarios')) {
                throw new RuntimeException('Usuário não encontrado neste tenant.');
            }

            $db->set('senha', password_hash($senha, PASSWORD_DEFAULT));
            foreach (['sessao_versao', 'token_versao'] as $versao) {
                if ($db->field_exists($versao, 'usuarios')) {
                    $db->set($versao, $versao . ' + 1', false);
                }
            }
            if (! $db->where('idUsuarios', $idUsuario)->update('usuarios')) {
                throw new RuntimeException('Não foi possível trocar a senha: ' . ($db->error()['message'] ?? ''));
            }
        } finally {
            $db->close();
        }

        return $senha;
    }

    /**
     * Conexão com o banco mestre (CLI e painel usam para as contas da
     * plataforma).
     */
    public function conexaoMestre()
    {
        return $this->mestre();
    }

    /**
     * Verifica se um schema já existe no cluster.
     */
    public function schemaExiste(string $schema): bool
    {
        $res = $this->mestre()->query(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$schema]
        );

        return $res !== false && $res->num_rows() > 0;
    }

    /**
     * Base legível do nome do schema, derivada do domínio (só [a-z0-9]).
     *
     * Não é único: `a-b.com` e `ab.com` dão a mesma base. O nome definitivo é
     * o de gerarNomeSchema(), que acrescenta um sufixo aleatório.
     */
    public static function nomeSchema(string $dominio): string
    {
        $base = preg_replace('/[^a-z0-9]/', '', strtolower($dominio));

        return self::SCHEMA_PREFIX . substr($base, 0, 50);
    }

    /**
     * Nome definitivo do schema de um tenant novo: base do domínio + sufixo
     * aleatório. Dois domínios que sanitizam para a mesma base nunca caem no
     * mesmo schema — e criarSchema() recusa um schema que já existe.
     */
    public static function gerarNomeSchema(string $dominio): string
    {
        $base = substr(self::nomeSchema($dominio), strlen(self::SCHEMA_PREFIX), 43);
        if ($base === '') {
            $base = 'tenant';
        }

        return self::SCHEMA_PREFIX . $base . '_' . bin2hex(random_bytes(3));
    }

    /**
     * Cria o schema vazio do tenant no cluster (sem tabelas).
     *
     * Um schema que já existe é erro, não "nada a fazer": seguir em frente
     * entregaria a um tenant novo os dados de outro.
     */
    public function criarSchema(string $schema): void
    {
        $this->validarNomeSchema($schema);

        if ($this->schemaExiste($schema)) {
            throw new RuntimeException("O schema {$schema} já existe; provisionamento recusado.");
        }

        $mestre = $this->mestre();
        $this->executar($mestre, 'CREATE DATABASE ' . $mestre->protect_identifiers($schema)
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /**
     * Remove o schema do tenant do cluster (LGPD: direito ao esquecimento total
     * da empresa).
     */
    public function excluirSchema(string $schema): void
    {
        $this->validarNomeSchema($schema);

        if (! $this->schemaExiste($schema)) {
            return;
        }

        $mestre = $this->mestre();
        $this->executar($mestre, 'DROP DATABASE ' . $mestre->protect_identifiers($schema));
    }

    /**
     * Nome de schema entra em SQL cru (CREATE/DROP DATABASE não aceita binding),
     * então a validação é o que separa um nome legítimo de um ataque.
     *
     * Só letras minúsculas, dígitos e underscore, com o prefixo do tenant: nada
     * de backtick, espaço, ponto, hífen ou nome de banco alheio.
     *
     * A regex sozinha não basta: `mapos_padrao` e `mapos_master` casariam com
     * ela, e `excluirSchema()` roda `DROP DATABASE`. Os bancos que o Map-OS já
     * usa — o de trabalho (DB_DATABASE) e o mestre (DB_MASTER_DATABASE), além
     * dos nomes padrão da documentação — são barrados explicitamente.
     */
    private function validarNomeSchema(string $schema): void
    {
        $formatoValido = (bool) preg_match('/^' . preg_quote(self::SCHEMA_PREFIX, '/') . '[a-z0-9_]{1,50}$/', $schema);

        $reservados = array_filter([
            'mapos_padrao',
            'mapos_master',
            strtolower(trim((string) ($_ENV['DB_DATABASE'] ?? ''))),
            strtolower(trim((string) ($_ENV['DB_MASTER_DATABASE'] ?? ''))),
        ]);

        if (! $formatoValido || in_array($schema, $reservados, true)) {
            throw new InvalidArgumentException('Nome de schema inválido.');
        }
    }

    /**
     * Provisiona um tenant novo: cria o schema, roda as migrations dentro dele,
     * grava o registro no banco mestre e devolve o id.
     *
     * A ordem é do mais barato para o mais caro de desfazer: primeiro o schema e
     * as migrations (se algo falhar aqui, só se dropa um schema que ainda não tem
     * registro no mestre), e só então o INSERT em tenants — sem o qual nenhuma
     * requisição resolve o tenant, então o schema órfão não vaza acesso.
     *
     * O domínio informado é o de plataforma (`loja.SAAS_DOMINIO_BASE`) e nasce
     * verificado; domínios próprios entram depois, por adicionarDominio().
     *
     * O admin do tenant nasce com o e-mail do pedido e uma senha aleatória, que
     * só existe no retorno — o chamador a entrega (e-mail de boas-vindas na
     * Fase 5; na tela do CLI enquanto isso).
     *
     * @param  array{nome:string, dominio:string, admin_email:string, admin_nome?:string, documento?:string, limites?:array, data_expiracao?:string, plano_id?:int|null}  $dados
     * @return array{id:int, schema:string, admin_email:string, admin_senha:string}
     */
    public function provisionar(array $dados): array
    {
        $dominio = self::normalizarDominio($dados['dominio']);
        $this->recusarDominioReservado($dominio);
        if (self::dominioBase() !== '' && self::tipoDominio($dominio) !== 'plataforma') {
            throw new InvalidArgumentException('O domínio do provisionamento precisa ser um subdomínio de ' . self::dominioBase() . '; domínio próprio entra depois, com verificação.');
        }
        if (trim($dados['nome']) === '') {
            throw new InvalidArgumentException('O nome do tenant é obrigatório.');
        }
        $email = strtolower(trim($dados['admin_email']));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('E-mail do administrador inválido.');
        }

        // Qualquer status: um domínio suspenso ou cancelado continua reservado,
        // e o UNIQUE de tenant_dominios recusaria o INSERT de qualquer forma —
        // só que depois de o schema já ter sido criado.
        if ($this->buscarPorDominio($dominio) !== null) {
            throw new RuntimeException("Já existe um tenant no domínio {$dominio}.");
        }

        $schema = self::gerarNomeSchema($dominio);
        $senha = self::gerarSenha();

        $this->criarSchema($schema);

        try {
            // Igual ao instalador: banco.sql e depois as migrations posteriores a ele.
            $this->carregarBancoSql($schema, [
                'nome' => trim((string) ($dados['admin_nome'] ?? '')) ?: 'Administrador',
                'email' => $email,
                'senha' => $senha,
            ]);
            $this->migrarSchema($schema);

            $agora = date('Y-m-d H:i:s');
            $mestre = $this->mestre();
            $mestre->trans_begin();
            $mestre->insert('tenants', [
                'nome' => $dados['nome'],
                'documento' => $dados['documento'] ?? null,
                'schema' => $schema,
                'status' => 'ativo',
                'limites' => isset($dados['limites']) ? json_encode($dados['limites'], JSON_UNESCAPED_UNICODE) : null,
                'data_expiracao' => $dados['data_expiracao'] ?? null,
                'criado_em' => $agora,
            ] + (isset($dados['plano_id']) ? ['plano_id' => (int) $dados['plano_id']] : []));
            $id = (int) $mestre->insert_id();
            $mestre->insert('tenant_dominios', [
                'tenant_id' => $id,
                'dominio' => $dominio,
                'tipo' => 'plataforma',
                'verificacao_token' => null,
                'verificado_em' => $agora,
                'criado_em' => $agora,
            ]);

            if ($id === 0 || $mestre->trans_status() === false) {
                $erro = $mestre->error()['message'] ?? '';
                $mestre->trans_rollback();

                throw new RuntimeException('Não foi possível gravar o tenant no banco mestre: ' . $erro);
            }
            $mestre->trans_commit();
        } catch (Throwable $e) {
            // Schema sem registro no mestre é inalcançável por qualquer requisição:
            // removê-lo evita acumular lixo no cluster a cada tentativa que falhou.
            $this->excluirSchema($schema);

            throw $e;
        }

        $this->atualizarRotasTraefik();

        return ['id' => $id, 'schema' => $schema, 'admin_email' => $email, 'admin_senha' => $senha];
    }

    /**
     * Senha inicial do admin: 18 caracteres de base64 url-safe (108 bits).
     */
    public static function gerarSenha(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(14)), '+/', '-_'), '=');
    }

    /**
     * Carrega o banco.sql no schema novo, do mesmo jeito que o instalador
     * (install/do_install.php): esquema da 4.x, configurações padrão, perfil
     * Administrador e o admin, com os marcadores trocados por
     * instaladorMontarSql(). Depois disso, migrarSchema() aplica só as
     * migrations posteriores à versão que o banco.sql grava.
     *
     * Rodar todas as migrations a partir do zero NÃO dá o mesmo esquema: o
     * histórico antigo do Map-OS diverge do banco.sql (ex.: cobrancas.charge_id
     * vira int, e os gateways gravam texto ali). O tenant tem de nascer igual a
     * uma instalação nova, que é o esquema que o resto do sistema espera.
     *
     * @param  array{nome:string, email:string, senha:string}  $admin
     */
    protected function carregarBancoSql(string $schema, array $admin): void
    {
        require_once __DIR__ . '/../../install/instalador.php';

        $arquivo = __DIR__ . '/../../banco.sql';
        $sql = is_file($arquivo) ? (string) file_get_contents($arquivo) : '';
        if ($sql === '') {
            throw new RuntimeException('banco.sql não encontrado na raiz do sistema.');
        }

        $db = $this->conexao($schema);
        try {
            $mysqli = $db->conn_id;
            if (! $mysqli instanceof mysqli) {
                throw new RuntimeException('O provisionamento de tenants exige o driver mysqli (DB_DRIVER=mysqli).');
            }

            $sql = instaladorMontarSql($sql, [
                'full_name' => $admin['nome'],
                'email' => $admin['email'],
                'password' => $admin['senha'],
            ], date('Y-m-d H:i:s'), static fn (string $valor): string => $mysqli->real_escape_string($valor));

            $this->executarScript($mysqli, $sql);

            // O admin do banco.sql traz documento e endereço de exemplo; num
            // tenant eles seriam dados falsos da empresa.
            $db->where('email', $admin['email'])->update('usuarios', [
                'rg' => null, 'cpf' => '', 'cep' => '', 'rua' => null, 'numero' => null,
                'bairro' => null, 'cidade' => null, 'estado' => null, 'telefone' => '',
                'dataExpiracao' => null,
            ]);
        } finally {
            $db->close();
        }
    }

    /**
     * multi_query que não engole erro: lê todos os resultados e para no
     * primeiro statement que falhou (o instalador só descarta os resultados).
     */
    private function executarScript(mysqli $mysqli, string $sql): void
    {
        $falha = static fn () => new RuntimeException('Erro ao carregar o banco.sql: ' . $mysqli->error);

        try {
            if (! $mysqli->multi_query($sql)) {
                throw $falha();
            }
            while (true) {
                $resultado = $mysqli->store_result();
                if ($resultado instanceof mysqli_result) {
                    $resultado->free();
                }
                if (! $mysqli->more_results()) {
                    break;
                }
                // next_result() devolve false quando o próximo statement falhou.
                if (! $mysqli->next_result()) {
                    throw $falha();
                }
            }
        } catch (mysqli_sql_exception $e) {
            throw new RuntimeException('Erro ao carregar o banco.sql: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Roda as migrations do banco mestre (tenants, tenant_usage) no
     * DB_MASTER_DATABASE. O banco mestre precisa existir.
     */
    public function migrarMestre(): void
    {
        $this->rodarMigrations($this->mestre(), APPPATH . self::MIGRATIONS_MESTRE);
    }

    /**
     * Leva um schema de tenant até a última migration de negócio. Serve tanto
     * para o schema recém-criado quanto para atualizar um tenant existente
     * depois de um deploy.
     */
    public function migrarSchema(string $schema): void
    {
        $this->validarNomeSchema($schema);

        // Migrations que mexem em arquivos (20261014030000_move_uploads_to_storage)
        // precisam usar a pasta deste tenant, não a da requisição/CLI atual.
        $schemaAnterior = $GLOBALS['mapos_tenant_schema'] ?? null;
        $GLOBALS['mapos_tenant_schema'] = $schema;

        $conexao = $this->conexao($schema);
        try {
            $this->rodarMigrations($conexao, APPPATH . self::MIGRATIONS_TENANT);
        } finally {
            $conexao->close();
            $GLOBALS['mapos_tenant_schema'] = $schemaAnterior;
        }
    }

    /**
     * Pasta de uploads do tenant (STORAGE_PATH/<schema>), para backup e
     * exclusão. Pode não existir se a empresa nunca enviou arquivo.
     */
    public function pastaArmazenamento(string $schema): string
    {
        $this->validarNomeSchema($schema);
        $this->CI->load->helper('armazenamento');

        return armazenamentoRaizBase() . DIRECTORY_SEPARATOR . $schema;
    }

    /**
     * Roda as migrations de uma pasta em uma conexão específica.
     *
     * O CI_Migration (e as migrations, via __get) usam `$CI->db` e
     * `$CI->dbforge`, então os dois são trocados pela conexão alvo durante a
     * execução e devolvidos no finally — senão o resto da requisição
     * continuaria no schema errado.
     *
     * O CI_Migration é instanciado direto, e não por `load->library()`: o
     * loader guarda uma instância só, presa à primeira conexão e pasta.
     */
    protected function rodarMigrations($conexao, string $pasta): void
    {
        $CI = $this->CI;
        $dbAnterior = $CI->db ?? null;
        $forgeAnterior = $CI->dbforge ?? null;

        $CI->db = $conexao;
        $CI->dbforge = $CI->load->dbforge($conexao, true);

        try {
            // Só para o CI incluir a classe CI_Migration; a instância que roda é
            // a de baixo, com a pasta certa.
            $CI->load->library('migration');

            $migration = new CI_Migration([
                'migration_enabled' => true,
                'migration_type' => 'timestamp',
                'migration_table' => 'migrations',
                'migration_auto_latest' => false,
                'migration_version' => '',
                'migration_path' => $pasta,
            ]);

            if ($migration->latest() === false) {
                throw new RuntimeException('Falha ao rodar as migrations em ' . $conexao->database . ': ' . $migration->error_string());
            }
        } finally {
            $CI->db = $dbAnterior;
            $CI->dbforge = $forgeAnterior;
        }
    }

    /**
     * Plano e vencimento da empresa. Vencimento em AAAA-MM-DD ou null (sem
     * vencimento). A cobrança e o bloqueio por vencimento são da Fase 5.
     */
    public function alterarPlano(int $id, ?int $planoId, ?string $vencimento): void
    {
        if ($this->buscarPorId($id) === null) {
            throw new RuntimeException("Tenant {$id} não existe.");
        }

        if ($vencimento !== null) {
            $partes = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $vencimento, $m) ? [(int) $m[2], (int) $m[3], (int) $m[1]] : null;
            if ($partes === null || ! checkdate(...$partes)) {
                throw new InvalidArgumentException('Data de vencimento inválida.');
            }
        }

        $mestre = $this->mestre();
        if ($planoId !== null && $mestre->where('id', $planoId)->count_all_results('planos') === 0) {
            throw new InvalidArgumentException('Plano não encontrado.');
        }

        $mestre->where('id', $id)->update('tenants', [
            'plano_id' => $planoId,
            'data_expiracao' => $vencimento,
            'atualizado_em' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Consumo atual da empresa, para comparar com o plano: usuários ativos, OS
     * abertas no mês e espaço dos uploads em MB.
     *
     * @return array{usuarios:int, os_mes:int, armazenamento_mb:float}
     */
    public function consumo(int $id): array
    {
        $tenant = $this->buscarPorId($id);
        if ($tenant === null) {
            throw new RuntimeException("Tenant {$id} não existe.");
        }

        $db = $this->conexao($tenant['schema']);
        try {
            $usuarios = (int) $db->where('situacao', 1)->count_all_results('usuarios');
            $osMes = (int) $db->where('dataInicial >=', date('Y-m-01'))->count_all_results('os');
        } finally {
            $db->close();
        }

        $bytes = 0;
        $pasta = $this->pastaArmazenamento($tenant['schema']);
        if (is_dir($pasta)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS)) as $arquivo) {
                $bytes += $arquivo->isFile() ? (int) $arquivo->getSize() : 0;
            }
        }

        return ['usuarios' => $usuarios, 'os_mes' => $osMes, 'armazenamento_mb' => round($bytes / 1048576, 1)];
    }

    /**
     * Arquivo de rotas do Traefik (provider "file"), quando o Map-OS fica atrás
     * de um Traefik que atende outros sistemas na mesma VPS: uma rota por
     * endereço, com certificado próprio emitido pelo resolvedor do Traefik
     * (desafio HTTP). Entram o painel e todo domínio verificado de qualquer
     * empresa; domínio próprio só depois de verificado. Empresa suspensa
     * continua com rota, para o hook responder 404 com HTTPS.
     *
     * Configuração (.env): SAAS_TRAEFIK_ARQUIVO (vazio desliga), e opcionais
     * SAAS_TRAEFIK_SERVICO_URL, SAAS_TRAEFIK_CERTRESOLVER, SAAS_TRAEFIK_ENTRYPOINT.
     *
     * @return string|null caminho gravado, ou null se desligado
     */
    public function gerarRotasTraefik(): ?string
    {
        $arquivo = trim((string) ($_ENV['SAAS_TRAEFIK_ARQUIVO'] ?? ''));
        if ($arquivo === '') {
            return null;
        }

        $servico = trim((string) ($_ENV['SAAS_TRAEFIK_SERVICO_URL'] ?? '')) ?: 'http://mapos-nginx:80';
        $resolvedor = trim((string) ($_ENV['SAAS_TRAEFIK_CERTRESOLVER'] ?? '')) ?: 'le';
        $entrada = trim((string) ($_ENV['SAAS_TRAEFIK_ENTRYPOINT'] ?? '')) ?: 'websecure';
        if (! preg_match('#^https?://[a-z0-9.-]+(:\d+)?/?$#', $servico) || ! preg_match('/^[a-zA-Z0-9_-]+$/', $resolvedor . $entrada)) {
            throw new InvalidArgumentException('SAAS_TRAEFIK_SERVICO_URL, SAAS_TRAEFIK_CERTRESOLVER ou SAAS_TRAEFIK_ENTRYPOINT inválido.');
        }

        $hosts = array_column($this->mestre()->select('dominio')->where('verificado_em IS NOT NULL', null, false)->get('tenant_dominios')->result_array(), 'dominio');
        $hosts[] = self::hostPainel();
        $hosts = array_values(array_unique(array_filter(array_map('strtolower', $hosts))));
        sort($hosts);

        $linhas = ['# Gerado pelo Map-OS (Tenant_manager::gerarRotasTraefik). Não edite: é reescrito a cada empresa ou domínio novo.', 'http:', '  routers:'];
        foreach ($hosts as $host) {
            // O host entra numa regra do Traefik: só letras, números, hífen e ponto.
            if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)) {
                log_message('error', 'gerarRotasTraefik: domínio ignorado por ter caracteres inválidos: ' . $host);

                continue;
            }
            $nome = 'mapos-' . substr(preg_replace('/[^a-z0-9]+/', '-', $host), 0, 50) . '-' . substr(md5($host), 0, 6);
            array_push(
                $linhas,
                '    ' . $nome . ':',
                '      rule: "Host(`' . $host . '`)"',
                '      entryPoints: ["' . $entrada . '"]',
                '      service: mapos',
                '      tls:',
                '        certResolver: ' . $resolvedor
            );
        }
        if (count($linhas) === 3) {
            $linhas[2] = '  routers: {}';
        }
        array_push($linhas, '  services:', '    mapos:', '      loadBalancer:', '        passHostHeader: true', '        servers:', '          - url: "' . rtrim($servico, '/') . '"', '');

        $pasta = dirname($arquivo);
        if (! is_dir($pasta) && ! @mkdir($pasta, 0755, true) && ! is_dir($pasta)) {
            throw new RuntimeException('Pasta das rotas do Traefik não existe: ' . $pasta);
        }
        $temporario = $pasta . DIRECTORY_SEPARATOR . '.' . basename($arquivo) . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporario, implode("\n", $linhas)) === false || ! @rename($temporario, $arquivo)) {
            @unlink($temporario);

            throw new RuntimeException('Não foi possível gravar as rotas do Traefik em ' . $arquivo);
        }
        @chmod($arquivo, 0644);

        return $arquivo;
    }

    /**
     * gerarRotasTraefik() sem derrubar quem chamou: a empresa já foi criada (ou
     * o domínio verificado) e a rota pode ser regerada depois por
     * `tools traefik_rotas`. A falha vai para o log.
     */
    protected function atualizarRotasTraefik(): void
    {
        try {
            $this->gerarRotasTraefik();
        } catch (Throwable $erro) {
            log_message('error', 'Rotas do Traefik não atualizadas (rode tools traefik_rotas): ' . $erro->getMessage());
        }
    }

    /** Tarefas agendadas que rodam para cada empresa (`tools tenants_tarefa`). */
    public const TAREFAS_POR_TENANT = ['email/process', 'email/retry'];

    /**
     * Roda um comando num processo próprio para uma empresa: o processo filho
     * recebe MAPOS_TENANT_SCHEMA e o hook aponta banco, uploads e tokens para
     * ela (tenantResolverCli). Um processo por empresa: nada da empresa
     * anterior (conexão, singletons do CI, globais) chega na seguinte.
     *
     * @param  list<string>  $comando  Executável e argumentos, sem shell
     * @return array{codigo:int, saida:string, tempo:float}  código -1 = estourou o tempo
     */
    public function executarTarefa(string $schema, array $comando, int $limiteSegundos = 300): array
    {
        $this->validarNomeSchema($schema);

        // O filho herda o ambiente inteiro (env null). Passar um array faria o
        // proc_open descartar as variáveis vazias (EMAIL_SMTP_CRYPTO= vira o
        // padrão 'tls' no filho), então o schema entra por putenv só durante
        // a chamada.
        $anterior = getenv('MAPOS_TENANT_SCHEMA');
        putenv('MAPOS_TENANT_SCHEMA=' . $schema);

        $inicio = microtime(true);
        try {
            $processo = proc_open($comando, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
        } finally {
            putenv($anterior === false ? 'MAPOS_TENANT_SCHEMA' : 'MAPOS_TENANT_SCHEMA=' . $anterior);
        }
        if (! is_resource($processo)) {
            throw new RuntimeException('Não foi possível iniciar o processo da tarefa.');
        }
        fclose($tubos[0]);
        stream_set_blocking($tubos[1], false);
        stream_set_blocking($tubos[2], false);

        $saida = '';
        $codigo = null;
        while (true) {
            $saida .= (string) stream_get_contents($tubos[1]) . (string) stream_get_contents($tubos[2]);
            $estado = proc_get_status($processo);
            if (! $estado['running']) {
                $codigo = (int) $estado['exitcode'];
                break;
            }
            if (microtime(true) - $inicio > $limiteSegundos) {
                proc_terminate($processo, 9);
                $codigo = -1;
                break;
            }
            $leitura = [$tubos[1], $tubos[2]];
            $nada = null;
            @stream_select($leitura, $nada, $nada, 0, 200000);
        }
        $saida .= (string) stream_get_contents($tubos[1]) . (string) stream_get_contents($tubos[2]);
        fclose($tubos[1]);
        fclose($tubos[2]);
        proc_close($processo);

        return ['codigo' => $codigo, 'saida' => mb_substr(trim($saida), 0, 2000), 'tempo' => round(microtime(true) - $inicio, 2)];
    }

    /**
     * Tabelas cujo conteúdo não vai para o backup (só a estrutura): sessões,
     * tentativas de login, tokens e resets são segredos temporários, como no
     * backup do painel da empresa (Mapos::backup()).
     */
    public const BACKUP_SEM_DADOS = ['ci_sessions', 'login_attempts', 'api_refresh_tokens', 'api_rate_limit', 'resets_de_senha'];

    /**
     * Pasta dos backups das empresas: SAAS_BACKUP_PATH ou STORAGE_PATH/_backups,
     * fora das pastas das empresas e fechada para o web.
     */
    public function pastaBackups(): string
    {
        $configurado = rtrim(trim((string) ($_ENV['SAAS_BACKUP_PATH'] ?? '')), '/\\');
        $this->CI->load->helper('armazenamento');
        $pasta = $configurado !== '' ? $configurado : armazenamentoRaizBase() . DIRECTORY_SEPARATOR . '_backups';

        if (! is_dir($pasta) && ! @mkdir($pasta, 0750, true) && ! is_dir($pasta)) {
            throw new RuntimeException('Não foi possível criar a pasta de backups: ' . $pasta);
        }
        if (! file_exists($pasta . DIRECTORY_SEPARATOR . '.htaccess')) {
            @file_put_contents($pasta . DIRECTORY_SEPARATOR . '.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n");
        }

        return $pasta;
    }

    /**
     * Backup completo de uma empresa num .zip na pasta de backups: banco.sql
     * (estrutura e dados do schema), os uploads (arquivos/) e a logo e as fotos
     * de perfil (publicos/). Restaurar: LEIA-ME.txt dentro do arquivo.
     *
     * @return array{arquivo:string, caminho:string, tamanho:int}
     */
    public function backup(int $id): array
    {
        $tenant = $this->buscarPorId($id);
        if ($tenant === null) {
            throw new RuntimeException("Tenant {$id} não existe.");
        }
        $schema = $tenant['schema'];
        $this->validarNomeSchema($schema);
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('O PHP está sem a extensão zip, necessária para o backup.');
        }

        $pasta = $this->pastaBackups();
        $nome = $schema . '-' . date('Ymd-His') . '.zip';
        $destino = $pasta . DIRECTORY_SEPARATOR . $nome;
        $sql = $destino . '.sql.tmp';

        try {
            $this->exportarSchema($schema, $sql);

            $zip = new ZipArchive();
            if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Não foi possível criar o arquivo de backup.');
            }
            $zip->addFile($sql, 'banco.sql');

            $uploads = $this->pastaArmazenamento($schema);
            if (is_dir($uploads)) {
                $base = strlen(rtrim($uploads, '/\\')) + 1;
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS)) as $arquivo) {
                    if ($arquivo->isFile()) {
                        $zip->addFile($arquivo->getPathname(), 'arquivos/' . str_replace('\\', '/', substr($arquivo->getPathname(), $base)));
                    }
                }
            }

            foreach ($this->arquivosPublicos($schema) as $relativo => $absoluto) {
                $zip->addFile($absoluto, 'publicos/' . $relativo);
            }

            $dominios = array_column($this->dominios($id), 'dominio');
            $zip->addFromString('LEIA-ME.txt', implode("\n", [
                'Backup da empresa ' . $tenant['nome'] . ' (código ' . $id . ')',
                'Schema: ' . $schema,
                'Domínios: ' . implode(', ', $dominios),
                'Gerado em: ' . date('d/m/Y H:i:s'),
                '',
                'Para restaurar:',
                '1. Crie o banco: CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
                '2. Importe: mysql ' . $schema . ' < banco.sql',
                '3. Copie o conteúdo de arquivos/ para STORAGE_PATH/' . $schema . '/',
                '4. Copie publicos/assets/ para a pasta assets/ do Map-OS.',
                '5. Se a empresa foi excluída, cadastre de novo o registro em tenants e tenant_dominios no banco mestre.',
                '',
            ]));

            if (! $zip->close()) {
                throw new RuntimeException('Não foi possível gravar o arquivo de backup.');
            }
        } catch (Throwable $erro) {
            @unlink($destino);

            throw $erro;
        } finally {
            @unlink($sql);
        }

        return ['arquivo' => $nome, 'caminho' => $destino, 'tamanho' => (int) filesize($destino)];
    }

    /**
     * Backups guardados, do mais novo ao mais antigo.
     *
     * @return list<array{arquivo:string, schema:string, tamanho:int, criado_em:int}>
     */
    public function listarBackups(?string $schema = null): array
    {
        $backups = [];
        foreach (glob($this->pastaBackups() . DIRECTORY_SEPARATOR . '*.zip') ?: [] as $caminho) {
            $nome = basename($caminho);
            if (! preg_match(self::PADRAO_BACKUP, $nome, $m) || ($schema !== null && $m[1] !== $schema)) {
                continue;
            }
            $backups[] = ['arquivo' => $nome, 'schema' => $m[1], 'tamanho' => (int) filesize($caminho), 'criado_em' => (int) filemtime($caminho)];
        }
        usort($backups, static fn ($a, $b) => strcmp($b['arquivo'], $a['arquivo']));

        return $backups;
    }

    /**
     * Apaga os backups de uma empresa mais antigos que $dias, mantendo sempre
     * os $minimo mais novos. Só mexe nos backups do schema informado: o backup
     * final de uma empresa excluída (schema que não existe mais) nunca passa
     * por aqui.
     *
     * @return int quantos foram apagados
     */
    public function podarBackups(string $schema, int $dias, int $minimo = 3): int
    {
        $this->validarNomeSchema($schema);
        $limite = time() - max(1, $dias) * 86400;
        $apagados = 0;

        foreach (array_slice($this->listarBackups($schema), max(0, $minimo)) as $backup) {
            if ($backup['criado_em'] < $limite && @unlink($this->pastaBackups() . DIRECTORY_SEPARATOR . $backup['arquivo'])) {
                $apagados++;
            }
        }

        return $apagados;
    }

    /** Nome de arquivo de backup: <schema>-AAAAMMDD-HHMMSS.zip */
    public const PADRAO_BACKUP = '/^(mapos_[a-z0-9_]{1,50})-(\d{8}-\d{6})\.zip$/';

    /** Caminho de um backup pelo nome, ou null se o nome não é de backup ou não existe. */
    public function caminhoBackup(string $arquivo): ?string
    {
        if (! preg_match(self::PADRAO_BACKUP, $arquivo)) {
            return null;
        }
        $caminho = $this->pastaBackups() . DIRECTORY_SEPARATOR . $arquivo;

        return is_file($caminho) ? $caminho : null;
    }

    /**
     * Exclusão definitiva de uma empresa cancelada (LGPD): gera o backup
     * final e só então apaga o schema, a pasta de uploads, a logo e as fotos
     * de perfil, o consumo e o registro no mestre (os domínios vão junto). A
     * trilha de auditoria do painel fica.
     *
     * Se o backup falhar, nada é apagado.
     *
     * @return array{backup:string}
     */
    public function excluirTenant(int $id): array
    {
        $tenant = $this->buscarPorId($id);
        if ($tenant === null) {
            throw new RuntimeException("Tenant {$id} não existe.");
        }
        if ($tenant['status'] !== 'cancelado') {
            throw new RuntimeException('Só uma empresa cancelada pode ser excluída. Cancele antes.');
        }

        $schema = $tenant['schema'];
        $backup = $this->backup($id);
        $publicos = $this->schemaExiste($schema) ? $this->arquivosPublicos($schema) : [];

        $this->excluirSchema($schema);
        $this->apagarPasta($this->pastaArmazenamento($schema));
        foreach ($publicos as $absoluto) {
            @unlink($absoluto);
        }

        $mestre = $this->mestre();
        $mestre->trans_begin();
        $mestre->where('tenant_id', $id)->delete('tenant_usage');
        $mestre->where('id', $id)->delete('tenants');
        if ($mestre->trans_status() === false) {
            $mestre->trans_rollback();

            throw new RuntimeException('O schema foi apagado, mas o registro da empresa ficou no mestre: ' . ($mestre->error()['message'] ?? ''));
        }
        $mestre->trans_commit();

        $this->atualizarRotasTraefik();

        return ['backup' => $backup['arquivo']];
    }

    /**
     * Despeja estrutura e dados do schema em SQL, linha a linha (sem carregar
     * a tabela inteira na memória).
     */
    protected function exportarSchema(string $schema, string $arquivo): void
    {
        $db = $this->conexao($schema);
        $saida = fopen($arquivo, 'wb');
        if ($saida === false) {
            $db->close();

            throw new RuntimeException('Não foi possível gravar o backup em ' . $arquivo);
        }

        try {
            /** @var mysqli $mysqli */
            $mysqli = $db->conn_id;
            fwrite($saida, "-- Backup do schema {$schema}, " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

            $tabelas = [];
            $views = [];
            $lista = $mysqli->query('SHOW FULL TABLES');
            if (! $lista instanceof mysqli_result) {
                throw new RuntimeException('Erro de banco: ' . $mysqli->error);
            }
            while ($linha = $lista->fetch_row()) {
                if ($linha[1] === 'VIEW') {
                    $views[] = $linha[0];
                } else {
                    $tabelas[] = $linha[0];
                }
            }
            $lista->free();

            foreach ($tabelas as $tabela) {
                $nome = '`' . str_replace('`', '``', $tabela) . '`';
                $criar = $mysqli->query('SHOW CREATE TABLE ' . $nome);
                if (! $criar instanceof mysqli_result) {
                    throw new RuntimeException('Erro de banco: ' . $mysqli->error);
                }
                fwrite($saida, "DROP TABLE IF EXISTS {$nome};\n" . $criar->fetch_row()[1] . ";\n\n");
                $criar->free();

                if (in_array($tabela, self::BACKUP_SEM_DADOS, true)) {
                    continue;
                }

                $linhas = $mysqli->query('SELECT * FROM ' . $nome, MYSQLI_USE_RESULT);
                if (! $linhas instanceof mysqli_result) {
                    throw new RuntimeException('Erro de banco: ' . $mysqli->error);
                }
                $colunas = implode(', ', array_map(static fn ($c) => '`' . str_replace('`', '``', $c->name) . '`', $linhas->fetch_fields()));
                $lote = [];
                while ($linha = $linhas->fetch_row()) {
                    $lote[] = '(' . implode(', ', array_map(static fn ($v) => $v === null ? 'NULL' : "'" . $mysqli->real_escape_string((string) $v) . "'", $linha)) . ')';
                    if (count($lote) === 200) {
                        fwrite($saida, "INSERT INTO {$nome} ({$colunas}) VALUES\n" . implode(",\n", $lote) . ";\n");
                        $lote = [];
                    }
                }
                if ($lote !== []) {
                    fwrite($saida, "INSERT INTO {$nome} ({$colunas}) VALUES\n" . implode(",\n", $lote) . ";\n");
                }
                $linhas->free();
                fwrite($saida, "\n");
            }

            foreach ($views as $view) {
                $nome = '`' . str_replace('`', '``', $view) . '`';
                $criar = $mysqli->query('SHOW CREATE VIEW ' . $nome);
                if ($criar instanceof mysqli_result) {
                    fwrite($saida, "DROP VIEW IF EXISTS {$nome};\n" . $criar->fetch_row()[1] . ";\n\n");
                    $criar->free();
                }
            }

            fwrite($saida, "SET FOREIGN_KEY_CHECKS = 1;\n");
        } finally {
            fclose($saida);
            $db->close();
        }
    }

    /**
     * Logo do emitente e fotos de perfil da empresa, que ficam em assets/
     * numa pasta comum a todas: só os arquivos que o banco dela cita.
     *
     * @return array<string, string> caminho relativo (assets/...) => absoluto
     */
    protected function arquivosPublicos(string $schema): array
    {
        $db = $this->conexao($schema);
        try {
            $logos = array_column($db->select('url_logo')->get('emitente')->result_array() ?: [], 'url_logo');
            $fotos = array_column($db->select('url_image_user')->get('usuarios')->result_array() ?: [], 'url_image_user');
        } finally {
            $db->close();
        }

        $arquivos = [];
        foreach (['assets/uploads' => $logos, 'assets/userImage' => $fotos] as $pasta => $valores) {
            foreach ($valores as $valor) {
                $nome = basename((string) parse_url((string) $valor, PHP_URL_PATH));
                if ($nome === '' || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $nome) || in_array(strtolower($nome), ['index.html', '.htaccess'], true)) {
                    continue;
                }
                $absoluto = rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pasta) . DIRECTORY_SEPARATOR . $nome;
                if (is_file($absoluto)) {
                    $arquivos[$pasta . '/' . $nome] = $absoluto;
                }
            }
        }

        return $arquivos;
    }

    /** Apaga a pasta e tudo dentro dela (pasta de uploads de uma empresa). */
    protected function apagarPasta(string $pasta): void
    {
        if (! is_dir($pasta)) {
            return;
        }

        $itens = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($itens as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($pasta);
    }

    /**
     * Conexão com o banco mestre, aberta uma vez.
     */
    protected function mestre()
    {
        if ($this->mestre === null) {
            $this->mestre = $this->conexao(self::bancoMestre());
        }

        return $this->mestre;
    }

    /**
     * Conexão nova com o grupo `default`, trocando só o banco.
     *
     * `db_debug` desligado: com ele ligado, um erro de SQL mostra a página de
     * erro e encerra o processo, e o provisionamento não teria como desfazer o
     * schema criado. Os erros são conferidos em executar() e viram exceção.
     */
    protected function conexao(string $database)
    {
        $config = $this->configBase();
        $config['database'] = $database;
        $config['db_debug'] = false;
        $config['char_set'] = 'utf8mb4';
        $config['dbcollat'] = 'utf8mb4_unicode_ci';

        $conexao = $this->CI->load->database($config, true);
        if (! is_object($conexao) || empty($conexao->conn_id)) {
            throw new RuntimeException("Não foi possível conectar ao banco {$database}.");
        }

        return $conexao;
    }

    /**
     * Parâmetros do grupo `default` (config/database.php), lidos da conexão da
     * aplicação: mesmas credenciais, mesmo driver. Só o `database` muda em
     * conexao(), então tanto faz para qual schema a conexão da aplicação
     * aponta.
     */
    protected function configBase(): array
    {
        if ($this->configBase === null) {
            $this->CI->load->database();
            $db = $this->CI->db;

            $this->configBase = [
                'dsn' => '',
                'hostname' => $db->hostname,
                'port' => $db->port,
                'username' => $db->username,
                'password' => $db->password,
                'dbdriver' => $db->dbdriver,
                'dbprefix' => $db->dbprefix,
                'pconnect' => false,
                'cache_on' => false,
                'cachedir' => '',
                'swap_pre' => '',
                'encrypt' => $db->encrypt,
                'compress' => $db->compress,
                'stricton' => $db->stricton,
                'failover' => [],
                'save_queries' => false,
            ];
        }

        return $this->configBase;
    }

    /**
     * Query que não pode falhar em silêncio.
     */
    protected function executar($conexao, string $sql, array $binds = []): void
    {
        if ($conexao->query($sql, $binds ?: false) === false) {
            $erro = $conexao->error();

            throw new RuntimeException('Erro de banco: ' . ($erro['message'] ?? 'desconhecido'));
        }
    }
}
