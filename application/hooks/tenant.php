<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Resolve o tenant pelo hostname e aponta o schema da conexão (Fase 1).
 *
 * Roda em pre_system, antes de o framework abrir o banco: aqui ainda não existe
 * loader do CodeIgniter, então a consulta ao banco mestre é uma só, direta, com
 * PDO (extensão já exigida pelo driver mysqli) e statement parametrizado.
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
    // novo o schema do tenant já estaria no $_ENV e a consulta ao mestre
    // consultaria o banco errado.
    if (isset($GLOBALS['mapos_tenant_resolvido'])) {
        return;
    }
    $GLOBALS['mapos_tenant_resolvido'] = true;

    $dominio = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $dominio = preg_replace('/:\d+$/', '', $dominio); // remove a porta, se vier com ela

    // Host é cabeçalho do cliente. Só hostname válido é consultado: o valor vai
    // para um statement parametrizado, mas restringir aqui evita consulta
    // inútil no banco mestre com hostname fora do padrão.
    if ($dominio === '' || filter_var($dominio, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        return;
    }

    try {
        $tenant = tenantConsultarPorDominio($dominio);
    } catch (Throwable $e) {
        // Banco mestre fora do ar não pode derrubar a aplicação inteira antes
        // mesmo do framework: segue com o schema configurado e deixa o erro
        // aparecer na tela normal de falha de banco.
        return;
    }

    if ($tenant !== null) {
        $_ENV['DB_DATABASE'] = $tenant['schema'];
        $_SERVER['DB_DATABASE'] = $tenant['schema'];
        putenv('DB_DATABASE=' . $tenant['schema']);
    }
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
    $host = $_ENV['DB_HOSTNAME'] ?? 'localhost';
    $usuario = $_ENV['DB_USERNAME'] ?? '';
    $senha = $_ENV['DB_PASSWORD'] ?? '';
    $bancoMestre = $_ENV['DB_MASTER_DATABASE'] ?? ($_ENV['DB_DATABASE'] ?? '');
    $porta = (int) ($_ENV['DB_PORT'] ?? 3306);

    if ($bancoMestre === '') {
        return null;
    }

    $dsn = 'mysql:host=' . $host . ';port=' . $porta . ';dbname=' . $bancoMestre . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $usuario, $senha, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3,
    ]);

    $stmt = $pdo->prepare('SELECT id, dominio, nome, schema, status FROM tenants WHERE dominio = ? AND status = ? LIMIT 1');
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
