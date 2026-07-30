<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/BackupService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/IntegrityCheckService.php';

global $USER;

if (!function_exists('sb_backup_require_manager')) {
    function sb_backup_require_manager(int $siteId): void
    {
        global $USER;
        if ($siteId <= 0) {
            sb_json_error('SITE_ID_REQUIRED', 422);
        }
        if ($USER && $USER->IsAdmin()) {
            return;
        }
        sb_require_content_manager($siteId);
    }
}

if (!function_exists('sb_backup_require_owner')) {
    function sb_backup_require_owner(int $siteId): void
    {
        global $USER;
        if ($siteId <= 0) {
            sb_json_error('SITE_ID_REQUIRED', 422);
        }
        if ($USER && $USER->IsAdmin()) {
            return;
        }
        sb_require_site_role($siteId, 4);
    }
}

if (!function_exists('sb_backup_record_or_error')) {
    function sb_backup_record_or_error(int $backupId): array
    {
        if ($backupId <= 0) {
            sb_json_error('BACKUP_ID_REQUIRED', 422);
        }
        $backup = BackupService::get($backupId);
        if (!$backup) {
            sb_json_error('BACKUP_NOT_FOUND', 404);
        }
        return $backup;
    }
}

try {
    if ($action === 'backup.list') {
        $siteId = (int)($_POST['siteId'] ?? 0);
        sb_backup_require_manager($siteId);
        sb_json_ok(['items' => BackupService::list($siteId, (int)($_POST['limit'] ?? 100))]);
    }

    if ($action === 'backup.get') {
        $backup = sb_backup_record_or_error((int)($_POST['backupId'] ?? 0));
        sb_backup_require_manager((int)$backup['originalSiteId']);
        sb_json_ok(['backup' => $backup]);
    }

    if ($action === 'backup.create') {
        $siteId = (int)($_POST['siteId'] ?? 0);
        $includeAccess = !empty($_POST['includeAccess']);
        $includeAccess ? sb_backup_require_owner($siteId) : sb_backup_require_manager($siteId);
        $backup = BackupService::create($siteId, $includeAccess, (int)$USER->GetID());
        sb_json_ok(['backup' => $backup]);
    }

    if ($action === 'backup.import') {
        $siteId = (int)($_POST['siteId'] ?? 0);
        sb_backup_require_owner($siteId);
        $backup = BackupService::import(
            $siteId,
            is_array($_FILES['backupFile'] ?? null) ? $_FILES['backupFile'] : [],
            (int)$USER->GetID()
        );
        sb_json_ok(['backup' => $backup]);
    }

    if ($action === 'backup.verify') {
        $backup = sb_backup_record_or_error((int)($_POST['backupId'] ?? 0));
        sb_backup_require_manager((int)$backup['originalSiteId']);
        sb_json_ok(BackupService::verify((int)$backup['id']));
    }

    if ($action === 'backup.restore') {
        $backup = sb_backup_record_or_error((int)($_POST['backupId'] ?? 0));
        sb_backup_require_owner((int)$backup['originalSiteId']);
        $result = BackupService::restore(
            (int)$backup['id'],
            trim((string)($_POST['siteName'] ?? '')),
            trim((string)($_POST['slug'] ?? '')),
            (int)($_POST['sectionId'] ?? 0),
            !empty($_POST['restoreAccess']),
            (int)$USER->GetID()
        );
        sb_json_ok($result);
    }

    if ($action === 'backup.delete') {
        $backup = sb_backup_record_or_error((int)($_POST['backupId'] ?? 0));
        sb_backup_require_owner((int)$backup['originalSiteId']);
        sb_json_ok(['backup' => BackupService::delete((int)$backup['id'], (int)$USER->GetID())]);
    }

    if ($action === 'integrity.list') {
        $siteId = (int)($_POST['siteId'] ?? 0);
        sb_backup_require_manager($siteId);
        sb_json_ok(['items' => IntegrityCheckService::listRuns($siteId, (int)($_POST['limit'] ?? 50))]);
    }

    if ($action === 'integrity.get') {
        $runId = (int)($_POST['runId'] ?? 0);
        $run = $runId > 0 ? IntegrityCheckService::getRun($runId) : null;
        if (!$run) {
            sb_json_error('INTEGRITY_RUN_NOT_FOUND', 404);
        }
        sb_backup_require_manager((int)$run['siteId']);
        sb_json_ok(['run' => $run]);
    }

    if ($action === 'integrity.run') {
        $siteId = (int)($_POST['siteId'] ?? 0);
        sb_backup_require_manager($siteId);
        sb_json_ok(['run' => IntegrityCheckService::run($siteId, (int)$USER->GetID())]);
    }
} catch (PDOException $e) {
    if (sb_db_exception_sqlstate($e) === '42P01') {
        sb_json_error('STAGE12_MIGRATION_REQUIRED', 503);
    }
    throw $e;
} catch (InvalidArgumentException $e) {
    $known = ['SITE_ID_REQUIRED', 'BACKUP_ID_REQUIRED'];
    if (in_array($e->getMessage(), $known, true)) {
        sb_json_error($e->getMessage(), 422);
    }
    throw $e;
} catch (RuntimeException $e) {
    $status = [
        'SITE_NOT_FOUND' => 404,
        'BACKUP_NOT_FOUND' => 404,
        'BACKUP_NOT_READY' => 409,
        'BACKUP_FILE_NOT_FOUND' => 404,
        'BACKUP_TOO_LARGE' => 413,
        'BACKUP_STORED_FILE_TOO_LARGE' => 413,
        'BACKUP_UPLOAD_TOO_LARGE' => 413,
        'BACKUP_UPLOAD_FAILED' => 422,
        'BACKUP_UPLOAD_MISSING' => 422,
        'BACKUP_FILE_EMPTY' => 422,
        'BACKUP_JSON_INVALID' => 422,
        'BACKUP_COMPRESSION_INVALID' => 422,
        'BACKUP_DECOMPRESSION_FAILED' => 422,
        'BACKUP_FORMAT_INVALID' => 422,
        'BACKUP_PAYLOAD_INVALID' => 422,
        'BACKUP_DIRECTORY_NOT_WRITABLE' => 503,
        'BACKUP_DIRECTORY_CREATE_FAILED' => 503,
        'SECTION_NOT_FOUND' => 404,
    ][$e->getMessage()] ?? 500;
    if ($status < 500) {
        sb_json_error($e->getMessage(), $status);
    }
    error_log('SiteBuilder backup operation failed: ' . $e->getMessage());
    sb_json_error('BACKUP_OPERATION_FAILED', 500);
}

sb_json_error('UNKNOWN_BACKUP_ACTION', 400);
