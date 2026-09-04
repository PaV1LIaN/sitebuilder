<?php

declare(strict_types=1);

/*
 * Public SiteBuilder URLs are intentionally independent from database IDs.
 * A site is addressed by its slug and a page by the complete chain of page
 * slugs. The complete chain is required because page slugs are unique only
 * among siblings.
 */

if (!function_exists('sb_public_route_catalog')) {
    function sb_public_route_catalog(): array
    {
        static $catalog = null;

        if (is_array($catalog)) {
            return $catalog;
        }

        $sitesById = [];
        $sitesBySlug = [];

        foreach (sb_read_sites() as $site) {
            if (!is_array($site)) {
                continue;
            }

            $siteId = (int)($site['id'] ?? 0);
            $slug = trim((string)($site['slug'] ?? ''));

            if ($siteId <= 0 || $slug === '') {
                continue;
            }

            $sitesById[$siteId] = $site;
            $slugKey = mb_strtolower($slug);

            if (array_key_exists($slugKey, $sitesBySlug)) {
                $sitesBySlug[$slugKey] = null;
            } else {
                $sitesBySlug[$slugKey] = $site;
            }
        }

        $pagesById = [];
        $childrenBySiteAndParent = [];

        foreach (sb_read_pages() as $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageId = (int)($page['id'] ?? 0);
            $siteId = (int)($page['siteId'] ?? 0);
            $parentId = (int)($page['parentId'] ?? 0);
            $slug = trim((string)($page['slug'] ?? ''));

            if ($pageId <= 0 || $siteId <= 0 || $slug === '') {
                continue;
            }

            $pagesById[$pageId] = $page;
            $key = $siteId . ':' . $parentId;

            if (!isset($childrenBySiteAndParent[$key])) {
                $childrenBySiteAndParent[$key] = [];
            }

            $slugKey = mb_strtolower($slug);

            if (array_key_exists($slugKey, $childrenBySiteAndParent[$key])) {
                $childrenBySiteAndParent[$key][$slugKey] = null;
            } else {
                $childrenBySiteAndParent[$key][$slugKey] = $page;
            }
        }

        $catalog = [
            'sitesById' => $sitesById,
            'sitesBySlug' => $sitesBySlug,
            'pagesById' => $pagesById,
            'childrenBySiteAndParent' => $childrenBySiteAndParent,
        ];

        return $catalog;
    }
}

if (!function_exists('sb_public_route_segment')) {
    function sb_public_route_segment(string $slug): string
    {
        return rawurlencode(trim($slug));
    }
}

if (!function_exists('sb_public_site_url')) {
    function sb_public_site_url(string $basePath, $site): string
    {
        $catalog = sb_public_route_catalog();

        if (!is_array($site)) {
            $site = $catalog['sitesById'][(int)$site] ?? null;
        }

        if (!is_array($site)) {
            return '#';
        }

        $slug = trim((string)($site['slug'] ?? ''));

        if ($slug === '') {
            return '#';
        }

        return rtrim($basePath, '/')
            . '/s/'
            . sb_public_route_segment($slug)
            . '/';
    }
}

if (!function_exists('sb_public_page_path')) {
    function sb_public_page_path(int $siteId, int $pageId): ?string
    {
        $catalog = sb_public_route_catalog();
        $segments = [];
        $visited = [];
        $cursorId = $pageId;

        while ($cursorId > 0) {
            if (isset($visited[$cursorId])) {
                return null;
            }

            $visited[$cursorId] = true;
            $page = $catalog['pagesById'][$cursorId] ?? null;

            if (!is_array($page) || (int)($page['siteId'] ?? 0) !== $siteId) {
                return null;
            }

            $slug = trim((string)($page['slug'] ?? ''));

            if ($slug === '') {
                return null;
            }

            array_unshift($segments, sb_public_route_segment($slug));
            $cursorId = (int)($page['parentId'] ?? 0);

            if (count($segments) > 1000) {
                return null;
            }
        }

        return implode('/', $segments);
    }
}

if (!function_exists('sb_public_sitemap_url')) {
    function sb_public_sitemap_url(string $basePath, $site): string
    {
        $siteUrl = sb_public_site_url($basePath, $site);
        return $siteUrl === '#' ? '#' : $siteUrl . 'sitemap.xml';
    }
}

if (!function_exists('sb_public_page_url')) {
    function sb_public_page_url(string $basePath, int $siteId, int $pageId): string
    {
        $catalog = sb_public_route_catalog();
        $site = $catalog['sitesById'][$siteId] ?? null;
        $siteUrl = sb_public_site_url($basePath, $site);

        if ($siteUrl === '#') {
            return '#';
        }

        if ((int)($site['homePageId'] ?? 0) === $pageId) {
            return $siteUrl;
        }

        $pagePath = sb_public_page_path($siteId, $pageId);

        if ($pagePath === null || $pagePath === '') {
            return '#';
        }

        return $siteUrl . $pagePath . '/';
    }
}

if (!function_exists('sb_public_resolve_route')) {
    function sb_public_resolve_route(string $siteSlug, string $pagePath = ''): ?array
    {
        $catalog = sb_public_route_catalog();
        $siteSlug = trim(rawurldecode($siteSlug));
        $site = $catalog['sitesBySlug'][mb_strtolower($siteSlug)] ?? null;

        if (!is_array($site)) {
            return null;
        }

        $siteId = (int)($site['id'] ?? 0);
        $pagePath = trim($pagePath, '/');

        if ($pagePath === '') {
            return [
                'site' => $site,
                'siteId' => $siteId,
                'pageId' => null,
            ];
        }

        $parentId = 0;
        $page = null;

        foreach (explode('/', $pagePath) as $rawSegment) {
            if ($rawSegment === '') {
                return null;
            }

            $segment = trim(rawurldecode($rawSegment));
            $key = $siteId . ':' . $parentId;
            $page = $catalog['childrenBySiteAndParent'][$key][mb_strtolower($segment)] ?? null;

            if (!is_array($page)) {
                return null;
            }

            $parentId = (int)($page['id'] ?? 0);
        }

        return [
            'site' => $site,
            'siteId' => $siteId,
            'pageId' => (int)($page['id'] ?? 0),
        ];
    }
}

if (!function_exists('sb_public_redirect_query')) {
    function sb_public_redirect_query(array $query): string
    {
        unset(
            $query['siteId'],
            $query['pageId'],
            $query['siteSlug'],
            $query['pagePath']
        );

        return $query ? '?' . http_build_query($query) : '';
    }
}
