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
$isLayoutPreview = !empty($vm['layoutPreview']);

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

if (!function_exists('sb_public_design_font_stack')) {
    function sb_public_design_font_stack(string $font): string
    {
        $map = [
            'system' => 'Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
            'arial' => 'Arial,Helvetica,sans-serif',
            'georgia' => 'Georgia,"Times New Roman",serif',
            'times' => '"Times New Roman",Times,serif',
            'mono' => 'ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace',
        ];
        return $map[$font] ?? $map['system'];
    }
}

if (!function_exists('sb_public_design_shadow')) {
    function sb_public_design_shadow(string $preset): string
    {
        $map = [
            'none' => 'none',
            'soft' => '0 12px 32px rgba(15,23,42,.08)',
            'medium' => '0 18px 48px rgba(15,23,42,.14)',
            'strong' => '0 24px 70px rgba(15,23,42,.22)',
        ];
        return $map[$preset] ?? $map['soft'];
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
            'secondaryColor' => sb_public_appearance_color((string)($settings['secondaryColor'] ?? '#0f172a'), '#0f172a'),
            'textColor' => sb_public_appearance_color((string)($settings['textColor'] ?? '#0f172a'), '#0f172a'),
            'mutedColor' => sb_public_appearance_color((string)($settings['mutedColor'] ?? '#64748b'), '#64748b'),
            'surfaceColor' => sb_public_appearance_color((string)($settings['surfaceColor'] ?? '#ffffff'), '#ffffff'),
            'borderColor' => sb_public_appearance_color((string)($settings['borderColor'] ?? '#e2e8f0'), '#e2e8f0'),
            'headingFont' => sb_public_design_font_stack((string)($settings['headingFont'] ?? 'system')),
            'bodyFont' => sb_public_design_font_stack((string)($settings['bodyFont'] ?? 'system')),
            'baseFontSize' => max(14, min(22, (int)($settings['baseFontSize'] ?? 16))),
            'bodyLineHeight' => max(1.2, min(2.2, (float)($settings['bodyLineHeight'] ?? 1.6))),
            'headingWeight' => in_array((int)($settings['headingWeight'] ?? 800), [500,600,700,800,900], true) ? (int)($settings['headingWeight'] ?? 800) : 800,
            'radiusScale' => max(0, min(32, (int)($settings['radiusScale'] ?? 16))),
            'buttonRadius' => max(0, min(40, (int)($settings['buttonRadius'] ?? 12))),
            'sectionGap' => max(0, min(96, (int)($settings['sectionGap'] ?? 24))),
            'shadowPreset' => in_array((string)($settings['shadowPreset'] ?? 'soft'), ['none','soft','medium','strong'], true) ? (string)$settings['shadowPreset'] : 'soft',

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
        $styles[] = '--sb-secondary: ' . sb_public_h((string)($appearance['secondaryColor'] ?? '#0f172a'));
        $styles[] = '--sb-text: ' . sb_public_h((string)($appearance['textColor'] ?? '#0f172a'));
        $styles[] = '--sb-muted: ' . sb_public_h((string)($appearance['mutedColor'] ?? '#64748b'));
        $styles[] = '--sb-surface: ' . sb_public_h((string)($appearance['surfaceColor'] ?? '#ffffff'));
        $styles[] = '--sb-border: ' . sb_public_h((string)($appearance['borderColor'] ?? '#e2e8f0'));
        $styles[] = '--sb-heading-font: ' . (string)($appearance['headingFont'] ?? sb_public_design_font_stack('system'));
        $styles[] = '--sb-body-font: ' . (string)($appearance['bodyFont'] ?? sb_public_design_font_stack('system'));
        $styles[] = '--sb-base-font-size: ' . max(14, min(22, (int)($appearance['baseFontSize'] ?? 16))) . 'px';
        $styles[] = '--sb-body-line-height: ' . max(1.2, min(2.2, (float)($appearance['bodyLineHeight'] ?? 1.6)));
        $styles[] = '--sb-heading-weight: ' . (int)($appearance['headingWeight'] ?? 800);
        $styles[] = '--sb-radius: ' . max(0, min(32, (int)($appearance['radiusScale'] ?? 16))) . 'px';
        $styles[] = '--sb-button-radius: ' . max(0, min(40, (int)($appearance['buttonRadius'] ?? 12))) . 'px';
        $styles[] = '--sb-section-stack-gap: ' . max(0, min(96, (int)($appearance['sectionGap'] ?? 24))) . 'px';
        $styles[] = '--sb-global-shadow: ' . sb_public_design_shadow((string)($appearance['shadowPreset'] ?? 'soft'));
        $styles[] = '--sb-logo-size: ' . max(24, min(160, (int)($appearance['logoSize'] ?? 42))) . 'px';
        $styles[] = 'font-family:var(--sb-body-font)';
        $styles[] = 'font-size:var(--sb-base-font-size)';
        $styles[] = 'line-height:var(--sb-body-line-height)';
        $styles[] = 'color:var(--sb-text)';
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

$pageSeo = is_array($currentPage['seo'] ?? null) ? $currentPage['seo'] : [];
$pageSeoTitle = trim((string)($pageSeo['title'] ?? ''));
if ($pageSeoTitle === '') {
    $pageSeoTitle = (string)($currentPage['title'] ?? $site['name'] ?? 'SiteBuilder');
}
$pageSeoDescription = trim((string)($pageSeo['description'] ?? ''));
$pageSeoKeywords = trim((string)($pageSeo['keywords'] ?? ''));
$pageSeoRobots = (!array_key_exists('robotsIndex', $pageSeo) || !empty($pageSeo['robotsIndex']) ? 'index' : 'noindex')
    . ','
    . (!array_key_exists('robotsFollow', $pageSeo) || !empty($pageSeo['robotsFollow']) ? 'follow' : 'nofollow');
$pageSeoCanonical = sb_public_safe_url($pageSeo['canonical'] ?? '');
$pageSeoOgTitle = trim((string)($pageSeo['ogTitle'] ?? '')) ?: $pageSeoTitle;
$pageSeoOgDescription = trim((string)($pageSeo['ogDescription'] ?? '')) ?: $pageSeoDescription;
$pageSeoOgImage = sb_public_safe_url($pageSeo['ogImage'] ?? '', true);

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

if ($vm['leftMode'] === 'menu') {
    $sectionNavHtml = (string)($vm['sectionNavHtml'] ?? '');

    /*
     * Корневой раздел без дочерних страниц не должен создавать
     * пустую боковую колонку. Настоящее дерево всегда содержит
     * хотя бы один элемент .sb-tree-node.
     */
    $leftContentHtml = str_contains(
        $sectionNavHtml,
        'sb-tree-node'
    )
        ? $sectionNavHtml
        : '';
}

/*
 * Классы сетки зависят от реально отображаемых панелей,
 * а не только от сохранённых настроек layout.
 */
$renderLeftSidebar = !empty($vm['showLeft'])
    && trim((string)$leftContentHtml) !== '';

$renderRightSidebar = !empty($vm['showRight'])
    && trim((string)$rightHtml) !== '';

global $APPLICATION;

if ($pageHasDiskBlock && !$isLayoutPreview) {
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

    <?php if (!$isLayoutPreview) { $APPLICATION->ShowHead(); } ?>

    <title><?= sb_public_h($pageSeoTitle) ?></title>
    <meta name="robots" content="<?= sb_public_h($pageSeoRobots) ?>">
    <?php if ($pageSeoDescription !== ''): ?><meta name="description" content="<?= sb_public_h($pageSeoDescription) ?>"><?php endif; ?>
    <?php if ($pageSeoKeywords !== ''): ?><meta name="keywords" content="<?= sb_public_h($pageSeoKeywords) ?>"><?php endif; ?>
    <?php if ($pageSeoCanonical !== ''): ?><link rel="canonical" href="<?= sb_public_h($pageSeoCanonical) ?>"><?php endif; ?>
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= sb_public_h($pageSeoOgTitle) ?>">
    <?php if ($pageSeoOgDescription !== ''): ?><meta property="og:description" content="<?= sb_public_h($pageSeoOgDescription) ?>"><?php endif; ?>
    <?php if ($pageSeoOgImage !== ''): ?><meta property="og:image" content="<?= sb_public_h($pageSeoOgImage) ?>"><?php endif; ?>

    <link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/assets/public/public.css?v=25">

    <?php if ($pageHasDiskBlock): ?>
        <link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/components/disk/styles.css?v=10">
    <?php endif; ?>

    <style>
        :root {
            --sb-accent: <?= sb_public_h($appearance['accent']) ?>;
            --sb-container-width: <?= (int)$vm['containerWidth'] ?>px;
            --sb-left-width: <?= (int)$vm['leftWidth'] ?>px;
            --sb-right-width: <?= (int)$vm['rightWidth'] ?>px;
        }
    </style>
    <link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/assets/public/business-blocks.css?v=20">
    <link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/assets/public/forms2.css?v=1">
    <link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/assets/public/responsive-blocks.css?v=1">
    <link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/assets/public/responsive-stage2.css?v=1">
    <link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/assets/public/responsive-stage3.css?v=1">
    <link rel="stylesheet" href="<?= sb_public_h($basePath) ?>/assets/public/sections2.css?v=1">
</head>
<body>
<div class="sb-public-shell" style="<?= sb_public_h($appearanceStyle) ?>">
    <?php if ($vm['showHeader']): ?>
        <header class="sb-public-header" data-role="public-header">
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
            <?php if ($renderLeftSidebar): ?>
                <button
                    type="button"
                    class="sb-public-section-nav-toggle"
                    data-action="open-section-nav"
                    aria-controls="sb-public-section-nav"
                    aria-expanded="false"
                >
                    <span
                        class="sb-public-section-nav-toggle__icon"
                        aria-hidden="true"
                    ></span>
                    <span>Разделы</span>
                </button>

                <div
                    class="sb-public-nav-backdrop"
                    data-action="close-section-nav"
                    hidden
                ></div>
            <?php endif; ?>

            <div class="sb-layout <?= $renderLeftSidebar ? 'sb-layout--left' : '' ?> <?= $renderRightSidebar ? 'sb-layout--right' : '' ?>">
                <?php if ($renderLeftSidebar): ?>
                    <aside
                        id="sb-public-section-nav"
                        class="sb-sidebar sb-sidebar--left"
                        data-role="section-nav-drawer"
                        aria-label="Навигация по страницам раздела"
                    >
                        <div class="sb-sidebar__mobile-head">
                            <strong>Разделы</strong>

                            <button
                                type="button"
                                class="sb-sidebar__mobile-close"
                                data-action="close-section-nav"
                                aria-label="Закрыть навигацию"
                            >×</button>
                        </div>

                        <div class="sb-box sb-sidebar__box">
                            <?= $leftContentHtml ?>
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

                <?php if ($renderRightSidebar): ?>
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


<?php if ($pageHasDiskBlock && !$isLayoutPreview): ?>
    <script src="<?= sb_public_h($basePath) ?>/components/disk/script.js?v=9"></script>
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
<?php if (!$isLayoutPreview): ?>
    <script src="<?= sb_public_h($basePath) ?>/components/table/view.js"></script>
<?php endif; ?>

<?php if ($isPublicEditMode): ?>
    <script>
        window.SB_PUBLIC_EDIT_CONFIG = <?= json_encode([
            'apiUrl' => $basePath . '/api.php',
            'sessid' => bitrix_sessid(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <script src="<?= sb_public_h($basePath) ?>/components/table/edit.js"></script>
<?php endif; ?>

<?php if (!$isLayoutPreview): ?>
    <script src="<?= sb_public_h($basePath) ?>/assets/public/public-interactions.js?v=20"></script>
    <script src="<?= sb_public_h($basePath) ?>/assets/public/business-blocks.js?v=21"></script>
<?php endif; ?>
</body>
</html>
