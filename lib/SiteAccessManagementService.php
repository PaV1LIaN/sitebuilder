<?php

use Bitrix\Main\Loader;

require_once __DIR__ . '/OutboxService.php';

class SiteAccessManagementService
{
    public static function list(int $siteId): array
    {
        if ($siteId <= 0) {
            throw new RuntimeException('EMPTY_SITE_ID');
        }

        $access = sb_read_access();
        $items = [];

        foreach ($access as $row) {
            if ((int)($row['siteId'] ?? 0) !== $siteId) {
                continue;
            }

            $accessCode = (string)($row['accessCode'] ?? '');
            $role = (string)($row['role'] ?? '');

            if ($accessCode === '' || $role === '') {
                continue;
            }

            $userId = self::extractUserId($accessCode);

            $items[] = [
                'siteId' => $siteId,
                'accessCode' => $accessCode,
                'userId' => $userId,
                'userName' => $userId > 0 ? self::getUserName($userId) : '',
                'role' => $role,
                'createdBy' => (int)($row['createdBy'] ?? 0),
                'createdAt' => (string)($row['createdAt'] ?? ''),
                'updatedBy' => (int)($row['updatedBy'] ?? 0),
                'updatedAt' => (string)($row['updatedAt'] ?? ''),
            ];
        }

        usort($items, static function ($a, $b) {
            $rankA = function_exists('sb_role_rank') ? sb_role_rank($a['role'] ?? '') : 0;
            $rankB = function_exists('sb_role_rank') ? sb_role_rank($b['role'] ?? '') : 0;

            if ($rankA !== $rankB) {
                return $rankB <=> $rankA;
            }

            return (int)($a['userId'] ?? 0) <=> (int)($b['userId'] ?? 0);
        });

        return $items;
    }

    public static function setRole(int $siteId, int $targetUserId, string $role, int $currentUserId): array
    {
        if ($siteId <= 0) {
            throw new RuntimeException('EMPTY_SITE_ID');
        }

        if ($targetUserId <= 0) {
            throw new RuntimeException('EMPTY_TARGET_USER_ID');
        }

        if ($currentUserId <= 0) {
            throw new RuntimeException('EMPTY_CURRENT_USER_ID');
        }

        $role = self::normalizeRole($role);
        $accessCode = 'U' . $targetUserId;

        $saveResult = sb_set_access_role(
            $siteId,
            $accessCode,
            $role,
            $currentUserId,
            [
                'allowOwnerAssignment' => true,
                'allowOwnerDowngrade' => true,
                'protectLastOwner' => true,
            ]
        );

        $found = !empty($saveResult['updated']);

        $groupJob = OutboxService::enqueueGroupMemberReconcile(
            $siteId,
            $targetUserId,
            $currentUserId
        );
        $groupSync = [
            'ok' => true,
            'queued' => true,
            'jobId' => (int)$groupJob['id'],
            'jobType' => (string)$groupJob['jobType'],
        ];

        return [
            'siteId' => $siteId,
            'accessCode' => $accessCode,
            'userId' => $targetUserId,
            'role' => $role,
            'created' => !$found,
            'updated' => $found,
            'groupSync' => $groupSync,
            'items' => self::list($siteId),
        ];
    }

    public static function removeRole(int $siteId, int $targetUserId, int $currentUserId): array
    {
        if ($siteId <= 0) {
            throw new RuntimeException('EMPTY_SITE_ID');
        }

        if ($targetUserId <= 0) {
            throw new RuntimeException('EMPTY_TARGET_USER_ID');
        }

        if ($currentUserId <= 0) {
            throw new RuntimeException('EMPTY_CURRENT_USER_ID');
        }

        $accessCode = 'U' . $targetUserId;

        $deletedRow = sb_delete_access_row(
            $siteId,
            $accessCode,
            [
                'allowOwnerRemoval' => true,
                'protectLastOwner' => true,
            ]
        );

        $removed = $deletedRow !== null;

        $groupJob = OutboxService::enqueueGroupMemberReconcile(
            $siteId,
            $targetUserId,
            $currentUserId
        );
        $groupSync = [
            'ok' => true,
            'queued' => true,
            'jobId' => (int)$groupJob['id'],
            'jobType' => (string)$groupJob['jobType'],
        ];

        return [
            'siteId' => $siteId,
            'accessCode' => $accessCode,
            'userId' => $targetUserId,
            'removed' => $removed,
            'groupSync' => $groupSync,
            'items' => self::list($siteId),
        ];
    }

