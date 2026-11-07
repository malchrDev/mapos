<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Base dos controllers do painel administrativo da plataforma
 * (controllers/Plataforma_*.php).
 *
 * Só existe no hostname do painel (SAAS_PAINEL_HOST): o hook de tenant marca
 * `$GLOBALS['mapos_contexto'] = 'plataforma'` e aponta a conexão para o banco
 * mestre. Em qualquer outro hostname — de tenant ou instalação única — estes
 * controllers respondem 404, mesmo chamados pelo nome na URL.
 *
 * Não estende MY_Controller: aquele é o painel do tenant (permissões,
 * configurações e usuários do schema dele), que não existe no mestre.
 */
abstract class Plataforma_Controller extends CI_Controller
{
    /** @var object|null Conta da plataforma logada (plataforma_usuarios) */
    protected $conta;

    /** @var Tenant_manager */
    public $tenant_manager;

    /** @var Plataforma_contas */
    public $plataforma_contas;

    public function __construct()
    {
        parent::__construct();

        if (($GLOBALS['mapos_contexto'] ?? '') !== 'plataforma') {
            show_404();
        }

        // Telas de administração (e a de senha gerada) nunca vão para cache.
        $this->output->set_header('Cache-Control: no-store, private');

        $this->load->helper('plataforma');
        $this->load->library('Tenant_manager');
        $this->load->library('Plataforma_contas');
    }

    /**
     * Exige sessão de conta da plataforma ativa. A conta é relida a cada
     * requisição: desativada ou com a senha trocada (sessao_versao subiu), a
     * sessão aberta antes deixa de valer.
     *
     * O 2FA é obrigatório no painel: a conta que ainda não ativou só alcança a
     * tela de ativação (Plataforma_conta), que passa $semDoisFatores = true.
     */
    protected function exigirLogin(bool $semDoisFatores = false): void
    {
        $this->exigirSessao();

        if (! $semDoisFatores && ($this->conta->totp_ativado_em ?? null) === null) {
            redirect(site_url('conta')); // redirect() encerra a requisição
        }
    }

    /**
     * Confere a senha da conta logada antes de uma ação sensível, com o limite
     * de tentativas (escopo `confirmacao`). Devolve a mensagem de erro, ou null.
     */
    protected function confirmarSenha(string $senha): ?string
    {
        $this->load->library('Limite_login');
        $resultado = $this->limite_login->confirmarSenha((string) $this->conta->email, (string) $this->input->ip_address(), $senha, (string) $this->conta->senha);

        return match ($resultado) {
            Limite_login::SENHA_OK => null,
            Limite_login::SENHA_BLOQUEADA => Limite_login::MENSAGEM,
            default => 'A senha não confere.',
        };
    }

    private function exigirSessao(): void
    {
        $id = (int) $this->session->userdata('plataforma_id');
        $conta = $id > 0 ? $this->plataforma_contas->porId($id) : null;

        $valida = $conta !== null
            && (int) $conta->ativo === 1
            && (int) $conta->sessao_versao === (int) $this->session->userdata('plataforma_sessao_versao');

        if (! $valida) {
            $this->session->unset_userdata(['plataforma_id', 'plataforma_sessao_versao']);
            if ($id > 0) {
                $this->session->set_flashdata('error', 'Sua sessão terminou. Entre de novo.');
            }
            redirect(site_url('login')); // redirect() encerra a requisição
        }

        $this->conta = $conta;
    }

    /** Aceita só POST; o resto volta para $destino. */
    protected function exigirPost(string $destino): void
    {
        if ($this->input->method() !== 'post') {
            redirect(site_url($destino)); // redirect() encerra a requisição
        }
    }

    /**
     * Linha na trilha de auditoria do painel, com a conta logada e o IP.
     */
    protected function auditar(string $acao, ?string $detalhes = null, ?int $tenantId = null): void
    {
        $this->plataforma_contas->auditar($this->conta, $acao, $detalhes, (string) $this->input->ip_address(), $tenantId);
    }

    /**
     * Valida o POST com regras do form_validation (mensagens em pt-br) e
     * devolve os erros por campo; vazio se passou.
     *
     * @param  list<array{field:string, label:string, rules:string}>  $regras
     * @return array<string, string>
     */
    protected function validar(array $regras): array
    {
        $this->load->library('form_validation');
        $this->form_validation->set_rules($regras);

        $idioma = $this->config->item('language');
        $this->config->set_item('language', 'pt-br');
        try {
            $valido = $this->form_validation->run();
        } finally {
            $this->config->set_item('language', $idioma);
        }

        return $valido ? [] : $this->form_validation->error_array();
    }

    /**
     * Renderiza uma tela dentro do layout do painel da plataforma.
     *
     * @param  array<string, mixed>  $dados
     * @param  array|null  $acao  Ação principal da topbar (props do button)
     */
    protected function tela(string $view, array $dados, string $titulo, string $menu, ?array $acao = null): void
    {
        $flash = [];
        foreach (['success' => ['success', 'Pronto', 5000], 'error' => ['danger', 'Falha', 8000]] as $chave => [$variante, $rotulo, $duracao]) {
            $mensagem = $this->session->flashdata($chave);
            if (is_string($mensagem) && trim($mensagem) !== '') {
                $flash[] = ['variant' => $variante, 'title' => $rotulo, 'duration' => $duracao, 'message' => $mensagem];
            }
        }

        $this->load->view('plataforma/layout', $dados + [
            'view' => $view,
            'plataforma' => [
                'titulo' => $titulo,
                'menu' => $menu,
                'acao' => $acao,
                'flash' => $flash,
                'usuario' => (string) $this->conta->nome,
                'dois_fatores' => ($this->conta->totp_ativado_em ?? null) !== null,
                'iniciais' => layoutIniciais((string) $this->conta->nome),
                'saudacao' => layoutSaudacao((int) date('G')),
            ],
        ]);
    }
}
