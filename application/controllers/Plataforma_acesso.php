<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

require_once __DIR__ . '/../core/Plataforma_Controller.php';

/**
 * Login do painel administrativo da plataforma (contas do banco mestre).
 *
 * Mesmas regras do login do painel do tenant (Login::verificarLogin): limite de
 * tentativas (Limite_login, escopo `plataforma`), mensagem única para e-mail
 * inexistente e senha errada, senhaConfere() gastando o mesmo bcrypt sem
 * conta, e ID de sessão novo a cada login.
 */
class Plataforma_acesso extends Plataforma_Controller
{
    public function index()
    {
        if ((int) $this->session->userdata('plataforma_id') > 0) {
            return redirect(site_url('empresas'));
        }

        $this->load->view('plataforma/login', ['erro' => $this->session->flashdata('error')]);
    }

    /**
     * POST do formulário de login (assets/js/modules/login/formulario.js):
     * responde JSON {result, message}.
     */
    public function entrar()
    {
        $this->output->set_content_type('application/json');

        if ($this->input->method() !== 'post') {
            return $this->responder(false, 'Método não permitido.');
        }

        $email = mb_strtolower(trim((string) $this->input->post('email')));
        $senha = (string) $this->input->post('senha');
        $ip = (string) $this->input->ip_address();

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $senha === '') {
            return $this->responder(false, 'Informe o e-mail e a senha.');
        }

        $this->load->library('Limite_login', [
            'auditar' => fn (string $tarefa, string $ipBloqueado) => $this->plataforma_contas->auditar(null, 'login_bloqueado', $tarefa, $ipBloqueado),
        ]);

        if ($this->limite_login->bloqueado('plataforma', $email, $ip)) {
            return $this->responder(false, Limite_login::MENSAGEM);
        }

        $conta = $this->plataforma_contas->porEmail($email);
        $confere = senhaConfere($senha, $conta->senha ?? null);

        // Conta desativada conta como dado errado: não revela que o e-mail existe.
        if ($conta === null || ! $confere || (int) $conta->ativo !== 1) {
            $this->limite_login->registrarFalha('plataforma', $email, $ip);

            return $this->responder(false, 'Os dados de acesso estão incorretos.');
        }

        // 2FA ativo: a senha sozinha não entra. A sessão guarda só que a senha
        // conferiu, por 5 minutos e 5 tentativas; o contador de falhas do
        // Limite_login só zera com o código certo.
        if (($conta->totp_ativado_em ?? null) !== null) {
            $this->session->sess_regenerate(true);
            $this->session->set_userdata('plataforma_2fa', ['id' => (int) $conta->id, 'expira' => time() + self::DOIS_FATORES_VALIDADE, 'tentativas' => 0]);

            return $this->responder(true, '', site_url('login/codigo'));
        }

        // Sem 2FA ainda (conta nova): entra, mas exigirLogin() só deixa chegar
        // à tela de ativação.
        $this->limite_login->registrarSucesso('plataforma', $email);
        $this->iniciarSessao($conta);

        return $this->responder(true, '', site_url('conta'));
    }

    /** Segundo passo do login: código do aplicativo ou de recuperação. */
    public function codigo()
    {
        if ($this->doisFatoresPendente() === null) {
            $this->session->set_flashdata('error', 'Entre com e-mail e senha primeiro.');

            return redirect(site_url('login'));
        }

        $this->load->view('plataforma/login_codigo', ['erro' => $this->session->flashdata('error')]);
    }

    public function verificar()
    {
        $pendente = $this->doisFatoresPendente();
        if ($pendente === null || $this->input->method() !== 'post') {
            $this->session->set_flashdata('error', 'Entre com e-mail e senha primeiro.');

            return redirect(site_url('login'));
        }

        $conta = $this->plataforma_contas->porId((int) $pendente['id']);
        $email = (string) ($conta->email ?? '');
        $ip = (string) $this->input->ip_address();

        $this->load->library('Limite_login', [
            'auditar' => fn (string $tarefa, string $ipBloqueado) => $this->plataforma_contas->auditar(null, 'login_bloqueado', $tarefa, $ipBloqueado),
        ]);
        $this->load->library('Totp');

        if ($this->limite_login->bloqueado('plataforma', $email, $ip)) {
            $this->session->unset_userdata('plataforma_2fa');
            $this->session->set_flashdata('error', Limite_login::MENSAGEM);

            return redirect(site_url('login'));
        }

        $codigo = trim((string) $this->input->post('codigo'));
        $ok = $conta !== null && (int) $conta->ativo === 1 && $this->plataforma_contas->aceitarCodigo($this->totp, $conta, $codigo);

        if (! $ok) {
            $this->limite_login->registrarFalha('plataforma', $email, $ip);
            $pendente['tentativas']++;

            if ($pendente['tentativas'] >= self::DOIS_FATORES_TENTATIVAS) {
                $this->session->unset_userdata('plataforma_2fa');
                $this->session->set_flashdata('error', 'Código incorreto várias vezes. Entre de novo com e-mail e senha.');

                return redirect(site_url('login'));
            }

            $this->session->set_userdata('plataforma_2fa', $pendente);
            $this->session->set_flashdata('error', 'Código incorreto. Confira o aplicativo ou use um código de recuperação.');

            return redirect(site_url('login/codigo'));
        }

        $this->limite_login->registrarSucesso('plataforma', $email);
        $this->session->unset_userdata('plataforma_2fa');
        $this->iniciarSessao($conta);

        return redirect(site_url('empresas'));
    }

    private const DOIS_FATORES_VALIDADE = 300;

    private const DOIS_FATORES_TENTATIVAS = 5;

    /** @return array{id:int, expira:int, tentativas:int}|null */
    private function doisFatoresPendente(): ?array
    {
        $pendente = $this->session->userdata('plataforma_2fa');
        if (! is_array($pendente) || (int) ($pendente['expira'] ?? 0) < time()) {
            $this->session->unset_userdata('plataforma_2fa');

            return null;
        }

        return $pendente;
    }

    private function iniciarSessao(object $conta): void
    {
        $this->session->sess_regenerate(true);
        $this->session->set_userdata([
            'plataforma_id' => (int) $conta->id,
            'plataforma_sessao_versao' => (int) $conta->sessao_versao,
        ]);
        $this->plataforma_contas->registrarLogin((int) $conta->id);
        $this->conta = $conta;
        $this->auditar('login');
    }

    public function sair()
    {
        $id = (int) $this->session->userdata('plataforma_id');
        if ($id > 0) {
            $this->conta = $this->plataforma_contas->porId($id);
            $this->auditar('logout');
        }

        $this->session->sess_destroy();

        return redirect(site_url('login'));
    }

    /** Qualquer rota que não é do painel, no hostname do painel. */
    public function nao_encontrado()
    {
        show_404();
    }

    private function responder(bool $ok, string $mensagem = '', ?string $destino = null)
    {
        $resposta = ['result' => $ok] + ($destino !== null ? ['destino' => $destino] : []);
        if (! $ok) {
            // Token CSRF novo para a próxima tentativa (csrf_regenerate).
            $resposta += ['message' => $mensagem, 'MAPOS_TOKEN' => $this->security->get_csrf_hash()];
        }

        return $this->output->set_output(json_encode($resposta));
    }
}
