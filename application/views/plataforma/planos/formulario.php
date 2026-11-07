<?php
/**
 * Novo plano ou edição. Limite vazio = sem limite. Cortesia libera o acesso
 * sem pagamento e grava preço zero.
 *
 * @var array|null            $plano   null = novo
 * @var array<string, mixed>  $valores
 * @var array<string, string> $erros
 */
$caixa = 'rounded-xl border border-border bg-surface p-4 sm:p-6';
$valor = static fn ($v) => $v === null || $v === '' ? null : (string) $v;
?>
<div class="flex max-w-3xl flex-col gap-4 pt-2 pb-8">
    <header>
        <h1 class="font-display text-heading-xl text-text"><?= e($plano !== null ? 'Editar plano' : 'Novo plano') ?></h1>
        <?php if ($plano !== null && (int) ($plano['ativo'] ?? 1) !== 1) { ?>
            <p class="text-caption text-muted">Plano desativado: não aparece para novas empresas.</p>
        <?php } ?>
    </header>

    <?php if ($erros !== []) { ?>
        <?= component('alert', ['variant' => 'danger', 'message' => count($erros) === 1 ? 'Confira o campo destacado.' : 'Confira os ' . count($erros) . ' campos destacados.']) ?>
    <?php } ?>

    <form method="post" action="<?= e(site_url($plano !== null ? 'planos/' . $plano['id'] : 'planos/novo')) ?>" novalidate class="flex flex-col gap-4" <?= js_module('formulario/padrao') ?>>
        <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">

        <section class="<?= e($caixa) ?>" aria-labelledby="secao-plano">
            <h2 id="secao-plano" class="text-heading-sm text-text">Plano</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <?= component('input', ['name' => 'nome', 'label' => 'Nome', 'required' => true, 'value' => $valor($valores['nome']), 'error' => $erros['nome'] ?? null, 'attrs' => ['maxlength' => 80]]) ?>
                <?= component('input', ['name' => 'preco_mensal', 'label' => 'Preço mensal (R$)', 'placeholder' => '99,90', 'value' => $valor($valores['preco_mensal']), 'error' => $erros['preco_mensal'] ?? null, 'help' => 'Ignorado no plano cortesia.', 'attrs' => ['inputmode' => 'decimal', 'maxlength' => 12]]) ?>
            </div>
            <div class="mt-4">
                <?= component('checkbox', ['name' => 'cortesia', 'label' => 'Cortesia: libera o acesso sem pagamento', 'checked' => (bool) $valores['cortesia'], 'help' => 'O administrador da empresa recebe o acesso na hora, sem esperar confirmação de pagamento.']) ?>
            </div>
        </section>

        <section class="<?= e($caixa) ?>" aria-labelledby="secao-limites">
            <h2 id="secao-limites" class="text-heading-sm text-text">Limites</h2>
            <p class="mt-1 text-caption text-muted">Deixe em branco para não limitar.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <?php foreach (Catalogo_planos::LIMITES as $coluna => $rotulo) { ?>
                    <?= component('input', ['name' => $coluna, 'label' => $rotulo, 'type' => 'number', 'value' => $valor($valores[$coluna]), 'error' => $erros[$coluna] ?? null, 'attrs' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric']]) ?>
                <?php } ?>
            </div>
        </section>

        <div class="flex flex-wrap justify-end gap-2">
            <?= component('button', ['label' => 'Cancelar', 'variant' => 'ghost', 'href' => site_url('planos')]) ?>
            <?= component('button', ['label' => 'Salvar plano', 'icon' => 'save', 'type' => 'submit']) ?>
        </div>
    </form>
</div>
