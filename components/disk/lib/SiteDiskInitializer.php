<?php

use Bitrix\Disk\Driver;
use Bitrix\Disk\Storage;
use Bitrix\Disk\Folder;

class SiteDiskInitializer
{
    public static function ensureSiteRootFolder(int $siteId, int $currentUserId, string $siteName = ''): int
    {
        $existing = SiteRepository::getRootDiskFolderId($siteId);
        if ($existing !== null && $existing > 0) {
            return $existing;
        }

        $site = SiteRepository::getById($siteId);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $driver = Driver::getInstance();
        $securityContext = $driver->getFakeSecurityContext($currentUserId);

        $storage = self::resolveDefaultStorage($currentUserId);
        if (!$storage instanceof Storage) {
            throw new RuntimeException('DISK_STORAGE_NOT_FOUND');
        }

        $rootFolder = $storage->getRootObject();
        if (!$rootFolder instanceof Folder) {
            throw new RuntimeException('DISK_STORAGE_ROOT_NOT_FOUND');
        }

        $folderName = $siteName !== ''
            ? ('Сайт: ' . $siteName)
            : ('Сайт: ' . (string)$site['name']);

        $siteFolder = $rootFolder->addSubFolder([
            'NAME' => $folderName,
            'CREATED_BY' => $currentUserId,
        ], $securityContext);

        if (!$siteFolder instanceof Folder) {
            throw new RuntimeException('DISK_SITE_ROOT_CREATE_ERROR');
        }

        SiteRepository::updateRootDiskFolderId($siteId, (int)$siteFolder->getId());

        return (int)$siteFolder->getId();
    }

    protected static function resolveDefaultStorage(int $currentUserId): ?Storage
    {
        $driver = Driver::getInstance();

        $storage = $driver->getStorageByUserId($currentUserId);
        if ($storage instanceof Storage) {
            return $storage;
        }

        return null;
    }
}