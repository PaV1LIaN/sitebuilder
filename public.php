<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

global $USER;

// Пока public не “по-настоящему публичный” — держим под авторизацией
if (!$USER->IsAuthorized()) {
    LocalRedirect('/auth/');
}

$siteId = (int)($_GET['siteId'] ?? 0);

// совместимость со старым вариантом:
// public.php?siteId=1&p=about
$slug = trim((string)($_GET['p'] ?? ''));

// совместимость с “прямым” открытием:
// public.php?siteId=1&pageId=10
$pageId = (int)($_GET['pageId'] ?? 0);

function sb_data_path(string $file): string {
    return $_SERVER['DOCUMENT_ROOT'] . '/upload/sitebuilder/' . $file;
}
function sb_read_json(string $file): array {
    $path = sb_data_path($file);
    if (!file_exists($path)) return [];
    $raw = file_get_contents($path);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

if ($siteId <= 0) { http_response_code(404); echo 'SITE_ID_REQUIRED'; exit; }

$sites = sb_read_json('sites.json');
$pages = sb_read_json('pages.json');

$site = null;
foreach ($sites as $s) {
    if ((int)($s['id'] ?? 0) === $siteId) { $site = $s; break; }
}
if (!$site) { http_response_code(404); echo 'SITE_NOT_FOUND'; exit; }

// --- 1) Явный pageId ---
$targetPageId = 0;
if ($pageId > 0) {
    foreach ($pages as $p) {
        if ((int)($p['id'] ?? 0) === $pageId && (int)($p['siteId'] ?? 0) === $siteId) {
            $targetPageId = $pageId;
            break;
        }
    }
}

// --- 2) slug p=... ---
if ($targetPageId <= 0 && $slug !== '') {
    foreach ($pages as $p) {
        if ((int)($p['siteId'] ?? 0) === $siteId && (string)($p['slug'] ?? '') === $slug) {
            $targetPageId = (int)($p['id'] ?? 0);
            break;
        }
    }
}

// --- 3) homePageId ---
if ($targetPageId <= 0) {
    $home = (int)($site['homePageId'] ?? 0);
    if ($home > 0) {
        foreach ($pages as $p) {
            if ((int)($p['id'] ?? 0) === $home && (int)($p['siteId'] ?? 0) === $siteId) {
                $targetPageId = $home;
                break;
            }
        }
    }
}

// --- 4) первая корневая (parentId=0) по sort/id ---
if ($targetPageId <= 0) {
    $rootPages = array_values(array_filter($pages, function($p) use ($siteId){
        return (int)($p['siteId'] ?? 0) === $siteId && (int)($p['parentId'] ?? 0) === 0;
    }));

    usort($rootPages, function($a, $b){
        $sa = (int)($a['sort'] ?? 500);
        $sb = (int)($b['sort'] ?? 500);
        if ($sa === $sb) return ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
        return $sa <=> $sb;
    });

    if ($rootPages) $targetPageId = (int)($rootPages[0]['id'] ?? 0);
}

// --- 5) fallback: вообще первая страница сайта ---
if ($targetPageId <= 0) {
    $sitePages = array_values(array_filter($pages, fn($p)=> (int)($p['siteId'] ?? 0) === $siteId));
    usort($sitePages, function($a, $b){
        $sa = (int)($a['sort'] ?? 500);
        $sb = (int)($b['sort'] ?? 500);
        if ($sa === $sb) return ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
        return $sa <=> $sb;
    });
    if ($sitePages) $targetPageId = (int)($sitePages[0]['id'] ?? 0);
}

if ($targetPageId <= 0) { http_response_code(404); echo 'NO_PAGES'; exit; }

// Сейчас используем единый рендерер:
// позже поменяем на public_view.php (для настоящего публичного режима)
LocalRedirect('/local/sitebuilder/view.php?siteId='.$siteId.'&pageId='.$targetPageId);