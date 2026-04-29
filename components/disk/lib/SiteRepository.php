<?php

class SiteRepository
{
    public static function getById(int $siteId): ?array
    {
        return DiskSitebuilderBridge::getSiteById($siteId);
    }

    public static function getRootDiskFolderId(int $siteId): ?int
    {
        $site = self::getById($siteId);
        if (!$site) {
            return null;
        }

        return !empty($site['diskFolderId']) ? (int)$site['diskFolderId'] : null;
    }

    public static function updateRootDiskFolderId(int $siteId, ?int $folderId): bool
    {
        if (!$folderId || $folderId <= 0) {
            throw new RuntimeException('INVALID_FOLDER_ID');
        }

        return DiskSitebuilderBridge::updateSiteDiskFolderId($siteId, (int)$folderId);
    }
}