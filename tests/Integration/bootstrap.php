<?php

/**
 * Bootstrap dos testes de integração (MySQL real).
 *
 * Ao contrário de tests/bootstrap.php, aqui o CI_Migration e o driver mysqli
 * são os de verdade: o que se testa é o provisionamento criando schemas,
 * rodando as 40+ migrations e resolvendo o tenant pelo Host. Do framework só
 * se simula o super-objeto (get_instance) com o que essas peças usam: db,
 * dbforge, load e lang.
 */
define('MAPOS_ROOT', dirname(__DIR__, 2));
define('APPPATH', MAPOS_ROOT . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
define('FCPATH', MAPOS_ROOT . DIRECTORY_SEPARATOR);
define('BASEPATH', APPPATH . 'vendor' . DIRECTORY_SEPARATOR . 'codeigniter' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'testing');

require APPPATH . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

// Sem Dotenv: o ambiente vem do container (docker/teste/docker-compose.yml).
foreach (['DB_HOSTNAME', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'DB_MASTER_DATABASE', 'SAAS_DOMINIO_BASE', 'SAAS_PAINEL_HOST'] as $variavel) {
    $valor = getenv($variavel);
    if ($valor !== false) {
        $_ENV[$variavel] = $valor;
    }
}

function log_message($level, $message, $php_error = false)
{
}

function show_error($message, $status_code = 500, $heading = 'Erro')
{
    throw new RuntimeException($heading . ': ' . (is_array($message) ? implode(' ', $message) : $message));
}

function config_item($item)
{
    $config = ['charset' => 'UTF-8', 'subclass_prefix' => 'MY_'];

    return $config[$item] ?? null;
}

require BASEPATH . 'core' . DIRECTORY_SEPARATOR . 'Common.php';
require BASEPATH . 'database' . DIRECTORY_SEPARATOR . 'DB.php';
require BASEPATH . 'libraries' . DIRECTORY_SEPARATOR . 'Migration.php';

/** O mínimo do CI_Loader que Tenant_manager, CI_Migration e as migrations chamam. */
final class IntegracaoLoader
{
    public function database($params = '', $return = false, $query_builder = null)
    {
        // Como no CI_Loader: sem parâmetros e já conectado, não faz nada.
        if ($params === '' && ! $return && is_object(get_instance()->db) && ! empty(get_instance()->db->conn_id)) {
            return false;
        }
        if ($params === '') {
            $params = integracaoConfigBanco($_ENV['DB_DATABASE']);
        }

        $db = &DB($params, $query_builder);
        if ($return) {
            return $db;
        }
        get_instance()->db = $db;

        return $this;
    }

    public function dbforge($db = null, $return = false)
    {
        $db = is_object($db) ? $db : get_instance()->db;

        require_once BASEPATH . 'database/DB_forge.php';
        require_once BASEPATH . 'database/drivers/' . $db->dbdriver . '/' . $db->dbdriver . '_forge.php';
        $classe = 'CI_DB_' . $db->dbdriver . '_forge';
        $forge = new $classe($db);

        if ($return) {
            return $forge;
        }
        get_instance()->dbforge = $forge;

        return $this;
    }

    public function helper($nome)
    {
        require_once APPPATH . 'helpers/' . $nome . '_helper.php';
    }

    /** CI_Migration já foi incluída acima; nenhuma outra biblioteca é usada. */
    public function library($nome)
    {
        if (strtolower($nome) !== 'migration') {
            throw new LogicException("Biblioteca {$nome} não existe no bootstrap de integração.");
        }
    }
}

/** Grupo `default` como o config/database.php monta, para o banco informado. */
function integracaoConfigBanco(string $banco): array
{
    return [
        'dsn' => '',
        'hostname' => $_ENV['DB_HOSTNAME'],
        'port' => (int) $_ENV['DB_PORT'],
        'username' => $_ENV['DB_USERNAME'],
        'password' => $_ENV['DB_PASSWORD'],
        'database' => $banco,
        'dbdriver' => 'mysqli',
        'dbprefix' => '',
        'pconnect' => false,
        'db_debug' => false,
        'char_set' => 'utf8mb4',
        'dbcollat' => 'utf8mb4_unicode_ci',
        'encrypt' => false,
        'compress' => false,
        'stricton' => false,
        'failover' => [],
    ];
}

final class IntegracaoLang
{
    public function load($arquivo)
    {
    }

    public function line($chave)
    {
        return $chave;
    }
}

final class IntegracaoCI
{
    public $db;

    public $dbforge;

    public $load;

    public $lang;

    public $config;

    public function __construct()
    {
        $this->load = new IntegracaoLoader();
        $this->lang = new IntegracaoLang();
        // O que o CI_Config faz com application/config/<arquivo>.php.
        $this->config = new class() {
            private array $itens = [];

            public function load($arquivo, $secoes = false, $falharEmSilencio = false)
            {
                $config = [];
                require APPPATH . 'config/' . $arquivo . '.php';
                $this->itens = array_merge($this->itens, $config);

                return true;
            }

            public function item($chave)
            {
                return $this->itens[$chave] ?? null;
            }
        };
    }
}

function &get_instance()
{
    static $ci = null;
    if ($ci === null) {
        $ci = new IntegracaoCI();
    }

    return $ci;
}

require_once APPPATH . 'libraries' . DIRECTORY_SEPARATOR . 'Tenant_manager.php';
require_once APPPATH . 'hooks' . DIRECTORY_SEPARATOR . 'tenant.php';