    /**
     * Приводит членство пользователя в рабочей группе к текущей прямой роли
     * SiteBuilder. Метод вызывается только worker-ом после COMMIT.
     */
    public static function reconcileUserMembership(
        int $siteId,
        int $targetUserId,
        int $actorUserId
    ): array {
        if ($siteId <= 0 || $targetUserId <= 0) {
            throw new InvalidArgumentException('INVALID_GROUP_MEMBER_RECONCILE_TARGET');
        }

        $groupId = self::getSiteBitrixGroupId($siteId);
        if ($groupId <= 0) {
            throw new RuntimeException('BITRIX_GROUP_NOT_READY');
        }

        $row = sb_db_fetch_one("
            SELECT role
            FROM sitebuilder.access
            WHERE site_id=:site_id AND access_code=:access_code
            LIMIT 1
        ", [
            ':site_id' => $siteId,
            ':access_code' => 'U' . $targetUserId,
        ]);

        $role = strtoupper(trim((string)($row['role'] ?? '')));
        if ($role === '') {
            $result = self::removeUserFromBitrixGroup(
                $siteId,
                $targetUserId,
                $actorUserId
            );
        } else {
            $result = self::syncUserToBitrixGroup(
                $siteId,
                $targetUserId,
                self::normalizeRole($role),
                $actorUserId
            );
        }

        if (empty($result['ok']) && empty($result['skipped'])) {
            $errorCode = strtoupper(trim((string)($result['error'] ?? 'GROUP_MEMBER_RECONCILE_FAILED')));
            if (!preg_match('/^[A-Z][A-Z0-9_]{2,119}$/', $errorCode)) {
                $errorCode = 'GROUP_MEMBER_RECONCILE_FAILED';
            }
            throw new RuntimeException($errorCode);
        }

