<?php
/**
 * Nova conta do painel da plataforma. A senha inicial é aleatória e aparece
 * uma vez na próxima tela; no primeiro acesso a conta ativa o 2FA. Quem cria
 * confirma com a própria senha.
 *
 * @var array<string, string> $valores nome, email
 * @var array<string, string> $erros   nome, email, senha
 */
$caixa = 'rounded-xl border border-border bg-surface p-4 sm:p-6';
?>
<div class="flex max-w-3xl flex-col gap-4 pt-2 pb-8">
    <header>
        <h1 class="font-display text-heading-xl text-text">Nova conta do painel</h1>
        <p class="text-caption text-muted">A conta tem o mesmo acesso que a sua: todas as empresas, planos, backups e contas.</p>
    </header>

    <?php if ($erros !== []) { ?>
        <?= component('alert', ['variant' => 'danger', 'message' => count($erros) === 1 ? 'Confira o campo destacado.' : 'Confira os ' . count($erros) . ' campos destacados.']) ?>
    <?php } ?>

    <form method="post" action="<?= e(site_url('contas/nova')) ?>" novalidate class="flex flex-col gap-4" <?= js_module('formulario/padrao') ?>>
        <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">

        <section class="<?= e($caixa) ?>" aria-labelledby="secao-conta">
            <h2 id="secao-conta" class="text-heading-sm text-text">Pessoa</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <?= component('input', ['name' => 'nome', 'label' => 'Nome', 'required' => true, 'value' => $valores['nome'] !== '' ? $valores['nome'] : null, 'error' => $erros['nome'] ?? null, 'attrs' => ['maxlength' => 120]]) ?>
                <?= component('input', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true, 'autocomplete' => 'off', 'value' => $valores['email'] !== '' ? $valores['email'] : null, 'error' => $erros['email'] ?? null, 'attrs' => ['maxlength' => 160, 'inputmode' => 'email']]) ?>
            </div>
        </section>

        <section class="<?= e($caixa) ?>" aria-labelledby="secao-confirmar">
            <h2 id="secao-confirmar" class="text-heading-sm text-text">Confirmação</h2>
            <div class="mt-4 sm:max-w-sm">
                <?= component('input', ['name' => 'senha', 'label' => 'Sua senha', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'error' => $erros['senha'] ?? null, 'attrs' => ['data-msg-vazio' => 'Informe a sua senha.']]) ?>
            </div>
        </section>

        <div class="flex flex-wrap justify-end gap-2">
            <?= component('button', ['label' => 'Cancelar', 'variant' => 'ghost', 'href' => site_url('contas')]) ?>
            <?= component('button', ['label' => 'Criar conta', 'icon' => 'plus', 'type' => 'submit']) ?>
        </div>
    </form>
</div>
