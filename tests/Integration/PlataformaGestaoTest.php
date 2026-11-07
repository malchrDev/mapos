<?php

require_once APPPATH . 'libraries/Plataforma_contas.php';
require_once APPPATH . 'libraries/Catalogo_planos.php';
require_once APPPATH . 'libraries/Totp.php';
require_once APPPATH . 'helpers/armazenamento_helper.php';

use PHPUnit\Framework\TestCase;

/**
 * Gestão do painel da plataforma contra MySQL real: 2FA e contas do painel,
 * planos, backup restaurável e exclusão definitiva de empresa.
 */
final class PlataformaGestaoTest extends TestCase
{
    private Tenant_manager $tenants;

    private $mestre;

    private Plataforma_contas $contas;

    private Catalogo_planos $planos;

    private Totp $totp;

    private string $storage;

    private ?string $storageAnterior;

    protected function setUp(): void
    {
        if (trim((string) ($_ENV['DB_MASTER_DATABASE'] ?? '')) === '') {
            $this->markTestSkipped('Sem DB_MASTER_DATABASE: rode em docker/teste/docker-compose.yml.');
        }

        // Uploads e backups numa pasta temporária, nunca em application/storage.
        $this->storageAnterior = $_ENV['STORAGE_PATH'] ?? null;
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mapos-gestao-' . bin2hex(random_bytes(4));
        $_ENV['STORAGE_PATH'] = $this->storage;
        unset($_ENV['SAAS_BACKUP_PATH'], $GLOBALS['mapos_tenant_schema']);

        $this->tenants = new Tenant_manager();
        $this->tenants->migrarMestre();
        $this->mestre = $this->tenants->conexaoMestre();
        $this->contas = new Plataforma_contas(['db' => $this->mestre]);
        $this->planos = new Catalogo_planos(['db' => $this->mestre]);
        $this->totp = new Totp(['chave' => 'chave-de-teste-do-2fa-com-32-caracteres!!', 'emissor' => 'Teste']);
        $this->limpar();
    }

    protected function tearDown(): void
    {
        if (isset($this->tenants)) {
            $this->limpar();
            $this->apagar($this->storage);
        }
        if ($this->storageAnterior === null) {
            unset($_ENV['STORAGE_PATH']);
        } else {
            $_ENV['STORAGE_PATH'] = $this->storageAnterior;
        }
    }

    private function limpar(): void
    {
        foreach ($this->tenants->listar() as $tenant) {
            $this->tenants->excluirSchema($tenant['schema']);
        }
        $this->mestre->query('DELETE FROM tenants');
        $this->mestre->query("DELETE FROM planos WHERE nome <> 'Cortesia'");
        foreach (['plataforma_usuarios', 'plataforma_auditoria', 'login_attempts'] as $tabela) {
            $this->mestre->query("DELETE FROM {$tabela}");
        }
    }

    private function apagar(string $caminho): void
    {
        if (is_file($caminho) || is_link($caminho)) {
            unlink($caminho);

            return;
        }
        foreach (is_dir($caminho) ? scandir($caminho) : [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->apagar($caminho . DIRECTORY_SEPARATOR . $item);
            }
        }
        if (is_dir($caminho)) {
            rmdir($caminho);
        }
    }

    private function provisionar(string $dominio, ?int $planoId = null): array
    {
        return $this->tenants->provisionar(['dominio' => $dominio, 'nome' => 'Empresa ' . $dominio, 'admin_email' => 'admin@' . $dominio] + ($planoId !== null ? ['plano_id' => $planoId] : []));
    }

    private function contaComTotp(string $email): array
    {
        $this->contas->criar('Conta', $email);
        $conta = $this->contas->porEmail($email);
        $segredo = $this->totp->criarSegredo();
        $codigos = $this->totp->gerarCodigosDeRecuperacao();
        $passo = (int) floor(time() / 30) - 2;

        $this->assertTrue($this->contas->ativarTotp((int) $conta->id, $this->totp->cifrar($segredo), array_map([Totp::class, 'hashDoCodigo'], $codigos), $passo));

        return [$this->contas->porEmail($email), $segredo, $codigos];
    }

