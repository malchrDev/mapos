<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Tenant_manager
 * ----------------------------------------------------------
 * Provisionamento e resolução de tenant (Fase 1 — multi-tenancy,
 * schema-per-tenant).
 *
 * O banco MESTRE (DB_DATABASE) guarda a tabela tenants: domínio -> schema.
 * Cada tenant tem seu próprio schema MySQL no mesmo cluster; backup, restore e
 * remoção total de dados (LGPD) são operações de schema inteiro.
 *
 * Nunca se deriva o nome do schema de entrada do usuário: o nome é gerado aqui
 * no provisionamento e gravado na tabela tenants.
 */
class Tenant_manager
{
    /** @var object CodeIgniter instance */
    protected $CI;

    /** Prefixo do nome do schema de um tenant (evita colisões com outras bases). */
    const SCHEMA_PREFIX = 'mapos_';

    public function __construct()
    {
        $this->CI = &get_instance();
    }

    /**
     * Resolve o tenant a partir do hostname da requisição atual.
     * Retorna o registro de tenants ou null (não encontrado / suspenso).
     */
    public function resolverPorDominio(?string $dominio = null): ?array
    {
        if (! $dominio) {
            $dominio = $_SERVER['HTTP_HOST'] ?? '';
            $dominio = preg_replace('/:.*$/', '', $dominio); //remove porta
        }

        $this->CI->load->database();
        $this->CI->db->where('dominio', strtolower($dominio));
        $this->CI->db->where('status', 'ativo');
        $registro = $this->CI->db->get('tenants')->row_array();

        return $registro ?: null;
    }

    /**
     * Verifica se um schema já existe no cluster.
     */
    public function schemaExiste(string $schema): bool
    {
        $this->CI->load->database();
        $this->CI->db->where('schema_name', $schema);
        $res = $this->CI->db->get('information_schema.schemata');

        return $res->num_rows() > 0;
    }

    /**
     * Gera o nome do schema a partir do domínio.
     */
    public static function nomeSchema(string $dominio): string
    {
        $base = preg_replace('/[^a-z0-9]/', '', strtolower($dominio));
        return self::SCHEMA_PREFIX . substr($base, 0, 50);
    }

