<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage_db.php';
require_once __DIR__ . '/helpers.php';

if (!function_exists('sb_route_effective_slug')) {
    function sb_route_effective_slug(array $record, string $titleKey): string
    {
        $slug = trim((string)($record['slug'] ?? ''));

        if ($slug === '') {
            $slug = sb_slugify((string)($record[$titleKey] ?? ''));
        }

        $slug = trim($slug, "/ \t\n\r\0\x0B");

        if ($slug === '' || $slug === '.' || $slug === '..') {
            return '';
        }

        if (preg_match('/[\x00-\x1F\x7F\/\\\\?#]/u', $slug)) {
            return '';
        }

        return $slug;
    }
}

if (!function_exists('sb_route_slug_equals')) {
    function sb_route_slug_equals(string $left, string $right): bool
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($left, 'UTF-8') === mb_strtolower($right, 'UTF-8');
        }

        return strtolower($left) === strtolower($right);
    }
}

if (!function_exists('sb_route_encode_segment')) {
    function sb_route_encode_segment(string $segment): string
    {
        return rawurlencode($segment);
    }
}

if (!function_exists('sb_route_find_site_by_id')) {
    function sb_route_find_site_by_id(int $siteId): ?array
    {
        if ($siteId <= 0) {
            return null;
        }

        foreach (sb_read_sites() as $site) {
            if ((int)($site['id'] ?? 0) === $siteId) {
                return $site;
            }
        }

        return null;
    }
}

if (!function_exists('sb_route_find_site_by_slug')) {
    function sb_route_find_site_by_slug(string $slug): ?array
    {
        $slug = trim(rawurldecode($slug));

        if ($slug === '') {
            return null;
        }

        $match = null;

        foreach (sb_read_sites() as $site) {
            $candidate = sb_route_effective_slug($site, 'name');

            if ($candidate === '' || !sb_route_slug_equals($candidate, $slug)) {
                continue;
            }

            // Два одинаковых slug дают неоднозначный URL. В таком случае не угадываем.
            if ($match !== null) {
                return null;
            }

            $match = $site;
        }

        return $match;
    }
}

if (!function_exists('sb_route_pages_for_site')) {
    function sb_route_pages_for_site(int $siteId): array
    {
        $pages = [];

        foreach (sb_read_pages() as $page) {
            if ((int)($page['siteId'] ?? 0) !== $siteId) {
                continue;
            }

            $pageId = (int)($page['id'] ?? 0);
            if ($pageId <= 0) {
                continue;
            }

            $pages[$pageId] = $page;
        }

        return $pages;
    }
}

if (!function_exists('sb_route_find_page_by_path')) {
    function sb_route_find_page_by_path(int $siteId, string $pagePath): ?array
    {
        $pagePath = trim($pagePath, '/');

        if ($siteId <= 0 || $pagePath === '') {
            return null;
        }

        $rawSegments = explode('/', $pagePath);
        if (count($rawSegments) > 64) {
            return null;
        }

        $segments = [];
        foreach ($rawSegments as $rawSegment) {
            if ($rawSegment === '') {
                continue;
            }

            $segment = rawurldecode($rawSegment);
            if (
                $segment === ''
                || strlen($segment) > 255
                || str_contains($segment, '/')
                || str_contains($segment, '\\')
                || preg_match('/[\x00-\x1F\x7F?#]/u', $segment)
            ) {
                return null;
            }

            $segments[] = $segment;
        }

        if (empty($segments)) {
            return null;
        }

        $pages = sb_route_pages_for_site($siteId);
        $parentId = 0;
        $current = null;

        foreach ($segments as $segment) {
            $matches = [];

            foreach ($pages as $page) {
                if ((int)($page['parentId'] ?? 0) !== $parentId) {
                    continue;
                }

                $candidate = sb_route_effective_slug($page, 'title');
                if ($candidate !== '' && sb_route_slug_equals($candidate, $segment)) {
                    $matches[] = $page;
                }
            }

            // Не допускаем случайного выбора при дубликате sibling-slug.
            if (count($matches) !== 1) {
                return null;
            }

            $current = $matches[0];
            $parentId = (int)($current['id'] ?? 0);
        }

        return $current;
    }
}

if (!function_exists('sb_route_page_path')) {
    function sb_route_page_path(int $siteId, int $pageId): ?string
    {
        if ($siteId <= 0 || $pageId <= 0) {
            return null;
        }

        $site = sb_route_find_site_by_id($siteId);
        if (!$site) {
            return null;
        }

        // Домашняя страница канонически открывается по корню сайта.
        if ((int)($site['homePageId'] ?? 0) === $pageId) {
            return '';
        }

        $pages = sb_route_pages_for_site($siteId);
        if (!isset($pages[$pageId])) {
            return null;
        }

        $segments = [];
        $currentId = $pageId;
        $visited = [];
        $safety = 0;

        while ($currentId > 0 && $safety < 64) {
            if (isset($visited[$currentId]) || !isset($pages[$currentId])) {
                return null;
            }

            $visited[$currentId] = true;
            $page = $pages[$currentId];
            $slug = sb_route_effective_slug($page, 'title');

            if ($slug === '') {
                return null;
            }

            array_unshift($segments, sb_route_encode_segment($slug));
            $currentId = (int)($page['parentId'] ?? 0);
            $safety++;
        }

        if ($currentId > 0) {
            return null;
        }

        return implode('/', $segments);
    }
}

if (!function_exists('sb_public_site_url')) {
    function sb_public_site_url(string $basePath, int $siteId): string
    {
        $site = sb_route_find_site_by_id($siteId);
        if (!$site) {
            return '';
        }

        $slug = sb_route_effective_slug($site, 'name');
        if ($slug === '') {
            return '';
        }

        return rtrim($basePath, '/') . '/s/' . sb_route_encode_segment($slug) . '/';
    }
}

/*
 * В public_render.php функция объявлена через function_exists().
 * Поэтому этот вариант становится единым генератором публичных URL.
 */
if (!function_exists('sb_public_page_url')) {
    function sb_public_page_url(string $basePath, int $siteId, int $pageId): string
    {
        $siteUrl = sb_public_site_url($basePath, $siteId);
        if ($siteUrl === '') {
            return '';
        }

        $pagePath = sb_route_page_path($siteId, $pageId);
        if ($pagePath === null || $pagePath === '') {
            return $siteUrl;
        }

        return $siteUrl . $pagePath . '/';
    }
}

if (!function_exists('sb_route_public_url')) {
    function sb_route_public_url(string $basePath, int $siteId, ?int $pageId = null): string
    {
        if ($pageId !== null && $pageId > 0) {
            return sb_public_page_url($basePath, $siteId, $pageId);
        }

        return sb_public_site_url($basePath, $siteId);
    }
}
