<?php

/*
 * The browser opens this GET URL in a new tab. The action validates SiteBuilder
 * permissions and streams the stored b_file through Bitrix itself.
 *
 * Do not call Bitrix\Disk\File::getDownloadUrl(): that method does not exist.
 * Do not redirect to native Disk URLs: they can use a different ACL model
 * from SiteBuilder custom/page permissions.
 */
$downloadStage = 'user';
$currentUserId = 0;
$fileId = 0;
$parentFolderId = 0;
$effectivePermissions = null;
$context = null;

try {
    $currentUserId = DiskCurrentUser::requireId();

    $downloadStage = 'input';
    $data = disk_read_json_body();
    $context = DiskContextFactory::fromArray([
        'siteId' => (int)($data['siteId'] ?? ($_GET['siteId'] ?? 0)),
        'pageId' => (int)($data['pageId'] ?? ($_GET['pageId'] ?? 0)),
        'blockId' => (int)($data['blockId'] ?? ($_GET['blockId'] ?? 0)),
        'currentUserId' => $currentUserId,
    ]);

    $fileId = (int)($data['fileId'] ?? ($_GET['fileId'] ?? 0));
    if ($fileId <= 0) {
        throw new RuntimeException('INVALID_FILE_ID');
    }

    $downloadStage = 'context';
    DiskValidator::assertContext($context);

    $downloadStage = 'settings';
    // Downloading must not cause a write to the disk block or bump its version.
    $settings = DiskSettingsRepository::getByBlockId($context->blockId);

    $downloadStage = 'scope';
    $rootFolderId = DiskRootResolver::resolve($context, $settings);
    DiskValidator::assertFileInsideRoot($fileId, $rootFolderId, $context);

    $downloadStage = 'permissions';
    $parentFolderId = DiskValidator::itemParentFolderId('file', $fileId);
    $effectivePermissions = DiskValidator::permissionsForFolder(
        $context,
        $settings,
        $parentFolderId,
        (int)$rootFolderId
    );
    DiskValidator::assertCan($effectivePermissions, 'canDownload');

    $downloadStage = 'file';
    $file = \Bitrix\Disk\File::loadById($fileId);
    if (!$file instanceof \Bitrix\Disk\File) {
        throw new RuntimeException('DISK_FILE_NOT_FOUND');
    }
    if (method_exists($file, 'isDeleted') && $file->isDeleted()) {
        throw new RuntimeException('DISK_FILE_DELETED');
    }

    // Bitrix Disk ID and b_file ID are different values. getFile() returns
    // the b_file array, including the correct ID and external storage metadata.
    $fileData = $file->getFile();
    if (!is_array($fileData) || (int)($fileData['ID'] ?? 0) <= 0) {
        throw new RuntimeException('DISK_FILE_DATA_NOT_FOUND');
    }

    $fileName = trim(str_replace(["\r", "\n"], ' ', (string)$file->getName()));
    if ($fileName === '') {
        $fileName = (string)($fileData['ORIGINAL_NAME'] ?? 'download');
    }

    // Rights have already been verified. Release the session lock before a
    // potentially large transfer so other Bitrix requests do not stall.
    if (function_exists('sb_disk_release_session_lock')) {
        sb_disk_release_session_lock();
    }

    // Avoid corrupting binary data with output emitted by the Bitrix prolog.
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) {
            break;
        }
    }

    $downloadStage = 'stream';
    header('X-Content-Type-Options: nosniff');
    \CFile::ViewByUser($fileData, [
        'force_download' => true,
        'cache_time' => 0,
        'attachment_name' => $fileName,
    ]);
    exit;
} catch (Throwable $e) {
    $diagnosticId = 'SD-DL-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));

    $groups = [];
    $isBitrixAdmin = false;
    try {
        $groups = DiskCurrentUser::getGroupIds();
        $isBitrixAdmin = DiskCurrentUser::isAdmin();
    } catch (Throwable $diagnosticError) {
        // Diagnostics must not replace the original download error.
    }

    error_log('SiteBuilder Disk download error: ' . json_encode([
        'diagnosticId' => $diagnosticId,
        'stage' => $downloadStage,
        'userId' => $currentUserId,
        'bitrixGroupIds' => $groups,
        'siteId' => $context instanceof DiskContext ? $context->siteId : 0,
        'pageId' => $context instanceof DiskContext ? $context->pageId : 0,
        'blockId' => $context instanceof DiskContext ? $context->blockId : 0,
        'fileId' => $fileId,
        'parentFolderId' => $parentFolderId,
        'effectivePermissions' => $effectivePermissions,
        'exception' => get_class($e),
        'technicalError' => $e->getMessage(),
        'source' => $e->getFile() . ':' . $e->getLine(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

    // If a file transfer has already started, never append JSON to the bytes.
    if (headers_sent()) {
        exit;
    }

    $reason = $e->getMessage();
    $accessDenied = in_array($reason, [
        'ACCESS_DENIED', 'FOLDER_OUT_OF_SCOPE', 'ROOT_FOLDER_OPERATION_FORBIDDEN',
    ], true);
    $notFound = in_array($reason, [
        'DISK_FILE_NOT_FOUND', 'DISK_FILE_DATA_NOT_FOUND', 'DISK_FILE_DELETED',
        'DISK_FOLDER_NOT_FOUND',
    ], true);
    $invalid = $reason === 'INVALID_FILE_ID';
    $message = $accessDenied
        ? 'Нет прав на скачивание файла.'
        : ($notFound ? 'Файл не найден или недоступен для скачивания.'
            : ($invalid ? 'Не указан корректный ID файла.'
                : 'Не удалось скачать файл из Диска Битрикс24.'));
    $message .= ' Пользователь ID ' . $currentUserId
        . ', файл ID ' . $fileId
        . '. Код диагностики: ' . $diagnosticId . '.';

    $details = [
        'diagnosticId' => $diagnosticId,
        'stage' => $downloadStage,
        'userId' => $currentUserId,
        'fileId' => $fileId,
    ];
    if ($isBitrixAdmin) {
        $details['bitrixGroupIds'] = $groups;
        $details['effectivePermissions'] = $effectivePermissions;
        $details['technicalError'] = $reason;
    }

    http_response_code($accessDenied ? 403 : ($notFound ? 404 : ($invalid ? 422 : 500)));
    DiskResponse::error(
        $accessDenied ? 'ACCESS_DENIED' : ($notFound ? 'DISK_FILE_NOT_FOUND'
            : ($invalid ? 'INVALID_FILE_ID' : 'DISK_DOWNLOAD_FAILED')),
        $message,
        $details
    );
}
