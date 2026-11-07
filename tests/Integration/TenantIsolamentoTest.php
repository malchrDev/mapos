<?php

use PHPUnit\Framework\TestCase;

/**
 * Multi-tenancy contra MySQL real (critério de aceitação da Fase 1).
 *
 * Provisiona tenants de verdade — CREATE DATABASE, todas as migrations de
 * negócio, INSERT no mestre — e confere que um Host só alcança o próprio
 * schema. Roda em docker/teste/docker-compose.yml.
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

    /** Remove todo tenant (schema e registro) deixado por um teste. */
    private function limpar(): void
    {
        $mestre = $this->conexao($_ENV['DB_MASTER_DATABASE']);
        foreach ($this->tenants->listar() as $tenant) {
            $this->tenants->excluirSchema($tenant['schema']);
        }
        $mestre->query('DELETE FROM tenants');
        $mestre->close();
    }

    private function conexao(string $banco)
    {
        return DB(integracaoConfigBanco($banco));
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

    public function testCadaHostSoEnxergaOsDadosDoProprioTenant(): void
    {
        $this->tenants->provisionar(['dominio' => 'alfa.teste.local', 'nome' => 'Alfa']);
        $this->tenants->provisionar(['dominio' => 'beta.teste.local', 'nome' => 'Beta']);

        $alfa = $this->tenants->buscarPorDominio('alfa.teste.local');
        $beta = $this->tenants->buscarPorDominio('beta.teste.local');
        $this->assertNotSame($alfa['schema'], $beta['schema']);

        $this->criarCliente($alfa['schema'], 'ClienteAlfa');
        $this->criarCliente($beta['schema'], 'ClienteBeta');

        $this->assertSame(['ClienteAlfa'], $this->clientesVistosPeloHost('alfa.teste.local'));
        $this->assertSame(['ClienteBeta'], $this->clientesVistosPeloHost('BETA.teste.local:8080'));
    }

    public function testProvisionamentoCriaTodasAsTabelasDeNegocioENenhumaDoMestre(): void
    {
        $this->tenants->provisionar(['dominio' => 'tabelas.teste.local', 'nome' => 'Tabelas']);
        $schema = $this->tenants->buscarPorDominio('tabelas.teste.local')['schema'];

        $db = $this->conexao($schema);
        $tabelas = $db->list_tables();
        $versao = $db->get('migrations')->row()->version;
        $db->close();

        foreach (['clientes', 'os', 'usuarios', 'permissoes', 'configuracoes', 'emitente', 'migrations'] as $tabela) {
            $this->assertContains($tabela, $tabelas);
        }
        $this->assertNotContains('tenants', $tabelas);
        $this->assertNotContains('tenant_usage', $tabelas);

        // Versão = última migration de negócio: o painel não pode achar que o
        // banco do tenant está desatualizado.
        $arquivos = glob(APPPATH . Tenant_manager::MIGRATIONS_TENANT . '*_*.php');
        sort($arquivos, SORT_STRING);
        $this->assertSame(substr(basename(end($arquivos)), 0, 14), (string) $versao);
    }

    public function testProvisionarNaoMexeNoBancoPadrao(): void
    {
        $padrao = $this->conexao($_ENV['DB_DATABASE']);
        $antes = $padrao->list_tables();

        $this->tenants->provisionar(['dominio' => 'padrao.teste.local', 'nome' => 'Padrao']);

        $this->assertSame($antes, $padrao->list_tables());
        $padrao->close();
    }

    public function testDominiosQueSanitizamIgualGanhamSchemasDiferentes(): void
    {
        $this->tenants->provisionar(['dominio' => 'a-b.teste.local', 'nome' => 'Com hifen']);
        $this->tenants->provisionar(['dominio' => 'ab.teste.local', 'nome' => 'Sem hifen']);

        $this->assertNotSame(
            $this->tenants->buscarPorDominio('a-b.teste.local')['schema'],
            $this->tenants->buscarPorDominio('ab.teste.local')['schema']
        );
    }

    public function testDominioRepetidoERecusadoMesmoComTenantSuspenso(): void
    {
        $this->tenants->provisionar(['dominio' => 'repetido.teste.local', 'nome' => 'Primeiro']);
        $mestre = $this->conexao($_ENV['DB_MASTER_DATABASE']);
        $mestre->update('tenants', ['status' => 'suspenso'], ['dominio' => 'repetido.teste.local']);
        $mestre->close();

        $this->expectException(RuntimeException::class);
        $this->tenants->provisionar(['dominio' => 'repetido.teste.local', 'nome' => 'Segundo']);
    }

    public function testTenantSuspensoNaoResolve(): void
    {
        $this->tenants->provisionar(['dominio' => 'suspenso.teste.local', 'nome' => 'Suspenso']);
        $this->assertNotNull(tenantSchemaDoHost('suspenso.teste.local'));

        $mestre = $this->conexao($_ENV['DB_MASTER_DATABASE']);
        $mestre->update('tenants', ['status' => 'suspenso'], ['dominio' => 'suspenso.teste.local']);
        $mestre->close();

        $this->assertNull(tenantSchemaDoHost('suspenso.teste.local'));
    }

    public function testHostDesconhecidoOuInvalidoNaoResolve(): void
    {
        $this->assertNull(tenantSchemaDoHost('ninguem.teste.local'));
        $this->assertNull(tenantSchemaDoHost("x' OR '1'='1"));
        $this->assertNull(tenantSchemaDoHost(''));
    }

    public function testCriarSchemaQueJaExisteERecusado(): void
    {
        $this->tenants->provisionar(['dominio' => 'existe.teste.local', 'nome' => 'Existe']);
        $schema = $this->tenants->buscarPorDominio('existe.teste.local')['schema'];

        $this->expectException(RuntimeException::class);
        $this->tenants->criarSchema($schema);
    }

    public function testMasterVazioDesligaAResolucao(): void
    {
        $this->tenants->provisionar(['dominio' => 'desligado.teste.local', 'nome' => 'Desligado']);

        $mestre = $_ENV['DB_MASTER_DATABASE'];
        $_ENV['DB_MASTER_DATABASE'] = '';
        try {
            $this->assertNull(tenantSchemaDoHost('desligado.teste.local'));
        } finally {
            $_ENV['DB_MASTER_DATABASE'] = $mestre;
        }
    }

    public function testMigrarSchemaDeTenantExistenteEIdempotente(): void
    {
        $this->tenants->provisionar(['dominio' => 'idempotente.teste.local', 'nome' => 'Idempotente']);
        $schema = $this->tenants->buscarPorDominio('idempotente.teste.local')['schema'];

        $this->tenants->migrarSchema($schema);
        $this->tenants->migrarSchema($schema);

        $this->addToAssertionCount(1);
    }
}
