<?php

class SiteAccessRepository
{
    public static function getUserRole(int $siteId, int $userId): ?string
    {
        if ($siteId <= 0 || $userId <= 0) {
            return null;
        }

        $accessCodes = self::buildAccessCodes($userId);
        if (empty($accessCodes)) {
            return null;
        }

        $placeholders = [];
        $params = [
            ':site_id' => $siteId,
        ];

        foreach ($accessCodes as $index => $code) {
            $key = ':code_' . $index;
            $placeholders[] = $key;
            $params[$key] = $code;
        }

        $sql = "
            SELECT access_code, role
            FROM sitebuilder.access
            WHERE site_id = :site_id
              AND access_code IN (" . implode(', ', $placeholders) . ")
        ";

        $rows = DiskDb::fetchAll($sql, $params);
        if (empty($rows)) {
            return null;
        }

        $bestRole = null;
        $bestRank = -1;

        foreach ($rows as $row) {
            $roleCode = strtoupper(trim((string)($row['role'] ?? '')));
            $mappedRole = self::mapSitebuilderRoleToDiskRole($roleCode);

            if ($mappedRole === null) {
                continue;
            }

            $rank = self::diskRoleRank($mappedRole);
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $bestRole = $mappedRole;
            }
        }

        return $bestRole;
    }

    public static function setUserRole(int $siteId, int $userId, string $roleCode): bool
    {
        if ($siteId <= 0 || $userId <= 0) {
            throw new RuntimeException('INVALID_SITE_OR_USER');
        }

        $accessCode = 'U' . $userId;
        $sitebuilderRole = self::mapDiskRoleToSitebuilderRole($roleCode);

        if ($sitebuilderRole === null) {
            throw new RuntimeException('INVALID_ROLE_CODE');
        }

        $sql = "
            INSERT INTO sitebuilder.access (
                site_id,
                access_code,
                role,
                created_at,
                updated_at
            ) VALUES (
                :site_id,
                :access_code,
                :role,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
            ON CONFLICT (site_id, access_code)
            DO UPDATE SET
                role = EXCLUDED.role,
                updated_at = CURRENT_TIMESTAMP
        ";

        return DiskDb::execute($sql, [
            ':site_id' => $siteId,
            ':access_code' => $accessCode,
            ':role' => $sitebuilderRole,
        ]);
    }

    public static function hasAnyAccess(int $siteId, int $userId): bool
    {
        return self::getUserRole($siteId, $userId) !== null;
    }

    protected static function buildAccessCodes(int $userId): array
    {
        $codes = ['U' . $userId];

        if (DiskCurrentUser::isAdmin()) {
            $codes[] = 'AU';
            $codes[] = 'ADMIN';
        }

        foreach (DiskCurrentUser::getGroupIds() as $groupId) {
            if ($groupId > 0) {
                $codes[] = 'G' . (int)$groupId;
            }
        }

        return array_values(array_unique($codes));
    }

    protected static function mapSitebuilderRoleToDiskRole(string $roleCode): ?string
    {
        switch ($roleCode) {
            case 'OWNER':
            case 'ADMIN':
                return 'site_admin';

            case 'EDITOR':
                return 'site_editor';

            case 'USER':
            case 'MEMBER':
                return 'site_user';

            case 'VIEWER':
            case 'READER':
                return 'site_viewer';

            default:
                return null;
        }
    }

    protected static function mapDiskRoleToSitebuilderRole(string $roleCode): ?string
    {
        switch ($roleCode) {
            case 'site_admin':
                return 'OWNER';

            case 'site_editor':
                return 'EDITOR';

            case 'site_user':
                return 'USER';

            case 'site_viewer':
                return 'VIEWER';

            default:
                return null;
        }
    }

    protected static function diskRoleRank(string $roleCode): int
    {
        switch ($roleCode) {
            case 'site_admin':
                return 4;

            case 'site_editor':
                return 3;

            case 'site_user':
                return 2;

            case 'site_viewer':
                return 1;

            default:
                return 0;
        }
    }
}