<?php

class SiteRepository
{
    public static function getById(int $siteId): ?array
    {
        if ($siteId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                s.id,
                s.name,
                s.code,
                s.root_disk_folder_id,
                s.settings_json,
                s.created_at,
                s.updated_at
            FROM sitebuilder.sitebuilder_site s
            WHERE s.id = :id
            LIMIT 1
        ";

        return DiskDb::fetchOne($sql, [
            ':id' => $siteId,
        ]);
    }

    public static function getRootDiskFolderId(int $siteId): ?int
    {
        $row = self::getById($siteId);
        if (!$row) {
            return null;
        }

        return !empty($row['root_disk_folder_id'])
            ? (int)$row['root_disk_folder_id']
            : null;
    }

    public static function updateRootDiskFolderId(int $siteId, ?int $folderId): bool
    {
        $sql = "
            UPDATE sitebuilder.sitebuilder_site
            SET root_disk_folder_id = :root_disk_folder_id,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ";

        return DiskDb::execute($sql, [
            ':id' => $siteId,
            ':root_disk_folder_id' => $folderId,
        ]);
    }
}