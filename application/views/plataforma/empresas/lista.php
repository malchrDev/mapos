<?php
/**
 * Empresas (tenants) da plataforma, no padrão das listagens da v5
 * (views/clientes/clientes.php): filtros por GET, data-table que vira cartões
 * no celular, empty-state diferente para "nada cadastrado" e "nada encontrado".
 *
 * @var list<array> $empresas  Tenant_manager::listarParaPainel()
 * @var array<string, string> $filtros
 */
$temFiltro = $filtros !== [];
$total = count($empresas);

$colunas = [
    ['key' => 'id', 'label' => 'Cód.', 'align' => 'right', 'nowrap' => true],
    ['label' => 'Empresa', 'class' => 'min-w-40', 'render' => fn ($t) => component('link', ['label' => $t['nome'], 'href' => site_url('empresas/' . $t['id']), 'class' => 'font-medium'])],
    ['label' => 'Endereço', 'render' => fn ($t) => (string) ($t['dominio'] ?? '') . ((int) $t['total_dominios'] > 1 ? ' (+' . ((int) $t['total_dominios'] - 1) . ')' : '')],
    ['label' => 'Status', 'nowrap' => true, 'render' => fn ($t) => component('pill-status', plataformaStatusPill((string) $t['status']))],
    ['label' => 'Plano', 'nowrap' => true, 'hide_until' => 'md', 'render' => function ($t) {
        $vencimento = plataformaVencimentoPill($t['data_expiracao'] ?? null);

        $plano = (string) ($t['plano'] ?? '—');

        return $vencimento !== null ? [$plano, ' ', component('pill-status', $vencimento)] : $plano;
    }],
    ['label' => 'Criada em', 'nowrap' => true, 'hide_until' => 'md', 'render' => fn ($t) => date('d/m/Y', strtotime((string) $t['criado_em']))],
    ['label' => 'Ações', 'align' => 'right', 'nowrap' => true, 'render' => fn ($t) => component('button', ['label' => 'Ver ' . $t['nome'], 'icon' => 'eye', 'icon_only' => true, 'variant' => 'ghost', 'size' => 'sm', 'href' => site_url('empresas/' . $t['id'])])],
];

$vazio = $temFiltro
    ? component('empty-state', [
        'title' => 'Nenhuma empresa encontrada',
        'message' => 'Nada corresponde aos filtros. Confira a busca ou limpe os filtros.',
        'icon' => 'search-x',
        'action' => component('button', ['label' => 'Limpar filtros', 'variant' => 'outline', 'href' => site_url('empresas')]),
        'class' => 'border-0',
    ])
    : component('empty-state', [
        'title' => 'Nenhuma empresa cadastrada',
        'message' => 'As empresas criadas na plataforma aparecem aqui.',
        'icon' => 'house',
        'action' => component('button', ['label' => 'Criar empresa', 'icon' => 'plus', 'variant' => 'outline', 'href' => site_url('empresas/nova')]),
        'class' => 'border-0',
    ]);
?>
<div class="flex flex-col gap-4 pt-2 pb-8">
    <header>
        <h1 class="font-display text-heading-xl text-text">Empresas</h1>
        <p class="text-caption text-muted" aria-live="polite">
            <?= e(($total === 1 ? '1 empresa' : number_format($total, 0, ',', '.') . ' empresas') . ($temFiltro ? ($total === 1 ? ' encontrada' : ' encontradas') . ' com os filtros' : '')) ?>
        </p>
    </header>

    <form method="get" action="<?= e(site_url('empresas')) ?>" role="search" aria-label="Filtrar empresas" class="grid items-end gap-3 rounded-xl border border-border bg-surface p-4 sm:grid-cols-[minmax(0,1fr)_12rem_auto]">
        <?= component('input', [
            'name' => 'busca',
            'label' => 'Buscar',
            'type' => 'search',
            'value' => $filtros['busca'] ?? null,
            'placeholder' => 'Nome, CPF/CNPJ ou endereço',
            'attrs' => ['maxlength' => 100],
        ]) ?>
        <?= component('select', [
            'name' => 'status',
            'label' => 'Status',
            'placeholder' => 'Todos',
            'options' => ['ativo' => 'Ativas', 'suspenso' => 'Suspensas', 'cancelado' => 'Canceladas'],
            'selected' => $filtros['status'] ?? null,
        ]) ?>
        <div class="flex gap-2">
            <?= component('button', ['label' => 'Filtrar', 'icon' => 'search', 'variant' => 'outline', 'type' => 'submit']) ?>
            <?php if ($temFiltro) { ?>
                <?= component('button', ['label' => 'Limpar', 'icon' => 'x', 'variant' => 'ghost', 'href' => site_url('empresas')]) ?>
            <?php } ?>
        </div>
    </form>

    <?= component('data-table', [
        'columns' => $colunas,
        'rows' => $empresas,
        'empty' => $vazio,
        'caption' => 'Empresas da plataforma',
    ]) ?>
</div>
