<?php
/**
 * Layout do painel administrativo da plataforma.
 *
 * Mesma moldura do painel do tenant (DESIGN.md `sidebar` e `topbar`, classes
 * de views/tema/ e assets/dist/layout.css), com o menu da plataforma e sem o
 * que só existe no tenant (busca global, área do cliente, perfil). O modo de
 * cor segue o sistema operacional; o tema.js aplica a preferência guardada no
 * navegador, se houver.
 *
 * A tela fica em .v5-conteudo, fora de .v5-shell (o CSS da moldura só vale
 * dentro do shell).
 *
 * @var string $view
 * @var array{titulo: string, menu: string, acao: ?array, flash: list<array>, usuario: string, iniciais: string, saudacao: string} $plataforma
 */
$foco = 'focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring';
$ativo = 'aria-[current=page]:bg-primary-tint aria-[current=page]:font-semibold aria-[current=page]:text-text'
    . ' aria-[current=page]:before:absolute aria-[current=page]:before:inset-y-2 aria-[current=page]:before:-left-3 aria-[current=page]:before:w-[3px] aria-[current=page]:before:rounded-r-[3px] aria-[current=page]:before:bg-primary';
$classeLink = 'relative flex h-10 items-center gap-3 rounded-md px-2.5 text-[0.9375rem] font-medium text-muted transition-colors hover:bg-surface-subtle hover:text-text ' . $foco . ' ' . $ativo;

$menu = [
    'empresas' => ['label' => 'Empresas', 'icon' => 'house', 'url' => 'empresas'],
    'auditoria' => ['label' => 'Auditoria', 'icon' => 'history', 'url' => 'auditoria'],
];
$assets = layoutAssets();
$versao = (string) config_item('app_version');
?><!DOCTYPE html>
<html lang="pt-br"<?= temaAtributosHtml(['app_tema_modo' => 'sistema']) ?>>

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($plataforma['titulo'] . ' — Painel da plataforma') ?></title>
  <meta name="csrf-token-name" content="<?= e(config_item('csrf_token_name')) ?>">
  <meta name="csrf-cookie-name" content="<?= e(config_item('csrf_cookie_name')) ?>">
  <meta name="base-url" content="<?= e(base_url()) ?>">
  <?php if (function_exists('segurancaNonceAtual') && segurancaNonceAtual() !== '') { ?>
  <meta name="csp-nonce" content="<?= e(segurancaNonceAtual()) ?>">
  <?php } ?>
  <link rel="shortcut icon" type="image/png" href="<?= e(base_url('assets/img/favicon.png')) ?>">
  <script src="<?= e(assetUrl('assets/js/tema.js')) ?>"></script>
  <?php foreach ($assets['css'] as $arquivo) { ?>
  <link rel="stylesheet" href="<?= e(layoutUrlAsset($arquivo, base_url(), $versao)) ?>">
  <?php } ?>
  <?php foreach ($assets['modulos'] as $arquivo) { ?>
  <script type="module" src="<?= e(layoutUrlAsset($arquivo, base_url(), $versao)) ?>"></script>
  <?php } ?>
</head>

