<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Resolve o tenant pelo hostname e aponta o schema da conexão (Fase 1).
 *
 * Roda em pre_system, antes de o framework abrir o banco: aqui ainda não existe
 * loader do CodeIgniter, então a consulta ao banco mestre é uma só, direta, com
 * PDO e statement parametrizado.
 *
 * O schema resolvido é gravado em $_ENV['DB_DATABASE'] — que é exatamente o que
 * config/database.php lê para montar a conexão 'default'. Assim nenhum modelo
 * precisa saber que multi-tenancy existe: a conexão já nasce no schema certo.
 *
 * Sem tenant resolvido (CLI, instalador, migração, ambiente ainda não
 * provisionado) a função não faz nada: o banco continua sendo o configurado.
 */
function resolverTenant()
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    // O hook roda uma vez por requisição (pre_system), mas se for chamado de
    // novo o schema do tenant já estaria no $_ENV.
    if (isset($GLOBALS['mapos_tenant_resolvido'])) {
        return;
    }
    $GLOBALS['mapos_tenant_resolvido'] = true;

    $schema = tenantSchemaDoHost((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($schema !== null) {
        $_ENV['DB_DATABASE'] = $schema;
        $_SERVER['DB_DATABASE'] = $schema;
        putenv('DB_DATABASE=' . $schema);
    }
}

/**
 * Schema do tenant ativo para o cabeçalho Host informado, ou null.
 *
 * Separada de resolverTenant() para poder ser exercitada fora de uma
 * requisição web (testes de integração).
 */
function tenantSchemaDoHost(string $host): ?string
{
    // DB_MASTER_DATABASE vazio ou ausente desliga a multi-tenancy. Sem
    // fallback para DB_DATABASE: numa instalação única isso abriria uma
    // conexão a mais por requisição, só para falhar ao procurar `tenants`.
    if (trim((string) ($_ENV['DB_MASTER_DATABASE'] ?? '')) === '') {
        return null;
    }

    $dominio = strtolower($host);
    $dominio = preg_replace('/:\d+$/', '', $dominio); // remove a porta, se vier com ela

    // Host é cabeçalho do cliente. Só hostname válido é consultado: o valor vai
    // para um statement parametrizado, mas restringir aqui evita consulta
    // inútil no banco mestre com hostname fora do padrão.
    if ($dominio === '' || filter_var($dominio, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        return null;
    }

    try {
        $tenant = tenantConsultarPorDominio($dominio);
    } catch (Throwable $e) {
        // Banco mestre fora do ar não pode derrubar a aplicação inteira antes
        // mesmo do framework: segue com o schema configurado e deixa o erro
        // aparecer na tela normal de falha de banco. Fica registrado no log do
        // PHP — sem isso, um erro de SQL aqui seria invisível.
        error_log('mapos tenant: falha ao consultar o banco mestre: ' . $e->getMessage());

        return null;
    }

    return $tenant['schema'] ?? null;
}

/**
 * Uma linha de tenants (banco mestre) para o domínio informado, ou null.
 *
 * Só tenant 'ativo' é devolvido: suspenso e cancelado continuam sem acesso,
 * mesmo que o schema ainda exista.
 *
 * @return array{id:int, dominio:string, schema:string, status:string, nome:string}|null
 */
function tenantConsultarPorDominio(string $dominio): ?array
{
    $bancoMestre = trim((string) ($_ENV['DB_MASTER_DATABASE'] ?? ''));
    if ($bancoMestre === '') {
        return null;
    }

    $host = $_ENV['DB_HOSTNAME'] ?? 'localhost';
    $usuario = $_ENV['DB_USERNAME'] ?? '';
    $senha = $_ENV['DB_PASSWORD'] ?? '';
    $porta = (int) ($_ENV['DB_PORT'] ?? 3306);

    $dsn = 'mysql:host=' . $host . ';port=' . $porta . ';dbname=' . $bancoMestre . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $usuario, $senha, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3,
    ]);

    // `schema` é palavra reservada do MySQL: sem crase, a consulta não compila.
    $stmt = $pdo->prepare('SELECT `id`, `dominio`, `nome`, `schema`, `status` FROM `tenants` WHERE `dominio` = ? AND `status` = ? LIMIT 1');
    $stmt->execute([$dominio, 'ativo']);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);

    if (! $linha) {
        return null;
    }

    return [
        'id' => (int) $linha['id'],
        'dominio' => (string) $linha['dominio'],
        'schema' => (string) $linha['schema'],
        'status' => (string) $linha['status'],
        'nome' => (string) $linha['nome'],
    ];
}
