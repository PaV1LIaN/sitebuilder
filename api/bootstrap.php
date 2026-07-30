<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessRepository.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessService.php';

use Bitrix\Main\Loader;
use Bitrix\Disk\Storage;
use Bitrix\Disk\Folder;
use Bitrix\Disk\File;
use Bitrix\Disk\Driver;

global $USER;

header('Content-Type: application/json; charset=UTF-8');

sitebuilder_require_api_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!check_bitrix_sessid()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'BAD_SESSID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/lib/json.php';
require_once $projectRoot . '/lib/response.php';
require_once $projectRoot . '/lib/access.php';
require_once $projectRoot . '/lib/helpers.php';
require_once $projectRoot . '/lib/IdSequenceService.php';
require_once $projectRoot . '/lib/OutboxService.php';
require_once $projectRoot . '/lib/RevisionService.php';
require_once $projectRoot . '/lib/RequestLockService.php';
require_once $projectRoot . '/lib/RecycleBinService.php';
require_once $projectRoot . '/lib/AuditLogService.php';
require_once $projectRoot . '/lib/MaintenanceService.php';
require_once $projectRoot . '/lib/disk.php';

/*
 * Не отдаём пользователю stack trace и SQL-текст при необработанной ошибке.
 * Активная request-транзакция откатывается через sb_json_error().
 */
set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof SiteBuilderResourceBusyException) {
        sb_json_error('RESOURCE_BUSY', 423, $e->context());
    }

    if ($e instanceof PDOException) {
        $sqlState = sb_db_exception_sqlstate($e);
        if ($sqlState === '55P03') {
            sb_json_error('RESOURCE_BUSY', 423);
        }
        if ($sqlState === '40P01' || $sqlState === '40001') {
            sb_json_error('RETRY_TRANSACTION', 409);
        }
    }

    if ($e instanceof SiteBuilderVersionConflictException) {
        sb_json_error('VERSION_CONFLICT', 409, $e->context());
    }

    if ($e instanceof InvalidArgumentException) {
        $knownErrors = [
            'EXPECTED_VERSION_REQUIRED',
            'BAD_VERSION_MAP',
            'INVALID_ENTITY_TYPE',
        ];

        if (in_array($e->getMessage(), $knownErrors, true)) {
            sb_json_error($e->getMessage(), 422);
        }
    }

    error_log(sprintf(
        'SiteBuilder API unhandled exception: %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    if (function_exists('sb_json_error')) {
        sb_json_error('INTERNAL_ERROR', 500);
    }

    if (function_exists('sb_db_rollback_request_transaction')) {
        sb_db_rollback_request_transaction();
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'INTERNAL_ERROR',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});


/*
 * Очистка запускается после регистрации exception handler: даже ошибка
 * maintenance не раскрывает SQL или stack trace в ответе API.
 */
$maintenanceResult = MaintenanceService::runIfDue();
if (is_array($maintenanceResult)) {
    AuditLogService::recordSystemAction('maintenance.auto_cleanup', $maintenanceResult);
}
