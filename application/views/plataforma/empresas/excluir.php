<?php
/**
 * Exclusão definitiva de uma empresa cancelada (LGPD). O formulário pede o
 * endereço da empresa digitado e a senha de quem exclui; o envio passa por
 * modal-confirm com button-danger. O backup final é gerado antes de apagar.
 *
 * @var array                 $tenant
 * @var string                $dominio
 * @var array|null            $consumo
 * @var array<string, string> $erros   confirmacao, senha, _geral
 */
$id = (int) $tenant['id'];
$caixa = 'rounded-xl border border-border bg-surface p-4 sm:p-6';
?>
<div class="flex max-w-3xl flex-col gap-4 pt-2 pb-8">
    <header>
        <h1 class="font-display text-heading-xl text-text">Excluir <?= e($tenant['nome']) ?></h1>
        <p class="text-caption text-muted">Exclusão definitiva, para o direito ao esquecimento (LGPD). Não dá para desfazer pelo painel.</p>
    </header>

    <?php if (isset($erros['_geral'])) { ?>
        <?= component('alert', ['variant' => 'danger', 'message' => $erros['_geral']]) ?>
    <?php } ?>

    <section class="<?= e($caixa) ?>" aria-labelledby="secao-o-que">
        <h2 id="secao-o-que" class="text-heading-sm text-text">O que acontece</h2>
        <ol class="mt-3 list-decimal space-y-1 pl-5 text-caption text-muted">
            <li>Um backup final é gerado e fica em Backups. Se ele falhar, nada é apagado.</li>
            <li>O banco da empresa é apagado<?= $consumo !== null ? e(' (' . $consumo['usuarios'] . ' usuário(s) ativo(s), ' . number_format((float) $consumo['armazenamento_mb'], 1, ',', '.') . ' MB de arquivos)') : '' ?>.</li>
            <li>Os arquivos enviados, a logo e as fotos de perfil são apagados.</li>
            <li>Os domínios deixam de ser da empresa e o registro dela sai da plataforma. O histórico do painel fica.</li>
        </ol>
        <p class="mt-3 text-caption text-muted">O backup final também tem dados pessoais: apague-o quando o prazo de guarda acabar.</p>
    </section>

    <form id="form-excluir-empresa" method="post" action="<?= e(site_url('empresas/' . $id . '/excluir')) ?>" novalidate class="<?= e($caixa) ?> flex flex-col gap-4">
        <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
        <?= component('input', ['name' => 'confirmacao', 'label' => 'Digite ' . $dominio . ' para confirmar', 'required' => true, 'autocomplete' => 'off', 'error' => $erros['confirmacao'] ?? null, 'attrs' => ['autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
        <?= component('input', ['name' => 'senha', 'label' => 'Sua senha', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'error' => $erros['senha'] ?? null]) ?>
        <div class="flex flex-wrap justify-end gap-2">
            <?= component('button', ['label' => 'Voltar', 'variant' => 'ghost', 'href' => site_url('empresas/' . $id)]) ?>
            <?= component('button', ['label' => 'Excluir definitivamente', 'icon' => 'trash-2', 'variant' => 'outline', 'attrs' => ['data-modal-abrir' => 'excluir-empresa']]) ?>
        </div>
    </form>
</div>

<?= component('modal-confirm', [
    'id' => 'excluir-empresa',
    'title' => 'Excluir a empresa para sempre?',
    'message' => [
        new HtmlSeguro('<strong class="font-semibold text-text">' . e($tenant['nome']) . '</strong>'),
        ' e todos os dados dela serão apagados. Só o backup final fica guardado.',
    ],
    'confirm_label' => 'Excluir para sempre',
    'confirm_attrs' => ['form' => 'form-excluir-empresa'],
]) ?>
