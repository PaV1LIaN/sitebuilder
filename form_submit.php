<?php

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/FormSubmissionService.php';

sitebuilder_require_api_auth();

global $USER;
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!check_bitrix_sessid()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'SESSION_EXPIRED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$siteId = (int)($_POST['siteId'] ?? 0);
$pageId = (int)($_POST['pageId'] ?? 0);
$blockId = (int)($_POST['blockId'] ?? 0);

$startedHere = sb_db_transaction_scope_begin();
try {
    $submission = FormSubmissionService::submit($siteId, $pageId, $blockId, $_POST, [
        'ipHash' => hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $siteId),
        'userAgent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'userId' => is_object($USER) ? (int)$USER->GetID() : 0,
    ]);
    sb_db_transaction_scope_commit($startedHere);
    echo json_encode(['ok' => true, 'submissionId' => $submission['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (SiteBuilderFormValidationException $e) {
    sb_db_transaction_scope_rollback($startedHere);
    http_response_code($e->getMessage() === 'FORM_RATE_LIMIT' ? 429 : 422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'fieldErrors' => $e->fieldErrors()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    sb_db_transaction_scope_rollback($startedHere);
    error_log('SiteBuilder form submit failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'FORM_SUBMIT_FAILED'], JSON_UNESCAPED_UNICODE);
}
