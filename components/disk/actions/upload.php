<?php

DiskCsrf::validateFromRequest();

$currentUserId = DiskCurrentUser::requireId();

$context = DiskContextFactory::fromArray([
    'siteId' => (int)($_POST['siteId'] ?? 0),
    'pageId' => (int)($_POST['pageId'] ?? 0),
    'blockId' => (int)($_POST['blockId'] ?? 0),
    'currentUserId' => $currentUserId,
]);

DiskValidator::assertContext($context);

$settings = DiskSettingsRepository::ensureExistsForBlock(
    $context->blockId,
    $context->siteId,
    $context->pageId,
    $context->currentUserId
);

$rootFolderId = DiskRootResolver::resolve($context, $settings);
$permissions = DiskPermissionService::resolve($context, $settings, $rootFolderId);

DiskValidator::assertCan($permissions, 'canView');
DiskValidator::assertCan($permissions, 'canUpload');

sb_disk_release_session_lock();

$currentFolderId = (int)($_POST['currentFolderId'] ?? 0);
DiskValidator::assertFolderInsideRoot($currentFolderId, $rootFolderId, $context);

$files = $_FILES['files'] ?? null;
if (!$files) {
    throw new RuntimeException('NO_FILES');
}

$normalizedFiles = [];
$count = is_array($files['name']) ? count($files['name']) : 0;

for ($i = 0; $i < $count; $i++) {
    $normalizedFiles[] = [
        'name' => $files['name'][$i],
        'type' => $files['type'][$i],
        'tmp_name' => $files['tmp_name'][$i],
        'error' => $files['error'][$i],
        'size' => $files['size'][$i],
    ];
}

$allowedExtensions = $settings['allowedExtensions'] ?? [];
$maxFileSize = (int)($settings['maxFileSize'] ?? 0);

foreach ($normalizedFiles as $file) {
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('UPLOAD_ERROR');
    }

    if ($maxFileSize > 0 && (int)$file['size'] > $maxFileSize) {
        throw new RuntimeException('FILE_TOO_LARGE');
    }

    if (!empty($allowedExtensions)) {
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            throw new RuntimeException('EXTENSION_NOT_ALLOWED');
        }
    }
}

$adapter = new DiskBitrixStorageAdapter($context->currentUserId);
$result = $adapter->uploadFiles($context, $currentFolderId, $normalizedFiles, $settings);

DiskResponse::success([
    'uploadResult' => $result,
]);