<?php

declare(strict_types=1);

use Bitrix\Main\Loader;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/json.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/PageAccessRepository.php';
require_once __DIR__ . '/PageAccessService.php';
require_once __DIR__ . '/RevisionService.php';
require_once __DIR__ . '/SiteBitrixGroupService.php';
require_once __DIR__ . '/SiteAccessManagementService.php';
require_once __DIR__ . '/AuditLogService.php';
require_once __DIR__ . '/SystemAlertService.php';

$diskLib = dirname(__DIR__) . '/components/disk/lib';
require_once $diskLib . '/DiskDb.php';
require_once $diskLib . '/DiskCurrentUser.php';
require_once $diskLib . '/DiskContext.php';
require_once $diskLib . '/DiskSitebuilderBridge.php';
require_once $diskLib . '/SiteRepository.php';
require_once $diskLib . '/DiskSettingsRepository.php';
require_once $diskLib . '/DiskRootResolver.php';
require_once $diskLib . '/DiskPageUserRepository.php';
require_once $diskLib . '/DiskAclBindingRepository.php';
require_once $diskLib . '/BitrixDiskRightsService.php';

/**
 * SiteBuilder является источником истины.
 *
 * - глобальные sitebuilder.access проецируются в рабочую группу портала;
 * - эффективные права страницы проецируются в прямые U-права Bitrix Disk;
 * - ручные внешние изменения не перезаписываются, если контроллер не может
 *   доказать, что текущее значение совпадает с ранее применённым им значением.
 */
final class UnifiedAccessReconciliationService
{
    public const MODE_AUDIT = 'audit';
    public const MODE_REPAIR = 'repair';

    private const TARGET_PORTAL = 'portal_member';
    private const TARGET_DISK = 'disk_acl';
    private const MAX_PLAN_ITEMS = 5000;

    public static function run(
        int $siteId,
        string $mode,
        int $actorUserId,
        int $jobId = 0
    ): array {
        if ($siteId <= 0 || $actorUserId <= 0) {
            throw new InvalidArgumentException('INVALID_ACCESS_RECONCILE_CONTEXT');
        }

        $mode = strtolower(trim($mode));
        if (!in_array($mode, [self::MODE_AUDIT, self::MODE_REPAIR], true)) {
            throw new InvalidArgumentException('INVALID_ACCESS_RECONCILE_MODE');
        }

        self::assertSchemaReady();
        $site = RevisionService::getSite($siteId, false);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $runId = self::startRun($siteId, $mode, $actorUserId, $jobId);

        try {
            if (!Loader::includeModule('socialnetwork')) {
                throw new RuntimeException('SOCIALNETWORK_MODULE_NOT_INSTALLED');
            }
            if (!Loader::includeModule('disk')) {
                throw new RuntimeException('DISK_MODULE_NOT_INSTALLED');
            }

            $portal = self::reconcilePortal(
                $site,
                $mode,
                $actorUserId,
                $runId
            );
            $disk = self::reconcileDisk(
                $site,
                $mode,
                $actorUserId,
                $runId
            );

            $planSize = count((array)($portal['items'] ?? []));
            foreach ((array)($disk['folders'] ?? []) as $folderPlan) {
                $planSize += count((array)($folderPlan['items'] ?? []));
            }

            if ($planSize > self::MAX_PLAN_ITEMS) {
                throw new RuntimeException('ACCESS_RECONCILE_PLAN_TOO_LARGE');
            }

            foreach ((array)($portal['items'] ?? []) as $item) {
                self::applyPortalItem(
                    $siteId,
                    $item,
                    $mode,
                    $actorUserId,
                    $runId,
                    $portal
                );
            }
            foreach ((array)($disk['folders'] ?? []) as $folderPlan) {
                self::applyDiskItems(
                    $siteId,
                    (int)($folderPlan['folderId'] ?? 0),
                    (array)($folderPlan['items'] ?? []),
                    $mode,
                    $runId,
                    $disk
                );
            }

            $planned = (int)$portal['planned'] + (int)$disk['planned'];
            $applied = (int)$portal['applied'] + (int)$disk['applied'];
            $conflicts = (int)$portal['conflicts'] + (int)$disk['conflicts'];
            $repairable = (int)$portal['repairable'] + (int)$disk['repairable'];
            $skipped = (int)$portal['skipped'] + (int)$disk['skipped'];

            $status = $conflicts > 0 ? 'partial' : 'succeeded';
            $result = [
                'runId' => $runId,
                'siteId' => $siteId,
                'mode' => $mode,
                'status' => $status,
                'planned' => $planned,
                'applied' => $applied,
                'conflicts' => $conflicts,
                'repairable' => $repairable,
                'skipped' => $skipped,
                'portal' => $portal,
                'disk' => $disk,
            ];

            self::finishRun($runId, $status, $result);
            self::synchronizeConflictAlert($result);
            AuditLogService::recordSystemAction(
                'access.reconcile.' . $mode,
                $result,
                'success',
                200,
                $siteId,
                $actorUserId
            );

            return $result;
        } catch (Throwable $exception) {
            self::failRun($runId, $exception);
            AuditLogService::recordSystemAction(
                'access.reconcile.' . $mode,
                [
                    'runId' => $runId,
                    'error' => self::errorCode($exception),
                ],
                'error',
                500,
                $siteId,
                $actorUserId
            );
            throw $exception;
        }
    }

