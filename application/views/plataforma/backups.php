<?php
/**
 * Backups das empresas, inclusive o backup final das que foram excluídas
 * (aparecem como "Excluída"). Download pelo controller, com auditoria.
 *
 * @var list<array>                                $backups
 * @var array<string, array{id:int, nome:string}>  $empresas  schema => empresa
 * @var string|null                                $erro
 */
$colunas = [
    ['label' => 'Empresa', 'render' => fn ($b) => isset($empresas[$b['schema']])
        ? component('link', ['label' => $empresas[$b['schema']]['nome'], 'href' => site_url('empresas/' . $empresas[$b['schema']]['id'])])
        : [$b['schema'], ' ', component('pill-status', ['label' => 'Excluída', 'variant' => 'neutral'])]],
    ['label' => 'Gerado em', 'nowrap' => true, 'render' => fn ($b) => date('d/m/Y H:i', $b['criado_em'])],
    ['label' => 'Tamanho', 'nowrap' => true, 'render' => fn ($b) => plataformaTamanho($b['tamanho'])],
    ['label' => 'Ações', 'align' => 'right', 'nowrap' => true, 'render' => fn ($b) => component('button', ['label' => 'Baixar ' . $b['arquivo'], 'icon' => 'download', 'icon_only' => true, 'variant' => 'ghost', 'size' => 'sm', 'href' => site_url('backups/' . $b['arquivo'])])],
];
?>
<div class="flex flex-col gap-4 pt-2 pb-8">
    <header>
        <h1 class="font-display text-heading-xl text-text">Backups</h1>
        <p class="text-caption text-muted">Cada arquivo traz o banco da empresa, os arquivos enviados, a logo e as fotos de perfil, com as instruções de restauração (LEIA-ME.txt). Para gerar um backup novo, abra a empresa.</p>
    </header>

    <?php if ($erro !== null) { ?>
        <?= component('alert', ['variant' => 'danger', 'message' => $erro]) ?>
    <?php } ?>

    <?= component('data-table', ['columns' => $colunas, 'rows' => $backups, 'caption' => 'Backups das empresas', 'empty' => 'Nenhum backup ainda.']) ?>
</div>
