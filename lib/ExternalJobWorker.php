<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/OutboxService.php';
require_once __DIR__ . '/RevisionService.php';
require_once __DIR__ . '/SiteBitrixGroupService.php';
require_once __DIR__ . '/SiteAccessSyncService.php';
require_once __DIR__ . '/SiteAccessManagementService.php';
require_once __DIR__ . '/disk.php';
require_once __DIR__ . '/QueueMonitorService.php';
require_once __DIR__ . '/SystemAlertService.php';
require_once __DIR__ . '/ExternalResourceReconcileService.php';

final class ExternalJobWorker
{
    public static function runBatch(?int $limit = null, ?string $workerId = null): array
    {
        $config = self::config();
        $limit = max(1, min(200, $limit ?? (int)$config['batch_size']));
        $workerId = trim((string)$workerId) ?: self::defaultWorkerId();
        $runId = self::monitorStart($workerId, $limit);

        try {
            try {
                ExternalResourceReconcileService::enqueueIfDue();
            } catch (Throwable $scheduleError) {
                error_log('SiteBuilder reconcile scheduling failed: ' . $scheduleError->getMessage());
            }
            self::recoverStaleJobs((int)$config['running_timeout_seconds']);
            $result = ['claimed' => 0, 'succeeded' => 0, 'retried' => 0, 'dead' => 0, 'jobs' => []];

            for ($i = 0; $i < $limit; $i++) {
                self::monitorHeartbeat($workerId, null, 'running');
                $job = self::claimOne($workerId);
                if (!$job) {
                    break;
                }
                $result['claimed']++;
                self::monitorHeartbeat($workerId, (int)$job['id'], 'running');

                try {
                    $jobResult = self::execute($job);
                    self::markSucceeded($job, $jobResult);
                    $result['succeeded']++;
                    $result['jobs'][] = ['id' => (int)$job['id'], 'status' => 'succeeded'];
                } catch (Throwable $e) {
                    try {
                        $failure = self::markFailed($job, $e);
                        $result[$failure['status'] === 'dead' ? 'dead' : 'retried']++;
                        $result['jobs'][] = [
                            'id' => (int)$job['id'],
                            'status' => $failure['status'],
                            'error' => $failure['errorCode'],
                        ];
                    } catch (RuntimeException $stateError) {
                        if ($stateError->getMessage() !== 'JOB_STATE_CHANGED') {
                            throw $stateError;
                        }
                        $result['jobs'][] = [
                            'id' => (int)$job['id'],
                            'status' => 'state_changed',
                            'error' => 'JOB_STATE_CHANGED',
                        ];
                    }
                    error_log('SiteBuilder external job #' . (int)$job['id'] . ' failed: ' . $e->getMessage());
                } finally {
                    self::monitorHeartbeat($workerId, null, 'running');
                }
            }

            self::monitorFinish($workerId, $runId, $result);
            try {
                SystemAlertService::synchronizeQueueHealth(QueueMonitorService::health());
            } catch (Throwable $alertError) {
                error_log('SiteBuilder queue alert synchronization failed: ' . $alertError->getMessage());
            }
            return $result;
        } catch (Throwable $e) {
            self::monitorFail($workerId, $runId, $e);
            throw $e;
        }
    }

