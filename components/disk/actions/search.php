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

DiskValidator::assertCan($permissions, 'canView');

$query = trim((string)($data['query'] ?? ''));
if ($query === '') {
    DiskResponse::success([
        'items' => [],
    ], [
        'total' => 0,
    ]);
}

$adapter = new DiskBitrixStorageAdapter($context->currentUserId);
$items = $adapter->search($context, (int)$rootFolderId, $query, []);

DiskResponse::success([
    'items' => $items,
], [
    'total' => count($items),
]);