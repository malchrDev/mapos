<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

require_once __DIR__ . '/../core/Plataforma_Controller.php';

/**
 * Planos da plataforma: listar, criar, editar, desativar e reativar.
 *
 * Limites vazios significam "sem limite". A cobrança e a aplicação dos
 * limites são da Fase 5; aqui o plano é cadastro e atribuição à empresa.
 */
class Plataforma_planos extends Plataforma_Controller
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
        $this->tela('plataforma/planos/lista', [
            'planos' => $this->catalogo_planos->listar(),
        ], 'Planos', 'planos', ['label' => 'Novo plano', 'icon' => 'plus', 'href' => site_url('planos/novo')]);
    }

    public function formulario($id = null)
    {
        $plano = null;
        if ($id !== null) {
            $plano = ctype_digit((string) $id) ? $this->catalogo_planos->porId((int) $id) : null;
            if ($plano === null) {
                show_404();
            }
        }

        $campos = array_merge(['nome', 'preco_mensal'], array_keys(Catalogo_planos::LIMITES));
        $valores = ['cortesia' => (int) ($plano['cortesia'] ?? 0) === 1];
        foreach ($campos as $campo) {
            $valores[$campo] = $plano[$campo] ?? '';
        }
        if ($plano !== null) {
            $valores['preco_mensal'] = number_format((float) $plano['preco_mensal'], 2, ',', '');
        }
        $erros = [];

        if ($this->input->method() === 'post') {
            foreach ($campos as $campo) {
                $valores[$campo] = trim((string) $this->input->post($campo));
            }
            $valores['cortesia'] = (string) $this->input->post('cortesia') === '1';

            $regras = [['field' => 'nome', 'label' => 'Nome', 'rules' => 'required|max_length[80]']];
            foreach (Catalogo_planos::LIMITES as $coluna => $rotulo) {
                $regras[] = ['field' => $coluna, 'label' => $rotulo, 'rules' => 'is_natural_no_zero|max_length[9]'];
            }
            $erros = $this->validar($regras);

            // "1.299,90" ou "99,90" (vírgula decimal) e também "99.90".
            $preco = str_contains($valores['preco_mensal'], ',')
                ? str_replace(',', '.', str_replace('.', '', $valores['preco_mensal']))
                : $valores['preco_mensal'];
            if (! $valores['cortesia'] && (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', $preco) || (float) $preco <= 0)) {
                $erros['preco_mensal'] = 'Informe o preço mensal (por exemplo 99,90), ou marque como cortesia.';
            }

            if ($erros === []) {
                $dados = ['nome' => $valores['nome'], 'preco_mensal' => $valores['cortesia'] ? '0.00' : number_format((float) $preco, 2, '.', ''), 'cortesia' => $valores['cortesia']];
                foreach (array_keys(Catalogo_planos::LIMITES) as $coluna) {
                    $dados[$coluna] = $valores[$coluna] === '' ? null : (int) $valores[$coluna];
                }

                try {
                    $salvo = $this->catalogo_planos->salvar($plano !== null ? (int) $plano['id'] : null, $dados);
                    $this->auditar($plano !== null ? 'plano_alterado' : 'plano_criado', $dados['nome'] . ' (' . $salvo . ')');
                    $this->session->set_flashdata('success', 'Plano ' . $dados['nome'] . ' salvo.');

                    redirect(site_url('planos'));
                } catch (InvalidArgumentException $e) {
                    $erros['nome'] = $e->getMessage();
                }
            }
        }

        $this->tela('plataforma/planos/formulario', [
            'plano' => $plano,
            'valores' => $valores,
            'erros' => $erros,
        ], $plano !== null ? 'Editar plano' : 'Novo plano', 'planos');
    }

    /** POST ativo=1|0 */
    public function status($id = null)
    {
        $plano = ctype_digit((string) $id) ? $this->catalogo_planos->porId((int) $id) : null;
        if ($plano === null) {
            show_404();
        }
        $this->exigirPost('planos');

        $ativo = (string) $this->input->post('ativo') === '1';
        $this->catalogo_planos->alterarAtivo((int) $plano['id'], $ativo);
        $this->auditar($ativo ? 'plano_reativado' : 'plano_desativado', (string) $plano['nome']);
        $this->session->set_flashdata('success', $ativo
            ? 'O plano ' . $plano['nome'] . ' voltou a aparecer para novas empresas.'
            : 'O plano ' . $plano['nome'] . ' não aparece mais para novas empresas. Quem já está nele continua.');

        redirect(site_url('planos'));
    }
}
