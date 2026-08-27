<?php

DiskCsrf::validateFromRequest();
$data = disk_read_json_body();

$currentUserId = DiskCurrentUser::requireId();

$context = DiskContextFactory::fromArray([
    'siteId' => (int)($data['siteId'] ?? 0),
    'pageId' => (int)($data['pageId'] ?? 0),
    'blockId' => (int)($data['blockId'] ?? 0),
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
$currentFolderId = (int)($data['currentFolderId'] ?? 0);
$name = trim((string)($data['name'] ?? ''));

DiskValidator::assertFolderInsideRoot($currentFolderId, $rootFolderId, $context);
DiskValidator::assertCanForFolder(
    $context,
    $settings,
    $currentFolderId,
    (int)$rootFolderId,
    'canCreateFolder'
);
DiskValidator::assertNonEmptyString($name, 'EMPTY_FOLDER_NAME');

$adapter = new DiskBitrixStorageAdapter($context->currentUserId);
$folder = $adapter->createFolder($context, $currentFolderId, $name);

DiskResponse::success([
    'folder' => $folder,
]);
