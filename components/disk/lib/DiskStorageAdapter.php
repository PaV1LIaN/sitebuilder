<?php

class DiskStorageAdapter implements DiskStorageAdapterInterface
{
    public function listItems(DiskContext $context, int $folderId, array $options = []): array
    {
        // TODO:
        // Здесь должна быть реальная работа с хранилищем.
        // Пока тестовые данные.
        return [
            [
                'id' => 1001,
                'entityType' => 'folder',
                'name' => 'Документы',
                'extension' => null,
                'mimeType' => null,
                'size' => null,
                'updatedAt' => '2026-04-16 10:00:00',
                'downloadUrl' => null,
            ],
            [
                'id' => 1002,
                'entityType' => 'folder',
                'name' => 'Шаблоны',
                'extension' => null,
                'mimeType' => null,
                'size' => null,
                'updatedAt' => '2026-04-15 18:40:00',
                'downloadUrl' => null,
            ],
            [
                'id' => 2001,
                'entityType' => 'file',
                'name' => 'report.pdf',
                'extension' => 'pdf',
                'mimeType' => 'application/pdf',
                'size' => 124000,
                'updatedAt' => '2026-04-16 09:55:00',
                'downloadUrl' => '/local/sitebuilder/components/disk/api.php?action=download&fileId=2001',
            ],
        ];
    }

    public function createFolder(DiskContext $context, int $parentFolderId, string $name): array
    {
        return [
            'id' => random_int(5000, 9999),
            'entityType' => 'folder',
            'name' => $name,
        ];
    }

    public function uploadFiles(DiskContext $context, int $folderId, array $files, array $options = []): array
    {
        return [
            'uploaded' => count($files),
            'items' => [],
        ];
    }

    public function rename(DiskContext $context, string $entityType, int $entityId, string $newName): array
    {
        return [
            'id' => $entityId,
            'entityType' => $entityType,
            'name' => $newName,
        ];
    }

    public function delete(DiskContext $context, array $items): array
    {
        return [
            'deleted' => count($items),
        ];
    }

    public function move(DiskContext $context, array $items, int $targetFolderId): array
    {
        return [
            'moved' => count($items),
            'targetFolderId' => $targetFolderId,
        ];
    }

    public function copy(DiskContext $context, array $items, int $targetFolderId): array
    {
        return [
            'copied' => count($items),
            'targetFolderId' => $targetFolderId,
        ];
    }

    public function search(DiskContext $context, int $rootFolderId, string $query, array $options = []): array
    {
        $all = $this->listItems($context, $rootFolderId, $options);
        $query = mb_strtolower(trim($query));

        if ($query === '') {
            return [];
        }

        return array_values(array_filter($all, static function ($item) use ($query) {
            return mb_stripos((string)($item['name'] ?? ''), $query) !== false;
        }));
    }

    public function getBreadcrumbs(DiskContext $context, int $folderId): array
    {
        return [
            ['id' => $folderId, 'name' => 'Корень'],
        ];
    }

    public function getDownloadUrl(DiskContext $context, int $fileId): string
    {
        return '/local/sitebuilder/components/disk/api.php?action=download&fileId=' . $fileId;
    }
}