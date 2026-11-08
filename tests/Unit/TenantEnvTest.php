<?php

require_once APPPATH . 'helpers/tenant_helper.php';
require_once APPPATH . 'helpers/configuracoes_helper.php';

/**
 * Configuração por empresa no SaaS (configuracoes.tenant_env): a empresa
 * configura gateways, API e impressão para si, sem tocar no .env da
 * plataforma, e nunca herda as credenciais de pagamento dela.
 */
final class TenantEnvTest extends MaposTestCase
{
    private array $envAnterior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envAnterior = $_ENV;
        $_ENV['APP_ENCRYPTION_KEY'] = 'chave-de-teste-da-instalacao-com-32-caracteres';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envAnterior;
        unset($GLOBALS['mapos_tenant_schema']);
        parent::tearDown();
    }

    public function testCredencialDePagamentoDaPlataformaNaoChegaNaEmpresa(): void
    {
        $_ENV['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY'] = 'chave-da-plataforma';
        $_ENV['PAYMENT_GATEWAYS_EFI_CREDENTIAIS_CLIENT_SECRET'] = 'segredo-da-plataforma';
        $_ENV['API_ENABLED'] = 'true';

        tenantEnvAplicar(null);

        $this->assertArrayNotHasKey('PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY', $_ENV);
        $this->assertArrayNotHasKey('PAYMENT_GATEWAYS_EFI_CREDENTIAIS_CLIENT_SECRET', $_ENV);
        $this->assertSame('true', $_ENV['API_ENABLED'], 'O que não é credencial herda o padrão da plataforma.');
    }

    public function testValoresDaEmpresaEntramESegredoAbre(): void
    {
        $json = tenantEnvMesclar(null, ['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY' => 'chave-da-loja', 'API_ENABLED' => 'false'], ['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY']);

        $this->assertStringNotContainsString('chave-da-loja', $json, 'O segredo vai cifrado para o banco.');

        tenantEnvAplicar($json);
        $this->assertSame('chave-da-loja', $_ENV['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY']);
        $this->assertSame('false', $_ENV['API_ENABLED']);
    }

    public function testJsonNaoTrocaOQueEDaPlataforma(): void
    {
        $_ENV['DB_PASSWORD'] = 'senha-do-banco';
        $_ENV['EMAIL_SMTP_HOST'] = 'smtp.plataforma.com';

        tenantEnvAplicar((string) json_encode(['DB_PASSWORD' => 'x', 'EMAIL_SMTP_HOST' => 'smtp.atacante.com', 'API_JWT_KEY' => 'x', 'STORAGE_PATH' => '/']));

        $this->assertSame('senha-do-banco', $_ENV['DB_PASSWORD']);
        $this->assertSame('smtp.plataforma.com', $_ENV['EMAIL_SMTP_HOST']);
        $this->assertSame($this->envAnterior['STORAGE_PATH'] ?? null, $_ENV['STORAGE_PATH'] ?? null);

        $this->expectException(InvalidArgumentException::class);
        tenantEnvMesclar(null, ['EMAIL_SMTP_HOST' => 'smtp.atacante.com'], []);
    }

    public function testSegredoDeOutraChaveFicaVazio(): void
    {
        $json = tenantEnvMesclar(null, ['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY' => 'chave-da-loja'], ['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY']);

        $_ENV['APP_ENCRYPTION_KEY'] = 'outra-chave-de-instalacao-com-32-caracteres!!';
        $log = tempnam(sys_get_temp_dir(), 'log');
        $anterior = ini_set('error_log', $log);
        try {
            tenantEnvAplicar($json);
        } finally {
            ini_set('error_log', (string) $anterior);
            @unlink($log);
        }

        $this->assertSame('', $_ENV['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY']);
    }

    public function testMesclarMantemOQueJaEstavaESegredoEmBrancoNaoApaga(): void
    {
        $primeiro = tenantEnvMesclar(null, ['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY' => 'chave-1', 'IMPRIMIR_ANEXOS' => 'true'], ['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY']);
        // Segredo em branco não vem no array (configuracaoDadosDoFormulario): fica o anterior.
        $segundo = tenantEnvMesclar($primeiro, ['IMPRIMIR_ANEXOS' => 'false'], ['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY']);

        tenantEnvAplicar($segundo);
        $this->assertSame('chave-1', $_ENV['PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY']);
        $this->assertSame('false', $_ENV['IMPRIMIR_ANEXOS']);
    }

    public function testListasCobremTodoCampoDoEnvDaTela(): void
    {
        $campos = [];
        foreach (array_keys(CONFIG_ABAS) as $aba) {
            foreach (configuracaoCamposDaInstalacao($aba) as $nome => $campo) {
                if ($campo['destino'] === 'env') {
                    $campos[] = $nome;
                }
            }
        }

        $this->assertEqualsCanonicalizing($campos, array_merge(array_keys(TENANT_ENV_CHAVES), TENANT_ENV_PLATAFORMA), 'Campo novo do .env na tela precisa ser da empresa ou da plataforma.');
        $this->assertSame([], array_intersect(array_keys(TENANT_ENV_CHAVES), TENANT_ENV_PLATAFORMA));
    }

    public function testNaEmpresaSomemSmtpChaveJwtEAbaDeEmail(): void
    {
        $this->assertArrayHasKey('email', configuracaoAbas());
        $this->assertArrayHasKey('API_JWT_KEY', configuracaoCampos('api'));

        $GLOBALS['mapos_tenant_schema'] = 'mapos_loja_abc123';

        $this->assertArrayNotHasKey('email', configuracaoAbas());
        $this->assertSame([], configuracaoCampos('email'));
        $this->assertArrayNotHasKey('API_JWT_KEY', configuracaoCampos('api'));
        $this->assertArrayHasKey('API_ENABLED', configuracaoCampos('api'));
        $this->assertArrayHasKey('PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY', configuracaoCampos('pagamentos'));

        [, $env] = configuracaoDadosDoFormulario('email', ['EMAIL_SMTP_HOST' => 'smtp.atacante.com']);
        $this->assertSame([], $env, 'POST com campo de SMTP não chega ao gravador.');
    }
}
