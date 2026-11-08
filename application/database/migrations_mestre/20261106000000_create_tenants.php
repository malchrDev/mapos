<?php

/**
 * Registro de tenants da plataforma SaaS (Fase 1 — multi-tenancy).
 *
 * Esta tabela vive no banco MESTRE (DB_MASTER_DATABASE), não no schema de tenant: é
 * o que permite resolver, a partir do hostname da requisição, em qual schema
 * os dados daquela empresa vivem.
 *
 * Isolamento é por schema (ADR tenants/schema-per-tenant): cada tenant tem seu
 * próprio schema MySQL no mesmo cluster. Assim o backup, a restauração e a
 * remoção definitiva de dados (LGPD) são operações de schema inteiro, sem
 * varrer linha por linha em tabelas compartilhadas.
 *
 * O schema nasce no provisionamento (application/libraries/Tenant_manager.php)
 * e o nome fica gravado aqui; nunca derivado de entrada do usuário.
 */
class Migration_create_tenants extends CI_Migration
{
    private $table = 'tenants';

    public function up()
    {
        $this->dbforge->add_field([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            // Identificação pública do tenant: subdomínio ou domínio próprio.
            'dominio' => ['type' => 'VARCHAR', 'constraint' => 255],
            'nome' => ['type' => 'VARCHAR', 'constraint' => 255],
            'documento' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            // Schema MySQL deste tenant no cluster. Nome gerado no provisionamento.
            'schema' => ['type' => 'VARCHAR', 'constraint' => 64],
            // ativo, suspenso (inadimplência/bloqueio), cancelado.
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'ativo'],
            // Limites do plano em JSON: {"usuarios":5,"os_mes":500,"armazenamento_mb":2048}
            'limites' => ['type' => 'TEXT', 'null' => true],
            'data_expiracao' => ['type' => 'DATE', 'null' => true],
            'criado_em' => ['type' => 'DATETIME'],
            'atualizado_em' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->dbforge->add_key('id', true);
        $this->dbforge->add_key('dominio');
        $this->dbforge->create_table($this->table, true, ['ENGINE' => 'InnoDB']);

        if (! $this->db->table_exists($this->table)) {
            throw new RuntimeException("Não foi possível criar a tabela {$this->table}; veja o log da aplicação.");
        }

        // Um domínio atende exatamente um tenant: sem unique, dois tenants
        // poderiam disputar o mesmo hostname e o schema resolvido seria ambíguo.
        $this->db->query('CREATE UNIQUE INDEX tenants_dominio ON ' . $this->db->protect_identifiers($this->table) . ' (dominio)');
        // Suspensão e inadimplência são consultadas a cada requisição para
        // barrar o acesso; sem índice o resolve vira table scan no banco mestre.
        $this->db->query('CREATE INDEX tenants_status ON ' . $this->db->protect_identifiers($this->table) . ' (status)');
    }

    public function down()
    {
        $this->dbforge->drop_table($this->table, true);
    }
}
