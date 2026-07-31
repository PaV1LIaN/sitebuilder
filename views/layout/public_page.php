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

if (!function_exists('sb_public_appearance_file_url')) {
    function sb_public_appearance_file_url(int $fileId): string
    {
        if ($fileId <= 0 || !class_exists('CFile')) {
            return '';
        }

        return (string)CFile::GetPath($fileId);
    }
}

if (!function_exists('sb_public_appearance_color')) {
    function sb_public_appearance_color(string $color, string $fallback): string
    {
        $color = trim($color);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) || preg_match('/^#[0-9a-fA-F]{3}$/', $color)) {
            return strtolower($color);
        }

        return $fallback;
    }
}

if (!function_exists('sb_public_appearance_background_size')) {
    function sb_public_appearance_background_size(string $mode): string
    {
        switch ($mode) {
            case 'contain':
                return 'contain';

            case 'auto':
                return 'auto';

            case 'stretch':
                return '100% 100%';

            case 'cover':
            default:
                return 'cover';
        }
    }
}

if (!function_exists('sb_public_appearance_background_position')) {
    function sb_public_appearance_background_position(string $position): string
    {
        $allowed = [
            'center center',
            'top center',
            'bottom center',
            'left center',
            'right center',
        ];

        return in_array($position, $allowed, true) ? $position : 'center center';
    }
}

if (!function_exists('sb_public_appearance_background_repeat')) {
    function sb_public_appearance_background_repeat(string $repeat): string
    {
        $allowed = [
            'no-repeat',
            'repeat',
            'repeat-x',
            'repeat-y',
        ];

        return in_array($repeat, $allowed, true) ? $repeat : 'no-repeat';
    }
}

if (!function_exists('sb_public_appearance_get')) {
    function sb_public_appearance_get(array $site, array $vm): array
    {
        $settings = is_array($site['settings'] ?? null) ? $site['settings'] : [];

        $logoFileId = (int)($settings['logoFileId'] ?? 0);
        $backgroundFileId = (int)($settings['backgroundFileId'] ?? 0);

        $headerLogoMode = (string)($settings['headerLogoMode'] ?? 'image');

        if (!in_array($headerLogoMode, ['image', 'text', 'both'], true)) {
            $headerLogoMode = 'image';
        }

        return [
            'accent' => sb_public_appearance_color(
                (string)($settings['accent'] ?? ($vm['accent'] ?? '#2563eb')),
                '#2563eb'
            ),

            'logoFileId' => $logoFileId,
            'logoUrl' => sb_public_appearance_file_url($logoFileId),

            'backgroundFileId' => $backgroundFileId,
            'backgroundUrl' => sb_public_appearance_file_url($backgroundFileId),

            'backgroundColor' => sb_public_appearance_color(
                (string)($settings['backgroundColor'] ?? '#f8fafc'),
                '#f8fafc'
            ),

            'backgroundMode' => (string)($settings['backgroundMode'] ?? 'cover'),

            'backgroundPosition' => sb_public_appearance_background_position(
                (string)($settings['backgroundPosition'] ?? 'center center')
            ),

            'backgroundRepeat' => sb_public_appearance_background_repeat(
                (string)($settings['backgroundRepeat'] ?? 'no-repeat')
            ),

            'headerLogoMode' => $headerLogoMode,

            'logoSize' => max(24, min(160, (int)($settings['logoSize'] ?? 42))),
        ];
    }
}

if (!function_exists('sb_public_appearance_style')) {
    function sb_public_appearance_style(array $appearance): string
    {
        $styles = [];

        $styles[] = '--sb-accent: ' . sb_public_h((string)($appearance['accent'] ?? '#2563eb'));
        $styles[] = '--sb-logo-size: ' . max(24, min(160, (int)($appearance['logoSize'] ?? 42))) . 'px';
        $styles[] = 'background-color: ' . sb_public_h((string)($appearance['backgroundColor'] ?? '#f8fafc'));

        $backgroundUrl = (string)($appearance['backgroundUrl'] ?? '');

        if ($backgroundUrl !== '') {
            $styles[] = 'background-image: url("' . sb_public_h($backgroundUrl) . '")';
            $styles[] = 'background-size: ' . sb_public_appearance_background_size((string)($appearance['backgroundMode'] ?? 'cover'));
            $styles[] = 'background-position: ' . sb_public_h((string)($appearance['backgroundPosition'] ?? 'center center'));
            $styles[] = 'background-repeat: ' . sb_public_h((string)($appearance['backgroundRepeat'] ?? 'no-repeat'));
        }

        return implode('; ', $styles);
    }
}

