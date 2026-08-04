<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';

sitebuilder_require_auth();

require_once __DIR__ . '/bootstrap.php';

use Bitrix\Disk\File;

function sb_disk_detect_mime(string $path, string $fileName = ''): string
{
    if (is_file($path) && function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }

    $ext = mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $map = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'txt' => 'text/plain; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'xml' => 'application/xml; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'htm' => 'text/html; charset=UTF-8',
        'csv' => 'text/csv; charset=UTF-8',
        'mp4' => 'video/mp4',
        'mp3' => 'audio/mpeg',
    ];

    return $map[$ext] ?? 'application/octet-stream';
}

try {
    $siteId = (int)($_GET['siteId'] ?? 0);
    $pageId = (int)($_GET['pageId'] ?? 0);
    $blockId = (int)($_GET['blockId'] ?? 0);
    $fileId = (int)($_GET['fileId'] ?? 0);

    $currentUserId = DiskCurrentUser::requireId();

    $context = DiskContextFactory::fromArray([
        'siteId' => $siteId,
        'pageId' => $pageId,
        'blockId' => $blockId,
        'currentUserId' => $currentUserId,
    ]);

    DiskValidator::assertContext($context);

    $settings = DiskSettingsRepository::ensureExistsForBlock(
        $context->blockId,
        $context->siteId,
        $context->pageId,
        $context->currentUserId
    );

    $rootInfo = DiskRootResolver::resolveWithSource($context, $settings, false);
    if ($fileId <= 0) {
        throw new RuntimeException('INVALID_FILE_ID');
    }

    DiskValidator::assertFileInsideRoot(
        $fileId,
        $rootInfo['rootFolderId'],
        $context
    );
    DiskValidator::assertCanForItemParent(
        $context,
        $settings,
        'file',
        $fileId,
        (int)$rootInfo['rootFolderId'],
        'canView'
    );

    $file = File::loadById($fileId);
    if (!$file instanceof File) {
        throw new RuntimeException('DISK_FILE_NOT_FOUND');
    }

    $fileArray = $file->getFile();
    if (!is_array($fileArray) || empty($fileArray['SRC'])) {
        throw new RuntimeException('FILE_SOURCE_NOT_FOUND');
    }

    $absolutePath = $_SERVER['DOCUMENT_ROOT'] . $fileArray['SRC'];
    if (!is_file($absolutePath)) {
        throw new RuntimeException('FILE_NOT_FOUND_ON_DISK');
    }

    $fileName = (string)$file->getName();
    $mimeType = sb_disk_detect_mime($absolutePath, $fileName);
    $fileSize = (int)filesize($absolutePath);

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('Content-Disposition: inline; filename="' . addslashes($fileName) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');

    readfile($absolutePath);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
    exit;
}
