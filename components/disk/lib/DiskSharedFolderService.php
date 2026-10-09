<?php

final class DiskSharedFolderService
{
    public static function buildView(DiskContext $context, int $folderId, int $currentFolderId): array
    {
        DiskValidator::assertContext($context);
        if (!PageAccessService::canViewPage($context->siteId, $context->pageId, $context->currentUserId)) {
            throw new RuntimeException('ACCESS_DENIED');
        }

        $settings = DiskSettingsRepository::getByBlockId($context->blockId);
        $blockRootId = DiskRootResolver::resolve($context, $settings, false);
        DiskValidator::assertFolderInsideRoot($folderId, $blockRootId, $context);
        DiskValidator::assertFolderInsideRoot($currentFolderId, $folderId, $context);

        $adapter = new DiskBitrixStorageAdapter($context->currentUserId);
        $breadcrumbs = [];
        $visited = [];
        $cursorId = $currentFolderId;

        // Stop at the shared folder; neither names nor links to its parents are exposed.
        while (true) {
            if ($cursorId <= 0 || isset($visited[$cursorId]) || count($visited) >= 1000) {
                throw new RuntimeException('FOLDER_OUT_OF_SCOPE');
            }
            $visited[$cursorId] = true;
            DiskValidator::assertCanForFolder($context, $settings, $cursorId, (int)$blockRootId, 'canView');
            $folder = $adapter->getReadableFolderInfo($cursorId);
            array_unshift($breadcrumbs, $folder);
            if ($cursorId === $folderId) {
                break;
            }
            $cursorId = $folder['parentId'];
        }

        $items = $adapter->listItems($context, $currentFolderId, [
            'sortBy' => 'name',
            'sortDir' => 'asc',
            'requireNativeRead' => true,
        ]);
        $items = DiskValidator::filterVisibleItems($context, $settings, $items, $currentFolderId, (int)$blockRootId);
        // Folders first; preserve the name ordering within each group.
        usort($items, static fn(array $a, array $b): int =>
            ((string)$a['entityType'] !== 'folder') <=> ((string)$b['entityType'] !== 'folder')
        );

        return [
            'folder' => $breadcrumbs[count($breadcrumbs) - 1],
            'breadcrumbs' => $breadcrumbs,
            'items' => $items,
        ];
    }
}
