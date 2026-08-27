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

$rights = $data['rights'] ?? null;
if (!is_array($rights)) {
    throw new RuntimeException('INVALID_DISK_RIGHTS_PAYLOAD');
}

$matrix = BitrixDiskRightsService::saveAccessMatrix(
    $context,
    $rootFolderId,
    $rights,
    (string)($data['expectedRightsRevision'] ?? '')
);
$matrix['rootSource'] = (string)($rootInfo['source'] ?? 'none');
$matrix['permissionMode'] = (string)($settings['permissionMode'] ?? 'inherit_site');

$auditPath = $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/AuditLogService.php';
if (is_file($auditPath)) {
    require_once $auditPath;
}
if (class_exists('AuditLogService')) {
    $counts = [];
    foreach ($rights as $right) {
        $taskName = is_array($right)
            ? (string)($right['taskName'] ?? '')
            : '';
        if ($taskName !== '') {
            $counts[$taskName] = (int)($counts[$taskName] ?? 0) + 1;
        }
    }

    AuditLogService::recordSystemAction(
        'disk.rights.save',
        [
            'pageId' => $context->pageId,
            'blockId' => $context->blockId,
            'folderId' => $rootFolderId,
            'rootSource' => $matrix['rootSource'],
            'userCount' => count($rights),
            'taskCounts' => $counts,
            'rightsRevision' => (string)($matrix['rightsRevision'] ?? ''),
        ],
        'success',
        200,
        $context->siteId,
        $currentUserId
    );
}

DiskResponse::success($matrix);
