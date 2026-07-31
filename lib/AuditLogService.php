<?php

require_once __DIR__ . '/db.php';

final class AuditLogService
{
    private const READ_ONLY_ACTIONS = [
        'ping',
        'site.list', 'site.get', 'site.accessList', 'site.appearanceGet',
        'page.list', 'pageAccess.list',
        'menu.list', 'block.list', 'section.list', 'template.list', 'template.get',
        'layout.get', 'layout.block.list',
        'user.search', 'history.list', 'history.get',
        'trash.list', 'trash.get',
        'pageSection.list',
        'audit.list', 'audit.get', 'maintenance.status',
        'backup.list', 'backup.get', 'integrity.list', 'integrity.get',
    ];

    private const REQUEST_KEYS = [
        'siteId', 'pageId', 'blockId', 'sectionId', 'menuId', 'layoutId',
        'revisionId', 'entityId', 'entityType', 'id', 'backupId', 'runId', 'folderId', 'fileId',
        'objectId', 'parentId', 'expectedVersion', 'expectedVersions', 'column',
        'dir', 'role', 'accessCode', 'status', 'slug', 'title', 'name', 'type',
        'includeChildren', 'includeAccess', 'restoreAccess',
        'canView', 'canEdit', 'canDiskView', 'canDiskEdit',
    ];

    public static function isReadOnlyAction(string $action): bool
    {
        return in_array($action, self::READ_ONLY_ACTIONS, true);
    }

    public static function recordResponse(array $payload, int $httpStatus): void
    {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === '' || self::isReadOnlyAction($action)) {
            return;
        }

        global $USER;
        $actorUserId = is_object($USER) && $USER->IsAuthorized() ? (int)$USER->GetID() : 0;
        $context = self::resolveContext($action, $payload);
        $details = [
            'request' => self::requestDetails(),
            'response' => self::responseDetails($payload),
        ];

