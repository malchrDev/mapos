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
 * Com a multi-tenancy ligada, a requisição **falha fechada**: Host sem tenant
 * ativo recebe 404 e banco mestre fora do ar recebe 503. Cair no DB_DATABASE
 * nesses casos serviria o mesmo banco a qualquer hostname.
 *
 * CLI e instalação única (DB_MASTER_DATABASE vazio) não passam por aqui.
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

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $resultado = tenantResolverHost($host);

    switch ($resultado['status']) {
        case 'desligado':
            return;

        case 'tenant':
            tenantApontarBanco($resultado['schema'], $host);
            $GLOBALS['mapos_contexto'] = 'tenant';
            // Uploads em STORAGE_PATH/<schema>/ (armazenamentoTenant()).
            $GLOBALS['mapos_tenant_schema'] = $resultado['schema'];

            return;

        case 'painel':
            // Painel administrativo da plataforma: trabalha no banco mestre, e
            // config/routes.php só deixa chegar aos controllers de
            // controllers/plataforma/ (nenhuma tela de tenant abre aqui).
            tenantApontarBanco(trim((string) $_ENV['DB_MASTER_DATABASE']), $host);
            $GLOBALS['mapos_contexto'] = 'plataforma';

            return;

        case 'indisponivel':
            tenantResponder(503, 'Serviço indisponível', 'Não foi possível acessar o sistema agora. Tente novamente em alguns minutos.');

            // no break
        default:
            tenantResponder(404, 'Endereço não encontrado', 'Nenhuma conta ativa responde por este endereço.');
    }
}

/**
 * Aponta a conexão `default` para o banco e o base_url para o Host pedido.
 *
 * Um tenant atende por vários hostnames: os links precisam sair no hostname
 * pedido, não no APP_BASEURL fixo. O Host já foi conferido (tenant_dominios ou
 * SAAS_PAINEL_HOST), então não é cabeçalho arbitrário. config.php já foi lido
 * (a classe Hooks carrega o Config antes do pre_system), por isso o base_url
 * vai direto no objeto de config.
 */
function tenantApontarBanco(string $banco, string $host): void
{
    $_ENV['DB_DATABASE'] = $banco;
    $_SERVER['DB_DATABASE'] = $banco;
    putenv('DB_DATABASE=' . $banco);

    $baseUrl = tenantBaseUrl($host);
    $_ENV['APP_BASEURL'] = $baseUrl;
    load_class('Config', 'core')->set_item('base_url', $baseUrl);
}

/**
 * O que fazer com uma requisição para o Host informado.
 *
 * - desligado: multi-tenancy desligada (DB_MASTER_DATABASE vazio), segue normal;
 * - tenant: Host de um tenant ativo, com o schema dele;
 * - painel: hostname do painel administrativo (SAAS_PAINEL_HOST);
 * - nao_encontrado: Host inválido ou sem tenant ativo e verificado;
 * - indisponivel: o banco mestre não respondeu.
 *
 * Separada de resolverTenant() para ser exercitada fora de uma requisição web.
 *
 * @return array{status:string, schema?:string}
 */
function tenantResolverHost(string $host): array
{
    // Sem fallback para DB_DATABASE: numa instalação única isso abriria uma
    // conexão a mais por requisição, só para falhar ao procurar `tenants`.
    if (trim((string) ($_ENV['DB_MASTER_DATABASE'] ?? '')) === '') {
        return ['status' => 'desligado'];
    }

    $dominio = strtolower($host);
    $dominio = preg_replace('/:\d+$/', '', $dominio); // remove a porta, se vier com ela

    // Host é cabeçalho do cliente. Só hostname válido é consultado.
    if ($dominio === '' || filter_var($dominio, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        return ['status' => 'nao_encontrado'];
    }

    $painel = strtolower(trim((string) ($_ENV['SAAS_PAINEL_HOST'] ?? '')));
    if ($painel !== '' && $dominio === $painel) {
        return ['status' => 'painel'];
    }

    try {
        $tenant = tenantConsultarPorDominio($dominio);
    } catch (Throwable $e) {
        // Fica no log do PHP: sem isso, um erro de SQL aqui seria invisível.
        error_log('mapos tenant: falha ao consultar o banco mestre: ' . $e->getMessage());

        return ['status' => 'indisponivel'];
    }

    if ($tenant === null) {
        return ['status' => 'nao_encontrado'];
    }

    return ['status' => 'tenant', 'schema' => $tenant['schema']];
}

/**
 * Schema do tenant ativo para o Host, ou null (atalho de tenantResolverHost).
 */
function tenantSchemaDoHost(string $host): ?string
{
    $resultado = tenantResolverHost($host);

    return $resultado['status'] === 'tenant' ? $resultado['schema'] : null;
}

/**
 * base_url para o hostname pedido, preservando a porta e a subpasta do
 * index.php. HTTPS só quando o próprio servidor diz que é (`$_SERVER['HTTPS']`).
 */
function tenantBaseUrl(string $host): string
{
    $esquema = ! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' ? 'https' : 'http';
    $pasta = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

    return $esquema . '://' . strtolower($host) . $pasta . '/';
}

/**
 * Responde e encerra antes do framework (não há views nem loader ainda).
 */
function tenantResponder(int $status, string $titulo, string $mensagem): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');

    $titulo = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $mensagem = htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8');

    exit('<!DOCTYPE html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $titulo . '</title></head>'
        . '<body style="font-family:sans-serif;max-width:36rem;margin:4rem auto;padding:0 1rem;line-height:1.5">'
        . '<h1 style="font-size:1.25rem">' . $titulo . '</h1><p>' . $mensagem . '</p></body></html>');
}

/**
 * Tenant ativo dono de um domínio verificado, ou null.
 *
 * Só tenant 'ativo' é devolvido: suspenso e cancelado continuam sem acesso,
 * mesmo que o schema ainda exista. Domínio próprio sem verificação também não.
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
    $stmt = $pdo->prepare(
        'SELECT t.`id`, d.`dominio`, t.`nome`, t.`schema`, t.`status`'
        . ' FROM `tenant_dominios` d JOIN `tenants` t ON t.`id` = d.`tenant_id`'
        . ' WHERE d.`dominio` = ? AND d.`verificado_em` IS NOT NULL AND t.`status` = ? LIMIT 1'
    );
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