    private static function claimOne(string $workerId): ?array
    {
        $pdo = sb_db();
        $pdo->beginTransaction();
        try {
            $row = sb_db_fetch_one("
                SELECT *
                FROM sitebuilder.outbox_job
                WHERE status IN ('pending','retry') AND available_at <= NOW()
                ORDER BY priority ASC, available_at ASC, id ASC
                FOR UPDATE SKIP LOCKED
                LIMIT 1
            ");
            if (!$row) {
                $pdo->commit();
                return null;
            }

            $stmt = $pdo->prepare("
                UPDATE sitebuilder.outbox_job
                SET status='running',attempts=attempts+1,locked_at=NOW(),locked_by=:locked_by,updated_at=NOW()
                WHERE id=:id
                RETURNING *
            ");
            $stmt->execute([':id' => (int)$row['id'], ':locked_by' => mb_substr($workerId, 0, 120)]);
            $claimed = $stmt->fetch(PDO::FETCH_ASSOC);
            OutboxService::recordEvent((int)$row['id'], 'claimed', ['workerId' => $workerId]);
            $pdo->commit();
            return $claimed ? OutboxService::mapRow($claimed) : null;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function execute(array $job): array
    {
        $siteId = (int)($job['siteId'] ?? 0);
        if ($siteId <= 0) {
            return self::executeType($job);
        }

        /*
         * Совместимо с RequestLockService::site.lifecycle. Удаление сайта
         * получает exclusive lock и не пересекается с внешней операцией.
         */
        return self::withAdvisoryLock(
            761236,
            $siteId,
            true,
            static fn(): array => self::executeType($job)
        );
    }

    private static function executeType(array $job): array
    {
        return match ((string)$job['jobType']) {
            OutboxService::JOB_GROUP_ENSURE => self::ensureGroup($job),
            OutboxService::JOB_ACCESS_SYNC => self::syncAccess($job),
            OutboxService::JOB_GROUP_MEMBER_RECONCILE => self::reconcileGroupMember($job),
            OutboxService::JOB_DISK_FOLDER_ENSURE => self::ensureDiskFolder($job),
            OutboxService::JOB_GROUP_DELETE => self::deleteGroup($job),
            OutboxService::JOB_DISK_FOLDER_DELETE => self::deleteDiskFolder($job),
            OutboxService::JOB_EXTERNAL_RECONCILE => self::reconcileExternalResources($job),
            default => throw new RuntimeException('UNSUPPORTED_JOB_TYPE'),
        };
    }

    private static function ensureGroup(array $job): array
    {
        $siteId = (int)$job['siteId'];
        $payload = (array)$job['payload'];
        $site = RevisionService::getSite($siteId, false);
        if (!$site) {
            return ['skipped' => true, 'reason' => 'site_missing'];
        }
        $existingGroupId = (int)($site['bitrixGroupId'] ?? 0);
        if ($existingGroupId > 0) {
            return ['created' => false, 'bitrixGroupId' => $existingGroupId];
        }

        $ownerUserId = (int)($payload['ownerUserId'] ?? $site['createdBy'] ?? 0);
        if ($ownerUserId <= 0) {
            throw new RuntimeException('EMPTY_OWNER_USER_ID');
        }

        $newGroupId = SiteBitrixGroupService::createForSite($site, $ownerUserId);
        $pdo = sb_db();
        $pdo->beginTransaction();
        try {
            $current = RevisionService::getSite($siteId, true);
            if (!$current) {
                $pdo->rollBack();
                SiteBitrixGroupService::deleteCreatedGroup($newGroupId);
                return ['skipped' => true, 'reason' => 'site_deleted_during_job'];
            }

            $currentGroupId = (int)($current['bitrixGroupId'] ?? 0);
            if ($currentGroupId > 0) {
                $pdo->commit();
                SiteBitrixGroupService::deleteCreatedGroup($newGroupId);
                return ['created' => false, 'bitrixGroupId' => $currentGroupId, 'duplicateCompensated' => true];
            }

            $current['bitrixGroupId'] = $newGroupId;
            $current['bitrixGroupCreatedBy'] = $ownerUserId;
            $current['bitrixGroupCreatedAt'] = date('c');
            $saved = RevisionService::saveSite($current, (int)$current['version'], $ownerUserId, 'queue_bitrix_group_attach');
            $memberJobs = OutboxService::enqueueAllGroupMembersReconcile($siteId, $ownerUserId);
            $pdo->commit();
            return [
                'created' => true,
                'bitrixGroupId' => $newGroupId,
                'siteVersion' => (int)$saved['version'],
                'memberReconcileJobs' => array_map(
                    static fn(array $memberJob): int => (int)$memberJob['id'],
                    $memberJobs
                ),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                SiteBitrixGroupService::deleteCreatedGroup($newGroupId);
            } catch (Throwable $cleanupError) {
                error_log('SiteBuilder could not compensate Bitrix group #' . $newGroupId . ': ' . $cleanupError->getMessage());
            }
            throw $e;
        }
    }

    private static function syncAccess(array $job): array
    {
        $siteId = (int)$job['siteId'];
        $payload = (array)$job['payload'];
        $site = RevisionService::getSite($siteId, false);
        if (!$site) {
            return ['skipped' => true, 'reason' => 'site_missing'];
        }
        if ((int)($site['bitrixGroupId'] ?? 0) <= 0) {
            throw new RuntimeException('BITRIX_GROUP_NOT_READY');
        }
        $actorUserId = (int)($payload['actorUserId'] ?? $site['createdBy'] ?? 0);
        return SiteAccessSyncService::sync($siteId, $actorUserId, true);
    }

    private static function reconcileGroupMember(array $job): array
    {
        $siteId = (int)$job['siteId'];
        $payload = (array)$job['payload'];
        $targetUserId = (int)($payload['targetUserId'] ?? 0);
        if ($targetUserId <= 0) {
            throw new RuntimeException('TARGET_USER_ID_REQUIRED');
        }

        $site = RevisionService::getSite($siteId, false);
        if (!$site) {
            return ['skipped' => true, 'reason' => 'site_missing'];
        }
        if ((int)($site['bitrixGroupId'] ?? 0) <= 0) {
            throw new RuntimeException('BITRIX_GROUP_NOT_READY');
        }

        $actorUserId = (int)($payload['actorUserId'] ?? $site['createdBy'] ?? 0);
        $hash = (int)(crc32($siteId . ':' . $targetUserId) & 0x7fffffff);
        return self::withAdvisoryLock(
            761317,
            $hash,
            false,
            static fn(): array => SiteAccessManagementService::reconcileUserMembership(
                $siteId,
                $targetUserId,
                $actorUserId
            )
        );
    }

    private static function withAdvisoryLock(
        int $namespace,
        int $resource,
        bool $shared,
        callable $callback
    ): mixed {
        $pdo = sb_db();
        $lockFunction = $shared ? 'pg_advisory_lock_shared' : 'pg_advisory_lock';
        $unlockFunction = $shared ? 'pg_advisory_unlock_shared' : 'pg_advisory_unlock';
        $lock = $pdo->prepare("SELECT {$lockFunction}(:namespace,:resource)");
        $unlock = $pdo->prepare("SELECT {$unlockFunction}(:namespace,:resource)");
        $lock->execute([':namespace' => $namespace, ':resource' => $resource]);
        try {
            return $callback();
        } finally {
            try {
                $unlock->execute([':namespace' => $namespace, ':resource' => $resource]);
            } catch (Throwable $e) {
                error_log('SiteBuilder worker advisory unlock failed: ' . $e->getMessage());
            }
        }
    }

    private static function ensureDiskFolder(array $job): array
    {
        $siteId = (int)$job['siteId'];
        $payload = (array)$job['payload'];
        $site = RevisionService::getSite($siteId, false);
        if (!$site) {
            return ['skipped' => true, 'reason' => 'site_missing'];
        }
        $existing = sb_disk_get_site_folder($siteId);
        if ($existing) {
            return ['created' => false, 'folderId' => (int)$existing->getId()];
        }
        return self::withAdvisoryLock(761315, $siteId, false, static function () use ($siteId, $payload, $site): array {
            $existing = sb_disk_get_site_folder($siteId);
            if ($existing) {
                return ['created' => false, 'folderId' => (int)$existing->getId()];
            }
            $folder = sb_disk_ensure_site_folder(
                $siteId,
                (int)($payload['actorUserId'] ?? $site['createdBy'] ?? 0)
            );
            return ['created' => true, 'folderId' => (int)$folder->getId()];
        });
    }

    private static function deleteGroup(array $job): array
    {
        $payload = (array)$job['payload'];
        $groupId = (int)($payload['groupId'] ?? 0);
        if ($groupId <= 0) {
            throw new RuntimeException('BITRIX_GROUP_ID_REQUIRED');
        }
        if (empty($payload['managed'])) {
            return ['skipped' => true, 'reason' => 'group_not_marked_managed', 'groupId' => $groupId];
        }

        return self::withAdvisoryLock(
            761318,
            $groupId,
            false,
            static function () use ($groupId, $payload): array {
                if (!empty($payload['orphanCleanup']) && self::externalResourceIsReferenced('bitrix_group', $groupId)) {
                    return [
                        'skipped' => true,
                        'reason' => 'resource_attached_after_reconcile',
                        'groupId' => $groupId,
                    ];
                }
                return SiteBitrixGroupService::deleteManagedGroup(
                    $groupId,
                    (string)($payload['siteName'] ?? '')
                );
            }
        );
    }

    private static function deleteDiskFolder(array $job): array
    {
        $payload = (array)$job['payload'];
        $folderId = (int)($payload['folderId'] ?? 0);
        if ($folderId <= 0) {
            throw new RuntimeException('DISK_FOLDER_ID_REQUIRED');
        }

        return self::withAdvisoryLock(
            761319,
            $folderId,
            false,
            static function () use ($folderId, $payload): array {
                if (!empty($payload['orphanCleanup']) && self::externalResourceIsReferenced('disk_folder', $folderId)) {
                    return [
                        'skipped' => true,
                        'reason' => 'resource_attached_after_reconcile',
                        'folderId' => $folderId,
                    ];
                }
                return sb_disk_delete_managed_site_folder(
                    $folderId,
                    (int)($payload['actorUserId'] ?? 0)
                );
            }
        );
    }

    private static function externalResourceIsReferenced(string $resourceType, int $externalId): bool
    {
        if ($externalId <= 0) {
            return false;
        }
        $column = match ($resourceType) {
            'bitrix_group' => 'bitrix_group_id',
            'disk_folder' => 'disk_folder_id',
            default => throw new InvalidArgumentException('INVALID_RESOURCE_TYPE'),
        };
        $stmt = sb_db()->prepare('SELECT 1 FROM sitebuilder.site WHERE ' . $column . '=:external_id LIMIT 1');
        $stmt->execute([':external_id' => $externalId]);
        return (bool)$stmt->fetchColumn();
    }

    private static function reconcileExternalResources(array $job): array
    {
        $payload = (array)$job['payload'];
        return ExternalResourceReconcileService::run(
            (int)($job['siteId'] ?? 0),
            (string)($payload['mode'] ?? 'audit'),
            (int)($payload['actorUserId'] ?? 0),
            (int)$job['id']
        );
    }

    private static function markSucceeded(array $job, array $result): void
    {
        $jobId = (int)$job['id'];
        self::withJobStateTransaction(static function () use ($jobId, $job, $result): void {
            $resultJson = self::encodeLimited($result);
            $eventDetails = json_decode($resultJson, true, 512, JSON_THROW_ON_ERROR);
            $stmt = sb_db()->prepare("
                UPDATE sitebuilder.outbox_job
                SET status='succeeded',result_json=CAST(:result_json AS jsonb),locked_at=NULL,locked_by=NULL,
                    last_error_code=NULL,last_error_at=NULL,completed_at=NOW(),updated_at=NOW()
                WHERE id=:id AND status='running'
            ");
            $stmt->execute([':id' => $jobId, ':result_json' => $resultJson]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('JOB_STATE_CHANGED');
            }
            OutboxService::recordEvent(
                $jobId,
                'succeeded',
                is_array($eventDetails) ? $eventDetails : ['result' => $eventDetails]
            );
            /*
             * Оповещения и реестр ресурсов — вспомогательные данные этапа 11.
             * Их отсутствие во время rolling deployment не должно превращать
             * уже успешно выполненную внешнюю операцию в повторное задание.
             */
            try {
                SystemAlertService::resolveJob($jobId);
                self::markDeletedExternalResource($job);
            } catch (Throwable $stateError) {
                error_log('SiteBuilder stage11 success metadata update failed: ' . $stateError->getMessage());
            }
        });
    }

    private static function markDeletedExternalResource(array $job): void
    {
        $payload = (array)($job['payload'] ?? []);
        $jobType = (string)($job['jobType'] ?? '');
        $resourceType = '';
        $externalId = 0;

        if ($jobType === OutboxService::JOB_GROUP_DELETE) {
            $resourceType = 'bitrix_group';
            $externalId = (int)($payload['groupId'] ?? 0);
        } elseif ($jobType === OutboxService::JOB_DISK_FOLDER_DELETE) {
            $resourceType = 'disk_folder';
            $externalId = (int)($payload['folderId'] ?? 0);
        }

        if ($resourceType === '' || $externalId <= 0) {
            return;
        }

        $tableExists = (bool)sb_db()
            ->query("SELECT to_regclass('sitebuilder.external_resource_registry') IS NOT NULL")
            ->fetchColumn();
        if (!$tableExists) {
            return;
        }

        sb_db_execute("
            UPDATE sitebuilder.external_resource_registry
            SET relation_status='deleted',resolved_at=NOW(),last_checked_at=NOW(),updated_at=NOW()
            WHERE resource_type=:resource_type AND external_id=:external_id
        ", [
            ':resource_type' => $resourceType,
            ':external_id' => $externalId,
        ]);
        SystemAlertService::resolveByKey('external:' . $resourceType . ':' . $externalId . ':orphaned');
    }

    private static function markFailed(array $job, Throwable $e): array
    {
        $attempts = (int)$job['attempts'];
        $maxAttempts = (int)$job['maxAttempts'];
        $dead = $attempts >= $maxAttempts;
        $errorCode = self::errorCode($e);
        $delay = self::retryDelay($attempts);
        $status = $dead ? 'dead' : 'retry';
        $availableAt = $dead
            ? (string)$job['availableAt']
            : (new DateTimeImmutable())->modify('+' . $delay . ' seconds')->format('c');
        $completedAt = $dead ? (new DateTimeImmutable())->format('c') : null;

        self::withJobStateTransaction(static function () use (
            $job,
            $status,
            $availableAt,
            $completedAt,
            $errorCode,
            $dead,
            $attempts,
            $delay
        ): void {
            $stmt = sb_db()->prepare("
                UPDATE sitebuilder.outbox_job
                SET status=:status,available_at=:available_at,locked_at=NULL,locked_by=NULL,
                    last_error_code=:error_code,last_error_at=NOW(),completed_at=:completed_at,updated_at=NOW()
                WHERE id=:id AND status='running'
            ");
            $stmt->execute([
                ':id' => (int)$job['id'],
                ':status' => $status,
                ':available_at' => $availableAt,
                ':completed_at' => $completedAt,
                ':error_code' => $errorCode,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('JOB_STATE_CHANGED');
            }
            OutboxService::recordEvent((int)$job['id'], $dead ? 'dead' : 'retry_scheduled', [
                'errorCode' => $errorCode,
                'attempts' => $attempts,
                'delaySeconds' => $dead ? 0 : $delay,
            ]);
        });

        if ($dead) {
            try {
                SystemAlertService::jobDead($job, $errorCode);
            } catch (Throwable $alertError) {
                error_log('SiteBuilder dead job alert failed: ' . $alertError->getMessage());
            }
        }

        return ['status' => $status, 'errorCode' => $errorCode];
    }

    private static function recoverStaleJobs(int $timeoutSeconds): void
    {
        $timeoutSeconds = max(60, min(86400, $timeoutSeconds));
        $cutoff = (new DateTimeImmutable())->modify('-' . $timeoutSeconds . ' seconds')->format('c');

        self::withJobStateTransaction(static function () use ($cutoff): void {
            $rows = sb_db_fetch_all("
                UPDATE sitebuilder.outbox_job
                SET status=CASE WHEN attempts>=max_attempts THEN 'dead' ELSE 'retry' END,
                    available_at=NOW(),locked_at=NULL,locked_by=NULL,last_error_code='WORKER_TIMEOUT',
                    last_error_at=NOW(),completed_at=CASE WHEN attempts>=max_attempts THEN NOW() ELSE NULL END,updated_at=NOW()
                WHERE status='running' AND locked_at < :cutoff
                RETURNING id,status
            ", [':cutoff' => $cutoff]);
            foreach ($rows as $row) {
                OutboxService::recordEvent((int)$row['id'], 'stale_recovered', [
                    'status' => (string)$row['status'],
                    'errorCode' => 'WORKER_TIMEOUT',
                ]);
            }
        });
    }

    private static function withJobStateTransaction(callable $callback): mixed
    {
        $pdo = sb_db();
        $startedHere = !$pdo->inTransaction();
        if ($startedHere) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($startedHere) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function retryDelay(int $attempt): int
    {
        $config = self::config();
        $base = max(1, (int)$config['retry_base_seconds']);
        $max = max($base, (int)$config['retry_max_seconds']);
        return min($max, $base * (2 ** max(0, min(16, $attempt - 1))));
    }

    private static function errorCode(Throwable $e): string
    {
        $message = strtoupper(trim($e->getMessage()));
        if (preg_match('/^[A-Z][A-Z0-9_]{2,119}/', $message, $match)) {
            return $match[0];
        }
        return 'JOB_EXECUTION_FAILED';
    }

    private static function encodeLimited(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $max = max(1024, (int)self::config()['max_result_bytes']);
        if (strlen($json) <= $max) {
            return $json;
        }
        return json_encode(['truncated' => true, 'bytes' => strlen($json)], JSON_THROW_ON_ERROR);
    }

    private static function monitorStart(string $workerId, int $limit): int
    {
        try {
            return QueueMonitorService::startRun($workerId, $limit);
        } catch (Throwable $e) {
            error_log('SiteBuilder queue monitor start failed: ' . $e->getMessage());
            return 0;
        }
    }

    private static function monitorHeartbeat(string $workerId, ?int $jobId, string $status): void
    {
        try {
            QueueMonitorService::heartbeat($workerId, $jobId, $status);
        } catch (Throwable $e) {
            error_log('SiteBuilder queue heartbeat failed: ' . $e->getMessage());
        }
    }

    private static function monitorFinish(string $workerId, int $runId, array $result): void
    {
        if ($runId <= 0) {
            return;
        }
        try {
            QueueMonitorService::finishRun($workerId, $runId, $result);
        } catch (Throwable $e) {
            error_log('SiteBuilder queue monitor finish failed: ' . $e->getMessage());
        }
    }

    private static function monitorFail(string $workerId, int $runId, Throwable $error): void
    {
        if ($runId <= 0) {
            return;
        }
        try {
            QueueMonitorService::failRun($workerId, $runId, $error);
        } catch (Throwable $e) {
            error_log('SiteBuilder queue monitor failure write failed: ' . $e->getMessage());
        }
    }

    private static function defaultWorkerId(): string
    {
        return (gethostname() ?: 'host') . ':' . getmypid();
    }

    private static function config(): array
    {
        static $config;
        if (is_array($config)) {
            return $config;
        }
        $defaults = [
            'batch_size' => 20,
            'running_timeout_seconds' => 900,
            'retry_base_seconds' => 15,
            'retry_max_seconds' => 3600,
            'max_result_bytes' => 32768,
        ];
        $path = dirname(__DIR__) . '/config/queue.php';
        $loaded = is_file($path) ? require $path : [];
        $config = array_merge($defaults, is_array($loaded) ? $loaded : []);
        return $config;
    }
}