        return $result;
    }

    protected static function normalizeRole(string $role): string
    {
        $role = strtoupper(trim($role));

        $allowed = [
            'VIEWER',
            'EDITOR',
            'ADMIN',
            'OWNER',
        ];

        if (!in_array($role, $allowed, true)) {
            throw new RuntimeException('INVALID_ROLE');
        }

        return $role;
    }

    protected static function extractUserId(string $accessCode): int
    {
        if (preg_match('/^U(\d+)$/', $accessCode, $m)) {
            return (int)$m[1];
        }

        return 0;
    }

    protected static function getUserName(int $userId): string
    {
        if ($userId <= 0 || !class_exists('CUser')) {
            return '';
        }

        $rs = \CUser::GetByID($userId);
        $row = $rs ? $rs->Fetch() : null;

        if (!$row) {
            return 'Пользователь #' . $userId;
        }

        $name = trim(
            (string)($row['LAST_NAME'] ?? '') . ' ' .
            (string)($row['NAME'] ?? '') . ' ' .
            (string)($row['SECOND_NAME'] ?? '')
        );

        if ($name === '') {
            $name = (string)($row['LOGIN'] ?? '');
        }

        if ($name === '') {
            $name = (string)($row['EMAIL'] ?? '');
        }

        return $name !== '' ? $name : ('Пользователь #' . $userId);
    }

    protected static function getSiteBitrixGroupId(int $siteId): int
    {
        $site = function_exists('sb_find_site') ? sb_find_site($siteId) : null;

        if (is_array($site)) {
            $groupId = (int)(
                $site['bitrixGroupId']
                ?? $site['bitrix_group_id']
                ?? 0
            );

            if ($groupId > 0) {
                return $groupId;
            }
        }

        if (function_exists('sb_db')) {
            $pdo = sb_db();

            $st = $pdo->prepare("
                SELECT bitrix_group_id
                FROM sitebuilder.site
                WHERE id = :site_id
                LIMIT 1
            ");

            $st->execute([
                ':site_id' => $siteId,
            ]);

            $row = $st->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return (int)($row['bitrix_group_id'] ?? 0);
            }
        }

        return 0;
    }

    protected static function syncUserToBitrixGroup(int $siteId, int $targetUserId, string $role, int $currentUserId): array
    {
        $groupId = self::getSiteBitrixGroupId($siteId);

        if ($groupId <= 0) {
            return [
                'ok' => false,
                'skipped' => true,
                'message' => 'SITE_HAS_NO_BITRIX_GROUP',
            ];
        }

        $sonetRole = self::mapSiteRoleToSonetRole($role);

        if ($sonetRole === '') {
            return [
                'ok' => true,
                'skipped' => true,
                'message' => 'OWNER_ROLE_IS_SITEBUILDER_ONLY',
            ];
        }

        try {
            if (!Loader::includeModule('socialnetwork')) {
                throw new RuntimeException('SOCIALNETWORK_MODULE_NOT_INSTALLED');
            }

            if (!class_exists('CSocNetUserToGroup')) {
                throw new RuntimeException('CSocNetUserToGroup_NOT_FOUND');
            }

            $membership = self::findGroupMembership($groupId, $targetUserId);

            if ($membership) {
                $membershipId = (int)$membership['ID'];
                $currentRole = (string)($membership['ROLE'] ?? '');

                if ($currentRole === $sonetRole) {
                    return [
                        'ok' => true,
                        'skipped' => false,
                        'action' => 'already_exists',
                        'groupId' => $groupId,
                        'sonetRole' => $sonetRole,
                    ];
                }

                $updated = \CSocNetUserToGroup::Update($membershipId, [
                    'ROLE' => $sonetRole,
                ]);

                if (!$updated) {
                    throw new RuntimeException('BITRIX_GROUP_MEMBER_UPDATE_ERROR');
                }

                return [
                    'ok' => true,
                    'skipped' => false,
                    'action' => 'updated',
                    'groupId' => $groupId,
                    'sonetRole' => $sonetRole,
                ];
            }

            $id = \CSocNetUserToGroup::Add([
                'USER_ID' => $targetUserId,
                'GROUP_ID' => $groupId,
                'ROLE' => $sonetRole,
                'INITIATED_BY_TYPE' => defined('SONET_INITIATED_BY_GROUP') ? SONET_INITIATED_BY_GROUP : 'G',
                'INITIATED_BY_USER_ID' => $currentUserId,
                'MESSAGE' => '',
                'SEND_MAIL' => 'N',
            ]);

            if (!$id) {
                throw new RuntimeException('BITRIX_GROUP_MEMBER_ADD_ERROR');
            }

            return [
                'ok' => true,
                'skipped' => false,
                'action' => 'created',
                'groupId' => $groupId,
                'sonetRole' => $sonetRole,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'skipped' => false,
                'groupId' => $groupId,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected static function removeUserFromBitrixGroup(int $siteId, int $targetUserId, int $currentUserId): array
    {
        $groupId = self::getSiteBitrixGroupId($siteId);

        if ($groupId <= 0) {
            return [
                'ok' => false,
                'skipped' => true,
                'message' => 'SITE_HAS_NO_BITRIX_GROUP',
            ];
        }

        try {
            if (!Loader::includeModule('socialnetwork')) {
                throw new RuntimeException('SOCIALNETWORK_MODULE_NOT_INSTALLED');
            }

            if (!class_exists('CSocNetUserToGroup')) {
                throw new RuntimeException('CSocNetUserToGroup_NOT_FOUND');
            }

            $membership = self::findGroupMembership($groupId, $targetUserId);

            if (!$membership) {
                return [
                    'ok' => true,
                    'skipped' => true,
                    'message' => 'USER_NOT_IN_GROUP',
                    'groupId' => $groupId,
                ];
            }

            $membershipId = (int)$membership['ID'];
            $sonetRole = (string)($membership['ROLE'] ?? '');

            $ownerRole = defined('SONET_ROLES_OWNER') ? SONET_ROLES_OWNER : 'A';

            if ($sonetRole === $ownerRole || $sonetRole === 'A') {
                return [
                    'ok' => false,
                    'skipped' => true,
                    'message' => 'BITRIX_GROUP_OWNER_NOT_REMOVED',
                    'groupId' => $groupId,
                ];
            }

            $deleted = \CSocNetUserToGroup::Delete($membershipId);

            if (!$deleted) {
                throw new RuntimeException('BITRIX_GROUP_MEMBER_DELETE_ERROR');
            }

            return [
                'ok' => true,
                'skipped' => false,
                'action' => 'deleted',
                'groupId' => $groupId,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'skipped' => false,
                'groupId' => $groupId,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected static function findGroupMembership(int $groupId, int $userId): ?array
    {
        $rs = \CSocNetUserToGroup::GetList(
            ['ID' => 'ASC'],
            [
                'GROUP_ID' => $groupId,
                'USER_ID' => $userId,
            ],
            false,
            false,
            [
                'ID',
                'USER_ID',
                'GROUP_ID',
                'ROLE',
            ]
        );

        if ($row = $rs->Fetch()) {
            return $row;
        }

        return null;
    }

    protected static function mapSiteRoleToSonetRole(string $role): string
    {
        $role = strtoupper(trim($role));

        $userRole = defined('SONET_ROLES_USER') ? SONET_ROLES_USER : 'K';
        $moderatorRole = defined('SONET_ROLES_MODERATOR') ? SONET_ROLES_MODERATOR : 'E';

        if ($role === 'VIEWER') {
            return $userRole;
        }

        if ($role === 'EDITOR' || $role === 'ADMIN') {
            return $moderatorRole;
        }

        /*
         * OWNER в SiteBuilder пока не делаем владельцем группы,
         * чтобы случайно не сменить владельца рабочей группы Битрикс24.
         */
        if ($role === 'OWNER') {
            return '';
        }

        return '';
    }
}