<?php

global $USER;

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/SystemAlertService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/ExternalResourceReconcileService.php';

if (!function_exists('sb_system_require_site_manager')) {
    function sb_system_require_site_manager(int $siteId): void
    {
        global $USER;
        if ($USER && $USER->IsAdmin()) {
            return;
        }
        if ($siteId <= 0) {
            sb_json_error('SITE_ID_REQUIRED', 422);
        }
        sb_require_site_role($siteId, 3);
    }
}

if ($action === 'system.alert.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    sb_system_require_site_manager($siteId);
    try {
        sb_json_ok(SystemAlertService::list([
            'siteId' => $siteId,
            'status' => (string)($_POST['status'] ?? ''),
            'severity' => (string)($_POST['severity'] ?? ''),
            'limit' => (int)($_POST['limit'] ?? 100),
            'offset' => (int)($_POST['offset'] ?? 0),
        ]));
    } catch (InvalidArgumentException $e) {
        sb_json_error($e->getMessage(), 422);
    }
}

if ($action === 'system.alert.get') {
    $alertId = (int)($_POST['alertId'] ?? 0);
    if ($alertId <= 0) {
        sb_json_error('ALERT_ID_REQUIRED', 422);
    }
    $alert = SystemAlertService::get($alertId);
    if (!$alert) {
        sb_json_error('ALERT_NOT_FOUND', 404);
    }
    sb_system_require_site_manager((int)$alert['siteId']);
    if (!$USER->IsAdmin()) {
        unset($alert['deliveries']);
    }
    sb_json_ok(['alert' => $alert]);
}

if ($action === 'system.alert.ack') {
    $alertId = (int)($_POST['alertId'] ?? 0);
    $alert = $alertId > 0 ? SystemAlertService::get($alertId) : null;
    if (!$alert) {
        sb_json_error('ALERT_NOT_FOUND', 404);
    }
    sb_system_require_site_manager((int)$alert['siteId']);
    try {
        sb_json_ok(['alert' => SystemAlertService::acknowledge($alertId, (int)$USER->GetID())]);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'ALERT_NOT_ACKNOWLEDGEABLE') {
            sb_json_error('ALERT_NOT_ACKNOWLEDGEABLE', 409);
        }
        throw $e;
    }
}

if ($action === 'system.alert.resolve') {
    $alertId = (int)($_POST['alertId'] ?? 0);
    $alert = $alertId > 0 ? SystemAlertService::get($alertId) : null;
    if (!$alert) {
        sb_json_error('ALERT_NOT_FOUND', 404);
    }
    sb_system_require_site_manager((int)$alert['siteId']);
    try {
        sb_json_ok(['alert' => SystemAlertService::resolve($alertId, (int)$USER->GetID())]);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'ALERT_NOT_RESOLVABLE') {
            sb_json_error('ALERT_NOT_RESOLVABLE', 409);
        }
        throw $e;
    }
}

if ($action === 'external.resource.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    sb_system_require_site_manager($siteId);
    try {
        sb_json_ok(ExternalResourceReconcileService::listResources([
            'siteId' => $siteId,
            'resourceType' => (string)($_POST['resourceType'] ?? ''),
            'status' => (string)($_POST['status'] ?? ''),
            'limit' => (int)($_POST['limit'] ?? 100),
            'offset' => (int)($_POST['offset'] ?? 0),
        ]));
    } catch (InvalidArgumentException $e) {
        sb_json_error($e->getMessage(), 422);
    }
}

if ($action === 'external.reconcile.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    sb_system_require_site_manager($siteId);
    sb_json_ok(['items' => ExternalResourceReconcileService::listRuns([
        'siteId' => $siteId,
        'limit' => (int)($_POST['limit'] ?? 30),
    ])]);
}

if ($action === 'external.reconcile.get') {
    $runId = (int)($_POST['runId'] ?? 0);
    $run = $runId > 0 ? ExternalResourceReconcileService::getRun($runId) : null;
    if (!$run) {
        sb_json_error('RECONCILE_RUN_NOT_FOUND', 404);
    }
    sb_system_require_site_manager((int)$run['siteId']);
    sb_json_ok(['run' => $run]);
}

if ($action === 'external.reconcile.enqueue') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    sb_system_require_site_manager($siteId);
    $mode = strtolower(trim((string)($_POST['mode'] ?? 'audit')));
    try {
        $job = ExternalResourceReconcileService::enqueue($siteId, $mode, (int)$USER->GetID());
        sb_json_ok(['job' => $job]);
    } catch (InvalidArgumentException $e) {
        sb_json_error($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'STAGE11_MIGRATION_REQUIRED') {
            sb_json_error('STAGE11_MIGRATION_REQUIRED', 503);
        }
        throw $e;
    }
}

if ($action === 'external.resource.cleanup') {
    if (!$USER->IsAdmin()) {
        sb_json_error('BITRIX_ADMIN_REQUIRED', 403);
    }
    $resourceType = trim((string)($_POST['resourceType'] ?? ''));
    $externalId = (int)($_POST['externalId'] ?? 0);
    if ($externalId <= 0) {
        sb_json_error('EXTERNAL_ID_REQUIRED', 422);
    }
    try {
        sb_json_ok(['job' => ExternalResourceReconcileService::cleanupOrphan(
            $resourceType,
            $externalId,
            (int)$USER->GetID()
        )]);
    } catch (InvalidArgumentException $e) {
        sb_json_error($e->getMessage(), 422);
    } catch (RuntimeException $e) {
        if (in_array($e->getMessage(), ['RESOURCE_NOT_CLEANABLE', 'RESOURCE_ALREADY_ATTACHED'], true)) {
            sb_json_error($e->getMessage(), 409);
        }
        throw $e;
    }
}

sb_json_error('NOT_MOVED_YET', 501, ['handler' => 'system', 'action' => $action]);
