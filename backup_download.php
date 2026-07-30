<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/helpers.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/BackupService.php';

sitebuilder_require_auth();
global $USER;

$backupId = (int)($_GET['id'] ?? 0);
if ($backupId <= 0) {
    http_response_code(403);
    die('Доступ запрещён.');
}

try {
    $data = BackupService::downloadPath($backupId);
    $record = $data['record'];
    $siteId = (int)$record['originalSiteId'];
    if (!$USER->IsAdmin()) {
        sb_require_content_manager($siteId);
    }
    $path = (string)$data['path'];
    $downloadName = sprintf(
        'sitebuilder-%s-%d-%s',
        preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)$record['siteSlug']) ?: 'site',
        $backupId,
        str_ends_with($path, '.gz') ? 'backup.json.gz' : 'backup.json'
    );
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($path);
    exit;
} catch (Throwable $e) {
    error_log('SiteBuilder backup download failed: ' . $e->getMessage());
    http_response_code(404);
    die('Резервная копия недоступна.');
}
