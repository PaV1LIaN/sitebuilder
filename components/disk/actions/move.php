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
$permissions = DiskPermissionService::resolve($context, $settings, $rootFolderId);

DiskValidator::assertCan($permissions, 'canRename');

$items = $data['items'] ?? [];
$targetFolderId = (int)($data['targetFolderId'] ?? 0);

if (!is_array($items) || empty($items)) {
    throw new RuntimeException('EMPTY_ITEMS');
}

DiskValidator::assertFolderInsideRoot($targetFolderId, $rootFolderId, $context);

$adapter = new DiskBitrixStorageAdapter($context->currentUserId);
$result = $adapter->move($context, $items, $targetFolderId);

DiskResponse::success([
    'result' => $result,
]);