<?php

require_once __DIR__ . '/db.php';

/** Heartbeat, метрики запусков и health-check transactional outbox. */
final class QueueMonitorService
{
    public static function startRun(string $workerId, int $requestedLimit): int
    {
        $workerId = self::normalizeWorkerId($workerId);
        $host = (string)(gethostname() ?: 'host');
        $pid = getmypid();
        $pdo = sb_db();
        $startedHere = sb_db_transaction_scope_begin();

        try {
            $stmt = $pdo->prepare("
                INSERT INTO sitebuilder.queue_worker_state (
                    worker_id,host_name,process_id,status,current_job_id,started_at,heartbeat_at,
                    last_run_started_at,last_error_code,updated_at
                ) VALUES (
                    :worker_id,:host_name,:process_id,'starting',NULL,NOW(),NOW(),NOW(),NULL,NOW()
                )
                ON CONFLICT (worker_id) DO UPDATE SET
                    host_name=EXCLUDED.host_name,process_id=EXCLUDED.process_id,status='starting',
                    current_job_id=NULL,heartbeat_at=NOW(),last_run_started_at=NOW(),
                    last_error_code=NULL,updated_at=NOW()
            ");
            $stmt->execute([
                ':worker_id' => $workerId,
                ':host_name' => mb_substr($host, 0, 255),
                ':process_id' => $pid !== false ? (int)$pid : null,
            ]);

            $run = $pdo->prepare("
                INSERT INTO sitebuilder.queue_worker_run (worker_id,status,requested_limit,started_at)
                VALUES (:worker_id,'running',:requested_limit,NOW())
                RETURNING id
            ");
            $run->execute([
                ':worker_id' => $workerId,
                ':requested_limit' => max(0, $requestedLimit),
            ]);
            $runId = (int)$run->fetchColumn();
            if ($runId <= 0) {
                throw new RuntimeException('QUEUE_RUN_CREATE_FAILED');
            }
            sb_db_transaction_scope_commit($startedHere);
            return $runId;
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }
    }

    public static function heartbeat(string $workerId, ?int $currentJobId = null, string $status = 'running'): void
    {
        $workerId = self::normalizeWorkerId($workerId);
        if (!in_array($status, ['starting', 'running', 'idle', 'failed', 'stopped'], true)) {
            $status = 'running';
        }

        $stmt = sb_db()->prepare("\n            UPDATE sitebuilder.queue_worker_state\n            SET status=:status,current_job_id=:current_job_id,heartbeat_at=NOW(),updated_at=NOW()\n            WHERE worker_id=:worker_id\n        ");
        $stmt->execute([
            ':worker_id' => $workerId,
            ':status' => $status,
            ':current_job_id' => $currentJobId !== null && $currentJobId > 0 ? $currentJobId : null,
        ]);
    }

    public static function finishRun(string $workerId, int $runId, array $result): void
    {
        $workerId = self::normalizeWorkerId($workerId);
        $claimed = max(0, (int)($result['claimed'] ?? 0));
        $succeeded = max(0, (int)($result['succeeded'] ?? 0));
        $retried = max(0, (int)($result['retried'] ?? 0));
        $dead = max(0, (int)($result['dead'] ?? 0));
        $status = $dead > 0 || $retried > 0 ? 'partial' : 'succeeded';
        $details = self::encodeDetails($result);

        $pdo = sb_db();
        $startedHere = sb_db_transaction_scope_begin();
        try {
            $stmt = $pdo->prepare("\n                UPDATE sitebuilder.queue_worker_run\n                SET status=:status,claimed=:claimed,succeeded=:succeeded,retried=:retried,dead=:dead,\n                    details_json=CAST(:details_json AS jsonb),finished_at=NOW(),\n                    duration_ms=GREATEST(0,(EXTRACT(EPOCH FROM (NOW()-started_at))*1000)::bigint)\n                WHERE id=:id AND worker_id=:worker_id AND status='running'\n            ");
            $stmt->execute([
                ':id' => $runId,
                ':worker_id' => $workerId,
                ':status' => $status,
                ':claimed' => $claimed,
                ':succeeded' => $succeeded,
                ':retried' => $retried,
                ':dead' => $dead,
                ':details_json' => $details,
            ]);

            $state = $pdo->prepare("\n                UPDATE sitebuilder.queue_worker_state\n                SET status='idle',current_job_id=NULL,heartbeat_at=NOW(),last_run_finished_at=NOW(),\n                    last_error_code=NULL,batches_total=batches_total+1,\n                    claimed_total=claimed_total+:claimed,succeeded_total=succeeded_total+:succeeded,\n                    retried_total=retried_total+:retried,dead_total=dead_total+:dead,updated_at=NOW()\n                WHERE worker_id=:worker_id\n            ");
            $state->execute([
                ':worker_id' => $workerId,
                ':claimed' => $claimed,
                ':succeeded' => $succeeded,
                ':retried' => $retried,
                ':dead' => $dead,
            ]);
            sb_db_transaction_scope_commit($startedHere);
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }
    }

    public static function failRun(string $workerId, int $runId, Throwable $error): void
    {
        $workerId = self::normalizeWorkerId($workerId);
        $errorCode = self::errorCode($error);
        $pdo = sb_db();
        $startedHere = sb_db_transaction_scope_begin();
        try {
            $stmt = $pdo->prepare("\n                UPDATE sitebuilder.queue_worker_run\n                SET status='failed',error_code=:error_code,finished_at=NOW(),\n                    duration_ms=GREATEST(0,(EXTRACT(EPOCH FROM (NOW()-started_at))*1000)::bigint)\n                WHERE id=:id AND worker_id=:worker_id AND status='running'\n            ");
            $stmt->execute([':id' => $runId, ':worker_id' => $workerId, ':error_code' => $errorCode]);

            $state = $pdo->prepare("\n                UPDATE sitebuilder.queue_worker_state\n                SET status='failed',current_job_id=NULL,heartbeat_at=NOW(),last_run_finished_at=NOW(),\n                    last_error_code=:error_code,batches_total=batches_total+1,updated_at=NOW()\n                WHERE worker_id=:worker_id\n            ");
            $state->execute([':worker_id' => $workerId, ':error_code' => $errorCode]);
            sb_db_transaction_scope_commit($startedHere);
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }
    }

    public static function health(int $siteId = 0): array
    {
        $config = self::config();
        $params = [];
        $siteSql = '';
        if ($siteId > 0) {
            $siteSql = ' AND site_id=:site_id';
            $params[':site_id'] = $siteId;
        }

        $queue = sb_db_fetch_one("\n            SELECT\n                COUNT(*) FILTER (WHERE status='pending') AS pending,\n                COUNT(*) FILTER (WHERE status='retry') AS retry,\n                COUNT(*) FILTER (WHERE status='running') AS running,\n                COUNT(*) FILTER (WHERE status='dead') AS dead,\n                COUNT(*) FILTER (WHERE status='cancelled') AS cancelled,\n                COUNT(*) FILTER (WHERE status='succeeded') AS succeeded,\n                COUNT(*) FILTER (WHERE status IN ('pending','retry') AND available_at<=NOW()) AS ready,\n                COALESCE(MAX(EXTRACT(EPOCH FROM (NOW()-available_at)))\n                    FILTER (WHERE status IN ('pending','retry') AND available_at<=NOW()),0) AS oldest_ready_seconds,\n                COUNT(*) FILTER (WHERE status='running' AND locked_at < NOW() - (CAST(:running_timeout AS integer) * INTERVAL '1 second')) AS stale_running,\n                COUNT(*) FILTER (WHERE status='dead' AND job_type IN ('bitrix.group.delete','disk.site_folder.delete')) AS dead_cleanup,\n                COUNT(*) FILTER (WHERE status='dead' AND job_type='external.resources.reconcile') AS dead_reconcile\n            FROM sitebuilder.outbox_job\n            WHERE 1=1 {$siteSql}\n        ", $params + [':running_timeout' => max(60, (int)$config['running_timeout_seconds'])]) ?: [];

        $workerRows = sb_db_fetch_all("\n            SELECT worker_id,host_name,process_id,status,current_job_id,heartbeat_at,\n                   last_run_started_at,last_run_finished_at,last_error_code,batches_total,\n                   claimed_total,succeeded_total,retried_total,dead_total,\n                   EXTRACT(EPOCH FROM (NOW()-heartbeat_at)) AS heartbeat_age_seconds\n            FROM sitebuilder.queue_worker_state\n            ORDER BY heartbeat_at DESC\n            LIMIT 20\n        ");

        $recent = sb_db_fetch_one("\n            SELECT COUNT(*) AS runs,\n                   COALESCE(SUM(claimed),0) AS claimed,COALESCE(SUM(succeeded),0) AS succeeded,\n                   COALESCE(SUM(retried),0) AS retried,COALESCE(SUM(dead),0) AS dead,\n                   COALESCE(AVG(duration_ms),0) AS average_duration_ms,\n                   MAX(finished_at) AS last_finished_at\n            FROM sitebuilder.queue_worker_run\n            WHERE started_at >= NOW()-INTERVAL '24 hours'\n        ") ?: [];

        $operations = [
            'openCriticalAlerts' => 0,
            'openWarningAlerts' => 0,
            'externalAnomalies' => 0,
            'orphanedResources' => 0,
        ];
        try {
            if ((bool)sb_db()->query("SELECT to_regclass('sitebuilder.system_alert') IS NOT NULL")->fetchColumn()) {
                $alertParams = [];
                $alertSiteSql = '';
                if ($siteId > 0) {
                    $alertSiteSql = ' AND site_id=:site_id';
                    $alertParams[':site_id'] = $siteId;
                }
                $alertCounts = sb_db_fetch_one("
                    SELECT COUNT(*) FILTER (WHERE severity='critical') AS critical,
                           COUNT(*) FILTER (WHERE severity='warning') AS warning
                    FROM sitebuilder.system_alert
                    WHERE status IN ('open','acknowledged') {$alertSiteSql}
                ", $alertParams) ?: [];
                $operations['openCriticalAlerts'] = (int)($alertCounts['critical'] ?? 0);
                $operations['openWarningAlerts'] = (int)($alertCounts['warning'] ?? 0);
            }
            if ((bool)sb_db()->query("SELECT to_regclass('sitebuilder.external_resource_registry') IS NOT NULL")->fetchColumn()) {
                $resourceParams = [];
                $resourceSiteSql = '';
                if ($siteId > 0) {
                    $resourceSiteSql = ' AND site_id=:site_id';
                    $resourceParams[':site_id'] = $siteId;
                }
                $resourceCounts = sb_db_fetch_one("
                    SELECT COUNT(*) FILTER (WHERE relation_status IN ('missing','mismatched','orphaned')) AS anomalies,
                           COUNT(*) FILTER (WHERE relation_status='orphaned') AS orphaned
                    FROM sitebuilder.external_resource_registry
                    WHERE 1=1 {$resourceSiteSql}
                ", $resourceParams) ?: [];
                $operations['externalAnomalies'] = (int)($resourceCounts['anomalies'] ?? 0);
                $operations['orphanedResources'] = (int)($resourceCounts['orphaned'] ?? 0);
            }
        } catch (Throwable $e) {
            error_log('SiteBuilder operational health counters failed: ' . $e->getMessage());
        }

        $active = (int)($queue['pending'] ?? 0) + (int)($queue['retry'] ?? 0) + (int)($queue['running'] ?? 0);
        $latestHeartbeatAge = $workerRows ? (int)round((float)($workerRows[0]['heartbeat_age_seconds'] ?? 0)) : null;
        $status = 'healthy';
        $checks = [];

        $heartbeatWarning = max(30, (int)$config['heartbeat_warning_seconds']);
        $heartbeatCritical = max($heartbeatWarning, (int)$config['heartbeat_critical_seconds']);
        if ($latestHeartbeatAge === null) {
            $level = $active > 0 ? 'critical' : 'warning';
            $checks[] = ['code' => 'WORKER_HEARTBEAT_MISSING', 'level' => $level];
            $status = self::maxStatus($status, $level);
        } elseif ($latestHeartbeatAge > $heartbeatCritical) {
            $checks[] = ['code' => 'WORKER_HEARTBEAT_STALE', 'level' => 'critical', 'seconds' => $latestHeartbeatAge];
            $status = 'critical';
        } elseif ($latestHeartbeatAge > $heartbeatWarning) {
            $checks[] = ['code' => 'WORKER_HEARTBEAT_DELAYED', 'level' => 'warning', 'seconds' => $latestHeartbeatAge];
            $status = self::maxStatus($status, 'warning');
        }

        $oldest = (int)round((float)($queue['oldest_ready_seconds'] ?? 0));
        if ($oldest > (int)$config['oldest_ready_critical_seconds']) {
            $checks[] = ['code' => 'QUEUE_DELAY_CRITICAL', 'level' => 'critical', 'seconds' => $oldest];
            $status = 'critical';
        } elseif ($oldest > (int)$config['oldest_ready_warning_seconds']) {
            $checks[] = ['code' => 'QUEUE_DELAY_WARNING', 'level' => 'warning', 'seconds' => $oldest];
            $status = self::maxStatus($status, 'warning');
        }

        if ((int)($queue['stale_running'] ?? 0) > 0) {
            $checks[] = ['code' => 'STALE_RUNNING_JOBS', 'level' => 'critical', 'count' => (int)$queue['stale_running']];
            $status = 'critical';
        }
        if ((int)($queue['dead_reconcile'] ?? 0) > 0) {
            $checks[] = ['code' => 'EXTERNAL_RECONCILE_FAILED', 'level' => 'critical', 'count' => (int)$queue['dead_reconcile']];
            $status = 'critical';
        }
        if ((int)($queue['dead_cleanup'] ?? 0) > 0) {
            $checks[] = ['code' => 'EXTERNAL_CLEANUP_FAILED', 'level' => 'critical', 'count' => (int)$queue['dead_cleanup']];
            $status = 'critical';
        } elseif ((int)($queue['dead'] ?? 0) > 0) {
            $checks[] = ['code' => 'DEAD_JOBS_PRESENT', 'level' => 'warning', 'count' => (int)$queue['dead']];
            $status = self::maxStatus($status, 'warning');
        }
        if (!$checks) {
            $checks[] = ['code' => 'QUEUE_OK', 'level' => 'healthy'];
        }

        return [
            'status' => $status,
            'checkedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
            'siteId' => max(0, $siteId),
            'queue' => [
                'pending' => (int)($queue['pending'] ?? 0),
                'retry' => (int)($queue['retry'] ?? 0),
                'running' => (int)($queue['running'] ?? 0),
                'dead' => (int)($queue['dead'] ?? 0),
                'cancelled' => (int)($queue['cancelled'] ?? 0),
                'succeeded' => (int)($queue['succeeded'] ?? 0),
                'ready' => (int)($queue['ready'] ?? 0),
                'oldestReadySeconds' => $oldest,
                'staleRunning' => (int)($queue['stale_running'] ?? 0),
                'deadCleanup' => (int)($queue['dead_cleanup'] ?? 0),
                'deadReconcile' => (int)($queue['dead_reconcile'] ?? 0),
            ],
            'workers' => array_map([self::class, 'mapWorker'], $workerRows),
            'last24Hours' => [
                'runs' => (int)($recent['runs'] ?? 0),
                'claimed' => (int)($recent['claimed'] ?? 0),
                'succeeded' => (int)($recent['succeeded'] ?? 0),
                'retried' => (int)($recent['retried'] ?? 0),
                'dead' => (int)($recent['dead'] ?? 0),
                'averageDurationMs' => (int)round((float)($recent['average_duration_ms'] ?? 0)),
                'lastFinishedAt' => (string)($recent['last_finished_at'] ?? ''),
            ],
            'checks' => $checks,
            'operations' => $operations,
        ];
    }

    private static function mapWorker(array $row): array
    {
        return [
            'workerId' => (string)$row['worker_id'],
            'hostName' => (string)$row['host_name'],
            'processId' => !empty($row['process_id']) ? (int)$row['process_id'] : 0,
            'status' => (string)$row['status'],
            'currentJobId' => !empty($row['current_job_id']) ? (int)$row['current_job_id'] : 0,
            'heartbeatAt' => (string)$row['heartbeat_at'],
            'heartbeatAgeSeconds' => (int)round((float)($row['heartbeat_age_seconds'] ?? 0)),
            'lastRunStartedAt' => (string)($row['last_run_started_at'] ?? ''),
            'lastRunFinishedAt' => (string)($row['last_run_finished_at'] ?? ''),
            'lastErrorCode' => (string)($row['last_error_code'] ?? ''),
            'batchesTotal' => (int)$row['batches_total'],
            'claimedTotal' => (int)$row['claimed_total'],
            'succeededTotal' => (int)$row['succeeded_total'],
            'retriedTotal' => (int)$row['retried_total'],
            'deadTotal' => (int)$row['dead_total'],
        ];
    }

    private static function normalizeWorkerId(string $workerId): string
    {
        $workerId = trim($workerId);
        if ($workerId === '') {
            throw new InvalidArgumentException('WORKER_ID_REQUIRED');
        }
        return mb_substr($workerId, 0, 120);
    }

    private static function encodeDetails(array $details): string
    {
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) {
            return json_encode(['truncated' => true, 'bytes' => strlen($json)], JSON_THROW_ON_ERROR);
        }
        return $json;
    }

    private static function errorCode(Throwable $error): string
    {
        $message = strtoupper(trim($error->getMessage()));
        if (preg_match('/^[A-Z][A-Z0-9_]{2,119}/', $message, $match)) {
            return $match[0];
        }
        return 'QUEUE_RUN_FAILED';
    }

    private static function maxStatus(string $left, string $right): string
    {
        $rank = ['healthy' => 0, 'warning' => 1, 'critical' => 2];
        return ($rank[$right] ?? 0) > ($rank[$left] ?? 0) ? $right : $left;
    }

    private static function config(): array
    {
        static $config;
        if (is_array($config)) {
            return $config;
        }
        $defaults = [
            'running_timeout_seconds' => 900,
            'heartbeat_warning_seconds' => 180,
            'heartbeat_critical_seconds' => 900,
            'oldest_ready_warning_seconds' => 300,
            'oldest_ready_critical_seconds' => 900,
        ];
        $path = dirname(__DIR__) . '/config/queue.php';
        $loaded = is_file($path) ? require $path : [];
        $config = array_merge($defaults, is_array($loaded) ? $loaded : []);
        return $config;
    }
}
