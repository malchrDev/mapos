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

if (! defined('TENANT_ENV_CHAVES')) {
    /**
     * Chaves do .env que cada empresa configura para si (Configurações),
     * gravadas no banco dela (configuracoes.tenant_env). true = credencial de
     * pagamento: numa empresa ela nunca herda o valor da plataforma (as
     * cobranças cairiam na conta de outro), fica vazia até a empresa preencher.
     * false = herda o padrão do .env até a empresa mudar.
     *
     * O resto do .env (banco, SMTP, chaves da plataforma) a empresa não vê nem
     * altera (TENANT_ENV_PLATAFORMA).
     */
    define('TENANT_ENV_CHAVES', [
        'IMPRIMIR_ANEXOS' => false,
        'API_ENABLED' => false,
        'API_TOKEN_EXPIRE_TIME' => false,
        'PAYMENT_GATEWAYS_EFI_PRODUCTION' => true,
        'PAYMENT_GATEWAYS_EFI_CREDENTIAIS_CLIENT_ID' => true,
        'PAYMENT_GATEWAYS_EFI_CREDENTIAIS_CLIENT_SECRET' => true,
        'PAYMENT_GATEWAYS_EFI_BOLETO_EXPIRATION' => true,
        'PAYMENT_GATEWAYS_MERCADO_PAGO_CREDENTIALS_PUBLIC_KEY' => true,
        'PAYMENT_GATEWAYS_MERCADO_PAGO_CREDENTIALS_ACCESS_TOKEN' => true,
        'PAYMENT_GATEWAYS_MERCADO_PAGO_CREDENTIALS_CLIENT_ID' => true,
        'PAYMENT_GATEWAYS_MERCADO_PAGO_CREDENTIALS_CLIENT_SECRET' => true,
        'PAYMENT_GATEWAYS_MERCADO_PAGO_BOLETO_EXPIRATION' => true,
        'PAYMENT_GATEWAYS_ASAAS_PRODUCTION' => true,
        'PAYMENT_GATEWAYS_ASAAS_NOTIFY' => true,
        'PAYMENT_GATEWAYS_ASAAS_CREDENTIAIS_API_KEY' => true,
        'PAYMENT_GATEWAYS_ASAAS_BOLETO_EXPIRATION' => true,
    ]);
}

if (! defined('TENANT_ENV_PLATAFORMA')) {
    /** Campos da tela de Configurações que, numa empresa, são só da plataforma. */
    define('TENANT_ENV_PLATAFORMA', ['EMAIL_PROTOCOL', 'EMAIL_SMTP_HOST', 'EMAIL_SMTP_PORT', 'EMAIL_SMTP_CRYPTO', 'EMAIL_SMTP_USER', 'EMAIL_SMTP_PASS', 'API_JWT_KEY']);
}

if (! function_exists('tenantEnvChave')) {
    /** Chave AES-256 dos segredos da empresa, derivada do APP_ENCRYPTION_KEY. */
    function tenantEnvChave(): string
    {
        return hash_hkdf('sha256', (string) ($_ENV['APP_ENCRYPTION_KEY'] ?? ''), 32, 'mapos-tenant-env-v1');
    }
}

if (! function_exists('tenantEnvCifrar')) {
    /** "enc:" + base64(nonce . tag . texto cifrado), AES-256-GCM. */
    function tenantEnvCifrar(string $valor): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $cifrado = openssl_encrypt($valor, 'aes-256-gcm', tenantEnvChave(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cifrado === false) {
            throw new RuntimeException('Não foi possível cifrar a configuração.');
        }

        return 'enc:' . base64_encode($nonce . $tag . $cifrado);
    }
}

if (! function_exists('tenantEnvDecifrar')) {
    /** Valor gravado (cifrado ou não); null se o cifrado não abre (outra chave, adulterado). */
    function tenantEnvDecifrar(string $valor): ?string
    {
        if (! str_starts_with($valor, 'enc:')) {
            return $valor;
        }

        $bruto = base64_decode(substr($valor, 4), true);
        if ($bruto === false || strlen($bruto) < 29) {
            return null;
        }
        $texto = openssl_decrypt(substr($bruto, 28), 'aes-256-gcm', tenantEnvChave(), OPENSSL_RAW_DATA, substr($bruto, 0, 12), substr($bruto, 12, 16));

        return $texto === false ? null : $texto;
    }
}

if (! function_exists('tenantEnvAplicar')) {
    /**
     * Põe no $_ENV da requisição a configuração da empresa (JSON de
     * configuracoes.tenant_env): primeiro apaga as credenciais de pagamento
     * herdadas da plataforma, depois aplica só as chaves de TENANT_ENV_CHAVES.
     * Chave fora da lista no JSON é ignorada: a empresa nunca troca o banco,
     * o SMTP ou as chaves da plataforma.
     */
    function tenantEnvAplicar(?string $json): void
    {
        foreach (TENANT_ENV_CHAVES as $chave => $soDaEmpresa) {
            if ($soDaEmpresa) {
                unset($_ENV[$chave], $_SERVER[$chave]);
                putenv($chave);
            }
        }

        $valores = $json !== null && $json !== '' ? json_decode($json, true) : [];
        foreach (is_array($valores) ? $valores : [] as $chave => $valor) {
            if (! is_string($chave) || ! array_key_exists($chave, TENANT_ENV_CHAVES) || ! is_string($valor)) {
                continue;
            }
            $texto = tenantEnvDecifrar($valor);
            if ($texto === null) {
                error_log('tenant: configuração ' . $chave . ' não abriu (APP_ENCRYPTION_KEY trocada?); ficou vazia.');
                $texto = '';
            }
            $_ENV[$chave] = $texto;
        }
    }
}

if (! function_exists('tenantEnvMesclar')) {
    /**
     * JSON novo de configuracoes.tenant_env: o atual com os valores salvos
     * agora. Segredos (tipo segredo da tela) vão cifrados.
     *
     * @param  array<string, string>  $novos
     * @param  list<string>           $segredos  Chaves que vão cifradas
     */
    function tenantEnvMesclar(?string $atual, array $novos, array $segredos): string
    {
        $valores = $atual !== null && $atual !== '' ? json_decode($atual, true) : [];
        $valores = is_array($valores) ? $valores : [];

        foreach ($novos as $chave => $valor) {
            if (! array_key_exists($chave, TENANT_ENV_CHAVES)) {
                throw new InvalidArgumentException('Configuração que a empresa não pode alterar: ' . $chave);
            }
            $valores[$chave] = in_array($chave, $segredos, true) && $valor !== '' ? tenantEnvCifrar($valor) : $valor;
        }
        ksort($valores);

        return (string) json_encode($valores, JSON_UNESCAPED_SLASHES);
    }
}
