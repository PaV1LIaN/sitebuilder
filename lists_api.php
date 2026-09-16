<?php
declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/PageAccessService.php';
require_once __DIR__ . '/lib/DataListService.php';
sitebuilder_require_api_auth();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
$started = false;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new DataListException('Используйте POST.', 405);
    $body = file_get_contents('php://input');
    if (!is_string($body) || strlen($body) > 1048576) throw new DataListException('Слишком большой запрос.', 413);
    $input = json_decode($body, true);
    if (!is_array($input)) throw new DataListException('Некорректный запрос.');
    $token = $input['sessid'] ?? null;
    if (!is_string($token) || $token === '' || !hash_equals((string)bitrix_sessid(), $token)) {
        throw new DataListException('Сессия истекла. Обновите страницу.', 403);
    }
    global $USER;
    $userId = is_object($USER) && $USER->IsAuthorized() ? (int)$USER->GetID() : 0;
    $action = (string)($input['action'] ?? '');
    if (in_array($action, ['create','schema','saveRecord','deleteRecord','restoreRecord'], true)) {
        $started = sb_db_transaction_scope_begin();
    }
    $data = DataListService::dispatch($action, $input, $userId);
    sb_db_transaction_scope_commit($started);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    sb_db_transaction_scope_rollback($started);
    $known = $e instanceof DataListException;
    http_response_code($known ? $e->status : 500);
    if (!$known) error_log('SiteBuilder lists: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => $known ? $e->getMessage() : 'Не удалось выполнить действие. Обратитесь к администратору.',
        'details' => $known ? $e->details : []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
