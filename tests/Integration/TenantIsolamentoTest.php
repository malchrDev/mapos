<?php

use PHPUnit\Framework\TestCase;

/**
 * Multi-tenancy contra MySQL real (critério de aceitação da Fase 1).
 *
 * Provisiona tenants de verdade — CREATE DATABASE, todas as migrations de
 * negócio, dados iniciais, INSERT no mestre — e confere que um Host só alcança
 * o próprio schema. Roda em docker/teste/docker-compose.yml (serviço `testes`),
 * com SAAS_DOMINIO_BASE=teste.local e SAAS_PAINEL_HOST=painel.teste.local.
 */
final class TenantIsolamentoTest extends TestCase
{
    private Tenant_manager $tenants;

    protected function setUp(): void
    {
        if (trim((string) ($_ENV['DB_MASTER_DATABASE'] ?? '')) === '') {
            $this->markTestSkipped('Sem DB_MASTER_DATABASE: rode em docker/teste/docker-compose.yml.');
        }

        $this->tenants = new Tenant_manager();
        $this->tenants->migrarMestre();
        $this->limpar();
    }

    protected function tearDown(): void
    {
        if (isset($this->tenants)) {
            $this->limpar();
        }
    }

    /** Remove todo tenant (schema e registro) do mestre de teste. */
    private function limpar(): void
    {
        foreach ($this->tenants->listar() as $tenant) {
            $this->tenants->excluirSchema($tenant['schema']);
        }
        $mestre = $this->conexao($_ENV['DB_MASTER_DATABASE']);
        $mestre->query('DELETE FROM tenants');
        $mestre->close();
    }

    private function conexao(string $banco)
    {
        return DB(integracaoConfigBanco($banco));
    }

    private function provisionar(string $dominio, string $nome = 'Tenant'): array
    {
        return $this->tenants->provisionar(['dominio' => $dominio, 'nome' => $nome, 'admin_email' => 'admin@' . $dominio]);
    }

    private function criarCliente(string $schema, string $nome): void
    {
        $db = $this->conexao($schema);
        $db->insert('clientes', [
            'nomeCliente' => $nome,
            'documento' => '000',
            'telefone' => '000',
            'email' => strtolower($nome) . '@teste.local',
            'senha' => 'x',
        ]);
        $db->close();
    }

    /** O que a aplicação veria numa requisição com este Host. */
    private function clientesVistosPeloHost(string $host): array
    {
        $schema = tenantSchemaDoHost($host);
        $this->assertNotNull($schema, "O host {$host} deveria resolver um tenant.");

        $db = $this->conexao($schema);
        $nomes = array_column($db->get('clientes')->result_array(), 'nomeCliente');
        $db->close();

        return $nomes;
    }

    private function suspender(string $dominio): void
    {
        $tenant = $this->tenants->buscarPorDominio($dominio);
        $mestre = $this->conexao($_ENV['DB_MASTER_DATABASE']);
        $mestre->update('tenants', ['status' => 'suspenso'], ['id' => $tenant['id']]);
        $mestre->close();
    }

    public function testCadaHostSoEnxergaOsDadosDoProprioTenant(): void
    {
        $alfa = $this->provisionar('alfa.teste.local', 'Alfa');
        $beta = $this->provisionar('beta.teste.local', 'Beta');
        $this->assertNotSame($alfa['schema'], $beta['schema']);

        $this->criarCliente($alfa['schema'], 'ClienteAlfa');
        $this->criarCliente($beta['schema'], 'ClienteBeta');

        $this->assertSame(['ClienteAlfa'], $this->clientesVistosPeloHost('alfa.teste.local'));
        $this->assertSame(['ClienteBeta'], $this->clientesVistosPeloHost('BETA.teste.local:8080'));
    }

