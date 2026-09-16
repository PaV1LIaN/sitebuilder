<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/storage_db.php';
require_once __DIR__ . '/RevisionService.php';
require_once __DIR__ . '/OutboxService.php';
require_once __DIR__ . '/SiteBitrixGroupService.php';
require_once __DIR__ . '/SystemAlertService.php';
require_once __DIR__ . '/disk.php';

/** Сверка привязок PostgreSQL с рабочими группами Битрикс24 и папками Диска. */
final class ExternalResourceReconcileService
{
    private const SCHEDULE_KEY = 'external_resource_reconcile';
    private const GLOBAL_LOCK_NAMESPACE = 761320;
    private const SITE_LOCK_NAMESPACE = 761325;

    public static function enqueueIfDue(int $actorUserId = 0): ?array
    {
        /*
         * При rolling deployment PHP-код может обновиться раньше миграции.
         * Worker продолжает старую очередь и не планирует сверку, пока
         * таблицы этапа 11 ещё не созданы.
         */
        if (!self::schemaReady()) {
            return null;
        }

        $config = self::config();
        $interval = max(300, (int)$config['auto_interval_seconds']);
        $pdo = sb_db();
        $startedHere = sb_db_transaction_scope_begin();
        try {
            sb_db_execute("\n                INSERT INTO sitebuilder.maintenance_state (task_key,last_result_json,updated_at)\n                VALUES (:key,'{}'::jsonb,NOW())\n                ON CONFLICT (task_key) DO NOTHING\n            ", [':key' => self::SCHEDULE_KEY]);
            $state = sb_db_fetch_one(
                'SELECT task_key,last_run_at FROM sitebuilder.maintenance_state WHERE task_key=:key FOR UPDATE',
                [':key' => self::SCHEDULE_KEY]
            );
            $lastRun = !empty($state['last_run_at']) ? strtotime((string)$state['last_run_at']) : false;
            if ($lastRun !== false && (time() - $lastRun) < $interval) {
                sb_db_transaction_scope_commit($startedHere);
                return null;
            }
            $job = OutboxService::enqueueExternalReconcile(
                0,
                (string)$config['auto_mode'],
                $actorUserId
            );
            sb_db_execute("\n                UPDATE sitebuilder.maintenance_state\n                SET last_run_at=NOW(),last_result_json=CAST(:result AS jsonb),updated_at=NOW()\n                WHERE task_key=:key\n            ", [
                ':key' => self::SCHEDULE_KEY,
                ':result' => json_encode([
                    'scheduledJobId' => (int)$job['id'],
                    'mode' => (string)$config['auto_mode'],
                    'scheduledAt' => date(DATE_ATOM),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            sb_db_transaction_scope_commit($startedHere);
            return $job;
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }
    }

    public static function run(int $siteId = 0, string $mode = 'audit', int $actorUserId = 0, int $jobId = 0): array
    {
        if (!self::schemaReady()) {
            throw new RuntimeException('STAGE11_MIGRATION_REQUIRED');
        }
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['audit', 'repair'], true)) {
            throw new InvalidArgumentException('INVALID_RECONCILE_MODE');
        }
        $locks = self::acquireLocks($siteId);
        if ($locks === null) {
            throw new RuntimeException('RECONCILE_BUSY');
        }

        $runId = 0;
        $summary = [
            'runId' => 0,
            'siteId' => max(0, $siteId),
            'mode' => $mode,
            'checkedSites' => 0,
            'checkedGroups' => 0,
            'checkedFolders' => 0,
            'anomalies' => 0,
            'repairs' => 0,
            'cleanupJobs' => 0,
            'accessReconcileJobs' => 0,
            'findings' => [],
        ];

        try {
            $runId = self::startRun($siteId, $mode, $actorUserId, $jobId);
            $summary['runId'] = $runId;

            $sites = $siteId > 0
                ? array_values(array_filter(sb_read_sites(), static fn(array $site): bool => (int)$site['id'] === $siteId))
                : sb_read_sites();
            if ($siteId > 0 && !$sites) {
                throw new RuntimeException('SITE_NOT_FOUND');
            }
            $cleanup = self::activeCleanupReferences();
            $referencedGroups = [];
            $referencedFolders = [];

            foreach ($sites as $site) {
                $summary['checkedSites']++;
                $currentSiteId = (int)$site['id'];
                $groupId = (int)($site['bitrixGroupId'] ?? 0);
                $folderId = (int)($site['diskFolderId'] ?? 0);
                if ($groupId > 0) {
                    $referencedGroups[$groupId] = $currentSiteId;
                }
                if ($folderId > 0) {
                    $referencedFolders[$folderId] = $currentSiteId;
                }
                self::checkSiteGroup($site, $mode, $actorUserId, $runId, $summary);
                self::checkSiteFolder($site, $mode, $actorUserId, $runId, $summary);
                $accessActorUserId = $actorUserId > 0
                    ? $actorUserId
                    : (int)($site['createdBy'] ?? 0);
                if (
                    $groupId > 0
                    && $accessActorUserId > 0
                    && self::unifiedAccessSchemaReady()
                ) {
                    OutboxService::enqueueUnifiedAccessReconcile(
                        $currentSiteId,
                        $mode,
                        $accessActorUserId
                    );
                    $summary['accessReconcileJobs']++;
                }
            }

            if ($siteId <= 0) {
                foreach (SiteBitrixGroupService::listManagedGroups() as $group) {
                    $summary['checkedGroups']++;
                    $externalId = (int)$group['id'];
                    if (isset($referencedGroups[$externalId])) {
                        continue;
                    }
                    $pending = isset($cleanup['bitrix_group'][$externalId]);
                    $status = $pending ? 'cleanup_pending' : 'orphaned';
                    self::upsertRegistry('bitrix_group', $externalId, 0, '', (string)$group['name'], $status, true, $runId, $group);
                    if ($pending) {
                        self::resolveResourceAlerts('bitrix_group', $externalId);
                        continue;
                    }
                    self::finding($summary, [
                        'resourceType' => 'bitrix_group',
                        'externalId' => $externalId,
                        'status' => 'orphaned',
                        'actualName' => (string)$group['name'],
                    ]);
                    SystemAlertService::openOrTouch(
                        'external:bitrix_group:' . $externalId . ':orphaned',
                        'warning',
                        'ORPHANED_BITRIX_GROUP',
                        'Найдена непривязанная рабочая группа SiteBuilder',
                        ['externalId' => $externalId, 'name' => (string)$group['name']],
                        0,
                        'bitrix_group',
                        $externalId
                    );
                }

                foreach (sb_disk_list_managed_site_folders() as $folder) {
                    $summary['checkedFolders']++;
                    $externalId = (int)$folder['id'];
                    if (isset($referencedFolders[$externalId])) {
                        continue;
                    }
                    $pending = isset($cleanup['disk_folder'][$externalId]);
                    $status = $pending ? 'cleanup_pending' : 'orphaned';
                    self::upsertRegistry('disk_folder', $externalId, 0, '', (string)$folder['name'], $status, true, $runId, $folder);
                    if ($pending) {
                        self::resolveResourceAlerts('disk_folder', $externalId);
                        continue;
                    }
                    self::finding($summary, [
                        'resourceType' => 'disk_folder',
                        'externalId' => $externalId,
                        'status' => 'orphaned',
                        'actualName' => (string)$folder['name'],
                    ]);
                    SystemAlertService::openOrTouch(
                        'external:disk_folder:' . $externalId . ':orphaned',
                        'warning',
                        'ORPHANED_DISK_FOLDER',
                        'Найдена непривязанная папка SiteBuilder в Битрикс.Диске',
                        ['externalId' => $externalId, 'name' => (string)$folder['name']],
                        0,
                        'disk_folder',
                        $externalId
                    );
                }

                self::markUnseenDeleted($runId);
            }

            self::finishRun($runId, $summary['anomalies'] > 0 ? 'partial' : 'succeeded', $summary);
            return $summary;
        } catch (Throwable $e) {
            if ($runId > 0) {
                self::failRun($runId, $e, $summary);
            }
            throw $e;
        } finally {
            self::releaseLocks($locks);
        }
    }

    public static function listResources(array $filters = []): array
    {
        $where = [];
        $params = [];
        $siteId = (int)($filters['siteId'] ?? 0);
        if ($siteId > 0) {
            $where[] = 'site_id=:site_id';
            $params[':site_id'] = $siteId;
        }
        $type = trim((string)($filters['resourceType'] ?? ''));
        if ($type !== '') {
            if (!in_array($type, ['bitrix_group', 'disk_folder'], true)) {
                throw new InvalidArgumentException('INVALID_RESOURCE_TYPE');
            }
            $where[] = 'resource_type=:resource_type';
            $params[':resource_type'] = $type;
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $allowed = ['attached','missing','mismatched','orphaned','cleanup_pending','deleted','unknown'];
            if (!in_array($status, $allowed, true)) {
                throw new InvalidArgumentException('INVALID_RESOURCE_STATUS');
            }
            $where[] = 'relation_status=:status';
            $params[':status'] = $status;
        }
        $limit = max(1, min(300, (int)($filters['limit'] ?? 100)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $count = sb_db()->prepare("SELECT COUNT(*) FROM sitebuilder.external_resource_registry {$sqlWhere}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $stmt = sb_db()->prepare("SELECT * FROM sitebuilder.external_resource_registry {$sqlWhere} ORDER BY CASE relation_status WHEN 'missing' THEN 5 WHEN 'mismatched' THEN 4 WHEN 'orphaned' THEN 3 WHEN 'cleanup_pending' THEN 2 ELSE 1 END DESC,updated_at DESC,id DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return [
            'items' => array_map([self::class, 'mapRegistryRow'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public static function listRuns(array $filters = []): array
    {
        $where = [];
        $params = [];
        $siteId = (int)($filters['siteId'] ?? 0);
        if ($siteId > 0) {
            $where[] = 'site_id=:site_id';
            $params[':site_id'] = $siteId;
        }
        $limit = max(1, min(100, (int)($filters['limit'] ?? 30)));
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = sb_db()->prepare("SELECT * FROM sitebuilder.external_reconcile_run {$sqlWhere} ORDER BY id DESC LIMIT :limit");
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'mapRunRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function getRun(int $runId): ?array
    {
        $row = sb_db_fetch_one('SELECT * FROM sitebuilder.external_reconcile_run WHERE id=:id', [':id' => $runId]);
        return $row ? self::mapRunRow($row) : null;
    }

    public static function enqueue(int $siteId, string $mode, int $actorUserId): array
    {
        if (!self::schemaReady()) {
            throw new RuntimeException('STAGE11_MIGRATION_REQUIRED');
        }
        return OutboxService::enqueueExternalReconcile($siteId, $mode, $actorUserId);
    }

    public static function cleanupOrphan(string $resourceType, int $externalId, int $actorUserId): array
    {
        $row = sb_db_fetch_one(
            'SELECT * FROM sitebuilder.external_resource_registry WHERE resource_type=:type AND external_id=:external_id FOR UPDATE',
            [':type' => $resourceType, ':external_id' => $externalId]
        );
        if (!$row || (string)$row['relation_status'] !== 'orphaned' || !self::toBool($row['managed'] ?? false)) {
            throw new RuntimeException('RESOURCE_NOT_CLEANABLE');
        }
        if (self::resourceIsReferenced($resourceType, $externalId)) {
            sb_db_execute("
                UPDATE sitebuilder.external_resource_registry
                SET relation_status='attached',resolved_at=NOW(),last_checked_at=NOW(),updated_at=NOW()
                WHERE id=:id
            ", [':id' => (int)$row['id']]);
            self::resolveResourceAlerts($resourceType, $externalId);
            throw new RuntimeException('RESOURCE_ALREADY_ATTACHED');
        }
        $job = match ($resourceType) {
            'bitrix_group' => OutboxService::enqueueOrphanGroupDelete(
                $externalId,
                (string)($row['actual_name'] ?? ''),
                $actorUserId
            ),
            'disk_folder' => OutboxService::enqueueOrphanDiskFolderDelete(
                $externalId,
                (string)($row['actual_name'] ?? ''),
                $actorUserId
            ),
            default => throw new InvalidArgumentException('INVALID_RESOURCE_TYPE'),
        };
        sb_db_execute("\n            UPDATE sitebuilder.external_resource_registry\n            SET relation_status='cleanup_pending',updated_at=NOW(),last_checked_at=NOW()\n            WHERE id=:id\n        ", [':id' => (int)$row['id']]);
        return $job;
    }

    private static function checkSiteGroup(array $site, string $mode, int $actorUserId, int $runId, array &$summary): void
    {
        $siteId = (int)$site['id'];
        $groupId = (int)($site['bitrixGroupId'] ?? 0);
        $expected = SiteBitrixGroupService::expectedGroupName($site);
        if ($groupId <= 0) {
            self::resolveSiteResourceAlertsExcept($siteId, 'bitrix_group', 'unattached');
            self::finding($summary, ['siteId' => $siteId, 'resourceType' => 'bitrix_group', 'status' => 'unattached']);
            SystemAlertService::openOrTouch(
                'external:site:' . $siteId . ':bitrix_group:unattached',
                'warning', 'BITRIX_GROUP_UNATTACHED',
                'К сайту не привязана рабочая группа Битрикс24',
                ['expectedName' => $expected], $siteId, 'site', $siteId
            );
            if ($mode === 'repair') {
                OutboxService::enqueueGroupEnsure($siteId, $actorUserId > 0 ? $actorUserId : (int)$site['createdBy']);
                $summary['repairs']++;
            }
            return;
        }
        $summary['checkedGroups']++;
        $group = SiteBitrixGroupService::inspectGroup($groupId);
        if (!$group) {
            self::resolveSiteResourceAlertsExcept($siteId, 'bitrix_group', 'missing');
            self::upsertRegistry('bitrix_group', $groupId, $siteId, $expected, '', 'missing', true, $runId, []);
            self::finding($summary, ['siteId' => $siteId, 'resourceType' => 'bitrix_group', 'externalId' => $groupId, 'status' => 'missing']);
            SystemAlertService::openOrTouch(
                'external:site:' . $siteId . ':bitrix_group:missing',
                'critical', 'BITRIX_GROUP_MISSING',
                'Привязанная рабочая группа Битрикс24 отсутствует',
                ['externalId' => $groupId, 'expectedName' => $expected], $siteId, 'bitrix_group', $groupId
            );
            if ($mode === 'repair' && !empty(self::config()['repair_missing_links'])) {
                self::detachAndRecreate($siteId, 'bitrix_group', $groupId, $actorUserId);
                $summary['repairs']++;
            }
            return;
        }
        if (empty($group['managed'])) {
            self::resolveSiteResourceAlertsExcept($siteId, 'bitrix_group', 'mismatched');
            self::upsertRegistry('bitrix_group', $groupId, $siteId, $expected, (string)$group['name'], 'mismatched', false, $runId, $group);
            self::finding($summary, ['siteId' => $siteId, 'resourceType' => 'bitrix_group', 'externalId' => $groupId, 'status' => 'mismatched']);
            SystemAlertService::openOrTouch(
                'external:site:' . $siteId . ':bitrix_group:mismatched',
                'critical', 'BITRIX_GROUP_NOT_MANAGED',
                'Сайт ссылается на группу, которая не принадлежит SiteBuilder',
                ['externalId' => $groupId, 'actualName' => (string)$group['name']], $siteId, 'bitrix_group', $groupId
            );
            return;
        }
        $status = (string)$group['name'] === $expected ? 'attached' : 'mismatched';
        self::upsertRegistry('bitrix_group', $groupId, $siteId, $expected, (string)$group['name'], $status, true, $runId, $group);
        if ($status === 'mismatched') {
            self::resolveSiteResourceAlertsExcept($siteId, 'bitrix_group', 'name_mismatch');
            self::finding($summary, ['siteId' => $siteId, 'resourceType' => 'bitrix_group', 'externalId' => $groupId, 'status' => 'name_mismatch']);
            SystemAlertService::openOrTouch(
                'external:site:' . $siteId . ':bitrix_group:name_mismatch',
                'warning', 'BITRIX_GROUP_NAME_MISMATCH',
                'Название рабочей группы не совпадает с названием сайта',
                ['externalId' => $groupId, 'expectedName' => $expected, 'actualName' => (string)$group['name']],
                $siteId, 'bitrix_group', $groupId
            );
        } else {
            self::resolveSiteResourceAlerts($siteId, 'bitrix_group');
        }
    }

    private static function checkSiteFolder(array $site, string $mode, int $actorUserId, int $runId, array &$summary): void
    {
        $siteId = (int)$site['id'];
        $folderId = (int)($site['diskFolderId'] ?? 0);
        $expected = sb_disk_site_folder_name($site);
        if ($folderId <= 0) {
            self::resolveSiteResourceAlertsExcept($siteId, 'disk_folder', 'unattached');
            self::finding($summary, ['siteId' => $siteId, 'resourceType' => 'disk_folder', 'status' => 'unattached']);
            SystemAlertService::openOrTouch(
                'external:site:' . $siteId . ':disk_folder:unattached',
                'warning', 'DISK_FOLDER_UNATTACHED',
                'К сайту не привязана папка Битрикс.Диска',
                ['expectedName' => $expected], $siteId, 'site', $siteId
            );
            if ($mode === 'repair') {
                OutboxService::enqueueDiskFolderEnsure($siteId, $actorUserId > 0 ? $actorUserId : (int)$site['createdBy']);
                $summary['repairs']++;
            }
            return;
        }
        $summary['checkedFolders']++;
        $folder = sb_disk_inspect_managed_site_folder($folderId);
        if (!$folder) {
            self::resolveSiteResourceAlertsExcept($siteId, 'disk_folder', 'missing');
            self::upsertRegistry('disk_folder', $folderId, $siteId, $expected, '', 'missing', true, $runId, []);
            self::finding($summary, ['siteId' => $siteId, 'resourceType' => 'disk_folder', 'externalId' => $folderId, 'status' => 'missing']);
            SystemAlertService::openOrTouch(
                'external:site:' . $siteId . ':disk_folder:missing',
                'critical', 'DISK_FOLDER_MISSING',
                'Привязанная папка Битрикс.Диска отсутствует',
                ['externalId' => $folderId, 'expectedName' => $expected], $siteId, 'disk_folder', $folderId
            );
            if ($mode === 'repair' && !empty(self::config()['repair_missing_links'])) {
                self::detachAndRecreate($siteId, 'disk_folder', $folderId, $actorUserId);
                $summary['repairs']++;
            }
            return;
        }
        if (empty($folder['managed'])) {
            self::resolveSiteResourceAlertsExcept($siteId, 'disk_folder', 'mismatched');
            self::upsertRegistry('disk_folder', $folderId, $siteId, $expected, (string)$folder['name'], 'mismatched', false, $runId, $folder);
            self::finding($summary, ['siteId' => $siteId, 'resourceType' => 'disk_folder', 'externalId' => $folderId, 'status' => 'mismatched']);
            SystemAlertService::openOrTouch(
                'external:site:' . $siteId . ':disk_folder:mismatched',
                'critical', 'DISK_FOLDER_NOT_MANAGED',
                'Сайт ссылается на папку вне служебного каталога SiteBuilder',
                ['externalId' => $folderId, 'actualName' => (string)$folder['name']], $siteId, 'disk_folder', $folderId
            );
            return;
        }
        $status = (string)$folder['name'] === $expected ? 'attached' : 'mismatched';
        self::upsertRegistry('disk_folder', $folderId, $siteId, $expected, (string)$folder['name'], $status, true, $runId, $folder);
        if ($status === 'mismatched') {
            self::resolveSiteResourceAlertsExcept($siteId, 'disk_folder', 'name_mismatch');
            self::finding($summary, ['siteId' => $siteId, 'resourceType' => 'disk_folder', 'externalId' => $folderId, 'status' => 'name_mismatch']);
            SystemAlertService::openOrTouch(
                'external:site:' . $siteId . ':disk_folder:name_mismatch',
                'warning', 'DISK_FOLDER_NAME_MISMATCH',
                'Название папки Диска не совпадает со slug сайта',
                ['externalId' => $folderId, 'expectedName' => $expected, 'actualName' => (string)$folder['name']],
                $siteId, 'disk_folder', $folderId
            );
        } else {
            self::resolveSiteResourceAlerts($siteId, 'disk_folder');
        }
    }

    private static function detachAndRecreate(int $siteId, string $type, int $externalId, int $actorUserId): void
    {
        $pdo = sb_db();
        $startedHere = sb_db_transaction_scope_begin();
        try {
            $site = RevisionService::getSite($siteId, true);
            if (!$site) {
                sb_db_transaction_scope_commit($startedHere);
                return;
            }
            if ($type === 'bitrix_group' && (int)$site['bitrixGroupId'] === $externalId) {
                $site['bitrixGroupId'] = 0;
                $site['bitrixGroupCreatedBy'] = 0;
                $site['bitrixGroupCreatedAt'] = '';
                $saved = RevisionService::saveSite($site, (int)$site['version'], $actorUserId, 'external_reconcile_detach_missing_group');
                OutboxService::enqueueGroupEnsure($siteId, $actorUserId > 0 ? $actorUserId : (int)$saved['createdBy']);
                self::markRegistryResolved('bitrix_group', $externalId);
            } elseif ($type === 'disk_folder' && (int)$site['diskFolderId'] === $externalId) {
                $site['diskFolderId'] = 0;
                $saved = RevisionService::saveSite($site, (int)$site['version'], $actorUserId, 'external_reconcile_detach_missing_folder');
                OutboxService::enqueueDiskFolderEnsure($siteId, $actorUserId > 0 ? $actorUserId : (int)$saved['createdBy']);
                self::markRegistryResolved('disk_folder', $externalId);
            }
            sb_db_transaction_scope_commit($startedHere);
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }
    }

    private static function resourceIsReferenced(string $resourceType, int $externalId): bool
    {
        $column = match ($resourceType) {
            'bitrix_group' => 'bitrix_group_id',
            'disk_folder' => 'disk_folder_id',
            default => throw new InvalidArgumentException('INVALID_RESOURCE_TYPE'),
        };
        $stmt = sb_db()->prepare('SELECT 1 FROM sitebuilder.site WHERE ' . $column . '=:external_id LIMIT 1');
        $stmt->execute([':external_id' => $externalId]);
        return (bool)$stmt->fetchColumn();
    }

    private static function activeCleanupReferences(): array
    {
        $result = ['bitrix_group' => [], 'disk_folder' => []];
        $rows = sb_db_fetch_all("\n            SELECT job_type,status,payload_json\n            FROM sitebuilder.outbox_job\n            WHERE job_type IN ('bitrix.group.delete','disk.site_folder.delete')\n              AND status IN ('pending','running','retry')\n        ");
        foreach ($rows as $row) {
            $payload = sb_json_decode_assoc($row['payload_json'] ?? '{}');
            if ((string)$row['job_type'] === 'bitrix.group.delete' && (int)($payload['groupId'] ?? 0) > 0) {
                $result['bitrix_group'][(int)$payload['groupId']] = true;
            }
            if ((string)$row['job_type'] === 'disk.site_folder.delete' && (int)($payload['folderId'] ?? 0) > 0) {
                $result['disk_folder'][(int)$payload['folderId']] = true;
            }
        }
        return $result;
    }

    private static function upsertRegistry(
        string $type,
        int $externalId,
        int $siteId,
        string $expectedName,
        string $actualName,
        string $status,
        bool $managed,
        int $runId,
        array $metadata
    ): void {
        sb_db_execute("\n            INSERT INTO sitebuilder.external_resource_registry (\n                resource_type,external_id,site_id,expected_name,actual_name,relation_status,managed,\n                last_reconcile_run_id,metadata_json,first_seen_at,last_seen_at,last_checked_at,resolved_at,updated_at\n            ) VALUES (\n                :type,:external_id,:site_id,:expected_name,:actual_name,:status,:managed,\n                :run_id,CAST(:metadata AS jsonb),NOW(),NOW(),NOW(),\n                :resolved_at,NOW()\n            )\n            ON CONFLICT (resource_type,external_id) DO UPDATE SET\n                site_id=EXCLUDED.site_id,expected_name=EXCLUDED.expected_name,actual_name=EXCLUDED.actual_name,\n                relation_status=EXCLUDED.relation_status,managed=EXCLUDED.managed,\n                last_reconcile_run_id=EXCLUDED.last_reconcile_run_id,metadata_json=EXCLUDED.metadata_json,\n                last_seen_at=NOW(),last_checked_at=NOW(),\n                resolved_at=CASE WHEN EXCLUDED.relation_status IN ('attached','deleted') THEN NOW() ELSE NULL END,\n                updated_at=NOW()\n        ", [
            ':type' => $type,
            ':external_id' => $externalId,
            ':site_id' => $siteId > 0 ? $siteId : null,
            ':expected_name' => $expectedName,
            ':actual_name' => $actualName,
            ':status' => $status,
            ':managed' => $managed,
            ':run_id' => $runId,
            ':metadata' => json_encode((object)$metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':resolved_at' => in_array($status, ['attached', 'deleted'], true) ? (new DateTimeImmutable())->format('c') : null,
        ]);
    }

    private static function markUnseenDeleted(int $runId): void
    {
        $rows = sb_db_fetch_all("\n            UPDATE sitebuilder.external_resource_registry\n            SET relation_status='deleted',resolved_at=NOW(),last_checked_at=NOW(),updated_at=NOW()\n            WHERE managed=TRUE\n              AND relation_status IN ('orphaned','cleanup_pending')\n              AND COALESCE(last_reconcile_run_id,0)<>:run_id\n            RETURNING resource_type,external_id\n        ", [':run_id' => $runId]);
        foreach ($rows as $row) {
            self::resolveResourceAlerts((string)$row['resource_type'], (int)$row['external_id']);
        }
    }

    private static function resolveSiteResourceAlertsExcept(int $siteId, string $type, string $activeSuffix): void
    {
        foreach (['unattached','missing','mismatched','name_mismatch'] as $suffix) {
            if ($suffix === $activeSuffix) {
                continue;
            }
            SystemAlertService::resolveByKey('external:site:' . $siteId . ':' . $type . ':' . $suffix);
        }
    }

    private static function markRegistryResolved(string $type, int $externalId): void
    {
        sb_db_execute("
            UPDATE sitebuilder.external_resource_registry
            SET site_id=NULL,relation_status='deleted',resolved_at=NOW(),last_checked_at=NOW(),updated_at=NOW()
            WHERE resource_type=:type AND external_id=:external_id
        ", [':type' => $type, ':external_id' => $externalId]);
    }

    private static function resolveSiteResourceAlerts(int $siteId, string $type): void
    {
        foreach (['unattached','missing','mismatched','name_mismatch'] as $suffix) {
            SystemAlertService::resolveByKey('external:site:' . $siteId . ':' . $type . ':' . $suffix);
        }
    }

    private static function resolveResourceAlerts(string $type, int $externalId): void
    {
        SystemAlertService::resolveByKey('external:' . $type . ':' . $externalId . ':orphaned');
    }

    private static function finding(array &$summary, array $finding): void
    {
        $summary['anomalies']++;
        $max = max(10, (int)self::config()['max_findings']);
        if (count($summary['findings']) < $max) {
            $summary['findings'][] = $finding;
        }
    }

    private static function startRun(int $siteId, string $mode, int $actorUserId, int $jobId): int
    {
        $stmt = sb_db()->prepare("\n            INSERT INTO sitebuilder.external_reconcile_run (site_id,mode,status,actor_user_id,job_id,started_at)\n            VALUES (:site_id,:mode,'running',:actor,:job_id,NOW())\n            RETURNING id\n        ");
        $stmt->execute([
            ':site_id' => $siteId > 0 ? $siteId : null,
            ':mode' => $mode,
            ':actor' => $actorUserId > 0 ? $actorUserId : null,
            ':job_id' => $jobId > 0 ? $jobId : null,
        ]);
        $id = (int)$stmt->fetchColumn();
        if ($id <= 0) {
            throw new RuntimeException('RECONCILE_RUN_CREATE_FAILED');
        }
        return $id;
    }

    private static function finishRun(int $runId, string $status, array $summary): void
    {
        sb_db_execute("\n            UPDATE sitebuilder.external_reconcile_run\n            SET status=:status,checked_sites=:checked_sites,checked_groups=:checked_groups,\n                checked_folders=:checked_folders,anomalies=:anomalies,repairs=:repairs,cleanup_jobs=:cleanup_jobs,\n                details_json=CAST(:details AS jsonb),finished_at=NOW()\n            WHERE id=:id\n        ", [
            ':id' => $runId,
            ':status' => $status,
            ':checked_sites' => (int)$summary['checkedSites'],
            ':checked_groups' => (int)$summary['checkedGroups'],
            ':checked_folders' => (int)$summary['checkedFolders'],
            ':anomalies' => (int)$summary['anomalies'],
            ':repairs' => (int)$summary['repairs'],
            ':cleanup_jobs' => (int)$summary['cleanupJobs'],
            ':details' => json_encode((object)$summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    private static function failRun(int $runId, Throwable $error, array $summary): void
    {
        $code = self::errorCode($error);
        try {
            sb_db_execute("\n                UPDATE sitebuilder.external_reconcile_run\n                SET status='failed',error_code=:error_code,\n                    checked_sites=:checked_sites,checked_groups=:checked_groups,checked_folders=:checked_folders,\n                    anomalies=:anomalies,repairs=:repairs,cleanup_jobs=:cleanup_jobs,\n                    details_json=CAST(:details AS jsonb),finished_at=NOW()\n                WHERE id=:id\n            ", [
                ':id' => $runId,
                ':error_code' => $code,
                ':checked_sites' => (int)$summary['checkedSites'],
                ':checked_groups' => (int)$summary['checkedGroups'],
                ':checked_folders' => (int)$summary['checkedFolders'],
                ':anomalies' => (int)$summary['anomalies'],
                ':repairs' => (int)$summary['repairs'],
                ':cleanup_jobs' => (int)$summary['cleanupJobs'],
                ':details' => json_encode((object)$summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            SystemAlertService::openOrTouch(
                'external:reconcile:run:' . $runId . ':failed',
                'critical', 'EXTERNAL_RECONCILE_FAILED',
                'Сверка внешних ресурсов завершилась с ошибкой',
                ['runId' => $runId, 'errorCode' => $code],
                (int)$summary['siteId'], 'external_reconcile_run', $runId
            );
        } catch (Throwable $stateError) {
            error_log('SiteBuilder reconcile failure state write failed: ' . $stateError->getMessage());
        }
    }

    public static function mapRegistryRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'resourceType' => (string)$row['resource_type'],
            'externalId' => (int)$row['external_id'],
            'siteId' => !empty($row['site_id']) ? (int)$row['site_id'] : 0,
            'expectedName' => (string)($row['expected_name'] ?? ''),
            'actualName' => (string)($row['actual_name'] ?? ''),
            'relationStatus' => (string)$row['relation_status'],
            'managed' => self::toBool($row['managed'] ?? false),
            'metadata' => sb_json_decode_assoc($row['metadata_json'] ?? '{}'),
            'firstSeenAt' => (string)$row['first_seen_at'],
            'lastSeenAt' => (string)$row['last_seen_at'],
            'lastCheckedAt' => (string)$row['last_checked_at'],
            'resolvedAt' => (string)($row['resolved_at'] ?? ''),
            'updatedAt' => (string)$row['updated_at'],
        ];
    }

    public static function mapRunRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'siteId' => !empty($row['site_id']) ? (int)$row['site_id'] : 0,
            'mode' => (string)$row['mode'],
            'status' => (string)$row['status'],
            'actorUserId' => !empty($row['actor_user_id']) ? (int)$row['actor_user_id'] : 0,
            'jobId' => !empty($row['job_id']) ? (int)$row['job_id'] : 0,
            'checkedSites' => (int)$row['checked_sites'],
            'checkedGroups' => (int)$row['checked_groups'],
            'checkedFolders' => (int)$row['checked_folders'],
            'anomalies' => (int)$row['anomalies'],
            'repairs' => (int)$row['repairs'],
            'cleanupJobs' => (int)$row['cleanup_jobs'],
            'errorCode' => (string)($row['error_code'] ?? ''),
            'details' => sb_json_decode_assoc($row['details_json'] ?? '{}'),
            'startedAt' => (string)$row['started_at'],
            'finishedAt' => (string)($row['finished_at'] ?? ''),
        ];
    }

    /**
     * Глобальная сверка получает exclusive lock. Сверка одного сайта получает
     * shared global lock и отдельный exclusive lock сайта. Поэтому разные сайты
     * могут сверяться параллельно, но глобальный проход не пересекается с ними.
     */
    private static function acquireLocks(int $siteId): ?array
    {
        if ($siteId <= 0) {
            if (!self::tryAdvisoryLock(self::GLOBAL_LOCK_NAMESPACE, 1, false)) {
                return null;
            }
            return [['namespace' => self::GLOBAL_LOCK_NAMESPACE, 'resource' => 1, 'shared' => false]];
        }

        if (!self::tryAdvisoryLock(self::GLOBAL_LOCK_NAMESPACE, 1, true)) {
            return null;
        }
        try {
            if (!self::tryAdvisoryLock(self::SITE_LOCK_NAMESPACE, $siteId, false)) {
                self::unlockAdvisory(self::GLOBAL_LOCK_NAMESPACE, 1, true);
                return null;
            }
        } catch (Throwable $e) {
            self::unlockAdvisory(self::GLOBAL_LOCK_NAMESPACE, 1, true);
            throw $e;
        }
        return [
            ['namespace' => self::GLOBAL_LOCK_NAMESPACE, 'resource' => 1, 'shared' => true],
            ['namespace' => self::SITE_LOCK_NAMESPACE, 'resource' => $siteId, 'shared' => false],
        ];
    }

    private static function releaseLocks(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            self::unlockAdvisory(
                (int)$lock['namespace'],
                (int)$lock['resource'],
                !empty($lock['shared'])
            );
        }
    }

    private static function tryAdvisoryLock(int $namespace, int $resource, bool $shared): bool
    {
        $sql = $shared
            ? 'SELECT pg_try_advisory_lock_shared(:namespace,:resource)'
            : 'SELECT pg_try_advisory_lock(:namespace,:resource)';
        $stmt = sb_db()->prepare($sql);
        $stmt->execute([':namespace' => $namespace, ':resource' => $resource]);
        return self::toBool($stmt->fetchColumn());
    }

    private static function unlockAdvisory(int $namespace, int $resource, bool $shared): void
    {
        try {
            $sql = $shared
                ? 'SELECT pg_advisory_unlock_shared(:namespace,:resource)'
                : 'SELECT pg_advisory_unlock(:namespace,:resource)';
            $stmt = sb_db()->prepare($sql);
            $stmt->execute([':namespace' => $namespace, ':resource' => $resource]);
        } catch (Throwable $e) {
            error_log('SiteBuilder reconcile unlock failed: ' . $e->getMessage());
        }
    }

    private static function toBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true', 'y', 'Y'], true);
    }

    private static function errorCode(Throwable $error): string
    {
        $message = strtoupper(trim($error->getMessage()));
        return preg_match('/^[A-Z][A-Z0-9_]{2,119}/', $message, $match) ? $match[0] : 'EXTERNAL_RECONCILE_FAILED';
    }

    private static function schemaReady(): bool
    {
        try {
            $stmt = sb_db()->query("
                SELECT
                    to_regclass('sitebuilder.external_reconcile_run') IS NOT NULL
                    AND to_regclass('sitebuilder.external_resource_registry') IS NOT NULL
                    AND to_regclass('sitebuilder.system_alert') IS NOT NULL
            ");
            return self::toBool($stmt->fetchColumn());
        } catch (Throwable $e) {
            error_log('SiteBuilder reconcile schema check failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function unifiedAccessSchemaReady(): bool
    {
        try {
            $row = sb_db_fetch_one("
                SELECT
                    to_regclass('sitebuilder.access_reconcile_run') AS run_table,
                    to_regclass('sitebuilder.access_sync_binding') AS binding_table
            ");
            return !empty($row['run_table']) && !empty($row['binding_table']);
        } catch (Throwable $exception) {
            return false;
        }
    }

    private static function config(): array
    {
        static $config;
        if (is_array($config)) {
            return $config;
        }
        $defaults = [
            'auto_interval_seconds' => 21600,
            'auto_mode' => 'audit',
            'repair_missing_links' => true,
            'max_findings' => 500,
        ];
        $path = dirname(__DIR__) . '/config/reconciliation.php';
        $loaded = is_file($path) ? require $path : [];
        $config = array_merge($defaults, is_array($loaded) ? $loaded : []);
        if (!in_array((string)$config['auto_mode'], ['audit', 'repair'], true)) {
            $config['auto_mode'] = 'audit';
        }
        return $config;
    }
}
