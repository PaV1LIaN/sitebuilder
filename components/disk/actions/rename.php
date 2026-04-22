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

$entityType = trim((string)($data['entityType'] ?? ''));
$entityId = (int)($data['entityId'] ?? 0);
$newName = trim((string)($data['newName'] ?? ''));

if (!in_array($entityType, ['file', 'folder'], true)) {
    throw new RuntimeException('INVALID_ENTITY_TYPE');
}

if ($entityId <= 0) {
    throw new RuntimeException('INVALID_ENTITY_ID');
}

DiskValidator::assertNonEmptyString($newName, 'EMPTY_NAME');

$adapter = new DiskBitrixStorageAdapter($context->currentUserId);
$item = $adapter->rename($context, $entityType, $entityId, $newName);

DiskResponse::success([
    'item' => $item,
]);