    public function testProvisionamentoCriaTodasAsTabelasDeNegocioENenhumaDoMestre(): void
    {
        $schema = $this->provisionar('tabelas.teste.local')['schema'];

        $db = $this->conexao($schema);
        $tabelas = $db->list_tables();
        $versao = $db->get('migrations')->row()->version;
        $db->close();

        foreach (['clientes', 'os', 'usuarios', 'permissoes', 'configuracoes', 'emitente', 'migrations', 'ci_sessions'] as $tabela) {
            $this->assertContains($tabela, $tabelas);
        }
        foreach (['tenants', 'tenant_usage', 'tenant_dominios'] as $tabela) {
            $this->assertNotContains($tabela, $tabelas);
        }

        // Versão = última migration de negócio: o painel não pode achar que o
        // banco do tenant está desatualizado.
        $arquivos = glob(APPPATH . Tenant_manager::MIGRATIONS_TENANT . '*_*.php');
        sort($arquivos, SORT_STRING);
        $this->assertSame(substr(basename(end($arquivos)), 0, 14), (string) $versao);
    }

    public function testAdminNasceComOEmailDoPedidoESenhaQueConfere(): void
    {
        $novo = $this->provisionar('admin.teste.local');

        $this->assertSame('admin@admin.teste.local', $novo['admin_email']);
        $this->assertGreaterThanOrEqual(16, strlen($novo['admin_senha']));

        $db = $this->conexao($novo['schema']);
        $usuario = $db->get_where('usuarios', ['email' => 'admin@admin.teste.local'])->row_array();
        $perfil = $db->get_where('permissoes', ['idPermissao' => $usuario['permissoes_id']])->row_array();
        $configs = array_column($db->get('configuracoes')->result_array(), 'valor', 'config');
        $db->close();

        $this->assertTrue(password_verify($novo['admin_senha'], $usuario['senha']));
        $this->assertSame('1', (string) $usuario['situacao']);
        $this->assertSame('Administrador', $perfil['nome']);
        // Mesmas permissões do Administrador de uma instalação nova (banco.sql).
        preg_match("/INSERT IGNORE INTO `permissoes`.*?VALUES\\s*\\(1, 'Administrador', '(.*?)', 1/s", (string) file_get_contents(MAPOS_ROOT . '/banco.sql'), $banco);
        $this->assertSame(unserialize($banco[1]), unserialize($perfil['permissoes']));
        $this->assertSame('Map-OS', $configs['app_name']);
        $this->assertArrayHasKey('os_status_list', $configs);
        // O banco.sql traz documento e endereço de exemplo para o admin; no tenant eles saem.
        $this->assertSame('', $usuario['cpf']);
        $this->assertNull($usuario['rua']);
    }

    /**
     * O instalador só descarta os resultados do multi_query; o provisionamento
     * não pode seguir com um banco.sql que falhou no meio.
     */
    public function testScriptComErroNoMeioFalha(): void
    {
        $db = $this->conexao($_ENV['DB_MASTER_DATABASE']);
        $executar = new ReflectionMethod(Tenant_manager::class, 'executarScript');

        try {
            $executar->invoke($this->tenants, $db->conn_id, 'SELECT 1; SELEC quebrado; SELECT 2;');
            $this->fail('O statement inválido deveria interromper o script.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Erro ao carregar o banco.sql', $e->getMessage());
        } finally {
            $db->close();
        }

        // E um script válido inteiro passa.
        $db = $this->conexao($_ENV['DB_MASTER_DATABASE']);
        $executar->invoke($this->tenants, $db->conn_id, 'SELECT 1; SELECT 2; SET @x = 3;');
        $this->assertSame('3', (string) $db->query('SELECT @x AS x')->row()->x);
        $db->close();
    }

    /**
     * O tenant nasce igual a uma instalação nova (banco.sql + migrations
     * posteriores), não do histórico de migrations desde 2012 — que diverge do
     * banco.sql. Estas colunas são as que divergiam.
     */
    public function testEsquemaDoTenantEOMesmoDeUmaInstalacaoNova(): void
    {
        $schema = $this->provisionar('esquema.teste.local')['schema'];

        $db = $this->conexao($schema);
        $tipos = [];
        foreach ($db->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?', [$schema])->result_array() as $coluna) {
            $tipos[$coluna['TABLE_NAME'] . '.' . $coluna['COLUMN_NAME']] = $coluna['COLUMN_TYPE'] . ($coluna['IS_NULLABLE'] === 'YES' ? ' null' : '');
        }
        $db->close();

        // Os gateways gravam o id da cobrança em texto.
        $this->assertSame('varchar(255) null', $tipos['cobrancas.charge_id']);
        $this->assertSame('varchar(15) null', $tipos['cobrancas.total']);
        $this->assertSame('int null', $tipos['vendas.garantia']);
        $this->assertSame('int', $tipos['lancamentos.usuarios_id']);
        $this->assertSame('varchar(255) null', $tipos['clientes.asaas_id']);
    }

