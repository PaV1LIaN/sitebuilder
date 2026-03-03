<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;

global $USER;

if (!$USER->IsAuthorized()) {
    http_response_code(401);
    echo 'NOT_AUTHORIZED';
    exit;
}

if (!Loader::includeModule('disk')) {
    http_response_code(500);
    echo 'DISK_NOT_INSTALLED';
    exit;
}

$siteId = (int)($_GET['siteId'] ?? 0);
$fileId = (int)($_GET['fileId'] ?? 0);

if ($siteId <= 0 || $fileId <= 0) {
    http_response_code(422);
    echo 'BAD_PARAMS';
    exit;
}

/** ---------------- helpers: same access rules as api.php ---------------- */

function sb_data_path(string $file): string {
    return $_SERVER['DOCUMENT_ROOT'] . '/upload/sitebuilder/' . $file;
}

function sb_read_json_file(string $file): array {
    $path = sb_data_path($file);
    if (!file_exists($path)) return [];
    $raw = file_get_contents($path);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function sb_user_access_code(): string {
    return 'U' . (int)$GLOBALS['USER']->GetID();
}

function sb_get_role(int $siteId, string $accessCode): ?string {
    $access = sb_read_json_file('access.json');
    foreach ($access as $r) {
        if ((int)($r['siteId'] ?? 0) === $siteId && (string)($r['accessCode'] ?? '') === $accessCode) {
            $role = strtoupper((string)($r['role'] ?? ''));
            return $role !== '' ? $role : null;
        }
    }
    return null;
}

function sb_role_rank(?string $role): int {
    $role = strtoupper((string)$role);
    return match ($role) {
        'OWNER'  => 4,
        'ADMIN'  => 3,
        'EDITOR' => 2,
        'VIEWER' => 1,
        default  => 0,
    };
}

function sb_require_viewer(int $siteId): void {
    $role = sb_get_role($siteId, sb_user_access_code());
    if (sb_role_rank($role) < 1) {
        http_response_code(403);
        echo 'FORBIDDEN';
        exit;
    }
}

function sb_site_disk_folder_id(int $siteId): int {
    $sites = sb_read_json_file('sites.json');
    foreach ($sites as $s) {
        if ((int)($s['id'] ?? 0) === $siteId) {
            return (int)($s['diskFolderId'] ?? 0);
        }
    }
    return 0;
}

/** ---------------- access check ---------------- */

sb_require_viewer($siteId);

/** ---------------- load site folder ---------------- */

$folderId = sb_site_disk_folder_id($siteId);
if ($folderId <= 0) {
    http_response_code(404);
    echo 'SITE_FOLDER_NOT_FOUND';
    exit;
}

$folder = Folder::loadById($folderId);
if (!$folder) {
    http_response_code(404);
    echo 'SITE_FOLDER_NOT_FOUND';
    exit;
}

/** ---------------- load file + ensure it belongs to folder ---------------- */

$file = File::loadById($fileId);
if (!$file) {
    http_response_code(404);
    echo 'FILE_NOT_FOUND';
    exit;
}

if ((int)$file->getParentId() !== (int)$folder->getId()) {
    http_response_code(403);
    echo 'FOREIGN_FILE';
    exit;
}

/** ---------------- get physical file id robustly ---------------- */

$realFileId = 0;

// 1) Prefer getFileId() if exists
if (method_exists($file, 'getFileId')) {
    $realFileId = (int)$file->getFileId();
}

// 2) Fallback: getFile() may return array or object depending on version
if ($realFileId <= 0 && method_exists($file, 'getFile')) {
    $src = $file->getFile();

    if (is_array($src)) {
        $realFileId = (int)($src['ID'] ?? $src['Id'] ?? $src['id'] ?? 0);
    } elseif (is_object($src) && method_exists($src, 'getId')) {
        $realFileId = (int)$src->getId();
    }
}

if ($realFileId <= 0) {
    http_response_code(500);
    echo 'FILE_SOURCE_NOT_FOUND';
    exit;
}

/** ---------------- stream physical file ---------------- */

$rel = \CFile::GetPath($realFileId);
if (!is_string($rel) || $rel === '') {
    http_response_code(404);
    echo 'FILE_NOT_ON_DISK';
    exit;
}

$abs = $_SERVER['DOCUMENT_ROOT'] . $rel;
if (!is_file($abs)) {
    http_response_code(404);
    echo 'FILE_NOT_ON_DISK';
    exit;
}

$downloadName = (string)$file->getName();

// mime (best effort)
$mime = 'application/octet-stream';
if (function_exists('mime_content_type')) {
    $m = @mime_content_type($abs);
    if (is_string($m) && $m !== '') $mime = $m;
}

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');

readfile($abs);
exit;