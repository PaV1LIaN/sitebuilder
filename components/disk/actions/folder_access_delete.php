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

DiskValidator::assertFolderInsideRoot($folderId, $rootFolderId, $context);
$permissions = DiskPermissionService::resolve($context, $settings, $folderId, $rootFolderId);
DiskValidator::assertCan($permissions, 'canManageAccess');

FolderAccessRepository::deleteUserRole(
    $context->siteId,
    $context->blockId,
    $folderId,
    $userId
);

DiskResponse::success(['deleted' => true]);