    public function testSenhaDoAdminEDiferenteACadaTenant(): void
    {
        $this->assertNotSame(
            $this->provisionar('senha1.teste.local')['admin_senha'],
            $this->provisionar('senha2.teste.local')['admin_senha']
        );
    }

    public function testCadaTenantTemPastaDeUploadsPropriaEAMigrationNaoVazaOContexto(): void
    {
        $alfa = $this->provisionar('uploads-alfa.teste.local')['schema'];
        $beta = $this->provisionar('uploads-beta.teste.local')['schema'];

        $this->assertNotSame($this->tenants->pastaArmazenamento($alfa), $this->tenants->pastaArmazenamento($beta));
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR . $alfa, $this->tenants->pastaArmazenamento($alfa));

        $GLOBALS['mapos_tenant_schema'] = $beta;
        try {
            $this->tenants->migrarSchema($alfa);
            $this->assertSame($beta, $GLOBALS['mapos_tenant_schema'], 'migrarSchema() devolve o tenant que estava ativo.');
        } finally {
            unset($GLOBALS['mapos_tenant_schema']);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->tenants->pastaArmazenamento('../outro');
    }

    public function testProvisionarNaoMexeNoBancoPadrao(): void
    {
        $padrao = $this->conexao($_ENV['DB_DATABASE']);
        $antes = $padrao->list_tables();

        $this->provisionar('padrao.teste.local');

        $this->assertSame($antes, $padrao->list_tables());
        $padrao->close();
    }

    public function testDominiosQueSanitizamIgualGanhamSchemasDiferentes(): void
    {
        $this->assertNotSame(
            $this->provisionar('a-b.teste.local')['schema'],
            $this->provisionar('ab.teste.local')['schema']
        );
    }

    public function testDominioRepetidoERecusadoMesmoComTenantSuspenso(): void
    {
        $this->provisionar('repetido.teste.local');
        $this->suspender('repetido.teste.local');

        $this->expectException(RuntimeException::class);
        $this->provisionar('repetido.teste.local');
    }

    public function testProvisionamentoForaDoDominioBaseERecusado(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->provisionar('loja.outrodominio.com');
    }

