<?php

require_once APPPATH . 'helpers/plataforma_helper.php';

/**
 * Rotas do painel da plataforma (config/routes_plataforma.php): no hostname do
 * painel só os controllers Plataforma_* são alcançáveis; qualquer tela de
 * tenant ou da API cai no 404.
 *
 * O roteamento é o do CI_Router::_parse_routes(): rota literal primeiro,
 * depois as regex na ordem, com :any e :num trocados.
 */
final class PlataformaRotasTest extends MaposTestCase
{
    private static function rotas(): array
    {
        $route = ['ja_existia' => 'mapos'];
        require APPPATH . 'config/routes_plataforma.php';

        return $route;
    }

    private static function destino(string $uri): string
    {
        $rotas = self::rotas();
        unset($rotas['default_controller'], $rotas['404_override'], $rotas['translate_uri_dashes']);

        if (isset($rotas[$uri])) {
            return $rotas[$uri];
        }

        foreach ($rotas as $chave => $valor) {
            $chave = str_replace([':any', ':num'], ['[^/]+', '[0-9]+'], $chave);
            if (preg_match('#^' . $chave . '$#', $uri, $m)) {
                return preg_replace('#^' . $chave . '$#', $valor, $uri);
            }
        }

        return $uri;
    }

    public function testTabelaDoPainelSubstituiAsRotasDoTenant(): void
    {
        $rotas = self::rotas();

        $this->assertArrayNotHasKey('ja_existia', $rotas, 'Rotas do tenant (e da API) não podem sobrar no painel.');
        $this->assertSame('plataforma_empresas', $rotas['default_controller']);
    }

    public function testRotasDoPainelChegamAosControllersDaPlataforma(): void
    {
        $this->assertSame('plataforma_acesso', self::destino('login'));
        $this->assertSame('plataforma_acesso/entrar', self::destino('login/entrar'));
        $this->assertSame('plataforma_acesso/sair', self::destino('sair'));
        $this->assertSame('plataforma_empresas', self::destino('empresas'));
        $this->assertSame('plataforma_empresas/nova', self::destino('empresas/nova'));
        $this->assertSame('plataforma_empresas/ver/12', self::destino('empresas/12'));
        $this->assertSame('plataforma_empresas/status/12', self::destino('empresas/12/status'));
        $this->assertSame('plataforma_empresas/senha/12', self::destino('empresas/12/senha'));
        $this->assertSame('plataforma_auditoria', self::destino('auditoria'));
    }

    /**
     * @dataProvider urisForaDoPainel
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('urisForaDoPainel')]
    public function testQualquerOutraUriNoPainelE404(string $uri): void
    {
        $this->assertSame('plataforma_acesso/nao_encontrado', self::destino($uri));
    }

    public static function urisForaDoPainel(): array
    {
        return [
            'clientes do tenant' => ['clientes'],
            'OS do tenant' => ['os/visualizar/1'],
            'login do tenant' => ['login/verificarLogin'],
            'área do cliente' => ['mine'],
            'API' => ['api/v1/login'],
            'controller do painel pelo nome' => ['plataforma_empresas/ver/1'],
            'ferramentas' => ['tools/migrate'],
            'empresa sem id numérico' => ['empresas/abc'],
            'ação inexistente' => ['empresas/1/excluir'],
        ];
    }

    public function testStatusDoTenantUsaAPaletaSemantica(): void
    {
        $this->assertSame(['label' => 'Ativa', 'variant' => 'success'], plataformaStatusPill('ativo'));
        $this->assertSame(['label' => 'Suspensa', 'variant' => 'warning'], plataformaStatusPill('suspenso'));
        $this->assertSame(['label' => 'Cancelada', 'variant' => 'neutral'], plataformaStatusPill('cancelado'));
    }

    public function testAcaoDeAuditoriaVemLegivel(): void
    {
        $this->assertSame('Suspendeu a empresa', plataformaAcaoLegivel('empresa_suspensa'));
        $this->assertSame('acao_nova', plataformaAcaoLegivel('acao_nova'));
    }
}