if (!function_exists('sb_public_appearance_brand')) {
    function sb_public_appearance_brand(array $site, array $appearance): string
    {
        $siteName = (string)($site['name'] ?? 'SiteBuilder');
        $logoUrl = (string)($appearance['logoUrl'] ?? '');
        $mode = (string)($appearance['headerLogoMode'] ?? 'image');

        if (!in_array($mode, ['image', 'text', 'both'], true)) {
            $mode = 'image';
        }

        $html = '';

        if (($mode === 'image' || $mode === 'both') && $logoUrl !== '') {
            $html .= '<span class="sb-brand__logo">';
            $html .= '<img src="' . sb_public_h($logoUrl) . '" alt="' . sb_public_h($siteName) . '">';
            $html .= '</span>';
        }

        if ($mode === 'text' || $mode === 'both' || $logoUrl === '') {
            $html .= '<span class="sb-brand__text">' . sb_public_h($siteName) . '</span>';
        }

        return $html;
    }
}

if (!function_exists('sb_public_auto_menu_is_page_visible')) {
    function sb_public_auto_menu_is_page_visible(array $page, int $currentPageId = 0): bool
    {
        $status = (string)($page['status'] ?? 'published');
        $pageId = (int)($page['id'] ?? 0);

        if ($pageId === $currentPageId) {
            return true;
        }

        return $status === 'published';
    }
}

