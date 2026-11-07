<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Tenant_manager
 * ----------------------------------------------------------
 * Provisionamento e resolução de tenant (Fase 1 — multi-tenancy,
 * schema-per-tenant).
 *
 * O banco MESTRE (DB_MASTER_DATABASE) guarda `tenants` e `tenant_usage`:
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
     * Resolve o tenant ativo de um domínio (padrão: hostname da requisição).
     * Retorna o registro de tenants ou null (não encontrado / suspenso).
     */
    public function resolverPorDominio(?string $dominio = null): ?array
    {
        if (! $dominio) {
            $dominio = $_SERVER['HTTP_HOST'] ?? '';
            $dominio = preg_replace('/:\d+$/', '', $dominio); // remove porta
        }

        $registro = $this->mestre()
            ->where('dominio', strtolower($dominio))
            ->where('status', 'ativo')
            ->get('tenants')
            ->row_array();

        return $registro ?: null;
    }

    /**
     * Registro de tenants do domínio, em qualquer status.
     */
    public function buscarPorDominio(string $dominio): ?array
    {
        $registro = $this->mestre()
            ->where('dominio', strtolower(trim($dominio)))
            ->get('tenants')
            ->row_array();

        return $registro ?: null;
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
     * @param  array{nome:string, documento?:string, dominio:string, limites?:array, data_expiracao?:string}  $dados
     */
    public function provisionar(array $dados): int
    {
        $dominio = strtolower(trim($dados['dominio']));
        if ($dominio === '' || filter_var($dominio, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('Domínio do tenant inválido.');
        }
        if (trim($dados['nome']) === '') {
            throw new InvalidArgumentException('O nome do tenant é obrigatório.');
        }

        // Qualquer status: um domínio suspenso ou cancelado continua reservado,
        // e o UNIQUE de tenants.dominio recusaria o INSERT de qualquer forma —
        // só que depois de o schema já ter sido criado.
        if ($this->buscarPorDominio($dominio) !== null) {
            throw new RuntimeException("Já existe um tenant no domínio {$dominio}.");
        }

        $schema = self::gerarNomeSchema($dominio);

        $this->criarSchema($schema);

        try {
            $this->migrarSchema($schema);

            $mestre = $this->mestre();
            $ok = $mestre->insert('tenants', [
                'dominio' => $dominio,
                'nome' => $dados['nome'],
                'documento' => $dados['documento'] ?? null,
                'schema' => $schema,
                'status' => 'ativo',
                'limites' => isset($dados['limites']) ? json_encode($dados['limites'], JSON_UNESCAPED_UNICODE) : null,
                'data_expiracao' => $dados['data_expiracao'] ?? null,
                'criado_em' => date('Y-m-d H:i:s'),
            ]);
            $id = $ok ? (int) $mestre->insert_id() : 0;

            if ($id === 0) {
                throw new RuntimeException('Não foi possível gravar o tenant no banco mestre: ' . ($mestre->error()['message'] ?? ''));
            }
        } catch (Throwable $e) {
            // Schema sem registro no mestre é inalcançável por qualquer requisição:
            // removê-lo evita acumular lixo no cluster a cada tentativa que falhou.
            $this->excluirSchema($schema);

            throw $e;
        }

        return $id;
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
    protected function executar($conexao, string $sql): void
    {
        if ($conexao->query($sql) === false) {
            $erro = $conexao->error();

            throw new RuntimeException('Erro de banco: ' . ($erro['message'] ?? 'desconhecido'));
        }
    }
}
