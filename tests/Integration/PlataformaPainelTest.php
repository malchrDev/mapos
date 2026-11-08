<?php

require_once APPPATH . 'libraries/Plataforma_contas.php';
require_once APPPATH . 'libraries/Limite_login.php';

use PHPUnit\Framework\TestCase;

/**
 * Painel administrativo da plataforma contra MySQL real: contas e auditoria
 * no banco mestre, e as operações do painel sobre os tenants (status,
 * usuários, nova senha).
 */
final class PlataformaPainelTest extends TestCase
{
    private Tenant_manager $tenants;

    private $mestre;

    private Plataforma_contas $contas;

    protected function setUp(): void
    {
        if (trim((string) ($_ENV['DB_MASTER_DATABASE'] ?? '')) === '') {
            $this->markTestSkipped('Sem DB_MASTER_DATABASE: rode em docker/teste/docker-compose.yml.');
        }

        $this->tenants = new Tenant_manager();
        $this->tenants->migrarMestre();
        $this->mestre = $this->tenants->conexaoMestre();
        $this->contas = new Plataforma_contas(['db' => $this->mestre]);
        $this->limpar();
    }

    protected function tearDown(): void
    {
        if (isset($this->tenants)) {
            $this->limpar();
        }
    }

    private function limpar(): void
    {
        foreach ($this->tenants->listar() as $tenant) {
            $this->tenants->excluirSchema($tenant['schema']);
        }
        foreach (['tenants', 'plataforma_usuarios', 'plataforma_auditoria', 'login_attempts'] as $tabela) {
            $this->mestre->query("DELETE FROM {$tabela}");
        }
    }

    private function provisionar(string $dominio): array
    {
        return $this->tenants->provisionar(['dominio' => $dominio, 'nome' => 'Empresa ' . $dominio, 'admin_email' => 'admin@' . $dominio]);
    }

    public function testMestreTemAsTabelasDoPainel(): void
    {
        foreach (['ci_sessions', 'login_attempts', 'plataforma_usuarios', 'plataforma_auditoria'] as $tabela) {
            $this->assertTrue($this->mestre->table_exists($tabela), $tabela);
        }
    }

    public function testContaNovaTemSenhaAleatoriaQueConfere(): void
    {
        $senha = $this->contas->criar('Ana', 'Ana@Plataforma.com');
        $conta = $this->contas->porEmail('ana@plataforma.com');

        $this->assertNotNull($conta);
        $this->assertSame('ana@plataforma.com', $conta->email);
        $this->assertTrue(password_verify($senha, $conta->senha));
        $this->assertNotSame($senha, $conta->senha);
        $this->assertSame('1', (string) $conta->ativo);
    }

    public function testEmailRepetidoERecusado(): void
    {
        $this->contas->criar('Ana', 'ana@plataforma.com');

        $this->expectException(RuntimeException::class);
        $this->contas->criar('Outra Ana', 'ANA@plataforma.com');
    }

    public function testRedefinirSenhaInvalidaASessaoAberta(): void
    {
        $antiga = $this->contas->criar('Ana', 'ana@plataforma.com');
        $versao = (int) $this->contas->porEmail('ana@plataforma.com')->sessao_versao;

        $nova = $this->contas->redefinirSenha('ana@plataforma.com');
        $conta = $this->contas->porEmail('ana@plataforma.com');

        $this->assertNotSame($antiga, $nova);
        $this->assertFalse(password_verify($antiga, $conta->senha));
        $this->assertTrue(password_verify($nova, $conta->senha));
        // Plataforma_Controller::exigirLogin() compara com a versão guardada na sessão.
        $this->assertSame($versao + 1, (int) $conta->sessao_versao);
    }

    public function testAuditoriaGuardaQuemOQueEOnde(): void
    {
        $this->contas->criar('Ana', 'ana@plataforma.com');
        $conta = $this->contas->porEmail('ana@plataforma.com');
        $novo = $this->provisionar('auditada.teste.local');

        $this->contas->auditar($conta, 'empresa_suspensa', null, '10.0.0.1', $novo['id']);
        $this->contas->auditar(null, 'login_bloqueado', 'tentativas demais', '10.0.0.2');

        $daEmpresa = $this->contas->auditoria(10, $novo['id']);
        $this->assertCount(1, $daEmpresa);
        $this->assertSame('Ana', $daEmpresa[0]->usuario_nome);
        $this->assertSame('empresa_suspensa', $daEmpresa[0]->acao);
        $this->assertSame('10.0.0.1', $daEmpresa[0]->ip);

        $todas = $this->contas->auditoria(10);
        $this->assertCount(2, $todas);
        $this->assertSame('Sistema', $todas[0]->usuario_nome, 'Mais recente primeiro; sem conta, "Sistema".');
    }

