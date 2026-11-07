<?php

/**
 * Consumo do tenant, para aplicar os limites do plano (Fase 1 — multi-tenancy).
 *
 * Uma linha por tenant e métrica; o período é o mês de referência no formato
 * AAAA-MM. A soma corrente é lida pelo Tenant_manager antes de autorizar a
 * criação de um recurso que estoura o plano (usuários, OS do mês, arquivos).
 *
 * Mora no banco mestre, não no schema do tenant: é o plano cobrando o consumo,
 * e o consumo precisa ser lido mesmo quando o tenant está suspenso.
 */
class Migration_create_tenant_usage extends CI_Migration
{
    private $table = 'tenant_usage';

    public function up()
    {
        $this->dbforge->add_field([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            // usuarios, os_mes, armazenamento_mb, ...
            'metrica' => ['type' => 'VARCHAR', 'constraint' => 40],
            'periodo' => ['type' => 'CHAR', 'constraint' => 7],
            'valor' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'atualizado_em' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->dbforge->add_key('id', true);
        $this->dbforge->add_key('tenant_id');
        $this->dbforge->create_table($this->table, true, ['ENGINE' => 'InnoDB']);

        if (! $this->db->table_exists($this->table)) {
            throw new RuntimeException("Não foi possível criar a tabela {$this->table}; veja o log da aplicação.");
        }

        // Upsert por (tenant, métrica, período): sem unique, duas escritas
        // simultâneas criariam linhas duplicadas e o limite seria subcontado.
        $this->db->query('CREATE UNIQUE INDEX tenant_usage_chave ON ' . $this->db->protect_identifiers($this->table) . ' (tenant_id, metrica, periodo)');
    }

    public function down()
    {
        $this->dbforge->drop_table($this->table, true);
    }
}
