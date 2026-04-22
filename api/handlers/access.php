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
        'file' => __FILE__,
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

    $current = sb_find_access_row($siteId, $accessCode);

    if ($current && (string)($current['role'] ?? '') === 'OWNER' && $role !== 'OWNER') {
        sb_json_error('CANNOT_DOWNGRADE_OWNER', 422);
    }

    if ($role === 'OWNER') {
        sb_json_error('OWNER_ASSIGNMENT_FORBIDDEN', 422);
    }

    $rows = sb_read_access();
    $updated = null;
    $found = false;

    foreach ($rows as &$row) {
        if (
            (int)($row['siteId'] ?? 0) === $siteId
            && (string)($row['accessCode'] ?? '') === $accessCode
        ) {
            $row['role'] = $role;
            $row['updatedAt'] = date('c');
            $row['updatedBy'] = (int)$USER->GetID();
            $updated = $row;
            $found = true;
            break;
        }
    }
    unset($row);

    if (!$found) {
        $updated = [
            'siteId' => $siteId,
            'accessCode' => $accessCode,
            'role' => $role,
            'createdBy' => (int)$USER->GetID(),
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
            'updatedBy' => (int)$USER->GetID(),
        ];

        $rows[] = $updated;
    }

    sb_write_access($rows);

    sb_json_ok([
        'accessRow' => $updated,
        'handler' => 'access',
        'file' => __FILE__,
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

    $row = sb_find_access_row($siteId, $accessCode);
    if (!$row) {
        sb_json_error('ACCESS_NOT_FOUND', 404);
    }

    $role = (string)($row['role'] ?? '');
    if ($role === 'OWNER') {
        if (sb_count_site_owners($siteId) <= 1) {
            sb_json_error('CANNOT_DELETE_LAST_OWNER', 422);
        }
        sb_json_error('OWNER_DELETE_FORBIDDEN', 422);
    }

    $rows = sb_read_access();
    $before = count($rows);

    $rows = array_values(array_filter($rows, static function ($r) use ($siteId, $accessCode) {
        return !(
            (int)($r['siteId'] ?? 0) === $siteId
            && (string)($r['accessCode'] ?? '') === $accessCode
        );
    }));

    if (count($rows) === $before) {
        sb_json_error('ACCESS_NOT_FOUND', 404);
    }

    sb_write_access($rows);

    sb_json_ok([
        'handler' => 'access',
        'file' => __FILE__,
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'access',
    'action' => $action,
    'file' => __FILE__,
]);