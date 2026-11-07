<?php
/**
 * Contas do painel da plataforma. A própria conta aparece marcada e sem
 * ações (ficam em Minha conta). Nova senha, redefinir 2FA e desativar passam
 * por modal-confirm; os dois primeiros pedem a senha de quem faz, num campo
 * do próprio modal. Cada modal é preenchido pelo botão da linha (data-valor-*).
 *
 * @var list<array> $contas
 * @var int         $eu
 */
$token = '<input type="hidden" name="' . e($this->security->get_csrf_token_name()) . '" value="' . e($this->security->get_csrf_hash()) . '">';

$botao = static fn (string $modal, string $rotulo, string $icone, array $c) => component('button', [
    'label' => $rotulo . ' ' . $c['nome'],
    'icon' => $icone,
    'icon_only' => true,
    'variant' => 'ghost',
    'size' => 'sm',
    'attrs' => ['data-modal-abrir' => $modal, 'data-valor-id' => (string) $c['id'], 'data-valor-nome' => $c['nome']],
]);

$colunas = [
    ['label' => 'Nome', 'render' => fn ($c) => $c['id'] === $eu ? [$c['nome'], ' ', component('pill-status', ['label' => 'Você', 'variant' => 'info'])] : $c['nome']],
    ['label' => 'E-mail', 'render' => fn ($c) => $c['email']],
    ['label' => '2FA', 'nowrap' => true, 'render' => fn ($c) => component('pill-status', $c['dois_fatores'] ? ['label' => 'Ativado', 'variant' => 'success'] : ['label' => 'Pendente', 'variant' => 'warning'])],
    ['label' => 'Situação', 'nowrap' => true, 'render' => fn ($c) => component('pill-status', $c['ativo'] ? ['label' => 'Ativa', 'variant' => 'success'] : ['label' => 'Desativada', 'variant' => 'neutral'])],
    ['label' => 'Último acesso', 'nowrap' => true, 'hide_until' => 'md', 'render' => fn ($c) => $c['ultimo_login_em'] ? date('d/m/Y H:i', strtotime((string) $c['ultimo_login_em'])) : 'Nunca'],
    ['label' => 'Ações', 'align' => 'right', 'nowrap' => true, 'render' => function ($c) use ($eu, $botao, $token) {
        if ($c['id'] === $eu) {
            return component('button', ['label' => 'Minha conta', 'icon' => 'circle-user', 'icon_only' => true, 'variant' => 'ghost', 'size' => 'sm', 'href' => site_url('conta')]);
        }

        $acoes = [$botao('senha-conta', 'Gerar nova senha para', 'key-round', $c)];
        if ($c['dois_fatores']) {
            $acoes[] = $botao('redefinir-2fa', 'Redefinir o 2FA de', 'fingerprint', $c);
        }
        if ($c['ativo']) {
            $acoes[] = $botao('desativar-conta', 'Desativar', 'shield-off', $c);
        } else {
            $acoes[] = new HtmlSeguro('<form method="post" action="' . e(site_url('contas/status')) . '" class="inline">' . $token . '<input type="hidden" name="id" value="' . e((string) $c['id']) . '"><input type="hidden" name="ativo" value="1">');
            $acoes[] = component('button', ['label' => 'Reativar ' . $c['nome'], 'icon' => 'shield-check', 'icon_only' => true, 'variant' => 'ghost', 'size' => 'sm', 'type' => 'submit']);
            $acoes[] = new HtmlSeguro('</form>');
        }

        return $acoes;
    }],
];

$campoSenha = static fn (string $modal) => component('input', ['name' => 'senha', 'id' => 'senha-' . $modal, 'label' => 'Sua senha', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'attrs' => ['form' => 'form-' . $modal]]);
?>
<div class="flex flex-col gap-4 pt-2 pb-8">
    <header>
        <h1 class="font-display text-heading-xl text-text">Contas do painel</h1>
        <p class="text-caption text-muted">Quem administra a plataforma. Todas as contas têm o mesmo acesso, e o 2FA é obrigatório.</p>
    </header>

    <?= component('data-table', ['columns' => $colunas, 'rows' => $contas, 'caption' => 'Contas do painel', 'empty' => 'Nenhuma conta.']) ?>
</div>

<?php foreach (['senha-conta' => 'senha', 'redefinir-2fa' => 'redefinir2fa', 'desativar-conta' => 'status'] as $modal => $acao) { ?>
    <form id="form-<?= e($modal) ?>" method="post" action="<?= e(site_url('contas/' . $acao)) ?>" hidden>
        <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
        <input type="hidden" name="id" value="" data-modal-de="<?= e($modal) ?>" data-modal-valor="id">
        <?php if ($acao === 'status') { ?>
            <input type="hidden" name="ativo" value="0">
        <?php } ?>
    </form>
<?php } ?>

<?= component('modal-confirm', [
    'id' => 'senha-conta',
    'title' => 'Gerar nova senha?',
    'message' => [
        'A senha atual de ',
        new HtmlSeguro('<strong class="font-semibold text-text" data-modal-valor="nome"></strong>'),
        ' deixa de valer e as sessões abertas são encerradas. A nova senha aparece uma vez, na próxima tela.',
        $campoSenha('senha-conta'),
    ],
    'confirm_label' => 'Gerar senha',
    'icon' => 'key-round',
    'confirm_attrs' => ['form' => 'form-senha-conta'],
]) ?>

<?= component('modal-confirm', [
    'id' => 'redefinir-2fa',
    'title' => 'Redefinir o 2FA?',
    'message' => [
        'O aplicativo autenticador e os códigos de recuperação de ',
        new HtmlSeguro('<strong class="font-semibold text-text" data-modal-valor="nome"></strong>'),
        ' deixam de valer. No próximo login, ele cadastra o aplicativo de novo. Confirme antes quem está pedindo.',
        $campoSenha('redefinir-2fa'),
    ],
    'confirm_label' => 'Redefinir 2FA',
    'icon' => 'fingerprint',
    'confirm_attrs' => ['form' => 'form-redefinir-2fa'],
]) ?>

<?= component('modal-confirm', [
    'id' => 'desativar-conta',
    'title' => 'Desativar conta?',
    'message' => [
        new HtmlSeguro('<strong class="font-semibold text-text" data-modal-valor="nome"></strong>'),
        ' não entra mais no painel, e as sessões abertas são encerradas. Dá para reativar depois.',
    ],
    'confirm_label' => 'Desativar',
    'icon' => 'shield-off',
    'confirm_attrs' => ['form' => 'form-desativar-conta'],
]) ?>
