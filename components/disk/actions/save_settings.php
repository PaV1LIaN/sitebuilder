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

$currentSettings = DiskSettingsRepository::getByBlockId($context->blockId);

if (!$currentSettings) {
    /*
     * Normal settings flow always calls getSettings before Save, so this is
     * only a defensive first-use fallback. Existing settings are never
     * rewritten before optimistic-lock validation.
     */
    $currentSettings = DiskSettingsRepository::ensureExistsForBlock(
        $context->blockId,
        $context->siteId,
        $context->pageId,
        $context->currentUserId
    );
}

$rootFolderId = DiskRootResolver::resolve($context, $currentSettings);
$permissions = DiskPermissionService::resolve($context, $currentSettings, $rootFolderId);

DiskValidator::assertCan($permissions, 'canEditSettings');

$settings = $data['settings'] ?? [];
if (!is_array($settings)) {
    throw new RuntimeException('INVALID_SETTINGS_PAYLOAD');
}

$allowedExtensions = [];
if (isset($settings['allowedExtensions'])) {
    if (is_array($settings['allowedExtensions'])) {
        $allowedExtensions = $settings['allowedExtensions'];
    } else {
        $raw = trim((string)$settings['allowedExtensions']);
        if ($raw !== '') {
            $allowedExtensions = preg_split('/[\s,;]+/u', $raw);
        }
    }
}

$rootFolderIdValue = null;
if (array_key_exists('rootFolderId', $settings)) {
    $tmp = trim((string)$settings['rootFolderId']);
    $rootFolderIdValue = ($tmp !== '' && (int)$tmp > 0) ? (int)$tmp : null;
}

$normalized = [
    'title' => trim((string)($settings['title'] ?? 'Файлы')),
    'rootFolderId' => $rootFolderIdValue,
    'viewMode' => in_array((string)($settings['viewMode'] ?? 'table'), ['table', 'grid'], true)
        ? (string)$settings['viewMode']
        : 'table',
    'allowUpload' => disk_normalize_bool($settings['allowUpload'] ?? true),
    'allowCreateFolder' => disk_normalize_bool($settings['allowCreateFolder'] ?? true),
    'allowRename' => disk_normalize_bool($settings['allowRename'] ?? true),
    'allowDelete' => disk_normalize_bool($settings['allowDelete'] ?? false),
    'allowDownload' => disk_normalize_bool($settings['allowDownload'] ?? true),
    'showSearch' => disk_normalize_bool($settings['showSearch'] ?? true),
    'showBreadcrumbs' => disk_normalize_bool($settings['showBreadcrumbs'] ?? true),
    'defaultSort' => trim((string)($settings['defaultSort'] ?? 'updatedAt')),
    'defaultSortDirection' => strtolower((string)($settings['defaultSortDirection'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
    'allowedExtensions' => array_values(array_filter(array_map(static function ($value) {
        return strtolower(trim((string)$value));
    }, $allowedExtensions))),
    'maxFileSize' => max(0, (int)($settings['maxFileSize'] ?? 52428800)),
    'permissionMode' => in_array((string)($settings['permissionMode'] ?? 'inherit_site'), ['inherit_site', 'custom', 'bitrix_disk'], true)
        ? (string)$settings['permissionMode']
        : 'inherit_site',
    'useSiteRootFallback' => disk_normalize_bool($settings['useSiteRootFallback'] ?? true),
];

$expectedVersion = RevisionService::requireExpectedVersion(
    $data['expectedVersion'] ?? null
);

$startedHere = sb_db_transaction_scope_begin();
try {
    DiskSettingsRepository::save(
        $context->blockId,
        $normalized,
        $expectedVersion,
        $currentUserId
    );

    $accessReconcileJob = OutboxService::enqueueUnifiedAccessReconcile(
        $context->siteId,
        'repair',
        $currentUserId,
        1
    );
    sb_db_transaction_scope_commit($startedHere);
} catch (Throwable $exception) {
    sb_db_transaction_scope_rollback($startedHere);
    throw $exception;
}

$updatedSettings = DiskSettingsRepository::getByBlockId($context->blockId);
$updatedBlock = BlockRepository::getById($context->blockId);

DiskResponse::success([
    'settings' => $updatedSettings,
    'blockVersion' => max(1, (int)($updatedBlock['version'] ?? 1)),
    'accessReconcileJob' => $accessReconcileJob,
]);
