<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/MigrationService.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/BackupService.php';

final class PreflightService
{
    public static function run(int $actorUserId = 0): array
    {
        $startedAt = microtime(true);
        try {
            $runId = MigrationService::startPreflightRun($actorUserId);
        } catch (Throwable $e) {
            error_log('SiteBuilder preflight run log start failed: ' . $e->getMessage());
            $runId = 0;
        }
        $checks = [];

        self::checkPhp($checks);
        self::checkExtensions($checks);
        self::checkPhpIni($checks);
        self::checkDatabase($checks);
        self::checkSchema($checks);
        self::checkFilesystem($checks);
        self::checkBitrixModules($checks);
        self::checkGuestUser($checks);
        self::checkQueue($checks);
        self::checkCriticalAlerts($checks);

        $errors = count(array_filter($checks, static fn(array $check): bool => $check['level'] === 'error'));
        $warnings = count(array_filter($checks, static fn(array $check): bool => $check['level'] === 'warning'));
        $status = $errors > 0 ? 'failed' : 'succeeded';
        $durationMs = max(0, (int)round((microtime(true) - $startedAt) * 1000));

        $result = [
            'ready' => $errors === 0,
            'status' => $status,
            'errorsCount' => $errors,
            'warningsCount' => $warnings,
            'checksCount' => count($checks),
            'durationMs' => $durationMs,
            'checkedAt' => date('c'),
            'checks' => $checks,
        ];

        try {
            MigrationService::finishPreflightRun($runId, $status, $result);
        } catch (Throwable $e) {
            error_log('SiteBuilder preflight run log finish failed: ' . $e->getMessage());
        }
        $result['runId'] = $runId;

        return $result;
    }

    private static function checkPhp(array &$checks): void
    {
        $config = MigrationService::config();
        $minimum = (string)($config['minimum_php_version'] ?? '8.1.0');
        $ok = version_compare(PHP_VERSION, $minimum, '>=');

        self::add(
            $checks,
            'php.version',
            $ok ? 'ok' : 'error',
            'Версия PHP',
            $ok
                ? 'PHP ' . PHP_VERSION . ' соответствует минимальной версии ' . $minimum . '.'
                : 'Требуется PHP не ниже ' . $minimum . ', установлена ' . PHP_VERSION . '.',
            ['current' => PHP_VERSION, 'minimum' => $minimum]
        );
    }

    private static function checkExtensions(array &$checks): void
    {
        $required = ['json', 'mbstring', 'pdo', 'pdo_pgsql', 'openssl', 'fileinfo'];
        $recommended = ['zlib', 'opcache'];

        foreach ($required as $extension) {
            $loaded = extension_loaded($extension);
            self::add(
                $checks,
                'php.extension.' . $extension,
                $loaded ? 'ok' : 'error',
                'Расширение PHP: ' . $extension,
                $loaded ? 'Загружено.' : 'Обязательное расширение не загружено.'
            );
        }

        foreach ($recommended as $extension) {
            $loaded = extension_loaded($extension);
            self::add(
                $checks,
                'php.extension.' . $extension,
                $loaded ? 'ok' : 'warning',
                'Расширение PHP: ' . $extension,
                $loaded ? 'Загружено.' : 'Расширение рекомендуется для production.'
            );
        }
    }

    private static function checkPhpIni(array &$checks): void
    {
        $displayErrors = strtolower(trim((string)ini_get('display_errors')));
        $displayErrorsEnabled = in_array($displayErrors, ['1', 'on', 'yes', 'true'], true);
        self::add(
            $checks,
            'php.display_errors',
            $displayErrorsEnabled ? 'error' : 'ok',
            'display_errors',
            $displayErrorsEnabled
                ? 'На production нужно отключить display_errors.'
                : 'Ошибки PHP не выводятся пользователю.'
        );

        $uploadBytes = self::iniBytes((string)ini_get('upload_max_filesize'));
        $postBytes = self::iniBytes((string)ini_get('post_max_size'));
        $minBytes = 10 * 1024 * 1024;
        $level = ($uploadBytes >= $minBytes && $postBytes >= $minBytes) ? 'ok' : 'warning';
        self::add(
            $checks,
            'php.upload_limits',
            $level,
            'Лимиты загрузки',
            sprintf(
                'upload_max_filesize=%s, post_max_size=%s.',
                (string)ini_get('upload_max_filesize'),
                (string)ini_get('post_max_size')
            ),
            ['uploadBytes' => $uploadBytes, 'postBytes' => $postBytes]
        );
    }