    /**
     * Cria o schema vazio do tenant no cluster (sem tabelas).
     * Chamado somente no provisionamento.
     */
    public function criarSchema(string $schema): void
    {
        $this->validarNomeSchema($schema);

        if ($this->schemaExiste($schema)) {
            return;
        }
        $this->CI->load->database();
        $this->CI->db->query('CREATE DATABASE ' . $this->CI->db->protect_identifiers($schema)
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
        $this->CI->load->database();
        $this->CI->db->query('DROP DATABASE ' . $this->CI->db->protect_identifiers($schema));
    }

    /**
     * Nome de schema entra em SQL cru (CREATE/DROP DATABASE não aceita binding),
     * então a validação é o que separa um nome legítimo de um ataque.
     *
     * Só letras minúsculas, dígitos e underscore, com o prefixo do tenant: nada
     * de backtick, espaço, ponto, hífen ou nome de banco alheio.
     *
     * A regex sozinha não basta: `mapos_padrao` e `mapos_master` casariam com
     * ela, e `excluirSchema()` roda `DROP DATABASE`. Os nomes que o Map-OS já
     * usa — banco de trabalho de instalação única e banco mestre — são barrados
     * explicitamente, de quem é o schema do tenant e de quem não é.
     */
    private function validarNomeSchema(string $schema): void
    {
        $formatoValido = (bool) preg_match('/^' . preg_quote(self::SCHEMA_PREFIX, '/') . '[a-z0-9_]{1,50}$/', $schema);

        $bancoReservado = in_array($schema, ['mapos_padrao', 'mapos_master'], true);

        if (! $formatoValido || $bancoReservado) {
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
        if ($dominio === '') {
            throw new InvalidArgumentException('O domínio do tenant é obrigatório.');
        }

        $this->CI->load->database();

        if ($this->resolverPorDominio($dominio) !== null) {
            throw new RuntimeException("Já existe um tenant ativo no domínio {$dominio}.");
        }

        $schema = self::nomeSchema($dominio);

        $this->criarSchema($schema);

        try {
            $this->rodarMigrationsNoSchema($schema);
        } catch (Throwable $e) {
            // Schema sem registro no mestre é inalcançável por qualquer requisição:
            // removê-lo evita acumular lixo no cluster a cada tentativa que falhou.
            $this->excluirSchema($schema);

            throw $e;
        }

        $this->CI->load->database('default', true);
        $this->CI->db->query('START TRANSACTION');
        try {
            $this->CI->db->insert('tenants', [
                'dominio' => $dominio,
                'nome' => $dados['nome'],
                'documento' => $dados['documento'] ?? null,
                'schema' => $schema,
                'status' => 'ativo',
                'limites' => isset($dados['limites']) ? json_encode($dados['limites'], JSON_UNESCAPED_UNICODE) : null,
                'data_expiracao' => $dados['data_expiracao'] ?? null,
                'criado_em' => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->CI->db->insert_id();
            $this->CI->db->query('COMMIT');
        } catch (Throwable $e) {
            $this->CI->db->query('ROLLBACK');

            throw $e;
        }

        if ($id === 0) {
            throw new RuntimeException('Não foi possível gravar o tenant no banco mestre.');
        }

        return $id;
    }

    /**
     * Roda as migrations do Map-OS dentro do schema informado, que ainda não tem
     * nenhuma tabela.
     *
     * A conexão é religada com o `database` do tenant: a biblioteca de migration
     * do CI usa a conexão ativa, então não há como rodar as migrations "de fora"
     * apontando para outro schema. Ao terminar, a conexão volta ao banco mestre —
     * provisionar() continua usando-a para o INSERT em tenants, e deixá-la no
     * schema do tenant gravaria o registro no banco errado.
     *
     * As migrations do banco mestre (tenants, tenant_usage) são ignoradas: elas
     * não existem no schema do tenant, e só rodam no mestre. Em vez de replicar
     * a lógica de `version()` do CI (que depende de métodos `protected`), as
     * migrations de negócio são copiadas para um diretório temporário e o
     * `_migration_path` aponta pra lá. Assim o CI roda exatamente as que devem
     * rodar, registra a versão correta na tabela migrations do tenant, e falha
     * no primeiro erro real.
     */
    private function rodarMigrationsNoSchema(string $schema): void
    {
        $config = $this->CI->db->conn_id === null ? [] : [
            'hostname' => $this->CI->db->hostname,
            'username' => $this->CI->db->username,
            'password' => $this->CI->db->password,
            'port' => $this->CI->db->port,
            'database' => $schema,
            'dbdriver' => $this->CI->db->dbdriver,
            'dbprefix' => $this->CI->db->dbprefix,
        ];

        $this->CI->load->database($config, true);

        // Diretório temporário com as migrations do tenant (sem as do mestre)
        $origem = APPPATH . 'database/migrations/';
        $staging = APPPATH . 'database/migrations_staging/';

        try {
            if (!is_dir($staging)) {
                mkdir($staging, 0755, true);
            }

            $arquivos = glob($origem . '*_*.php');
            foreach ($arquivos as $arquivo) {
                if (str_contains(basename($arquivo), 'create_tenants') || str_contains(basename($arquivo), 'create_tenant_usage')) {
                    continue;
                }
                copy($arquivo, $staging . basename($arquivo));
            }

            // Aponta a biblioteca de migration para o staging
            $this->CI->load->library('migration');
            $reflexao = new ReflectionProperty($this->CI->migration, '_migration_path');
            $reflexao->setAccessible(true);
            $reflexao->setValue($this->CI->migration, $staging);

            $resultado = $this->CI->migration->latest();

            if ($resultado === false) {
                throw new RuntimeException('Falha ao criar as tabelas do tenant: ' . $this->CI->migration->error_string());
            }
        } finally {
            // Limpa o staging e volta a conexão ao mestre
            if (is_dir($staging)) {
                $limpos = glob($staging . '*.php');
                foreach ($limpos as $limpo) {
                    @unlink($limpo);
                }
                @rmdir($staging);
            }

            $this->CI->load->database('default', true);
        }
    }
}
