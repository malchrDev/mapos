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
    public function __construct()
    {
        parent::__construct();
        $this->exigirLogin();
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
        $valores = ['nome' => '', 'subdominio' => '', 'admin_email' => '', 'admin_nome' => '', 'documento' => ''];
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

        $this->tela('plataforma/empresas/ver', [
            'tenant' => $tenant,
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
        if (! in_array($novo, ['ativo', 'suspenso'], true) || $novo === $tenant['status']) {
            $this->session->set_flashdata('error', 'Status inválido para esta empresa.');

            return redirect(site_url('empresas/' . $tenant['id']));
        }

        $this->tenant_manager->alterarStatus((int) $tenant['id'], $novo);
        $this->auditar($novo === 'ativo' ? 'empresa_reativada' : 'empresa_suspensa', null, (int) $tenant['id']);
        $this->session->set_flashdata('success', $novo === 'ativo'
            ? $tenant['nome'] . ' foi reativada e voltou a acessar o sistema.'
            : $tenant['nome'] . ' foi suspensa: os endereços dela deixam de abrir. Os dados continuam guardados.');

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
