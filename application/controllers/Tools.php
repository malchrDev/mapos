<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Tools extends CI_Controller
{
    /**
     * Gerador de dados falsos dos seeds. Só existe com as dependências de
     * desenvolvimento (composer install sem --no-dev); migrate e rollback não
     * dependem dele, para funcionar na instalação de produção.
     *
     * @var \Faker\Generator|null
     */
    public $faker;

    /** @var Seeder */
    public $seeder;

    public function __construct()
    {
        parent::__construct();

        // can only be called from the command line
        if (! $this->input->is_cli_request()) {
            exit('Direct access is not allowed. This is a command line tool, use the terminal');
        }

        $this->load->dbforge();

        $this->load->library('Seeder');

        // initiate seeder
        $this->seeder = Seeder::create();
    }

    public function index()
    {
        $this->help();
    }

    public function message($to = 'World')
    {
        echo "Hello {$to}!" . PHP_EOL;
    }

    public function help()
    {
        $result = "The following are the available command line interface commands\n\n";
        $result .= "php index.php tools migration \"file_name\"         Create new migration file\n";
        $result .= "php index.php tools migrate [\"version_number\"]    Run all migrations. The version number is optional.\n";
        $result .= "php index.php tools seeder \"file_name\"            Creates a new seed file.\n";
        $result .= "php index.php tools seed \"file_name\"              Run the specified seed file.\n";
        $result .= "php index.php tools tenant_mestre                  Multi-tenancy: migrate the master database.\n";
        $result .= "php index.php tools tenant_criar \"dominio\" \"nome\" \"email\"  Multi-tenancy: provision a tenant.\n";
        $result .= "php index.php tools tenant_dominio \"atual\" \"novo\"   Multi-tenancy: add a custom domain.\n";
        $result .= "php index.php tools tenant_verificar \"dominio\"       Multi-tenancy: verify a custom domain (DNS TXT).\n";
        $result .= "php index.php tools tenants_migrate                Multi-tenancy: migrate every tenant schema.\n";
        $result .= "php index.php tools plataforma_conta \"email\" \"nome\"  Platform panel: create an admin account.\n";
        $result .= "php index.php tools plataforma_senha \"email\"         Platform panel: new password for an account.\n";

        echo $result . PHP_EOL;
    }

    public function migration($name)
    {
        $this->make_migration_file($name);
    }

    public function migrate($version = null)
    {
        $this->load->library('migration');

        try {
            $resultado = $version != null ? $this->migration->version($version) : $this->migration->latest();
        } catch (Throwable $erro) {
            // A migration que falhou não é marcada como concluída: corrija a causa e rode de novo.
            log_message('error', 'tools migrate: ' . $erro->getMessage());
            show_error('A migration parou: ' . $erro->getMessage());

            return;
        }

        if ($resultado === false) {
            show_error($this->migration->error_string());
        } else {
            echo 'Migrations run successfully' . PHP_EOL;
        }
    }

    /**
     * Multi-tenancy: cria/atualiza as tabelas do banco mestre (tenants,
     * tenant_usage) em DB_MASTER_DATABASE. Ver docs/multi-tenancy.md.
     */
    public function tenant_mestre()
    {
        $this->executarTenant(function (Tenant_manager $tenants) {
            $tenants->migrarMestre();
            echo 'Banco mestre atualizado.' . PHP_EOL;
        });
    }

    /**
     * Multi-tenancy: provisiona um tenant (schema + migrations + registro).
     */
    public function tenant_criar($dominio = null, $nome = null, $email = null)
    {
        if (! $dominio || ! $nome || ! $email) {
            echo 'Uso: php index.php tools tenant_criar "dominio" "nome" "email_do_admin" (espaço como %20 e @ como %40: o CI recusa esses caracteres no CLI)' . PHP_EOL;

            return;
        }

        $this->executarTenant(function (Tenant_manager $tenants) use ($dominio, $nome, $email) {
            $novo = $tenants->provisionar(['dominio' => $dominio, 'nome' => urldecode($nome), 'admin_email' => urldecode($email)]);
            echo "Tenant {$novo['id']} criado: {$dominio} -> {$novo['schema']}" . PHP_EOL;
            // Até o e-mail de boas-vindas (Fase 5), a senha inicial só existe aqui.
            echo "Admin: {$novo['admin_email']}  senha inicial: {$novo['admin_senha']}" . PHP_EOL;
        });
    }

    /**
     * Multi-tenancy: cadastra um domínio próprio para o tenant dono de
     * `dominio_atual` e mostra o TXT que prova a posse.
     */
    public function tenant_dominio($dominioAtual = null, $novoDominio = null)
    {
        if (! $dominioAtual || ! $novoDominio) {
            echo 'Uso: php index.php tools tenant_dominio "dominio_atual" "novo_dominio"' . PHP_EOL;

            return;
        }

        $this->executarTenant(function (Tenant_manager $tenants) use ($dominioAtual, $novoDominio) {
            $tenant = $tenants->buscarPorDominio($dominioAtual);
            if ($tenant === null) {
                throw new RuntimeException("Nenhum tenant no domínio {$dominioAtual}.");
            }
            $txt = $tenants->adicionarDominio((int) $tenant['id'], $novoDominio);
            echo "Domínio {$novoDominio} cadastrado, aguardando verificação. Publique no DNS:" . PHP_EOL;
            echo "  TXT {$txt['nome']}  \"{$txt['valor']}\"" . PHP_EOL;
            echo "Depois: php index.php tools tenant_verificar {$novoDominio}" . PHP_EOL;
        });
    }

    /**
     * Multi-tenancy: confere o TXT de um domínio próprio e o libera.
     */
    public function tenant_verificar($dominio = null)
    {
        if (! $dominio) {
            echo 'Uso: php index.php tools tenant_verificar "dominio"' . PHP_EOL;

            return;
        }

        $this->executarTenant(function (Tenant_manager $tenants) use ($dominio) {
            echo $tenants->verificarDominio($dominio)
                ? "Domínio {$dominio} verificado." . PHP_EOL
                : "O TXT de {$dominio} ainda não foi encontrado." . PHP_EOL;
        });
    }

    /**
     * Multi-tenancy: leva todos os schemas de tenant à última migration (rodar
     * depois de cada deploy, junto com tenant_mestre).
     */
    public function tenants_migrate()
    {
        $this->executarTenant(function (Tenant_manager $tenants) {
            foreach ($tenants->listar() as $tenant) {
                $tenants->migrarSchema($tenant['schema']);
                echo "{$tenant['nome']} ({$tenant['schema']}): ok" . PHP_EOL;
            }
        });
    }

    /**
     * Painel da plataforma: cria uma conta de administrador da plataforma
     * (banco mestre) e mostra a senha inicial, uma vez.
     */
    public function plataforma_conta($email = null, $nome = null)
    {
        if (! $email || ! $nome) {
            echo 'Uso: php index.php tools plataforma_conta "email" "nome" (espaço como %20 e @ como %40)' . PHP_EOL;

            return;
        }

        $this->executarTenant(function (Tenant_manager $tenants) use ($email, $nome) {
            $contas = $this->contasDaPlataforma($tenants);
            $email = urldecode($email);
            $senha = $contas->criar(urldecode($nome), $email);
            $contas->auditar(null, 'conta_criada', $email . ' (CLI)', 'cli');
            echo "Conta da plataforma criada: {$email}  senha inicial: {$senha}" . PHP_EOL;
        });
    }

    /**
     * Painel da plataforma: nova senha para uma conta (encerra as sessões dela).
     */
    public function plataforma_senha($email = null)
    {
        if (! $email) {
            echo 'Uso: php index.php tools plataforma_senha "email" (@ como %40)' . PHP_EOL;

            return;
        }

        $this->executarTenant(function (Tenant_manager $tenants) use ($email) {
            $contas = $this->contasDaPlataforma($tenants);
            $email = urldecode($email);
            $senha = $contas->redefinirSenha($email);
            $contas->auditar(null, 'senha_redefinida', 'conta da plataforma ' . $email . ' (CLI)', 'cli');
            echo "Nova senha de {$email}: {$senha}" . PHP_EOL;
        });
    }

    private function contasDaPlataforma(Tenant_manager $tenants): Plataforma_contas
    {
        $this->load->library('Plataforma_contas', ['db' => $tenants->conexaoMestre()]);

        return $this->plataforma_contas;
    }

    private function executarTenant(callable $acao)
    {
        $this->load->library('Tenant_manager');

        try {
            $acao($this->tenant_manager);
        } catch (Throwable $erro) {
            log_message('error', 'tools tenant: ' . $erro->getMessage());
            echo 'Erro: ' . $erro->getMessage() . PHP_EOL;
            exit(1);
        }
    }

    public function seeder($name)
    {
        $this->make_seed_file($name);
    }

    public function seed($name = null)
    {
        // initiate faker (pacote de desenvolvimento: ausente em composer install --no-dev)
        if ($this->faker === null && class_exists(\Faker\Factory::class)) {
            $this->faker = \Faker\Factory::create();
        }

        if ($name) {
            $this->seeder->call($name);

            echo 'Seeds run successfully' . PHP_EOL;

            return;
        }

        $seeds = [
            'Permissoes',
            'Usuarios',
            'Configuracoes',
        ];

        foreach ($seeds as $seed) {
            $this->seeder->call($seed);
        }

        echo 'Seeds run successfully' . PHP_EOL;
    }

    protected function make_migration_file($name)
    {
        $date = new DateTime();
        $timestamp = $date->format('YmdHis');

        $path = APPPATH . "database/migrations/$timestamp" . '_' . "$name.php";

        $my_migration = fopen($path, 'w') or exit('Unable to create migration file!');

        $migration_stub_path = APPPATH . 'database/stubs/migration.stub';

        $migration_stub = file_get_contents($migration_stub_path) or exit('Unable to open migration stub!');

        $migration_stub = preg_replace('/{name}/', $name, $migration_stub);

        fwrite($my_migration, $migration_stub);

        fclose($my_migration);

        echo "$path migration has successfully been created." . PHP_EOL;
    }

    protected function make_seed_file($name)
    {
        $className = ucfirst($name);

        $path = APPPATH . "database/seeds/$className.php";

        $my_seed = fopen($path, 'w') or exit('Unable to create seed file!');

        $seed_stub_path = APPPATH . 'database/stubs/seed.stub';

        $seed_stub = file_get_contents($seed_stub_path) or exit('Unable to open seed stub!');

        $seed_stub = preg_replace('/{name}/', $className, $seed_stub);

        fwrite($my_seed, $seed_stub);

        fclose($my_seed);

        echo "$path seeder has successfully been created." . PHP_EOL;
    }
}
