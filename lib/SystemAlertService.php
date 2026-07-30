<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AlertNotificationService.php';

/** Единый реестр операционных оповещений SiteBuilder. */
final class SystemAlertService
{
    private const SEVERITIES = ['info', 'warning', 'critical'];
    private const STATUSES = ['open', 'acknowledged', 'resolved'];

    public static function openOrTouch(
        string $alertKey,
        string $severity,
        string $code,
        string $title,
        array $details = [],
        int $siteId = 0,
        string $sourceType = '',
        int $sourceId = 0
    ): array {
        $alertKey = trim($alertKey);
        if ($alertKey === '') {
            throw new InvalidArgumentException('ALERT_KEY_REQUIRED');
        }
        $severity = strtolower(trim($severity));
        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException('INVALID_ALERT_SEVERITY');
        }

        $stmt = sb_db()->prepare("\n            INSERT INTO sitebuilder.system_alert (\n                alert_key,severity,status,site_id,source_type,source_id,code,title,details_json,\n                occurrences,first_seen_at,last_seen_at,created_at,updated_at\n            ) VALUES (\n                :alert_key,:severity,'open',:site_id,:source_type,:source_id,:code,:title,CAST(:details AS jsonb),\n                1,NOW(),NOW(),NOW(),NOW()\n            )\n            ON CONFLICT (alert_key) DO UPDATE SET\n                severity=EXCLUDED.severity,\n                status=CASE WHEN sitebuilder.system_alert.status='resolved' THEN 'open' ELSE sitebuilder.system_alert.status END,\n                site_id=EXCLUDED.site_id,source_type=EXCLUDED.source_type,source_id=EXCLUDED.source_id,code=EXCLUDED.code,\n                title=EXCLUDED.title,details_json=EXCLUDED.details_json,\n                occurrences=sitebuilder.system_alert.occurrences+1,last_seen_at=NOW(),\n                acknowledged_by=CASE WHEN sitebuilder.system_alert.status='resolved' THEN NULL ELSE sitebuilder.system_alert.acknowledged_by END,\n                acknowledged_at=CASE WHEN sitebuilder.system_alert.status='resolved' THEN NULL ELSE sitebuilder.system_alert.acknowledged_at END,\n                resolved_by=NULL,resolved_at=NULL,updated_at=NOW()\n            RETURNING *\n        ");
        $stmt->execute([
            ':alert_key' => mb_substr($alertKey, 0, 255),
            ':severity' => $severity,
            ':site_id' => $siteId > 0 ? $siteId : null,
            ':source_type' => mb_substr(trim($sourceType), 0, 50),
            ':source_id' => $sourceId > 0 ? $sourceId : null,
            ':code' => mb_substr(strtoupper(trim($code)), 0, 120),
            ':title' => trim($title),
            ':details' => self::encodeDetails($details),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('ALERT_UPSERT_FAILED');
        }
        $alert = self::mapRow($row);
        try {
            AlertNotificationService::notifyIfDue($alert);
        } catch (Throwable $e) {
            error_log('SiteBuilder alert delivery wrapper failed: ' . $e->getMessage());
        }
        return $alert;
    }

    public static function resolveByKey(string $alertKey, int $actorUserId = 0): bool
    {
        $stmt = sb_db()->prepare("\n            UPDATE sitebuilder.system_alert\n            SET status='resolved',resolved_by=:actor,resolved_at=NOW(),updated_at=NOW()\n            WHERE alert_key=:alert_key AND status<>'resolved'\n        ");
        $stmt->execute([
            ':alert_key' => mb_substr(trim($alertKey), 0, 255),
            ':actor' => $actorUserId > 0 ? $actorUserId : null,
        ]);
        return $stmt->rowCount() > 0;
    }

    public static function acknowledge(int $alertId, int $actorUserId): array
    {
        $stmt = sb_db()->prepare("\n            UPDATE sitebuilder.system_alert\n            SET status='acknowledged',acknowledged_by=:actor,acknowledged_at=NOW(),updated_at=NOW()\n            WHERE id=:id AND status='open'\n            RETURNING *\n        ");
        $stmt->execute([':id' => $alertId, ':actor' => $actorUserId > 0 ? $actorUserId : null]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('ALERT_NOT_ACKNOWLEDGEABLE');
        }
        return self::mapRow($row);
    }

    public static function resolve(int $alertId, int $actorUserId): array
    {
        $stmt = sb_db()->prepare("\n            UPDATE sitebuilder.system_alert\n            SET status='resolved',resolved_by=:actor,resolved_at=NOW(),updated_at=NOW()\n            WHERE id=:id AND status<>'resolved'\n            RETURNING *\n        ");
        $stmt->execute([':id' => $alertId, ':actor' => $actorUserId > 0 ? $actorUserId : null]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('ALERT_NOT_RESOLVABLE');
        }
        return self::mapRow($row);
    }

