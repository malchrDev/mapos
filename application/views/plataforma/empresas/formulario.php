<?php
/**
 * Nova empresa (tenant), no padrão dos formulários da v5
 * (views/clientes/formulario.php): POST comum com CSRF, validação no navegador
 * pelo módulo formulario/padrao (que também põe o botão em carregamento — o
 * provisionamento cria o banco e roda as migrations, e leva alguns segundos) e
 * de novo no servidor, com o erro em cada campo.
 *
 * @var array<string, string> $valores
 * @var array<string, string> $erros   Por campo; _geral para falha ao criar
 * @var string $dominioBase            SAAS_DOMINIO_BASE ('' = domínio completo)
 * @var list<array> $planos             Planos ativos
 */
$erroGeral = $erros['_geral'] ?? null;
$errosDeCampo = array_diff_key($erros, ['_geral' => true]);

$campo = static fn (string $nome, array $props) => $props + [
    'name' => $nome,
    'value' => ($valores[$nome] ?? '') !== '' ? $valores[$nome] : null,
    'error' => $errosDeCampo[$nome] ?? null,
];

$caixa = 'rounded-xl border border-border bg-surface p-4 sm:p-6';
$grade = 'mt-4 grid gap-4 sm:grid-cols-2';
?>
<div class="flex max-w-4xl flex-col gap-4 pt-2 pb-8">
    <header>
        <h1 class="font-display text-heading-xl text-text">Nova empresa</h1>
        <p class="text-caption text-muted">Cria o banco da empresa, as tabelas e o usuário administrador. A senha inicial aparece uma única vez, na próxima tela.</p>
    </header>

    <?php if ($erroGeral !== null) { ?>
        <?= component('alert', ['variant' => 'danger', 'message' => $erroGeral]) ?>
    <?php } elseif ($errosDeCampo !== []) { ?>
        <?= component('alert', ['variant' => 'danger', 'message' => count($errosDeCampo) === 1 ? 'Confira o campo destacado.' : 'Confira os ' . count($errosDeCampo) . ' campos destacados.']) ?>
    <?php } ?>

    <form method="post" action="<?= e(site_url('empresas/nova')) ?>" novalidate class="flex flex-col gap-4" <?= js_module('formulario/padrao') ?>>
        <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">

        <section class="<?= e($caixa) ?>" aria-labelledby="secao-empresa">
            <h2 id="secao-empresa" class="text-heading-sm text-text">Empresa</h2>
            <div class="<?= e($grade) ?>">
                <?= component('input', $campo('nome', ['label' => 'Nome da empresa', 'required' => true, 'attrs' => ['maxlength' => 255]])) ?>
                <?= component('input', $campo('documento', ['label' => 'CPF/CNPJ', 'help' => 'Opcional.', 'attrs' => ['maxlength' => 20]])) ?>
                <?= component('input', $campo('subdominio', [
                    'label' => 'Endereço',
                    'required' => true,
                    'placeholder' => 'minhaloja',
                    'help' => $dominioBase !== ''
                        ? 'A empresa vai acessar em <endereço>.' . $dominioBase . '. Letras minúsculas, números e hífen.'
                        : 'Domínio completo da empresa.',
                    'attrs' => ['maxlength' => 40, 'pattern' => '[a-z0-9]([a-z0-9\-]*[a-z0-9])?', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'data-msg-invalido' => 'Use letras minúsculas, números e hífen (sem começar ou terminar com hífen).'],
                ])) ?>
            </div>
        </section>

        <section class="<?= e($caixa) ?>" aria-labelledby="secao-plano">
            <h2 id="secao-plano" class="text-heading-sm text-text">Plano</h2>
            <p class="mt-1 text-caption text-muted">Pode ficar sem plano e ser definido depois, na tela da empresa.</p>
            <div class="<?= e($grade) ?>">
                <?php
                $opcoesPlano = ['' => 'Sem plano'];
                foreach ($planos as $p) {
                    $opcoesPlano[(string) $p['id']] = $p['nome'] . ' — ' . Catalogo_planos::precoLegivel($p);
                }
                ?>
                <?= component('select', ['name' => 'plano_id', 'label' => 'Plano', 'options' => $opcoesPlano, 'selected' => (string) ($valores['plano_id'] ?? ''), 'error' => $errosDeCampo['plano_id'] ?? null]) ?>
            </div>
        </section>

        <section class="<?= e($caixa) ?>" aria-labelledby="secao-admin">
            <h2 id="secao-admin" class="text-heading-sm text-text">Administrador</h2>
            <p class="mt-1 text-caption text-muted">Primeiro usuário da empresa, com todas as permissões. É o e-mail do pedido de inscrição.</p>
            <div class="<?= e($grade) ?>">
                <?= component('input', $campo('admin_email', ['label' => 'E-mail', 'type' => 'email', 'required' => true, 'autocomplete' => 'off', 'attrs' => ['maxlength' => 80, 'inputmode' => 'email']])) ?>
                <?= component('input', $campo('admin_nome', ['label' => 'Nome', 'help' => 'Opcional. Sem nome, fica "Administrador".', 'attrs' => ['maxlength' => 80]])) ?>
            </div>
        </section>

        <div class="flex flex-wrap justify-end gap-2">
            <?= component('button', ['label' => 'Cancelar', 'variant' => 'ghost', 'href' => site_url('empresas')]) ?>
            <?= component('button', ['label' => 'Criar empresa', 'icon' => 'plus', 'type' => 'submit', 'attrs' => ['data-rotulo-carregando' => 'Criando…']]) ?>
        </div>
    </form>
</div>
