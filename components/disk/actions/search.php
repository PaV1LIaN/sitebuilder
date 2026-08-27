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
$permissions = DiskPermissionService::resolve($context, $settings, $rootFolderId, $rootFolderId);

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
$items = array_values(array_filter($items, static function ($item) use ($context, $settings, $rootFolderId): bool {
    if (!is_array($item)) {
        return false;
    }

    $entityType = (string)($item['entityType'] ?? '');
    $entityId = (int)($item['id'] ?? 0);

    try {
        $folderId = $entityType === 'folder'
            ? $entityId
            : DiskValidator::itemParentFolderId($entityType, $entityId);
        $itemPermissions = DiskValidator::permissionsForFolder(
            $context,
            $settings,
            $folderId,
            (int)$rootFolderId
        );
        return !empty($itemPermissions['canView']);
    } catch (Throwable $e) {
        return false;
    }
}));

DiskResponse::success([
    'items' => $items,
], [
    'total' => count($items),
]);
