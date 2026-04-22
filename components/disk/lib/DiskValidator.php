<?php

class DiskValidator
{
    public static function assertContext(DiskContext $context): void
    {
        if ($context->siteId <= 0) {
            throw new RuntimeException('INVALID_SITE_ID');
        }

        if ($context->pageId <= 0) {
            throw new RuntimeException('INVALID_PAGE_ID');
        }

        if ($context->blockId <= 0) {
            throw new RuntimeException('INVALID_BLOCK_ID');
        }

        if ($context->currentUserId <= 0) {
            throw new RuntimeException('NOT_AUTHORIZED');
        }

        self::assertSiteExists($context->siteId);
        self::assertBlockBelongsToContext($context);
    }

    public static function assertSiteExists(int $siteId): void
    {
        $site = SiteRepository::getById($siteId);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }
    }

    public static function assertBlockBelongsToContext(DiskContext $context): void
    {
        $block = BlockRepository::getDiskBlockByContext(
            $context->siteId,
            $context->pageId,
            $context->blockId
        );

        if (!$block) {
            throw new RuntimeException('BLOCK_CONTEXT_MISMATCH');
        }
    }

    public static function assertFolderInsideRoot(int $folderId, ?int $rootFolderId, ?DiskContext $context = null): void
    {
        if ($folderId <= 0) {
            throw new RuntimeException('INVALID_FOLDER_ID');
        }

        if ($rootFolderId === null || $rootFolderId <= 0) {
            throw new RuntimeException('ROOT_FOLDER_NOT_RESOLVED');
        }

        if ($folderId === $rootFolderId) {
            return;
        }

        if (!$context instanceof DiskContext) {
            throw new RuntimeException('DISK_CONTEXT_REQUIRED');
        }

        $adapter = new DiskBitrixStorageAdapter($context->currentUserId);
        $ok = $adapter->isFolderInsideRoot($context, $folderId, $rootFolderId);

        if (!$ok) {
            throw new RuntimeException('FOLDER_OUT_OF_SCOPE');
        }
    }

    public static function assertCan(array $permissions, string $permissionKey): void
    {
        if (empty($permissions[$permissionKey])) {
            throw new RuntimeException('ACCESS_DENIED');
        }
    }

    public static function assertNonEmptyString(string $value, string $errorCode): void
    {
        if (trim($value) === '') {
            throw new RuntimeException($errorCode);
        }
    }
}