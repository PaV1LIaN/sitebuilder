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
if (!is_array($items) || empty($items)) {
    throw new RuntimeException('EMPTY_ITEMS');
}

DiskValidator::assertItemsInsideRoot($items, $rootFolderId, $context);
DiskValidator::assertCanForItemParents(
    $context,
    $settings,
    $items,
    (int)$rootFolderId,
    'canDelete'
);

$adapter = new DiskBitrixStorageAdapter($context->currentUserId);
$result = $adapter->delete($context, $items);

DiskResponse::success([
    'result' => $result,
]);
