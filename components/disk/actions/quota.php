<?php

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
    throw new RuntimeException('ACCESS_DENIED');
}
$settings = DiskSettingsRepository::getByBlockId($context->blockId);
$rootFolderId = DiskRootResolver::resolve($context, $settings, false);
$folderId = (int)($data['currentFolderId'] ?? $rootFolderId);
DiskValidator::assertFolderInsideRoot($folderId, $rootFolderId, $context);
$permissions = DiskValidator::assertCanForFolder($context, $settings, $folderId, (int)$rootFolderId, 'canView');
if ($action === 'checkUpload') DiskValidator::assertCan($permissions, 'canUpload');
(new DiskBitrixStorageAdapter($context->currentUserId))->getReadableFolderInfo($folderId);

sb_disk_release_session_lock();
header('Cache-Control: private, no-store');
$quota = DiskQuotaService::status($context, (int)$rootFolderId, $folderId);
$result = ['quota' => $quota];
if ($action === 'checkUpload') {
    if (!is_array($data['files'] ?? null)) throw new InvalidArgumentException('INVALID_UPLOAD_METADATA');
    $result['upload'] = DiskQuotaService::checkUpload($data['files'], $settings, $quota);
}
DiskResponse::success($result);
