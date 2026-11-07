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

        $this->limite_login->registrarSucesso('plataforma', $email);

        $this->session->sess_regenerate(true);
        $this->session->set_userdata([
            'plataforma_id' => (int) $conta->id,
            'plataforma_sessao_versao' => (int) $conta->sessao_versao,
        ]);
        $this->plataforma_contas->registrarLogin((int) $conta->id);
        $this->conta = $conta;
        $this->auditar('login');

        return $this->responder(true);
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

    private function responder(bool $ok, string $mensagem = '')
    {
        $resposta = ['result' => $ok];
        if (! $ok) {
            // Token CSRF novo para a próxima tentativa (csrf_regenerate).
            $resposta += ['message' => $mensagem, 'MAPOS_TOKEN' => $this->security->get_csrf_hash()];
        }

        return $this->output->set_output(json_encode($resposta));
    }
}
