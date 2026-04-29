<?php

use Bitrix\Disk\Folder;

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

        $folderBaseName = $blockTitle !== ''
            ? ('Блок ' . $blockTitle)
            : ('Блок ' . $blockId);

        $folderName = DiskNameSanitizer::sanitizeFolderName($folderBaseName, 'Блок');

            $created = $siteRootFolder->addSubFolder([
                'NAME' => $folderName,
                'CREATED_BY' => $currentUserId,
            ], [], true);
            
            if (!$created instanceof Folder) {
                $errors = [];
            
                if (is_object($siteRootFolder) && method_exists($siteRootFolder, 'getErrors')) {
                    foreach ((array)$siteRootFolder->getErrors() as $error) {
                        if (is_object($error) && method_exists($error, 'getMessage')) {
                            $errors[] = $error->getMessage();
                        } else {
                            $errors[] = (string)$error;
                        }
                    }
                }
            
                throw new RuntimeException(
                    'BLOCK_ROOT_CREATE_ERROR' . (!empty($errors) ? ': ' . implode(' | ', $errors) : '')
                );
            }

        DiskSettingsRepository::save($blockId, [
            'rootMode' => 'block',
            'rootFolderId' => (int)$created->getId(),
            'useSiteRootFallback' => true,
        ]);

        return (int)$created->getId();
    }
}