    private static function checkDatabase(array &$checks): void
    {
        try {
            $row = sb_db_fetch_one(
                "SELECT current_database() AS database_name,
                        current_user AS database_user,
                        current_setting('server_version') AS server_version,
                        current_setting('server_version_num') AS server_version_num,
                        current_setting('TimeZone') AS timezone"
            );

            self::add(
                $checks,
                'database.connection',
                'ok',
                'Подключение PostgreSQL',
                'Подключение установлено.',
                [
                    'database' => (string)($row['database_name'] ?? ''),
                    'user' => (string)($row['database_user'] ?? ''),
                    'timezone' => (string)($row['timezone'] ?? ''),
                ]
            );

            $config = MigrationService::config();
            $minimum = (string)($config['minimum_postgresql_version'] ?? '12.0');
            $current = (string)($row['server_version'] ?? '0');
            preg_match('/\d+(?:\.\d+){0,2}/', $current, $versionMatch);
            $currentComparable = (string)($versionMatch[0] ?? '0');
            $ok = version_compare($currentComparable, $minimum, '>=');
            self::add(
                $checks,
                'database.version',
                $ok ? 'ok' : 'error',
                'Версия PostgreSQL',
                $ok
                    ? 'PostgreSQL ' . $current . ' соответствует требованиям.'
                    : 'Требуется PostgreSQL не ниже ' . $minimum . ', установлен ' . $current . '.',
                ['current' => $current, 'comparable' => $currentComparable, 'minimum' => $minimum]
            );
        } catch (Throwable $e) {
            error_log('SiteBuilder preflight database check failed: ' . $e->getMessage());
            self::add(
                $checks,
                'database.connection',
                'error',
                'Подключение PostgreSQL',
                'Не удалось выполнить диагностический запрос к базе данных.'
            );
        }
    }

    private static function checkSchema(array &$checks): void
    {
        try {
            $status = MigrationService::status();
            if (empty($status['registryReady'])) {
                self::add(
                    $checks,
                    'database.migrations.registry',
                    'error',
                    'Реестр миграций',
                    'Реестр миграций не установлен. Сначала примени этап 13.'
                );
                return;
            }

            $drift = (int)($status['driftCount'] ?? 0);
            $invalid = (int)($status['invalidCount'] ?? 0);
            $pending = (int)($status['pendingCount'] ?? 0);

            self::add(
                $checks,
                'database.migrations.drift',
                ($drift > 0 || $invalid > 0) ? 'error' : 'ok',
                'Контрольные суммы миграций',
                $invalid > 0
                    ? 'Отсутствуют или не читаются SQL-файлы миграций: ' . $invalid . '.'
                    : ($drift > 0
                        ? 'Обнаружены изменённые после применения SQL-файлы: ' . $drift . '.'
                        : 'Изменений применённых миграций не обнаружено.'),
                ['driftCount' => $drift, 'invalidCount' => $invalid]
            );

            self::add(
                $checks,
                'database.migrations.pending',
                $pending > 0 ? 'error' : 'ok',
                'Неприменённые миграции',
                $pending > 0
                    ? 'Ожидают применения миграции: ' . $pending . '.'
                    : 'Все миграции применены.',
                ['count' => $pending]
            );

            $baseRelations = [
                'sitebuilder.site',
                'sitebuilder.page',
                'sitebuilder.block',
                'sitebuilder.menu',
                'sitebuilder.layout',
                'sitebuilder.access',
                'sitebuilder.page_access',
                'sitebuilder.site_section',
                'sitebuilder.access_reconcile_run',
                'sitebuilder.access_sync_binding',
            ];
            $missing = [];
            foreach ($baseRelations as $relation) {
                $row = sb_db_fetch_one(
                    'SELECT to_regclass(:relation_name) AS relation_name',
                    [':relation_name' => $relation]
                );
                if (empty($row['relation_name'])) {
                    $missing[] = $relation;
                }
            }

            self::add(
                $checks,
                'database.base_schema',
                $missing ? 'error' : 'ok',
                'Базовая схема SiteBuilder',
                $missing
                    ? 'Не найдены обязательные объекты: ' . implode(', ', $missing) . '.'
                    : 'Основные таблицы SiteBuilder присутствуют.',
                ['missing' => $missing]
            );
        } catch (Throwable $e) {
            error_log('SiteBuilder preflight schema check failed: ' . $e->getMessage());
            self::add(
                $checks,
                'database.schema',
                'error',
                'Схема SiteBuilder',
                'Не удалось проверить состояние схемы.'
            );
        }
    }

