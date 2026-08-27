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
$rootInfo = DiskRootResolver::resolveWithSource($context, $settings, false);
$rootFolderId = (int)($rootInfo['rootFolderId'] ?? 0);
$permissions = DiskPermissionService::resolve(
    $context,
    $settings,
    $rootFolderId > 0 ? $rootFolderId : null
);

DiskValidator::assertCan($permissions, 'canManageAccess');

if ($rootFolderId <= 0) {
    http_response_code(422);
    DiskResponse::error(
        'DISK_ROOT_FOLDER_NOT_FOUND',
        'Сначала создайте или выберите корневую папку компонента.'
    );
}

$matrix = BitrixDiskRightsService::getAccessMatrix(
    $context,
    $rootFolderId
);
$matrix['rootSource'] = (string)($rootInfo['source'] ?? 'none');
$matrix['permissionMode'] = (string)($settings['permissionMode'] ?? 'inherit_site');

DiskResponse::success($matrix);
