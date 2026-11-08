<?php
/**
 * Uma empresa (tenant): status, domínios, usuários e a auditoria dela.
 *
 * Suspender é destrutivo para o cliente (os endereços param de abrir), então
 * passa por modal-confirm com button-danger; reativar é um POST direto. Gerar
 * nova senha também confirma antes: a senha atual do usuário deixa de valer.
 * Os modais são um por ação, preenchidos pelo botão da linha (data-valor-*),
 * como na listagem de clientes.
 *
 * @var array        $tenant
 * @var array|null   $plano     Plano atual da empresa
 * @var list<array>  $planos    Planos para escolher (ativos e o atual)
 * @var array|null   $consumo   usuarios, os_mes, armazenamento_mb
 * @var list<array>  $backups   Backups desta empresa
 * @var list<array>  $dominios
 * @var list<array>  $usuarios
 * @var string|null  $erroUsuarios
 * @var list<object> $auditoria
 * @var string       $porta  ":8090" no ambiente local
 */
$id = (int) $tenant['id'];
$csrf = '<input type="hidden" name="' . e($this->security->get_csrf_token_name()) . '" value="' . e($this->security->get_csrf_hash()) . '">';
$caixa = 'rounded-xl border border-border bg-surface p-4 sm:p-6';
$esquema = is_https() ? 'https' : 'http';
$ativo = $tenant['status'] === 'ativo';
$cancelado = $tenant['status'] === 'cancelado';
$vencimento = plataformaVencimentoPill($tenant['data_expiracao'] ?? null);

$opcoesPlano = ['' => 'Sem plano'];
foreach ($planos as $p) {
    $opcoesPlano[(string) $p['id']] = $p['nome'] . ' — ' . Catalogo_planos::precoLegivel($p) . ((int) $p['ativo'] === 1 ? '' : ' (desativado)');
}

$linhasConsumo = [];
foreach (['usuarios' => ['Usuários ativos', 'limite_usuarios', ''], 'os_mes' => ['OS abertas neste mês', 'limite_os_mes', ''], 'armazenamento_mb' => ['Arquivos enviados', 'limite_armazenamento_mb', ' MB']] as $chave => [$rotulo, $coluna, $sufixo]) {
    $limite = $plano[$coluna] ?? null;
    $usado = $consumo[$chave] ?? null;
    $linhasConsumo[] = [
        'rotulo' => $rotulo,
        'usado' => $usado === null ? '—' : number_format((float) $usado, $chave === 'armazenamento_mb' ? 1 : 0, ',', '.') . $sufixo,
        'limite' => $limite === null ? 'Sem limite' : number_format((int) $limite, 0, ',', '.') . $sufixo,
        'acima' => $limite !== null && $usado !== null && (float) $usado > (int) $limite,
    ];
}
$colunasConsumo = [
    ['label' => 'Item', 'render' => fn ($c) => $c['rotulo']],
    ['label' => 'Em uso', 'nowrap' => true, 'render' => fn ($c) => $c['usado']],
    ['label' => 'Limite do plano', 'nowrap' => true, 'render' => fn ($c) => $c['limite']],
    ['label' => 'Situação', 'nowrap' => true, 'render' => fn ($c) => $c['limite'] === 'Sem limite' ? '' : component('pill-status', $c['acima'] ? ['label' => 'Acima do limite', 'variant' => 'danger'] : ['label' => 'Dentro', 'variant' => 'success'])],
];

$colunasBackups = [
    ['label' => 'Gerado em', 'nowrap' => true, 'render' => fn ($b) => date('d/m/Y H:i', $b['criado_em'])],
    ['label' => 'Tamanho', 'nowrap' => true, 'render' => fn ($b) => plataformaTamanho($b['tamanho'])],
    ['label' => 'Ações', 'align' => 'right', 'nowrap' => true, 'render' => fn ($b) => component('button', ['label' => 'Baixar ' . $b['arquivo'], 'icon' => 'download', 'icon_only' => true, 'variant' => 'ghost', 'size' => 'sm', 'href' => site_url('backups/' . $b['arquivo'])])],
];

