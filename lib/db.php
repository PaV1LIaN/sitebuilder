<?php

function sb_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/lib/pg_master.php';

    if (!function_exists('getPDO')) {
        throw new RuntimeException('FUNCTION_getPDO_NOT_FOUND');
    }

    $pdo = getPDO();

    if (!$pdo instanceof PDO) {
        throw new RuntimeException('getPDO_DID_NOT_RETURN_PDO');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    try {
        $pdo->exec("SET search_path TO sitebuilder, public");
    } catch (Throwable $e) {
        // Если схема уже задана на уровне подключения — не критично.
    }

    return $pdo;
}

/**
 * Возвращает SQLSTATE независимо от того, положил ли PDO драйвер код
 * в Exception::code или в errorInfo[0].
 */
function sb_db_exception_sqlstate(Throwable $e): string
{
    if ($e instanceof PDOException) {
        $errorInfo = $e->errorInfo ?? null;
        if (is_array($errorInfo) && !empty($errorInfo[0])) {
            return (string)$errorInfo[0];
        }
    }

    return (string)$e->getCode();
}

function sb_db_fetch_all(string $sql, array $params = []): array
{
    $stmt = sb_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function sb_db_fetch_one(string $sql, array $params = []): ?array
{
    $stmt = sb_db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

function sb_db_execute(string $sql, array $params = []): bool
{
    $stmt = sb_db()->prepare($sql);
    return $stmt->execute($params);
}

function sb_db_last_insert_id(?string $sequence = null): int
{
    return (int)sb_db()->lastInsertId($sequence);
}

function sb_json_decode_assoc($value): array
{
    if (is_array($value)) {
        return $value;
    }

    if ($value === null || $value === '') {
        return [];
    }

    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}
/**
 * Регистрирует действие, которое нужно выполнить только после успешного COMMIT.
 * Вне управляемой request-транзакции действие выполняется сразу.
 */
function sb_db_after_commit(callable $callback): void
{
    if (!empty($GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'])) {
        $GLOBALS['SB_REQUEST_AFTER_COMMIT'][] = $callback;
        return;
    }

    /* Локальные transaction-scope также поддерживают отложенные действия. */
    if (!empty($GLOBALS['SB_SCOPE_TRANSACTION_ACTIVE'])) {
        $GLOBALS['SB_SCOPE_AFTER_COMMIT'][] = $callback;
        return;
    }

    try {
        $callback();
    } catch (Throwable $e) {
        error_log('SiteBuilder after-commit callback failed: ' . $e->getMessage());
    }
}

/**
 * Регистрирует компенсирующее действие на случай ROLLBACK.
 * Вне управляемой request-транзакции callback не нужен и не выполняется.
 */
function sb_db_after_rollback(callable $callback): void
{
    if (!empty($GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'])) {
        $GLOBALS['SB_REQUEST_AFTER_ROLLBACK'][] = $callback;
        return;
    }

    if (!empty($GLOBALS['SB_SCOPE_TRANSACTION_ACTIVE'])) {
        $GLOBALS['SB_SCOPE_AFTER_ROLLBACK'][] = $callback;
    }
}

function sb_db_run_callbacks(string $key): void
{
    $callbacks = $GLOBALS[$key] ?? [];
    $GLOBALS[$key] = [];

    foreach ($callbacks as $callback) {
        if (!is_callable($callback)) {
            continue;
        }

        try {
            $callback();
        } catch (Throwable $e) {
            error_log('SiteBuilder transaction callback failed: ' . $e->getMessage());
        }
    }
}

function sb_db_clear_transaction_callbacks(): void
{
    $GLOBALS['SB_REQUEST_AFTER_COMMIT'] = [];
    $GLOBALS['SB_REQUEST_AFTER_ROLLBACK'] = [];
}

/**
 * Открывает управляемую транзакцию одного API-запроса.
 *
 * Начиная с этапа 8 здесь больше нет общей advisory-блокировки всего
 * SiteBuilder. Нужные resource locks выбирает RequestLockService после
 * определения action и идентификаторов затрагиваемых объектов.
 */
function sb_db_begin_request_transaction(): void
{
    if (!empty($GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'])) {
        return;
    }

    $pdo = sb_db();

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    $GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'] = true;
    $GLOBALS['SB_REQUEST_RESOURCE_LOCKS'] = [];
    sb_db_clear_transaction_callbacks();

    if (empty($GLOBALS['SB_REQUEST_TRANSACTION_SHUTDOWN_REGISTERED'])) {
        $GLOBALS['SB_REQUEST_TRANSACTION_SHUTDOWN_REGISTERED'] = true;

        register_shutdown_function(static function (): void {
            if (empty($GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'])) {
                return;
            }

            try {
                sb_db_rollback_request_transaction();
            } catch (Throwable $e) {
                error_log('SiteBuilder transaction shutdown rollback failed: ' . $e->getMessage());
            }
        });
    }
}

function sb_db_commit_request_transaction(): void
{
    if (empty($GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'])) {
        return;
    }

    $pdo = sb_db();

    try {
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        $GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'] = false;
        $GLOBALS['SB_REQUEST_RESOURCE_LOCKS'] = [];
        $GLOBALS['SB_REQUEST_AFTER_ROLLBACK'] = [];

        sb_db_run_callbacks('SB_REQUEST_AFTER_COMMIT');
    } catch (Throwable $e) {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $rollbackError) {
            error_log('SiteBuilder commit failure rollback failed: ' . $rollbackError->getMessage());
        }

        $GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'] = false;
        $GLOBALS['SB_REQUEST_RESOURCE_LOCKS'] = [];
        $GLOBALS['SB_REQUEST_AFTER_COMMIT'] = [];
        sb_db_run_callbacks('SB_REQUEST_AFTER_ROLLBACK');
        throw $e;
    }
}

function sb_db_rollback_request_transaction(): void
{
    if (empty($GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'])) {
        return;
    }

    $pdo = sb_db();

    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } finally {
        $GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'] = false;
        $GLOBALS['SB_REQUEST_RESOURCE_LOCKS'] = [];
        $GLOBALS['SB_REQUEST_AFTER_COMMIT'] = [];

        sb_db_run_callbacks('SB_REQUEST_AFTER_ROLLBACK');
    }
}

/**
 * Запускает локальную транзакцию, только если вызывающий код ещё не открыл её.
 */
function sb_db_transaction_scope_begin(): bool
{
    $pdo = sb_db();

    if ($pdo->inTransaction()) {
        return false;
    }

    $GLOBALS['SB_SCOPE_AFTER_COMMIT'] = [];
    $GLOBALS['SB_SCOPE_AFTER_ROLLBACK'] = [];
    $GLOBALS['SB_SCOPE_TRANSACTION_ACTIVE'] = true;
    $pdo->beginTransaction();
    return true;
}

function sb_db_transaction_scope_commit(bool $startedHere): void
{
    if (!$startedHere) {
        return;
    }

    try {
        if (sb_db()->inTransaction()) {
            sb_db()->commit();
        }
        $GLOBALS['SB_SCOPE_TRANSACTION_ACTIVE'] = false;
        $GLOBALS['SB_SCOPE_AFTER_ROLLBACK'] = [];
        sb_db_run_callbacks('SB_SCOPE_AFTER_COMMIT');
    } catch (Throwable $e) {
        try {
            if (sb_db()->inTransaction()) {
                sb_db()->rollBack();
            }
        } finally {
            $GLOBALS['SB_SCOPE_TRANSACTION_ACTIVE'] = false;
            $GLOBALS['SB_SCOPE_AFTER_COMMIT'] = [];
            sb_db_run_callbacks('SB_SCOPE_AFTER_ROLLBACK');
        }
        throw $e;
    }
}

function sb_db_transaction_scope_rollback(bool $startedHere): void
{
    if (!$startedHere) {
        return;
    }

    try {
        if (sb_db()->inTransaction()) {
            sb_db()->rollBack();
        }
    } finally {
        $GLOBALS['SB_SCOPE_TRANSACTION_ACTIVE'] = false;
        $GLOBALS['SB_SCOPE_AFTER_COMMIT'] = [];
        sb_db_run_callbacks('SB_SCOPE_AFTER_ROLLBACK');
    }
}
