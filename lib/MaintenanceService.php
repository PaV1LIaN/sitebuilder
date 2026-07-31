<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/BackupService.php';

final class MaintenanceService
{
    private const TASK_KEY = 'retention_cleanup';
    private const LOCK_KEY_1 = 761243;
    private const LOCK_KEY_2 = 1;

    public static function config(): array
    {
        static $config = null;
        if (is_array($config)) {
            return $config;
        }

        $path = dirname(__DIR__) . '/config/maintenance.php';
        $loaded = is_file($path) ? require $path : [];
        $config = array_merge([
            'revision_retention_days' => 180,
            'recycle_bin_retention_days' => 30,
            'audit_log_retention_days' => 365,
            'form_submission_retention_days' => 730,
            'outbox_succeeded_retention_days' => 30,
            'outbox_terminal_retention_days' => 90,
            'queue_worker_run_retention_days' => 90,
            'queue_worker_state_retention_days' => 7,
            'system_alert_resolved_retention_days' => 180,
            'system_alert_delivery_retention_days' => 90,
            'external_reconcile_run_retention_days' => 90,
            'external_resource_deleted_retention_days' => 30,
            'auto_cleanup_interval_seconds' => 86400,
            'batch_size' => 2000,
            'max_batches' => 5,
        ], is_array($loaded) ? $loaded : []);

        foreach ([
            'revision_retention_days',
            'recycle_bin_retention_days',
            'audit_log_retention_days',
            'form_submission_retention_days',
            'outbox_succeeded_retention_days',
            'outbox_terminal_retention_days',
            'queue_worker_run_retention_days',
            'queue_worker_state_retention_days',
            'system_alert_resolved_retention_days',
            'system_alert_delivery_retention_days',
            'external_reconcile_run_retention_days',
            'external_resource_deleted_retention_days',
        ] as $key) {
            $config[$key] = max(1, min(3650, (int)$config[$key]));
        }
        $config['auto_cleanup_interval_seconds'] = max(3600, (int)$config['auto_cleanup_interval_seconds']);
        $config['batch_size'] = max(100, min(20000, (int)$config['batch_size']));
        $config['max_batches'] = max(1, min(50, (int)$config['max_batches']));

        return $config;
    }

