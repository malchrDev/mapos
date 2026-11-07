<?php

/**
 * Painel administrativo da plataforma (banco mestre).
 *
 * No hostname do painel (SAAS_PAINEL_HOST) a conexão da aplicação aponta para
 * o banco mestre, então ele precisa das tabelas que o framework usa em toda
 * requisição — `ci_sessions` (sessão) e `login_attempts` (Limite_login) — além
 * das contas e da trilha de auditoria da plataforma.
 *
 * As contas da plataforma não têm nada a ver com os usuários dos tenants: moram
 * só aqui, e um usuário de tenant nunca entra no painel (nem o contrário).
 */
class Migration_create_plataforma extends CI_Migration
{
    public function up()
    {
        // Mesma estrutura das do schema de negócio (create_base e
        // create_login_attempts): o framework e o Limite_login leem igual.
        $this->dbforge->add_field([
            'id' => ['type' => 'VARCHAR', 'constraint' => 128],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45],
            'timestamp' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'default' => 0],
            'data' => ['type' => 'BLOB'],
        ]);
        $this->dbforge->add_key('id', true);
        $this->dbforge->add_key('timestamp');
        $this->dbforge->create_table('ci_sessions', true, ['ENGINE' => 'InnoDB']);

        $this->dbforge->add_field([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'escopo' => ['type' => 'VARCHAR', 'constraint' => 20],
            'tipo' => ['type' => 'VARCHAR', 'constraint' => 10],
            'chave' => ['type' => 'CHAR', 'constraint' => 64],
            'falhas' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'bloqueado_ate' => ['type' => 'DATETIME', 'null' => true],
            'ultima_falha' => ['type' => 'DATETIME'],
        ]);
        $this->dbforge->add_key('id', true);
        $this->dbforge->add_key('ultima_falha');
        $this->dbforge->create_table('login_attempts', true, ['ENGINE' => 'InnoDB']);

        $this->dbforge->add_field([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'nome' => ['type' => 'VARCHAR', 'constraint' => 120],
            'email' => ['type' => 'VARCHAR', 'constraint' => 160],
            'senha' => ['type' => 'VARCHAR', 'constraint' => 255],
            'ativo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            // Sobe a cada troca de senha ou desativação: a sessão aberta antes
            // deixa de valer na próxima requisição.
            'sessao_versao' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'ultimo_login_em' => ['type' => 'DATETIME', 'null' => true],
            'criado_em' => ['type' => 'DATETIME'],
        ]);
        $this->dbforge->add_key('id', true);
        $this->dbforge->create_table('plataforma_usuarios', true, ['ENGINE' => 'InnoDB']);

        // Quem fez o quê no painel: criar, suspender, reativar, trocar senha de
        // admin de tenant, cadastrar domínio. O nome fica copiado para a linha
        // continuar legível mesmo se a conta for removida.
        $this->dbforge->add_field([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'usuario_nome' => ['type' => 'VARCHAR', 'constraint' => 120],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'acao' => ['type' => 'VARCHAR', 'constraint' => 60],
            'detalhes' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'ip' => ['type' => 'VARCHAR', 'constraint' => 45],
            'criado_em' => ['type' => 'DATETIME'],
        ]);
        $this->dbforge->add_key('id', true);
        $this->dbforge->add_key('tenant_id');
        $this->dbforge->add_key('criado_em');
        $this->dbforge->create_table('plataforma_auditoria', true, ['ENGINE' => 'InnoDB']);

        foreach (['ci_sessions', 'login_attempts', 'plataforma_usuarios', 'plataforma_auditoria'] as $tabela) {
            if (! $this->db->table_exists($tabela)) {
                throw new RuntimeException("Não foi possível criar a tabela {$tabela}; veja o log da aplicação.");
            }
        }

        $this->db->query('CREATE UNIQUE INDEX login_attempts_chave ON ' . $this->db->protect_identifiers('login_attempts') . ' (escopo, tipo, chave)');
        $this->db->query('CREATE UNIQUE INDEX plataforma_usuarios_email ON ' . $this->db->protect_identifiers('plataforma_usuarios') . ' (email)');
    }

    public function down()
    {
        foreach (['plataforma_auditoria', 'plataforma_usuarios', 'login_attempts', 'ci_sessions'] as $tabela) {
            $this->dbforge->drop_table($tabela, true);
        }
    }
}
