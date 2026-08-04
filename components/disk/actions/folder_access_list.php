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
$rootFolderId = (int)DiskRootResolver::resolve($context, $settings);
$folderId = (int)($data['folderId'] ?? $rootFolderId);
DiskValidator::assertFolderInsideRoot($folderId, $rootFolderId, $context);

$permissions = DiskPermissionService::resolve($context, $settings, $folderId, $rootFolderId);
DiskValidator::assertCan($permissions, 'canManageAccess');

$items = FolderAccessRepository::listForFolder(
    $context->siteId,
    $context->blockId,
    $folderId
);

foreach ($items as &$item) {
    $userId = FolderAccessRepository::userIdFromAccessCode((string)$item['access_code']);
    $item = [
        'id' => (int)$item['id'],
        'userId' => $userId,
        'userName' => sb_disk_user_name_by_id($userId),
        'role' => (string)$item['role'],
        'folderId' => (int)$item['folder_id'],
        'updatedAt' => (string)($item['updated_at'] ?? ''),
    ];
}
unset($item);

DiskResponse::success([
    'folderId' => $folderId,
    'items' => $items,
    'permissionMode' => (string)($settings['permissionMode'] ?? 'inherit_site'),
]);
