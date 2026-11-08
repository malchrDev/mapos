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
    public const TAMANHO_MINIMO_SENHA = 12;

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

        return $this->redefinirSenhaPorId((int) $conta->id);
    }

    public function redefinirSenhaPorId(int $id): string
    {
        $senha = Tenant_manager::gerarSenha();
        $this->trocarSenha($id, $senha);

        return $senha;
    }

    /**
     * Grava a senha (com hash) e derruba as sessões abertas da conta. O
     * tamanho mínimo (TAMANHO_MINIMO_SENHA) é conferido por quem chama.
     */
    public function trocarSenha(int $id, string $senha): void
    {
        $this->db->set('senha', password_hash($senha, PASSWORD_DEFAULT))
            ->set('sessao_versao', 'sessao_versao + 1', false)
            ->where('id', $id)
            ->update('plataforma_usuarios');
    }

    /**
     * Contas para a tela de contas: sem senha nem segredo do 2FA.
     *
     * @return list<array{id:int, nome:string, email:string, ativo:bool, dois_fatores:bool, ultimo_login_em:?string, criado_em:string}>
     */
    public function listar(): array
    {
        $linhas = $this->db->select('id, nome, email, ativo, totp_ativado_em, ultimo_login_em, criado_em')
            ->order_by('nome', 'ASC')->get('plataforma_usuarios')->result_array();

        return array_map(static fn (array $l) => [
            'id' => (int) $l['id'],
            'nome' => (string) $l['nome'],
            'email' => (string) $l['email'],
            'ativo' => (int) $l['ativo'] === 1,
            'dois_fatores' => $l['totp_ativado_em'] !== null,
            'ultimo_login_em' => $l['ultimo_login_em'],
            'criado_em' => (string) $l['criado_em'],
        ], $linhas);
    }

    public function contasAtivas(): int
    {
        return (int) $this->db->where('ativo', 1)->count_all_results('plataforma_usuarios');
    }

    /**
     * Ativa ou desativa a conta. Desativar derruba as sessões dela; a última
     * conta ativa não pode ser desativada (o painel ficaria sem ninguém).
     */
    public function alterarAtivo(int $id, bool $ativo): void
    {
        $conta = $this->porId($id);
        if ($conta === null) {
            throw new RuntimeException('Conta não encontrada.');
        }
        if (! $ativo && (int) $conta->ativo === 1 && $this->contasAtivas() <= 1) {
            throw new RuntimeException('Esta é a única conta ativa do painel: crie outra antes de desativá-la.');
        }

        $this->db->set('ativo', $ativo ? 1 : 0);
        if (! $ativo) {
            $this->db->set('sessao_versao', 'sessao_versao + 1', false);
        }
        $this->db->where('id', $id)->update('plataforma_usuarios');
    }

    /**
     * Liga o 2FA: grava o segredo cifrado, o passo já usado na confirmação e
     * os hashes dos códigos de recuperação, tudo ou nada.
     *
     * @param  list<string>  $hashesDosCodigos
     */
    public function ativarTotp(int $id, string $segredoCifrado, array $hashesDosCodigos, int $passo): bool
    {
        $this->db->trans_start();
        $this->db->where('id', $id)->where('totp_ativado_em IS NULL', null, false)->update('plataforma_usuarios', [
            'totp_secreto' => $segredoCifrado,
            'totp_ativado_em' => date('Y-m-d H:i:s'),
            'totp_ultimo_passo' => $passo,
        ]);
        $ativou = $this->db->affected_rows() === 1;
        if ($ativou) {
            $this->gravarCodigos($id, $hashesDosCodigos);
        }
        $this->db->trans_complete();

        return $ativou && $this->db->trans_status();
    }

    /**
     * Apaga o 2FA da conta (celular perdido): no próximo login ela cadastra de
     * novo. As sessões abertas caem.
     */
    public function redefinirTotp(int $id): void
    {
        $this->db->trans_start();
        $this->db->where('usuario_id', $id)->delete('plataforma_codigos_recuperacao');
        $this->db->set('totp_secreto', null)
            ->set('totp_ativado_em', null)
            ->set('totp_ultimo_passo', null)
            ->set('sessao_versao', 'sessao_versao + 1', false)
            ->where('id', $id)
            ->update('plataforma_usuarios');
        $this->db->trans_complete();
    }

    /** @param  list<string>  $hashesDosCodigos */
    public function substituirCodigos(int $id, array $hashesDosCodigos): void
    {
        $this->db->trans_start();
        $this->db->where('usuario_id', $id)->delete('plataforma_codigos_recuperacao');
        $this->gravarCodigos($id, $hashesDosCodigos);
        $this->db->trans_complete();
    }

    public function codigosRestantes(int $id): int
    {
        return (int) $this->db->where('usuario_id', $id)->where('usado_em IS NULL', null, false)->count_all_results('plataforma_codigos_recuperacao');
    }

    /**
     * Confere o código do segundo passo do login: de recuperação (uso único)
     * ou do aplicativo (a mesma janela de 30 s não vale duas vezes, nem em
     * requisições simultâneas). Quem chama conta a falha no Limite_login.
     *
     * @param  object  $totp   Biblioteca Totp
     * @param  object  $conta  Linha de plataforma_usuarios
     */
    public function aceitarCodigo(object $totp, object $conta, string $codigo): bool
    {
        if (Totp::pareceCodigoDeRecuperacao($codigo)) {
            $this->db->set('usado_em', date('Y-m-d H:i:s'))
                ->where('usuario_id', (int) $conta->id)
                ->where('codigo_hash', Totp::hashDoCodigo($codigo))
                ->where('usado_em IS NULL', null, false)
                ->update('plataforma_codigos_recuperacao');

            return $this->db->affected_rows() === 1;
        }

        $segredo = $totp->decifrar($conta->totp_secreto ?? null);
        if ($segredo === null) {
            return false;
        }

        $ultimo = ($conta->totp_ultimo_passo ?? null) !== null ? (int) $conta->totp_ultimo_passo : null;
        $passo = $totp->verificar($segredo, $codigo, $ultimo);
        if ($passo === null) {
            return false;
        }

        // Grava só se ninguém gravou este passo (ou um posterior) antes.
        $this->db->set('totp_ultimo_passo', $passo)
            ->where('id', (int) $conta->id)
            ->group_start()->where('totp_ultimo_passo IS NULL', null, false)->or_where('totp_ultimo_passo <', $passo)->group_end()
            ->update('plataforma_usuarios');

        return $this->db->affected_rows() === 1;
    }

    /** @param  list<string>  $hashesDosCodigos */
    private function gravarCodigos(int $id, array $hashesDosCodigos): void
    {
        foreach ($hashesDosCodigos as $hash) {
            $this->db->insert('plataforma_codigos_recuperacao', ['usuario_id' => $id, 'codigo_hash' => $hash]);
        }
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
