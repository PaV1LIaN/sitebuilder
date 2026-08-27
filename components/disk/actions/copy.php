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
$items = $data['items'] ?? [];
$targetFolderId = (int)($data['targetFolderId'] ?? 0);

if (!is_array($items) || empty($items)) {
    throw new RuntimeException('EMPTY_ITEMS');
}

DiskValidator::assertFolderInsideRoot($targetFolderId, $rootFolderId, $context);
DiskValidator::assertItemsInsideRoot($items, $rootFolderId, $context);
DiskValidator::assertCanForItemParents(
    $context,
    $settings,
    $items,
    (int)$rootFolderId,
    'canView'
);
DiskValidator::assertCanForFolder(
    $context,
    $settings,
    $targetFolderId,
    (int)$rootFolderId,
    'canUpload'
);

$adapter = new DiskBitrixStorageAdapter($context->currentUserId);
$result = $adapter->copy($context, $items, $targetFolderId);

DiskResponse::success([
    'result' => $result,
]);
