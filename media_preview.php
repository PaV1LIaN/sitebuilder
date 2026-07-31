<?php

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/lib.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/access.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/disk.php';

use Bitrix\Disk\File;

sitebuilder_require_auth();

try {
    $siteId = (int)($_GET['siteId'] ?? 0);
    $fileId = (int)($_GET['fileId'] ?? 0);

    if ($siteId <= 0 || $fileId <= 0) {
        throw new RuntimeException('INVALID_MEDIA_REQUEST');
    }

    sb_require_viewer($siteId);

    if (!sb_disk_file_belongs_to_site($siteId, $fileId)) {
        throw new RuntimeException('MEDIA_NOT_IN_SITE');
    }

    $file = sb_disk_load_file_by_id($fileId);
    if (!$file instanceof File) {
        throw new RuntimeException('MEDIA_NOT_FOUND');
    }

    $fileArray = $file->getFile();
    $relativePath = is_array($fileArray) ? (string)($fileArray['SRC'] ?? '') : '';
    $absolutePath = $relativePath !== '' ? $_SERVER['DOCUMENT_ROOT'] . $relativePath : '';

    if ($absolutePath === '' || !is_file($absolutePath)) {
        throw new RuntimeException('MEDIA_SOURCE_NOT_FOUND');
    }

    $fileName = (string)$file->getName();
    $safeFileName = preg_replace('/[\r\n"\\]+/', '_', $fileName) ?: 'image';
    $mimeType = function_exists('mime_content_type')
        ? (string)(@mime_content_type($absolutePath) ?: '')
        : '';

    if ($mimeType === 'image/svg') {
        $mimeType = 'image/svg+xml';
    } elseif ($mimeType === 'image/jpg') {
        $mimeType = 'image/jpeg';
    }

    if ($mimeType === '') {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    $allowedMimeTypes = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ];

    if (!in_array(strtolower($mimeType), $allowedMimeTypes, true)) {
        throw new RuntimeException('MEDIA_TYPE_NOT_PREVIEWABLE');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $fileSize = (int)filesize($absolutePath);
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('Content-Disposition: inline; filename="' . $safeFileName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300, must-revalidate');
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");

    readfile($absolutePath);
    exit;
} catch (Throwable $e) {
    error_log('SiteBuilder media preview failed: ' . $e->getMessage());
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo 'MEDIA_NOT_AVAILABLE';
    exit;
}
