<?php

require_once __DIR__ . '/db.php';

final class SiteBuilderMigrationException extends RuntimeException
{
    private array $details;

    public function __construct(string $errorCode, array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($errorCode, 0, $previous);
        $this->details = $details;
    }

    public function details(): array
    {
        return $this->details;
    }
}

final class MigrationService
{
    private const LOCK_CLASS = 761239;
    private const LOCK_ID = 1300;

    private static ?array $config = null;

    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $file = dirname(__DIR__) . '/config/deployment.php';
        $loaded = is_file($file) ? require $file : [];

        self::$config = array_merge([
            'migration_lock_timeout_seconds' => 15,
            'minimum_php_version' => '8.1.0',
            'minimum_postgresql_version' => '12.0',
            'worker_heartbeat_warning_seconds' => 180,
            'writable_paths' => [
                '/upload/sitebuilder',
                '/upload/sitebuilder/backups',
            ],
        ], is_array($loaded) ? $loaded : []);

        return self::$config;
    }

    public static function manifest(): array
    {
        $root = dirname(__DIR__);

        return [
            [
                'key' => '20260729_001_entity_versions',
                'stage' => 5,
                'title' => 'Версии страниц и блоков',
                'file' => $root . '/migrations/20260729_001_entity_versions.sql',
                'fingerprint' => [
                    'relations' => ['sitebuilder.entity_revision'],
                    'columns' => ['sitebuilder.page.version', 'sitebuilder.block.version'],
                ],
            ],
            [
                'key' => '20260729_002_site_menu_layout_versions_and_recycle_bin',
                'stage' => 6,
                'title' => 'Версии сайта, меню, layout и корзина',
                'file' => $root . '/migrations/20260729_002_site_menu_layout_versions_and_recycle_bin.sql',
                'fingerprint' => [
                    'relations' => ['sitebuilder.recycle_bin'],
                    'columns' => [
                        'sitebuilder.site.version',
                        'sitebuilder.menu.version',
                        'sitebuilder.layout.version',
                    ],
                ],
            ],
            [
                'key' => '20260729_003_page_sections_audit_retention',
                'stage' => 7,
                'title' => 'Секции, аудит и retention',
                'file' => $root . '/migrations/20260729_003_page_sections_audit_retention.sql',
                'afterApply' => 'importLegacyPageSections',
                'fingerprint' => [
                    'relations' => [
                        'sitebuilder.page_section',
                        'sitebuilder.audit_log',
                        'sitebuilder.maintenance_state',
                    ],
                ],
            ],
            [
                'key' => '20260729_004_sequences_and_external_jobs',
                'stage' => 9,
                'title' => 'Sequences и очередь внешних операций',
                'file' => $root . '/migrations/20260729_004_sequences_and_external_jobs.sql',
                'fingerprint' => [
                    'relations' => [
                        'sitebuilder.site_id_seq',
                        'sitebuilder.page_id_seq',
                        'sitebuilder.block_id_seq',
                        'sitebuilder.menu_id_seq',
                        'sitebuilder.outbox_job',
                        'sitebuilder.outbox_job_event',
                    ],
                ],
            ],
            [
                'key' => '20260729_005_external_cleanup_and_queue_health',
                'stage' => 10,
                'title' => 'Очистка внешних ресурсов и метрики worker',
                'file' => $root . '/migrations/20260729_005_external_cleanup_and_queue_health.sql',
                'fingerprint' => [
                    'relations' => [
                        'sitebuilder.queue_worker_state',
                        'sitebuilder.queue_worker_run',
                    ],
                ],
            ],
            [
                'key' => '20260729_006_external_reconciliation_and_alerts',
                'stage' => 11,
                'title' => 'Сверка внешних ресурсов и оповещения',
                'file' => $root . '/migrations/20260729_006_external_reconciliation_and_alerts.sql',
                'fingerprint' => [
                    'relations' => [
                        'sitebuilder.external_reconcile_run',
                        'sitebuilder.external_resource_registry',
                        'sitebuilder.system_alert',
                        'sitebuilder.system_alert_delivery',
                    ],
                ],
            ],
            [
                'key' => '20260729_007_backups_and_integrity',
                'stage' => 12,
                'title' => 'Резервные копии и проверки целостности',
                'file' => $root . '/migrations/20260729_007_backups_and_integrity.sql',
                'fingerprint' => [
                    'relations' => [
                        'sitebuilder.site_backup',
                        'sitebuilder.integrity_check_run',
                    ],
                ],
            ],
            [
                'key' => '20260730_008_migration_registry_and_deployment_runs',
                'stage' => 13,
                'title' => 'Реестр миграций и журнал развёртываний',
                'file' => $root . '/migrations/20260730_008_migration_registry_and_deployment_runs.sql',
                'fingerprint' => [
                    'relations' => [
                        'sitebuilder.schema_migration',
                        'sitebuilder.deployment_run',
                    ],
                ],
            ],
        ];
    }

    public static function registryReady(): bool
    {
        return self::relationExists('sitebuilder.schema_migration')
            && self::relationExists('sitebuilder.deployment_run');
    }

    public static function status(): array
    {
        $registryReady = self::registryReady();
        $applied = [];

        if ($registryReady) {
            foreach (sb_db_fetch_all(
                "SELECT migration_key, stage_number, filename, title, checksum, source,
                        execution_ms, applied_by, applied_at, metadata_json
                 FROM sitebuilder.schema_migration
                 ORDER BY stage_number, migration_key"
            ) as $row) {
                $row['metadata'] = sb_json_decode_assoc($row['metadata_json'] ?? null);
                unset($row['metadata_json']);
                $applied[(string)$row['migration_key']] = $row;
            }
        }

        $items = [];
        $pending = 0;
        $drift = 0;
        $invalid = 0;

        foreach (self::manifest() as $entry) {
            $checksum = '';
            $fileError = '';
            try {
                $checksum = self::checksum($entry);
            } catch (Throwable $e) {
                $fileError = $e->getMessage();
            }

            $row = $applied[$entry['key']] ?? null;
            $state = 'pending';

            if ($fileError !== '') {
                $state = 'missing';
            } elseif ($row !== null) {
                $state = hash_equals((string)$row['checksum'], $checksum) ? 'applied' : 'drift';
            }

            if ($state === 'pending') {
                $pending++;
            } elseif ($state === 'drift') {
                $drift++;
            } elseif ($state === 'missing') {
                $invalid++;
            }

            $items[] = [
                'key' => $entry['key'],
                'stage' => $entry['stage'],
                'title' => $entry['title'],
                'filename' => basename($entry['file']),
                'checksum' => $checksum,
                'state' => $state,
                'fileError' => $fileError,
                'fingerprintPassed' => self::fingerprintPassed($entry),
                'applied' => $row,
            ];
        }

        return [
            'registryReady' => $registryReady,
            'pendingCount' => $pending,
            'driftCount' => $drift,
            'invalidCount' => $invalid,
            'ready' => $registryReady && $pending === 0 && $drift === 0 && $invalid === 0,
            'items' => $items,
        ];
    }

    /**
     * Первичная установка этапа 13 на базе старой схемы.
     * Создаёт реестр, регистрирует уже существующие миграции по fingerprint
     * и выполняет реально отсутствующие миграции.
     */
    public static function bootstrap(int $actorUserId): array
    {
        return self::withGlobalLock(function () use ($actorUserId): array {
            $manifest = self::manifest();
            $registryEntry = end($manifest);

            if (!self::registryReady()) {
                self::executeSqlFileWithoutRegistry($registryEntry);
            }

            $runId = self::startRun('bootstrap', $actorUserId);
            $startedAt = microtime(true);
            $baselineCount = 0;
            $appliedCount = 0;
            $skippedCount = 0;
            $events = [];

            try {
                /* Этап 13 мог создать таблицу до появления строки в реестре. */
                if (!self::migrationRow((string)$registryEntry['key'])) {
                    self::recordApplied($registryEntry, 'executed', $actorUserId, 0, [
                        'bootstrap' => true,
                    ]);
                    $appliedCount++;
                    $events[] = ['key' => $registryEntry['key'], 'result' => 'executed'];
                }

                foreach ($manifest as $entry) {
                    if ((string)$entry['key'] === (string)$registryEntry['key']) {
                        continue;
                    }

                    $existing = self::migrationRow((string)$entry['key']);
                    if ($existing) {
                        self::assertChecksum($entry, $existing);
                        $skippedCount++;
                        continue;
                    }

                    if (self::fingerprintPassed($entry)) {
                        self::recordApplied($entry, 'baseline', $actorUserId, 0, [
                            'detectedByFingerprint' => true,
                        ]);
                        $baselineCount++;
                        $events[] = ['key' => $entry['key'], 'result' => 'baseline'];
                        continue;
                    }

                    self::updateRunCurrent($runId, (string)$entry['key']);
                    $execution = self::executeMigration($entry, $actorUserId);
                    $appliedCount++;
                    $events[] = [
                        'key' => $entry['key'],
                        'result' => 'executed',
                        'executionMs' => $execution['executionMs'],
                    ];
                }

                self::finishRun($runId, 'succeeded', [
                    'appliedCount' => $appliedCount,
                    'baselineCount' => $baselineCount,
                    'skippedCount' => $skippedCount,
                    'details' => ['events' => $events],
                    'durationMs' => self::elapsedMs($startedAt),
                ]);

                return [
                    'runId' => $runId,
                    'appliedCount' => $appliedCount,
                    'baselineCount' => $baselineCount,
                    'skippedCount' => $skippedCount,
                    'events' => $events,
                    'status' => self::status(),
                ];
            } catch (Throwable $e) {
                self::finishRun($runId, 'failed', [
                    'appliedCount' => $appliedCount,
                    'baselineCount' => $baselineCount,
                    'skippedCount' => $skippedCount,
                    'failedMigrationKey' => self::currentRunMigration($runId),
                    'errorCode' => $e->getMessage(),
                    'details' => ['events' => $events],
                    'durationMs' => self::elapsedMs($startedAt),
                ]);
                throw $e;
            }
        });
    }

    public static function applyPending(int $actorUserId): array
    {
        if (!self::registryReady()) {
            throw new SiteBuilderMigrationException('MIGRATION_REGISTRY_NOT_READY');
        }

        return self::withGlobalLock(function () use ($actorUserId): array {
            $runId = self::startRun('migrate', $actorUserId);
            $startedAt = microtime(true);
            $appliedCount = 0;
            $skippedCount = 0;
            $events = [];

            try {
                foreach (self::manifest() as $entry) {
                    $existing = self::migrationRow((string)$entry['key']);
                    if ($existing) {
                        self::assertChecksum($entry, $existing);
                        $skippedCount++;
                        continue;
                    }

                    self::updateRunCurrent($runId, (string)$entry['key']);
                    $execution = self::executeMigration($entry, $actorUserId);
                    $appliedCount++;
                    $events[] = [
                        'key' => $entry['key'],
                        'executionMs' => $execution['executionMs'],
                    ];
                }

                self::finishRun($runId, 'succeeded', [
                    'appliedCount' => $appliedCount,
                    'skippedCount' => $skippedCount,
                    'details' => ['events' => $events],
                    'durationMs' => self::elapsedMs($startedAt),
                ]);

                return [
                    'runId' => $runId,
                    'appliedCount' => $appliedCount,
                    'skippedCount' => $skippedCount,
                    'events' => $events,
                    'status' => self::status(),
                ];
            } catch (Throwable $e) {
                self::finishRun($runId, 'failed', [
                    'appliedCount' => $appliedCount,
                    'skippedCount' => $skippedCount,
                    'failedMigrationKey' => self::currentRunMigration($runId),
                    'errorCode' => $e->getMessage(),
                    'details' => ['events' => $events],
                    'durationMs' => self::elapsedMs($startedAt),
                ]);
                throw $e;
            }
        });
    }

    public static function recentRuns(int $limit = 20): array
    {
        if (!self::registryReady()) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $rows = sb_db_fetch_all(
            "SELECT id, mode, status, actor_user_id, host_name, php_sapi,
                    current_migration_key, applied_count, baseline_count,
                    skipped_count, failed_migration_key, error_code,
                    details_json, started_at, finished_at, duration_ms
             FROM sitebuilder.deployment_run
             ORDER BY id DESC
             LIMIT {$limit}"
        );

        foreach ($rows as &$row) {
            $row['details'] = sb_json_decode_assoc($row['details_json'] ?? null);
            unset($row['details_json']);
        }
        unset($row);

        return $rows;
    }

    public static function startPreflightRun(int $actorUserId): int
    {
        if (!self::registryReady()) {
            return 0;
        }

        return self::startRun('preflight', $actorUserId);
    }

    public static function finishPreflightRun(int $runId, string $status, array $details): void
    {
        if ($runId <= 0 || !self::registryReady()) {
            return;
        }

        self::finishRun($runId, $status, [
            'details' => $details,
            'errorCode' => $status === 'failed' ? 'PREFLIGHT_FAILED' : null,
            'durationMs' => isset($details['durationMs']) ? (int)$details['durationMs'] : null,
        ]);
    }

    private static function executeMigration(array $entry, int $actorUserId): array
    {
        $existing = self::migrationRow((string)$entry['key']);
        if ($existing) {
            self::assertChecksum($entry, $existing);
            return ['executionMs' => 0, 'skipped' => true];
        }

        $sql = self::readMigrationSql($entry);
        $pdo = sb_db();
        $startedAt = microtime(true);

        if ($pdo->inTransaction()) {
            throw new SiteBuilderMigrationException('MIGRATION_TRANSACTION_ALREADY_ACTIVE', [
                'migrationKey' => $entry['key'],
            ]);
        }

        $pdo->beginTransaction();

        try {
            $pdo->exec(self::stripTransactionWrapper($sql));
            self::runAfterApplyHook($entry);
            $executionMs = self::elapsedMs($startedAt);
            self::recordApplied($entry, 'executed', $actorUserId, $executionMs, []);
            $pdo->commit();

            return ['executionMs' => $executionMs, 'skipped' => false];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(sprintf(
                'SiteBuilder migration %s failed: %s',
                (string)$entry['key'],
                $e->getMessage()
            ));

            throw new SiteBuilderMigrationException('MIGRATION_FAILED', [
                'migrationKey' => $entry['key'],
            ], $e);
        }
    }

    private static function executeSqlFileWithoutRegistry(array $entry): void
    {
        $sql = self::readMigrationSql($entry);
        $pdo = sb_db();

        if ($pdo->inTransaction()) {
            throw new SiteBuilderMigrationException('MIGRATION_TRANSACTION_ALREADY_ACTIVE');
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec(self::stripTransactionWrapper($sql));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SiteBuilder migration registry bootstrap failed: ' . $e->getMessage());
            throw new SiteBuilderMigrationException('MIGRATION_REGISTRY_BOOTSTRAP_FAILED', [], $e);
        }
    }

    private static function runAfterApplyHook(array $entry): void
    {
        if (($entry['afterApply'] ?? '') !== 'importLegacyPageSections') {
            return;
        }

        require_once __DIR__ . '/PageSectionRepository.php';
        $result = PageSectionRepository::importLegacyJson();

        if (!is_array($result)) {
            throw new RuntimeException('PAGE_SECTION_IMPORT_FAILED');
        }
    }

    private static function recordApplied(
        array $entry,
        string $source,
        int $actorUserId,
        int $executionMs,
        array $metadata
    ): void {
        sb_db_execute(
            "INSERT INTO sitebuilder.schema_migration (
                migration_key, stage_number, filename, title, checksum, source,
                execution_ms, applied_by, applied_at, metadata_json
             ) VALUES (
                :migration_key, :stage_number, :filename, :title, :checksum, :source,
                :execution_ms, :applied_by, NOW(), CAST(:metadata_json AS jsonb)
             )
             ON CONFLICT (migration_key) DO NOTHING",
            [
                ':migration_key' => (string)$entry['key'],
                ':stage_number' => (int)$entry['stage'],
                ':filename' => basename((string)$entry['file']),
                ':title' => (string)$entry['title'],
                ':checksum' => self::checksum($entry),
                ':source' => $source,
                ':execution_ms' => max(0, $executionMs),
                ':applied_by' => $actorUserId > 0 ? $actorUserId : null,
                ':metadata_json' => self::json($metadata),
            ]
        );
    }

    private static function migrationRow(string $key): ?array
    {
        if (!self::registryReady()) {
            return null;
        }

        return sb_db_fetch_one(
            "SELECT migration_key, checksum, source, applied_at
             FROM sitebuilder.schema_migration
             WHERE migration_key = :migration_key",
            [':migration_key' => $key]
        );
    }

    private static function assertChecksum(array $entry, array $existing): void
    {
        $expected = self::checksum($entry);
        $actual = (string)($existing['checksum'] ?? '');

        if ($actual === '' || !hash_equals($actual, $expected)) {
            throw new SiteBuilderMigrationException('MIGRATION_CHECKSUM_DRIFT', [
                'migrationKey' => $entry['key'],
                'expectedChecksum' => $expected,
                'registeredChecksum' => $actual,
            ]);
        }
    }

    private static function readMigrationSql(array $entry): string
    {
        $file = (string)($entry['file'] ?? '');
        if ($file === '' || !is_file($file)) {
            throw new SiteBuilderMigrationException('MIGRATION_FILE_NOT_FOUND', [
                'migrationKey' => $entry['key'] ?? '',
            ]);
        }

        $sql = file_get_contents($file);
        if (!is_string($sql) || trim($sql) === '') {
            throw new SiteBuilderMigrationException('EMPTY_MIGRATION', [
                'migrationKey' => $entry['key'] ?? '',
            ]);
        }

        return $sql;
    }

    private static function stripTransactionWrapper(string $sql): string
    {
        $sql = preg_replace('/(^|\R)\s*BEGIN\s*;\s*/i', '$1', $sql, 1) ?? $sql;
        $sql = preg_replace('/\s*COMMIT\s*;\s*$/i', '', $sql, 1) ?? $sql;
        return trim($sql);
    }

    private static function checksum(array $entry): string
    {
        $file = (string)($entry['file'] ?? '');
        $checksum = is_file($file) ? hash_file('sha256', $file) : false;

        if (!is_string($checksum) || strlen($checksum) !== 64) {
            throw new SiteBuilderMigrationException('MIGRATION_CHECKSUM_FAILED', [
                'migrationKey' => $entry['key'] ?? '',
            ]);
        }

        return $checksum;
    }

    private static function fingerprintPassed(array $entry): bool
    {
        $fingerprint = is_array($entry['fingerprint'] ?? null) ? $entry['fingerprint'] : [];

        foreach (($fingerprint['relations'] ?? []) as $relation) {
            if (!self::relationExists((string)$relation)) {
                return false;
            }
        }

        foreach (($fingerprint['columns'] ?? []) as $column) {
            $parts = explode('.', (string)$column);
            if (count($parts) !== 3 || !self::columnExists($parts[0], $parts[1], $parts[2])) {
                return false;
            }
        }

        return !empty($fingerprint);
    }

    private static function relationExists(string $qualifiedName): bool
    {
        try {
            $row = sb_db_fetch_one(
                'SELECT to_regclass(:qualified_name) AS relation_name',
                [':qualified_name' => $qualifiedName]
            );
            return !empty($row['relation_name']);
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function columnExists(string $schema, string $table, string $column): bool
    {
        try {
            $row = sb_db_fetch_one(
                "SELECT 1 AS found
                 FROM information_schema.columns
                 WHERE table_schema = :table_schema
                   AND table_name = :table_name
                   AND column_name = :column_name
                 LIMIT 1",
                [
                    ':table_schema' => $schema,
                    ':table_name' => $table,
                    ':column_name' => $column,
                ]
            );
            return $row !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function withGlobalLock(callable $callback): array
    {
        $pdo = sb_db();
        if ($pdo->inTransaction()) {
            throw new SiteBuilderMigrationException('MIGRATION_TRANSACTION_ALREADY_ACTIVE');
        }

        $config = self::config();
        $timeout = max(1, (int)($config['migration_lock_timeout_seconds'] ?? 15));
        $deadline = microtime(true) + $timeout;
        $locked = false;

        do {
            $stmt = $pdo->prepare('SELECT pg_try_advisory_lock(:lock_class, :lock_id) AS locked');
            $stmt->execute([
                ':lock_class' => self::LOCK_CLASS,
                ':lock_id' => self::LOCK_ID,
            ]);
            $value = $stmt->fetchColumn();
            $locked = $value === true || $value === 't' || $value === 1 || $value === '1';

            if (!$locked) {
                usleep(200000);
            }
        } while (!$locked && microtime(true) < $deadline);

        if (!$locked) {
            throw new SiteBuilderMigrationException('MIGRATION_LOCK_TIMEOUT');
        }

        try {
            return $callback();
        } finally {
            try {
                $stmt = $pdo->prepare('SELECT pg_advisory_unlock(:lock_class, :lock_id)');
                $stmt->execute([
                    ':lock_class' => self::LOCK_CLASS,
                    ':lock_id' => self::LOCK_ID,
                ]);
            } catch (Throwable $e) {
                error_log('SiteBuilder migration unlock failed: ' . $e->getMessage());
            }
        }
    }

    private static function startRun(string $mode, int $actorUserId): int
    {
        $row = sb_db_fetch_one(
            "INSERT INTO sitebuilder.deployment_run (
                mode, status, actor_user_id, host_name, php_sapi, started_at, details_json
             ) VALUES (
                :mode, 'running', :actor_user_id, :host_name, :php_sapi, NOW(), '{}'::jsonb
             )
             RETURNING id",
            [
                ':mode' => $mode,
                ':actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
                ':host_name' => (string)(gethostname() ?: ''),
                ':php_sapi' => PHP_SAPI,
            ]
        );

        return (int)($row['id'] ?? 0);
    }

    private static function updateRunCurrent(int $runId, string $migrationKey): void
    {
        if ($runId <= 0) {
            return;
        }

        sb_db_execute(
            "UPDATE sitebuilder.deployment_run
             SET current_migration_key = :migration_key
             WHERE id = :id",
            [':migration_key' => $migrationKey, ':id' => $runId]
        );
    }

    private static function currentRunMigration(int $runId): ?string
    {
        if ($runId <= 0) {
            return null;
        }

        $row = sb_db_fetch_one(
            'SELECT current_migration_key FROM sitebuilder.deployment_run WHERE id = :id',
            [':id' => $runId]
        );

        $value = trim((string)($row['current_migration_key'] ?? ''));
        return $value !== '' ? $value : null;
    }

    private static function finishRun(int $runId, string $status, array $data): void
    {
        if ($runId <= 0) {
            return;
        }

        sb_db_execute(
            "UPDATE sitebuilder.deployment_run
             SET status = :status,
                 current_migration_key = NULL,
                 applied_count = :applied_count,
                 baseline_count = :baseline_count,
                 skipped_count = :skipped_count,
                 failed_migration_key = :failed_migration_key,
                 error_code = :error_code,
                 details_json = CAST(:details_json AS jsonb),
                 finished_at = NOW(),
                 duration_ms = :duration_ms
             WHERE id = :id",
            [
                ':status' => $status,
                ':applied_count' => max(0, (int)($data['appliedCount'] ?? 0)),
                ':baseline_count' => max(0, (int)($data['baselineCount'] ?? 0)),
                ':skipped_count' => max(0, (int)($data['skippedCount'] ?? 0)),
                ':failed_migration_key' => $data['failedMigrationKey'] ?? null,
                ':error_code' => $data['errorCode'] ?? null,
                ':details_json' => self::json(is_array($data['details'] ?? null) ? $data['details'] : []),
                ':duration_ms' => isset($data['durationMs']) ? max(0, (int)$data['durationMs']) : null,
                ':id' => $runId,
            ]
        );
    }

    private static function elapsedMs(float $startedAt): int
    {
        return max(0, (int)round((microtime(true) - $startedAt) * 1000));
    }

    private static function json(array $value): string
    {
        /*
         * Поля metadata_json и details_json имеют CHECK:
         * jsonb_typeof(...) = 'object'.
         *
         * В PHP пустой массив кодируется как [], то есть JSON-массив,
         * поэтому для пустого значения принудительно записываем {}.
         */
        $payload = $value === [] ? new stdClass() : $value;

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($json)) {
            throw new RuntimeException('JSON_ENCODE_FAILED');
        }

        return $json;
    }
}
