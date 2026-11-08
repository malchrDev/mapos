<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

require_once __DIR__ . '/../core/Plataforma_Controller.php';

/**
 * Contas do painel da plataforma: listar, criar, desativar/reativar, gerar
 * nova senha e redefinir o 2FA de outra conta. Toda conta do painel tem o
 * mesmo acesso; as ações sobre a própria conta ficam em Minha conta.
 *
 * Ações que entregam o acesso de alguém (criar, nova senha, redefinir 2FA)
 * pedem a senha de quem faz e ficam na auditoria. Senha gerada aparece uma
 * vez, na resposta do POST.
 */
class Plataforma_equipe extends Plataforma_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->exigirLogin();
    }

    public function index()
    {
        $this->tela('plataforma/contas/lista', [
            'contas' => $this->plataforma_contas->listar(),
            'eu' => (int) $this->conta->id,
        ], 'Contas do painel', 'contas', ['label' => 'Nova conta', 'icon' => 'plus', 'href' => site_url('contas/nova')]);
    }

    public function nova()
    {
        $valores = ['nome' => '', 'email' => ''];
        $erros = [];

        if ($this->input->method() === 'post') {
            $valores = ['nome' => trim((string) $this->input->post('nome')), 'email' => mb_strtolower(trim((string) $this->input->post('email')))];
            $erros = $this->validar([
                ['field' => 'nome', 'label' => 'Nome', 'rules' => 'required|max_length[120]'],
                ['field' => 'email', 'label' => 'E-mail', 'rules' => 'required|valid_email|max_length[160]'],
            ]);
            if ($erros === [] && ($erro = $this->confirmarSenha((string) $this->input->post('senha')))) {
                $erros['senha'] = $erro;
            }

            if ($erros === []) {
                try {
                    $senha = $this->plataforma_contas->criar($valores['nome'], $valores['email']);
                    $this->auditar('conta_criada', $valores['email']);
                    $this->tela('plataforma/empresas/senha', [
                        'titulo' => 'Conta criada',
                        'mensagem' => $valores['nome'] . ' já pode entrar no painel. No primeiro acesso, o painel pede para ativar o 2FA.',
                        'endereco' => site_url('login'),
                        'email' => $valores['email'],
                        'senha' => $senha,
                        'voltar' => site_url('contas'),
                    ], 'Conta criada', 'contas');

                    return;
                } catch (InvalidArgumentException|RuntimeException $e) {
                    $erros['email'] = $e->getMessage();
                }
            }
        }

        $this->tela('plataforma/contas/formulario', ['valores' => $valores, 'erros' => $erros], 'Nova conta', 'contas');
    }

    /** POST ativo=1|0 */
    public function status()
    {
        $this->exigirPost('contas');
        $alvo = $this->outraConta($this->input->post('id'));

        $ativo = (string) $this->input->post('ativo') === '1';
        try {
            $this->plataforma_contas->alterarAtivo((int) $alvo->id, $ativo);
            $this->auditar($ativo ? 'conta_reativada' : 'conta_desativada', (string) $alvo->email);
            $this->session->set_flashdata('success', $ativo ? $alvo->nome . ' voltou a acessar o painel.' : $alvo->nome . ' não acessa mais o painel; as sessões abertas foram encerradas.');
        } catch (RuntimeException $e) {
            $this->session->set_flashdata('error', $e->getMessage());
        }

        redirect(site_url('contas'));
    }

    /** POST senha (de quem faz): nova senha aleatória para a outra conta. */
    public function senha()
    {
        $this->exigirPost('contas');
        $alvo = $this->outraConta($this->input->post('id'));

        if ($erro = $this->confirmarSenha((string) $this->input->post('senha'))) {
            $this->session->set_flashdata('error', $erro);
            redirect(site_url('contas'));
        }

        $senha = $this->plataforma_contas->redefinirSenhaPorId((int) $alvo->id);
        $this->auditar('conta_senha', (string) $alvo->email);

        $this->tela('plataforma/empresas/senha', [
            'titulo' => 'Senha redefinida',
            'mensagem' => 'As sessões abertas por ' . $alvo->nome . ' foram encerradas.',
            'endereco' => site_url('login'),
            'email' => (string) $alvo->email,
            'senha' => $senha,
            'voltar' => site_url('contas'),
        ], 'Senha redefinida', 'contas');
    }

    /** POST senha (de quem faz): apaga o 2FA da outra conta (celular perdido). */
    public function redefinir2fa()
    {
        $this->exigirPost('contas');
        $alvo = $this->outraConta($this->input->post('id'));

        if ($erro = $this->confirmarSenha((string) $this->input->post('senha'))) {
            $this->session->set_flashdata('error', $erro);
            redirect(site_url('contas'));
        }

        $this->plataforma_contas->redefinirTotp((int) $alvo->id);
        $this->auditar('conta_2fa_redefinido', (string) $alvo->email);
        $this->session->set_flashdata('success', 'O 2FA de ' . $alvo->nome . ' foi apagado. No próximo login, ele cadastra o aplicativo de novo.');

        redirect(site_url('contas'));
    }

    /**
     * Conta pelo id enviado no POST (modal compartilhado da lista, como em
     * clientes/excluir), conferida no banco; nunca a própria (essa fica em
     * Minha conta).
     */
    private function outraConta($id): object
    {
        $alvo = ctype_digit((string) $id) ? $this->plataforma_contas->porId((int) $id) : null;
        if ($alvo === null) {
            show_404();
        }
        if ((int) $alvo->id === (int) $this->conta->id) {
            $this->session->set_flashdata('error', 'Para a sua própria conta, use Minha conta.');
            redirect(site_url('contas'));
        }

        return $alvo;
    }
}