    private static function checkFilesystem(array &$checks): void
    {
        $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $config = MigrationService::config();

        foreach ((array)($config['writable_paths'] ?? []) as $relativePath) {
            $relativePath = '/' . trim((string)$relativePath, '/');
            $absolutePath = $documentRoot . $relativePath;
            $exists = is_dir($absolutePath);
            $writable = $exists && is_writable($absolutePath);
            $parentWritable = !$exists && self::nearestExistingParentWritable($absolutePath);
            $level = $writable ? 'ok' : ($parentWritable ? 'warning' : 'error');

            self::add(
                $checks,
                'filesystem.' . str_replace(['/', '.'], ['_', '_'], trim($relativePath, '/')),
                $level,
                'Каталог ' . $relativePath,
                !$exists
                    ? ($parentWritable ? 'Каталог отсутствует, но может быть создан PHP.' : 'Каталог отсутствует и родитель недоступен на запись.')
                    : ($writable ? 'Каталог доступен на запись.' : 'У PHP нет прав на запись.'),
                ['path' => $absolutePath]
            );
        }

        try {
            $privateDir = BackupService::storageDirectory();
            $legacyDir = BackupService::legacyStorageDirectory();
            $outsideDocumentRoot = $documentRoot !== ''
                && !str_starts_with(rtrim($privateDir, '/') . '/', rtrim($documentRoot, '/') . '/');
            $privateExists = is_dir($privateDir);
            $privateWritable = $privateExists && is_writable($privateDir);
            $parentWritable = !$privateExists && self::nearestExistingParentWritable($privateDir);

            self::add(
                $checks,
                'filesystem.backup_private_location',
                $outsideDocumentRoot ? 'ok' : 'error',
                'Приватное хранилище резервных копий',
                $outsideDocumentRoot
                    ? 'Каталог расположен вне DOCUMENT_ROOT.'
                    : 'Каталог резервных копий находится внутри DOCUMENT_ROOT и может быть доступен напрямую по HTTP.',
                ['path' => $privateDir]
            );

            self::add(
                $checks,
                'filesystem.backup_private_writable',
                $privateWritable ? 'ok' : ($parentWritable ? 'warning' : 'error'),
                'Запись резервных копий',
                $privateWritable
                    ? 'Приватный каталог доступен на запись.'
                    : ($parentWritable ? 'Приватный каталог будет создан при первой записи.' : 'Приватный каталог и его родитель недоступны на запись.'),
                ['path' => $privateDir]
            );

            $legacyFiles = 0;
            if (is_dir($legacyDir)) {
                foreach ((array)glob($legacyDir . '/site-*-*.json*') as $legacyFile) {
                    if (is_file($legacyFile)) {
                        $legacyFiles++;
                    }
                }
            }
            self::add(
                $checks,
                'filesystem.backup_legacy_files',
                $legacyFiles > 0 ? 'error' : 'ok',
                'Старые web-доступные резервные копии',
                $legacyFiles > 0
                    ? 'В старом каталоге внутри DOCUMENT_ROOT осталось файлов: ' . $legacyFiles . '. Повтори bootstrap этапа 13.'
                    : 'Старых резервных копий внутри DOCUMENT_ROOT не найдено.',
                ['path' => $legacyDir, 'count' => $legacyFiles]
            );
        } catch (Throwable $e) {
            error_log('SiteBuilder preflight backup storage check failed: ' . $e->getMessage());
            self::add(
                $checks,
                'filesystem.backup_storage',
                'error',
                'Хранилище резервных копий',
                'Не удалось проверить каталог резервных копий.'
            );
        }

        if ($documentRoot !== '' && is_dir($documentRoot)) {
            $free = @disk_free_space($documentRoot);
            if (is_float($free) || is_int($free)) {
                $level = $free < 1024 * 1024 * 1024 ? 'warning' : 'ok';
                self::add(
                    $checks,
                    'filesystem.free_space',
                    $level,
                    'Свободное место',
                    'Свободно ' . self::formatBytes((int)$free) . '.',
                    ['bytes' => (int)$free]
                );
            }
        }
    }