    public static function status(): array
    {
        $row = sb_db_fetch_one("
            SELECT task_key,last_run_at,last_success_at,last_result_json,updated_at
            FROM sitebuilder.maintenance_state
            WHERE task_key=:task_key
        ", [':task_key' => self::TASK_KEY]);

        return [
            'taskKey' => self::TASK_KEY,
            'lastRunAt' => (string)($row['last_run_at'] ?? ''),
            'lastSuccessAt' => (string)($row['last_success_at'] ?? ''),
            'lastResult' => sb_json_decode_assoc($row['last_result_json'] ?? []),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
            'config' => self::config(),
        ];
    }

    public static function runIfDue(): ?array
    {
        $pdo = sb_db();
        try {
            if (!(bool)$pdo->query("SELECT to_regclass('sitebuilder.maintenance_state') IS NOT NULL")->fetchColumn()) {
                return null;
            }
            $stmt = $pdo->query('SELECT pg_try_advisory_lock(' . self::LOCK_KEY_1 . ',' . self::LOCK_KEY_2 . ')');
            if (!$stmt || !$stmt->fetchColumn()) {
                return null;
            }
        } catch (Throwable $e) {
            /* Таблица/миграция ещё может отсутствовать во время развёртывания. */
            return null;
        }

        try {
            $row = sb_db_fetch_one("
                SELECT last_run_at
                FROM sitebuilder.maintenance_state
                WHERE task_key=:task_key
            ", [':task_key' => self::TASK_KEY]);

            $lastRun = !empty($row['last_run_at']) ? strtotime((string)$row['last_run_at']) : false;
            $interval = (int)self::config()['auto_cleanup_interval_seconds'];
            if ($lastRun !== false && $lastRun > time() - $interval) {
                return null;
            }

            return self::run(false, 0);
        } catch (Throwable $e) {
            error_log('SiteBuilder automatic maintenance failed: ' . $e->getMessage());
            return null;
        } finally {
            try {
                $pdo->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY_1 . ',' . self::LOCK_KEY_2 . ')');
            } catch (Throwable $e) {
            }
        }
    }

    public static function run(bool $lock = true, int $actorUserId = 0): array
    {
        $pdo = sb_db();
        $locked = false;
        if ($lock) {
            $stmt = $pdo->query('SELECT pg_try_advisory_lock(' . self::LOCK_KEY_1 . ',' . self::LOCK_KEY_2 . ')');
            $locked = $stmt && (bool)$stmt->fetchColumn();
            if (!$locked) {
                throw new RuntimeException('MAINTENANCE_ALREADY_RUNNING');
            }
        }

        $config = self::config();
        $startedHere = sb_db_transaction_scope_begin();
        $startedAt = microtime(true);

        try {
            sb_db_execute("
                INSERT INTO sitebuilder.maintenance_state (task_key,last_run_at,last_result_json,updated_at)
                VALUES (:task_key,NOW(),'{}'::jsonb,NOW())
                ON CONFLICT (task_key) DO UPDATE SET last_run_at=NOW(),updated_at=NOW()
            ", [':task_key' => self::TASK_KEY]);

            $result = [
                'revisions' => self::cleanupRevisions($config),
                'recycleBin' => self::cleanupSimpleTable(
                    'sitebuilder.recycle_bin',
                    'deleted_at',
                    (int)$config['recycle_bin_retention_days'],
                    $config
                ),
                'auditLog' => self::cleanupSimpleTable(
                    'sitebuilder.audit_log',
                    'created_at',
                    (int)$config['audit_log_retention_days'],
                    $config
                ),
                'formSubmissions' => self::cleanupOptionalTable(
                    'form_submission',
                    'created_at',
                    'TRUE',
                    (int)$config['form_submission_retention_days'],
                    $config
                ),
                'externalJobs' => self::cleanupExternalJobs($config),
                'queueWorkerRuns' => self::cleanupQueueWorkerRuns($config),
                'queueWorkerStates' => self::cleanupQueueWorkerStates($config),
                'resolvedAlerts' => self::cleanupOptionalTable('system_alert', 'resolved_at', 'status=\'resolved\'', (int)$config['system_alert_resolved_retention_days'], $config),
                'alertDeliveries' => self::cleanupOptionalTable('system_alert_delivery', 'attempted_at', 'TRUE', (int)$config['system_alert_delivery_retention_days'], $config),
                'reconcileRuns' => self::cleanupOptionalTable('external_reconcile_run', 'started_at', 'status<>\'running\'', (int)$config['external_reconcile_run_retention_days'], $config),
                'deletedExternalResources' => self::cleanupOptionalTable('external_resource_registry', 'updated_at', 'relation_status=\'deleted\'', (int)$config['external_resource_deleted_retention_days'], $config),
                'expiredBackups' => self::cleanupExpiredBackups($config),
                'durationMs' => 0,
                'actorUserId' => $actorUserId,
                'runAt' => date('c'),
            ];
            $result['durationMs'] = (int)round((microtime(true) - $startedAt) * 1000);

            sb_db_execute("
                UPDATE sitebuilder.maintenance_state
                SET last_success_at=NOW(),last_result_json=CAST(:result AS jsonb),updated_at=NOW()
                WHERE task_key=:task_key
            ", [
                ':task_key' => self::TASK_KEY,
                ':result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);

            sb_db_transaction_scope_commit($startedHere);
            return $result;
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            try {
                sb_db_execute("
                    INSERT INTO sitebuilder.maintenance_state (task_key,last_run_at,last_result_json,updated_at)
                    VALUES (:task_key,NOW(),CAST(:result AS jsonb),NOW())
                    ON CONFLICT (task_key) DO UPDATE SET
                        last_run_at=NOW(),last_result_json=EXCLUDED.last_result_json,updated_at=NOW()
                ", [
                    ':task_key' => self::TASK_KEY,
                    ':result' => json_encode([
                        'error' => 'MAINTENANCE_FAILED',
                        'runAt' => date('c'),
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            } catch (Throwable $stateError) {
                error_log('SiteBuilder maintenance state write failed: ' . $stateError->getMessage());
            }
            throw $e;
        } finally {
            if ($lock && $locked) {
                try {
                    $pdo->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY_1 . ',' . self::LOCK_KEY_2 . ')');
                } catch (Throwable $e) {
                }
            }
        }
    }


    private static function cleanupExpiredBackups(array $config): int
    {
        try {
            $exists = (bool)sb_db()->query(
                "SELECT to_regclass('sitebuilder.site_backup') IS NOT NULL"
            )->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
        if (!$exists) {
            return 0;
        }
        return BackupService::cleanupExpired(
            min(2000, (int)$config['batch_size'] * (int)$config['max_batches'])
        );
    }

    private static function cleanupRevisions(array $config): int
    {
        $deleted = 0;
        $batchSize = (int)$config['batch_size'];
        $cutoffDays = (int)$config['revision_retention_days'];

        for ($batch = 0; $batch < (int)$config['max_batches']; $batch++) {
            $stmt = sb_db()->prepare("
                WITH candidates AS (
                    SELECT id
                    FROM (
                        SELECT id,created_at,
                               ROW_NUMBER() OVER (
                                   PARTITION BY entity_type,entity_id
                                   ORDER BY id DESC
                               ) AS position
                        FROM sitebuilder.entity_revision
                    ) ranked
                    WHERE position > 1
                      AND created_at < NOW() - (CAST(:days AS integer) * INTERVAL '1 day')
                    ORDER BY id ASC
                    LIMIT :limit
                )
                DELETE FROM sitebuilder.entity_revision r
                USING candidates c
                WHERE r.id=c.id
            ");
            $stmt->bindValue(':days', $cutoffDays, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $count = $stmt->rowCount();
            $deleted += $count;
            if ($count < $batchSize) {
                break;
            }
        }

        return $deleted;
    }

    private static function cleanupExternalJobs(array $config): int
    {
        try {
            $exists = (bool)sb_db()->query(
                "SELECT to_regclass('sitebuilder.outbox_job') IS NOT NULL"
            )->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
        if (!$exists) {
            return 0;
        }

        $deleted = 0;
        $batchSize = (int)$config['batch_size'];
        $successDays = (int)$config['outbox_succeeded_retention_days'];
        $terminalDays = (int)$config['outbox_terminal_retention_days'];

        for ($batch = 0; $batch < (int)$config['max_batches']; $batch++) {
            $stmt = sb_db()->prepare("
                DELETE FROM sitebuilder.outbox_job
                WHERE id IN (
                    SELECT id
                    FROM sitebuilder.outbox_job
                    WHERE (
                        status='succeeded'
                        AND completed_at < NOW() - (CAST(:success_days AS integer) * INTERVAL '1 day')
                    ) OR (
                        status IN ('dead','cancelled')
                        AND completed_at < NOW() - (CAST(:terminal_days AS integer) * INTERVAL '1 day')
                    )
                    ORDER BY id ASC
                    LIMIT :limit
                )
            ");
            $stmt->bindValue(':success_days', $successDays, PDO::PARAM_INT);
            $stmt->bindValue(':terminal_days', $terminalDays, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $count = $stmt->rowCount();
            $deleted += $count;
            if ($count < $batchSize) {
                break;
            }
        }

        return $deleted;
    }

    private static function cleanupQueueWorkerRuns(array $config): int
    {
        try {
            $exists = (bool)sb_db()->query(
                "SELECT to_regclass('sitebuilder.queue_worker_run') IS NOT NULL"
            )->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
        if (!$exists) {
            return 0;
        }

        $deleted = 0;
        $days = (int)$config['queue_worker_run_retention_days'];
        $batchSize = (int)$config['batch_size'];
        for ($batch = 0; $batch < (int)$config['max_batches']; $batch++) {
            $stmt = sb_db()->prepare("
                DELETE FROM sitebuilder.queue_worker_run
                WHERE id IN (
                    SELECT id FROM sitebuilder.queue_worker_run
                    WHERE started_at < NOW() - (CAST(:days AS integer) * INTERVAL '1 day')
                    ORDER BY id ASC LIMIT :limit
                )
            ");
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $count = $stmt->rowCount();
            $deleted += $count;
            if ($count < $batchSize) {
                break;
            }
        }
        return $deleted;
    }

    private static function cleanupQueueWorkerStates(array $config): int
    {
        try {
            $exists = (bool)sb_db()->query(
                "SELECT to_regclass('sitebuilder.queue_worker_state') IS NOT NULL"
            )->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
        if (!$exists) {
            return 0;
        }

        $stmt = sb_db()->prepare("
            DELETE FROM sitebuilder.queue_worker_state
            WHERE status<>'running'
              AND heartbeat_at < NOW() - (CAST(:days AS integer) * INTERVAL '1 day')
        ");
        $stmt->bindValue(':days', (int)$config['queue_worker_state_retention_days'], PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    private static function cleanupOptionalTable(
        string $table,
        string $dateColumn,
        string $condition,
        int $cutoffDays,
        array $config
    ): int {
        $allowed = [
            'system_alert' => ['resolved_at', "status='resolved'"],
            'system_alert_delivery' => ['attempted_at', 'TRUE'],
            'external_reconcile_run' => ['started_at', "status<>'running'"],
            'external_resource_registry' => ['updated_at', "relation_status='deleted'"],
        ];
        if (!isset($allowed[$table]) || $allowed[$table] !== [$dateColumn, $condition]) {
            throw new InvalidArgumentException('INVALID_OPTIONAL_MAINTENANCE_TABLE');
        }
        try {
            $exists = (bool)sb_db()->query(
                "SELECT to_regclass('sitebuilder.{$table}') IS NOT NULL"
            )->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
        if (!$exists) {
            return 0;
        }
        $deleted = 0;
        $batchSize = (int)$config['batch_size'];
        for ($batch = 0; $batch < (int)$config['max_batches']; $batch++) {
            $stmt = sb_db()->prepare("
                DELETE FROM sitebuilder.{$table}
                WHERE id IN (
                    SELECT id FROM sitebuilder.{$table}
                    WHERE {$condition}
                      AND {$dateColumn} IS NOT NULL
                      AND {$dateColumn} < NOW() - (CAST(:days AS integer) * INTERVAL '1 day')
                    ORDER BY id ASC LIMIT :limit
                )
            ");
            $stmt->bindValue(':days', $cutoffDays, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $count = $stmt->rowCount();
            $deleted += $count;
            if ($count < $batchSize) {
                break;
            }
        }
        return $deleted;
    }

    private static function cleanupSimpleTable(
        string $table,
        string $dateColumn,
        int $cutoffDays,
        array $config
    ): int {
        $allowed = [
            'sitebuilder.recycle_bin' => ['deleted_at'],
            'sitebuilder.audit_log' => ['created_at'],
        ];
        if (!isset($allowed[$table]) || !in_array($dateColumn, $allowed[$table], true)) {
            throw new InvalidArgumentException('INVALID_MAINTENANCE_TABLE');
        }

        $deleted = 0;
        $batchSize = (int)$config['batch_size'];
        for ($batch = 0; $batch < (int)$config['max_batches']; $batch++) {
            $stmt = sb_db()->prepare("
                DELETE FROM {$table}
                WHERE id IN (
                    SELECT id
                    FROM {$table}
                    WHERE {$dateColumn} < NOW() - (CAST(:days AS integer) * INTERVAL '1 day')
                    ORDER BY id ASC
                    LIMIT :limit
                )
            ");
            $stmt->bindValue(':days', $cutoffDays, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $count = $stmt->rowCount();
            $deleted += $count;
            if ($count < $batchSize) {
                break;
            }
        }
        return $deleted;
    }
}
