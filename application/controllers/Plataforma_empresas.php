<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

require_once __DIR__ . '/../core/Plataforma_Controller.php';

/**
 * Empresas (tenants) no painel da plataforma: listar, criar, ver, suspender e
 * reativar, domínios próprios e nova senha para usuário do tenant.
 *
 * Toda alteração entra na trilha de auditoria (plataforma_auditoria). Senhas
 * geradas aparecem uma vez, na resposta do próprio POST — nunca em flashdata,
 * que ficaria gravada na sessão.
 */
class Plataforma_empresas extends Plataforma_Controller
{
    /** @var Catalogo_planos */
    public $catalogo_planos;

    public function __construct()
    {
        parent::__construct();
        $this->exigirLogin();
        $this->load->library('Catalogo_planos');
    }

    public function index()
    {
        $filtros = [
            'busca' => mb_substr(trim((string) $this->input->get('busca')), 0, 100),
            'status' => in_array($this->input->get('status'), Tenant_manager::STATUS, true) ? (string) $this->input->get('status') : '',
        ];

        $this->tela('plataforma/empresas/lista', [
            'empresas' => $this->tenant_manager->listarParaPainel($filtros['busca'], $filtros['status'] ?: null),
            'filtros' => array_filter($filtros),
        ], 'Empresas', 'empresas', ['label' => 'Nova empresa', 'icon' => 'plus', 'href' => site_url('empresas/nova')]);
    }

    public function nova()
    {
        $valores = ['nome' => '', 'subdominio' => '', 'admin_email' => '', 'admin_nome' => '', 'documento' => '', 'plano_id' => ''];
        $planos = $this->catalogo_planos->listar(true);
        $erros = [];

        if ($this->input->method() === 'post') {
            foreach (array_keys($valores) as $campo) {
                $valores[$campo] = trim((string) $this->input->post($campo));
            }
            $valores['subdominio'] = strtolower($valores['subdominio']);

            $erros = $this->validar([
                ['field' => 'nome', 'label' => 'Nome da empresa', 'rules' => 'required|max_length[255]'],
                ['field' => 'subdominio', 'label' => 'Endereço', 'rules' => 'required|max_length[40]|regex_match[/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/]'],
                ['field' => 'admin_email', 'label' => 'E-mail do administrador', 'rules' => 'required|valid_email|max_length[80]'],
                ['field' => 'admin_nome', 'label' => 'Nome do administrador', 'rules' => 'max_length[80]'],
                ['field' => 'documento', 'label' => 'CPF/CNPJ', 'rules' => 'max_length[20]'],
            ]);
            if ($valores['plano_id'] !== '' && ! in_array((int) $valores['plano_id'], array_map('intval', array_column($planos, 'id')), true)) {
                $erros['plano_id'] = 'Escolha um dos planos ativos.';
            }

            if ($erros === []) {
                $dominio = $this->dominioDoSubdominio($valores['subdominio']);
                $novo = null;

                try {
                    $novo = $this->tenant_manager->provisionar([
                        'dominio' => $dominio,
                        'nome' => $valores['nome'],
                        'documento' => $valores['documento'] ?: null,
                        'admin_email' => $valores['admin_email'],
                        'admin_nome' => $valores['admin_nome'],
                        'plano_id' => $valores['plano_id'] !== '' ? (int) $valores['plano_id'] : null,
                    ]);
                } catch (InvalidArgumentException $e) {
                    $erros['_geral'] = $e->getMessage();
                } catch (Throwable $e) {
                    $erros['_geral'] = str_starts_with($e->getMessage(), 'Já existe')
                        ? $e->getMessage()
                        : 'Não foi possível criar a empresa. Nada ficou pela metade; tente de novo e, se repetir, veja o log.';
                    log_message('error', 'plataforma: falha ao provisionar ' . $dominio . ': ' . $e->getMessage());
                }

                if ($novo !== null) {
                    $this->auditar('empresa_criada', $dominio . ' (' . $novo['schema'] . '), admin ' . $novo['admin_email'], $novo['id']);

                    $this->tela('plataforma/empresas/senha', [
                        'titulo' => 'Empresa criada',
                        'mensagem' => $valores['nome'] . ' já pode entrar em ' . $dominio . '.',
                        'endereco' => $this->urlDoTenant($dominio),
                        'email' => $novo['admin_email'],
                        'senha' => $novo['admin_senha'],
                        'voltar' => site_url('empresas/' . $novo['id']),
                    ], 'Empresa criada', 'empresas');

                    return;
                }
            }
        }

        $this->tela('plataforma/empresas/formulario', [
            'valores' => $valores,
            'erros' => $erros,
            'dominioBase' => Tenant_manager::dominioBase(),
            'planos' => $planos,
        ], 'Nova empresa', 'empresas');
    }

