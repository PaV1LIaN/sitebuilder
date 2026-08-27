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
$folderId = (int)($data['folderId'] ?? 0);
$userId = (int)($data['userId'] ?? 0);
$role = strtoupper(trim((string)($data['role'] ?? '')));

DiskValidator::assertFolderInsideRoot($folderId, $rootFolderId, $context);
$permissions = DiskPermissionService::resolve($context, $settings, $folderId, $rootFolderId);
DiskValidator::assertCan($permissions, 'canManageAccess');

if ($userId <= 0) {
    throw new RuntimeException('INVALID_USER_ID');
}

$userResult = class_exists('CUser') ? CUser::GetByID($userId) : null;
$user = $userResult ? $userResult->Fetch() : null;
if (!$user || (string)($user['ACTIVE'] ?? '') !== 'Y') {
    throw new RuntimeException('USER_NOT_FOUND');
}

$saved = FolderAccessRepository::setUserRole(
    $context->siteId,
    $context->blockId,
    $folderId,
    $userId,
    $role,
    $currentUserId
);

DiskResponse::success([
    'item' => [
        'id' => (int)$saved['id'],
        'userId' => $userId,
        'userName' => sb_disk_user_name_by_id($userId),
        'role' => (string)$saved['role'],
        'folderId' => (int)$saved['folder_id'],
        'updatedAt' => (string)($saved['updated_at'] ?? ''),
    ],
]);
