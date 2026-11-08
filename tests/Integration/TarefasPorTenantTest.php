<?php

use PHPUnit\Framework\TestCase;

/**
 * Tarefas agendadas por empresa contra MySQL real: o hook na linha de comando
 * (MAPOS_TENANT_SCHEMA) só aceita empresa ativa, e o executor roda cada
 * empresa num processo próprio, com o ambiente inteiro e tempo máximo.
 */
final class TarefasPorTenantTest extends TestCase
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
        putenv('MAPOS_TENANT_SCHEMA');
    }

    private function limpar(): void
    {
        foreach ($this->tenants->listar() as $tenant) {
            $this->tenants->excluirSchema($tenant['schema']);
        }
        $this->tenants->conexaoMestre()->query('DELETE FROM tenants');
    }

    public function testCliSoResolveEmpresaAtiva(): void
    {
        $empresa = $this->tenants->provisionar(['dominio' => 'cli.teste.local', 'nome' => 'CLI', 'admin_email' => 'admin@cli.teste.local']);

        $this->assertSame(['status' => 'tenant', 'dominio' => 'cli.teste.local'], tenantResolverSchema($empresa['schema']));

        $this->tenants->alterarStatus($empresa['id'], 'suspenso');
        $this->assertSame('nao_encontrado', tenantResolverSchema($empresa['schema'])['status'], 'Suspensa não roda tarefa.');

        foreach (['mapos_naoexiste', 'mapos_x`;DROP', $_ENV['DB_MASTER_DATABASE'], '', 'MAPOS_A'] as $invalido) {
            $this->assertSame('nao_encontrado', tenantResolverSchema($invalido)['status'], $invalido);
        }
    }

    public function testCliComMestreForaDoArFicaIndisponivel(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'log');
        $logAnterior = ini_set('error_log', $log);
        $hostAnterior = $_ENV['DB_HOSTNAME'];
        $_ENV['DB_HOSTNAME'] = '127.0.0.1';
        $_ENV['DB_PORT_ANTERIOR'] = $_ENV['DB_PORT'];
        $_ENV['DB_PORT'] = '1';

        try {
            $this->assertSame('indisponivel', tenantResolverSchema('mapos_qualquer')['status']);
        } finally {
            $_ENV['DB_HOSTNAME'] = $hostAnterior;
            $_ENV['DB_PORT'] = $_ENV['DB_PORT_ANTERIOR'];
            unset($_ENV['DB_PORT_ANTERIOR']);
            ini_set('error_log', (string) $logAnterior);
            @unlink($log);
        }
    }

    public function testHookCarregaAConfiguracaoDoBancoDaEmpresa(): void
    {
        $anterior = $_ENV;
        $_ENV['APP_ENCRYPTION_KEY'] = 'chave-de-teste-da-instalacao-com-32-caracteres';
        $_ENV['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY'] = 'chave-da-plataforma';

        try {
            $loja = $this->tenants->provisionar(['dominio' => 'env1.teste.local', 'nome' => 'Env 1', 'admin_email' => 'admin@env1.teste.local']);
            $outra = $this->tenants->provisionar(['dominio' => 'env2.teste.local', 'nome' => 'Env 2', 'admin_email' => 'admin@env2.teste.local']);

            $banco = DB(integracaoConfigBanco($loja['schema']));
            $banco->insert('configuracoes', ['config' => 'tenant_env', 'valor' => tenantEnvMesclar(null, ['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY' => 'chave-da-loja'], ['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY'])]);
            $banco->close();

            tenantCarregarEnv($loja['schema']);
            $this->assertSame('chave-da-loja', $_ENV['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY']);

            $_ENV['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY'] = 'chave-da-plataforma';
            tenantCarregarEnv($outra['schema']);
            $this->assertArrayNotHasKey('PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY', $_ENV, 'Empresa sem configuração não herda a da plataforma nem a de outra.');
        } finally {
            $_ENV = $anterior;
        }
    }

    public function testBaseUrlDaCliUsaODominioDaEmpresa(): void
    {
        $anterior = $_ENV['APP_BASEURL'] ?? null;
        try {
            $_ENV['APP_BASEURL'] = 'http://localhost:8090/';
            $this->assertSame('http://loja.teste.local/', tenantBaseUrlCli('loja.teste.local'));
            $_ENV['APP_BASEURL'] = '';
            $this->assertSame('https://loja.teste.local/', tenantBaseUrlCli('loja.teste.local'));
        } finally {
            $_ENV['APP_BASEURL'] = $anterior;
        }
    }

    public function testProcessoDaTarefaRecebeOSchemaEOAmbienteInteiro(): void
    {
        putenv('MAPOS_TESTE_VAZIA=');
        try {
            $resultado = $this->tenants->executarTarefa('mapos_loja_abc123', [PHP_BINARY, '-r', 'echo getenv("MAPOS_TENANT_SCHEMA"), "|", var_export(getenv("MAPOS_TESTE_VAZIA"), true); exit(7);']);
        } finally {
            putenv('MAPOS_TESTE_VAZIA');
        }

        $this->assertSame(7, $resultado['codigo'], 'O código de saída do filho volta.');
        $this->assertSame("mapos_loja_abc123|''", $resultado['saida'], 'Variável vazia chega vazia, não some (EMAIL_SMTP_CRYPTO=).');
        $this->assertFalse(getenv('MAPOS_TENANT_SCHEMA'), 'O processo pai não fica com o schema.');
    }

    public function testTarefaQueEstouraOTempoEInterrompida(): void
    {
        $resultado = $this->tenants->executarTarefa('mapos_loja_abc123', [PHP_BINARY, '-r', 'sleep(30);'], 1);

        $this->assertSame(-1, $resultado['codigo']);
        $this->assertLessThan(10, $resultado['tempo']);
    }

    public function testSchemaInvalidoNaoIniciaProcesso(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->tenants->executarTarefa('../mapos', [PHP_BINARY, '-r', 'echo 1;']);
    }
}
