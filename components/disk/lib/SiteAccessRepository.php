<?php

class SiteAccessRepository
{
    public static function getUserRole(int $siteId, int $userId): ?string
    {
        if ($siteId <= 0 || $userId <= 0) {
            return null;
        }

        $sql = "
            SELECT role_code
            FROM sitebuilder.sitebuilder_site_user_access
            WHERE site_id = :site_id
              AND user_id = :user_id
            LIMIT 1
        ";

        $row = DiskDb::fetchOne($sql, [
            ':site_id' => $siteId,
            ':user_id' => $userId,
        ]);

        return $row ? (string)$row['role_code'] : null;
    }

    public static function setUserRole(int $siteId, int $userId, string $roleCode): bool
    {
        $roleCode = trim($roleCode);

        if (!in_array($roleCode, ['site_admin', 'site_editor', 'site_user', 'site_viewer'], true)) {
            throw new RuntimeException('INVALID_ROLE_CODE');
        }

        $existing = self::getUserRole($siteId, $userId);

        if ($existing === null) {
            $sql = "
                INSERT INTO sitebuilder.sitebuilder_site_user_access (
                    site_id,
                    user_id,
                    role_code,
                    created_at,
                    updated_at
                ) VALUES (
                    :site_id,
                    :user_id,
                    :role_code,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
            ";

            return DiskDb::execute($sql, [
                ':site_id' => $siteId,
                ':user_id' => $userId,
                ':role_code' => $roleCode,
            ]);
        }

        $sql = "
            UPDATE sitebuilder.sitebuilder_site_user_access
            SET role_code = :role_code,
                updated_at = CURRENT_TIMESTAMP
            WHERE site_id = :site_id
              AND user_id = :user_id
        ";

        return DiskDb::execute($sql, [
            ':site_id' => $siteId,
            ':user_id' => $userId,
            ':role_code' => $roleCode,
        ]);
    }

    public static function hasAnyAccess(int $siteId, int $userId): bool
    {
        return self::getUserRole($siteId, $userId) !== null;
    }
}