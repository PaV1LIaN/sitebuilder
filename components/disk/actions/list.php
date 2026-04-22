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

if ($rootFolderId === null) {
    DiskResponse::success([
        'folder' => null,
        'breadcrumbs' => [],
        'items' => [],
    ], [
        'isEmpty' => true,
        'noRoot' => true,
        'total' => 0,
    ]);
}

$currentFolderId = (int)($data['currentFolderId'] ?? $rootFolderId);
DiskValidator::assertFolderInsideRoot($currentFolderId, $rootFolderId, $context);

$adapter = new DiskBitrixStorageAdapter($context->currentUserId);

$items = $adapter->listItems($context, $currentFolderId, [
    'sortBy' => $data['sortBy'] ?? 'updatedAt',
    'sortDir' => $data['sortDir'] ?? 'desc',
    'filters' => $data['filters'] ?? [],
]);

$breadcrumbs = $adapter->getBreadcrumbs($context, $currentFolderId);

DiskResponse::success([
    'folder' => [
        'id' => $currentFolderId,
        'name' => 'Текущая папка',
    ],
    'breadcrumbs' => $breadcrumbs,
    'items' => $items,
], [
    'isEmpty' => empty($items),
    'total' => count($items),
]);