    private function codigoAgora(string $segredo): string
    {
        $semQr = new class() implements RobThree\Auth\Providers\Qr\IQRCodeProvider {
            public function getQRCodeImage(string $qrText, int $size): string
            {
                return '';
            }

            public function getMimeType(): string
            {
                return 'image/png';
            }
        };

        return (new RobThree\Auth\TwoFactorAuth($semQr))->getCode($segredo);
    }

    // ---- 2FA ----

    public function testCodigoDoAplicativoValeUmaVez(): void
    {
        [$conta, $segredo] = $this->contaComTotp('totp@plataforma.com');
        $codigo = $this->codigoAgora($segredo);

        $this->assertTrue($this->contas->aceitarCodigo($this->totp, $conta, $codigo));
        $this->assertFalse($this->contas->aceitarCodigo($this->totp, $this->contas->porEmail('totp@plataforma.com'), $codigo), 'O mesmo código não vale de novo.');
        $this->assertFalse($this->contas->aceitarCodigo($this->totp, $conta, $codigo), 'Nem com a linha lida antes do primeiro uso (requisições simultâneas).');
        $this->assertFalse($this->contas->aceitarCodigo($this->totp, $conta, '000000'));
    }

    public function testCodigoDeRecuperacaoValeUmaVezESoParaADonaConta(): void
    {
        [$conta, , $codigos] = $this->contaComTotp('rec@plataforma.com');
        [$outra] = $this->contaComTotp('outra@plataforma.com');

        $this->assertFalse($this->contas->aceitarCodigo($this->totp, $outra, $codigos[0]), 'Código de outra conta.');
        $this->assertTrue($this->contas->aceitarCodigo($this->totp, $conta, strtoupper($codigos[0])));
        $this->assertFalse($this->contas->aceitarCodigo($this->totp, $conta, $codigos[0]), 'Já usado.');
        $this->assertSame(9, $this->contas->codigosRestantes((int) $conta->id));
    }

    public function testSegredoGuardadoCifradoESegundaAtivacaoRecusada(): void
    {
        [$conta, $segredo] = $this->contaComTotp('cifra@plataforma.com');

        $this->assertStringNotContainsString($segredo, (string) $conta->totp_secreto);
        $this->assertSame($segredo, $this->totp->decifrar($conta->totp_secreto));
        $this->assertFalse($this->contas->ativarTotp((int) $conta->id, $this->totp->cifrar('OUTRO'), [], 1), 'Ativar de novo por cima é recusado.');
    }

    public function testRedefinirTotpApagaTudoEDerrubaASessao(): void
    {
        [$conta] = $this->contaComTotp('perdeu@plataforma.com');

        $this->contas->redefinirTotp((int) $conta->id);
        $depois = $this->contas->porEmail('perdeu@plataforma.com');

        $this->assertNull($depois->totp_ativado_em);
        $this->assertNull($depois->totp_secreto);
        $this->assertSame(0, $this->contas->codigosRestantes((int) $conta->id));
        $this->assertSame((int) $conta->sessao_versao + 1, (int) $depois->sessao_versao);
    }

    // ---- Contas ----

