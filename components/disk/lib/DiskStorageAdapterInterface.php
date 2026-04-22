<?php

interface DiskStorageAdapterInterface
{
    public function listItems(DiskContext $context, int $folderId, array $options = []): array;

    public function createFolder(DiskContext $context, int $parentFolderId, string $name): array;

    public function uploadFiles(DiskContext $context, int $folderId, array $files, array $options = []): array;

    public function rename(DiskContext $context, string $entityType, int $entityId, string $newName): array;

    public function delete(DiskContext $context, array $items): array;

    public function move(DiskContext $context, array $items, int $targetFolderId): array;

    public function copy(DiskContext $context, array $items, int $targetFolderId): array;

    public function search(DiskContext $context, int $rootFolderId, string $query, array $options = []): array;

    public function getBreadcrumbs(DiskContext $context, int $folderId): array;

    public function getDownloadUrl(DiskContext $context, int $fileId): string;

    public function isFolderInsideRoot(DiskContext $context, int $folderId, int $rootFolderId): bool;
}