    public function ver($id = null)
    {
        $tenant = $this->tenantOu404($id);

        try {
            $usuarios = $this->tenant_manager->usuariosDoTenant((int) $tenant['id']);
            $erroUsuarios = null;
        } catch (Throwable $e) {
            $usuarios = [];
            $erroUsuarios = 'Não foi possível ler os usuários desta empresa.';
            log_message('error', 'plataforma: usuários do tenant ' . $tenant['id'] . ': ' . $e->getMessage());
        }

        try {
            $consumo = $this->tenant_manager->consumo((int) $tenant['id']);
        } catch (Throwable $e) {
            $consumo = null;
            log_message('error', 'plataforma: consumo do tenant ' . $tenant['id'] . ': ' . $e->getMessage());
        }

        try {
            $backups = $this->tenant_manager->listarBackups((string) $tenant['schema']);
        } catch (RuntimeException $e) {
            $backups = [];
        }

        $plano = ! empty($tenant['plano_id']) ? $this->catalogo_planos->porId((int) $tenant['plano_id']) : null;
        $planos = $this->catalogo_planos->listar(true);
        if ($plano !== null && ! in_array((int) $plano['id'], array_map('intval', array_column($planos, 'id')), true)) {
            $planos[] = $plano; // plano desativado em que a empresa ainda está
        }

        $this->tela('plataforma/empresas/ver', [
            'tenant' => $tenant,
            'plano' => $plano,
            'planos' => $planos,
            'consumo' => $consumo,
            'backups' => $backups,
            'dominios' => $this->tenant_manager->dominios((int) $tenant['id']),
            'usuarios' => $usuarios,
            'erroUsuarios' => $erroUsuarios,
            'auditoria' => $this->plataforma_contas->auditoria(20, (int) $tenant['id']),
            'porta' => $this->portaDaRequisicao(),
        ], $tenant['nome'], 'empresas');
    }

    /** POST status=ativo|suspenso */
    public function status($id = null)
    {
        $tenant = $this->tenantOu404($id);
        $this->exigirPost('empresas/' . $tenant['id']);

        $novo = (string) $this->input->post('status');
        if (! in_array($novo, Tenant_manager::STATUS, true) || $novo === $tenant['status']) {
            $this->session->set_flashdata('error', 'Status inválido para esta empresa.');

            return redirect(site_url('empresas/' . $tenant['id']));
        }

        $this->tenant_manager->alterarStatus((int) $tenant['id'], $novo);
        $this->auditar(['ativo' => 'empresa_reativada', 'suspenso' => 'empresa_suspensa', 'cancelado' => 'empresa_cancelada'][$novo], null, (int) $tenant['id']);
        $this->session->set_flashdata('success', [
            'ativo' => $tenant['nome'] . ' foi reativada e voltou a acessar o sistema.',
            'suspenso' => $tenant['nome'] . ' foi suspensa: os endereços dela deixam de abrir. Os dados continuam guardados.',
            'cancelado' => $tenant['nome'] . ' foi cancelada: os endereços dela deixam de abrir. Os dados continuam guardados até a exclusão definitiva.',
        ][$novo]);

        return redirect(site_url('empresas/' . $tenant['id']));
    }

    /** POST dominio=www.exemplo.com.br */
    public function dominio($id = null)
    {
        $tenant = $this->tenantOu404($id);
        $this->exigirPost('empresas/' . $tenant['id']);

        $dominio = strtolower(trim((string) $this->input->post('dominio')));
        try {
            $this->tenant_manager->adicionarDominio((int) $tenant['id'], $dominio);
            $this->auditar('dominio_adicionado', $dominio, (int) $tenant['id']);
            $this->session->set_flashdata('success', 'Domínio ' . $dominio . ' cadastrado. Publique o registro TXT mostrado e clique em Verificar.');
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->session->set_flashdata('error', $e->getMessage());
        }

        return redirect(site_url('empresas/' . $tenant['id']));
    }