    public function testUltimaContaAtivaNaoPodeSerDesativada(): void
    {
        $this->contas->criar('Única', 'unica@plataforma.com');
        $unica = $this->contas->porEmail('unica@plataforma.com');

        try {
            $this->contas->alterarAtivo((int) $unica->id, false);
            $this->fail('A última conta ativa foi desativada.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('única conta ativa', $e->getMessage());
        }

        $this->contas->criar('Segunda', 'segunda@plataforma.com');
        $this->contas->alterarAtivo((int) $unica->id, false);
        $depois = $this->contas->porId((int) $unica->id);
        $this->assertSame('0', (string) $depois->ativo);
        $this->assertSame((int) $unica->sessao_versao + 1, (int) $depois->sessao_versao, 'Desativar derruba a sessão.');
    }

    public function testListagemDeContasNaoExpoeSenhaNemSegredo(): void
    {
        $this->contaComTotp('lista@plataforma.com');

        $linha = $this->contas->listar()[0];
        $this->assertSame(['id', 'nome', 'email', 'ativo', 'dois_fatores', 'ultimo_login_em', 'criado_em'], array_keys($linha));
        $this->assertTrue($linha['dois_fatores']);
    }

    public function testTrocarSenhaDerrubaAsSessoes(): void
    {
        $this->contas->criar('Ana', 'ana@plataforma.com');
        $antes = $this->contas->porEmail('ana@plataforma.com');

        $this->contas->trocarSenha((int) $antes->id, 'nova-senha-bem-longa');
        $depois = $this->contas->porEmail('ana@plataforma.com');

        $this->assertTrue(password_verify('nova-senha-bem-longa', $depois->senha));
        $this->assertSame((int) $antes->sessao_versao + 1, (int) $depois->sessao_versao);
    }

    // ---- Planos ----

    public function testMigrationCriaOPlanoCortesia(): void
    {
        $cortesia = $this->mestre->where('nome', 'Cortesia')->get('planos')->row_array();

        $this->assertNotNull($cortesia);
        $this->assertSame('1', (string) $cortesia['cortesia']);
        $this->assertSame('0.00', (string) $cortesia['preco_mensal']);
    }

    public function testPlanoComNomeRepetidoERecusadoECortesiaZeraOPreco(): void
    {
        $id = $this->planos->salvar(null, ['nome' => 'Básico', 'preco_mensal' => '49.90', 'cortesia' => false, 'limite_usuarios' => 3, 'limite_os_mes' => null, 'limite_armazenamento_mb' => 1024]);
        $this->assertSame('49.90', (string) $this->planos->porId($id)['preco_mensal']);
        $this->assertSame('3 usuários · 1.024 MB', Catalogo_planos::resumoLimites($this->planos->porId($id)));

        $this->planos->salvar($id, ['nome' => 'Básico', 'preco_mensal' => '49.90', 'cortesia' => true, 'limite_usuarios' => null, 'limite_os_mes' => null, 'limite_armazenamento_mb' => null]);
        $this->assertSame('0.00', (string) $this->planos->porId($id)['preco_mensal'], 'Editar o próprio nome não conta como repetido.');

        $this->expectException(InvalidArgumentException::class);
        $this->planos->salvar(null, ['nome' => 'Básico', 'preco_mensal' => '10.00', 'cortesia' => false, 'limite_usuarios' => null, 'limite_os_mes' => null, 'limite_armazenamento_mb' => null]);
    }

    public function testPlanoEVencimentoDaEmpresa(): void
    {
        $plano = $this->planos->salvar(null, ['nome' => 'Pro', 'preco_mensal' => '99.00', 'cortesia' => false, 'limite_usuarios' => 10, 'limite_os_mes' => 500, 'limite_armazenamento_mb' => null]);
        $empresa = $this->provisionar('plano.teste.local', $plano);

        $this->assertSame($plano, (int) $this->tenants->buscarPorId($empresa['id'])['plano_id'], 'O plano escolhido na criação fica gravado.');
        $this->assertSame('Pro', $this->tenants->listarParaPainel()[0]['plano']);

        $this->tenants->alterarPlano($empresa['id'], null, '2027-01-31');
        $registro = $this->tenants->buscarPorId($empresa['id']);
        $this->assertNull($registro['plano_id']);
        $this->assertSame('2027-01-31', $registro['data_expiracao']);

        foreach (['2027-02-30', '31/01/2027', 'amanhã'] as $invalida) {
            try {
                $this->tenants->alterarPlano($empresa['id'], null, $invalida);
                $this->fail('Data aceita: ' . $invalida);
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->tenants->alterarPlano($empresa['id'], 999999, null);
    }

    public function testPlanoEmUsoNaoPodeSerApagado(): void
    {
        $plano = $this->planos->salvar(null, ['nome' => 'Em uso', 'preco_mensal' => '10.00', 'cortesia' => false, 'limite_usuarios' => null, 'limite_os_mes' => null, 'limite_armazenamento_mb' => null]);
        $this->provisionar('emuso.teste.local', $plano);

        $this->assertFalse($this->mestre->where('id', $plano)->delete('planos'), 'A chave estrangeira segura o plano.');
        $this->assertNotNull($this->planos->porId($plano));
    }

    // ---- Backup e exclusão ----

    public function testBackupRestauraOBancoComOsMesmosDados(): void
    {
        $empresa = $this->provisionar('backup.teste.local');
        $banco = DB(integracaoConfigBanco($empresa['schema']));
        $banco->insert('clientes', ['nomeCliente' => "D'Ávila \"aspas\" \\ barra", 'documento' => '1', 'telefone' => '', 'celular' => '', 'email' => 'c@x.com', 'dataCadastro' => date('Y-m-d'), 'rua' => '', 'numero' => '', 'bairro' => '', 'cidade' => '', 'estado' => '', 'cep' => '', 'contato' => '', 'complemento' => '', 'fornecedor' => 0]);
        $banco->close();

        $GLOBALS['mapos_tenant_schema'] = $empresa['schema'];
        $pasta = armazenamentoPasta('anexos/11-2026/OS-1');
        unset($GLOBALS['mapos_tenant_schema']);
        file_put_contents($pasta . '/foto.png', 'png');

        $backup = $this->tenants->backup($empresa['id']);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($backup['caminho']) === true);
        $this->assertNotFalse($zip->locateName('banco.sql'));
        $this->assertNotFalse($zip->locateName('LEIA-ME.txt'));
        $this->assertSame('png', $zip->getFromName('arquivos/anexos/11-2026/OS-1/foto.png'));
        $sql = (string) $zip->getFromName('banco.sql');
        $zip->close();

        $this->assertStringNotContainsString('INSERT INTO `ci_sessions`', $sql, 'Sessões não vão para o backup.');

        // Restaura num schema novo e compara tabela a tabela.
        $copia = 'mapos_restauro_' . bin2hex(random_bytes(3));
        $this->mestre->query('CREATE DATABASE `' . $copia . '`');
        try {
            $mysqli = new mysqli($_ENV['DB_HOSTNAME'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $copia, (int) $_ENV['DB_PORT']);
            $mysqli->set_charset('utf8mb4');
            $this->assertTrue($mysqli->multi_query($sql), $mysqli->error);
            do {
                if ($r = $mysqli->store_result()) {
                    $r->free();
                }
            } while ($mysqli->more_results() && $mysqli->next_result());
            $this->assertSame(0, $mysqli->errno, $mysqli->error);

            $original = DB(integracaoConfigBanco($empresa['schema']));
            $restaurado = DB(integracaoConfigBanco($copia));
            $this->assertEqualsCanonicalizing($original->list_tables(), $restaurado->list_tables());
            foreach ($original->list_tables() as $tabela) {
                if (in_array($tabela, Tenant_manager::BACKUP_SEM_DADOS, true)) {
                    continue;
                }
                $this->assertSame($original->count_all($tabela), $restaurado->count_all($tabela), $tabela);
            }
            $this->assertSame("D'Ávila \"aspas\" \\ barra", $restaurado->where('email', 'c@x.com')->get('clientes')->row()->nomeCliente);
            $original->close();
            $restaurado->close();
            $mysqli->close();
        } finally {
            $this->mestre->query('DROP DATABASE IF EXISTS `' . $copia . '`');
        }

        $this->assertSame([$backup['arquivo']], array_column($this->tenants->listarBackups($empresa['schema']), 'arquivo'));
    }

    public function testNomeDeBackupForaDoPadraoNaoEServido(): void
    {
        $empresa = $this->provisionar('nome.teste.local');
        $backup = $this->tenants->backup($empresa['id']);

        $this->assertSame($backup['caminho'], $this->tenants->caminhoBackup($backup['arquivo']));
        foreach (['../' . $backup['arquivo'], '.htaccess', 'mapos_x-20260101-000000.zip', $backup['arquivo'] . '/../.htaccess', 'MAPOS_A-20260101-000000.zip'] as $nome) {
            $this->assertNull($this->tenants->caminhoBackup($nome), $nome);
        }
    }

    public function testEmpresaSoEExcluidaDepoisDeCancelada(): void
    {
        $empresa = $this->provisionar('ativa.teste.local');

        try {
            $this->tenants->excluirTenant($empresa['id']);
            $this->fail('Empresa ativa excluída.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cancelada', $e->getMessage());
        }
        $this->assertTrue($this->tenants->schemaExiste($empresa['schema']));
    }

    public function testExclusaoApagaTudoDaEmpresaEGuardaOBackupFinal(): void
    {
        $empresa = $this->provisionar('excluir.teste.local');
        $outra = $this->provisionar('fica.teste.local');

        $GLOBALS['mapos_tenant_schema'] = $empresa['schema'];
        $pasta = armazenamentoPasta('arquivos/01-11-2026');
        unset($GLOBALS['mapos_tenant_schema']);
        file_put_contents($pasta . '/contrato.pdf', '%PDF');

        $foto = 'teste-exclusao-' . bin2hex(random_bytes(4)) . '.png';
        $caminhoFoto = FCPATH . 'assets' . DIRECTORY_SEPARATOR . 'userImage' . DIRECTORY_SEPARATOR . $foto;
        file_put_contents($caminhoFoto, 'foto');
        $banco = DB(integracaoConfigBanco($empresa['schema']));
        $banco->update('usuarios', ['url_image_user' => $foto]);
        $banco->close();

        $this->contas->auditar(null, 'empresa_criada', 'teste', '127.0.0.1', $empresa['id']);
        $this->tenants->alterarStatus($empresa['id'], 'cancelado');
        $this->assertNotNull($this->tenants->buscarPorId($empresa['id'])['cancelado_em']);

        try {
            $resultado = $this->tenants->excluirTenant($empresa['id']);
        } finally {
            @unlink($caminhoFoto);
        }

        $this->assertFalse($this->tenants->schemaExiste($empresa['schema']), 'Schema apagado.');
        $this->assertDirectoryDoesNotExist($this->tenants->pastaArmazenamento($empresa['schema']), 'Uploads apagados.');
        $this->assertNull($this->tenants->buscarPorId($empresa['id']), 'Registro apagado.');
        $this->assertNull($this->tenants->resolverPorDominio('excluir.teste.local'));
        $this->assertSame(0, (int) $this->mestre->where('tenant_id', $empresa['id'])->count_all_results('tenant_dominios'), 'Domínios apagados.');
        $this->assertSame(1, count($this->contas->auditoria(10, $empresa['id'])), 'A auditoria fica.');

        $final = $this->tenants->caminhoBackup($resultado['backup']);
        $this->assertNotNull($final, 'Backup final guardado.');
        $zip = new ZipArchive();
        $zip->open((string) $final);
        $this->assertSame('%PDF', $zip->getFromName('arquivos/arquivos/01-11-2026/contrato.pdf'));
        $this->assertSame('foto', $zip->getFromName('publicos/assets/userImage/' . $foto), 'A foto de perfil vai para o backup.');
        $zip->close();

        $this->assertTrue($this->tenants->schemaExiste($outra['schema']), 'A outra empresa não é tocada.');
        $this->assertNotNull($this->tenants->buscarPorId($outra['id']));
    }

    public function testConsumoContaUsuariosOsEArquivos(): void
    {
        $empresa = $this->provisionar('consumo.teste.local');

        $GLOBALS['mapos_tenant_schema'] = $empresa['schema'];
        $pasta = armazenamentoPasta('anexos');
        unset($GLOBALS['mapos_tenant_schema']);
        file_put_contents($pasta . '/grande.bin', str_repeat('x', 2 * 1048576));

        $consumo = $this->tenants->consumo($empresa['id']);

        $this->assertSame(1, $consumo['usuarios'], 'O admin criado no provisionamento.');
        $this->assertSame(0, $consumo['os_mes']);
        $this->assertSame(2.0, $consumo['armazenamento_mb']);
    }
}
