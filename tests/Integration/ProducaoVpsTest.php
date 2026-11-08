<?php

use PHPUnit\Framework\TestCase;

/**
 * Peças da produção numa VPS dividida com outro sistema (docs/producao-vps.md):
 * subdomínios reservados, arquivo de rotas do Traefik e poda dos backups.
 */
final class ProducaoVpsTest extends TestCase
{
    private Tenant_manager $tenants;

    private string $pasta;

    private array $envAnterior;

    protected function setUp(): void
    {
        if (trim((string) ($_ENV['DB_MASTER_DATABASE'] ?? '')) === '') {
            $this->markTestSkipped('Sem DB_MASTER_DATABASE: rode em docker/teste/docker-compose.yml.');
        }

        $this->envAnterior = $_ENV;
        $this->pasta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mapos-vps-' . bin2hex(random_bytes(4));
        $_ENV['SAAS_TRAEFIK_ARQUIVO'] = $this->pasta . '/traefik/mapos.yml';
        $_ENV['STORAGE_PATH'] = $this->pasta . '/storage';
        unset($_ENV['SAAS_BACKUP_PATH']);

        $this->tenants = new Tenant_manager();
        $this->tenants->migrarMestre();
        $this->limpar();
    }

    protected function tearDown(): void
    {
        if (isset($this->tenants)) {
            $this->limpar();
        }
        $_ENV = $this->envAnterior;
        exec('rm -rf ' . escapeshellarg($this->pasta));
    }

    private function limpar(): void
    {
        foreach ($this->tenants->listar() as $tenant) {
            $this->tenants->excluirSchema($tenant['schema']);
        }
        $this->tenants->conexaoMestre()->query('DELETE FROM tenants');
    }

    private function provisionar(string $dominio): array
    {
        return $this->tenants->provisionar(['dominio' => $dominio, 'nome' => 'Empresa', 'admin_email' => 'admin@' . $dominio]);
    }

    public function testSubdominiosReservadosEComMaisDeUmNivelSaoRecusados(): void
    {
        $_ENV['SAAS_SUBDOMINIOS_RESERVADOS'] = 'zapscout, Outro ';

        foreach (['app.teste.local', 'api.teste.local', 'www.teste.local', 'zapscout.teste.local', 'outro.teste.local', 'a.b.teste.local'] as $dominio) {
            try {
                $this->provisionar($dominio);
                $this->fail('Aceito: ' . $dominio);
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotEmpty($this->provisionar('aplicativo.teste.local')['schema'], 'Nome parecido com um reservado, mas diferente, passa.');
        $this->assertSame([], array_filter($this->tenants->listar(), static fn ($t) => $t['status'] !== 'ativo'));
    }

    public function testRotasTemOPainelEOsDominiosVerificados(): void
    {
        $loja = $this->provisionar('rota1.teste.local');
        $this->tenants->adicionarDominio($loja['id'], 'www.lojapropria.com.br');
        $suspensa = $this->provisionar('rota2.teste.local');
        $this->tenants->alterarStatus($suspensa['id'], 'suspenso');

        $arquivo = $this->tenants->gerarRotasTraefik();
        $rotas = (string) file_get_contents((string) $arquivo);

        $this->assertStringContainsString('Host(`rota1.teste.local`)', $rotas, 'Criar a empresa já gera a rota.');
        $this->assertStringContainsString('Host(`rota2.teste.local`)', $rotas, 'Suspensa continua com rota (404 com HTTPS).');
        $this->assertStringContainsString('Host(`painel.teste.local`)', $rotas);
        $this->assertStringNotContainsString('lojapropria', $rotas, 'Domínio próprio só depois de verificado.');
        $this->assertSame(3, substr_count($rotas, 'certResolver: le'), 'Uma rota (e um certificado) por endereço.');
        $this->assertStringContainsString('url: "http://mapos-nginx:80"', $rotas);
        $this->assertSame([], glob(dirname((string) $arquivo) . '/.*.yml.*') ?: [], 'Sem arquivo temporário sobrando.');

        $propria = array_values(array_filter($this->tenants->dominios($loja['id']), static fn ($d) => $d['dominio'] === 'www.lojapropria.com.br'))[0];
        $txt = Tenant_manager::registroVerificacao('www.lojapropria.com.br', (string) $propria['verificacao_token']);
        $this->tenants->verificarDominio('www.lojapropria.com.br', static fn () => [$txt['valor']]);
        $this->assertStringContainsString('Host(`www.lojapropria.com.br`)', (string) file_get_contents((string) $arquivo), 'Verificar o domínio atualiza as rotas.');
    }

    public function testDominioComCaractereEstranhoNaoEntraNaRegra(): void
    {
        $loja = $this->provisionar('rota3.teste.local');
        $this->tenants->conexaoMestre()->insert('tenant_dominios', ['tenant_id' => $loja['id'], 'dominio' => 'x.com`) || PathPrefix(`/', 'tipo' => 'proprio', 'verificado_em' => date('Y-m-d H:i:s'), 'criado_em' => date('Y-m-d H:i:s')]);

        $rotas = (string) file_get_contents((string) $this->tenants->gerarRotasTraefik());

        $this->assertStringNotContainsString('PathPrefix', $rotas);
        $this->assertStringContainsString('Host(`rota3.teste.local`)', $rotas);
    }

    public function testConfiguracaoInvalidaDoTraefikERecusadaESemArquivoNadaAcontece(): void
    {
        $_ENV['SAAS_TRAEFIK_CERTRESOLVER'] = "le\n    rule: x";
        try {
            $this->tenants->gerarRotasTraefik();
            $this->fail('Resolvedor com quebra de linha aceito.');
        } catch (InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }

        $_ENV['SAAS_TRAEFIK_ARQUIVO'] = '';
        $this->assertNull($this->tenants->gerarRotasTraefik());
    }

    public function testPodaApagaSoOsAntigosDaEmpresaEMantemOsMaisNovos(): void
    {
        $loja = $this->provisionar('poda.teste.local');
        $pasta = $this->tenants->pastaBackups();
        $antigo = time() - 30 * 86400;
        foreach (['20260101-000000', '20260102-000000', '20260103-000000', '20260104-000000', '20260105-000000'] as $quando) {
            touch($pasta . '/' . $loja['schema'] . '-' . $quando . '.zip', $antigo);
        }
        $excluida = $pasta . '/mapos_empresa_excluida_abc123-20250101-000000.zip';
        touch($excluida, $antigo);

        $this->assertSame(2, $this->tenants->podarBackups($loja['schema'], 14), '5 antigos, ficam os 3 mais novos.');
        $this->assertCount(3, $this->tenants->listarBackups($loja['schema']));
        $this->assertFileExists($excluida, 'Backup final de empresa excluída não é podado.');
        $this->assertSame(0, $this->tenants->podarBackups($loja['schema'], 14));
    }
}
