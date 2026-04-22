<?php

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\BaseObject;
use Bitrix\Main\Type\DateTime;

class DiskBitrixStorageAdapter implements DiskStorageAdapterInterface
{
    protected Driver $driver;
    protected \Bitrix\Disk\Security\SecurityContext $securityContext;

    public function __construct(?int $userId = null)
    {
        $this->driver = Driver::getInstance();

        global $USER;

        $userId = $userId ?: (int)($USER instanceof CUser ? $USER->GetID() : 0);
        if ($userId <= 0) {
            throw new RuntimeException('NOT_AUTHORIZED');
        }

        $this->securityContext = $this->driver->getFakeSecurityContext($userId);
    }

    public function listItems(DiskContext $context, int $folderId, array $options = []): array
    {
        $folder = $this->getFolderById($folderId);

        $sortBy = (string)($options['sortBy'] ?? 'updatedAt');
        $sortDir = strtolower((string)($options['sortDir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $children = $folder->getChildren($this->securityContext, [
            'filter' => [
                '=DELETED_TYPE' => 0,
            ],
            'order' => $this->normalizeOrder($sortBy, $sortDir),
        ]);

        $items = [];

        foreach ($children as $child) {
            if ($child instanceof Folder) {
                $items[] = $this->normalizeFolder($context, $child);
                continue;
            }

            if ($child instanceof File) {
                $items[] = $this->normalizeFile($context, $child);
            }
        }

        return $items;
    }

    public function createFolder(DiskContext $context, int $parentFolderId, string $name): array
    {
        $parentFolder = $this->getFolderById($parentFolderId);

        $createdFolder = $parentFolder->addSubFolder([
            'NAME' => $name,
            'CREATED_BY' => $context->currentUserId,
        ], $this->securityContext);

        if (!$createdFolder instanceof Folder) {
            throw new RuntimeException('DISK_CREATE_FOLDER_ERROR');
        }

        return $this->normalizeFolder($context, $createdFolder);
    }

    public function uploadFiles(DiskContext $context, int $folderId, array $files, array $options = []): array
    {
        $folder = $this->getFolderById($folderId);

        $uploadedItems = [];

        foreach ($files as $file) {
            if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                throw new RuntimeException('INVALID_UPLOADED_FILE');
            }

            $diskFile = $folder->uploadFile(
                $file,
                [
                    'NAME' => (string)$file['name'],
                    'CREATED_BY' => $context->currentUserId,
                ],
                [],
                $this->securityContext
            );

            if (!$diskFile instanceof File) {
                throw new RuntimeException('DISK_UPLOAD_ERROR');
            }

            $uploadedItems[] = $this->normalizeFile($context, $diskFile);
        }

        return [
            'uploaded' => count($uploadedItems),
            'items' => $uploadedItems,
        ];
    }

    public function rename(DiskContext $context, string $entityType, int $entityId, string $newName): array
    {
        $object = $this->getObjectByTypeAndId($entityType, $entityId);

        $result = $object->rename($newName, $context->currentUserId);
        if (!$result) {
            throw new RuntimeException('DISK_RENAME_ERROR');
        }

        $object = $this->reloadObject($object);

        if ($object instanceof Folder) {
            return $this->normalizeFolder($context, $object);
        }

        if ($object instanceof File) {
            return $this->normalizeFile($context, $object);
        }

        throw new RuntimeException('DISK_OBJECT_RELOAD_ERROR');
    }

    public function delete(DiskContext $context, array $items): array
    {
        $deleted = 0;

        foreach ($items as $item) {
            $entityType = (string)($item['entityType'] ?? '');
            $id = (int)($item['id'] ?? 0);

            if ($id <= 0 || !in_array($entityType, ['file', 'folder'], true)) {
                continue;
            }

            $object = $this->getObjectByTypeAndId($entityType, $id);
            $result = $object->delete($context->currentUserId);

            if ($result) {
                $deleted++;
            }
        }

        return [
            'deleted' => $deleted,
        ];
    }

    public function move(DiskContext $context, array $items, int $targetFolderId): array
    {
        $targetFolder = $this->getFolderById($targetFolderId);
        $moved = 0;

        foreach ($items as $item) {
            $entityType = (string)($item['entityType'] ?? '');
            $id = (int)($item['id'] ?? 0);

            if ($id <= 0 || !in_array($entityType, ['file', 'folder'], true)) {
                continue;
            }

            $object = $this->getObjectByTypeAndId($entityType, $id);
            $result = $object->moveTo($targetFolder, $context->currentUserId);

            if ($result) {
                $moved++;
            }
        }

        return [
            'moved' => $moved,
            'targetFolderId' => $targetFolderId,
        ];
    }

    public function copy(DiskContext $context, array $items, int $targetFolderId): array
    {
        $targetFolder = $this->getFolderById($targetFolderId);
        $copied = 0;

        foreach ($items as $item) {
            $entityType = (string)($item['entityType'] ?? '');
            $id = (int)($item['id'] ?? 0);

            if ($id <= 0 || !in_array($entityType, ['file', 'folder'], true)) {
                continue;
            }

            $object = $this->getObjectByTypeAndId($entityType, $id);
            $result = $object->copyTo($targetFolder, $context->currentUserId, true);

            if ($result) {
                $copied++;
            }
        }

        return [
            'copied' => $copied,
            'targetFolderId' => $targetFolderId,
        ];
    }