if (!function_exists('sb_public_auto_menu_children')) {
    function sb_public_auto_menu_children(array $pages, int $parentId, int $currentPageId = 0): array
    {
        $items = [];

        foreach ($pages as $page) {
            if ((int)($page['parentId'] ?? 0) !== $parentId) {
                continue;
            }

            if (!sb_public_auto_menu_is_page_visible($page, $currentPageId)) {
                continue;
            }

            $items[] = $page;
        }

        usort($items, static function ($a, $b) {
            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);

            if ($sortCmp !== 0) {
                return $sortCmp;
            }

            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return $items;
    }
}

if (!function_exists('sb_public_auto_menu_has_active_child')) {
    function sb_public_auto_menu_has_active_child(array $pages, int $pageId, int $currentPageId): bool
    {
        foreach ($pages as $page) {
            if ((int)($page['parentId'] ?? 0) !== $pageId) {
                continue;
            }

            $childId = (int)($page['id'] ?? 0);

            if ($childId === $currentPageId) {
                return true;
            }

            if (sb_public_auto_menu_has_active_child($pages, $childId, $currentPageId)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('sb_public_render_auto_menu_level')) {
    function sb_public_render_auto_menu_level(
        array $pages,
        int $parentId,
        string $basePath,
        int $siteId,
        int $currentPageId,
        int $level = 0
    ): string {
        $children = sb_public_auto_menu_children(
            $pages,
            $parentId,
            $currentPageId
        );

        if (empty($children)) {
            return '';
        }

        $class = $level === 0
            ? 'sb-public-menu'
            : 'sb-public-menu__dropdown';

        $html = '<nav class="' . $class . '">';

        foreach ($children as $page) {
            $pageId = (int)($page['id'] ?? 0);
            $title = (string)($page['title'] ?? 'Страница');
            $url = sb_public_page_url($basePath, $siteId, $pageId);

            $childHtml = '';
            $hasChildren = false;

            $isActive =
                $pageId === $currentPageId
                || sb_public_auto_menu_has_active_child(
                    $pages,
                    $pageId,
                    $currentPageId
                );

            $html .= '<div class="sb-public-menu__item'
                . ($hasChildren ? ' has-children' : '')
                . ($isActive ? ' is-active' : '')
                . '">';

            $html .= '<a class="sb-public-menu__link" href="'
                . sb_public_h($url)
                . '">';

            $html .= sb_public_h($title);

            if ($hasChildren) {
                $html .= ' <span class="sb-public-menu__arrow">▾</span>';
            }

            $html .= '</a>';

            if ($hasChildren) {
                $html .= $childHtml;
            }

            $html .= '</div>';
        }

        $html .= '</nav>';

        return $html;
    }
}

if (!function_exists('sb_public_render_auto_pages_menu')) {
    function sb_public_render_auto_pages_menu(
        array $pages,
        string $basePath,
        int $siteId,
        int $currentPageId = 0
    ): string {
        return sb_public_render_auto_menu_level(
            $pages,
            0,
            $basePath,
            $siteId,
            $currentPageId,
            0
        );
    }
}

$appearance = sb_public_appearance_get($site, $vm);
$appearanceStyle = sb_public_appearance_style($appearance);

$headerBlocks = $layout['zones']['header'] ?? [];
$footerBlocks = $layout['zones']['footer'] ?? [];
$leftBlocks = $layout['zones']['left'] ?? [];
$rightBlocks = $layout['zones']['right'] ?? [];

$headerHtml = sb_public_render_blocks($headerBlocks, $vm);
$footerHtml = sb_public_render_blocks($footerBlocks, $vm);
$leftHtml = sb_public_render_blocks($leftBlocks, $vm);
$rightHtml = sb_public_render_blocks($rightBlocks, $vm);

$pageSections = is_array($vm['pageSections'] ?? null)
    ? $vm['pageSections']
    : [];

if (empty($pageSections) && function_exists('sb_public_page_sections')) {
    $pageSections = sb_public_page_sections(
        $siteId,
        (int)($currentPage['id'] ?? 0)
    );
}

$pageHtml = function_exists('sb_public_render_page_sections')
    ? sb_public_render_page_sections($pageSections, $pageBlocks, $vm)
    : sb_public_render_blocks($pageBlocks, $vm);

$menuHtml = sb_public_render_auto_pages_menu(
    $pages,
    $basePath,
    $siteId,
    (int)($currentPage['id'] ?? 0)
);

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

$leftContentHtml = $vm['leftMode'] === 'menu' && $menuHtml !== ''
    ? $menuHtml
    : $leftHtml;

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

    <link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/assets/public/public.css?v=16">

    <?php if ($pageHasDiskBlock): ?>
        <link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/components/disk/styles.css?v=4">
    <?php endif; ?>

    <style>
        :root {
            --sb-accent: <?= sb_public_h($appearance['accent']) ?>;
            --sb-container-width: <?= (int)$vm['containerWidth'] ?>px;
            --sb-left-width: <?= (int)$vm['leftWidth'] ?>px;
            --sb-right-width: <?= (int)$vm['rightWidth'] ?>px;
        }
    </style>
</head>
<body>
<div class="sb-public-shell" style="<?= sb_public_h($appearanceStyle) ?>">
    <?php if ($vm['showHeader']): ?>
        <header class="sb-public-header">
            <div class="sb-container sb-header-container">
                <div class="sb-header-brand-row">
                    <div class="sb-brand">
                        <?= sb_public_appearance_brand($site, $appearance) ?>
                    </div>

                    <?php if ($headerHtml !== ''): ?>
                        <div class="sb-header-custom">
                            <?= $headerHtml ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($menuHtml !== ''): ?>
                    <div class="sb-header-menu-row">
                        <?= $menuHtml ?>
                    </div>
                <?php endif; ?>
            </div>
        </header>
    <?php endif; ?>

    <main class="sb-public-main">
        <div class="sb-container">
            <div class="sb-layout <?= $vm['showLeft'] ? 'sb-layout--left' : '' ?> <?= $vm['showRight'] ? 'sb-layout--right' : '' ?>">
                <?php if ($vm['showLeft'] && trim($leftContentHtml) !== ''): ?>
                    <aside class="sb-sidebar sb-sidebar--left">
                        <div class="sb-box">
                            <?= $leftContentHtml !== ''
                                ? $leftContentHtml
                                : '<div class="sb-empty">Левая зона пуста</div>' ?>
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

                            <?= $pageHtml !== ''
                                ? $pageHtml
                                : '<div class="sb-empty">На странице пока нет блоков</div>' ?>
                        <?php else: ?>
                            <div class="sb-empty">У сайта пока нет страниц</div>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if ($vm['showRight']): ?>
                    <aside class="sb-sidebar sb-sidebar--right">
                        <div class="sb-box">
                            <?= $rightHtml !== ''
                                ? $rightHtml
                                : '<div class="sb-empty">Правая зона пуста</div>' ?>
                        </div>
                    </aside>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php if ($vm['showFooter'] && $footerHtml !== ''): ?>
        <footer class="sb-public-footer">
            <div class="sb-container">
                <?= $footerHtml !== '' ? $footerHtml : '' ?>
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
    toggle.setAttribute(
        'aria-expanded',
        !isOpen ? 'true' : 'false'
    );
});
</script>

<?php if ($pageHasDiskBlock): ?>
    <script src="<?= sb_public_h($basePath) ?>/components/disk/script.js?v=4"></script>
<?php endif; ?>

<?php
global $USER;

$isPublicEditMode = (
    (string)($_GET['edit'] ?? '') === 'Y'
    && is_object($USER)
    && $USER->IsAuthorized()
    && $USER->IsAdmin()
);
?>

<link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/components/table/styles.css">
<script src="<?= sb_public_h($basePath) ?>/components/table/view.js"></script>

<?php if ($isPublicEditMode): ?>
    <script>
        window.SB_PUBLIC_EDIT_CONFIG = <?= json_encode([
            'apiUrl' => $basePath . '/api.php',
            'sessid' => bitrix_sessid(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <script src="<?= sb_public_h($basePath) ?>/components/table/edit.js"></script>
<?php endif; ?>

</body>
</html>