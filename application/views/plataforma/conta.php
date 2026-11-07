<?php
/**
 * Minha conta, no painel da plataforma: 2FA (obrigatório) e troca de senha.
 *
 * - Sem 2FA: QR code, a chave para digitar e o formulário de ativação. É a
 *   única tela que a conta alcança até ativar.
 * - Recém-ativado (ou novos códigos): os códigos de recuperação, uma vez.
 * - Com 2FA: quantos códigos restam, gerar novos e trocar a senha.
 *
 * @var bool                  $ativo
 * @var int                   $restantes
 * @var list<string>|null     $codigos
 * @var array<string, string> $erros   codigo, senha_ativar, senha_codigos, senha_atual, nova_senha, confirmacao, _geral
 * @var string|null           $qr
 * @var string|null           $segredo
 * @var string                $email
 */
$caixa = 'rounded-xl border border-border bg-surface p-4 sm:p-6';
?>
<div class="flex max-w-3xl flex-col gap-4 pt-2 pb-8">
    <header>
        <h1 class="font-display text-heading-xl text-text">Minha conta</h1>
        <p class="text-caption text-muted"><?= e($email) ?></p>
    </header>

    <?php if (! $ativo && $codigos === null) { ?>
        <?= component('alert', ['variant' => 'warning', 'message' => 'O painel da plataforma exige a autenticação em dois fatores. Ative abaixo para continuar.']) ?>
    <?php } ?>
    <?php if (isset($erros['_geral'])) { ?>
        <?= component('alert', ['variant' => 'danger', 'message' => $erros['_geral']]) ?>
    <?php } ?>

    <?php if ($codigos !== null) { ?>
        <section class="<?= e($caixa) ?>" aria-labelledby="secao-codigos">
            <h2 id="secao-codigos" class="text-heading-sm text-text">Códigos de recuperação</h2>
            <p class="mt-1 text-caption text-muted">Guarde estes códigos em um lugar seguro. Cada um vale uma vez e substitui o aplicativo se você perder o celular. <strong class="font-medium text-text">Eles não aparecem de novo.</strong></p>
            <ul class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3" data-codigos>
                <?php foreach ($codigos as $codigo) { ?>
                    <li class="rounded-md border border-border bg-surface-subtle px-3 py-2 text-center font-mono text-sm text-text"><?= e($codigo) ?></li>
                <?php } ?>
            </ul>
            <div class="mt-4 flex justify-end">
                <?= component('button', ['label' => 'Concluir', 'href' => site_url('empresas'), 'icon' => 'check']) ?>
            </div>
        </section>
    <?php } elseif (! $ativo) { ?>
        <section class="<?= e($caixa) ?>" aria-labelledby="secao-ativar">
            <h2 id="secao-ativar" class="text-heading-sm text-text">Ativar a autenticação em dois fatores</h2>
            <ol class="mt-3 list-decimal space-y-1 pl-5 text-caption text-muted">
                <li>Instale um aplicativo autenticador (Google Authenticator, Microsoft Authenticator, Authy, 1Password...).</li>
                <li>Leia o QR code, ou digite a chave abaixo no aplicativo.</li>
                <li>Digite a sua senha e o código de 6 dígitos que o aplicativo mostrar.</li>
            </ol>
            <div class="mt-4 flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                <img src="<?= e($qr) ?>" alt="QR code para o aplicativo autenticador" width="200" height="200" class="size-[200px] rounded-md border border-border">
                <div class="min-w-0 flex-1">
                    <p class="text-label-md text-text">Chave para digitar</p>
                    <p class="mt-1 rounded-md border border-border bg-surface-subtle px-3 py-2 font-mono text-sm text-text" data-segredo><?php foreach (explode(' ', (string) $segredo) as $grupo) { ?><span class="inline-block whitespace-nowrap"><?= e($grupo) ?></span> <?php } ?></p>
                    <form method="post" action="<?= e(site_url('conta/ativar')) ?>" novalidate class="mt-4 flex flex-col gap-3" <?= js_module('formulario/padrao') ?>>
                        <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
                        <?= component('input', ['name' => 'senha', 'label' => 'Sua senha', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'error' => $erros['senha_ativar'] ?? null, 'attrs' => ['data-msg-vazio' => 'Informe a senha.']]) ?>
                        <?= component('input', ['name' => 'codigo', 'label' => 'Código do aplicativo', 'required' => true, 'autocomplete' => 'one-time-code', 'error' => $erros['codigo'] ?? null, 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 7, 'pattern' => '[0-9 ]{6,7}', 'data-msg-vazio' => 'Digite o código de 6 dígitos.']]) ?>
                        <div><?= component('button', ['label' => 'Ativar', 'type' => 'submit', 'icon' => 'shield-check', 'attrs' => ['data-rotulo-carregando' => 'Verificando…']]) ?></div>
                    </form>
                </div>
            </div>
        </section>
    <?php } else { ?>
        <section class="<?= e($caixa) ?>" aria-labelledby="secao-estado">
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="secao-estado" class="text-heading-sm text-text">Autenticação em dois fatores</h2>
                <?= component('pill-status', ['label' => 'Ativada', 'variant' => 'success']) ?>
            </div>
            <p class="mt-1 text-caption text-muted">Códigos de recuperação restantes: <strong class="font-medium text-text"><?= e((string) $restantes) ?></strong>. <?= $restantes <= 2 ? 'Gere novos códigos em breve.' : '' ?></p>
            <form method="post" action="<?= e(site_url('conta/codigos')) ?>" novalidate class="mt-4 flex flex-col gap-3 sm:max-w-sm" <?= js_module('formulario/padrao') ?>>
                <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
                <?= component('input', ['name' => 'senha', 'label' => 'Sua senha', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'error' => $erros['senha_codigos'] ?? null, 'help' => 'Gera 10 códigos novos; os anteriores deixam de valer.', 'attrs' => ['data-msg-vazio' => 'Informe a senha.']]) ?>
                <div><?= component('button', ['label' => 'Gerar novos códigos', 'type' => 'submit', 'variant' => 'outline', 'icon' => 'key-round']) ?></div>
            </form>
        </section>

        <section class="<?= e($caixa) ?>" aria-labelledby="secao-senha">
            <h2 id="secao-senha" class="text-heading-sm text-text">Trocar a senha</h2>
            <p class="mt-1 text-caption text-muted">As outras sessões da sua conta são encerradas.</p>
            <form method="post" action="<?= e(site_url('conta/senha')) ?>" novalidate class="mt-4 flex flex-col gap-3 sm:max-w-sm" <?= js_module('formulario/padrao') ?>>
                <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
                <?= component('input', ['name' => 'senha_atual', 'label' => 'Senha atual', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'error' => $erros['senha_atual'] ?? null, 'attrs' => ['data-msg-vazio' => 'Informe a senha atual.']]) ?>
                <?= component('input', ['name' => 'nova_senha', 'label' => 'Nova senha', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'help' => 'Pelo menos ' . Plataforma_contas::TAMANHO_MINIMO_SENHA . ' caracteres.', 'error' => $erros['nova_senha'] ?? null, 'attrs' => ['minlength' => Plataforma_contas::TAMANHO_MINIMO_SENHA, 'data-msg-vazio' => 'Informe a nova senha.']]) ?>
                <?= component('input', ['name' => 'confirmacao', 'label' => 'Repita a nova senha', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'error' => $erros['confirmacao'] ?? null, 'attrs' => ['data-msg-vazio' => 'Repita a nova senha.']]) ?>
                <div><?= component('button', ['label' => 'Trocar a senha', 'type' => 'submit', 'variant' => 'outline', 'icon' => 'key-round']) ?></div>
            </form>
        </section>
    <?php } ?>
</div>
