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

$rootInfo = DiskRootResolver::resolveWithSource($context, $settings, true);
$permissions = DiskPermissionService::resolve($context, $settings, $rootInfo['rootFolderId']);

DiskResponse::success([
    'siteId' => $context->siteId,
    'pageId' => $context->pageId,
    'blockId' => $context->blockId,
    'settings' => $settings,
    'permissions' => $permissions,
    'rootFolderId' => $rootInfo['rootFolderId'],
    'currentFolderId' => $rootInfo['rootFolderId'],
    'rootSource' => $rootInfo['source'],
]);