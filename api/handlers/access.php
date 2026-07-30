<?php

global $USER;

if ($action === 'access.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_admin($siteId);

    $rows = sb_access_rows_for_site($siteId);

    usort($rows, static function ($a, $b) {
        $roleCmp = sb_role_rank((string)($b['role'] ?? '')) <=> sb_role_rank((string)($a['role'] ?? ''));
        if ($roleCmp !== 0) {
            return $roleCmp;
        }

        return strcmp((string)($a['accessCode'] ?? ''), (string)($b['accessCode'] ?? ''));
    });

    sb_json_ok([
        'access' => $rows,
        'handler' => 'access',
    ]);
}

if ($action === 'access.set') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $accessCode = trim((string)($_POST['accessCode'] ?? ''));
    $role = sb_normalize_access_role((string)($_POST['role'] ?? ''));

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    if ($accessCode === '') {
        sb_json_error('ACCESS_CODE_REQUIRED', 422);
    }
    if ($role === '') {
        sb_json_error('BAD_ROLE', 422);
    }

    sb_require_admin($siteId);

    if (!sb_site_exists($siteId)) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }

    try {
        $saveResult = sb_set_access_role(
            $siteId,
            $accessCode,
            $role,
            (int)$USER->GetID(),
            [
                'allowOwnerAssignment' => false,
                'allowOwnerDowngrade' => false,
                'protectLastOwner' => true,
            ]
        );

        $updated = $saveResult['row'];
    } catch (RuntimeException $e) {
        $knownErrors = [
            'INVALID_ACCESS_CODE',
            'INVALID_ROLE',
            'INVALID_SITE_ID',
            'OWNER_ASSIGNMENT_FORBIDDEN',
            'CANNOT_DOWNGRADE_OWNER',
            'LAST_OWNER_CANNOT_BE_DOWNGRADED',
        ];
        if (in_array($e->getMessage(), $knownErrors, true)) {
            sb_json_error($e->getMessage(), 422);
        }
        error_log('SiteBuilder legacy access.set failed: ' . $e->getMessage());
        sb_json_error('ACCESS_OPERATION_FAILED', 500);
    }

    sb_json_ok([
        'accessRow' => $updated,
        'handler' => 'access',
    ]);
}

if ($action === 'access.delete') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $accessCode = trim((string)($_POST['accessCode'] ?? ''));

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    if ($accessCode === '') {
        sb_json_error('ACCESS_CODE_REQUIRED', 422);
    }

    sb_require_admin($siteId);

    try {
        $deleted = sb_delete_access_row(
            $siteId,
            $accessCode,
            [
                'allowOwnerRemoval' => false,
                'protectLastOwner' => true,
            ]
        );
    } catch (RuntimeException $e) {
        $knownErrors = [
            'INVALID_ACCESS_CODE',
            'INVALID_SITE_ID',
            'OWNER_DELETE_FORBIDDEN',
            'LAST_OWNER_CANNOT_BE_REMOVED',
        ];
        if (in_array($e->getMessage(), $knownErrors, true)) {
            sb_json_error($e->getMessage(), 422);
        }
        error_log('SiteBuilder legacy access.delete failed: ' . $e->getMessage());
        sb_json_error('ACCESS_OPERATION_FAILED', 500);
    }

    if ($deleted === null) {
        sb_json_error('ACCESS_NOT_FOUND', 404);
    }

    sb_json_ok([
        'handler' => 'access',
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'access',
    'action' => $action,
]);