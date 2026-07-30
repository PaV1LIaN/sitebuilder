<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/AuditLogService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/MaintenanceService.php';

global $USER;

if (!function_exists('sb_audit_require_site')) {
    function sb_audit_require_site(int $siteId): void
    {
        if ($siteId <= 0) {
            sb_json_error('SITE_ID_REQUIRED', 422);
        }
        sb_require_content_manager($siteId);
    }
}

if (!function_exists('sb_audit_user_map')) {
    function sb_audit_user_map(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $id = (int)($item['actorUserId'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        $users = [];
        foreach (array_keys($ids) as $id) {
            $row = CUser::GetByID((int)$id)->Fetch();
            if (!$row) {
                continue;
            }
            $name = trim(implode(' ', array_filter([
                (string)($row['LAST_NAME'] ?? ''),
                (string)($row['NAME'] ?? ''),
                (string)($row['SECOND_NAME'] ?? ''),
            ])));
            $users[(int)$id] = [
                'id' => (int)$id,
                'name' => $name !== '' ? $name : (string)($row['LOGIN'] ?? ('Пользователь #' . $id)),
                'login' => (string)($row['LOGIN'] ?? ''),
            ];
        }
        return $users;
    }
}

if ($action === 'audit.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    sb_audit_require_site($siteId);

    $result = AuditLogService::list([
        'siteId' => $siteId,
        'actorUserId' => (int)($_POST['actorUserId'] ?? 0),
        'action' => (string)($_POST['actionFilter'] ?? ''),
        'entityType' => (string)($_POST['entityType'] ?? ''),
        'outcome' => (string)($_POST['outcome'] ?? ''),
        'dateFrom' => (string)($_POST['dateFrom'] ?? ''),
        'dateTo' => (string)($_POST['dateTo'] ?? ''),
        'limit' => (int)($_POST['limit'] ?? 100),
        'offset' => (int)($_POST['offset'] ?? 0),
    ]);
    $result['users'] = sb_audit_user_map($result['items']);

    sb_json_ok($result);
}

if ($action === 'audit.get') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    $item = AuditLogService::get($id);
    if (!$item) {
        sb_json_error('AUDIT_ITEM_NOT_FOUND', 404);
    }
    sb_audit_require_site((int)$item['siteId']);

    sb_json_ok([
        'item' => $item,
        'users' => sb_audit_user_map([$item]),
    ]);
}

if ($action === 'maintenance.status') {
    if (!$USER->IsAdmin()) {
        sb_json_error('BITRIX_ADMIN_REQUIRED', 403);
    }
    sb_json_ok(['maintenance' => MaintenanceService::status()]);
}

if ($action === 'maintenance.run') {
    if (!$USER->IsAdmin()) {
        sb_json_error('BITRIX_ADMIN_REQUIRED', 403);
    }

    try {
        $result = MaintenanceService::run(true, (int)$USER->GetID());
        sb_json_ok(['maintenance' => $result]);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'MAINTENANCE_ALREADY_RUNNING') {
            sb_json_error('MAINTENANCE_ALREADY_RUNNING', 409);
        }
        throw $e;
    }
}

sb_json_error('UNKNOWN_AUDIT_ACTION', 400);
