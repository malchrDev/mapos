<?php

/**
 * Autenticação em dois fatores das contas do painel da plataforma (banco
 * mestre). Mesmo desenho do 2FA dos usuários dos tenants (#2876): segredo TOTP
 * cifrado, último passo aceito (um código vale uma vez) e códigos de
 * recuperação guardados só como SHA-256.
 *
 * No painel o 2FA é obrigatório: quem entra sem ele só alcança a tela de
 * ativação (Plataforma_Controller::exigirLogin()).
 */
class Migration_add_plataforma_2fa extends CI_Migration
{
    public function up()
    {
        $this->dbforge->add_column('plataforma_usuarios', [
            'totp_secreto' => ['type' => 'TEXT', 'null' => true],
            'totp_ativado_em' => ['type' => 'DATETIME', 'null' => true],
            'totp_ultimo_passo' => ['type' => 'BIGINT', 'constraint' => 20, 'null' => true],
        ]);

        $this->dbforge->add_field([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'codigo_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'usado_em' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->dbforge->add_key('id', true);
        $this->dbforge->add_key('usuario_id');
        $this->dbforge->create_table('plataforma_codigos_recuperacao', true, ['ENGINE' => 'InnoDB']);

        if (! $this->db->table_exists('plataforma_codigos_recuperacao') || ! $this->db->field_exists('totp_secreto', 'plataforma_usuarios')) {
            throw new RuntimeException('Não foi possível criar o 2FA do painel; veja o log da aplicação.');
        }

        $this->db->query('ALTER TABLE ' . $this->db->protect_identifiers('plataforma_codigos_recuperacao')
            . ' ADD CONSTRAINT plataforma_codigos_usuario FOREIGN KEY (usuario_id) REFERENCES '
            . $this->db->protect_identifiers('plataforma_usuarios') . ' (id) ON DELETE CASCADE');
    }

    public function down()
    {
        $this->dbforge->drop_table('plataforma_codigos_recuperacao', true);
        foreach (['totp_secreto', 'totp_ativado_em', 'totp_ultimo_passo'] as $coluna) {
            if ($this->db->field_exists($coluna, 'plataforma_usuarios')) {
                $this->dbforge->drop_column('plataforma_usuarios', $coluna);
            }
        }
    }
}