    /** POST dominio=... — confere o TXT de um domínio próprio desta empresa. */
    public function verificar($id = null)
    {
        $tenant = $this->tenantOu404($id);
        $this->exigirPost('empresas/' . $tenant['id']);

        $dominio = strtolower(trim((string) $this->input->post('dominio')));
        $dono = $dominio !== '' ? $this->tenant_manager->buscarPorDominio($dominio) : null;
        if ($dono === null || (int) $dono['id'] !== (int) $tenant['id']) {
            $this->session->set_flashdata('error', 'Esse domínio não é desta empresa.');

            return redirect(site_url('empresas/' . $tenant['id']));
        }

        if ($this->tenant_manager->verificarDominio($dominio)) {
            $this->auditar('dominio_verificado', $dominio, (int) $tenant['id']);
            $this->session->set_flashdata('success', 'Domínio ' . $dominio . ' verificado: já abre o sistema desta empresa.');
        } else {
            $this->session->set_flashdata('error', 'O registro TXT de ' . $dominio . ' ainda não foi encontrado. A propagação do DNS pode levar algumas horas.');
        }

        return redirect(site_url('empresas/' . $tenant['id']));
    }

    /** POST usuario=<idUsuarios> — nova senha, mostrada uma vez. */
    public function senha($id = null)
    {
        $tenant = $this->tenantOu404($id);
        $this->exigirPost('empresas/' . $tenant['id']);

        $idUsuario = (int) $this->input->post('usuario');
        $usuario = null;
        foreach ($this->tenant_manager->usuariosDoTenant((int) $tenant['id']) as $candidato) {
            if ($candidato['idUsuarios'] === $idUsuario) {
                $usuario = $candidato;
            }
        }
        if ($usuario === null) {
            $this->session->set_flashdata('error', 'Usuário não encontrado nesta empresa.');

            return redirect(site_url('empresas/' . $tenant['id']));
        }

        $senha = $this->tenant_manager->redefinirSenhaUsuario((int) $tenant['id'], $idUsuario);
        $this->auditar('senha_redefinida', $usuario['email'], (int) $tenant['id']);

        $dominio = $this->dominioPrincipal((int) $tenant['id']);

        $this->tela('plataforma/empresas/senha', [
            'titulo' => 'Senha redefinida',
            'mensagem' => 'As sessões abertas por ' . $usuario['nome'] . ' foram encerradas.',
            'endereco' => $dominio !== null ? $this->urlDoTenant($dominio) : null,
            'email' => $usuario['email'],
            'senha' => $senha,
            'voltar' => site_url('empresas/' . $tenant['id']),
        ], 'Senha redefinida', 'empresas');
    }

    /** POST plano_id (vazio = sem plano), vencimento (AAAA-MM-DD ou vazio). */
    public function plano($id = null)
    {
        $tenant = $this->tenantOu404($id);
        $this->exigirPost('empresas/' . $tenant['id']);

        $planoId = trim((string) $this->input->post('plano_id'));
        $vencimento = trim((string) $this->input->post('vencimento'));

        try {
            $this->tenant_manager->alterarPlano((int) $tenant['id'], $planoId !== '' ? (int) $planoId : null, $vencimento !== '' ? $vencimento : null);
            $plano = $planoId !== '' ? $this->catalogo_planos->porId((int) $planoId) : null;
            $this->auditar('empresa_plano', ($plano['nome'] ?? 'sem plano') . ($vencimento !== '' ? ', vence em ' . date('d/m/Y', (int) strtotime($vencimento)) : ', sem vencimento'), (int) $tenant['id']);
            $this->session->set_flashdata('success', 'Plano e vencimento de ' . $tenant['nome'] . ' atualizados.');
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->session->set_flashdata('error', $e->getMessage());
        }

        redirect(site_url('empresas/' . $tenant['id']));
    }

