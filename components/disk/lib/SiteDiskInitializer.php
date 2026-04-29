<?php

use Bitrix\Disk\Driver;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Storage;
use Bitrix\Main\Loader;

class SiteDiskInitializer
{
    /**
     * Fallback: ID папки в "Общий диск".
     * Если у сайта нет группы Битрикс24 или диск группы не найден,
     * папка сайта будет создана здесь.
     */
    protected const SHARED_ROOT_FOLDER_ID = 250;

    /**
     * Папка внутри диска группы Битрикс24.
     *
     * Структура будет:
     * Группа Битрикс24
     * └── Диск группы
     *     └── SiteBuilder
     *         └── site_11 - Название сайта
     */
    protected const GROUP_ROOT_FOLDER_NAME = 'SiteBuilder';

    public static function ensureSiteRootFolder(int $siteId, int $currentUserId, string $siteName = ''): int
    {
        if ($siteId <= 0) {
            throw new RuntimeException('EMPTY_SITE_ID');
        }

        if ($currentUserId <= 0) {
            throw new RuntimeException('EMPTY_CURRENT_USER_ID');
        }

        $existing = SiteRepository::getRootDiskFolderId($siteId);

        if ($existing !== null && $existing > 0) {
            $existingFolder = Folder::loadById((int)$existing);

            if ($existingFolder instanceof Folder) {
                return (int)$existingFolder->getId();
            }
        }

        $site = SiteRepository::getById($siteId);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $resolvedSiteName = trim($siteName);
        if ($resolvedSiteName === '') {
            $resolvedSiteName = trim((string)($site['name'] ?? ''));
        }

        $bitrixGroupId = self::extractBitrixGroupId($site);

        $parentFolder = null;
        $groupDiskError = '';

        if ($bitrixGroupId > 0) {
            try {
                $parentFolder = self::getOrCreateGroupSiteBuilderFolder(
                    $bitrixGroupId,
                    $currentUserId
                );
            } catch (Throwable $e) {
                $groupDiskError = $e->getMessage();
                $parentFolder = null;
            }
        }

        if (!$parentFolder instanceof Folder) {
            $parentFolder = self::getSharedRootFolder();

            if (!$parentFolder instanceof Folder) {
                throw new RuntimeException(
                    'SHARED_DISK_ROOT_FOLDER_NOT_FOUND'
                    . ($groupDiskError !== '' ? '; GROUP_DISK_ERROR: ' . $groupDiskError : '')
                );
            }
        }

        $siteFolderName = self::buildSiteFolderName($siteId, $resolvedSiteName);

        $siteFolder = self::getOrCreateChildFolder(
            $parentFolder,
            $siteFolderName,
            $currentUserId
        );

        SiteRepository::updateRootDiskFolderId($siteId, (int)$siteFolder->getId());

        return (int)$siteFolder->getId();
    }

    protected static function extractBitrixGroupId(array $site): int
    {
        return (int)(
            $site['bitrixGroupId']
            ?? $site['bitrix_group_id']
            ?? $site['BITRIX_GROUP_ID']
            ?? 0
        );
    }

    protected static function buildSiteFolderName(int $siteId, string $siteName): string
    {
        $siteName = trim($siteName);

        if ($siteName === '') {
            $siteName = 'site_' . $siteId;
        }

        $name = 'site_' . $siteId . ' - ' . $siteName;

        return DiskNameSanitizer::sanitizeFolderName($name, 'site_' . $siteId);
    }

    protected static function getOrCreateGroupSiteBuilderFolder(int $groupId, int $currentUserId): Folder
    {
        if ($groupId <= 0) {
            throw new RuntimeException('EMPTY_BITRIX_GROUP_ID');
        }

        $storage = self::getGroupStorage($groupId);

        if (!$storage instanceof Storage) {
            throw new RuntimeException('GROUP_DISK_STORAGE_NOT_FOUND: ' . $groupId);
        }

        $rootFolder = $storage->getRootObject();

        if (!$rootFolder instanceof Folder) {
            throw new RuntimeException('GROUP_DISK_ROOT_FOLDER_NOT_FOUND: ' . $groupId);
        }

        $folderName = DiskNameSanitizer::sanitizeFolderName(
            self::GROUP_ROOT_FOLDER_NAME,
            'SiteBuilder'
        );

        return self::getOrCreateChildFolder($rootFolder, $folderName, $currentUserId);
    }

    protected static function getGroupStorage(int $groupId): ?Storage
    {
        if ($groupId <= 0) {
            return null;
        }

        if (!Loader::includeModule('disk')) {
            throw new RuntimeException('DISK_MODULE_NOT_INSTALLED');
        }

        Loader::includeModule('socialnetwork');

        $driver = Driver::getInstance();

        if (is_object($driver) && method_exists($driver, 'getStorageByGroupId')) {
            $storage = $driver->getStorageByGroupId($groupId);

            if ($storage instanceof Storage) {
                return $storage;
            }
        }

        $storage = Storage::load([
            '=MODULE_ID' => 'socialnetwork',
            '=ENTITY_TYPE' => 'group',
            '=ENTITY_ID' => $groupId,
        ]);

        if ($storage instanceof Storage) {
            return $storage;
        }

        return null;
    }

    protected static function getSharedRootFolder(): Folder
    {
        $folderId = (int)self::SHARED_ROOT_FOLDER_ID;

        if ($folderId <= 0) {
            throw new RuntimeException('SHARED_ROOT_FOLDER_ID_NOT_CONFIGURED');
        }

        $folder = Folder::loadById($folderId);

        if (!$folder instanceof Folder) {
            throw new RuntimeException('SHARED_ROOT_FOLDER_NOT_FOUND: ' . $folderId);
        }

        return $folder;
    }

    protected static function getOrCreateChildFolder(Folder $parentFolder, string $folderName, int $currentUserId): Folder
    {
        $folderName = DiskNameSanitizer::sanitizeFolderName($folderName, 'Папка');

        $existingFolder = self::findChildFolderByName($parentFolder, $folderName, $currentUserId);

        if ($existingFolder instanceof Folder) {
            return $existingFolder;
        }

        $createdFolder = $parentFolder->addSubFolder([
            'NAME' => $folderName,
            'CREATED_BY' => $currentUserId,
        ], [], true);

        if ($createdFolder instanceof Folder) {
            return $createdFolder;
        }

        $errors = self::collectFolderErrors($parentFolder);

        throw new RuntimeException(
            'DISK_FOLDER_CREATE_ERROR'
            . (!empty($errors) ? ': ' . implode(' | ', $errors) : '')
        );
    }

    protected static function findChildFolderByName(Folder $parentFolder, string $folderName, int $currentUserId): ?Folder
    {
        $securityContext = Driver::getInstance()->getFakeSecurityContext($currentUserId);

        $children = $parentFolder->getChildren($securityContext);

        foreach ($children as $child) {
            if (!$child instanceof Folder) {
                continue;
            }

            if ((string)$child->getName() === $folderName) {
                return $child;
            }
        }

        return null;
    }

    protected static function collectFolderErrors(Folder $folder): array
    {
        $errors = [];

        if (!method_exists($folder, 'getErrors')) {
            return $errors;
        }

        foreach ((array)$folder->getErrors() as $error) {
            if (is_object($error) && method_exists($error, 'getMessage')) {
                $errors[] = $error->getMessage();
            } else {
                $errors[] = (string)$error;
            }
        }

        return $errors;
    }
}