<body class="v5-layout" <?= js_module('layout/shell') ?>>
  <div class="v5-shell">
    <a href="#conteudo-principal" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[60] focus:rounded-control focus:bg-surface focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-text focus:shadow-overlay">Pular para o conteúdo</a>

    <div class="v5-gaveta-fundo fixed inset-0 z-40 bg-backdrop lg:hidden" data-gaveta-fechar aria-hidden="true"></div>

    <aside id="v5-sidebar" class="v5-sidebar fixed inset-y-0 left-0 z-50 flex flex-col border-r border-border bg-surface" data-sidebar aria-label="Menu principal">
      <div class="v5-sidebar-topo flex h-topbar shrink-0 items-center gap-3 border-b border-border px-4">
        <a href="<?= e(site_url('empresas')) ?>" class="flex min-w-0 items-center gap-2.5 rounded-md <?= e($foco) ?>" title="Empresas">
          <span class="inline-flex size-[34px] shrink-0 items-center justify-center rounded-md bg-primary text-on-primary"><?= icon('wrench', ['class' => 'size-5']) ?></span>
          <span class="v5-rotulo flex min-w-0 items-center gap-2">
            <span class="truncate font-display text-[1.3125rem] leading-none font-bold tracking-[-0.2px] text-text">Plataforma</span>
          </span>
        </a>
        <?= component('button', ['label' => 'Fechar menu', 'icon' => 'x', 'icon_only' => true, 'variant' => 'ghost', 'size' => 'sm', 'class' => 'ml-auto lg:hidden', 'attrs' => ['data-gaveta-fechar' => true]]) ?>
      </div>

      <nav class="flex-1 overflow-y-auto overscroll-contain px-3 py-4" aria-label="Navegação">
        <ul class="flex flex-col gap-0.5">
          <?php foreach ($menu as $chave => $item) { ?>
            <li>
              <a href="<?= e(site_url($item['url'])) ?>" class="<?= e($classeLink) ?>" title="<?= e($item['label']) ?>"<?= $plataforma['menu'] === $chave ? ' aria-current="page"' : '' ?>>
                <?= icon($item['icon'], ['class' => 'size-5']) ?>
                <span class="v5-rotulo truncate"><?= e($item['label']) ?></span>
              </a>
            </li>
          <?php } ?>
        </ul>
      </nav>

      <div class="shrink-0 border-t border-border p-3">
        <div class="v5-sidebar-usuario flex items-center gap-2.5 rounded-md p-1">
          <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-accent-violet-mid text-sm font-semibold text-on-dark" aria-hidden="true"><?= e($plataforma['iniciais']) ?></span>
          <span class="v5-rotulo min-w-0 flex-1 leading-tight">
            <span class="block truncate text-sm font-semibold text-text"><?= e($plataforma['usuario']) ?></span>
            <span class="text-caption text-muted">Plataforma</span>
          </span>
          <a href="<?= e(site_url('sair')) ?>" class="v5-rotulo inline-flex size-9 shrink-0 items-center justify-center rounded-md text-muted hover:bg-surface-subtle hover:text-text <?= e($foco) ?>" title="Sair">
            <?= icon('log-out', ['class' => 'size-5']) ?>
            <span class="sr-only">Sair</span>
          </a>
        </div>
      </div>
    </aside>
  </div>

  <div class="v5-main">
    <div class="v5-shell">
      <header class="sticky top-0 z-30 flex h-topbar items-center gap-2 border-b border-border bg-surface px-3 sm:gap-3 lg:px-6">
        <?= component('button', [
            'label' => 'Menu',
            'icon' => 'menu',
            'icon_only' => true,
            'variant' => 'ghost',
            'attrs' => ['data-sidebar-alternar' => true, 'aria-controls' => 'v5-sidebar', 'aria-expanded' => 'true'],
        ]) ?>

        <div class="ml-auto flex items-center gap-2 sm:gap-3">
          <?php if ($plataforma['acao'] !== null) { ?>
            <?= component('button', $plataforma['acao'] + ['class' => 'max-sm:hidden']) ?>
            <?= component('button', $plataforma['acao'] + ['icon_only' => true, 'class' => 'sm:hidden']) ?>
          <?php } ?>

          <span class="hidden text-right leading-tight sm:block">
            <span class="block text-xs text-muted"><?= e($plataforma['saudacao']) ?>,</span>
            <span class="block max-w-40 truncate text-sm font-medium text-text"><?= e($plataforma['usuario']) ?></span>
          </span>
          <?= component('button', ['label' => 'Sair', 'icon' => 'log-out', 'icon_only' => true, 'variant' => 'ghost', 'href' => site_url('sair')]) ?>
        </div>
      </header>
    </div>

    <main id="conteudo-principal" tabindex="-1">
      <div class="v5-conteudo">
        <?php $this->load->view($view); ?>
      </div>
    </main>

    <div class="v5-shell">
      <footer class="px-4 py-6 text-center text-xs text-muted lg:px-6">
        <?= e(date('Y')) ?> &copy; Map-OS · Painel da plataforma<?= $versao !== '' ? e(' · Versão ' . $versao) : '' ?>
      </footer>
    </div>
  </div>

  <div class="v5-shell">
    <div data-toast-region class="pointer-events-none fixed right-4 bottom-4 z-50 flex flex-col items-end gap-2">
      <?php foreach ($plataforma['flash'] as $mensagem) { ?>
        <?= component('toast', ['variant' => $mensagem['variant'], 'title' => $mensagem['title'], 'duration' => $mensagem['duration'], 'message' => $mensagem['message']]) ?>
      <?php } ?>
    </div>
  </div>
</body>

</html>
