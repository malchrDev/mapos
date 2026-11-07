<?php

/**
 * Planos da plataforma (banco mestre) e o plano de cada empresa.
 *
 * O plano guarda o preço mensal e os limites (usuarios, os_mes,
 * armazenamento_mb; vazio = sem limite). `cortesia` marca o plano que libera o
 * acesso sem pagamento (decisão do produto: o admin recebe o acesso na hora).
 * A cobrança e a aplicação dos limites são da Fase 5; aqui o plano é cadastro
 * e atribuição, e o vencimento usa a coluna `tenants.data_expiracao` que já
 * existia.
 *
 * Nasce com o plano "Cortesia". Empresas que já existiam ficam sem plano.
 */
class Migration_create_planos extends CI_Migration
{
    public function up()
    {
        $this->dbforge->add_field([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'nome' => ['type' => 'VARCHAR', 'constraint' => 80],
            'preco_mensal' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => '0.00'],
            'cortesia' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'limite_usuarios' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'limite_os_mes' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'limite_armazenamento_mb' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'ativo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'criado_em' => ['type' => 'DATETIME'],
            'atualizado_em' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->dbforge->add_key('id', true);
        $this->dbforge->create_table('planos', true, ['ENGINE' => 'InnoDB']);

        $this->dbforge->add_column('tenants', [
            'plano_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'cancelado_em' => ['type' => 'DATETIME', 'null' => true],
        ]);

        if (! $this->db->table_exists('planos') || ! $this->db->field_exists('plano_id', 'tenants')) {
            throw new RuntimeException('Não foi possível criar os planos; veja o log da aplicação.');
        }

        $this->db->query('CREATE UNIQUE INDEX planos_nome ON ' . $this->db->protect_identifiers('planos') . ' (nome)');
        // RESTRICT: um plano em uso não some; desative-o em vez de apagar.
        $this->db->query('ALTER TABLE ' . $this->db->protect_identifiers('tenants')
            . ' ADD CONSTRAINT tenants_plano FOREIGN KEY (plano_id) REFERENCES '
            . $this->db->protect_identifiers('planos') . ' (id) ON DELETE RESTRICT');

        $this->db->insert('planos', [
            'nome' => 'Cortesia',
            'preco_mensal' => '0.00',
            'cortesia' => 1,
            'ativo' => 1,
            'criado_em' => date('Y-m-d H:i:s'),
        ]);
    }

    public function down()
    {
        if ($this->db->field_exists('plano_id', 'tenants')) {
            $this->db->query('ALTER TABLE ' . $this->db->protect_identifiers('tenants') . ' DROP FOREIGN KEY tenants_plano');
            $this->dbforge->drop_column('tenants', 'plano_id');
        }
        if ($this->db->field_exists('cancelado_em', 'tenants')) {
            $this->dbforge->drop_column('tenants', 'cancelado_em');
        }
        $this->dbforge->drop_table('planos', true);
    }
}
