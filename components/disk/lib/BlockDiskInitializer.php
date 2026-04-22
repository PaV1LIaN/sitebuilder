<?php

use Bitrix\Disk\Folder;
use Bitrix\Disk\Driver;

class BlockDiskInitializer
{
    public static function ensureBlockRootFolder(
        int $siteId,
        int $pageId,
        int $blockId,
        int $currentUserId,
        string $blockTitle = ''
    ): int {
        $settings = DiskSettingsRepository::ensureExistsForBlock($blockId, $siteId, $pageId, $currentUserId);

        if (!empty($settings['rootFolderId'])) {
            return (int)$settings['rootFolderId'];
        }

        $site = SiteRepository::getById($siteId);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $siteRootFolderId = SiteDiskInitializer::ensureSiteRootFolder(
            $siteId,
            $currentUserId,
            (string)$site['name']
        );

        $siteRootFolder = Folder::loadById($siteRootFolderId);
        if (!$siteRootFolder instanceof Folder) {
            throw new RuntimeException('SITE_ROOT_FOLDER_NOT_FOUND');
        }

        $folderName = $blockTitle !== ''
            ? ('Блок: ' . $blockTitle)
            : ('Блок #' . $blockId);

        $created = $siteRootFolder->addSubFolder([
            'NAME' => $folderName,
            'CREATED_BY' => $currentUserId,
        ], Driver::getInstance()->getFakeSecurityContext($currentUserId));

        if (!$created instanceof Folder) {
            throw new RuntimeException('BLOCK_ROOT_CREATE_ERROR');
        }

        DiskSettingsRepository::save($blockId, [
            'rootFolderId' => (int)$created->getId(),
            'useSiteRootFallback' => true,
        ]);

        return (int)$created->getId();
    }
}