        try {
            self::safeInsert([
                'requestId' => self::requestId(),
                'siteId' => $context['siteId'],
                'actorUserId' => $actorUserId,
                'actorAccessCode' => $actorUserId > 0 ? 'U' . $actorUserId : '',
                'action' => $action,
                'entityType' => $context['entityType'],
                'entityId' => $context['entityId'],
                'pageId' => $context['pageId'],
                'outcome' => $httpStatus >= 400 ? 'error' : 'success',
                'httpStatus' => $httpStatus,
                'clientIp' => self::clientIp(),
                'userAgent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'details' => $details,
            ]);
        } catch (Throwable $e) {
            /*
             * Журнал не должен ломать основную операцию. До применения
             * миграции таблица может ещё отсутствовать.
             */
            error_log('SiteBuilder audit log write failed: ' . $e->getMessage());
        }
    }

    public static function recordSystemAction(
        string $action,
        array $details,
        string $outcome = 'success',
        int $httpStatus = 200,
        int $siteId = 0,
        int $actorUserId = 0
    ): void {
        try {
            self::safeInsert([
                'requestId' => self::requestId(),
                'siteId' => $siteId,
                'actorUserId' => $actorUserId,
                'actorAccessCode' => $actorUserId > 0 ? 'U' . $actorUserId : '',
                'action' => $action,
                'entityType' => 'system',
                'entityId' => null,
                'pageId' => null,
                'outcome' => $outcome === 'error' ? 'error' : 'success',
                'httpStatus' => max(100, min(599, $httpStatus)),
                'clientIp' => self::clientIp(),
                'userAgent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'details' => self::sanitizeValue($details, 0),
            ]);
        } catch (Throwable $e) {
            error_log('SiteBuilder system audit log write failed: ' . $e->getMessage());
        }
    }

    public static function list(array $filters): array
    {
        $siteId = (int)($filters['siteId'] ?? 0);
        $limit = max(1, min(200, (int)($filters['limit'] ?? 100)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $where = [];
        $params = [];

        if ($siteId > 0) {
            $where[] = 'site_id=:site_id';
            $params[':site_id'] = $siteId;
        }

        $actorUserId = (int)($filters['actorUserId'] ?? 0);
        if ($actorUserId > 0) {
            $where[] = 'actor_user_id=:actor_user_id';
            $params[':actor_user_id'] = $actorUserId;
        }

        $action = trim((string)($filters['action'] ?? ''));
        if ($action !== '') {
            $where[] = 'action ILIKE :action';
            $params[':action'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $action) . '%';
        }

        $entityType = trim((string)($filters['entityType'] ?? ''));
        if ($entityType !== '') {
            $where[] = 'entity_type=:entity_type';
            $params[':entity_type'] = mb_substr($entityType, 0, 64);
        }

        $outcome = trim((string)($filters['outcome'] ?? ''));
        if (in_array($outcome, ['success', 'error'], true)) {
            $where[] = 'outcome=:outcome';
            $params[':outcome'] = $outcome;
        }

        foreach (['dateFrom' => '>=', 'dateTo' => '<='] as $key => $operator) {
            $value = trim((string)($filters[$key] ?? ''));
            if ($value !== '') {
                $param = ':' . strtolower($key);
                $where[] = 'created_at ' . $operator . ' ' . $param;
                $params[$param] = $value;
            }
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $countStmt = sb_db()->prepare('SELECT COUNT(*) FROM sitebuilder.audit_log' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = sb_db()->prepare("
            SELECT id,request_id,site_id,actor_user_id,actor_access_code,action,
                   entity_type,entity_id,page_id,outcome,http_status,client_ip,
                   user_agent,created_at
            FROM sitebuilder.audit_log
            {$whereSql}
            ORDER BY id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map([self::class, 'mapRow'], $stmt->fetchAll()),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public static function get(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = sb_db_fetch_one("
            SELECT id,request_id,site_id,actor_user_id,actor_access_code,action,
                   entity_type,entity_id,page_id,outcome,http_status,client_ip,
                   user_agent,details_json,created_at
            FROM sitebuilder.audit_log
            WHERE id=:id
        ", [':id' => $id]);

        return $row ? self::mapRow($row, true) : null;
    }

    private static function safeInsert(array $row): void
    {
        if (!self::tableExists()) {
            return;
        }

        $pdo = sb_db();
        $savepoint = $pdo->inTransaction();
        if ($savepoint) {
            $pdo->exec('SAVEPOINT sb_audit_write');
        }

        try {
            self::insert($row);
            if ($savepoint) {
                $pdo->exec('RELEASE SAVEPOINT sb_audit_write');
            }
        } catch (Throwable $e) {
            if ($savepoint) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT sb_audit_write');
                    $pdo->exec('RELEASE SAVEPOINT sb_audit_write');
                } catch (Throwable $savepointError) {
                    error_log('SiteBuilder audit savepoint rollback failed: ' . $savepointError->getMessage());
                }
            }
            throw $e;
        }
    }

    private static function tableExists(): bool
    {
        static $exists = null;
        if (is_bool($exists)) {
            return $exists;
        }
        try {
            $exists = (bool)sb_db()->query("SELECT to_regclass('sitebuilder.audit_log') IS NOT NULL")->fetchColumn();
        } catch (Throwable $e) {
            $exists = false;
        }
        return $exists;
    }

    private static function insert(array $row): void
    {
        $stmt = sb_db()->prepare("
            INSERT INTO sitebuilder.audit_log (
                request_id,site_id,actor_user_id,actor_access_code,action,
                entity_type,entity_id,page_id,outcome,http_status,client_ip,
                user_agent,details_json
            ) VALUES (
                :request_id,:site_id,:actor_user_id,:actor_access_code,:action,
                :entity_type,:entity_id,:page_id,:outcome,:http_status,:client_ip,
                :user_agent,CAST(:details AS jsonb)
            )
        ");
        $stmt->execute([
            ':request_id' => mb_substr((string)$row['requestId'], 0, 64),
            ':site_id' => !empty($row['siteId']) ? (int)$row['siteId'] : null,
            ':actor_user_id' => !empty($row['actorUserId']) ? (int)$row['actorUserId'] : null,
            ':actor_access_code' => mb_substr((string)($row['actorAccessCode'] ?? ''), 0, 128),
            ':action' => mb_substr((string)$row['action'], 0, 128),
            ':entity_type' => mb_substr((string)($row['entityType'] ?? ''), 0, 64),
            ':entity_id' => !empty($row['entityId']) ? (int)$row['entityId'] : null,
            ':page_id' => !empty($row['pageId']) ? (int)$row['pageId'] : null,
            ':outcome' => ($row['outcome'] ?? '') === 'error' ? 'error' : 'success',
            ':http_status' => max(100, min(599, (int)($row['httpStatus'] ?? 200))),
            ':client_ip' => mb_substr((string)($row['clientIp'] ?? ''), 0, 64),
            ':user_agent' => mb_substr((string)($row['userAgent'] ?? ''), 0, 500),
            ':details' => json_encode(
                is_array($row['details'] ?? null) ? $row['details'] : [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);
    }

    private static function resolveContext(string $action, array $payload): array
    {
        $siteId = self::firstPositive([
            $_POST['siteId'] ?? null,
            $payload['siteId'] ?? null,
            $payload['site']['id'] ?? null,
            $payload['entity']['siteId'] ?? null,
            $payload['result']['siteId'] ?? null,
        ]);
        $pageId = self::firstPositive([
            $_POST['pageId'] ?? null,
            $payload['pageId'] ?? null,
            $payload['page']['id'] ?? null,
            $payload['block']['pageId'] ?? null,
            $payload['section']['pageId'] ?? null,
            $payload['entity']['pageId'] ?? null,
        ]);

        $entityType = self::entityTypeForAction($action);
        $entityId = self::firstPositive([
            $_POST['entityId'] ?? null,
            $_POST['blockId'] ?? null,
            $_POST['sectionId'] ?? null,
            $_POST['menuId'] ?? null,
            $_POST['revisionId'] ?? null,
            $_POST['backupId'] ?? null,
            $_POST['runId'] ?? null,
            $_POST['jobId'] ?? null,
            $_POST['fileId'] ?? null,
            $_POST['objectId'] ?? null,
            $_POST['folderId'] ?? null,
            $_POST['id'] ?? null,
            $payload['entity']['id'] ?? null,
            $payload['site']['id'] ?? null,
            $payload['page']['id'] ?? null,
            $payload['block']['id'] ?? null,
            $payload['section']['id'] ?? null,
            $payload['menu']['id'] ?? null,
            $payload['backup']['id'] ?? null,
            $payload['run']['id'] ?? null,
            $payload['id'] ?? null,
        ]);

        if ($entityType === 'site' && $entityId <= 0) {
            $entityId = $siteId;
        }
        if ($entityType === 'page' && $entityId <= 0) {
            $entityId = $pageId;
        }
        if ($entityType === 'layout' && $entityId <= 0) {
            $entityId = $siteId;
        }
        if ($entityType === 'page_access' && $entityId <= 0) {
            $entityId = $pageId;
        }

        if ($siteId <= 0) {
            $siteId = self::resolveSiteIdFromEntity($entityType, $entityId, $pageId);
        }
        if ($pageId <= 0) {
            $pageId = self::resolvePageIdFromEntity($entityType, $entityId);
        }

        return [
            'siteId' => $siteId > 0 ? $siteId : null,
            'pageId' => $pageId > 0 ? $pageId : null,
            'entityType' => $entityType,
            'entityId' => $entityId > 0 ? $entityId : null,
        ];
    }

    private static function resolveSiteIdFromEntity(string $entityType, int $entityId, int $pageId): int
    {
        try {
            if ($pageId > 0) {
                return (int)(sb_db_fetch_one('SELECT site_id FROM sitebuilder.page WHERE id=:id', [':id' => $pageId])['site_id'] ?? 0);
            }
            $queries = [
                'page' => 'SELECT site_id FROM sitebuilder.page WHERE id=:id',
                'block' => 'SELECT p.site_id FROM sitebuilder.block b JOIN sitebuilder.page p ON p.id=b.page_id WHERE b.id=:id',
                'page_section' => 'SELECT site_id FROM sitebuilder.page_section WHERE id=:id',
                'menu' => 'SELECT site_id FROM sitebuilder.menu WHERE id=:id',
                'recycle_bin' => 'SELECT site_id FROM sitebuilder.recycle_bin WHERE id=:id',
                'entity_revision' => 'SELECT site_id FROM sitebuilder.entity_revision WHERE id=:id',
            ];
            if ($entityId > 0 && isset($queries[$entityType])) {
                return (int)(sb_db_fetch_one($queries[$entityType], [':id' => $entityId])['site_id'] ?? 0);
            }
        } catch (Throwable $e) {
        }
        return 0;
    }

    private static function resolvePageIdFromEntity(string $entityType, int $entityId): int
    {
        try {
            if ($entityType === 'page') {
                return $entityId;
            }
            if ($entityType === 'block' && $entityId > 0) {
                return (int)(sb_db_fetch_one('SELECT page_id FROM sitebuilder.block WHERE id=:id', [':id' => $entityId])['page_id'] ?? 0);
            }
            if ($entityType === 'page_section' && $entityId > 0) {
                return (int)(sb_db_fetch_one('SELECT page_id FROM sitebuilder.page_section WHERE id=:id', [':id' => $entityId])['page_id'] ?? 0);
            }
        } catch (Throwable $e) {
        }
        return 0;
    }

    private static function entityTypeForAction(string $action): string
    {
        if (str_starts_with($action, 'pageSection.')) return 'page_section';
        if (str_starts_with($action, 'pageAccess.')) return 'page_access';
        if (str_starts_with($action, 'layout.')) return 'layout';
        if (str_starts_with($action, 'site.')) return 'site';
        if (str_starts_with($action, 'page.')) return 'page';
        if (str_starts_with($action, 'block.')) return 'block';
        if (str_starts_with($action, 'menu.')) return 'menu';
        if (str_starts_with($action, 'file.')) return 'disk_object';
        if (str_starts_with($action, 'trash.')) return 'recycle_bin';
        if (str_starts_with($action, 'history.')) return 'entity_revision';
        if (str_starts_with($action, 'template.')) return 'template';
        if (str_starts_with($action, 'globalBlock.')) return 'global_block';
        if (str_starts_with($action, 'section.')) return 'site_section';
        if (str_starts_with($action, 'maintenance.')) return 'system';
        if (str_starts_with($action, 'job.')) return 'external_job';
        if (str_starts_with($action, 'backup.')) return 'site_backup';
        if (str_starts_with($action, 'integrity.')) return 'integrity_check';
        return mb_substr(strtok($action, '.') ?: 'system', 0, 64);
    }

    private static function requestDetails(): array
    {
        $result = [];
        foreach (self::REQUEST_KEYS as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $result[$key] = self::sanitizeValue($_POST[$key], 0);
        }
        return $result;
    }

    private static function responseDetails(array $payload): array
    {
        $allowed = ['error', 'deleted', 'purged', 'moved', 'id', 'siteId', 'pageId', 'recycleBinId', 'deletedPageIds', 'counts', 'warnings'];
        $result = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                $result[$key] = self::sanitizeValue($payload[$key], 0);
            }
        }
        foreach (['site', 'page', 'block', 'section', 'menu', 'layout', 'job', 'backup', 'run', 'entity', 'result'] as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                continue;
            }
            $result[$key] = self::identityOnly($payload[$key]);
        }
        return $result;
    }

    private static function identityOnly(array $value): array
    {
        $result = [];
        foreach (['id', 'siteId', 'pageId', 'version', 'rootPageId', 'title', 'name', 'slug'] as $key) {
            if (array_key_exists($key, $value) && (is_scalar($value[$key]) || $value[$key] === null)) {
                $result[$key] = $value[$key];
            }
        }
        return $result;
    }

    private static function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > 3) {
            return '[depth-limit]';
        }
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return mb_substr($value, 0, 1000);
        }
        if (is_array($value)) {
            $result = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count++ >= 50) {
                    $result['_truncated'] = true;
                    break;
                }
                $key = is_int($key) ? $key : mb_substr((string)$key, 0, 100);
                $result[$key] = self::sanitizeValue($item, $depth + 1);
            }
            return $result;
        }
        return mb_substr(get_debug_type($value), 0, 100);
    }

    private static function mapRow(array $row, bool $withDetails = false): array
    {
        $result = [
            'id' => (int)($row['id'] ?? 0),
            'requestId' => (string)($row['request_id'] ?? ''),
            'siteId' => (int)($row['site_id'] ?? 0),
            'actorUserId' => (int)($row['actor_user_id'] ?? 0),
            'actorAccessCode' => (string)($row['actor_access_code'] ?? ''),
            'action' => (string)($row['action'] ?? ''),
            'entityType' => (string)($row['entity_type'] ?? ''),
            'entityId' => (int)($row['entity_id'] ?? 0),
            'pageId' => (int)($row['page_id'] ?? 0),
            'outcome' => (string)($row['outcome'] ?? 'success'),
            'httpStatus' => (int)($row['http_status'] ?? 200),
            'clientIp' => (string)($row['client_ip'] ?? ''),
            'userAgent' => (string)($row['user_agent'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
        ];
        if ($withDetails) {
            $result['details'] = sb_json_decode_assoc($row['details_json'] ?? []);
        }
        return $result;
    }

    private static function firstPositive(array $values): int
    {
        foreach ($values as $value) {
            $number = (int)$value;
            if ($number > 0) {
                return $number;
            }
        }
        return 0;
    }

    private static function requestId(): string
    {
        if (!empty($GLOBALS['SB_AUDIT_REQUEST_ID'])) {
            return (string)$GLOBALS['SB_AUDIT_REQUEST_ID'];
        }
        try {
            $id = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $id = uniqid('sb-', true);
        }
        $GLOBALS['SB_AUDIT_REQUEST_ID'] = $id;
        return $id;
    }

    private static function clientIp(): string
    {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
}
