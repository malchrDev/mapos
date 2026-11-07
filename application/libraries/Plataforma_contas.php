<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Contas e auditoria do painel administrativo da plataforma (banco mestre).
 *
 *     $this->load->library('Plataforma_contas');                       // painel: $CI->db já é o mestre
 *     $this->load->library('Plataforma_contas', ['db' => $mestre]);    // CLI: conexão do Tenant_manager
 *
 * Nada aqui toca os schemas dos tenants.
 */
class Plataforma_contas
{
    /** @var mixed Query Builder do CodeIgniter ligado ao banco mestre */
    private $db;

    /**
     * @param  array{db?: mixed}|null  $opcoes
     */
    public function __construct($opcoes = null)
    {
        $opcoes = is_array($opcoes) ? $opcoes : [];
        $this->db = $opcoes['db'] ?? get_instance()->db;
    }

    /**
     * Conta ativa do e-mail, ou null. A senha é conferida por quem chama, com
     * senhaConfere(), para gastar o mesmo tempo com e sem conta.
     */
    public function porEmail(string $email): ?object
    {
        $conta = $this->db->where('email', mb_strtolower(trim($email)))->get('plataforma_usuarios')->row();

        return $conta ?: null;
    }

    public function porId(int $id): ?object
    {
        $conta = $this->db->where('id', $id)->get('plataforma_usuarios')->row();

        return $conta ?: null;
    }

    /**
     * Cria uma conta com senha aleatória e devolve a senha (só existe aqui).
     */
    public function criar(string $nome, string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('E-mail inválido.');
        }
        if (trim($nome) === '') {
            throw new InvalidArgumentException('O nome é obrigatório.');
        }
        if ($this->porEmail($email) !== null) {
            throw new RuntimeException("Já existe uma conta da plataforma com o e-mail {$email}.");
        }

        $senha = Tenant_manager::gerarSenha();
        $ok = $this->db->insert('plataforma_usuarios', [
            'nome' => trim($nome),
            'email' => $email,
            'senha' => password_hash($senha, PASSWORD_DEFAULT),
            'ativo' => 1,
            'sessao_versao' => 0,
            'criado_em' => date('Y-m-d H:i:s'),
        ]);
        if (! $ok) {
            throw new RuntimeException('Não foi possível criar a conta: ' . ($this->db->error()['message'] ?? ''));
        }

        return $senha;
    }

    /**
     * Nova senha aleatória para a conta; derruba as sessões abertas dela.
     */
    public function redefinirSenha(string $email): string
    {
        $conta = $this->porEmail($email);
        if ($conta === null) {
            throw new RuntimeException("Nenhuma conta da plataforma com o e-mail {$email}.");
        }

        $senha = Tenant_manager::gerarSenha();
        $this->db->set('senha', password_hash($senha, PASSWORD_DEFAULT))
            ->set('sessao_versao', 'sessao_versao + 1', false)
            ->where('id', $conta->id)
            ->update('plataforma_usuarios');

        return $senha;
    }

    public function registrarLogin(int $id): void
    {
        $this->db->where('id', $id)->update('plataforma_usuarios', ['ultimo_login_em' => date('Y-m-d H:i:s')]);
    }

    /**
     * Grava uma linha na trilha de auditoria do painel.
     */
    public function auditar(?object $conta, string $acao, ?string $detalhes, string $ip, ?int $tenantId = null): void
    {
        $this->db->insert('plataforma_auditoria', [
            'usuario_id' => $conta->id ?? null,
            'usuario_nome' => $conta->nome ?? 'Sistema',
            'tenant_id' => $tenantId,
            'acao' => substr($acao, 0, 60),
            'detalhes' => $detalhes !== null ? mb_substr($detalhes, 0, 500) : null,
            'ip' => substr($ip, 0, 45),
            'criado_em' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Últimas linhas da auditoria, opcionalmente de um tenant.
     *
     * @return list<object>
     */
    public function auditoria(int $limite = 50, ?int $tenantId = null): array
    {
        if ($tenantId !== null) {
            $this->db->where('tenant_id', $tenantId);
        }

        return $this->db->order_by('id', 'DESC')->limit($limite)->get('plataforma_auditoria')->result();
    }
}
