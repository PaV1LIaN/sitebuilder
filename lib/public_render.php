<?php

require_once __DIR__ . '/json.php';
require_once __DIR__ . '/helpers.php';

if (!function_exists('sb_public_h')) {
    function sb_public_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('sb_public_to_array')) {
    function sb_public_to_array($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}

if (!function_exists('sb_public_clamp_int')) {
    function sb_public_clamp_int($value, int $min, int $max): int
    {
        $value = (int)$value;

        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
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


if (!function_exists('sb_public_normalize_page_section')) {
    function sb_public_normalize_page_section(array $section, int $siteId, int $pageId): array
    {
        $layout = sb_public_to_array($section['layout'] ?? $section['layout_json'] ?? []);
        $props = sb_public_to_array($section['props'] ?? $section['props_json'] ?? []);

        $columns = sb_public_clamp_int($layout['columns'] ?? 1, 1, 4);
        $gap = sb_public_clamp_int($layout['gap'] ?? 24, 0, 120);

        $container = (string)($layout['container'] ?? 'default');
        if (!in_array($container, ['default', 'wide', 'full'], true)) {
            $container = 'default';
        }

        $layout['columns'] = $columns;
        $layout['gap'] = $gap;
        $layout['container'] = $container;

        return [
            'id' => (int)($section['id'] ?? 0),
            'siteId' => (int)($section['siteId'] ?? $section['site_id'] ?? $siteId),
            'pageId' => (int)($section['pageId'] ?? $section['page_id'] ?? $pageId),
            'title' => (string)($section['title'] ?? 'Секция'),
            'sort' => (int)($section['sort'] ?? 500),
            'layout' => $layout,
            'props' => $props,
        ];
    }
}

if (!function_exists('sb_public_extract_sections_result')) {
    function sb_public_extract_sections_result($result): array
    {
        if (!is_array($result)) {
            return [];
        }

        if (isset($result['sections']) && is_array($result['sections'])) {
            return $result['sections'];
        }

        if (isset($result['data']['sections']) && is_array($result['data']['sections'])) {
            return $result['data']['sections'];
        }

        return $result;
    }
}

if (!function_exists('sb_public_page_sections')) {
    function sb_public_page_sections(int $siteId, int $pageId): array
    {
        if ($siteId <= 0 || $pageId <= 0) {
            return [];
        }

        $sections = [];

        if (function_exists('sb_read_page_sections')) {
            try {
                $sections = sb_read_page_sections();
            } catch (Throwable $e) {
                $sections = [];
            }
        }

        if (empty($sections) && function_exists('sb_read_json_file')) {
            foreach (['page_sections.json', 'pageSections.json'] as $jsonFile) {
                try {
                    $rows = sb_read_json_file($jsonFile);
                    if (is_array($rows) && !empty($rows)) {
                        $sections = $rows;
                        break;
                    }
                } catch (Throwable $e) {
                }
            }
        }

        $repoFile = __DIR__ . '/PageSectionRepository.php';
        if (empty($sections) && file_exists($repoFile)) {
            require_once $repoFile;
        }

        if (empty($sections) && class_exists('PageSectionRepository')) {
            $methods = [
                'listByPage',
                'getByPage',
                'getList',
                'getByPageId',
                'getForPage',
                'findByPage',
                'forPage',
                'pageSections',
            ];

            foreach ($methods as $method) {
                if (!method_exists('PageSectionRepository', $method)) {
                    continue;
                }

                foreach ([[ $siteId, $pageId ], [ $pageId ]] as $args) {
                    try {
                        $result = call_user_func_array(['PageSectionRepository', $method], $args);
                        $rows = sb_public_extract_sections_result($result);
                        if (!empty($rows)) {
                            $sections = $rows;
                            break 2;
                        }
                    } catch (Throwable $e) {
                    }
                }
            }
        }

        if (empty($sections) && function_exists('sb_db_fetch_all')) {
            $queries = [
                "SELECT id, site_id, page_id, title, sort, layout, props FROM sitebuilder.page_section WHERE page_id = :page_id ORDER BY sort ASC, id ASC",
                "SELECT id, site_id, page_id, title, sort, layout_json AS layout, props_json AS props FROM sitebuilder.page_section WHERE page_id = :page_id ORDER BY sort ASC, id ASC",
                "SELECT id, site_id, page_id, title, sort, layout, props FROM sitebuilder.page_sections WHERE page_id = :page_id ORDER BY sort ASC, id ASC",
                "SELECT id, site_id, page_id, title, sort, layout_json AS layout, props_json AS props FROM sitebuilder.page_sections WHERE page_id = :page_id ORDER BY sort ASC, id ASC",
            ];

            foreach ($queries as $sql) {
                try {
                    $rows = sb_db_fetch_all($sql, [':page_id' => $pageId]);
                    if (is_array($rows) && !empty($rows)) {
                        $sections = $rows;
                        break;
                    }
                } catch (Throwable $e) {
                }
            }
        }

        if (!is_array($sections)) {
            return [];
        }

        $sections = array_values(array_filter($sections, static function ($section) use ($siteId, $pageId) {
            if (!is_array($section)) {
                return false;
            }

            $sectionPageId = (int)($section['pageId'] ?? $section['page_id'] ?? 0);
            $sectionSiteId = (int)($section['siteId'] ?? $section['site_id'] ?? 0);

            if ($sectionPageId > 0 && $sectionPageId !== $pageId) {
                return false;
            }

            if ($sectionSiteId > 0 && $sectionSiteId !== $siteId) {
                return false;
            }

            return true;
        }));

        $sections = array_map(static function ($section) use ($siteId, $pageId) {
            return sb_public_normalize_page_section($section, $siteId, $pageId);
        }, $sections);

        usort($sections, static function ($a, $b) {
            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);

            if ($sortCmp !== 0) {
                return $sortCmp;
            }

            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return $sections;
    }
}

if (!function_exists('sb_public_block_section_id')) {
    function sb_public_block_section_id(array $block): int
    {
        $props = sb_public_to_array($block['props'] ?? []);
        $placement = sb_public_to_array($props['_placement'] ?? []);

        $sectionId = (int)($block['sectionId'] ?? $block['section_id'] ?? 0);

        if ($sectionId <= 0) {
            $sectionId = (int)($props['sectionId'] ?? $props['section_id'] ?? 0);
        }

        if ($sectionId <= 0) {
            $sectionId = (int)($placement['sectionId'] ?? $placement['section_id'] ?? 0);
        }

        return $sectionId;
    }
}

if (!function_exists('sb_public_block_column')) {
    function sb_public_block_column(array $block): int
    {
        $props = sb_public_to_array($block['props'] ?? []);
        $placement = sb_public_to_array($props['_placement'] ?? []);

        $column = (int)($block['column'] ?? 0);

        if ($column <= 0) {
            $column = (int)($props['column'] ?? 0);
        }

        if ($column <= 0) {
            $column = (int)($placement['column'] ?? 0);
        }

        return $column > 0 ? $column : 1;
    }
}

if (!function_exists('sb_public_group_blocks_by_section')) {
    function sb_public_group_blocks_by_section(array $pageBlocks, array $sections): array
    {
        $result = [];
        $firstSectionId = 0;

        foreach ($sections as $section) {
            $sectionId = (int)($section['id'] ?? 0);

            if ($sectionId <= 0) {
                continue;
            }

            if ($firstSectionId <= 0) {
                $firstSectionId = $sectionId;
            }

            $result[$sectionId] = [];
        }

        foreach ($pageBlocks as $block) {
            $sectionId = sb_public_block_section_id($block);

            if ($sectionId <= 0 || !isset($result[$sectionId])) {
                $sectionId = $firstSectionId;
            }

            if ($sectionId > 0 && isset($result[$sectionId])) {
                $result[$sectionId][] = $block;
            }
        }

        return $result;
    }
}

if (!function_exists('sb_public_group_blocks_by_column')) {
    function sb_public_group_blocks_by_column(array $blocks, int $columns): array
    {
        $columns = max(1, min(4, $columns));
        $result = [];

        for ($i = 1; $i <= $columns; $i++) {
            $result[$i] = [];
        }

        foreach ($blocks as $block) {
            $column = sb_public_block_column($block);
            $column = max(1, min($columns, $column));
            $result[$column][] = $block;
        }

        return $result;
    }
}

if (!function_exists('sb_public_render_page_sections')) {
    function sb_public_render_page_sections(array $sections, array $pageBlocks, array $context = []): string
    {
        if (empty($sections)) {
            return sb_public_render_blocks($pageBlocks, $context);
        }

        $blocksBySection = sb_public_group_blocks_by_section($pageBlocks, $sections);
        $html = '<div class="sb-page-sections">';

        foreach ($sections as $section) {
            $sectionId = (int)($section['id'] ?? 0);

            if ($sectionId <= 0) {
                continue;
            }

            $layout = sb_public_to_array($section['layout'] ?? []);
            $columns = sb_public_clamp_int($layout['columns'] ?? 1, 1, 4);
            $gap = sb_public_clamp_int($layout['gap'] ?? 24, 0, 120);

            $sectionBlocks = $blocksBySection[$sectionId] ?? [];
            $columnBlocks = sb_public_group_blocks_by_column($sectionBlocks, $columns);

            $html .= '<section class="sb-page-section sb-page-section--columns-' . $columns . '">';
            $html .= '<div class="sb-page-section__grid" style="display:grid;grid-template-columns:repeat(' . $columns . ',minmax(0,1fr));gap:' . $gap . 'px;width:100%;min-width:0;align-items:start;box-sizing:border-box;">';

            for ($column = 1; $column <= $columns; $column++) {
                $html .= '<div class="sb-page-section__column sb-page-section__column--' . $column . '" style="min-width:0;box-sizing:border-box;">';
                $html .= sb_public_render_blocks($columnBlocks[$column] ?? [], $context);
                $html .= '</div>';
            }

            $html .= '</div>';
            $html .= '</section>';
        }

        $html .= '</div>';
        return $html;
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

        $rawContent = $block['content'] ?? [];
        $rawProps = $block['props'] ?? [];

        $block = sb_normalize_block_record($block);
        $content = sb_public_to_array($rawContent);
        $props = sb_public_to_array($rawProps);

        $block['content'] = $content;
        $block['props'] = $props;

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
        $pageSections = $currentPage ? sb_public_page_sections($siteId, (int)$currentPage['id']) : [];

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
            'pageSections' => $pageSections,
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