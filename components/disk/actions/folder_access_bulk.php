<?php

DiskCsrf::validateFromRequest();
$data = disk_read_json_body();
$context = DiskContextFactory::fromArray([
    'siteId' => (int)($data['siteId'] ?? 0),
    'pageId' => (int)($data['pageId'] ?? 0),
    'blockId' => (int)($data['blockId'] ?? 0),
    'currentUserId' => DiskCurrentUser::requireId(),
]);
DiskValidator::assertContext($context);
$settings = DiskSettingsRepository::getByBlockId($context->blockId);
$rootFolderId = (int)DiskRootResolver::resolve($context, $settings);
$folderId = (int)($data['folderId'] ?? 0);
DiskValidator::assertFolderInsideRoot($folderId, $rootFolderId, $context);
$permissions = DiskPermissionService::resolve($context, $settings, $folderId, $rootFolderId);
DiskValidator::assertCan($permissions, 'canManageAccess');

if ($action === 'folderAccessResolveUsers') {
    if (!is_string($data['text'] ?? null)) {
        throw new InvalidArgumentException('INVALID_BULK_USERS');
    }
    DiskResponse::success(['rows' => FolderAccessBulkService::resolve($data['text'])]);
}

FolderAccessBulkService::assertMode($settings);
if (!is_array($data['userIds'] ?? null) || !is_string($data['role'] ?? null)
    || !is_string($data['expectedRevision'] ?? null)) {
    throw new InvalidArgumentException('INVALID_BULK_USERS');
}
// Validate the complete set before opening the write transaction.
$userIds = FolderAccessBulkService::validateUserIds($data['userIds']);
$result = FolderAccessRepository::setUserRoles(
    $context->siteId, $context->blockId, $folderId, $userIds,
    $data['role'], $context->currentUserId, $data['expectedRevision']
);
DiskResponse::success($result);
