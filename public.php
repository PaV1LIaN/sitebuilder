<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/storage_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/storage_db_extra.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/response.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessRepository.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/routes.php';

sitebuilder_require_auth();

header('Content-Type: text/html; charset=UTF-8');

$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$siteId = 0;
$pageId = null;
$routeResolved = false;

$siteSlug = trim((string)($_GET['sbSiteSlug'] ?? ''));
$pagePath = trim((string)($_GET['sbPagePath'] ?? ''), '/');

if ($siteSlug !== '') {
    $site = sb_route_find_site_by_slug($siteSlug);

    if ($site) {
        $siteId = (int)($site['id'] ?? 0);
        $routeResolved = $siteId > 0;

        if ($routeResolved && $pagePath !== '') {
            $page = sb_route_find_page_by_path($siteId, $pagePath);

            if ($page) {
                $pageId = (int)($page['id'] ?? 0);
            } else {
                $routeResolved = false;
            }
        }
    }
} else {
    // Совместимость со старыми ссылками. Ниже они будут перенаправлены на ЧПУ.
    $siteId = (int)($_GET['siteId'] ?? 0);
    $pageId = isset($_GET['pageId']) ? (int)$_GET['pageId'] : null;
    $routeResolved = $siteId > 0;
}

require_once __DIR__ . '/lib/public_render.php';

$vm = $routeResolved && $siteId > 0
    ? sb_public_build_view_model($siteId, $pageId, $basePath)
    : null;

if (!$vm) {
    http_response_code(404);
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title>SiteBuilder / Public</title>
    </head>
    <body style="font-family:Arial,sans-serif;padding:24px;">
        <h1>Сайт или страница не найдены</h1>
        <p><a href="<?= sb_public_h($basePath) ?>/index.php">К списку сайтов</a></p>
    </body>
    </html>
    <?php
    exit;
}

/*
 * Старые ссылки вида public.php?siteId=...&pageId=... продолжают работать,
 * но браузер сразу получает адрес без числовых идентификаторов.
 */
$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
$legacyPublicPath = rtrim($basePath, '/') . '/public.php';

if ($siteSlug === '' && $requestPath === $legacyPublicPath) {
    $targetUrl = sb_route_public_url($basePath, $siteId, $pageId);

    if ($targetUrl !== '') {
        $query = $_GET;
        unset($query['siteId'], $query['pageId'], $query['sbSiteSlug'], $query['sbPagePath']);

        if (!empty($query)) {
            $targetUrl .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        header('Location: ' . $targetUrl, true, 302);
        exit;
    }
}

include __DIR__ . '/views/layout/public_page.php';
