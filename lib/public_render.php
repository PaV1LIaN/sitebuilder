<?php

require_once __DIR__ . '/json.php';
require_once __DIR__ . '/helpers.php';

if (!function_exists('sb_public_h')) {
    function sb_public_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('sb_public_find_site')) {
    function sb_public_find_site(int $siteId): ?array
    {
        foreach (sb_read_sites() as $site) {
            if ((int)($site['id'] ?? 0) === $siteId) {
                return $site;
            }
        }
        return null;
    }
}

if (!function_exists('sb_public_pages_for_site')) {
    function sb_public_pages_for_site(int $siteId): array
    {
        $pages = array_values(array_filter(sb_read_pages(), static function ($p) use ($siteId) {
            return (int)($p['siteId'] ?? 0) === $siteId;
        }));

        usort($pages, static function ($a, $b) {
            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
            if ($sortCmp !== 0) {
                return $sortCmp;
            }
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return array_map('sb_normalize_page_record', $pages);
    }
}

if (!function_exists('sb_public_find_page_for_site')) {
    function sb_public_find_page_for_site(int $siteId, int $pageId): ?array
    {
        foreach (sb_read_pages() as $page) {
            if (
                (int)($page['siteId'] ?? 0) === $siteId &&
                (int)($page['id'] ?? 0) === $pageId
            ) {
                return sb_normalize_page_record($page);
            }
        }
        return null;
    }
}

if (!function_exists('sb_public_page_blocks')) {
    function sb_public_page_blocks(int $pageId): array
    {
        $blocks = array_values(array_filter(sb_read_blocks(), static function ($b) use ($pageId) {
            return (int)($b['pageId'] ?? 0) === $pageId;
        }));

        usort($blocks, static function ($a, $b) {
            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
            if ($sortCmp !== 0) {
                return $sortCmp;
            }
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return array_map('sb_normalize_block_record', $blocks);
    }
}

if (!function_exists('sb_public_layout_for_site')) {
    function sb_public_layout_for_site(int $siteId): array
    {
        foreach (sb_read_layouts() as $layout) {
            if ((int)($layout['siteId'] ?? 0) === $siteId) {
                return sb_normalize_layout_record($layout);
            }
        }

        return sb_normalize_layout_record(sb_layout_default_record($siteId));
    }
}

if (!function_exists('sb_public_menu_for_site')) {
    function sb_public_menu_for_site(array $site): ?array
    {
        $topMenuId = (int)($site['topMenuId'] ?? 0);
        if ($topMenuId <= 0) {
            return null;
        }

        foreach (sb_read_menus() as $menu) {
            if ((int)($menu['id'] ?? 0) === $topMenuId) {
                return sb_normalize_menu_record($menu);
            }
        }

        return null;
    }
}

if (!function_exists('sb_public_page_url')) {
    function sb_public_page_url(string $basePath, int $siteId, int $pageId): string
    {
        return $basePath . '/public.php?siteId=' . $siteId . '&pageId=' . $pageId;
    }
}

if (!function_exists('sb_public_menu_item_url')) {
    function sb_public_menu_item_url(array $item, string $basePath, int $siteId): string
    {
        $type = (string)($item['type'] ?? 'page');

        if ($type === 'page') {
            $pageId = (int)($item['pageId'] ?? 0);
            return $pageId > 0 ? sb_public_page_url($basePath, $siteId, $pageId) : '#';
        }

        $url = trim((string)($item['url'] ?? ''));
        return $url !== '' ? $url : '#';
    }
}

if (!function_exists('sb_public_render_block')) {
    function sb_public_render_block(array $block, array $context = []): string
    {
        $type = (string)($block['type'] ?? 'text');
        $template = dirname(__DIR__) . '/views/blocks/' . $type . '.php';

        if (!file_exists($template)) {
            $template = dirname(__DIR__) . '/views/blocks/text.php';
        }

        $block = sb_normalize_block_record($block);
        $content = (array)($block['content'] ?? []);
        $props = (array)($block['props'] ?? []);

        ob_start();
        include $template;
        return (string)ob_get_clean();
    }
}

if (!function_exists('sb_public_render_blocks')) {
    function sb_public_render_blocks(array $blocks, array $context = []): string
    {
        if (!$blocks) {
            return '';
        }

        $html = '';
        foreach ($blocks as $block) {
            $html .= sb_public_render_block($block, $context);
        }
        return $html;
    }
}

if (!function_exists('sb_public_render_menu')) {
    function sb_public_render_menu(?array $menu, string $basePath, int $siteId): string
    {
        if (!$menu || empty($menu['items']) || !is_array($menu['items'])) {
            return '';
        }

        $html = '<nav class="sb-public-menu">';
        foreach ($menu['items'] as $item) {
            $title = sb_public_h((string)($item['title'] ?? 'Пункт'));
            $url = sb_public_h(sb_public_menu_item_url($item, $basePath, $siteId));
            $target = sb_public_h((string)($item['target'] ?? '_self'));
            $html .= '<a class="sb-public-menu__link" href="' . $url . '" target="' . $target . '">' . $title . '</a>';
        }
        $html .= '</nav>';

        return $html;
    }
}

if (!function_exists('sb_public_build_page_map')) {
    function sb_public_build_page_map(array $pages): array
    {
        $map = [];
        foreach ($pages as $page) {
            $page = sb_normalize_page_record($page);
            $page['children'] = [];
            $map[(int)$page['id']] = $page;
        }

        foreach ($map as $id => $page) {
            $parentId = (int)($page['parentId'] ?? 0);
            if ($parentId > 0 && isset($map[$parentId])) {
                $map[$parentId]['children'][] = $id;
            }
        }

        return $map;
    }
}

if (!function_exists('sb_public_page_children')) {
    function sb_public_page_children(array $pages, int $parentId): array
    {
        $result = [];

        foreach ($pages as $page) {
            if ((int)($page['parentId'] ?? 0) === $parentId) {
                $result[] = sb_normalize_page_record($page);
            }
        }

        usort($result, static function ($a, $b) {
            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
            if ($sortCmp !== 0) {
                return $sortCmp;
            }
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return $result;
    }
}

if (!function_exists('sb_public_breadcrumbs')) {
    function sb_public_breadcrumbs(array $pages, ?array $currentPage): array
    {
        if (!$currentPage) {
            return [];
        }

        $map = [];
        foreach ($pages as $page) {
            $page = sb_normalize_page_record($page);
            $map[(int)$page['id']] = $page;
        }

        $chain = [];
        $cursor = $currentPage;
        $safety = 0;

        while ($cursor && $safety < 1000) {
            array_unshift($chain, $cursor);
            $parentId = (int)($cursor['parentId'] ?? 0);
            if ($parentId <= 0 || !isset($map[$parentId])) {
                break;
            }
            $cursor = $map[$parentId];
            $safety++;
        }

        return $chain;
    }
}

if (!function_exists('sb_public_section_root')) {
    function sb_public_section_root(array $pages, ?array $currentPage): ?array
    {
        if (!$currentPage) {
            return null;
        }

        $map = [];
        foreach ($pages as $page) {
            $page = sb_normalize_page_record($page);
            $map[(int)$page['id']] = $page;
        }

        $cursor = $currentPage;
        $last = $cursor;
        $safety = 0;

        while ($cursor && $safety < 1000) {
            $parentId = (int)($cursor['parentId'] ?? 0);
            if ($parentId <= 0 || !isset($map[$parentId])) {
                break;
            }
            $last = $map[$parentId];
            $cursor = $map[$parentId];
            $safety++;
        }

        return $last;
    }
}

if (!function_exists('sb_public_render_breadcrumbs')) {
    function sb_public_render_breadcrumbs(array $breadcrumbs, string $basePath, int $siteId): string
    {
        if (!$breadcrumbs) {
            return '';
        }

        $html = '<nav class="sb-breadcrumbs">';
        $lastIndex = count($breadcrumbs) - 1;

        foreach ($breadcrumbs as $i => $page) {
            $title = sb_public_h((string)($page['title'] ?? 'Страница'));
            if ($i < $lastIndex) {
                $url = sb_public_h(sb_public_page_url($basePath, $siteId, (int)$page['id']));
                $html .= '<a class="sb-breadcrumbs__link" href="' . $url . '">' . $title . '</a>';
                $html .= '<span class="sb-breadcrumbs__sep">/</span>';
            } else {
                $html .= '<span class="sb-breadcrumbs__current">' . $title . '</span>';
            }
        }

        $html .= '</nav>';
        return $html;
    }
}

if (!function_exists('sb_public_render_section_nav')) {
    function sb_public_render_section_nav(array $pages, ?array $currentPage, string $basePath, int $siteId): string
    {
        if (!$currentPage) {
            return '';
        }

        $sectionRoot = sb_public_section_root($pages, $currentPage);
        if (!$sectionRoot) {
            return '';
        }

        $pageMap = [];
        foreach ($pages as $page) {
            $page = sb_normalize_page_record($page);
            $page['children_nodes'] = [];
            $pageMap[(int)$page['id']] = $page;
        }

        foreach ($pageMap as $id => $page) {
            $parentId = (int)($page['parentId'] ?? 0);
            if ($parentId > 0 && isset($pageMap[$parentId])) {
                $pageMap[$parentId]['children_nodes'][] = $id;
            }
        }

        $sortTree = function ($pageId) use (&$sortTree, &$pageMap) {
            if (!isset($pageMap[$pageId])) {
                return;
            }

            if (!empty($pageMap[$pageId]['children_nodes'])) {
                usort($pageMap[$pageId]['children_nodes'], function ($aId, $bId) use (&$pageMap) {
                    $a = $pageMap[$aId];
                    $b = $pageMap[$bId];

                    $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
                    if ($sortCmp !== 0) {
                        return $sortCmp;
                    }

                    return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
                });

                foreach ($pageMap[$pageId]['children_nodes'] as $childId) {
                    $sortTree($childId);
                }
            }
        };

        $rootId = (int)($sectionRoot['id'] ?? 0);
        if (!isset($pageMap[$rootId])) {
            return '';
        }

        $sortTree($rootId);

        $isInActiveBranch = function ($nodeId) use (&$isInActiveBranch, &$pageMap, $currentPage) {
            if ((int)$nodeId === (int)($currentPage['id'] ?? 0)) {
                return true;
            }

            if (!isset($pageMap[$nodeId]) || empty($pageMap[$nodeId]['children_nodes'])) {
                return false;
            }

            foreach ($pageMap[$nodeId]['children_nodes'] as $childId) {
                if ($isInActiveBranch($childId)) {
                    return true;
                }
            }

            return false;
        };

        $renderNode = function ($nodeId, $depth) use (&$renderNode, &$pageMap, $currentPage, $basePath, $siteId, $isInActiveBranch) {
            if (!isset($pageMap[$nodeId])) {
                return '';
            }

            $node = $pageMap[$nodeId];
            $children = $node['children_nodes'] ?? [];
            $hasChildren = !empty($children);
            $isActive = (int)($node['id'] ?? 0) === (int)($currentPage['id'] ?? 0);
            $isOpen = $isInActiveBranch($nodeId);

            $activeClass = $isActive ? ' is-active' : '';
            $hasChildrenClass = $hasChildren ? ' has-children' : '';
            $openClass = $isOpen ? ' is-open' : '';

            $url = sb_public_h(sb_public_page_url($basePath, $siteId, (int)$node['id']));
            $title = sb_public_h((string)($node['title'] ?? 'Страница'));
            $depth = max(0, (int)$depth);

            $html = '';
            $html .= '<div class="sb-tree-node' . $hasChildrenClass . $openClass . '" style="--sb-nav-depth:' . $depth . ';">';
            $html .= '  <div class="sb-tree-node__row">';

            if ($hasChildren) {
                $html .= '    <button type="button" class="sb-tree-node__toggle" data-role="toggle" aria-expanded="' . ($isOpen ? 'true' : 'false') . '">';
                $html .= '      <span class="sb-tree-node__toggle-icon"></span>';
                $html .= '    </button>';
            } else {
                $html .= '    <span class="sb-tree-node__toggle sb-tree-node__toggle--empty"></span>';
            }

            $html .= '    <a class="sb-section-nav__link' . $activeClass . '" href="' . $url . '">';
            $html .= '      <span class="sb-section-nav__text">' . $title . '</span>';
            $html .= '    </a>';
            $html .= '  </div>';

            if ($hasChildren) {
                $html .= '  <div class="sb-tree-node__children">';
                foreach ($children as $childId) {
                    $html .= $renderNode($childId, $depth + 1);
                }
                $html .= '  </div>';
            }

            $html .= '</div>';

            return $html;
        };

        $html = '<div class="sb-section-nav">';
        $html .= '<div class="sb-section-nav__title-row">';
        $html .= '  <a class="sb-section-nav__root-link" href="' . sb_public_h(sb_public_page_url($basePath, $siteId, $rootId)) . '">'
              . sb_public_h((string)($sectionRoot['title'] ?? 'Раздел')) . '</a>';
        $html .= '</div>';

        $html .= '<div class="sb-section-nav__tree">';

        $rootChildren = $pageMap[$rootId]['children_nodes'] ?? [];
        foreach ($rootChildren as $childId) {
            $html .= $renderNode($childId, 0);
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('sb_public_render_child_pages')) {
    function sb_public_render_child_pages(array $pages, ?array $currentPage, string $basePath, int $siteId): string
    {
        return '';
    }
}

if (!function_exists('sb_public_build_view_model')) {
    function sb_public_build_view_model(int $siteId, ?int $requestedPageId, string $basePath): ?array
    {
        $site = sb_public_find_site($siteId);
        if (!$site) {
            return null;
        }

        $pages = sb_public_pages_for_site($siteId);
        $currentPage = null;

        if ($requestedPageId && $requestedPageId > 0) {
            $currentPage = sb_public_find_page_for_site($siteId, $requestedPageId);
        }

        if (!$currentPage) {
            $homePageId = (int)($site['homePageId'] ?? 0);
            if ($homePageId > 0) {
                $currentPage = sb_public_find_page_for_site($siteId, $homePageId);
            }
        }

        if (!$currentPage && !empty($pages)) {
            $currentPage = $pages[0];
        }

        $layout = sb_public_layout_for_site($siteId);
        $menu = sb_public_menu_for_site($site);
        $pageBlocks = $currentPage ? sb_public_page_blocks((int)$currentPage['id']) : [];

        $settings = isset($site['settings']) && is_array($site['settings']) ? $site['settings'] : [];
        $layoutSettings = isset($layout['settings']) && is_array($layout['settings']) ? $layout['settings'] : [];

        $containerWidth = max(320, min(1920, (int)($settings['containerWidth'] ?? 1360)));
        $accent = (string)($settings['accent'] ?? '#2563eb');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $accent = '#2563eb';
        }

        $breadcrumbs = sb_public_breadcrumbs($pages, $currentPage);
        $sectionNavHtml = sb_public_render_section_nav($pages, $currentPage, $basePath, $siteId);
        $childPagesHtml = sb_public_render_child_pages($pages, $currentPage, $basePath, $siteId);

        return [
            'site' => $site,
            'pages' => $pages,
            'currentPage' => $currentPage,
            'pageBlocks' => $pageBlocks,
            'layout' => $layout,
            'menu' => $menu,
            'basePath' => $basePath,
            'siteId' => $siteId,
            'containerWidth' => $containerWidth,
            'accent' => $accent,
            'showHeader' => !empty($layoutSettings['showHeader']),
            'showFooter' => !empty($layoutSettings['showFooter']),
            'showLeft' => !empty($layoutSettings['showLeft']),
            'showRight' => !empty($layoutSettings['showRight']),
            'leftWidth' => max(120, min(800, (int)($layoutSettings['leftWidth'] ?? 260))),
            'rightWidth' => max(120, min(800, (int)($layoutSettings['rightWidth'] ?? 260))),
            'leftMode' => (string)($layoutSettings['leftMode'] ?? 'blocks'),
            'breadcrumbs' => $breadcrumbs,
            'breadcrumbsHtml' => sb_public_render_breadcrumbs($breadcrumbs, $basePath, $siteId),
            'sectionNavHtml' => $sectionNavHtml,
            'childPagesHtml' => $childPagesHtml,
        ];
    }
}