<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Planos da plataforma (banco mestre): nome, preço mensal, limites e se é
 * cortesia (acesso liberado sem pagamento).
 *
 *     $this->load->library('Catalogo_planos');                     // painel: $CI->db já é o mestre
 *     $this->load->library('Catalogo_planos', ['db' => $mestre]);  // testes
 *
 * Um plano em uso não é apagado (chave estrangeira em tenants.plano_id):
 * desative-o e ele deixa de aparecer para novas empresas.
 */
class Catalogo_planos
{
    /** @var mixed Query Builder ligado ao banco mestre */
    private $db;

    /** Limites do plano: coluna => rótulo. Vazio = sem limite. */
    public const LIMITES = [
        'limite_usuarios' => 'Usuários',
        'limite_os_mes' => 'OS por mês',
        'limite_armazenamento_mb' => 'Armazenamento (MB)',
    ];

    /**
     * @param  array{db?: mixed}|null  $opcoes
     */
    public function __construct($opcoes = null)
    {
        $opcoes = is_array($opcoes) ? $opcoes : [];
        $this->db = $opcoes['db'] ?? get_instance()->db;
    }

    /**
     * Planos com o número de empresas em cada um.
     *
     * @return list<array>
     */
    public function listar(bool $soAtivos = false): array
    {
        $this->db->select('p.*, COUNT(t.id) AS empresas', false)
            ->from('planos p')
            ->join('tenants t', 't.plano_id = p.id', 'left')
            ->group_by('p.id')
            ->order_by('p.ativo', 'DESC')
            ->order_by('p.preco_mensal', 'ASC')
            ->order_by('p.nome', 'ASC');
        if ($soAtivos) {
            $this->db->where('p.ativo', 1);
        }

        return $this->db->get()->result_array();
    }

    public function porId(int $id): ?array
    {
        $plano = $this->db->where('id', $id)->get('planos')->row_array();

        return $plano ?: null;
    }

    /**
     * Cria (sem $id) ou altera um plano e devolve o id. Os dados vêm já
     * validados pelo controller; aqui só confere o nome repetido.
     *
     * @param  array{nome:string, preco_mensal:string, cortesia:bool, limite_usuarios:?int, limite_os_mes:?int, limite_armazenamento_mb:?int}  $dados
     */
    public function salvar(?int $id, array $dados): int
    {
        $nome = trim($dados['nome']);
        $repetido = $this->db->where('nome', $nome);
        if ($id !== null) {
            $repetido->where('id !=', $id);
        }
        if ($repetido->count_all_results('planos') > 0) {
            throw new InvalidArgumentException('Já existe um plano com esse nome.');
        }

        $linha = [
            'nome' => $nome,
            'preco_mensal' => $dados['cortesia'] ? '0.00' : $dados['preco_mensal'],
            'cortesia' => $dados['cortesia'] ? 1 : 0,
        ];
        foreach (array_keys(self::LIMITES) as $coluna) {
            $linha[$coluna] = $dados[$coluna];
        }

        if ($id === null) {
            $this->db->insert('planos', $linha + ['ativo' => 1, 'criado_em' => date('Y-m-d H:i:s')]);

            return (int) $this->db->insert_id();
        }

        $this->db->where('id', $id)->update('planos', $linha + ['atualizado_em' => date('Y-m-d H:i:s')]);

        return $id;
    }

    public function alterarAtivo(int $id, bool $ativo): void
    {
        $this->db->where('id', $id)->update('planos', ['ativo' => $ativo ? 1 : 0, 'atualizado_em' => date('Y-m-d H:i:s')]);
    }

    /**
     * Texto curto dos limites: "5 usuários · 500 OS/mês · 2048 MB".
     */
    public static function resumoLimites(array $plano): string
    {
        $partes = [];
        foreach (['limite_usuarios' => ' usuários', 'limite_os_mes' => ' OS/mês', 'limite_armazenamento_mb' => ' MB'] as $coluna => $sufixo) {
            if (($plano[$coluna] ?? null) !== null) {
                $partes[] = number_format((int) $plano[$coluna], 0, ',', '.') . $sufixo;
            }
        }

        return $partes === [] ? 'Sem limites' : implode(' · ', $partes);
    }

    public static function precoLegivel(array $plano): string
    {
        return (int) ($plano['cortesia'] ?? 0) === 1 ? 'Cortesia' : 'R$ ' . number_format((float) $plano['preco_mensal'], 2, ',', '.') . '/mês';
    }
}
