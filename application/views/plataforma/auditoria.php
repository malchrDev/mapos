<?php
/**
 * Trilha de auditoria do painel da plataforma (plataforma_auditoria): as
 * últimas 200 ações, da mais recente para a mais antiga.
 *
 * @var list<object> $linhas
 */
$colunas = [
    ['label' => 'Quando', 'nowrap' => true, 'render' => fn ($a) => date('d/m/Y H:i:s', strtotime((string) $a->criado_em))],
    ['label' => 'Quem', 'render' => fn ($a) => $a->usuario_nome],
    ['label' => 'Ação', 'render' => fn ($a) => plataformaAcaoLegivel((string) $a->acao)],
    ['label' => 'Detalhes', 'render' => fn ($a) => (string) ($a->detalhes ?? '')],
    ['label' => 'Empresa', 'nowrap' => true, 'hide_until' => 'md', 'render' => fn ($a) => $a->tenant_id !== null
        ? component('link', ['label' => 'Cód. ' . $a->tenant_id, 'href' => site_url('empresas/' . (int) $a->tenant_id)])
        : ''],
    ['label' => 'IP', 'nowrap' => true, 'hide_until' => 'lg', 'render' => fn ($a) => $a->ip],
];
?>
<div class="flex flex-col gap-4 pt-2 pb-8">
    <header>
        <h1 class="font-display text-heading-xl text-text">Auditoria</h1>
        <p class="text-caption text-muted">Últimas ações no painel da plataforma: acessos, empresas criadas, suspensas e reativadas, domínios e senhas geradas.</p>
    </header>

    <?= component('data-table', [
        'columns' => $colunas,
        'rows' => $linhas,
        'caption' => 'Auditoria do painel da plataforma',
        'empty' => component('empty-state', ['title' => 'Nada registrado ainda', 'icon' => 'history', 'class' => 'border-0']),
    ]) ?>
</div>
