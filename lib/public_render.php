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


if (!function_exists('sb_public_safe_color')) {
    function sb_public_safe_color($value, string $fallback = ''): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return $fallback;
        }

        if (
            preg_match('/^#[0-9a-fA-F]{3}$/', $value)
            || preg_match('/^#[0-9a-fA-F]{6}$/', $value)
            || preg_match('/^#[0-9a-fA-F]{8}$/', $value)
        ) {
            return strtolower($value);
        }

        if ($value === 'transparent') {
            return $value;
        }

        return $fallback;
    }
}

if (!function_exists('sb_public_safe_url')) {
    function sb_public_safe_url($value, bool $imageOnly = false): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        /*
         * URL затем может попасть не только в href/src, но и в inline CSS.
         * Запрещаем управляющие символы, обратный слеш и символы, которыми
         * можно преждевременно закрыть url(...) или HTML-атрибут.
         */
        if (
            preg_match('/[\x00-\x20\x7f]/u', $value)
            || str_contains($value, '\\')
            || str_contains($value, '"')
            || str_contains($value, "'")
            || str_contains($value, '(')
            || str_contains($value, ')')
            || str_contains($value, '<')
            || str_contains($value, '>')
            || str_starts_with($value, '//')
        ) {
            return '';
        }

        $parts = parse_url($value);

        if ($parts === false) {
            return '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));

        /* Относительные URL, якоря и query-string. */
        if ($scheme === '') {
            if (
                isset($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                return '';
            }

            return $value;
        }

        $allowed = $imageOnly
            ? ['http', 'https']
            : ['http', 'https', 'mailto', 'tel'];

        return in_array($scheme, $allowed, true) ? $value : '';
    }
}

if (!function_exists('sb_public_sanitize_rich_html_fallback')) {
    function sb_public_sanitize_rich_html_fallback(string $html): string
    {
        $html = preg_replace(
            '#<(script|style|iframe|object|embed|svg|math|template)\b[^>]*>.*?</\1\s*>#is',
            '',
            $html
        ) ?? '';

        $html = strip_tags(
            $html,
            '<p><br><h2><h3><h4><h5><h6><strong><b><em><i><u><s><span><ul><ol><li><a><blockquote><code><pre>'
        );

        $allowed = [
            'p' => true,
            'br' => true,
            'h2' => true,
            'h3' => true,
            'h4' => true,
            'h5' => true,
            'h6' => true,
            'strong' => true,
            'b' => true,
            'em' => true,
            'i' => true,
            'u' => true,
            's' => true,
            'span' => true,
            'ul' => true,
            'ol' => true,
            'li' => true,
            'a' => true,
            'blockquote' => true,
            'code' => true,
            'pre' => true,
        ];

        return preg_replace_callback(
            '#<\s*(/?)\s*([a-z0-9]+)\b([^>]*)>#i',
            static function (array $match) use ($allowed): string {
                $closing = ($match[1] ?? '') === '/';
                $tag = strtolower((string)($match[2] ?? ''));
                $attributes = (string)($match[3] ?? '');

                if (!isset($allowed[$tag])) {
                    return '';
                }

                if ($closing) {
                    return $tag === 'br' ? '' : '</' . $tag . '>';
                }

                if ($tag === 'br') {
                    return '<br>';
                }

                if ($tag !== 'a') {
                    return '<' . $tag . '>';
                }

                $href = '';
                $target = '_self';

                if (preg_match(
                    '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i',
                    $attributes,
                    $hrefMatch
                )) {
                    $href = html_entity_decode(
                        (string)($hrefMatch[1] ?? $hrefMatch[2] ?? $hrefMatch[3] ?? ''),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    );
                    $href = sb_public_safe_url($href);
                }

                if (preg_match(
                    '/\btarget\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i',
                    $attributes,
                    $targetMatch
                )) {
                    $target = sb_public_safe_target(
                        (string)($targetMatch[1] ?? $targetMatch[2] ?? $targetMatch[3] ?? '')
                    );
                }

                if ($href === '') {
                    $target = '_self';
                }

                $result = '<a';
                if ($href !== '') {
                    $result .= ' href="' . sb_public_h($href) . '"';
                }
                if ($target === '_blank') {
                    $result .= ' target="_blank" rel="noopener noreferrer"';
                }
                return $result . '>';
            },
            $html
        ) ?? '';
    }
}

if (!function_exists('sb_public_sanitize_rich_html')) {
    function sb_public_sanitize_rich_html($value): string
    {
        $html = trim((string)$value);

        if ($html === '') {
            return '';
        }

        if (!preg_match('/<\/?[a-z][^>]*>/i', $html)) {
            return nl2br(sb_public_h($html));
        }

        /*
         * DOMDocument доступен в большинстве коробочных установок Битрикс.
         * Если расширение отключено, безопасно показываем текст без HTML.
         */
        if (!class_exists('DOMDocument')) {
            return sb_public_sanitize_rich_html_fallback($html);
        }

        $allowedTags = [
            'p' => true,
            'br' => true,
            'h2' => true,
            'h3' => true,
            'h4' => true,
            'h5' => true,
            'h6' => true,
            'strong' => true,
            'b' => true,
            'em' => true,
            'i' => true,
            'u' => true,
            's' => true,
            'span' => true,
            'ul' => true,
            'ol' => true,
            'li' => true,
            'a' => true,
            'blockquote' => true,
            'code' => true,
            'pre' => true,
        ];
        $dropWithContent = [
            'script' => true,
            'style' => true,
            'iframe' => true,
            'object' => true,
            'embed' => true,
            'svg' => true,
            'math' => true,
            'template' => true,
        ];

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $wrapped = '<!doctype html><html><body><div id="sb-rich-root">'
                . $html
                . '</div></body></html>';

            $flags = 0;
            if (defined('LIBXML_HTML_NOIMPLIED')) {
                $flags |= LIBXML_HTML_NOIMPLIED;
            }
            if (defined('LIBXML_HTML_NODEFDTD')) {
                $flags |= LIBXML_HTML_NODEFDTD;
            }

            if (!$document->loadHTML('<?xml encoding="UTF-8">' . $wrapped, $flags)) {
                return sb_public_sanitize_rich_html_fallback($html);
            }

            $root = $document->getElementById('sb-rich-root');
            if (!$root instanceof DOMElement) {
                return sb_public_sanitize_rich_html_fallback($html);
            }

            $sanitizeNode = static function (DOMNode $node) use (&$sanitizeNode, $allowedTags, $dropWithContent): void {
                $children = [];
                foreach ($node->childNodes as $child) {
                    $children[] = $child;
                }

                foreach ($children as $child) {
                    if ($child instanceof DOMComment) {
                        $child->parentNode?->removeChild($child);
                        continue;
                    }

                    if (!$child instanceof DOMElement) {
                        continue;
                    }

                    $tag = strtolower($child->tagName);

                    if (!isset($allowedTags[$tag])) {
                        if (isset($dropWithContent[$tag])) {
                            $child->parentNode?->removeChild($child);
                            continue;
                        }

                        $sanitizeNode($child);
                        $parent = $child->parentNode;

                        if ($parent !== null) {
                            while ($child->firstChild !== null) {
                                $parent->insertBefore($child->firstChild, $child);
                            }
                            $parent->removeChild($child);
                        }
                        continue;
                    }

                    $attributeNames = [];
                    if ($child->hasAttributes()) {
                        foreach ($child->attributes as $attribute) {
                            $attributeNames[] = $attribute->name;
                        }
                    }

                    foreach ($attributeNames as $attributeName) {
                        $keep = $tag === 'a'
                            && in_array(strtolower($attributeName), ['href', 'target', 'rel'], true);

                        if (!$keep) {
                            $child->removeAttribute($attributeName);
                        }
                    }

                    if ($tag === 'a') {
                        $href = sb_public_safe_url($child->getAttribute('href'));

                        if ($href === '') {
                            $child->removeAttribute('href');
                        } else {
                            $child->setAttribute('href', $href);
                        }

                        $target = sb_public_safe_target($child->getAttribute('target'));
                        if ($target === '_blank') {
                            $child->setAttribute('target', '_blank');
                            $child->setAttribute('rel', 'noopener noreferrer');
                        } else {
                            $child->removeAttribute('target');
                            $child->removeAttribute('rel');
                        }
                    }

                    $sanitizeNode($child);
                }
            };

            $sanitizeNode($root);

            $result = '';
            foreach ($root->childNodes as $child) {
                $result .= $document->saveHTML($child);
            }

            return $result;
        } catch (Throwable $e) {
            error_log('SiteBuilder rich text sanitize failed: ' . $e->getMessage());
            return sb_public_sanitize_rich_html_fallback($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}

if (!function_exists('sb_public_safe_target')) {
    function sb_public_safe_target($value): string
    {
        return (string)$value === '_blank' ? '_blank' : '_self';
    }
}

if (!function_exists('sb_public_safe_choice')) {
    function sb_public_safe_choice($value, array $allowed, string $fallback): string
    {
        $value = (string)$value;
        return in_array($value, $allowed, true) ? $value : $fallback;
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

if (!function_exists('sb_public_page_is_visible')) {
    function sb_public_page_is_visible(array $page, array $pageMap): bool
    {
        $status = strtolower(trim((string)($page['status'] ?? 'draft')));

        if ($status !== 'published') {
            return false;
        }

        $currentPage = $page;
        $visited = [];

        while (true) {
            $currentId = (int)($currentPage['id'] ?? 0);

            if ($currentId <= 0 || isset($visited[$currentId])) {
                return false;
            }

            $visited[$currentId] = true;

            $parentId = (int)($currentPage['parentId'] ?? 0);

            if ($parentId <= 0) {
                return true;
            }

            if (!isset($pageMap[$parentId])) {
                return false;
            }

            $parentPage = $pageMap[$parentId];
            $parentStatus = strtolower(
                trim((string)($parentPage['status'] ?? 'draft'))
            );

            if ($parentStatus !== 'published') {
                return false;
            }

            $currentPage = $parentPage;
        }
    }
}

if (!function_exists('sb_public_pages_for_site')) {
    function sb_public_pages_for_site(int $siteId): array
    {
        $pages = [];

        foreach (sb_read_pages() as $page) {
            if (!is_array($page)) {
                continue;
            }

            if ((int)($page['siteId'] ?? 0) !== $siteId) {
                continue;
            }

            $page = sb_normalize_page_record($page);
            $pageId = (int)($page['id'] ?? 0);

            if ($pageId <= 0) {
                continue;
            }

            $pages[$pageId] = $page;
        }

        $visiblePages = [];

        foreach ($pages as $page) {
            if (sb_public_page_is_visible($page, $pages)) {
                $visiblePages[] = $page;
            }
        }

        usort($visiblePages, static function ($a, $b) {
            $sortCmp =
                (int)($a['sort'] ?? 500)
                <=>
                (int)($b['sort'] ?? 500);

            if ($sortCmp !== 0) {
                return $sortCmp;
            }

            return
                (int)($a['id'] ?? 0)
                <=>
                (int)($b['id'] ?? 0);
        });

        /*
         * Публичный рендер SiteBuilder всё равно находится за авторизацией.
         * Поэтому опубликованный статус сам по себе не даёт доступ к странице:
         * дополнительно учитываем глобальную роль и точечные page_access права.
         */
        global $USER;

        $currentUserId = is_object($USER) ? (int)$USER->GetID() : 0;

        if (class_exists('PageAccessService') && $currentUserId > 0) {
            $visiblePages = PageAccessService::filterVisiblePages(
                $visiblePages,
                $siteId,
                $currentUserId
            );
        } else {
            $visiblePages = [];
        }

        return $visiblePages;
    }
}

if (!function_exists('sb_public_find_page_for_site')) {
    function sb_public_find_page_for_site(int $siteId, int $pageId): ?array
    {
        if ($siteId <= 0 || $pageId <= 0) {
            return null;
        }

        foreach (sb_public_pages_for_site($siteId) as $page) {
            if ((int)($page['id'] ?? 0) === $pageId) {
                return $page;
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
        $tabletColumns = sb_public_clamp_int($layout['tabletColumns'] ?? min($columns, 2), 1, $columns);
        $mobileColumns = sb_public_clamp_int($layout['mobileColumns'] ?? 1, 1, min($tabletColumns, 2));
        $gap = sb_public_clamp_int($layout['gap'] ?? 24, 0, 120);

        $container = (string)($layout['container'] ?? 'default');
        if (!in_array($container, ['default', 'wide', 'full'], true)) {
            $container = 'default';
        }

        $layout['columns'] = $columns;
        $layout['tabletColumns'] = $tabletColumns;
        $layout['mobileColumns'] = $mobileColumns;
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

        $repoFile = __DIR__ . '/PageSectionRepository.php';
        if (is_file($repoFile)) {
            require_once $repoFile;
        }

        if (!class_exists('PageSectionRepository')) {
            return [];
        }

        try {
            $sections = PageSectionRepository::listForPage($siteId, $pageId);
        } catch (Throwable $e) {
            error_log('SiteBuilder public sections load failed: ' . $e->getMessage());
            return [];
        }

        $sections = array_map(static function (array $section) use ($siteId, $pageId): array {
            return sb_public_normalize_page_section($section, $siteId, $pageId);
        }, $sections);

        usort($sections, static function (array $a, array $b): int {
            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
            return $sortCmp !== 0
                ? $sortCmp
                : (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
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
            $props = sb_public_to_array($section['props'] ?? []);

            $columns = sb_public_clamp_int($layout['columns'] ?? 1, 1, 4);
            $tabletColumns = sb_public_clamp_int(
                $layout['tabletColumns'] ?? min($columns, 2),
                1,
                $columns
            );
            $mobileColumns = sb_public_clamp_int(
                $layout['mobileColumns'] ?? 1,
                1,
                min($tabletColumns, 2)
            );
            $gap = sb_public_clamp_int($layout['gap'] ?? 24, 0, 120);
            $container = sb_public_safe_choice(
                $layout['container'] ?? 'default',
                ['default', 'wide', 'full'],
                'default'
            );
            $verticalAlign = sb_public_safe_choice(
                $layout['verticalAlign'] ?? 'start',
                ['start', 'center', 'end', 'stretch'],
                'start'
            );

            $paddingTop = sb_public_clamp_int($props['paddingTop'] ?? 32, 0, 240);
            $paddingBottom = sb_public_clamp_int($props['paddingBottom'] ?? 32, 0, 240);
            $paddingX = sb_public_clamp_int($props['paddingX'] ?? 24, 0, 160);
            $minHeight = sb_public_clamp_int($props['minHeight'] ?? 0, 0, 1200);
            $radius = sb_public_clamp_int($props['borderRadius'] ?? 0, 0, 80);
            $backgroundColor = sb_public_safe_color($props['backgroundColor'] ?? '', '');
            $textColor = sb_public_safe_color($props['textColor'] ?? '', '');
            $backgroundImage = sb_public_safe_url($props['backgroundImage'] ?? '', true);
            $backgroundPosition = sb_public_safe_choice(
                $props['backgroundPosition'] ?? 'center',
                ['center', 'top', 'bottom', 'left', 'right'],
                'center'
            );
            $backgroundSize = sb_public_safe_choice(
                $props['backgroundSize'] ?? 'cover',
                ['cover', 'contain', 'auto'],
                'cover'
            );
            $shadow = !empty($props['shadow']);
            $sectionDesign = sb_public_to_array($props['_design'] ?? []);
            [$sectionAnimation, $sectionDelay, $sectionDuration] = sb_public_design_animation($sectionDesign);

            $sectionBlocks = $blocksBySection[$sectionId] ?? [];
            $columnBlocks = sb_public_group_blocks_by_column($sectionBlocks, $columns);

            $sectionStyles = [
                '--sb-section-columns:' . $columns,
                '--sb-section-tablet-columns:' . $tabletColumns,
                '--sb-section-mobile-columns:' . $mobileColumns,
                '--sb-section-gap:' . $gap . 'px',
                '--sb-section-align:' . $verticalAlign,
                '--sb-section-padding-top:' . $paddingTop . 'px',
                '--sb-section-padding-bottom:' . $paddingBottom . 'px',
                '--sb-section-padding-x:' . $paddingX . 'px',
                '--sb-section-radius:' . $radius . 'px',
                '--sb-motion-delay:' . $sectionDelay . 'ms',
                '--sb-motion-duration:' . $sectionDuration . 'ms',
            ];

            if ($minHeight > 0) {
                $sectionStyles[] = 'min-height:' . $minHeight . 'px';
            }

            if ($backgroundColor !== '') {
                $sectionStyles[] = 'background-color:' . $backgroundColor;
            }

            if ($textColor !== '') {
                $sectionStyles[] = 'color:' . $textColor;
                $sectionStyles[] = '--sb-section-text-color:' . $textColor;
            }

            if ($backgroundImage !== '') {
                $sectionStyles[] = 'background-image:url("' . $backgroundImage . '")';
                $sectionStyles[] = 'background-position:' . $backgroundPosition;
                $sectionStyles[] = 'background-size:' . $backgroundSize;
                $sectionStyles[] = 'background-repeat:no-repeat';
            }

            if ($shadow) {
                $sectionStyles[] = 'box-shadow:0 18px 50px rgba(15,23,42,.10)';
            }

            $classes = array_merge([
                'sb-page-section',
                'sb-page-section--columns-' . $columns,
                'sb-page-section--container-' . $container,
            ], sb_public_design_classes($sectionDesign));

            $sectionAttributes = '';
            if ($sectionAnimation !== 'none') {
                $classes[] = 'sb-motion';
                $classes[] = 'sb-motion--' . $sectionAnimation;
                $sectionAttributes = ' data-sb-animate="' . sb_public_h($sectionAnimation) . '"';
            }

            if ($backgroundImage !== '') {
                $classes[] = 'sb-page-section--has-image';
            }

            if ($backgroundColor !== '' || $backgroundImage !== '' || $radius > 0 || $shadow) {
                $classes[] = 'sb-page-section--decorated';
            }

            $html .= '<section class="' . sb_public_h(implode(' ', $classes)) . '" style="' . sb_public_h(implode(';', $sectionStyles)) . '"' . $sectionAttributes . '>';
            $html .= '<div class="sb-page-section__inner">';
            $html .= '<div class="sb-page-section__grid">';

            for ($column = 1; $column <= $columns; $column++) {
                $html .= '<div class="sb-page-section__column sb-page-section__column--' . $column . '">';
                $html .= sb_public_render_blocks($columnBlocks[$column] ?? [], $context);
                $html .= '</div>';
            }

            $html .= '</div>';
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

if (!function_exists('sb_public_filter_menu_pages')) {
    function sb_public_filter_menu_pages(?array $menu, array $pages): ?array
    {
        if (!$menu) {
            return null;
        }

        $allowedPageIds = [];

        foreach ($pages as $page) {
            $pageId = (int)($page['id'] ?? 0);

            if ($pageId > 0) {
                $allowedPageIds[$pageId] = true;
            }
        }

        $items = isset($menu['items']) && is_array($menu['items'])
            ? $menu['items']
            : [];

        $menu['items'] = array_values(array_filter(
            $items,
            static function ($item) use ($allowedPageIds) {
                if (!is_array($item)) {
                    return false;
                }

                $type = (string)($item['type'] ?? 'page');

                if ($type !== 'page') {
                    return true;
                }

                $pageId = (int)($item['pageId'] ?? 0);

                return $pageId > 0 && isset($allowedPageIds[$pageId]);
            }
        ));

        return $menu;
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

if (!function_exists('sb_public_component_render_file')) {
    function sb_public_component_render_file(string $type): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/[^a-z0-9_-]/i', '', $type);

        if ($type === '') {
            $type = 'text';
        }

        $root = dirname(__DIR__);

        $candidates = [
            $root . '/components/' . $type . '/render.php',
            $root . '/views/blocks/' . $type . '.php',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        $fallbacks = [
            $root . '/components/text/render.php',
            $root . '/views/blocks/text.php',
        ];

        foreach ($fallbacks as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        throw new RuntimeException('SiteBuilder component renderer not found: ' . $type);
    }
}

if (!function_exists('sb_public_design_classes')) {
    function sb_public_design_classes(array $design): array
    {
        $classes = [];
        if (array_key_exists('desktop', $design) && !$design['desktop']) $classes[] = 'sb-hide-desktop';
        if (array_key_exists('tablet', $design) && !$design['tablet']) $classes[] = 'sb-hide-tablet';
        if (array_key_exists('mobile', $design) && !$design['mobile']) $classes[] = 'sb-hide-mobile';
        return $classes;
    }
}

if (!function_exists('sb_public_design_animation')) {
    function sb_public_design_animation(array $design): array
    {
        $animation = sb_public_safe_choice(
            $design['animation'] ?? 'none',
            ['none', 'fade', 'fade-up', 'zoom', 'slide-left', 'slide-right'],
            'none'
        );
        $delay = sb_public_clamp_int($design['animationDelay'] ?? 0, 0, 3000);
        $duration = sb_public_clamp_int($design['animationDuration'] ?? 600, 150, 3000);
        return [$animation, $delay, $duration];
    }
}


if (!function_exists('sb_public_responsive_margin_inline')) {
    function sb_public_responsive_margin_inline(string $align): string
    {
        return match ($align) {
            'center' => 'auto',
            'right' => 'auto 0',
            default => '0 auto',
        };
    }
}

if (!function_exists('sb_public_responsive_bool')) {
    function sb_public_responsive_bool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [1, '1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($value, [0, '0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }
}

if (!function_exists('sb_public_responsive_style_vars')) {
    function sb_public_responsive_style_vars(
        string $type,
        array $props
    ): array {
        $responsive = sb_public_to_array(
            $props['_responsive'] ?? []
        );

        if (!$responsive) {
            return [];
        }

        $styles = [];

        foreach (['tablet', 'mobile'] as $device) {
            $config = sb_public_to_array(
                $responsive[$device] ?? []
            );

            if (!$config) {
                continue;
            }

            $prefix = '--sb-r-' . $device . '-';

            if (
                array_key_exists('marginTop', $config)
                && $config['marginTop'] !== ''
            ) {
                $styles[] = $prefix . 'margin-top:'
                    . sb_public_clamp_int(
                        $config['marginTop'],
                        0,
                        240
                    )
                    . 'px';
            }

            if (
                array_key_exists('marginBottom', $config)
                && $config['marginBottom'] !== ''
            ) {
                $styles[] = $prefix . 'margin-bottom:'
                    . sb_public_clamp_int(
                        $config['marginBottom'],
                        0,
                        240
                    )
                    . 'px';
            }

            if ($type === 'heading') {
                if (
                    array_key_exists('size', $config)
                    && $config['size'] !== ''
                ) {
                    $styles[] = $prefix . 'size:'
                        . sb_public_clamp_int(
                            $config['size'],
                            12,
                            120
                        )
                        . 'px';
                }

                if (
                    array_key_exists('align', $config)
                    && $config['align'] !== ''
                ) {
                    $align = sb_public_safe_choice(
                        $config['align'],
                        ['left', 'center', 'right'],
                        'left'
                    );

                    $styles[] = $prefix
                        . 'align:' . $align;
                    $styles[] = $prefix
                        . 'margin-inline:'
                        . sb_public_responsive_margin_inline(
                            $align
                        );
                }

                if (
                    array_key_exists('maxWidth', $config)
                    && $config['maxWidth'] !== ''
                ) {
                    $maxWidth = sb_public_clamp_int(
                        $config['maxWidth'],
                        0,
                        1800
                    );

                    $styles[] = $prefix
                        . 'max-width:'
                        . ($maxWidth > 0
                            ? $maxWidth . 'px'
                            : 'none');
                }
            } elseif ($type === 'text') {
                if (
                    array_key_exists('size', $config)
                    && $config['size'] !== ''
                ) {
                    $styles[] = $prefix . 'size:'
                        . sb_public_clamp_int(
                            $config['size'],
                            12,
                            72
                        )
                        . 'px';
                }

                if (
                    array_key_exists('align', $config)
                    && $config['align'] !== ''
                ) {
                    $align = sb_public_safe_choice(
                        $config['align'],
                        [
                            'left',
                            'center',
                            'right',
                            'justify',
                        ],
                        'left'
                    );

                    $styles[] = $prefix
                        . 'align:' . $align;

                    if ($align !== 'justify') {
                        $styles[] = $prefix
                            . 'margin-inline:'
                            . sb_public_responsive_margin_inline(
                                $align
                            );
                    }
                }

                if (
                    array_key_exists('lineHeight', $config)
                    && $config['lineHeight'] !== ''
                    && is_numeric($config['lineHeight'])
                ) {
                    $lineHeight = max(
                        1.0,
                        min(
                            2.4,
                            (float)$config['lineHeight']
                        )
                    );

                    $styles[] = $prefix
                        . 'line-height:'
                        . rtrim(
                            rtrim(
                                number_format(
                                    $lineHeight,
                                    2,
                                    '.',
                                    ''
                                ),
                                '0'
                            ),
                            '.'
                        );
                }

                if (
                    array_key_exists('maxWidth', $config)
                    && $config['maxWidth'] !== ''
                ) {
                    $maxWidth = sb_public_clamp_int(
                        $config['maxWidth'],
                        0,
                        1800
                    );

                    $styles[] = $prefix
                        . 'max-width:'
                        . ($maxWidth > 0
                            ? $maxWidth . 'px'
                            : 'none');
                }
            } elseif ($type === 'button') {
                if (
                    array_key_exists('align', $config)
                    && $config['align'] !== ''
                ) {
                    $align = sb_public_safe_choice(
                        $config['align'],
                        ['left', 'center', 'right'],
                        'left'
                    );

                    $justify = match ($align) {
                        'center' => 'center',
                        'right' => 'flex-end',
                        default => 'flex-start',
                    };

                    $styles[] = $prefix
                        . 'align:' . $align;
                    $styles[] = $prefix
                        . 'button-display:flex';
                    $styles[] = $prefix
                        . 'button-justify:' . $justify;
                }

                if (
                    array_key_exists('fullWidth', $config)
                ) {
                    $fullWidth =
                        sb_public_responsive_bool(
                            $config['fullWidth']
                        );

                    if ($fullWidth !== null) {
                        $styles[] = $prefix
                            . 'button-width:'
                            . ($fullWidth
                                ? '100%'
                                : 'auto');
                    }
                }
            } elseif ($type === 'image') {
                if (
                    array_key_exists('width', $config)
                    && $config['width'] !== ''
                ) {
                    $styles[] = $prefix
                        . 'image-width:'
                        . sb_public_clamp_int(
                            $config['width'],
                            10,
                            100
                        )
                        . '%';
                }

                if (
                    array_key_exists('radius', $config)
                    && $config['radius'] !== ''
                ) {
                    $styles[] = $prefix
                        . 'image-radius:'
                        . sb_public_clamp_int(
                            $config['radius'],
                            0,
                            80
                        )
                        . 'px';
                }

                if (
                    array_key_exists('align', $config)
                    && $config['align'] !== ''
                ) {
                    $align = sb_public_safe_choice(
                        $config['align'],
                        ['left', 'center', 'right'],
                        'center'
                    );

                    $styles[] = $prefix
                        . 'margin-inline:'
                        . sb_public_responsive_margin_inline(
                            $align
                        );
                }
            } elseif ($type === 'hero') {
                if (
                    array_key_exists('minHeight', $config)
                    && $config['minHeight'] !== ''
                ) {
                    $styles[] = $prefix
                        . 'hero-height:'
                        . sb_public_clamp_int(
                            $config['minHeight'],
                            220,
                            900
                        )
                        . 'px';
                }

                if (
                    array_key_exists('radius', $config)
                    && $config['radius'] !== ''
                ) {
                    $styles[] = $prefix
                        . 'hero-radius:'
                        . sb_public_clamp_int(
                            $config['radius'],
                            0,
                            80
                        )
                        . 'px';
                }

                if (
                    array_key_exists('titleSize', $config)
                    && $config['titleSize'] !== ''
                ) {
                    $styles[] = $prefix
                        . 'title-size:'
                        . sb_public_clamp_int(
                            $config['titleSize'],
                            18,
                            96
                        )
                        . 'px';
                }

                if (
                    array_key_exists('align', $config)
                    && $config['align'] !== ''
                ) {
                    $align = sb_public_safe_choice(
                        $config['align'],
                        ['left', 'center'],
                        'left'
                    );

                    $styles[] = $prefix
                        . 'align:' . $align;
                    $styles[] = $prefix
                        . 'hero-justify:'
                        . ($align === 'center'
                            ? 'center'
                            : 'flex-start');
                }
            } elseif ($type === 'cards') {
                if (
                    array_key_exists('columns', $config)
                    && $config['columns'] !== ''
                ) {
                    $styles[] = $prefix
                        . 'columns:'
                        . sb_public_clamp_int(
                            $config['columns'],
                            1,
                            4
                        );
                }

                if (
                    array_key_exists('align', $config)
                    && $config['align'] !== ''
                ) {
                    $styles[] = $prefix
                        . 'align:'
                        . sb_public_safe_choice(
                            $config['align'],
                            ['left', 'center'],
                            'left'
                        );
                }
            }
        }

        return $styles;
    }
}

if (!function_exists('sb_public_render_block')) {
    function sb_public_render_block(array $block, array $context = []): string
    {
        $block = sb_normalize_block_record($block);

        $type = (string)($block['type'] ?? 'text');
        $content = (array)($block['content'] ?? []);
        $props = (array)($block['props'] ?? []);
        $contentHtml = '';

        if ($type === 'global') {
            $depth = (int)($context['_globalDepth'] ?? 0);
            $globalBlockId = (int)($content['globalBlockId'] ?? 0);
            $siteId = (int)($context['siteId'] ?? 0);

            if ($depth < 5 && $globalBlockId > 0 && $siteId > 0) {
                $serviceFile = __DIR__ . '/GlobalBlockService.php';
                if (is_file($serviceFile)) require_once $serviceFile;
                if (class_exists('GlobalBlockService')) {
                    $record = GlobalBlockService::get($globalBlockId, $siteId);
                    $savedBlock = is_array($record['payload']['block'] ?? null) ? $record['payload']['block'] : [];
                    if ($savedBlock) {
                        $savedBlock['id'] = 0;
                        $savedBlock['pageId'] = (int)($block['pageId'] ?? 0);
                        $nestedContext = $context;
                        $nestedContext['_globalDepth'] = $depth + 1;
                        $contentHtml = sb_public_render_block($savedBlock, $nestedContext);
                    }
                }
            }
        } else {
            $template = sb_public_component_render_file($type);
            ob_start();
            include $template;
            $contentHtml = (string)ob_get_clean();
        }

        $design = sb_public_to_array($props['_design'] ?? []);
        $classes = array_merge(['sb-content-block'], sb_public_design_classes($design));
        [$animation, $delay, $duration] = sb_public_design_animation($design);
        $styles = [
            '--sb-block-margin-top:' . sb_public_clamp_int($design['marginTop'] ?? 0, 0, 240) . 'px',
            '--sb-block-margin-bottom:' . sb_public_clamp_int($design['marginBottom'] ?? 0, 0, 240) . 'px',
            '--sb-motion-delay:' . $delay . 'ms',
            '--sb-motion-duration:' . $duration . 'ms',
        ];
        $attributes = '';

        $responsiveStyles =
            sb_public_responsive_style_vars(
                $type,
                $props
            );

        if ($responsiveStyles) {
            $classes[] = 'sb-responsive-block';
            $styles = array_merge(
                $styles,
                $responsiveStyles
            );
            $attributes .=
                ' data-sb-responsive-type="'
                . sb_public_h($type)
                . '"';
        }

        if ($animation !== 'none') {
            $classes[] = 'sb-motion';
            $classes[] = 'sb-motion--' . $animation;
            $attributes .= ' data-sb-animate="' . sb_public_h($animation) . '"';
        }

        return '<div class="' . sb_public_h(implode(' ', $classes)) . '" style="' . sb_public_h(implode(';', $styles)) . '"' . $attributes . '>' . $contentHtml . '</div>';
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
            $targetRaw = sb_public_safe_target($item['target'] ?? '_self');
            $target = sb_public_h($targetRaw);
            $rel = $targetRaw === '_blank' ? ' rel="noopener noreferrer"' : '';
            $html .= '<a class="sb-public-menu__link" href="' . $url . '" target="' . $target . '"' . $rel . '>' . $title . '</a>';
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
        
        $hasRequestedPage = $requestedPageId !== null && $requestedPageId > 0;
        
        if ($hasRequestedPage) {
            $currentPage = sb_public_find_page_for_site(
                $siteId,
                (int)$requestedPageId
            );
        
            if (!$currentPage) {
                return null;
            }
        }
        
        if (!$hasRequestedPage) {
            $homePageId = (int)($site['homePageId'] ?? 0);
        
            if ($homePageId > 0) {
                $currentPage = sb_public_find_page_for_site(
                    $siteId,
                    $homePageId
                );
            }
        
            if (!$currentPage && !empty($pages)) {
                $currentPage = $pages[0];
            }
        }
        $layout = sb_public_layout_for_site($siteId);

        $menu = sb_public_filter_menu_pages(
            sb_public_menu_for_site($site),
            $pages
        );
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