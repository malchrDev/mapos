<?php

/**
 * Vários domínios por tenant (Fase 1 — multi-tenancy, banco mestre).
 *
 * Um tenant atende pelo subdomínio da plataforma (`loja.SAAS_DOMINIO_BASE`) e,
 * opcionalmente, por domínios próprios. O subdomínio nasce verificado; um
 * domínio próprio só resolve depois de o cliente provar que é dono dele (TXT
 * no DNS com o `verificacao_token`) — sem isso, qualquer tenant poderia
 * cadastrar o domínio de outra empresa e receber o tráfego dela quando o DNS
 * apontasse para a plataforma.
 *
 * `tenants.dominio` sai: dois lugares guardando o domínio poderiam divergir. O
 * que existia vira o domínio de plataforma do tenant.
 */
class Migration_create_tenant_dominios extends CI_Migration
{
    private $table = 'tenant_dominios';

    public function up()
    {
        $this->dbforge->add_field([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'dominio' => ['type' => 'VARCHAR', 'constraint' => 255],
            // plataforma (subdomínio nosso) ou proprio (domínio do cliente).
            'tipo' => ['type' => 'VARCHAR', 'constraint' => 20],
            'verificacao_token' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            // Só domínio com verificado_em resolve no hook.
            'verificado_em' => ['type' => 'DATETIME', 'null' => true],
            'criado_em' => ['type' => 'DATETIME'],
        ]);
        $this->dbforge->add_key('id', true);
        $this->dbforge->add_key('tenant_id');
        $this->dbforge->create_table($this->table, true, ['ENGINE' => 'InnoDB']);

        if (! $this->db->table_exists($this->table)) {
            throw new RuntimeException("Não foi possível criar a tabela {$this->table}; veja o log da aplicação.");
        }

        $tabela = $this->db->protect_identifiers($this->table);
        // Um hostname atende um tenant só, entre todos os tenants.
        $this->db->query("CREATE UNIQUE INDEX tenant_dominios_dominio ON {$tabela} (dominio)");
        $this->db->query("ALTER TABLE {$tabela} ADD CONSTRAINT tenant_dominios_tenant FOREIGN KEY (tenant_id) REFERENCES "
            . $this->db->protect_identifiers('tenants') . ' (id) ON DELETE CASCADE');

        if ($this->db->field_exists('dominio', 'tenants')) {
            $this->db->query("INSERT INTO {$tabela} (tenant_id, dominio, tipo, verificado_em, criado_em) "
                . "SELECT id, dominio, 'plataforma', criado_em, criado_em FROM " . $this->db->protect_identifiers('tenants'));
            $this->db->query('DROP INDEX tenants_dominio ON ' . $this->db->protect_identifiers('tenants'));
            $this->dbforge->drop_column('tenants', 'dominio');
        }
    }

    public function down()
    {
        $this->dbforge->add_column('tenants', [
            'dominio' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'id'],
        ]);
        // Volta o domínio de plataforma; domínios próprios se perdem no rollback.
        $this->db->query('UPDATE ' . $this->db->protect_identifiers('tenants') . ' t JOIN '
            . $this->db->protect_identifiers($this->table) . " d ON d.tenant_id = t.id AND d.tipo = 'plataforma' SET t.dominio = d.dominio");
        $this->db->query('CREATE UNIQUE INDEX tenants_dominio ON ' . $this->db->protect_identifiers('tenants') . ' (dominio)');
        $this->dbforge->drop_table($this->table, true);
    }
}