    private static function checkBitrixModules(array &$checks): void
    {
        if (!class_exists('\\Bitrix\\Main\\Loader')) {
            self::add(
                $checks,
                'bitrix.loader',
                'error',
                'Bitrix Loader',
                'Класс Bitrix\\Main\\Loader недоступен.'
            );
            return;
        }

        foreach (['disk', 'socialnetwork'] as $module) {
            try {
                $loaded = \Bitrix\Main\Loader::includeModule($module);
            } catch (Throwable $e) {
                $loaded = false;
            }

            self::add(
                $checks,
                'bitrix.module.' . $module,
                $loaded ? 'ok' : 'error',
                'Модуль Битрикс: ' . $module,
                $loaded ? 'Модуль подключается.' : 'Модуль недоступен.'
            );
        }
    }

    private static function checkGuestUser(array &$checks): void
    {
        try {
            $config = sitebuilder_auth_config();
            $guestUserId = (int)($config['guest_user_id'] ?? 0);

            if ($guestUserId <= 0) {
                self::add(
                    $checks,
                    'auth.guest_user',
                    'error',
                    'Технический гость',
                    'ID технического пользователя не настроен.'
                );
                return;
            }

            if (!class_exists('CUser')) {
                self::add(
                    $checks,
                    'auth.guest_user',
                    'error',
                    'Технический гость',
                    'Класс CUser недоступен.'
                );
                return;
            }

            $result = CUser::GetByID($guestUserId);
            $user = $result->Fetch();
            if (!$user) {
                self::add(
                    $checks,
                    'auth.guest_user',
                    'error',
                    'Технический гость',
                    'Пользователь #' . $guestUserId . ' не найден.'
                );
                return;
            }

            $groups = array_map('intval', CUser::GetUserGroup($guestUserId));
            $isAdmin = in_array(1, $groups, true);
            $active = (string)($user['ACTIVE'] ?? 'N') === 'Y';
            $level = (!$active || $isAdmin) ? 'error' : 'ok';
            $message = !$active
                ? 'Технический пользователь деактивирован.'
                : ($isAdmin
                    ? 'Технический пользователь состоит в группе администраторов.'
                    : 'Технический пользователь активен и не является администратором.');

            self::add(
                $checks,
                'auth.guest_user',
                $level,
                'Технический гость',
                $message,
                ['userId' => $guestUserId]
            );

            if ($level === 'ok') {
                try {
                    $accessRow = sb_db_fetch_one(
                        "SELECT COUNT(*) AS count
                         FROM sitebuilder.access
                         WHERE access_code = :access_code
                           AND role IN ('OWNER','ADMIN','EDITOR','VIEWER')",
                        [':access_code' => 'U' . $guestUserId]
                    );
                    $accessCount = (int)($accessRow['count'] ?? 0);
                    self::add(
                        $checks,
                        'auth.guest_access',
                        $accessCount > 0 ? 'ok' : 'warning',
                        'Права технического гостя',
                        $accessCount > 0
                            ? 'Гостю назначены прямые роли SiteBuilder: ' . $accessCount . '.'
                            : 'У технического пользователя нет прямой роли SiteBuilder. Гостевой вход сработает, но сайты могут быть недоступны.',
                        ['userId' => $guestUserId, 'sitesCount' => $accessCount]
                    );
                } catch (Throwable $e) {
                    error_log('SiteBuilder preflight guest access check failed: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('SiteBuilder preflight guest user check failed: ' . $e->getMessage());
            self::add(
                $checks,
                'auth.guest_user',
                'error',
                'Технический гость',
                'Не удалось проверить технического пользователя.'
            );
        }
    }

    private static function checkQueue(array &$checks): void
    {
        try {
            $tables = sb_db_fetch_one(
                "SELECT to_regclass('sitebuilder.queue_worker_state') AS worker_state,
                        to_regclass('sitebuilder.outbox_job') AS outbox_job"
            );
            if (empty($tables['worker_state']) || empty($tables['outbox_job'])) {
                self::add(
                    $checks,
                    'queue.schema',
                    'error',
                    'Очередь внешних операций',
                    'Таблицы очереди или heartbeat отсутствуют.'
                );
                return;
            }

            $jobStats = sb_db_fetch_one(
                "SELECT
                    COUNT(*) FILTER (WHERE status IN ('pending','retry')) AS ready_count,
                    COUNT(*) FILTER (WHERE status='running') AS running_count,
                    COUNT(*) FILTER (WHERE status='dead') AS dead_count
                 FROM sitebuilder.outbox_job"
            ) ?: [];

            $worker = sb_db_fetch_one(
                "SELECT worker_id, status,
                        GREATEST(0, EXTRACT(EPOCH FROM (NOW()-heartbeat_at)))::bigint AS heartbeat_age_seconds
                 FROM sitebuilder.queue_worker_state
                 ORDER BY heartbeat_at DESC
                 LIMIT 1"
            );

            $ready = (int)($jobStats['ready_count'] ?? 0);
            $dead = (int)($jobStats['dead_count'] ?? 0);
            $warningAge = max(60, (int)(MigrationService::config()['worker_heartbeat_warning_seconds'] ?? 180));

            if (!$worker) {
                $level = $ready > 0 ? 'error' : 'warning';
                $message = $ready > 0
                    ? 'Есть ожидающие задания, но worker ещё не запускался.'
                    : 'Worker ещё не зарегистрировал heartbeat.';
            } else {
                $age = (int)($worker['heartbeat_age_seconds'] ?? 0);
                $level = $age > $warningAge && $ready > 0 ? 'error' : ($age > $warningAge ? 'warning' : 'ok');
                $message = sprintf(
                    'Worker %s, heartbeat %d секунд назад.',
                    (string)($worker['worker_id'] ?? ''),
                    $age
                );
            }

            self::add(
                $checks,
                'queue.worker',
                $level,
                'Worker очереди',
                $message,
                ['worker' => $worker, 'readyCount' => $ready]
            );

            self::add(
                $checks,
                'queue.dead_jobs',
                $dead > 0 ? 'error' : 'ok',
                'Окончательно упавшие задания',
                $dead > 0 ? 'Заданий в статусе dead: ' . $dead . '.' : 'Заданий в статусе dead нет.',
                ['count' => $dead]
            );
        } catch (Throwable $e) {
            error_log('SiteBuilder preflight queue check failed: ' . $e->getMessage());
            self::add(
                $checks,
                'queue.health',
                'error',
                'Очередь внешних операций',
                'Не удалось проверить очередь и worker.'
            );
        }
    }

    private static function checkCriticalAlerts(array &$checks): void
    {
        try {
            $row = sb_db_fetch_one(
                "SELECT to_regclass('sitebuilder.system_alert') AS relation_name"
            );
            if (empty($row['relation_name'])) {
                return;
            }

            $countRow = sb_db_fetch_one(
                "SELECT COUNT(*) AS count
                 FROM sitebuilder.system_alert
                 WHERE status IN ('open','acknowledged') AND severity='critical'"
            );
            $count = (int)($countRow['count'] ?? 0);
            self::add(
                $checks,
                'alerts.critical',
                $count > 0 ? 'error' : 'ok',
                'Критические оповещения',
                $count > 0
                    ? 'Открытых критических оповещений: ' . $count . '.'
                    : 'Открытых критических оповещений нет.',
                ['count' => $count]
            );
        } catch (Throwable $e) {
            error_log('SiteBuilder preflight alert check failed: ' . $e->getMessage());
        }
    }

    private static function add(
        array &$checks,
        string $code,
        string $level,
        string $title,
        string $message,
        array $details = []
    ): void {
        $checks[] = [
            'code' => $code,
            'level' => in_array($level, ['ok', 'warning', 'error'], true) ? $level : 'warning',
            'title' => $title,
            'message' => $message,
            'details' => $details,
        ];
    }

    private static function nearestExistingParentWritable(string $path): bool
    {
        $candidate = rtrim($path, '/');
        while ($candidate !== '' && $candidate !== '/') {
            $candidate = dirname($candidate);
            if (is_dir($candidate)) {
                return is_writable($candidate);
            }
        }
        return is_dir('/') && is_writable('/');
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float)$value;

        return match ($unit) {
            'g' => (int)round($number * 1024 * 1024 * 1024),
            'm' => (int)round($number * 1024 * 1024),
            'k' => (int)round($number * 1024),
            default => (int)round($number),
        };
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / (1024 ** 3), 1, ',', ' ') . ' ГБ';
        }
        if ($bytes >= 1024 ** 2) {
            return number_format($bytes / (1024 ** 2), 1, ',', ' ') . ' МБ';
        }
        return number_format($bytes / 1024, 1, ',', ' ') . ' КБ';
    }
}