    public function testHostDoPainelNaoPodeSerTenant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->provisionar('painel.teste.local');
    }

    public function testFalhaNoProvisionamentoNaoDeixaSchemaNemRegistro(): void
    {
        $antes = $this->schemasNoCluster();

        try {
            $this->tenants->provisionar(['dominio' => 'falha.teste.local', 'nome' => 'Falha', 'admin_email' => 'nao-e-email']);
            $this->fail('E-mail inválido deveria ser recusado.');
        } catch (InvalidArgumentException $e) {
        }

        $this->assertSame($antes, $this->schemasNoCluster());
        $this->assertNull($this->tenants->buscarPorDominio('falha.teste.local'));
    }

    private function schemasNoCluster(): array
    {
        $db = $this->conexao($_ENV['DB_MASTER_DATABASE']);
        $schemas = array_column($db->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'mapos\\_%' ORDER BY 1")->result_array(), 'SCHEMA_NAME');
        $db->close();

        return $schemas;
    }

    public function testTenantSuspensoNaoResolve(): void
    {
        $this->provisionar('suspenso.teste.local');
        $this->assertNotNull(tenantSchemaDoHost('suspenso.teste.local'));

        $this->suspender('suspenso.teste.local');

        $this->assertSame(['status' => 'nao_encontrado'], tenantResolverHost('suspenso.teste.local'));
    }

    public function testHostDesconhecidoOuInvalidoFalhaFechado(): void
    {
        $this->assertSame(['status' => 'nao_encontrado'], tenantResolverHost('ninguem.teste.local'));
        $this->assertSame(['status' => 'nao_encontrado'], tenantResolverHost("x' OR '1'='1"));
        $this->assertSame(['status' => 'nao_encontrado'], tenantResolverHost(''));
        $this->assertSame(['status' => 'nao_encontrado'], tenantResolverHost('127.0.0.1:8090'));
    }

    public function testHostDoPainelERecononhecido(): void
    {
        $this->assertSame(['status' => 'painel'], tenantResolverHost('Painel.Teste.Local:8090'));
    }

    public function testMestreForaDoArRespondeIndisponivelEnaoOBancoPadrao(): void
    {
        $this->provisionar('fora.teste.local');

        $porta = $_ENV['DB_PORT'];
        $_ENV['DB_PORT'] = '1';
        // O hook registra a falha no error_log; sob teste ele iria para o stderr.
        $log = tempnam(sys_get_temp_dir(), 'mapos');
        $logAnterior = ini_set('error_log', $log);
        try {
            $this->assertSame(['status' => 'indisponivel'], tenantResolverHost('fora.teste.local'));
            $this->assertStringContainsString('falha ao consultar o banco mestre', (string) file_get_contents($log));
        } finally {
            $_ENV['DB_PORT'] = $porta;
            ini_set('error_log', (string) $logAnterior);
            @unlink($log);
        }
    }

    public function testMasterVazioDesligaAResolucao(): void
    {
        $mestre = $_ENV['DB_MASTER_DATABASE'];
        $_ENV['DB_MASTER_DATABASE'] = '';
        try {
            $this->assertSame(['status' => 'desligado'], tenantResolverHost('qualquer.teste.local'));
        } finally {
            $_ENV['DB_MASTER_DATABASE'] = $mestre;
        }
    }

    public function testDominioProprioSoResolveDepoisDeVerificado(): void
    {
        $novo = $this->provisionar('propria.teste.local');
        $this->criarCliente($novo['schema'], 'ClientePropria');

        $txt = $this->tenants->adicionarDominio($novo['id'], 'www.lojapropria.com.br');
        $this->assertSame('_mapos-verificacao.www.lojapropria.com.br', $txt['nome']);
        $this->assertSame(['status' => 'nao_encontrado'], tenantResolverHost('www.lojapropria.com.br'));

        // TXT ausente ou errado: continua sem resolver.
        $this->assertFalse($this->tenants->verificarDominio('www.lojapropria.com.br', fn () => []));
        $this->assertFalse($this->tenants->verificarDominio('www.lojapropria.com.br', fn () => ['mapos-verificacao=outro']));
        $this->assertSame(['status' => 'nao_encontrado'], tenantResolverHost('www.lojapropria.com.br'));

        $consultado = null;
        $this->assertTrue($this->tenants->verificarDominio('www.lojapropria.com.br', function (string $nome) use ($txt, &$consultado) {
            $consultado = $nome;

            return ['v=spf1 -all', $txt['valor']];
        }));
        $this->assertSame($txt['nome'], $consultado);

        // Os dois hostnames chegam ao mesmo schema.
        $this->assertSame(['ClientePropria'], $this->clientesVistosPeloHost('www.lojapropria.com.br'));
        $this->assertSame(['ClientePropria'], $this->clientesVistosPeloHost('propria.teste.local'));
    }

    public function testDominioProprioDeOutroTenantERecusado(): void
    {
        $um = $this->provisionar('um.teste.local');
        $dois = $this->provisionar('dois.teste.local');
        $this->tenants->adicionarDominio($um['id'], 'disputado.com.br');

        $this->expectException(RuntimeException::class);
        $this->tenants->adicionarDominio($dois['id'], 'disputado.com.br');
    }

    public function testSubdominioDaPlataformaNaoEntraComoDominioProprio(): void
    {
        $um = $this->provisionar('base.teste.local');

        $this->expectException(InvalidArgumentException::class);
        $this->tenants->adicionarDominio($um['id'], 'outro.teste.local');
    }

    public function testExcluirTenantLevaOsDominiosJunto(): void
    {
        $novo = $this->provisionar('cascata.teste.local');
        $this->tenants->adicionarDominio($novo['id'], 'cascata.com.br');

        $mestre = $this->conexao($_ENV['DB_MASTER_DATABASE']);
        $mestre->delete('tenants', ['id' => $novo['id']]);
        $restantes = $mestre->where('tenant_id', $novo['id'])->count_all_results('tenant_dominios');
        $mestre->close();
        $this->tenants->excluirSchema($novo['schema']);

        $this->assertSame(0, $restantes);
    }

    public function testCriarSchemaQueJaExisteERecusado(): void
    {
        $schema = $this->provisionar('existe.teste.local')['schema'];

        $this->expectException(RuntimeException::class);
        $this->tenants->criarSchema($schema);
    }

    public function testMigrarSchemaDeTenantExistenteEIdempotente(): void
    {
        $schema = $this->provisionar('idempotente.teste.local')['schema'];

        $this->tenants->migrarSchema($schema);
        $this->tenants->migrarSchema($schema);

        $this->addToAssertionCount(1);
    }

    public function testBaseUrlSegueOHostPedido(): void
    {
        $servidor = $_SERVER;
        try {
            $_SERVER['SCRIPT_NAME'] = '/index.php';
            unset($_SERVER['HTTPS']);
            $this->assertSame('http://loja.teste.local:8090/', tenantBaseUrl('Loja.Teste.Local:8090'));

            $_SERVER['HTTPS'] = 'on';
            $_SERVER['SCRIPT_NAME'] = '/mapos/index.php';
            $this->assertSame('https://loja.teste.local/mapos/', tenantBaseUrl('loja.teste.local'));
        } finally {
            $_SERVER = $servidor;
        }
    }

    /**
     * A migration que cria tenant_dominios leva o domínio que já estava em
     * tenants.dominio, e o rollback o devolve.
     */
    public function testMigrationMoveODominioDeTenantsParaTenantDominios(): void
    {
        $banco = 'mapos_teste_migracao';
        $admin = $this->conexao($_ENV['DB_MASTER_DATABASE']);
        $admin->query("DROP DATABASE IF EXISTS {$banco}");
        $admin->query("CREATE DATABASE {$banco}");
        $admin->close();

        $db = $this->conexao($banco);
        try {
            $this->comMigration($db, fn (CI_Migration $m) => $m->version('20261106000100'));
            $db->insert('tenants', ['dominio' => 'antigo.teste.local', 'nome' => 'Antigo', 'schema' => 'mapos_antigo', 'criado_em' => '2026-11-06 10:00:00']);

            $this->comMigration($db, fn (CI_Migration $m) => $m->latest());
            $this->assertFalse($db->field_exists('dominio', 'tenants'));
            $linha = $db->get('tenant_dominios')->row_array();
            $this->assertSame('antigo.teste.local', $linha['dominio']);
            $this->assertSame('plataforma', $linha['tipo']);
            $this->assertNotNull($linha['verificado_em']);

            $this->comMigration($db, fn (CI_Migration $m) => $m->version('20261106000100'));
            $this->assertFalse($db->table_exists('tenant_dominios'));
            $this->assertSame('antigo.teste.local', $db->get('tenants')->row()->dominio);
        } finally {
            $db->query("DROP DATABASE IF EXISTS {$banco}");
            $db->close();
        }
    }

    private function comMigration($db, callable $acao): void
    {
        $ci = get_instance();
        [$dbAntes, $forgeAntes] = [$ci->db, $ci->dbforge];
        $ci->db = $db;
        try {
            $migration = new CI_Migration([
                'migration_enabled' => true,
                'migration_type' => 'timestamp',
                'migration_table' => 'migrations',
                'migration_auto_latest' => false,
                'migration_version' => '',
                'migration_path' => APPPATH . Tenant_manager::MIGRATIONS_MESTRE,
            ]);
            $this->assertNotFalse($acao($migration), $migration->error_string());
        } finally {
            [$ci->db, $ci->dbforge] = [$dbAntes, $forgeAntes];
        }
    }
}
