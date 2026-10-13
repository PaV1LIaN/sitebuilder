<?php

$diskDeleteStage = 'csrf';
DiskCsrf::validateFromRequest();
$data = disk_read_json_body();

$currentUserId = DiskCurrentUser::requireId();

$diskDeleteStage = 'context';
$context = DiskContextFactory::fromArray([
    'siteId' => (int)($data['siteId'] ?? 0),
    'pageId' => (int)($data['pageId'] ?? 0),
    'blockId' => (int)($data['blockId'] ?? 0),
    'currentUserId' => $currentUserId,
]);

DiskValidator::assertContext($context);

$diskDeleteStage = 'settings';
$settings = DiskSettingsRepository::ensureExistsForBlock(
    $context->blockId,
    $context->siteId,
    $context->pageId,
    $context->currentUserId
);

$diskDeleteStage = 'root';
$rootFolderId = DiskRootResolver::resolve($context, $settings);
$items = $data['items'] ?? [];
if (!is_array($items) || empty($items)) {
    throw new RuntimeException('EMPTY_ITEMS');
}

$diskDeleteStage = 'scope';
DiskValidator::assertItemsInsideRoot($items, $rootFolderId, $context);
$diskDeleteStage = 'permissions';
DiskValidator::assertCanForItemParents(
    $context,
    $settings,
    $items,
    (int)$rootFolderId,
    'canDelete'
);

$adapter = new DiskBitrixStorageAdapter($context->currentUserId);
$diskDeleteStage = 'bitrix_mark_deleted';
$result = $adapter->delete($context, $items);

DiskResponse::success([
    'result' => $result,
]);
