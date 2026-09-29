<?php

use Bitrix\Disk\Folder;

final class FolderAccessRepository
{
    public const ROLE_DENY = 'DENY';
    public const ROLE_VIEWER = 'VIEWER';
    public const ROLE_EDITOR = 'EDITOR';

    private static array $directRuleCache = [];
    private static array $effectiveRuleCache = [];

    public static function listForFolder(int $siteId, int $blockId, int $folderId): array
    {
        if ($siteId <= 0 || $blockId <= 0 || $folderId <= 0) {
            return [];
        }

        return DiskDb::fetchAll(
            "SELECT id, site_id, block_id, folder_id, access_code, role,
                    created_by, created_at, updated_by, updated_at
             FROM sitebuilder.disk_folder_access
             WHERE site_id = :site_id
               AND block_id = :block_id
               AND folder_id = :folder_id
             ORDER BY access_code",
            [
                ':site_id' => $siteId,
                ':block_id' => $blockId,
                ':folder_id' => $folderId,
            ]
        );
    }

    public static function setUserRole(
        int $siteId,
        int $blockId,
        int $folderId,
        int $userId,
        string $role,
        int $actorUserId
    ): array {
        self::assertRole($role);

        if ($siteId <= 0 || $blockId <= 0 || $folderId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('INVALID_FOLDER_ACCESS_TARGET');
        }

        return self::withFolderLock($blockId, $folderId, static function () use ($siteId, $blockId, $folderId, $userId, $role, $actorUserId): array {
            $row = DiskDb::fetchOne(
                "INSERT INTO sitebuilder.disk_folder_access (
                    site_id, block_id, folder_id, access_code, role,
                    created_by, created_at, updated_by, updated_at
                 ) VALUES (
                    :site_id, :block_id, :folder_id, :access_code, :role,
                    :actor_user_id, NOW(), :actor_user_id, NOW()
                 )
                 ON CONFLICT (block_id, folder_id, access_code)
                 DO UPDATE SET
                    role = EXCLUDED.role,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = NOW()
                 RETURNING id, site_id, block_id, folder_id, access_code, role,
                           created_by, created_at, updated_by, updated_at",
                [
                    ':site_id' => $siteId,
                    ':block_id' => $blockId,
                    ':folder_id' => $folderId,
                    ':access_code' => 'U' . $userId,
                    ':role' => $role,
                    ':actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
                ]
            );

            if (!$row) {
                throw new RuntimeException('FOLDER_ACCESS_SAVE_FAILED');
            }

            self::clearCache();
            return $row;
        });
    }

    public static function deleteUserRole(
        int $siteId,
        int $blockId,
        int $folderId,
        int $userId
    ): bool {
        if ($siteId <= 0 || $blockId <= 0 || $folderId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('INVALID_FOLDER_ACCESS_TARGET');
        }

        return self::withFolderLock($blockId, $folderId, static function () use ($siteId, $blockId, $folderId, $userId): bool {
            $deleted = DiskDb::execute(
                "DELETE FROM sitebuilder.disk_folder_access
                 WHERE site_id = :site_id
                   AND block_id = :block_id
                   AND folder_id = :folder_id
                   AND access_code = :access_code",
                [
                    ':site_id' => $siteId,
                    ':block_id' => $blockId,
                    ':folder_id' => $folderId,
                    ':access_code' => 'U' . $userId,
                ]
            );
            self::clearCache();
            return $deleted;
        });
    }

    public static function revision(array $rows): string
    {
        $rules = array_map(static fn($row) => [
            (string)$row['access_code'], (string)$row['role'], (string)$row['updated_at'],
        ], $rows);
        sort($rules);
        return hash('sha256', json_encode($rules, JSON_UNESCAPED_UNICODE));
    }

    public static function setUserRoles(
        int $siteId, int $blockId, int $folderId, array $userIds,
        string $role, int $actorUserId, string $expectedRevision
    ): array {
        if ($role !== 'INHERIT') {
            self::assertRole($role);
        }
        if ($siteId <= 0 || $blockId <= 0 || $folderId <= 0 || $actorUserId <= 0
            || !$userIds || count($userIds) > 200 || $expectedRevision === '') {
            throw new InvalidArgumentException('INVALID_FOLDER_ACCESS_TARGET');
        }
        return self::withFolderLock($blockId, $folderId, static function () use (
            $siteId, $blockId, $folderId, $userIds, $role, $actorUserId, $expectedRevision
        ): array {
            $current = self::listForFolder($siteId, $blockId, $folderId);
            if (!hash_equals(self::revision($current), $expectedRevision)) {
                throw new RuntimeException('FOLDER_ACCESS_VERSION_CONFLICT');
            }
            foreach ($userIds as $userId) {
                if ($role === 'INHERIT') {
                    self::deleteUserRole($siteId, $blockId, $folderId, $userId);
                } else {
                    self::setUserRole($siteId, $blockId, $folderId, $userId, $role, $actorUserId);
                }
            }
            $updated = self::listForFolder($siteId, $blockId, $folderId);
            return ['count' => count($userIds), 'revision' => self::revision($updated)];
        });
    }

    /** PostgreSQL lock shared by individual and bulk writes, across portal nodes. */
    private static function withFolderLock(int $blockId, int $folderId, callable $operation)
    {
        $pdo = DiskDb::getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            DiskDb::fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', [
                ':key' => 'sitebuilder.folder_access:' . $blockId . ':' . $folderId,
            ]);
            $result = $operation();
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            self::clearCache();
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function clearCache(): void
    {
        self::$directRuleCache = [];
        self::$effectiveRuleCache = [];
    }

    /**
     * Возвращает ближайшее правило пользователя от текущей папки к корню блока.
     * Правило дочерней папки перекрывает правило родителя.
     */
    public static function resolveEffectiveRole(
        int $blockId,
        int $folderId,
        int $rootFolderId,
        int $userId
    ): ?array {
        if ($blockId <= 0 || $folderId <= 0 || $rootFolderId <= 0 || $userId <= 0) {
            return null;
        }

        $effectiveKey = implode(':', [$blockId, $folderId, $rootFolderId, $userId]);
        if (array_key_exists($effectiveKey, self::$effectiveRuleCache)) {
            return self::$effectiveRuleCache[$effectiveKey];
        }

        $currentFolderId = $folderId;
        $visited = [];

        while ($currentFolderId > 0 && !isset($visited[$currentFolderId])) {
            $visited[$currentFolderId] = true;

            $directKey = implode(':', [$blockId, $currentFolderId, $userId]);
            if (!array_key_exists($directKey, self::$directRuleCache)) {
                self::$directRuleCache[$directKey] = DiskDb::fetchOne(
                    "SELECT folder_id, role
                     FROM sitebuilder.disk_folder_access
                     WHERE block_id = :block_id
                       AND folder_id = :folder_id
                       AND access_code = :access_code
                     LIMIT 1",
                    [
                        ':block_id' => $blockId,
                        ':folder_id' => $currentFolderId,
                        ':access_code' => 'U' . $userId,
                    ]
                );
            }

            $row = self::$directRuleCache[$directKey];

            if ($row) {
                return self::$effectiveRuleCache[$effectiveKey] = [
                    'folderId' => (int)$row['folder_id'],
                    'role' => (string)$row['role'],
                    'inherited' => (int)$row['folder_id'] !== $folderId,
                ];
            }

            if ($currentFolderId === $rootFolderId) {
                break;
            }

            $folder = Folder::loadById($currentFolderId);
            if (!$folder instanceof Folder) {
                break;
            }

            $parentId = (int)$folder->getParentId();
            if ($parentId <= 0) {
                break;
            }

            $currentFolderId = $parentId;
        }

        self::$effectiveRuleCache[$effectiveKey] = null;
        return null;
    }

    public static function userIdFromAccessCode(string $accessCode): int
    {
        return preg_match('/^U(\d+)$/', $accessCode, $matches)
            ? (int)$matches[1]
            : 0;
    }

    private static function assertRole(string $role): void
    {
        if (!in_array($role, [self::ROLE_DENY, self::ROLE_VIEWER, self::ROLE_EDITOR], true)) {
            throw new InvalidArgumentException('INVALID_FOLDER_ACCESS_ROLE');
        }
    }
}