    public static function listRuns(int $siteId, int $limit = 25): array
    {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('INVALID_SITE_ID');
        }

        self::assertSchemaReady();
        $limit = max(1, min(100, $limit));
        $stmt = sb_db()->prepare("
            SELECT id,site_id,mode,status,actor_user_id,job_id,
                   planned_count,applied_count,conflict_count,skipped_count,
                   error_code,details_json,started_at,finished_at
            FROM sitebuilder.access_reconcile_run
            WHERE site_id=:site_id
            ORDER BY id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'siteId' => (int)$row['site_id'],
                'mode' => (string)$row['mode'],
                'status' => (string)$row['status'],
                'actorUserId' => !empty($row['actor_user_id'])
                    ? (int)$row['actor_user_id']
                    : 0,
                'jobId' => !empty($row['job_id']) ? (int)$row['job_id'] : 0,
                'planned' => (int)$row['planned_count'],
                'applied' => (int)$row['applied_count'],
                'conflicts' => (int)$row['conflict_count'],
                'skipped' => (int)$row['skipped_count'],
                'errorCode' => (string)($row['error_code'] ?? ''),
                'details' => sb_json_decode_assoc($row['details_json'] ?? '{}'),
                'startedAt' => (string)$row['started_at'],
                'finishedAt' => (string)($row['finished_at'] ?? ''),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function reconcilePortal(
        array $site,
        string $mode,
        int $actorUserId,
        int $runId
    ): array {
        $siteId = (int)($site['id'] ?? 0);
        $groupId = (int)($site['bitrixGroupId'] ?? 0);
        if ($groupId <= 0) {
            throw new RuntimeException('BITRIX_GROUP_NOT_READY');
        }

        $group = SiteBitrixGroupService::inspectGroup($groupId);
        if (!$group || empty($group['active'])) {
            throw new RuntimeException('BITRIX_GROUP_NOT_FOUND');
        }

        $groupManaged = !empty($group['managed'])
            && (int)($site['bitrixGroupCreatedBy'] ?? 0) > 0;
        $ownerUserId = (int)($group['ownerId'] ?? 0);
        $current = SiteAccessManagementService::listPortalMemberships($siteId);
        $desiredSiteRoles = self::effectiveGlobalRolesByUser($siteId);
        $desired = [];
        foreach ($desiredSiteRoles as $userId => $siteRole) {
            $desired[$userId] = self::portalRoleForSiteRole(
                $siteRole,
                $userId === $ownerUserId
            );
        }

        $bindings = self::loadBindings(
            $siteId,
            self::TARGET_PORTAL,
            $groupId
        );
        if (
            count($current) > self::MAX_PLAN_ITEMS
            || count($desired) > self::MAX_PLAN_ITEMS
            || count($bindings) > self::MAX_PLAN_ITEMS
        ) {
            throw new RuntimeException('ACCESS_RECONCILE_PLAN_TOO_LARGE');
        }
        $items = [];

        foreach ($desired as $userId => $desiredRole) {
            $accessCode = 'U' . $userId;
            $item = self::planItem(
                self::TARGET_PORTAL,
                $groupId,
                $userId,
                $desiredRole,
                $current[$userId] ?? '',
                $bindings[$accessCode] ?? null,
                '',
                $groupManaged,
                [
                    'siteRole' => $desiredSiteRoles[$userId] ?? '',
                    'groupManaged' => $groupManaged,
                ]
            );
            if (!$groupManaged && (string)$item['action'] === 'apply') {
                $item['action'] = 'conflict';
                $item['reason'] = 'PORTAL_GROUP_NOT_MANAGED';
            }
            $items[] = $item;
        }

        foreach ($bindings as $accessCode => $binding) {
            if (empty($binding['managed'])) {
                continue;
            }
            $userId = self::userIdFromAccessCode($accessCode);
            if ($userId <= 0 || isset($desired[$userId])) {
                continue;
            }
            $item = self::planItem(
                self::TARGET_PORTAL,
                $groupId,
                $userId,
                null,
                $current[$userId] ?? '',
                $binding,
                '',
                true,
                ['groupManaged' => $groupManaged]
            );
            if ((string)($current[$userId] ?? '') === self::portalOwnerRole()) {
                $item['action'] = 'conflict';
                $item['reason'] = 'BITRIX_GROUP_OWNER_PROTECTED';
            } elseif (!$groupManaged && (string)$item['action'] === 'apply') {
                $item['action'] = 'conflict';
                $item['reason'] = 'PORTAL_GROUP_NOT_MANAGED';
            }
            $items[] = $item;
        }

        $unmanaged = [];
        foreach ($current as $userId => $role) {
            if (isset($desired[$userId]) || isset($bindings['U' . $userId])) {
                continue;
            }
            $unmanaged[] = [
                'userId' => (int)$userId,
                'role' => (string)$role,
            ];
        }

        $summary = self::emptySummary();
        $summary['unmanaged'] = $unmanaged;
        $summary['items'] = $items;
        $summary['groupId'] = $groupId;
        $summary['groupManaged'] = $groupManaged;
        $summary['skipped'] += count($unmanaged);
        return $summary;
    }

    private static function reconcileDisk(
        array $site,
        string $mode,
        int $actorUserId,
        int $runId
    ): array {
        $siteId = (int)($site['id'] ?? 0);
        $rows = sb_db_fetch_all("
            SELECT b.id AS block_id, b.page_id, b.props_json
            FROM sitebuilder.block b
            JOIN sitebuilder.page p ON p.id=b.page_id
            WHERE p.site_id=:site_id AND b.type='disk'
            ORDER BY b.id ASC
        ", [':site_id' => $siteId]);

        $folders = [];
        $skippedBlocks = [];

        foreach ($rows as $row) {
            $blockId = (int)$row['block_id'];
            $pageId = (int)$row['page_id'];
            $settings = DiskSitebuilderBridge::normalizeDiskProps(
                sb_json_decode_assoc($row['props_json'] ?? '{}')
            );
            if ((string)$settings['permissionMode'] !== 'bitrix_disk') {
                continue;
            }

            $context = new DiskContext(
                $siteId,
                $pageId,
                $blockId,
                $actorUserId
            );
            $root = DiskRootResolver::resolveWithSource(
                $context,
                $settings,
                false
            );
            $folderId = (int)($root['rootFolderId'] ?? 0);
            if ($folderId <= 0) {
                $skippedBlocks[] = [
                    'blockId' => $blockId,
                    'pageId' => $pageId,
                    'reason' => 'DISK_ROOT_FOLDER_NOT_FOUND',
                ];
                continue;
            }

            if (!isset($folders[$folderId])) {
                $folders[$folderId] = [
                    'folderId' => $folderId,
                    'consumers' => [],
                    'desired' => [],
                    'conflictingUsers' => [],
                ];
            }
            $folders[$folderId]['consumers'][] = [
                'blockId' => $blockId,
                'pageId' => $pageId,
                'rootSource' => (string)($root['source'] ?? 'none'),
            ];

            foreach (DiskPageUserRepository::listUsersWithPageAccess(
                $siteId,
                $pageId,
                true
            ) as $user) {
                $userId = (int)($user['userId'] ?? 0);
                if ($userId <= 0 || !empty($user['isBitrixAdmin'])) {
                    continue;
                }

                $taskName = self::diskTaskForPageUser($user);
                if (isset($folders[$folderId]['desired'][$userId])) {
                    if ($folders[$folderId]['desired'][$userId] !== $taskName) {
                        $tasks = $folders[$folderId]['conflictingUsers'][$userId]
                            ?? [$folders[$folderId]['desired'][$userId]];
                        $tasks[] = $taskName;
                        $folders[$folderId]['conflictingUsers'][$userId] =
                            array_values(array_unique($tasks));
                    }
                    continue;
                }

                $folders[$folderId]['desired'][$userId] = $taskName;
            }
        }

        $allBindings = self::loadBindings($siteId, self::TARGET_DISK, null);
        foreach ($allBindings as $binding) {
            if (empty($binding['managed'])) {
                continue;
            }
            $folderId = (int)($binding['targetId'] ?? 0);
            if ($folderId > 0 && !isset($folders[$folderId])) {
                $folders[$folderId] = [
                    'folderId' => $folderId,
                    'consumers' => [],
                    'desired' => [],
                    'conflictingUsers' => [],
                ];
            }
        }

        ksort($folders, SORT_NUMERIC);
        $summary = self::emptySummary();
        $summary['folders'] = [];
        $summary['skippedBlocks'] = $skippedBlocks;
        $summary['skipped'] += count($skippedBlocks);

        foreach ($folders as $folderId => $folder) {
            $folderBindings = self::loadBindings(
                $siteId,
                self::TARGET_DISK,
                (int)$folderId
            );
            $folderMissing = false;
            try {
                $current = BitrixDiskRightsService::getDirectUserRightsSnapshot(
                    (int)$folderId,
                    $siteId
                );
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'DISK_ROOT_FOLDER_NOT_FOUND') {
                    throw $exception;
                }
                $folderMissing = true;
                $current = [];
            }
            $items = [];

            foreach ($folder['desired'] as $userId => $desiredTask) {
                $accessCode = 'U' . $userId;
                if ($folderMissing) {
                    $binding = $folderBindings[$accessCode] ?? null;
                    $items[] = [
                        'targetType' => self::TARGET_DISK,
                        'targetId' => (int)$folderId,
                        'userId' => (int)$userId,
                        'accessCode' => $accessCode,
                        'desired' => (string)$desiredTask,
                        'current' => BitrixDiskRightsService::INHERIT,
                        'action' => 'conflict',
                        'reason' => 'DISK_ROOT_FOLDER_NOT_FOUND',
                        'managed' => $binding && !empty($binding['managed']),
                        'applied' => (string)($binding['appliedLevel'] ?? ''),
                        'metadata' => ['consumers' => $folder['consumers']],
                    ];
                    continue;
                }
                if (isset($folder['conflictingUsers'][$userId])) {
                    $binding = $folderBindings[$accessCode] ?? null;
                    $items[] = [
                        'targetType' => self::TARGET_DISK,
                        'targetId' => (int)$folderId,
                        'userId' => (int)$userId,
                        'accessCode' => $accessCode,
                        'desired' => $desiredTask,
                        'current' => $current[$userId] ?? BitrixDiskRightsService::INHERIT,
                        'action' => 'conflict',
                        'reason' => 'SHARED_FOLDER_RIGHTS_CONFLICT',
                        'managed' => $binding && !empty($binding['managed']),
                        'applied' => (string)($binding['appliedLevel'] ?? ''),
                        'metadata' => [
                            'tasks' => $folder['conflictingUsers'][$userId],
                            'consumers' => $folder['consumers'],
                        ],
                    ];
                    continue;
                }

                $items[] = self::planItem(
                    self::TARGET_DISK,
                    (int)$folderId,
                    (int)$userId,
                    (string)$desiredTask,
                    $current[$userId] ?? BitrixDiskRightsService::INHERIT,
                    $folderBindings[$accessCode] ?? null,
                    BitrixDiskRightsService::INHERIT,
                    true,
                    ['consumers' => $folder['consumers']]
                );
            }

            foreach ($folderBindings as $accessCode => $binding) {
                if (empty($binding['managed'])) {
                    continue;
                }
                $userId = self::userIdFromAccessCode($accessCode);
                if ($userId <= 0 || isset($folder['desired'][$userId])) {
                    continue;
                }
                if ($folderMissing) {
                    $items[] = [
                        'targetType' => self::TARGET_DISK,
                        'targetId' => (int)$folderId,
                        'userId' => $userId,
                        'accessCode' => $accessCode,
                        'desired' => BitrixDiskRightsService::INHERIT,
                        'current' => BitrixDiskRightsService::INHERIT,
                        'action' => 'removed',
                        'reason' => 'DISK_ROOT_FOLDER_ALREADY_MISSING',
                        'managed' => true,
                        'applied' => (string)($binding['appliedLevel'] ?? ''),
                        'metadata' => ['consumers' => $folder['consumers']],
                    ];
                    continue;
                }
                $items[] = self::planItem(
                    self::TARGET_DISK,
                    (int)$folderId,
                    $userId,
                    null,
                    $current[$userId] ?? BitrixDiskRightsService::INHERIT,
                    $binding,
                    BitrixDiskRightsService::INHERIT,
                    true,
                    ['consumers' => $folder['consumers']]
                );
            }

            $unmanaged = [];
            foreach ($current as $userId => $taskName) {
                if (
                    isset($folder['desired'][$userId])
                    || isset($folderBindings['U' . $userId])
                ) {
                    continue;
                }
                $unmanaged[] = [
                    'userId' => (int)$userId,
                    'taskName' => (string)$taskName,
                ];
            }

            $summary['skipped'] += count($unmanaged);
            $summary['folders'][] = [
                'folderId' => (int)$folderId,
                'consumers' => $folder['consumers'],
                'folderMissing' => $folderMissing,
                'unmanaged' => $unmanaged,
                'items' => $items,
            ];
        }

        return $summary;
    }

    private static function planItem(
        string $targetType,
        int $targetId,
        int $userId,
        ?string $desired,
        string $current,
        ?array $binding,
        string $neutral,
        bool $allowAdopt,
        array $metadata
    ): array {
        $accessCode = 'U' . $userId;
        $managedBinding = $binding && !empty($binding['managed']);
        $applied = $managedBinding
            ? (string)($binding['appliedLevel'] ?? '')
            : '';
        $target = $desired ?? $neutral;
        $action = 'noop';
        $reason = '';

        if ($desired === null) {
            if ($current === $neutral) {
                $action = 'removed';
            } elseif ($managedBinding && $current === $applied) {
                $action = 'apply';
            } else {
                $action = 'conflict';
                $reason = 'EXTERNAL_RIGHT_CHANGED';
            }
        } elseif ($current === $target) {
            $action = $managedBinding || $allowAdopt ? 'adopt' : 'observe';
        } elseif ($current === $neutral) {
            $action = 'apply';
        } elseif ($managedBinding && $current === $applied) {
            $action = 'apply';
        } else {
            $action = 'conflict';
            $reason = 'UNMANAGED_EXTERNAL_RIGHT';
        }

        return [
            'targetType' => $targetType,
            'targetId' => $targetId,
            'userId' => $userId,
            'accessCode' => $accessCode,
            'desired' => $target,
            'current' => $current,
            'action' => $action,
            'reason' => $reason,
            'managed' => $managedBinding,
            'applied' => $applied,
            'metadata' => $metadata,
        ];
    }

    private static function applyPortalItem(
        int $siteId,
        array $item,
        string $mode,
        int $actorUserId,
        int $runId,
        array &$summary
    ): void {
        $summary['planned']++;
        $action = (string)$item['action'];

        if ($action === 'conflict') {
            $summary['conflicts']++;
            if ($mode === self::MODE_REPAIR) {
                self::upsertBinding(
                    $siteId,
                    $item,
                    $runId,
                    !empty($item['managed']),
                    'conflict',
                    false
                );
            }
            return;
        }

        if ($action === 'apply') {
            $summary['repairable']++;
        }

        if ($mode === self::MODE_AUDIT || $action === 'observe') {
            $summary['skipped']++;
            return;
        }

        if ($action === 'apply') {
            SiteAccessManagementService::applyExpectedPortalRole(
                $siteId,
                (int)$item['userId'],
                (string)$item['desired'] !== '' ? (string)$item['desired'] : null,
                (string)$item['current'] !== '' ? (string)$item['current'] : null,
                $actorUserId
            );
            $summary['applied']++;
        } else {
            $summary['skipped']++;
        }

        self::upsertBinding(
            $siteId,
            $item,
            $runId,
            true,
            (string)$item['desired'] === '' ? 'removed' : 'synced'
        );
    }

    private static function applyDiskItems(
        int $siteId,
        int $folderId,
        array $items,
        string $mode,
        int $runId,
        array &$summary
    ): void {
        $requested = [];
        $expected = [];

        foreach ($items as $item) {
            $summary['planned']++;
            if ((string)$item['action'] === 'apply') {
                $summary['repairable']++;
            }
            if ((string)$item['action'] === 'conflict') {
                $summary['conflicts']++;
                if ($mode === self::MODE_REPAIR) {
                    self::upsertBinding(
                        $siteId,
                        $item,
                        $runId,
                        !empty($item['managed']),
                        'conflict',
                        false
                    );
                }
                continue;
            }

            if ($mode === self::MODE_REPAIR && (string)$item['action'] === 'apply') {
                $requested[(int)$item['userId']] = (string)$item['desired'];
                $expected[(int)$item['userId']] = (string)$item['current'];
            } else {
                $summary['skipped']++;
            }
        }

        if ($mode === self::MODE_REPAIR && !empty($requested)) {
            BitrixDiskRightsService::replaceDirectUserRights(
                $folderId,
                $requested,
                $expected
            );
            $summary['applied'] += count($requested);
        }

        if ($mode !== self::MODE_REPAIR) {
            return;
        }

        foreach ($items as $item) {
            if (in_array((string)$item['action'], ['conflict', 'observe'], true)) {
                continue;
            }
            self::upsertBinding(
                $siteId,
                $item,
                $runId,
                true,
                (string)$item['desired'] === BitrixDiskRightsService::INHERIT
                    ? 'removed'
                    : 'synced'
            );
        }
    }

    private static function portalRoleForSiteRole(
        string $siteRole,
        bool $isPortalOwner
    ): string {
        if ($isPortalOwner) {
            return defined('SONET_ROLES_OWNER') ? SONET_ROLES_OWNER : 'A';
        }

        $siteRole = strtoupper(trim($siteRole));
        if ($siteRole === 'VIEWER') {
            return defined('SONET_ROLES_USER') ? SONET_ROLES_USER : 'K';
        }
        if (in_array($siteRole, ['EDITOR', 'ADMIN', 'OWNER'], true)) {
            return defined('SONET_ROLES_MODERATOR') ? SONET_ROLES_MODERATOR : 'E';
        }
        throw new RuntimeException('INVALID_ROLE');
    }

    private static function portalOwnerRole(): string
    {
        return defined('SONET_ROLES_OWNER') ? SONET_ROLES_OWNER : 'A';
    }

    private static function diskTaskForPageUser(array $user): string
    {
        $role = strtoupper(trim((string)($user['globalRole'] ?? '')));
        $pageAccess = (array)($user['pageAccess'] ?? []);

        if (in_array($role, ['ADMIN', 'OWNER'], true)) {
            return 'disk_access_full';
        }
        if (!empty($pageAccess['canDiskEdit'])) {
            return 'disk_access_edit';
        }
        if (!empty($pageAccess['canDiskView'])) {
            return 'disk_access_read';
        }
        return BitrixDiskRightsService::NONE;
    }

    /** @return array<int,string> */
    private static function effectiveGlobalRolesByUser(int $siteId): array
    {
        $rows = sb_db_fetch_all("
            SELECT access_code,role
            FROM sitebuilder.access
            WHERE site_id=:site_id
            ORDER BY access_code
        ", [':site_id' => $siteId]);

        $result = [];
        foreach ($rows as $row) {
            $accessCode = mb_strtoupper(trim((string)($row['access_code'] ?? '')));
            $role = mb_strtoupper(trim((string)($row['role'] ?? '')));
            $userIds = [];

            if (preg_match('/^U([1-9]\d*)$/', $accessCode, $matches)) {
                $userIds[] = (int)$matches[1];
            } elseif (preg_match('/^G([1-9]\d*)$/', $accessCode, $matches)) {
                $userIds = self::activeUserIdsInMainGroup((int)$matches[1]);
            }

            foreach ($userIds as $userId) {
                $current = (string)($result[$userId] ?? '');
                if (self::siteRoleRank($role) > self::siteRoleRank($current)) {
                    $result[$userId] = $role;
                }
            }
            if (count($result) > self::MAX_PLAN_ITEMS) {
                throw new RuntimeException('ACCESS_RECONCILE_PLAN_TOO_LARGE');
            }
        }

        if (!empty($result) && class_exists('\Bitrix\Main\UserTable')) {
            $active = [];
            $users = \Bitrix\Main\UserTable::getList([
                'select' => ['ID'],
                'filter' => [
                    '@ID' => array_keys($result),
                    '=ACTIVE' => 'Y',
                ],
            ]);
            while ($user = $users->fetch()) {
                $active[(int)($user['ID'] ?? 0)] = true;
            }
            $result = array_filter(
                $result,
                static fn(string $role, int $userId): bool => isset($active[$userId]),
                ARRAY_FILTER_USE_BOTH
            );
        }

        ksort($result, SORT_NUMERIC);
        return $result;
    }

    /** @return int[] */
    private static function activeUserIdsInMainGroup(int $groupId): array
    {
        if ($groupId <= 0 || !class_exists('CUser')) {
            return [];
        }

        $by = 'id';
        $order = 'asc';
        $rows = \CUser::GetList(
            $by,
            $order,
            ['ACTIVE' => 'Y', 'GROUPS_ID' => $groupId],
            ['FIELDS' => ['ID']]
        );
        $result = [];
        while ($row = $rows->Fetch()) {
            $userId = (int)($row['ID'] ?? 0);
            if ($userId > 0) {
                $result[$userId] = true;
                if (count($result) > self::MAX_PLAN_ITEMS) {
                    throw new RuntimeException('ACCESS_RECONCILE_PLAN_TOO_LARGE');
                }
            }
        }
        return array_map('intval', array_keys($result));
    }

    private static function siteRoleRank(string $role): int
    {
        return match (mb_strtoupper(trim($role))) {
            'VIEWER' => 1,
            'EDITOR' => 2,
            'ADMIN' => 3,
            'OWNER' => 4,
            default => 0,
        };
    }

    /** @return array<string,array> */
    private static function loadBindings(
        int $siteId,
        string $targetType,
        ?int $targetId
    ): array {
        $params = [
            ':site_id' => $siteId,
            ':target_type' => $targetType,
        ];
        $whereTarget = '';
        if ($targetId !== null) {
            $whereTarget = ' AND target_id=:target_id';
            $params[':target_id'] = $targetId;
        }

        $rows = sb_db_fetch_all("
            SELECT *
            FROM sitebuilder.access_sync_binding
            WHERE site_id=:site_id
              AND target_type=:target_type
              {$whereTarget}
            ORDER BY target_id, access_code
        ", $params);

        $result = [];
        foreach ($rows as $row) {
            $mapped = self::mapBinding($row);
            $key = $targetId === null
                ? (string)$mapped['targetId'] . ':' . (string)$mapped['accessCode']
                : (string)$mapped['accessCode'];
            $result[$key] = $mapped;
        }
        return $result;
    }

    private static function upsertBinding(
        int $siteId,
        array $item,
        int $runId,
        bool $managed,
        string $status,
        bool $appliedNow = true
    ): void {
        $desired = (string)($item['desired'] ?? '');
        $current = (string)($item['current'] ?? '');
        $applied = $appliedNow
            ? $desired
            : (string)($item['applied'] ?? '');
        $stmt = sb_db()->prepare("
            INSERT INTO sitebuilder.access_sync_binding (
                site_id,target_type,target_id,access_code,
                desired_level,applied_level,last_external_level,
                status,managed,last_run_id,last_error_code,metadata_json,
                first_managed_at,last_checked_at,last_applied_at,updated_at
            ) VALUES (
                :site_id,:target_type,:target_id,:access_code,
                :desired_level,:applied_level,:last_external_level,
                :status,:managed,:last_run_id,:last_error_code,
                CAST(:metadata_json AS jsonb),
                CASE WHEN CAST(:managed_binding AS boolean) THEN NOW() ELSE NULL END,
                NOW(),
                CASE WHEN CAST(:applied_now AS boolean) THEN NOW() ELSE NULL END,
                NOW()
            )
            ON CONFLICT (site_id,target_type,target_id,access_code)
            DO UPDATE SET
                desired_level=EXCLUDED.desired_level,
                applied_level=CASE
                    WHEN EXCLUDED.last_applied_at IS NOT NULL THEN EXCLUDED.applied_level
                    ELSE sitebuilder.access_sync_binding.applied_level
                END,
                last_external_level=EXCLUDED.last_external_level,
                status=EXCLUDED.status,
                managed=EXCLUDED.managed,
                last_run_id=EXCLUDED.last_run_id,
                last_error_code=EXCLUDED.last_error_code,
                metadata_json=EXCLUDED.metadata_json,
                first_managed_at=CASE
                    WHEN EXCLUDED.first_managed_at IS NOT NULL
                    THEN COALESCE(sitebuilder.access_sync_binding.first_managed_at,NOW())
                    ELSE sitebuilder.access_sync_binding.first_managed_at
                END,
                last_checked_at=NOW(),
                last_applied_at=CASE
                    WHEN EXCLUDED.last_applied_at IS NOT NULL THEN NOW()
                    ELSE sitebuilder.access_sync_binding.last_applied_at
                END,
                updated_at=NOW()
        ");
        $stmt->execute([
            ':site_id' => $siteId,
            ':target_type' => (string)$item['targetType'],
            ':target_id' => (int)$item['targetId'],
            ':access_code' => (string)$item['accessCode'],
            ':desired_level' => $desired,
            ':applied_level' => $applied,
            ':last_external_level' => $current,
            ':status' => $status,
            ':managed' => $managed ? 'true' : 'false',
            ':last_run_id' => $runId,
            ':last_error_code' => $status === 'conflict'
                ? (string)($item['reason'] ?? 'ACCESS_SYNC_CONFLICT')
                : null,
            ':metadata_json' => json_encode(
                (object)($item['metadata'] ?? []),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            ':managed_binding' => $managed ? 'true' : 'false',
            ':applied_now' => $appliedNow ? 'true' : 'false',
        ]);
    }

    private static function mapBinding(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'siteId' => (int)$row['site_id'],
            'targetType' => (string)$row['target_type'],
            'targetId' => (int)$row['target_id'],
            'accessCode' => (string)$row['access_code'],
            'desiredLevel' => (string)$row['desired_level'],
            'appliedLevel' => (string)$row['applied_level'],
            'lastExternalLevel' => (string)$row['last_external_level'],
            'status' => (string)$row['status'],
            'managed' => self::boolValue($row['managed'] ?? false),
        ];
    }

    private static function emptySummary(): array
    {
        return [
            'planned' => 0,
            'applied' => 0,
            'conflicts' => 0,
            'repairable' => 0,
            'skipped' => 0,
        ];
    }

    private static function userIdFromAccessCode(string $accessCode): int
    {
        return preg_match('/^U([1-9]\d*)$/', $accessCode, $matches)
            ? (int)$matches[1]
            : 0;
    }

    private static function assertSchemaReady(): void
    {
        $row = sb_db_fetch_one("
            SELECT
                to_regclass('sitebuilder.access_reconcile_run') AS run_table,
                to_regclass('sitebuilder.access_sync_binding') AS binding_table
        ");
        if (empty($row['run_table']) || empty($row['binding_table'])) {
            throw new RuntimeException('ACCESS_RECONCILIATION_MIGRATION_REQUIRED');
        }
    }

    private static function startRun(
        int $siteId,
        string $mode,
        int $actorUserId,
        int $jobId
    ): int {
        $row = sb_db_fetch_one("
            INSERT INTO sitebuilder.access_reconcile_run (
                site_id,mode,status,actor_user_id,job_id,details_json
            ) VALUES (
                :site_id,:mode,'running',:actor_user_id,:job_id,'{}'::jsonb
            )
            RETURNING id
        ", [
            ':site_id' => $siteId,
            ':mode' => $mode,
            ':actor_user_id' => $actorUserId,
            ':job_id' => $jobId > 0 ? $jobId : null,
        ]);
        if (!$row) {
            throw new RuntimeException('ACCESS_RECONCILE_RUN_CREATE_FAILED');
        }
        return (int)$row['id'];
    }

    private static function finishRun(
        int $runId,
        string $status,
        array $result
    ): void {
        $stmt = sb_db()->prepare("
            UPDATE sitebuilder.access_reconcile_run
            SET status=:status,
                planned_count=:planned,
                applied_count=:applied,
                conflict_count=:conflicts,
                skipped_count=:skipped,
                details_json=CAST(:details AS jsonb),
                finished_at=NOW()
            WHERE id=:id
        ");
        $stmt->execute([
            ':status' => $status,
            ':planned' => (int)$result['planned'],
            ':applied' => (int)$result['applied'],
            ':conflicts' => (int)$result['conflicts'],
            ':skipped' => (int)$result['skipped'],
            ':details' => json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            ':id' => $runId,
        ]);
    }

    private static function failRun(int $runId, Throwable $exception): void
    {
        $stmt = sb_db()->prepare("
            UPDATE sitebuilder.access_reconcile_run
            SET status='failed',error_code=:error_code,
                details_json=CAST(:details AS jsonb),finished_at=NOW()
            WHERE id=:id
        ");
        $stmt->execute([
            ':error_code' => self::errorCode($exception),
            ':details' => json_encode(
                ['message' => mb_substr($exception->getMessage(), 0, 500)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            ':id' => $runId,
        ]);
    }

    private static function errorCode(Throwable $exception): string
    {
        $code = strtoupper(trim($exception->getMessage()));
        $code = preg_replace('/[^A-Z0-9_].*$/', '', $code) ?: '';
        return preg_match('/^[A-Z][A-Z0-9_]{2,119}$/', $code)
            ? $code
            : 'ACCESS_RECONCILE_FAILED';
    }

    private static function boolValue($value): bool
    {
        return is_bool($value)
            ? $value
            : in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }

    private static function synchronizeConflictAlert(array $result): void
    {
        $siteId = (int)($result['siteId'] ?? 0);
        if ($siteId <= 0) {
            return;
        }

        $alertKey = 'access:site:' . $siteId . ':conflicts';
        try {
            $conflicts = (int)($result['conflicts'] ?? 0);
            $repairableDrift = (string)($result['mode'] ?? '') === self::MODE_AUDIT
                ? (int)($result['repairable'] ?? 0)
                : 0;
            if ($conflicts <= 0 && $repairableDrift <= 0) {
                SystemAlertService::resolveByKey($alertKey);
                return;
            }

            SystemAlertService::openOrTouch(
                $alertKey,
                'warning',
                $conflicts > 0
                    ? 'ACCESS_RECONCILE_CONFLICTS'
                    : 'ACCESS_RECONCILE_DRIFT',
                $conflicts > 0
                    ? 'Обнаружены конфликты синхронизации прав SiteBuilder'
                    : 'Обнаружено безопасно исправимое расхождение прав SiteBuilder',
                [
                    'runId' => (int)($result['runId'] ?? 0),
                    'mode' => (string)($result['mode'] ?? ''),
                    'planned' => (int)($result['planned'] ?? 0),
                    'applied' => (int)($result['applied'] ?? 0),
                    'conflicts' => $conflicts,
                    'repairable' => (int)($result['repairable'] ?? 0),
                ],
                $siteId,
                'access_reconcile_run',
                (int)($result['runId'] ?? 0)
            );
        } catch (Throwable $exception) {
            error_log('SiteBuilder access conflict alert failed: ' . $exception->getMessage());
        }
    }
}
