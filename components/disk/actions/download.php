<?php

$currentUserId = DiskCurrentUser::requireId();

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

DiskValidator::assertContext($context);

$settings = DiskSettingsRepository::ensureExistsForBlock(
    $context->blockId,
    $context->siteId,
    $context->pageId,
    $context->currentUserId
);

$rootFolderId = DiskRootResolver::resolve($context, $settings);
$permissions = DiskPermissionService::resolve($context, $settings, $rootFolderId);

DiskValidator::assertCan($permissions, 'canDownload');

$adapter = new DiskBitrixStorageAdapter($context->currentUserId);
$url = $adapter->getDownloadUrl($context, $fileId);

// Чтобы не уйти в цикл на тот же action, берем прямой URL Bitrix Disk:
$file = \Bitrix\Disk\File::loadById($fileId);
if (!$file instanceof \Bitrix\Disk\File) {
    throw new RuntimeException('DISK_FILE_NOT_FOUND');
}

LocalRedirect((string)$file->getDownloadUrl());
exit;