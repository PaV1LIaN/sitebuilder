<?php

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    DiskResponse::error('METHOD_NOT_ALLOWED', 'Используйте POST.');
}

DiskCsrf::validateFromRequest();
$data = disk_read_json_body();
$context = DiskContextFactory::fromArray([
    'siteId' => (int)($data['siteId'] ?? 0),
    'pageId' => (int)($data['pageId'] ?? 0),
    'blockId' => (int)($data['blockId'] ?? 0),
    'currentUserId' => DiskCurrentUser::requireId(),
]);

DiskValidator::assertContext($context);
if (!PageAccessService::canViewPage($context->siteId, $context->pageId, $context->currentUserId)) {
    http_response_code(403);
    DiskResponse::error('ACCESS_DENIED', 'Нет доступа к странице.');
}

$entityType = (string)($data['entityType'] ?? '');
$entityId = (int)($data['entityId'] ?? 0);
$settings = DiskSettingsRepository::getByBlockId($context->blockId);
$rootFolderId = DiskRootResolver::resolve($context, $settings, false);

DiskValidator::assertItemInsideRoot($entityType, $entityId, $rootFolderId, $context);
$permissionFolderId = $entityType === 'folder'
    ? $entityId
    : DiskValidator::itemParentFolderId($entityType, $entityId);
DiskValidator::assertCanForFolder(
    $context,
    $settings,
    $permissionFolderId,
    (int)$rootFolderId,
    'canView'
);

$adapter = new DiskBitrixStorageAdapter($context->currentUserId);
try {
    $url = $adapter->getInternalLink($entityType, $entityId);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'DISK_NATIVE_READ_ACCESS_DENIED') {
        http_response_code(403);
        DiskResponse::error('ACCESS_DENIED', 'Нет права чтения этого объекта в Битрикс.Диске.');
    }
    throw $e;
}

header('Cache-Control: private, no-store');
DiskResponse::success(['url' => $url]);
