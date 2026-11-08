<?php
/**
 * Planos da plataforma. Desativar um plano só o tira da lista de novas
 * empresas; quem já está nele continua. Por isso desativar e reativar são
 * POST diretos (não destroem nada).
 *
 * @var list<array> $planos
 */
$token = '<input type="hidden" name="' . e($this->security->get_csrf_token_name()) . '" value="' . e($this->security->get_csrf_hash()) . '">';

$colunas = [
    ['label' => 'Plano', 'render' => fn ($p) => component('link', ['label' => $p['nome'], 'href' => site_url('planos/' . $p['id'])])],
    ['label' => 'Preço', 'nowrap' => true, 'render' => fn ($p) => Catalogo_planos::precoLegivel($p)],
    ['label' => 'Limites', 'hide_until' => 'md', 'render' => fn ($p) => Catalogo_planos::resumoLimites($p)],
    ['label' => 'Empresas', 'align' => 'right', 'nowrap' => true, 'render' => fn ($p) => (string) (int) $p['empresas']],
    ['label' => 'Situação', 'nowrap' => true, 'render' => fn ($p) => component('pill-status', (int) $p['ativo'] === 1 ? ['label' => 'Ativo', 'variant' => 'success'] : ['label' => 'Desativado', 'variant' => 'neutral'])],
    ['label' => 'Ações', 'align' => 'right', 'nowrap' => true, 'render' => fn ($p) => [
        component('button', ['label' => 'Editar ' . $p['nome'], 'icon' => 'pencil', 'icon_only' => true, 'variant' => 'ghost', 'size' => 'sm', 'href' => site_url('planos/' . $p['id'])]),
        new HtmlSeguro('<form method="post" action="' . e(site_url('planos/' . $p['id'] . '/status')) . '" class="inline">' . $token . '<input type="hidden" name="ativo" value="' . ((int) $p['ativo'] === 1 ? '0' : '1') . '">'),
        component('button', (int) $p['ativo'] === 1
            ? ['label' => 'Desativar ' . $p['nome'], 'icon' => 'eye-off', 'icon_only' => true, 'variant' => 'ghost', 'size' => 'sm', 'type' => 'submit']
            : ['label' => 'Reativar ' . $p['nome'], 'icon' => 'eye', 'icon_only' => true, 'variant' => 'ghost', 'size' => 'sm', 'type' => 'submit']),
        new HtmlSeguro('</form>'),
    ]],
];
?>
<div class="flex flex-col gap-4 pt-2 pb-8">
    <header>
        <h1 class="font-display text-heading-xl text-text">Planos</h1>
        <p class="text-caption text-muted">Preço e limites de cada plano. A cobrança e o bloqueio por limite entram com a integração de pagamento; por enquanto o plano é cadastro e atribuição.</p>
    </header>

    <?= component('data-table', ['columns' => $colunas, 'rows' => $planos, 'caption' => 'Planos da plataforma', 'empty' => 'Nenhum plano cadastrado.']) ?>
</div>