    public function testLimiteDeLoginDaPlataformaBloqueiaNoMestre(): void
    {
        $limite = new Limite_login(['db' => $this->mestre, 'auditar' => fn () => null, 'segredo' => 'teste']);

        for ($i = 0; $i < Limite_login::LIMITE_EMAIL; $i++) {
            $limite->registrarFalha('plataforma', 'ana@plataforma.com', '10.0.0.9');
        }

        $this->assertTrue($limite->bloqueado('plataforma', 'ana@plataforma.com', '10.0.0.9'));
        // Escopos separados: o bloqueio do painel não trava o login das lojas.
        $this->assertFalse($limite->bloqueado('usuario', 'ana@plataforma.com', '10.0.0.9'));
    }

    public function testSuspenderEReativarMudaOQueOHookResolve(): void
    {
        $novo = $this->provisionar('situacao.teste.local');
        $this->assertNotNull(tenantSchemaDoHost('situacao.teste.local'));

        $this->tenants->alterarStatus($novo['id'], 'suspenso');
        $this->assertNull(tenantSchemaDoHost('situacao.teste.local'));

        $this->tenants->alterarStatus($novo['id'], 'ativo');
        $this->assertSame($novo['schema'], tenantSchemaDoHost('situacao.teste.local'));
    }

    public function testStatusInvalidoERecusado(): void
    {
        $novo = $this->provisionar('invalido.teste.local');

        $this->expectException(InvalidArgumentException::class);
        $this->tenants->alterarStatus($novo['id'], 'apagado');
    }

    public function testListagemDoPainelTrazDominioEFiltra(): void
    {
        $um = $this->provisionar('listaum.teste.local');
        $this->provisionar('listadois.teste.local');
        $this->tenants->adicionarDominio($um['id'], 'www.listaum.com.br');
        $this->tenants->alterarStatus($um['id'], 'suspenso');

        $todas = $this->tenants->listarParaPainel();
        $this->assertCount(2, $todas);

        $porDominioProprio = $this->tenants->listarParaPainel('www.listaum');
        $this->assertCount(1, $porDominioProprio);
        $this->assertSame('listaum.teste.local', $porDominioProprio[0]['dominio']);
        $this->assertSame(2, (int) $porDominioProprio[0]['total_dominios']);

        $this->assertCount(1, $this->tenants->listarParaPainel('', 'suspenso'));
        $this->assertCount(1, $this->tenants->listarParaPainel('', 'ativo'));
        // Curinga de LIKE na busca é literal, não "qualquer coisa".
        $this->assertCount(0, $this->tenants->listarParaPainel('%'));
    }

    public function testUsuariosDoTenantNaoExpoemSenha(): void
    {
        $novo = $this->provisionar('usuarios.teste.local');

        $usuarios = $this->tenants->usuariosDoTenant($novo['id']);

        $this->assertCount(1, $usuarios);
        $this->assertSame('admin@usuarios.teste.local', $usuarios[0]['email']);
        $this->assertSame('Administrador', $usuarios[0]['perfil']);
        $this->assertSame(['idUsuarios', 'nome', 'email', 'situacao', 'perfil'], array_keys($usuarios[0]));
    }

    public function testNovaSenhaDoUsuarioDoTenantDerrubaSessaoEToken(): void
    {
        $novo = $this->provisionar('senha.teste.local');
        $usuario = $this->tenants->usuariosDoTenant($novo['id'])[0];

        $db = DB(integracaoConfigBanco($novo['schema']));
        $antes = $db->get_where('usuarios', ['idUsuarios' => $usuario['idUsuarios']])->row();

        $senha = $this->tenants->redefinirSenhaUsuario($novo['id'], $usuario['idUsuarios']);

        $depois = $db->get_where('usuarios', ['idUsuarios' => $usuario['idUsuarios']])->row();
        $db->close();

        $this->assertFalse(password_verify($novo['admin_senha'], $depois->senha));
        $this->assertTrue(password_verify($senha, $depois->senha));
        $this->assertSame((int) $antes->sessao_versao + 1, (int) $depois->sessao_versao);
        $this->assertSame((int) $antes->token_versao + 1, (int) $depois->token_versao);
    }

    public function testNovaSenhaParaUsuarioDeOutroTenantERecusada(): void
    {
        $um = $this->provisionar('donoum.teste.local');
        $dois = $this->provisionar('donodois.teste.local');
        // Mesmo id (1) existe nos dois schemas; o que vale é o tenant pedido.
        $idDoOutro = $this->tenants->usuariosDoTenant($dois['id'])[0]['idUsuarios'];

        $this->tenants->redefinirSenhaUsuario($um['id'], $idDoOutro);

        $db = DB(integracaoConfigBanco($dois['schema']));
        $doOutro = $db->get_where('usuarios', ['idUsuarios' => $idDoOutro])->row();
        $db->close();
        $this->assertTrue(password_verify($dois['admin_senha'], $doOutro->senha), 'A senha do outro tenant não pode mudar.');

        $this->expectException(RuntimeException::class);
        $this->tenants->redefinirSenhaUsuario($um['id'], 9999);
    }

    public function testHostDoPainelResolveComoPainel(): void
    {
        $this->assertSame(['status' => 'painel'], tenantResolverHost('painel.teste.local'));
    }
}
