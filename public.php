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
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/public_routes.php';

sitebuilder_require_auth();

header('Content-Type: text/html; charset=UTF-8');

$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$isCleanRoute = array_key_exists('siteSlug', $_GET);
$route = $isCleanRoute
    ? sb_public_resolve_route(
        (string)($_GET['siteSlug'] ?? ''),
        (string)($_GET['pagePath'] ?? '')
    )
    : null;

$siteId = $isCleanRoute
    ? (int)($route['siteId'] ?? 0)
    : (int)($_GET['siteId'] ?? 0);

$pageId = $isCleanRoute
    ? ($route['pageId'] ?? null)
    : (isset($_GET['pageId']) ? (int)$_GET['pageId'] : null);

require_once __DIR__ . '/lib/public_render.php';

$vm = $siteId > 0 ? sb_public_build_view_model($siteId, $pageId, $basePath) : null;

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

$currentPageId = (int)($vm['currentPage']['id'] ?? 0);
$canonicalPath = $currentPageId > 0
    ? sb_public_page_url($basePath, $siteId, $currentPageId)
    : sb_public_site_url($basePath, $siteId);

$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');

if (
    $canonicalPath !== '#'
    && (
        !$isCleanRoute
        || $requestPath !== $canonicalPath
    )
) {
    header(
        'Location: '
        . $canonicalPath
        . sb_public_redirect_query($_GET),
        true,
        301
    );
    exit;
}

include __DIR__ . '/views/layout/public_page.php';