    public static function get(int $alertId): ?array
    {
        $row = sb_db_fetch_one('SELECT * FROM sitebuilder.system_alert WHERE id=:id', [':id' => $alertId]);
        if (!$row) {
            return null;
        }
        $alert = self::mapRow($row);
        $alert['deliveries'] = array_map(static function (array $delivery): array {
            return [
                'id' => (int)$delivery['id'],
                'channel' => (string)$delivery['channel'],
                'recipient' => (string)$delivery['recipient'],
                'status' => (string)$delivery['status'],
                'errorCode' => (string)($delivery['error_code'] ?? ''),
                'attemptedAt' => (string)$delivery['attempted_at'],
                'deliveredAt' => (string)($delivery['delivered_at'] ?? ''),
            ];
        }, sb_db_fetch_all(
            'SELECT * FROM sitebuilder.system_alert_delivery WHERE alert_id=:id ORDER BY id DESC LIMIT 100',
            [':id' => $alertId]
        ));
        return $alert;
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
            if (!in_array($status, self::STATUSES, true)) {
                throw new InvalidArgumentException('INVALID_ALERT_STATUS');
            }
            $where[] = 'status=:status';
            $params[':status'] = $status;
        }
        $severity = trim((string)($filters['severity'] ?? ''));
        if ($severity !== '') {
            if (!in_array($severity, self::SEVERITIES, true)) {
                throw new InvalidArgumentException('INVALID_ALERT_SEVERITY');
            }
            $where[] = 'severity=:severity';
            $params[':severity'] = $severity;
        }
        $limit = max(1, min(200, (int)($filters['limit'] ?? 100)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = sb_db()->prepare("SELECT COUNT(*) FROM sitebuilder.system_alert {$sqlWhere}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = sb_db()->prepare("SELECT * FROM sitebuilder.system_alert {$sqlWhere} ORDER BY CASE severity WHEN 'critical' THEN 2 WHEN 'warning' THEN 1 ELSE 0 END DESC,last_seen_at DESC,id DESC LIMIT :limit OFFSET :offset");
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

    public static function jobDead(array $job, string $errorCode): array
    {
        $criticalTypes = ['bitrix.group.delete', 'disk.site_folder.delete', 'external.resources.reconcile'];
        $severity = in_array((string)($job['jobType'] ?? ''), $criticalTypes, true) ? 'critical' : 'warning';
        return self::openOrTouch(
            'outbox:dead:' . (int)$job['id'],
            $severity,
            'OUTBOX_JOB_DEAD',
            'Фоновое задание окончательно завершилось с ошибкой',
            [
                'jobType' => (string)($job['jobType'] ?? ''),
                'errorCode' => $errorCode,
                'attempts' => (int)($job['attempts'] ?? 0),
                'maxAttempts' => (int)($job['maxAttempts'] ?? 0),
            ],
            (int)($job['siteId'] ?? 0),
            'outbox_job',
            (int)$job['id']
        );
    }

    public static function resolveJob(int $jobId): bool
    {
        return self::resolveByKey('outbox:dead:' . $jobId);
    }

    public static function synchronizeQueueHealth(array $health): void
    {
        if (!self::schemaReady()) {
            return;
        }
        $siteId = (int)($health['siteId'] ?? 0);
        $activeKeys = [];
        foreach ((array)($health['checks'] ?? []) as $check) {
            $level = (string)($check['level'] ?? 'healthy');
            $code = (string)($check['code'] ?? '');
            if ($level === 'healthy' || $code === '') {
                continue;
            }
            $key = 'queue:' . $siteId . ':' . $code;
            $activeKeys[] = $key;
            self::openOrTouch(
                $key,
                $level === 'critical' ? 'critical' : 'warning',
                $code,
                'Проблема фоновой очереди SiteBuilder',
                $check,
                $siteId,
                'queue_health',
                0
            );
        }
        $knownCodes = [
            'WORKER_HEARTBEAT_MISSING','WORKER_HEARTBEAT_STALE','WORKER_HEARTBEAT_DELAYED',
            'QUEUE_DELAY_CRITICAL','QUEUE_DELAY_WARNING','STALE_RUNNING_JOBS',
            'EXTERNAL_CLEANUP_FAILED','EXTERNAL_RECONCILE_FAILED','DEAD_JOBS_PRESENT',
        ];
        foreach ($knownCodes as $code) {
            $key = 'queue:' . $siteId . ':' . $code;
            if (!in_array($key, $activeKeys, true)) {
                self::resolveByKey($key);
            }
        }
    }

    public static function mapRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'alertKey' => (string)$row['alert_key'],
            'severity' => (string)$row['severity'],
            'status' => (string)$row['status'],
            'siteId' => !empty($row['site_id']) ? (int)$row['site_id'] : 0,
            'sourceType' => (string)($row['source_type'] ?? ''),
            'sourceId' => !empty($row['source_id']) ? (int)$row['source_id'] : 0,
            'code' => (string)$row['code'],
            'title' => (string)$row['title'],
            'details' => sb_json_decode_assoc($row['details_json'] ?? '{}'),
            'occurrences' => (int)$row['occurrences'],
            'firstSeenAt' => (string)$row['first_seen_at'],
            'lastSeenAt' => (string)$row['last_seen_at'],
            'lastNotifiedAt' => (string)($row['last_notified_at'] ?? ''),
            'acknowledgedBy' => !empty($row['acknowledged_by']) ? (int)$row['acknowledged_by'] : 0,
            'acknowledgedAt' => (string)($row['acknowledged_at'] ?? ''),
            'resolvedBy' => !empty($row['resolved_by']) ? (int)$row['resolved_by'] : 0,
            'resolvedAt' => (string)($row['resolved_at'] ?? ''),
            'createdAt' => (string)$row['created_at'],
            'updatedAt' => (string)$row['updated_at'],
        ];
    }

    private static function schemaReady(): bool
    {
        try {
            $stmt = sb_db()->query("SELECT to_regclass('sitebuilder.system_alert') IS NOT NULL");
            return in_array($stmt->fetchColumn(), [true, 1, '1', 't', 'true'], true);
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function encodeDetails(array $details): string
    {
        $json = json_encode((object)$details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) {
            return json_encode(['truncated' => true, 'bytes' => strlen($json)], JSON_THROW_ON_ERROR);
        }
        return $json;
    }
}