    public function search(DiskContext $context, int $rootFolderId, string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $rootFolder = $this->getFolderById($rootFolderId);

        $result = [];
        $this->searchRecursive($context, $rootFolder, mb_strtolower($query), $result);

        return $result;
    }

    public function getBreadcrumbs(DiskContext $context, int $folderId): array
    {
        $folder = $this->getFolderById($folderId);

        $breadcrumbs = [];
        $chain = $folder->getPath();

        foreach ($chain as $pathFolder) {
            if (!$pathFolder instanceof Folder) {
                continue;
            }

            $breadcrumbs[] = [
                'id' => (int)$pathFolder->getId(),
                'name' => (string)$pathFolder->getName(),
            ];
        }

        return $breadcrumbs;
    }

    public function getDownloadUrl(DiskContext $context, int $fileId): string
    {
        $file = $this->getFileById($fileId);

        return '/local/sitebuilder/components/disk/api.php?action=download'
            . '&siteId=' . (int)$context->siteId
            . '&pageId=' . (int)$context->pageId
            . '&blockId=' . (int)$context->blockId
            . '&fileId=' . (int)$file->getId();
    }

    public function isFolderInsideRoot(DiskContext $context, int $folderId, int $rootFolderId): bool
    {
        if ($folderId === $rootFolderId) {
            return true;
        }

        $folder = $this->getFolderById($folderId);
        $path = $folder->getPath();

        foreach ($path as $pathFolder) {
            if ($pathFolder instanceof Folder && (int)$pathFolder->getId() === $rootFolderId) {
                return true;
            }
        }

        return false;
    }

    protected function searchRecursive(DiskContext $context, Folder $folder, string $query, array &$result): void
    {
        $children = $folder->getChildren($this->securityContext, [
            'filter' => [
                '=DELETED_TYPE' => 0,
            ],
            'order' => [
                'NAME' => 'ASC',
            ],
        ]);

        foreach ($children as $child) {
            $name = mb_strtolower((string)$child->getName());

            if (mb_stripos($name, $query) !== false) {
                if ($child instanceof Folder) {
                    $result[] = $this->normalizeFolder($context, $child);
                } elseif ($child instanceof File) {
                    $result[] = $this->normalizeFile($context, $child);
                }
            }

            if ($child instanceof Folder) {
                $this->searchRecursive($context, $child, $query, $result);
            }
        }
    }

    protected function getFolderById(int $folderId): Folder
    {
        $folder = Folder::loadById($folderId);
        if (!$folder instanceof Folder) {
            throw new RuntimeException('DISK_FOLDER_NOT_FOUND');
        }

        return $folder;
    }

    protected function getFileById(int $fileId): File
    {
        $file = File::loadById($fileId);
        if (!$file instanceof File) {
            throw new RuntimeException('DISK_FILE_NOT_FOUND');
        }

        return $file;
    }

    protected function getObjectByTypeAndId(string $entityType, int $id): BaseObject
    {
        if ($entityType === 'folder') {
            return $this->getFolderById($id);
        }

        if ($entityType === 'file') {
            return $this->getFileById($id);
        }

        throw new RuntimeException('INVALID_ENTITY_TYPE');
    }

    protected function reloadObject(BaseObject $object): BaseObject
    {
        if ($object instanceof Folder) {
            return $this->getFolderById((int)$object->getId());
        }

        if ($object instanceof File) {
            return $this->getFileById((int)$object->getId());
        }

        throw new RuntimeException('DISK_OBJECT_RELOAD_ERROR');
    }

    protected function normalizeFolder(DiskContext $context, Folder $folder): array
    {
        return [
            'id' => (int)$folder->getId(),
            'entityType' => 'folder',
            'name' => (string)$folder->getName(),
            'extension' => null,
            'mimeType' => null,
            'size' => null,
            'updatedAt' => $this->formatDateTime($folder->getUpdateTime()),
            'createdAt' => $this->formatDateTime($folder->getCreateTime()),
            'createdBy' => (int)$folder->getCreatedBy(),
            'downloadUrl' => null,
            'previewUrl' => null,
        ];
    }

    protected function normalizeFile(DiskContext $context, File $file): array
    {
        $name = (string)$file->getName();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return [
            'id' => (int)$file->getId(),
            'entityType' => 'file',
            'name' => $name,
            'extension' => $extension ?: null,
            'mimeType' => (string)$file->getMimeType(),
            'size' => (int)$file->getSize(),
            'updatedAt' => $this->formatDateTime($file->getUpdateTime()),
            'createdAt' => $this->formatDateTime($file->getCreateTime()),
            'createdBy' => (int)$file->getCreatedBy(),
            'downloadUrl' => $this->getDownloadUrl($context, (int)$file->getId()),
            'previewUrl' => null,
        ];
    }

    protected function formatDateTime($value): string
    {
        if ($value instanceof DateTime) {
            return $value->format('d.m.Y H:i:s');
        }

        if ($value instanceof \Bitrix\Main\Type\Date) {
            return $value->format('d.m.Y');
        }

        return '';
    }

    protected function normalizeOrder(string $sortBy, string $sortDir): array
    {
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        switch ($sortBy) {
            case 'name':
                return ['NAME' => $sortDir];

            case 'size':
                return ['SIZE' => $sortDir];

            case 'createdAt':
                return ['CREATE_TIME' => $sortDir];

            case 'updatedAt':
            default:
                return ['UPDATE_TIME' => $sortDir];
        }
    }
}