    /** POST: gera um backup completo agora. */
    public function backup($id = null)
    {
        $tenant = $this->tenantOu404($id);
        $this->exigirPost('empresas/' . $tenant['id']);

        try {
            $backup = $this->tenant_manager->backup((int) $tenant['id']);
            $this->auditar('backup_gerado', $backup['arquivo'], (int) $tenant['id']);
            $this->session->set_flashdata('success', 'Backup gerado: ' . $backup['arquivo'] . ' (' . plataformaTamanho($backup['tamanho']) . ').');
        } catch (Throwable $e) {
            log_message('error', 'plataforma: backup do tenant ' . $tenant['id'] . ': ' . $e->getMessage());
            $this->session->set_flashdata('error', 'Não foi possível gerar o backup: ' . $e->getMessage());
        }

        redirect(site_url('empresas/' . $tenant['id']));
    }

    /**
     * Exclusão definitiva (LGPD) de uma empresa cancelada. GET mostra a
     * confirmação; o POST exige digitar o endereço da empresa e a senha de
     * quem exclui. O backup final é gerado antes; sem ele nada é apagado.
     */
    public function excluir($id = null)
    {
        $tenant = $this->tenantOu404($id);
        if ($tenant['status'] !== 'cancelado') {
            $this->session->set_flashdata('error', 'Só uma empresa cancelada pode ser excluída. Cancele antes.');
            redirect(site_url('empresas/' . $tenant['id']));
        }

        $dominio = $this->dominioPrincipal((int) $tenant['id']) ?? (string) $tenant['schema'];
        $erros = [];

        if ($this->input->method() === 'post') {
            if (mb_strtolower(trim((string) $this->input->post('confirmacao'))) !== $dominio) {
                $erros['confirmacao'] = 'Digite exatamente ' . $dominio . ' para confirmar.';
            }
            if ($erro = $this->confirmarSenha((string) $this->input->post('senha'))) {
                $erros['senha'] = $erro;
            }

            if ($erros === []) {
                try {
                    $resultado = $this->tenant_manager->excluirTenant((int) $tenant['id']);
                    $this->auditar('empresa_excluida', $tenant['nome'] . ' (' . $dominio . '), backup ' . $resultado['backup'], (int) $tenant['id']);
                    $this->session->set_flashdata('success', $tenant['nome'] . ' foi excluída. O backup final ficou em Backups: ' . $resultado['backup'] . '.');

                    redirect(site_url('empresas'));
                } catch (Throwable $e) {
                    log_message('error', 'plataforma: exclusão do tenant ' . $tenant['id'] . ': ' . $e->getMessage());
                    $erros['_geral'] = 'Não foi possível excluir: ' . $e->getMessage();
                }
            }
        }

        try {
            $consumo = $this->tenant_manager->consumo((int) $tenant['id']);
        } catch (Throwable $e) {
            $consumo = null;
        }

        $this->tela('plataforma/empresas/excluir', [
            'tenant' => $tenant,
            'dominio' => $dominio,
            'consumo' => $consumo,
            'erros' => $erros,
        ], 'Excluir ' . $tenant['nome'], 'empresas');
    }

    private function tenantOu404($id): array
    {
        $tenant = ctype_digit((string) $id) ? $this->tenant_manager->buscarPorId((int) $id) : null;
        if ($tenant === null) {
            show_404();
        }

        return $tenant;
    }

    private function dominioDoSubdominio(string $subdominio): string
    {
        $base = Tenant_manager::dominioBase();

        return $base !== '' ? $subdominio . '.' . $base : $subdominio;
    }

    private function dominioPrincipal(int $tenantId): ?string
    {
        foreach ($this->tenant_manager->dominios($tenantId) as $dominio) {
            if ($dominio['tipo'] === 'plataforma') {
                return $dominio['dominio'];
            }
        }

        return null;
    }

    /** Endereço do tenant no mesmo esquema e porta em que o painel foi aberto. */
    private function urlDoTenant(string $dominio): string
    {
        return (is_https() ? 'https' : 'http') . '://' . $dominio . $this->portaDaRequisicao() . '/';
    }

    /**
     * ":8090" quando o painel é aberto numa porta fora do padrão (ambiente
     * local), para o link do tenant sair na mesma porta.
     */
    private function portaDaRequisicao(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        return preg_match('/:(\d+)$/', $host, $m) && ! in_array($m[1], ['80', '443'], true) ? ':' . $m[1] : '';
    }
}
