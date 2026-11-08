<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

require_once __DIR__ . '/../core/Plataforma_Controller.php';

/**
 * Minha conta, no painel da plataforma: ativar o 2FA (obrigatório), gerar
 * novos códigos de recuperação e trocar a própria senha.
 *
 * Quem ainda não ativou o 2FA só chega aqui (Plataforma_Controller::exigirLogin()).
 * O 2FA não tem "desativar": perdido o celular e os códigos, outra conta do
 * painel redefine (Contas) ou o CLI (`tools plataforma_2fa`).
 */
class Plataforma_conta extends Plataforma_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->exigirLogin(true);
        $this->load->library('Totp');
    }

    public function index()
    {
        $this->telaConta();
    }

    /** POST senha + codigo: liga o 2FA com o segredo mostrado na tela. */
    public function ativar()
    {
        $this->exigirPost('conta');
        $segredo = $this->session->userdata('plataforma_totp_pendente');

        if ($this->conta->totp_ativado_em !== null || ! is_string($segredo) || $segredo === '') {
            return redirect(site_url('conta'));
        }

        // A senha também na ativação: quem tem só a sessão de outra pessoa não
        // cadastra o próprio autenticador nela.
        if ($erro = $this->confirmarSenha((string) $this->input->post('senha'))) {
            $this->telaConta(['senha_ativar' => $erro]);

            return;
        }

        $passo = $this->totp->verificar($segredo, (string) $this->input->post('codigo'));
        if ($passo === null) {
            $this->telaConta(['codigo' => 'Código incorreto. Confira o aplicativo e tente de novo.']);

            return;
        }

        $codigos = $this->totp->gerarCodigosDeRecuperacao();
        if (! $this->plataforma_contas->ativarTotp((int) $this->conta->id, $this->totp->cifrar($segredo), array_map([Totp::class, 'hashDoCodigo'], $codigos), $passo)) {
            $this->telaConta(['_geral' => 'Não foi possível ativar. Tente de novo.']);

            return;
        }

        $this->session->unset_userdata('plataforma_totp_pendente');
        $this->auditar('2fa_ativado');
        $this->conta = $this->plataforma_contas->porId((int) $this->conta->id);

        $this->telaConta([], $codigos);

        return;
    }

    /** POST senha: 10 códigos novos; os anteriores deixam de valer. */
    public function codigos()
    {
        $this->exigirPost('conta');
        if ($this->conta->totp_ativado_em === null) {
            return redirect(site_url('conta'));
        }
        if ($erro = $this->confirmarSenha((string) $this->input->post('senha'))) {
            $this->telaConta(['senha_codigos' => $erro]);

            return;
        }

        $codigos = $this->totp->gerarCodigosDeRecuperacao();
        $this->plataforma_contas->substituirCodigos((int) $this->conta->id, array_map([Totp::class, 'hashDoCodigo'], $codigos));
        $this->auditar('2fa_codigos');

        $this->telaConta([], $codigos);

        return;
    }

    /** POST senha_atual, nova_senha, confirmacao. As outras sessões da conta caem. */
    public function senha()
    {
        $this->exigirPost('conta');
        if ($this->conta->totp_ativado_em === null) {
            return redirect(site_url('conta'));
        }

        $nova = (string) $this->input->post('nova_senha');
        $erros = [];
        if ($erro = $this->confirmarSenha((string) $this->input->post('senha_atual'))) {
            $erros['senha_atual'] = $erro;
        }
        if (mb_strlen($nova) < Plataforma_contas::TAMANHO_MINIMO_SENHA) {
            $erros['nova_senha'] = 'A nova senha precisa ter pelo menos ' . Plataforma_contas::TAMANHO_MINIMO_SENHA . ' caracteres.';
        } elseif ($nova !== (string) $this->input->post('confirmacao')) {
            $erros['confirmacao'] = 'A confirmação não é igual à nova senha.';
        }
        if ($erros !== []) {
            $this->telaConta($erros);

            return;
        }

        $this->plataforma_contas->trocarSenha((int) $this->conta->id, $nova);
        // Esta sessão continua; as outras desta conta caem na próxima requisição.
        $this->session->sess_regenerate(true);
        $this->session->set_userdata('plataforma_sessao_versao', (int) $this->conta->sessao_versao + 1);
        $this->auditar('senha_propria');
        $this->session->set_flashdata('success', 'Senha alterada. As outras sessões da sua conta foram encerradas.');

        return redirect(site_url('conta'));
    }

    /**
     * @param  array<string, string>  $erros
     * @param  list<string>|null      $codigos  Códigos de recuperação recém-gerados
     */
    private function telaConta(array $erros = [], ?array $codigos = null): void
    {
        $ativo = $this->conta->totp_ativado_em !== null;
        $qr = null;
        $segredo = null;

        if (! $ativo && $codigos === null) {
            // O segredo só vai para o banco depois que o código confere.
            $pendente = $this->session->userdata('plataforma_totp_pendente');
            if (! is_string($pendente) || $pendente === '') {
                $pendente = $this->totp->criarSegredo();
                $this->session->set_userdata('plataforma_totp_pendente', $pendente);
            }
            $qr = $this->totp->qrCodeDataUri((string) $this->conta->email, $pendente);
            $segredo = Totp::formatarSegredo($pendente);
        }

        $this->tela('plataforma/conta', [
            'ativo' => $ativo,
            'restantes' => $ativo ? $this->plataforma_contas->codigosRestantes((int) $this->conta->id) : 0,
            'codigos' => $codigos,
            'erros' => $erros,
            'qr' => $qr,
            'segredo' => $segredo,
            'email' => (string) $this->conta->email,
        ], 'Minha conta', 'conta');
    }
}
