<?php

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);
ob_start();

function sb_access_admin_reply(array $data, int $status = 200): never
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
    require_once dirname(__DIR__) . '/lib/auth.php';
    require_once dirname(__DIR__) . '/lib/AccessUserIdentity.php';
    if (!AccessUserIdentity::canOpenAdmin()) {
        sb_access_admin_reply(['ok' => false, 'error' => 'ACCESS_DENIED'], 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        sb_access_admin_reply(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
    }
    if (!check_bitrix_sessid()) {
        sb_access_admin_reply(['ok' => false, 'error' => 'BAD_SESSID'], 403);
    }
    $raw = (string)($_POST['payload'] ?? '{}');
    if (strlen($raw) > 1024 * 1024) {
        sb_access_admin_reply(['ok' => false, 'error' => 'REQUEST_TOO_LARGE'], 413);
    }
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) { throw new InvalidArgumentException('INVALID_REQUEST'); }
    $action = (string)($_POST['action'] ?? '');
    require_once dirname(__DIR__) . '/components/disk/bootstrap.php';
    require_once dirname(__DIR__) . '/lib/RequestLockService.php';
    require_once dirname(__DIR__) . '/lib/AccessUserResolver.php';
    require_once dirname(__DIR__) . '/lib/AccessAdminService.php';
    // All local ACL tables must participate in the same PDO transaction.
    DiskDb::setConnection(sb_db());
    $actor = (int)$USER->GetID();
    sb_disk_release_session_lock();
    require_once dirname(__DIR__) . '/lib/AccessGroupService.php';
    $data = str_starts_with($action, 'group.') ? AccessGroupService::handle($action, $input, $actor) : match ($action) {
        'tree' => AccessAdminService::tree($input, $actor),
        'view' => AccessAdminService::view($input, $actor),
        'parse' => ['lines' => AccessUserResolver::parse((string)($input['text'] ?? ''))],
        'resolve' => ['rows' => AccessUserResolver::resolve((array)($input['lines'] ?? []))],
        'search' => ['users' => AccessUserResolver::search((string)($input['query'] ?? ''))],
        'apply' => AccessAdminService::apply($input, $actor),
        default => throw new InvalidArgumentException('UNKNOWN_ACTION'),
    };
    sb_access_admin_reply(['ok' => true, 'data' => $data]);
} catch (Throwable $error) {
    $status = 500;
    $code = 'ACCESS_ADMIN_SERVER_ERROR';
    $details = [];
    if ($error instanceof AccessAdminException) {
        $status = $error->httpStatus; $code = $error->getMessage(); $details = $error->details;
    } elseif ($error instanceof InvalidArgumentException || $error instanceof JsonException) {
        $status = 422;
        $code = $error instanceof JsonException ? 'INVALID_JSON' : $error->getMessage();
    } elseif ($error instanceof PDOException && ($error->errorInfo[0] ?? '') === '42P01') {
        $code = 'REQUIRED_TABLE_MISSING';
    } elseif ($error instanceof PDOException && in_array(($error->errorInfo[0] ?? ''), ['55P03', '40P01', '40001'], true)) {
        $status = 409; $code = 'ACCESS_BUSY';
    } elseif (preg_match('/^[A-Z][A-Z0-9_]{2,80}$/', $error->getMessage())) {
        $code = $error->getMessage();
        if ($code === 'DISK_RIGHTS_BUSY') { $status = 409; }
    }
    $incident = bin2hex(random_bytes(8));
    error_log('SiteBuilder accessAdmin [' . $incident . '] ' . $error->getMessage());
    sb_access_admin_reply(['ok' => false, 'error' => $code, 'details' => $details, 'incident' => $incident], $status);
}
