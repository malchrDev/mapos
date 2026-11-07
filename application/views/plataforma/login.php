<?php
/**
 * Login do painel administrativo da plataforma: superfície de entrada do
 * DESIGN.md (sempre escura), como views/mapos/login.php, sem ação secundária —
 * no hostname do painel não existe área do cliente.
 *
 * O envio é do mesmo módulo do login do tenant (login/formulario.js), que
 * valida no navegador e posta em login/entrar (JSON {result, message}).
 *
 * @var string|null $erro  Mensagem de um redirect (flashdata)
 */
$saudacao = layoutSaudacao((int) date('H'));

$entrada = ['titulo' => 'Painel da plataforma'];
include APPPATH . 'views/entrada/inicio.php';
?>
    <main class="mx-auto grid w-full max-w-6xl flex-1 items-center gap-8 px-4 py-6 lg:grid-cols-[1fr_26rem] lg:gap-16 lg:px-8 lg:py-12">
        <section class="relative flex flex-col items-start gap-4">
            <span class="rounded-xs bg-night px-2 py-1 text-caption text-on-dark">Administração da plataforma</span>
            <h1 class="font-display text-display-hero-sm font-bold text-on-dark sm:text-display-large xl:text-display-hero">
                Todas as empresas em <span class="rounded-xs bg-accent-lime px-3 text-ink">ordem</span>.
            </h1>
            <p class="max-w-xl text-body-lg text-on-dark-muted max-sm:hidden">Crie empresas, acompanhe o status de cada uma e cuide dos domínios e acessos.</p>
        </section>

        <section class="superficie-entrada w-full rounded-xxl border border-hairline-violet bg-night p-6 text-text sm:p-8" aria-labelledby="titulo-login">
            <h2 id="titulo-login" class="mb-6 font-display text-heading-md text-text"><?= e($saudacao . '! Entre no painel') ?></h2>

            <form id="formLogin" method="post" action="<?= e(site_url('login/entrar')) ?>" novalidate class="flex flex-col gap-4" <?= js_module('login/formulario') ?> data-destino="<?= e(site_url('empresas')) ?>">
                <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">

                <div data-login-mensagem<?= empty($erro) ? ' hidden' : '' ?>>
                    <?= component('alert', ['message' => new HtmlSeguro('<span data-login-texto>' . e($erro ?? '') . '</span>'), 'variant' => 'danger']) ?>
                </div>

                <?= component('input', [
                    'name' => 'email',
                    'id' => 'email',
                    'label' => 'E-mail',
                    'type' => 'email',
                    'required' => true,
                    'autocomplete' => 'username',
                    'placeholder' => 'voce@empresa.com.br',
                    'attrs' => ['autofocus' => true, 'inputmode' => 'email', 'data-msg-vazio' => 'Informe o e-mail.', 'data-msg-invalido' => 'Informe um e-mail válido.'],
                ]) ?>

                <?= component('input', [
                    'name' => 'senha',
                    'id' => 'senha',
                    'label' => 'Senha',
                    'type' => 'password',
                    'required' => true,
                    'autocomplete' => 'current-password',
                    'attrs' => ['data-msg-vazio' => 'Informe a senha.'],
                ]) ?>

                <?= component('button', [
                    'label' => 'Entrar',
                    'type' => 'submit',
                    'size' => 'lg',
                    'icon' => 'log-in',
                    'id' => 'btn-acessar',
                    'class' => 'mt-2 w-full shadow-elev-3',
                    'attrs' => ['data-rotulo-carregando' => 'Entrando…'],
                ]) ?>
            </form>
        </section>
    </main>
<?php include APPPATH . 'views/entrada/fim.php'; ?>
