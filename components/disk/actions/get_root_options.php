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

$permissions = DiskPermissionService::resolve($context, $settings, null);
DiskValidator::assertCan($permissions, 'canEditSettings');

$site = SiteRepository::getById($context->siteId);
$siteRootFolderId = SiteRepository::getRootDiskFolderId($context->siteId);

$options = [];

if ($siteRootFolderId) {
    $options[] = [
        'value' => '',
        'label' => 'Использовать корень сайта',
        'type' => 'site_root',
        'folderId' => (int)$siteRootFolderId,
    ];
}

if (!empty($settings['rootFolderId'])) {
    $options[] = [
        'value' => (int)$settings['rootFolderId'],
        'label' => 'Собственная папка блока',
        'type' => 'block_root',
        'folderId' => (int)$settings['rootFolderId'],
    ];
}

$block = BlockRepository::getById($context->blockId);

DiskResponse::success([
    'options' => $options,
    'siteRootFolderId' => $siteRootFolderId ? (int)$siteRootFolderId : null,
    'blockRootFolderId' => !empty($settings['rootFolderId']) ? (int)$settings['rootFolderId'] : null,
    'siteName' => $site ? (string)$site['name'] : '',
    'blockVersion' => max(1, (int)($block['version'] ?? 1)),
]);