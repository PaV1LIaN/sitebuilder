<?php
/** @var array $vm */

$site = $vm['site'];
$pages = $vm['pages'];
$currentPage = $vm['currentPage'];
$pageBlocks = $vm['pageBlocks'];
$layout = $vm['layout'];
$menu = $vm['menu'];
$basePath = $vm['basePath'];
$siteId = (int)$vm['siteId'];

$headerBlocks = $layout['zones']['header'] ?? [];
$footerBlocks = $layout['zones']['footer'] ?? [];
$leftBlocks = $layout['zones']['left'] ?? [];
$rightBlocks = $layout['zones']['right'] ?? [];

$headerHtml = sb_public_render_blocks($headerBlocks, $vm);
$footerHtml = sb_public_render_blocks($footerBlocks, $vm);
$leftHtml = sb_public_render_blocks($leftBlocks, $vm);
$rightHtml = sb_public_render_blocks($rightBlocks, $vm);
$pageHtml = sb_public_render_blocks($pageBlocks, $vm);
$menuHtml = sb_public_render_menu($menu, $basePath, $siteId);

$pageHasDiskBlock = false;

foreach ($pageBlocks as $pageBlock) {
    if ((string)($pageBlock['type'] ?? '') === 'disk') {
        $pageHasDiskBlock = true;
        break;
    }
}

if (!$pageHasDiskBlock) {
    foreach ($headerBlocks as $layoutBlock) {
        if ((string)($layoutBlock['type'] ?? '') === 'disk') {
            $pageHasDiskBlock = true;
            break;
        }
    }
}

if (!$pageHasDiskBlock) {
    foreach ($footerBlocks as $layoutBlock) {
        if ((string)($layoutBlock['type'] ?? '') === 'disk') {
            $pageHasDiskBlock = true;
            break;
        }
    }
}

if (!$pageHasDiskBlock) {
    foreach ($leftBlocks as $layoutBlock) {
        if ((string)($layoutBlock['type'] ?? '') === 'disk') {
            $pageHasDiskBlock = true;
            break;
        }
    }
}

if (!$pageHasDiskBlock) {
    foreach ($rightBlocks as $layoutBlock) {
        if ((string)($layoutBlock['type'] ?? '') === 'disk') {
            $pageHasDiskBlock = true;
            break;
        }
    }
}

$leftContentHtml = $vm['leftMode'] === 'menu' && $menuHtml !== '' ? $menuHtml : $leftHtml;

if ($vm['leftMode'] === 'menu' && $vm['sectionNavHtml'] !== '') {
    $leftContentHtml = $vm['sectionNavHtml'];
}

global $APPLICATION;

if ($pageHasDiskBlock) {
    \CJSCore::Init([
        'viewer',
        'ui.viewer',
    ]);

    if (\Bitrix\Main\Loader::includeModule('disk')) {
        \Bitrix\Main\UI\Extension::load([
            'disk.viewer.document-item',
        ]);
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">

    <?php $APPLICATION->ShowHead(); ?>

    <title><?= sb_public_h((string)($currentPage['title'] ?? $site['name'] ?? 'SiteBuilder')) ?></title>

    <link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/assets/public/public.css">

    <?php if ($pageHasDiskBlock): ?>
        <link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/components/disk/styles.css">
    <?php endif; ?>

    <style>
        :root {
            --sb-accent: <?= sb_public_h($vm['accent']) ?>;
            --sb-container-width: <?= (int)$vm['containerWidth'] ?>px;
            --sb-left-width: <?= (int)$vm['leftWidth'] ?>px;
            --sb-right-width: <?= (int)$vm['rightWidth'] ?>px;
        }
    </style>
</head>
<body>
<div class="sb-public-shell">
    <?php if ($vm['showHeader']): ?>
        <header class="sb-public-header">
            <div class="sb-container">
                <?php if ($headerHtml !== ''): ?>
                    <?= $headerHtml ?>
                <?php else: ?>
                    <div class="sb-brand">
                        <?= sb_public_h((string)($site['name'] ?? 'SiteBuilder')) ?>
                    </div>
                <?php endif; ?>

                <?php if ($menuHtml !== ''): ?>
                    <?= $menuHtml ?>
                <?php elseif (!empty($pages)): ?>
                    <nav class="sb-public-menu">
                        <?php foreach ($pages as $page): ?>
                            <?php if ((int)($page['parentId'] ?? 0) !== 0) continue; ?>
                            <a class="sb-public-menu__link" href="<?= sb_public_h(sb_public_page_url($basePath, $siteId, (int)$page['id'])) ?>">
                                <?= sb_public_h((string)($page['title'] ?? 'Страница')) ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </header>
    <?php endif; ?>

    <main class="sb-public-main">
        <div class="sb-container">
            <?php if (!empty($vm['breadcrumbsHtml'])): ?>
                <?= $vm['breadcrumbsHtml'] ?>
            <?php endif; ?>

            <div class="sb-layout <?= $vm['showLeft'] ? 'sb-layout--left' : '' ?> <?= $vm['showRight'] ? 'sb-layout--right' : '' ?>">
                <?php if ($vm['showLeft']): ?>
                    <aside class="sb-sidebar sb-sidebar--left">
                        <div class="sb-box">
                            <?= $leftContentHtml !== '' ? $leftContentHtml : '<div class="sb-empty">Левая зона пуста</div>' ?>
                        </div>
                    </aside>
                <?php endif; ?>

                <section class="sb-content">
                    <div class="sb-box sb-box--content">
                        <?php if ($currentPage): ?>
                            <h1 class="sb-page-title">
                                <?= sb_public_h((string)($currentPage['title'] ?? 'Страница')) ?>
                            </h1>

                            <?php if (!empty($vm['childPagesHtml'])): ?>
                                <?= $vm['childPagesHtml'] ?>
                            <?php endif; ?>

                            <?= $pageHtml !== '' ? $pageHtml : '<div class="sb-empty">На странице пока нет блоков</div>' ?>
                        <?php else: ?>
                            <div class="sb-empty">У сайта пока нет страниц</div>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if ($vm['showRight']): ?>
                    <aside class="sb-sidebar sb-sidebar--right">
                        <div class="sb-box">
                            <?= $rightHtml !== '' ? $rightHtml : '<div class="sb-empty">Правая зона пуста</div>' ?>
                        </div>
                    </aside>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php if ($vm['showFooter']): ?>
        <footer class="sb-public-footer">
            <div class="sb-container">
                <?= $footerHtml !== '' ? $footerHtml : '<div class="sb-footer-note">© ' . date('Y') . ' ' . sb_public_h((string)($site['name'] ?? 'SiteBuilder')) . '</div>' ?>
            </div>
        </footer>
    <?php endif; ?>
</div>

<script>
document.addEventListener('click', function (e) {
    var toggle = e.target.closest('[data-role="toggle"]');
    if (!toggle) {
        return;
    }

    var node = toggle.closest('.sb-tree-node');
    if (!node) {
        return;
    }

    var isOpen = node.classList.contains('is-open');
    node.classList.toggle('is-open', !isOpen);
    toggle.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
});
</script>

<?php if ($pageHasDiskBlock): ?>
<script src="<?= sb_public_h($basePath) ?>/components/disk/script.js"></script>
<?php endif; ?>

</body>
</html>
