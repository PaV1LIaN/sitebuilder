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

$settings = DiskSettingsRepository::getByBlockId($context->blockId);

if (!$settings) {
    $settings = DiskSettingsRepository::ensureExistsForBlock(
        $context->blockId,
        $context->siteId,
        $context->pageId,
        $context->currentUserId
    );
}

$block = BlockRepository::getById($context->blockId);
$rootFolderId = DiskRootResolver::resolve($context, $settings);
$permissions = DiskPermissionService::resolve($context, $settings, $rootFolderId);
DiskValidator::assertCan($permissions, 'canEditSettings');
$settings['title'] = DiskTitleSyncService::folderName($rootFolderId, (string)$settings['title']);

DiskResponse::success([
    'settings' => $settings,
    'root' => ['id' => $rootFolderId, 'name' => $settings['title']],
    'blockVersion' => max(1, (int)($block['version'] ?? 1)),
]);
