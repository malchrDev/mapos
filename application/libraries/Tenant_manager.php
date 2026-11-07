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
    }

    /**
     * Todos os tenants registrados (qualquer status), para manutenção em lote.
     */
    public function listar(): array
    {
        return $this->mestre()->order_by('id')->get('tenants')->result_array();
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
     * @param  array{nome:string, dominio:string, admin_email:string, admin_nome?:string, documento?:string, limites?:array, data_expiracao?:string}  $dados
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
            $this->migrarSchema($schema);
            $this->gravarDadosIniciais($schema, [
                'nome' => trim((string) ($dados['admin_nome'] ?? '')) ?: 'Administrador',
                'email' => $email,
                'senha' => $senha,
            ]);

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
            ]);
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
     * O que uma instalação nova ganha do instalador (banco.sql) e as migrations
     * não criam: configurações padrão, o perfil Administrador e o usuário admin.
     *
     * @param  array{nome:string, email:string, senha:string}  $admin
     */
    protected function gravarDadosIniciais(string $schema, array $admin): void
    {
        $this->CI->config->load('tenant_dados_iniciais', false, false);
        $dados = $this->CI->config->item('tenant_dados_iniciais');
        $db = $this->conexao($schema);

        try {
            // INSERT IGNORE: algumas migrations já gravam a própria configuração.
            foreach ($dados['configuracoes'] as $config => $valor) {
                $this->executar($db, 'INSERT IGNORE INTO configuracoes (config, valor) VALUES (?, ?)', [$config, $valor]);
            }

            if (! $db->insert('permissoes', [
                'nome' => 'Administrador',
                'permissoes' => serialize($dados['permissoes_admin']),
                'situacao' => 1,
                'data' => date('Y-m-d'),
            ])) {
                throw new RuntimeException('Não foi possível criar o perfil Administrador: ' . ($db->error()['message'] ?? ''));
            }
            $perfil = (int) $db->insert_id();

            if (! $db->insert('usuarios', [
                'nome' => $admin['nome'],
                'cpf' => '',
                'cep' => '',
                'email' => $admin['email'],
                'senha' => password_hash($admin['senha'], PASSWORD_DEFAULT),
                'telefone' => '',
                'situacao' => 1,
                'dataCadastro' => date('Y-m-d'),
                'permissoes_id' => $perfil,
                'dataExpiracao' => null,
            ])) {
                throw new RuntimeException('Não foi possível criar o usuário administrador: ' . ($db->error()['message'] ?? ''));
            }
        } finally {
            $db->close();
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

        $conexao = $this->conexao($schema);
        try {
            $this->rodarMigrations($conexao, APPPATH . self::MIGRATIONS_TENANT);
        } finally {
            $conexao->close();
        }
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
