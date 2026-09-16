<?php

require_once __DIR__ . '/db.php';

/** Transactional outbox для операций с внешними подсистемами Битрикс24. */
final class OutboxService
{
    public const JOB_GROUP_ENSURE = 'bitrix.group.ensure';
    public const JOB_ACCESS_SYNC = 'bitrix.access.sync';
    public const JOB_UNIFIED_ACCESS_RECONCILE = 'access.unified.reconcile';
    public const JOB_GROUP_MEMBER_RECONCILE = 'bitrix.group.member.reconcile';
    public const JOB_DISK_FOLDER_ENSURE = 'disk.site_folder.ensure';
    public const JOB_GROUP_DELETE = 'bitrix.group.delete';
    public const JOB_DISK_FOLDER_DELETE = 'disk.site_folder.delete';
    public const JOB_EXTERNAL_RECONCILE = 'external.resources.reconcile';

    private const ACTIVE_STATUSES = ['pending', 'running', 'retry'];
    private const FINAL_STATUSES = ['succeeded', 'cancelled', 'dead'];

    public static function enqueue(
        string $jobType,
        int $siteId,
        array $payload,
        string $dedupeKey,
        int $createdBy = 0,
        int $priority = 100,
        int $maxAttempts = 8,
        ?DateTimeInterface $availableAt = null
    ): array {
        $jobType = self::normalizeJobType($jobType);
        $siteId = max(0, $siteId);
        $dedupeKey = trim($dedupeKey);
        if ($dedupeKey === '') {
            throw new InvalidArgumentException('OUTBOX_DEDUPE_KEY_REQUIRED');
        }

        $stmt = sb_db()->prepare("
            INSERT INTO sitebuilder.outbox_job (
                job_type,site_id,aggregate_type,aggregate_id,payload_json,dedupe_key,
                status,priority,attempts,max_attempts,available_at,created_by,created_at,updated_at
            ) VALUES (
                :job_type,:site_id,'site',:aggregate_id,CAST(:payload_json AS jsonb),:dedupe_key,
                'pending',:priority,0,:max_attempts,:available_at,:created_by,NOW(),NOW()
            )
            ON CONFLICT (dedupe_key)
            WHERE dedupe_key IS NOT NULL AND status IN ('pending','running','retry')
            DO UPDATE SET updated_at = sitebuilder.outbox_job.updated_at
            RETURNING *, (xmax = 0) AS inserted
        ");
        $stmt->execute([
            ':job_type' => $jobType,
            ':site_id' => $siteId > 0 ? $siteId : null,
            ':aggregate_id' => $siteId > 0 ? $siteId : null,
            ':payload_json' => json_encode((object)$payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':dedupe_key' => mb_substr($dedupeKey, 0, 255),
            ':priority' => max(1, min(1000, $priority)),
            ':max_attempts' => max(1, min(100, $maxAttempts)),
            ':available_at' => ($availableAt ?: new DateTimeImmutable())->format('c'),
            ':created_by' => $createdBy > 0 ? $createdBy : null,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('OUTBOX_ENQUEUE_FAILED');
        }

        $inserted = in_array($row['inserted'] ?? false, [true, 1, '1', 't', 'true'], true);
        self::recordEvent((int)$row['id'], $inserted ? 'enqueued' : 'deduplicated', [
            'jobType' => $jobType,
            'dedupeKey' => $dedupeKey,
        ]);

        return self::mapRow($row) + ['deduplicated' => !$inserted];
    }

    public static function enqueueGroupEnsure(int $siteId, int $ownerUserId): array
    {
        return self::enqueue(
            self::JOB_GROUP_ENSURE,
            $siteId,
            ['ownerUserId' => $ownerUserId],
            'site:' . $siteId . ':group.ensure',
            $ownerUserId,
            50
        );
    }

    public static function enqueueAccessSync(int $siteId, int $actorUserId, int $delaySeconds = 0): array
    {
        $availableAt = (new DateTimeImmutable())->modify('+' . max(0, $delaySeconds) . ' seconds');
        return self::enqueue(
            self::JOB_ACCESS_SYNC,
            $siteId,
            ['actorUserId' => $actorUserId],
            'site:' . $siteId . ':access.sync',
            $actorUserId,
            80,
            8,
            $availableAt
        );
    }

    /**
     * Ставит надёжную одностороннюю сверку прав SiteBuilder -> портал/Диск.
     *
     * Ключ намеренно уникален для каждого события. Если новое изменение
     * произойдёт, пока предыдущая сверка уже выполняется, оно не должно
     * дедуплицироваться в running-задачу и потеряться. Advisory lock воркера
     * всё равно последовательно выполняет задания одного сайта.
     */
    public static function enqueueUnifiedAccessReconcile(
        int $siteId,
        string $mode,
        int $actorUserId,
        int $delaySeconds = 0
    ): array {
        if ($siteId <= 0 || $actorUserId <= 0) {
            throw new InvalidArgumentException('INVALID_ACCESS_RECONCILE_CONTEXT');
        }

        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['audit', 'repair'], true)) {
            throw new InvalidArgumentException('INVALID_ACCESS_RECONCILE_MODE');
        }

        $availableAt = (new DateTimeImmutable())->modify(
            '+' . max(0, $delaySeconds) . ' seconds'
        );

        return self::enqueue(
            self::JOB_UNIFIED_ACCESS_RECONCILE,
            $siteId,
            [
                'mode' => $mode,
                'actorUserId' => $actorUserId,
            ],
            sprintf(
                'site:%d:access:%s:%s',
                $siteId,
                $mode,
                bin2hex(random_bytes(12))
            ),
            $actorUserId,
            80,
            10,
            $availableAt
        );
    }

    public static function enqueueGroupMemberReconcile(
        int $siteId,
        int $targetUserId,
        int $actorUserId
    ): array {
        if ($targetUserId <= 0) {
            throw new InvalidArgumentException('TARGET_USER_ID_REQUIRED');
        }

        return self::enqueue(
            self::JOB_GROUP_MEMBER_RECONCILE,
            $siteId,
            [
                'targetUserId' => $targetUserId,
                'actorUserId' => $actorUserId,
            ],
            sprintf(
                'site:%d:member:%d:reconcile:%s',
                $siteId,
                $targetUserId,
                bin2hex(random_bytes(8))
            ),
            $actorUserId,
            60,
            8
        );
    }

    public static function enqueueAllGroupMembersReconcile(
        int $siteId,
        int $actorUserId
    ): array {
        $rows = sb_db_fetch_all("
            SELECT access_code
            FROM sitebuilder.access
            WHERE site_id=:site_id AND access_code ~ '^U[0-9]+$'
            ORDER BY access_code ASC
        ", [':site_id' => $siteId]);

        $jobs = [];
        foreach ($rows as $row) {
            if (!preg_match('/^U(\d+)$/', (string)($row['access_code'] ?? ''), $match)) {
                continue;
            }
            $targetUserId = (int)$match[1];
            if ($targetUserId <= 0) {
                continue;
            }
            $jobs[] = self::enqueueGroupMemberReconcile(
                $siteId,
                $targetUserId,
                $actorUserId
            );
        }

        return $jobs;
    }

    public static function enqueueDiskFolderEnsure(int $siteId, int $actorUserId): array
    {
        return self::enqueue(
            self::JOB_DISK_FOLDER_ENSURE,
            $siteId,
            ['actorUserId' => $actorUserId],
            'site:' . $siteId . ':disk.folder.ensure',
            $actorUserId,
            70
        );
    }

    public static function enqueueSiteProvisioning(int $siteId, int $actorUserId): array
    {
        return [
            'group' => self::enqueueGroupEnsure($siteId, $actorUserId),
            'disk' => self::enqueueDiskFolderEnsure($siteId, $actorUserId),
        ];
    }

    public static function enqueueGroupDelete(array $site, int $actorUserId): ?array
    {
        $siteId = (int)($site['id'] ?? 0);
        $groupId = (int)($site['bitrixGroupId'] ?? $site['bitrix_group_id'] ?? 0);
        if ($siteId <= 0 || $groupId <= 0) {
            return null;
        }

        $managed = (int)($site['bitrixGroupCreatedBy'] ?? $site['bitrix_group_created_by'] ?? 0) > 0;
        return self::enqueue(
            self::JOB_GROUP_DELETE,
            $siteId,
            [
                'siteId' => $siteId,
                'siteName' => (string)($site['name'] ?? ''),
                'groupId' => $groupId,
                'managed' => $managed,
                'actorUserId' => $actorUserId,
            ],
            sprintf('site:%d:group:%d:delete', $siteId, $groupId),
            $actorUserId,
            20,
            20
        );
    }

    public static function enqueueDiskFolderDelete(array $site, int $actorUserId): ?array
    {
        $siteId = (int)($site['id'] ?? 0);
        $folderId = (int)($site['diskFolderId'] ?? $site['disk_folder_id'] ?? 0);
        if ($siteId <= 0 || $folderId <= 0) {
            return null;
        }

        return self::enqueue(
            self::JOB_DISK_FOLDER_DELETE,
            $siteId,
            [
                'siteId' => $siteId,
                'siteName' => (string)($site['name'] ?? ''),
                'siteSlug' => (string)($site['slug'] ?? ''),
                'folderId' => $folderId,
                'actorUserId' => $actorUserId,
            ],
            sprintf('site:%d:disk-folder:%d:delete', $siteId, $folderId),
            $actorUserId,
            25,
            20
        );
    }

    public static function enqueueExternalReconcile(
        int $siteId,
        string $mode,
        int $actorUserId,
        int $delaySeconds = 0
    ): array {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['audit', 'repair'], true)) {
            throw new InvalidArgumentException('INVALID_RECONCILE_MODE');
        }
        $availableAt = (new DateTimeImmutable())->modify('+' . max(0, $delaySeconds) . ' seconds');
        return self::enqueue(
            self::JOB_EXTERNAL_RECONCILE,
            max(0, $siteId),
            ['mode' => $mode, 'actorUserId' => $actorUserId],
            sprintf('external:reconcile:%d:%s', max(0, $siteId), $mode),
            $actorUserId,
            90,
            5,
            $availableAt
        );
    }

