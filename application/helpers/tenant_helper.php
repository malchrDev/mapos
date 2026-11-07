<?php

/**
 * Empresa (tenant) atendida agora, para o que precisa ficar separado por
 * empresa além do banco: pasta de uploads e chave dos tokens da API.
 *
 * O hook tenant.php grava o schema em $GLOBALS['mapos_tenant_schema'] e o
 * Tenant_manager faz o mesmo enquanto roda as migrations de um tenant. Na
 * instalação única (multi-tenancy desligada) não há valor e tudo funciona
 * como antes.
 */
if (! function_exists('tenantSchemaAtual')) {
    /**
     * Schema da empresa atual, ou null na instalação única. Um valor fora do
     * padrão de nome de schema derruba a requisição em vez de cair no
     * comportamento da instalação única.
     */
    function tenantSchemaAtual(): ?string
    {
        $schema = $GLOBALS['mapos_tenant_schema'] ?? null;
        if ($schema === null || $schema === '') {
            return null;
        }

        if (! is_string($schema) || ! preg_match('/^[a-z0-9_]{1,64}$/', $schema)) {
            throw new RuntimeException('Schema de tenant inválido.');
        }

        return $schema;
    }
}
