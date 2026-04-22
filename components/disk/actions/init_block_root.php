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

$permissions = DiskPermissionService::resolve($context, $settings, null);
DiskValidator::assertCan($permissions, 'canEditSettings');

$folderId = BlockDiskInitializer::ensureBlockRootFolder(
    $context->siteId,
    $context->pageId,
    $context->blockId,
    $context->currentUserId,
    (string)($settings['title'] ?? '')
);

DiskResponse::success([
    'rootFolderId' => $folderId,
]);