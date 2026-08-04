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

    public static function assertFileInsideRoot(
        int $fileId,
        ?int $rootFolderId,
        ?DiskContext $context = null
    ): void {
        if ($fileId <= 0) {
            throw new RuntimeException('INVALID_FILE_ID');
        }

        $file = \Bitrix\Disk\File::loadById($fileId);

        if (!$file instanceof \Bitrix\Disk\File) {
            throw new RuntimeException('DISK_FILE_NOT_FOUND');
        }

        self::assertFolderInsideRoot(
            (int)$file->getParentId(),
            $rootFolderId,
            $context
        );
    }

    public static function assertItemInsideRoot(
        string $entityType,
        int $entityId,
        ?int $rootFolderId,
        ?DiskContext $context = null,
        bool $allowRootFolder = false
    ): void {
        if ($entityId <= 0) {
            throw new RuntimeException('INVALID_ENTITY_ID');
        }

        if ($entityType === 'file') {
            self::assertFileInsideRoot($entityId, $rootFolderId, $context);
            return;
        }

        if ($entityType === 'folder') {
            if (!$allowRootFolder && $entityId === (int)$rootFolderId) {
                throw new RuntimeException('ROOT_FOLDER_OPERATION_FORBIDDEN');
            }

            self::assertFolderInsideRoot($entityId, $rootFolderId, $context);
            return;
        }

        throw new RuntimeException('INVALID_ENTITY_TYPE');
    }

    public static function assertItemsInsideRoot(
        array $items,
        ?int $rootFolderId,
        ?DiskContext $context = null,
        bool $allowRootFolder = false
    ): void {
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('INVALID_ITEM');
            }

            self::assertItemInsideRoot(
                trim((string)($item['entityType'] ?? '')),
                (int)($item['id'] ?? 0),
                $rootFolderId,
                $context,
                $allowRootFolder
            );
        }
    }

    public static function assertCan(array $permissions, string $permissionKey): void
    {
        if (empty($permissions[$permissionKey])) {
            throw new RuntimeException('ACCESS_DENIED');
        }
    }

    public static function permissionsForFolder(
        DiskContext $context,
        array $settings,
        int $folderId,
        int $rootFolderId
    ): array {
        self::assertFolderInsideRoot($folderId, $rootFolderId, $context);

        return DiskPermissionService::resolve(
            $context,
            $settings,
            $folderId,
            $rootFolderId
        );
    }

    public static function assertCanForFolder(
        DiskContext $context,
        array $settings,
        int $folderId,
        int $rootFolderId,
        string $permissionKey
    ): array {
        $permissions = self::permissionsForFolder(
            $context,
            $settings,
            $folderId,
            $rootFolderId
        );

        self::assertCan($permissions, $permissionKey);
        return $permissions;
    }

    public static function itemParentFolderId(string $entityType, int $entityId): int
    {
        if ($entityType === 'file') {
            $item = \Bitrix\Disk\File::loadById($entityId);
        } elseif ($entityType === 'folder') {
            $item = \Bitrix\Disk\Folder::loadById($entityId);
        } else {
            throw new RuntimeException('INVALID_ENTITY_TYPE');
        }

        if (!$item) {
            throw new RuntimeException('DISK_ITEM_NOT_FOUND');
        }

        $parentId = (int)$item->getParentId();
        if ($parentId <= 0) {
            throw new RuntimeException('ITEM_PARENT_FOLDER_NOT_FOUND');
        }

        return $parentId;
    }

    public static function assertCanForItemParent(
        DiskContext $context,
        array $settings,
        string $entityType,
        int $entityId,
        int $rootFolderId,
        string $permissionKey
    ): void {
        self::assertItemInsideRoot($entityType, $entityId, $rootFolderId, $context);
        $parentId = self::itemParentFolderId($entityType, $entityId);
        self::assertCanForFolder(
            $context,
            $settings,
            $parentId,
            $rootFolderId,
            $permissionKey
        );
    }

    public static function assertCanForItemParents(
        DiskContext $context,
        array $settings,
        array $items,
        int $rootFolderId,
        string $permissionKey
    ): void {
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('INVALID_ITEM');
            }

            self::assertCanForItemParent(
                $context,
                $settings,
                trim((string)($item['entityType'] ?? '')),
                (int)($item['id'] ?? 0),
                $rootFolderId,
                $permissionKey
            );
        }
    }

    public static function filterVisibleItems(
        DiskContext $context,
        array $settings,
        array $items,
        int $currentFolderId,
        int $rootFolderId
    ): array {
        $result = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $folderId = (string)($item['entityType'] ?? '') === 'folder'
                ? (int)($item['id'] ?? 0)
                : $currentFolderId;

            if ($folderId <= 0) {
                continue;
            }

            $permissions = self::permissionsForFolder(
                $context,
                $settings,
                $folderId,
                $rootFolderId
            );

            if (!empty($permissions['canView'])) {
                $result[] = $item;
            }
        }

        return $result;
    }

    public static function assertNonEmptyString(string $value, string $errorCode): void
    {
        if (trim($value) === '') {
            throw new RuntimeException($errorCode);
        }
    }
}
