<?php
/**
 * Senha gerada (empresa criada ou senha redefinida), mostrada uma única vez:
 * não fica em sessão, log nem banco — só o hash. Recarregar a página não a
 * mostra de novo.
 *
 * @var string      $titulo
 * @var string      $mensagem
 * @var string|null $endereco  URL do tenant
 * @var string      $email
 * @var string      $senha
 * @var string      $voltar
 */
?>
<div class="flex max-w-2xl flex-col gap-4 pt-2 pb-8">
    <header>
        <h1 class="font-display text-heading-xl text-text"><?= e($titulo) ?></h1>
        <p class="text-caption text-muted"><?= e($mensagem) ?></p>
    </header>

    <?= component('alert', [
        'variant' => 'warning',
        'title' => 'Anote a senha agora',
        'message' => 'Ela aparece só nesta tela. Se perder, gere outra na página da empresa.',
    ]) ?>

    <section class="rounded-xl border border-border bg-surface p-4 sm:p-6" aria-labelledby="titulo-acesso">
        <h2 id="titulo-acesso" class="text-heading-sm text-text">Dados de acesso</h2>
        <dl class="mt-4 grid gap-4">
            <?php if ($endereco !== null) { ?>
                <div>
                    <dt class="text-label-md text-muted">Endereço</dt>
                    <dd class="mt-1"><?= component('link', ['label' => $endereco, 'href' => $endereco, 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) ?></dd>
                </div>
            <?php } ?>
            <div>
                <dt class="text-label-md text-muted">E-mail</dt>
                <dd class="mt-1 text-text"><?= e($email) ?></dd>
            </div>
            <div>
                <?= component('input', [
                    'name' => 'senha_gerada',
                    'id' => 'senha-gerada',
                    'label' => 'Senha',
                    'value' => $senha,
                    'readonly' => true,
                    'autocomplete' => 'off',
                    'class' => 'font-mono',
                    'help' => 'Selecione e copie. O administrador pode trocá-la depois em Minha conta.',
                    'attrs' => ['spellcheck' => 'false'],
                ]) ?>
            </div>
        </dl>
    </section>

    <div class="flex justify-end">
        <?= component('button', ['label' => 'Ver a empresa', 'variant' => 'outline', 'href' => $voltar]) ?>
    </div>
</div>