$colunasDominios = [
    ['label' => 'Domínio', 'render' => fn ($d) => $d['verificado_em'] && $ativo
        ? component('link', ['label' => $d['dominio'], 'href' => $esquema . '://' . $d['dominio'] . $porta . '/', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']])
        : $d['dominio']],
    ['label' => 'Tipo', 'nowrap' => true, 'render' => fn ($d) => $d['tipo'] === 'plataforma' ? 'Plataforma' : 'Próprio'],
    ['label' => 'Situação', 'nowrap' => true, 'render' => fn ($d) => component('pill-status', $d['verificado_em'] ? ['label' => 'Verificado', 'variant' => 'success'] : ['label' => 'Aguardando DNS', 'variant' => 'warning'])],
    ['label' => 'Verificação', 'render' => function ($d) use ($id, $csrf) {
        if ($d['verificado_em']) {
            return '';
        }
        $txt = Tenant_manager::registroVerificacao($d['dominio'], (string) $d['verificacao_token']);

        return [
            new HtmlSeguro('<span class="block text-caption text-muted">TXT <code class="break-all text-text">' . e($txt['nome']) . '</code></span>'
                . '<span class="block text-caption text-muted">valor <code class="break-all text-text">' . e($txt['valor']) . '</code></span>'
                . '<form method="post" action="' . e(site_url('empresas/' . $id . '/verificar')) . '" class="mt-2">' . $csrf
                . '<input type="hidden" name="dominio" value="' . e($d['dominio']) . '">'),
            component('button', ['label' => 'Verificar', 'icon' => 'check', 'variant' => 'outline', 'size' => 'sm', 'type' => 'submit']),
            new HtmlSeguro('</form>'),
        ];
    }],
];

$colunasUsuarios = [
    ['label' => 'Nome', 'render' => fn ($u) => $u['nome']],
    ['label' => 'E-mail', 'render' => fn ($u) => $u['email']],
    ['label' => 'Perfil', 'hide_until' => 'md', 'render' => fn ($u) => $u['perfil'] ?? '—'],
    ['label' => 'Situação', 'nowrap' => true, 'render' => fn ($u) => component('pill-status', $u['situacao'] === 1 ? ['label' => 'Ativo', 'variant' => 'success'] : ['label' => 'Inativo', 'variant' => 'neutral'])],
    ['label' => 'Ações', 'align' => 'right', 'nowrap' => true, 'render' => fn ($u) => component('button', [
        'label' => 'Gerar nova senha para ' . $u['nome'],
        'icon' => 'key-round',
        'icon_only' => true,
        'variant' => 'ghost',
        'size' => 'sm',
        'attrs' => ['data-modal-abrir' => 'gerar-senha', 'data-valor-id' => (string) $u['idUsuarios'], 'data-valor-nome' => $u['nome']],
    ])],
];

$colunasAuditoria = [
    ['label' => 'Quando', 'nowrap' => true, 'render' => fn ($a) => date('d/m/Y H:i', strtotime((string) $a->criado_em))],
    ['label' => 'Quem', 'render' => fn ($a) => $a->usuario_nome],
    ['label' => 'O quê', 'render' => fn ($a) => plataformaAcaoLegivel((string) $a->acao) . ($a->detalhes ? ': ' . $a->detalhes : '')],
];
?>
<div class="flex flex-col gap-4 pt-2 pb-8">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="font-display text-heading-xl text-text"><?= e($tenant['nome']) ?></h1>
                <?= component('pill-status', plataformaStatusPill((string) $tenant['status'])) ?>
            </div>
            <p class="text-caption text-muted">
                <?= e('Cód. ' . $id . ' · criada em ' . date('d/m/Y', strtotime((string) $tenant['criado_em'])) . ($tenant['documento'] ? ' · ' . $tenant['documento'] : '')) ?>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <?= component('button', ['label' => 'Voltar', 'variant' => 'ghost', 'href' => site_url('empresas')]) ?>
            <?php if ($ativo) { ?>
                <?= component('button', ['label' => 'Suspender', 'icon' => 'shield-off', 'variant' => 'outline', 'attrs' => ['data-modal-abrir' => 'suspender-empresa']]) ?>
            <?php } else { ?>
                <form method="post" action="<?= e(site_url('empresas/' . $id . '/status')) ?>">
                    <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
                    <input type="hidden" name="status" value="ativo">
                    <?= component('button', ['label' => 'Reativar', 'icon' => 'shield-check', 'variant' => 'outline', 'type' => 'submit']) ?>
                </form>
            <?php } ?>
            <?php if ($cancelado) { ?>
                <?= component('button', ['label' => 'Excluir definitivamente', 'icon' => 'trash-2', 'variant' => 'outline', 'href' => site_url('empresas/' . $id . '/excluir')]) ?>
            <?php } else { ?>
                <?= component('button', ['label' => 'Cancelar empresa', 'icon' => 'archive', 'variant' => 'ghost', 'attrs' => ['data-modal-abrir' => 'cancelar-empresa']]) ?>
            <?php } ?>
        </div>
    </header>

    <?php if ($cancelado) { ?>
        <?= component('alert', ['variant' => 'warning', 'message' => 'Empresa cancelada' . (! empty($tenant['cancelado_em']) ? ' em ' . date('d/m/Y', strtotime((string) $tenant['cancelado_em'])) : '') . ': os endereços dela não abrem. Os dados continuam guardados até a exclusão definitiva, que gera um backup final antes de apagar.']) ?>
    <?php } elseif (! $ativo) { ?>
        <?= component('alert', ['variant' => 'warning', 'message' => 'Empresa sem acesso: os endereços dela respondem "Endereço não encontrado". Os dados continuam guardados.']) ?>
    <?php } ?>

    <section class="<?= e($caixa) ?>" aria-labelledby="secao-plano">
        <div class="flex flex-wrap items-center gap-2">
            <h2 id="secao-plano" class="text-heading-sm text-text">Plano</h2>
            <?php if ($plano !== null) { ?>
                <?= component('pill-status', ['label' => $plano['nome'], 'variant' => (int) $plano['cortesia'] === 1 ? 'info' : 'neutral']) ?>
            <?php } ?>
            <?php if ($vencimento !== null) { ?>
                <?= component('pill-status', $vencimento) ?>
            <?php } ?>
        </div>
        <p class="mt-1 text-caption text-muted">
            <?= e($plano !== null ? Catalogo_planos::precoLegivel($plano) . ' · ' . Catalogo_planos::resumoLimites($plano) : 'Sem plano definido.') ?>
            A cobrança e o bloqueio automático no vencimento entram com a integração de pagamento.
        </p>
        <form method="post" action="<?= e(site_url('empresas/' . $id . '/plano')) ?>" novalidate class="mt-4 grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto]" <?= js_module('formulario/padrao') ?>>
            <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
            <?= component('select', ['name' => 'plano_id', 'label' => 'Plano', 'options' => $opcoesPlano, 'selected' => (string) ($tenant['plano_id'] ?? '')]) ?>
            <?= component('input', ['name' => 'vencimento', 'label' => 'Vencimento', 'type' => 'date', 'value' => $tenant['data_expiracao'] ?? null, 'help' => 'Vazio: sem vencimento.']) ?>
            <?= component('button', ['label' => 'Salvar plano', 'icon' => 'save', 'variant' => 'outline', 'type' => 'submit']) ?>
        </form>
        <div class="mt-4">
            <?php if ($consumo === null) { ?>
                <?= component('alert', ['variant' => 'danger', 'message' => 'Não foi possível ler o consumo desta empresa.']) ?>
            <?php } else { ?>
                <?= component('data-table', ['columns' => $colunasConsumo, 'rows' => $linhasConsumo, 'caption' => 'Consumo da empresa', 'dense' => true]) ?>
            <?php } ?>
        </div>
    </section>

    <section class="<?= e($caixa) ?>" aria-labelledby="secao-dominios">
        <h2 id="secao-dominios" class="text-heading-sm text-text">Domínios</h2>
        <p class="mt-1 text-caption text-muted">Um domínio próprio só abre o sistema depois que o cliente publica o registro TXT no DNS e você clica em Verificar.</p>
        <div class="mt-4">
            <?= component('data-table', ['columns' => $colunasDominios, 'rows' => $dominios, 'caption' => 'Domínios da empresa', 'dense' => true]) ?>
        </div>
        <form method="post" action="<?= e(site_url('empresas/' . $id . '/dominio')) ?>" novalidate class="mt-4 grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto]" <?= js_module('formulario/padrao') ?>>
            <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
            <?= component('input', ['name' => 'dominio', 'label' => 'Novo domínio próprio', 'placeholder' => 'www.empresa.com.br', 'required' => true, 'attrs' => ['maxlength' => 255, 'autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
            <?= component('button', ['label' => 'Adicionar domínio', 'icon' => 'plus', 'variant' => 'outline', 'type' => 'submit']) ?>
        </form>
    </section>

    <section class="<?= e($caixa) ?>" aria-labelledby="secao-usuarios">
        <h2 id="secao-usuarios" class="text-heading-sm text-text">Usuários da empresa</h2>
        <div class="mt-4">
            <?php if ($erroUsuarios !== null) { ?>
                <?= component('alert', ['variant' => 'danger', 'message' => $erroUsuarios]) ?>
            <?php } else { ?>
                <?= component('data-table', ['columns' => $colunasUsuarios, 'rows' => $usuarios, 'caption' => 'Usuários da empresa', 'dense' => true, 'empty' => 'Nenhum usuário cadastrado.']) ?>
            <?php } ?>
        </div>
    </section>

    <section class="<?= e($caixa) ?>" aria-labelledby="secao-backups">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="secao-backups" class="text-heading-sm text-text">Backups</h2>
                <p class="mt-1 text-caption text-muted">Banco, arquivos enviados, logo e fotos de perfil num .zip, guardado fora do site.</p>
            </div>
            <form method="post" action="<?= e(site_url('empresas/' . $id . '/backup')) ?>" <?= js_module('formulario/padrao') ?>>
                <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
                <?= component('button', ['label' => 'Gerar backup', 'icon' => 'archive', 'variant' => 'outline', 'type' => 'submit', 'attrs' => ['data-rotulo-carregando' => 'Gerando…']]) ?>
            </form>
        </div>
        <div class="mt-4">
            <?= component('data-table', ['columns' => $colunasBackups, 'rows' => $backups, 'caption' => 'Backups da empresa', 'dense' => true, 'empty' => 'Nenhum backup ainda.']) ?>
        </div>
    </section>

    <section class="<?= e($caixa) ?>" aria-labelledby="secao-auditoria">
        <h2 id="secao-auditoria" class="text-heading-sm text-text">Histórico no painel</h2>
        <div class="mt-4">
            <?= component('data-table', ['columns' => $colunasAuditoria, 'rows' => $auditoria, 'caption' => 'Histórico da empresa no painel', 'dense' => true, 'empty' => 'Nada registrado ainda.']) ?>
        </div>
    </section>
</div>

<?php if ($ativo) { ?>
    <form id="form-suspender-empresa" method="post" action="<?= e(site_url('empresas/' . $id . '/status')) ?>" hidden>
        <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
        <input type="hidden" name="status" value="suspenso">
    </form>
    <?= component('modal-confirm', [
        'id' => 'suspender-empresa',
        'title' => 'Suspender empresa?',
        'message' => [
            new HtmlSeguro('<strong class="font-semibold text-text">' . e($tenant['nome']) . '</strong>'),
            ' deixa de acessar o sistema em todos os endereços. Os dados ficam guardados e você pode reativar depois.',
        ],
        'confirm_label' => 'Suspender',
        'icon' => 'shield-off',
        'confirm_attrs' => ['form' => 'form-suspender-empresa'],
    ]) ?>
<?php } ?>

<?php if (! $cancelado) { ?>
    <form id="form-cancelar-empresa" method="post" action="<?= e(site_url('empresas/' . $id . '/status')) ?>" hidden>
        <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
        <input type="hidden" name="status" value="cancelado">
    </form>
    <?= component('modal-confirm', [
        'id' => 'cancelar-empresa',
        'title' => 'Cancelar empresa?',
        'message' => [
            new HtmlSeguro('<strong class="font-semibold text-text">' . e($tenant['nome']) . '</strong>'),
            ' deixa de acessar o sistema. Os dados ficam guardados; depois de cancelada, a empresa pode ser reativada ou excluída definitivamente.',
        ],
        'confirm_label' => 'Cancelar empresa',
        'cancel_label' => 'Voltar',
        'icon' => 'archive',
        'confirm_attrs' => ['form' => 'form-cancelar-empresa'],
    ]) ?>
<?php } ?>

<form id="form-gerar-senha" method="post" action="<?= e(site_url('empresas/' . $id . '/senha')) ?>" hidden>
    <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
    <input type="hidden" name="usuario" value="" data-modal-de="gerar-senha" data-modal-valor="id">
</form>
<?= component('modal-confirm', [
    'id' => 'gerar-senha',
    'title' => 'Gerar nova senha?',
    'message' => [
        'A senha atual de ',
        new HtmlSeguro('<strong class="font-semibold text-text" data-modal-valor="nome"></strong>'),
        ' deixa de valer e as sessões abertas por ele são encerradas. A nova senha aparece uma vez, na próxima tela.',
    ],
    'confirm_label' => 'Gerar senha',
    'icon' => 'key-round',
    'confirm_attrs' => ['form' => 'form-gerar-senha'],
]) ?>
