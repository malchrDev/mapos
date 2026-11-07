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

    public function testRotasDaGestaoDoPainel(): void
    {
        $this->assertSame('plataforma_acesso/codigo', self::destino('login/codigo'));
        $this->assertSame('plataforma_acesso/verificar', self::destino('login/verificar'));
        $this->assertSame('plataforma_conta', self::destino('conta'));
        $this->assertSame('plataforma_conta/ativar', self::destino('conta/ativar'));
        $this->assertSame('plataforma_conta/senha', self::destino('conta/senha'));
        $this->assertSame('plataforma_equipe', self::destino('contas'));
        $this->assertSame('plataforma_equipe/redefinir2fa', self::destino('contas/redefinir2fa'));
        $this->assertSame('plataforma_planos/formulario', self::destino('planos/novo'));
        $this->assertSame('plataforma_planos/formulario/3', self::destino('planos/3'));
        $this->assertSame('plataforma_planos/status/3', self::destino('planos/3/status'));
        $this->assertSame('plataforma_empresas/excluir/12', self::destino('empresas/12/excluir'));
        $this->assertSame('plataforma_empresas/backup/12', self::destino('empresas/12/backup'));
        $this->assertSame('plataforma_empresas/plano/12', self::destino('empresas/12/plano'));
        $this->assertSame('plataforma_backups', self::destino('backups'));
        $this->assertSame('plataforma_backups/baixar/mapos_loja_ab12cd-20261108-101500.zip', self::destino('backups/mapos_loja_ab12cd-20261108-101500.zip'));
    }

    public function testVencimentoDoPlanoUsaAPaletaSemantica(): void
    {
        $hoje = strtotime('2026-11-08');

        $this->assertNull(plataformaVencimentoPill(null, $hoje));
        $this->assertSame('danger', plataformaVencimentoPill('2026-11-07', $hoje)['variant']);
        $this->assertSame('warning', plataformaVencimentoPill('2026-11-15', $hoje)['variant']);
        $this->assertSame('neutral', plataformaVencimentoPill('2026-12-31', $hoje)['variant']);
        $this->assertSame('1 KB', plataformaTamanho(10));
        $this->assertSame('2,0 MB', plataformaTamanho(2 * 1048576));
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
            'ação inexistente' => ['empresas/1/apagar'],
            'conta com ação inexistente' => ['conta/desativar'],
            'backup fora do padrão de nome' => ['backups/..%2F.env'],
            'backup com outra extensão' => ['backups/mapos_loja-20261108-101500.sql'],
            'ação de conta pelo id na URL' => ['contas/2/senha'],
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
