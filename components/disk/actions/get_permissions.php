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
$folderId = (int)($data['folderId'] ?? $rootFolderId);
DiskValidator::assertFolderInsideRoot($folderId, $rootFolderId, $context);
$permissions = DiskPermissionService::resolve($context, $settings, $folderId, $rootFolderId);

DiskResponse::success([
    'permissions' => $permissions,
    'folderId' => $folderId,
]);