    public static function enqueueOrphanGroupDelete(int $groupId, string $name, int $actorUserId): array
    {
        if ($groupId <= 0) {
            throw new InvalidArgumentException('INVALID_BITRIX_GROUP_ID');
        }
        return self::enqueue(
            self::JOB_GROUP_DELETE,
            0,
            [
                'siteId' => 0,
                'siteName' => '',
                'groupId' => $groupId,
                'groupName' => $name,
                'managed' => true,
                'orphanCleanup' => true,
                'actorUserId' => $actorUserId,
            ],
            sprintf('orphan:group:%d:delete', $groupId),
            $actorUserId,
            20,
            20
        );
    }

    public static function enqueueOrphanDiskFolderDelete(int $folderId, string $name, int $actorUserId): array
    {
        if ($folderId <= 0) {
            throw new InvalidArgumentException('INVALID_DISK_FOLDER_ID');
        }
        return self::enqueue(
            self::JOB_DISK_FOLDER_DELETE,
            0,
            [
                'siteId' => 0,
                'siteName' => '',
                'siteSlug' => '',
                'folderId' => $folderId,
                'folderName' => $name,
                'orphanCleanup' => true,
                'actorUserId' => $actorUserId,
            ],
            sprintf('orphan:disk-folder:%d:delete', $folderId),
            $actorUserId,
            25,
            20
        );
    }

