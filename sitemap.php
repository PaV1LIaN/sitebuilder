<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/storage_db.php';
require_once __DIR__ . '/lib/helpers.php';

$siteId = (int)($_GET['siteId'] ?? 0);
$site = $siteId > 0 ? sb_find_site($siteId) : null;
if (!$site) {
    http_response_code(404);
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach (sb_pages_for_site($siteId) as $page) {
    if ((string)($page['status'] ?? '') !== 'published') continue;
    $seo = is_array($page['seo'] ?? null) ? $page['seo'] : [];
    if (array_key_exists('robotsIndex', $seo) && empty($seo['robotsIndex'])) continue;
    $url = $scheme . '://' . $host . $basePath . '/public.php?siteId=' . $siteId . '&pageId=' . (int)$page['id'];
    echo '<url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
    if (!empty($page['updatedAt'])) echo '<lastmod>' . htmlspecialchars(date(DATE_ATOM, strtotime((string)$page['updatedAt'])), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>';
    echo '</url>';
}
echo '</urlset>';