    public static function enqueueSiteCleanup(array $site, int $actorUserId): array
    {
        $jobs = [];
        $group = self::enqueueGroupDelete($site, $actorUserId);
        if ($group) {
            $jobs['group'] = $group;
        }
        $disk = self::enqueueDiskFolderDelete($site, $actorUserId);
        if ($disk) {
            $jobs['disk'] = $disk;
        }
        return $jobs;
    }

    /** Отменяет ещё не запущенные задания сайта перед постановкой cleanup-заданий. */
    public static function cancelPendingForSite(int $siteId, int $actorUserId): int
    {
        if ($siteId <= 0) {
            return 0;
        }
        $rows = sb_db_fetch_all("
            UPDATE sitebuilder.outbox_job
            SET status='cancelled',locked_at=NULL,locked_by=NULL,completed_at=NOW(),updated_at=NOW(),
                last_error_code='SITE_DELETED',last_error_at=NOW()
            WHERE site_id=:site_id AND status IN ('pending','retry')
            RETURNING id
        ", [':site_id' => $siteId]);
        foreach ($rows as $row) {
            self::recordEvent((int)$row['id'], 'cancelled_site_deleted', [
                'actorUserId' => $actorUserId,
                'siteId' => $siteId,
            ]);
        }
        return count($rows);
    }

    public static function get(int $jobId): ?array
    {
        $row = sb_db_fetch_one('SELECT * FROM sitebuilder.outbox_job WHERE id=:id', [':id' => $jobId]);
        return $row ? self::mapRow($row) : null;
    }

    public static function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        $siteId = (int)($filters['siteId'] ?? 0);
        if ($siteId > 0) {
            $where[] = 'site_id=:site_id';
            $params[':site_id'] = $siteId;
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, array_merge(self::ACTIVE_STATUSES, self::FINAL_STATUSES), true)) {
                throw new InvalidArgumentException('INVALID_JOB_STATUS');
            }
            $where[] = 'status=:status';
            $params[':status'] = $status;
        }
        $jobType = trim((string)($filters['jobType'] ?? ''));
        if ($jobType !== '') {
            $where[] = 'job_type=:job_type';
            $params[':job_type'] = self::normalizeJobType($jobType);
        }

        $limit = max(1, min(200, (int)($filters['limit'] ?? 100)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = sb_db()->prepare("SELECT COUNT(*) FROM sitebuilder.outbox_job {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = sb_db()->prepare("SELECT * FROM sitebuilder.outbox_job {$whereSql} ORDER BY id DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map([self::class, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public static function events(int $jobId): array
    {
        $rows = sb_db_fetch_all(
            'SELECT id,job_id,event_type,details_json,created_at FROM sitebuilder.outbox_job_event WHERE job_id=:job_id ORDER BY id ASC',
            [':job_id' => $jobId]
        );
        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'jobId' => (int)$row['job_id'],
                'eventType' => (string)$row['event_type'],
                'details' => sb_json_decode_assoc($row['details_json'] ?? '{}'),
                'createdAt' => (string)$row['created_at'],
            ];
        }, $rows);
    }

    public static function retry(int $jobId, int $actorUserId): array
    {
        $pdo = sb_db();
        $startedHere = sb_db_transaction_scope_begin();
        try {
            $job = sb_db_fetch_one(
                'SELECT * FROM sitebuilder.outbox_job WHERE id=:id FOR UPDATE',
                [':id' => $jobId]
            );
            if (!$job || !in_array((string)$job['status'], ['dead', 'cancelled', 'retry'], true)) {
                throw new RuntimeException('JOB_NOT_RETRYABLE');
            }

            $dedupeKey = trim((string)($job['dedupe_key'] ?? ''));
            if ($dedupeKey !== '' && !in_array((string)$job['status'], self::ACTIVE_STATUSES, true)) {
                $active = sb_db_fetch_one("
                    SELECT *
                    FROM sitebuilder.outbox_job
                    WHERE dedupe_key=:dedupe_key
                      AND status IN ('pending','running','retry')
                      AND id<>:id
                    ORDER BY id DESC
                    LIMIT 1
                    FOR UPDATE
                ", [':dedupe_key' => $dedupeKey, ':id' => $jobId]);
                if ($active) {
                    self::recordEvent($jobId, 'manual_retry_reused_active', [
                        'actorUserId' => $actorUserId,
                        'activeJobId' => (int)$active['id'],
                    ]);
                    sb_db_transaction_scope_commit($startedHere);
                    return self::mapRow($active) + ['reusedActiveJob' => true];
                }
            }

            $pdo->exec('SAVEPOINT sb_outbox_manual_retry');
            try {
                $stmt = $pdo->prepare("
                    UPDATE sitebuilder.outbox_job
                    SET status='retry',available_at=NOW(),locked_at=NULL,locked_by=NULL,
                        last_error_code=NULL,last_error_at=NULL,completed_at=NULL,updated_at=NOW()
                    WHERE id=:id AND status IN ('dead','cancelled','retry')
                    RETURNING *
                ");
                $stmt->execute([':id' => $jobId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $pdo->exec('RELEASE SAVEPOINT sb_outbox_manual_retry');
            } catch (PDOException $e) {
                $sqlState = (string)($e->errorInfo[0] ?? $e->getCode());
                $pdo->exec('ROLLBACK TO SAVEPOINT sb_outbox_manual_retry');
                $pdo->exec('RELEASE SAVEPOINT sb_outbox_manual_retry');
                if ($sqlState !== '23505' || $dedupeKey === '') {
                    throw $e;
                }
                $active = sb_db_fetch_one("
                    SELECT *
                    FROM sitebuilder.outbox_job
                    WHERE dedupe_key=:dedupe_key
                      AND status IN ('pending','running','retry')
                    ORDER BY id DESC
                    LIMIT 1
                ", [':dedupe_key' => $dedupeKey]);
                if (!$active) {
                    throw $e;
                }
                self::recordEvent($jobId, 'manual_retry_reused_active', [
                    'actorUserId' => $actorUserId,
                    'activeJobId' => (int)$active['id'],
                ]);
                sb_db_transaction_scope_commit($startedHere);
                return self::mapRow($active) + ['reusedActiveJob' => true];
            }

            if (!$row) {
                throw new RuntimeException('JOB_NOT_RETRYABLE');
            }
            self::recordEvent($jobId, 'manual_retry', ['actorUserId' => $actorUserId]);
            sb_db_transaction_scope_commit($startedHere);
            return self::mapRow($row);
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }
    }

    public static function cancel(int $jobId, int $actorUserId): array
    {
        $stmt = sb_db()->prepare("
            UPDATE sitebuilder.outbox_job
            SET status='cancelled',locked_at=NULL,locked_by=NULL,completed_at=NOW(),updated_at=NOW()
            WHERE id=:id AND status IN ('pending','retry')
            RETURNING *
        ");
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('JOB_NOT_CANCELLABLE');
        }
        self::recordEvent($jobId, 'cancelled', ['actorUserId' => $actorUserId]);
        return self::mapRow($row);
    }

    public static function recordEvent(int $jobId, string $eventType, array $details = []): void
    {
        $stmt = sb_db()->prepare("
            INSERT INTO sitebuilder.outbox_job_event (job_id,event_type,details_json)
            VALUES (:job_id,:event_type,CAST(:details_json AS jsonb))
        ");
        $stmt->execute([
            ':job_id' => $jobId,
            ':event_type' => mb_substr(trim($eventType), 0, 50),
            ':details_json' => json_encode((object)$details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    public static function mapRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'jobType' => (string)$row['job_type'],
            'siteId' => !empty($row['site_id']) ? (int)$row['site_id'] : 0,
            'aggregateType' => (string)($row['aggregate_type'] ?? ''),
            'aggregateId' => !empty($row['aggregate_id']) ? (int)$row['aggregate_id'] : 0,
            'payload' => sb_json_decode_assoc($row['payload_json'] ?? '{}'),
            'dedupeKey' => (string)($row['dedupe_key'] ?? ''),
            'status' => (string)$row['status'],
            'priority' => (int)$row['priority'],
            'attempts' => (int)$row['attempts'],
            'maxAttempts' => (int)$row['max_attempts'],
            'availableAt' => (string)$row['available_at'],
            'lockedAt' => (string)($row['locked_at'] ?? ''),
            'lockedBy' => (string)($row['locked_by'] ?? ''),
            'lastErrorCode' => (string)($row['last_error_code'] ?? ''),
            'lastErrorAt' => (string)($row['last_error_at'] ?? ''),
            'result' => sb_json_decode_assoc($row['result_json'] ?? '{}'),
            'createdBy' => !empty($row['created_by']) ? (int)$row['created_by'] : 0,
            'createdAt' => (string)$row['created_at'],
            'updatedAt' => (string)$row['updated_at'],
            'completedAt' => (string)($row['completed_at'] ?? ''),
        ];
    }

    private static function normalizeJobType(string $jobType): string
    {
        $jobType = strtolower(trim($jobType));
        $allowed = [
            self::JOB_GROUP_ENSURE,
            self::JOB_ACCESS_SYNC,
            self::JOB_UNIFIED_ACCESS_RECONCILE,
            self::JOB_GROUP_MEMBER_RECONCILE,
            self::JOB_DISK_FOLDER_ENSURE,
            self::JOB_GROUP_DELETE,
            self::JOB_DISK_FOLDER_DELETE,
            self::JOB_EXTERNAL_RECONCILE,
        ];
        if (!in_array($jobType, $allowed, true)) {
            throw new InvalidArgumentException('INVALID_JOB_TYPE');
        }
        return $jobType;
    }
}
