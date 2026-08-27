<?php

use Bitrix\Disk\File;
use Bitrix\Disk\Folder;

class DiskBitrixStorageAdapter
{
    protected int $currentUserId;

    public function __construct(int $currentUserId)
    {
        $this->currentUserId = $currentUserId;
    }

    public function listItems(DiskContext $context, int $folderId, array $options = []): array
    {
        $folder = $this->getFolderById($folderId);

        $children = $folder->getChildren(
            \Bitrix\Disk\Driver::getInstance()->getFakeSecurityContext($this->currentUserId)
        );

        $items = [];

        foreach ($children as $child) {
            if ($child instanceof Folder) {
                $items[] = $this->normalizeFolder($context, $child);
            } elseif ($child instanceof File) {
                $items[] = $this->normalizeFile($context, $child);
            }
        }

        $sortBy = (string)($options['sortBy'] ?? 'updatedAt');
        $sortDir = strtolower((string)($options['sortDir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        usort($items, function ($a, $b) use ($sortBy, $sortDir) {
            $aVal = $a[$sortBy] ?? null;
            $bVal = $b[$sortBy] ?? null;

            if ($sortBy === 'name') {
                $cmp = strnatcasecmp((string)$aVal, (string)$bVal);
            } elseif ($sortBy === 'size') {
                $cmp = (int)$aVal <=> (int)$bVal;
            } else {
                $cmp = strcmp((string)$aVal, (string)$bVal);
            }

            return $sortDir === 'asc' ? $cmp : -$cmp;
        });

        return $items;
    }

    public function getBreadcrumbs(DiskContext $context, int $folderId): array
    {
        $breadcrumbs = [];
        $current = $this->getFolderById($folderId);

        while ($current instanceof Folder) {
            array_unshift($breadcrumbs, [
                'id' => (int)$current->getId(),
                'name' => (string)$current->getName(),
            ]);

            $parentId = (int)$current->getParentId();
            if ($parentId <= 0) {
                break;
            }

            $parent = Folder::loadById($parentId);
            if (!$parent instanceof Folder) {
                break;
            }

            $current = $parent;
        }

        return $breadcrumbs;
    }

    public function uploadFiles(DiskContext $context, int $folderId, array $files, array $settings = []): array
    {
        $folder = $this->getFolderById($folderId);
        $uploaded = [];

        foreach ($files as $file) {
            $safeName = DiskNameSanitizer::sanitizeFolderName((string)($file['name'] ?? ''), 'file');

            $fileArray = [
                'name' => $safeName,
                'type' => (string)($file['type'] ?? ''),
                'tmp_name' => (string)($file['tmp_name'] ?? ''),
                'error' => (int)($file['error'] ?? 0),
                'size' => (int)($file['size'] ?? 0),
            ];

            $createdFile = $folder->uploadFile(
                $fileArray,
                [
                    'NAME' => $safeName,
                    'CREATED_BY' => $context->currentUserId,
                ],
                []
            );

            if ($createdFile instanceof File) {
                $uploaded[] = $this->normalizeFile($context, $createdFile);
                continue;
            }

            $errors = [];

            if (method_exists($folder, 'getErrors')) {
                foreach ((array)$folder->getErrors() as $error) {
                    if (is_object($error) && method_exists($error, 'getMessage')) {
                        $errors[] = $error->getMessage();
                    } else {
                        $errors[] = (string)$error;
                    }
                }
            }

            throw new RuntimeException(
                'DISK_UPLOAD_FILE_ERROR' . (!empty($errors) ? ': ' . implode(' | ', $errors) : '')
            );
        }

        return $uploaded;
    }

    public function createFolder(DiskContext $context, int $parentFolderId, string $name): array
    {
        $parentFolder = $this->getFolderById($parentFolderId);

        $safeName = DiskNameSanitizer::sanitizeFolderName($name, 'Новая папка');

        $createdFolder = $parentFolder->addSubFolder([
            'NAME' => $safeName,
            'CREATED_BY' => $context->currentUserId,
        ], [], true);

        if (!$createdFolder instanceof Folder) {
            $errors = [];

            if (method_exists($parentFolder, 'getErrors')) {
                foreach ((array)$parentFolder->getErrors() as $error) {
                    if (is_object($error) && method_exists($error, 'getMessage')) {
                        $errors[] = $error->getMessage();
                    } else {
                        $errors[] = (string)$error;
                    }
                }
            }

            throw new RuntimeException(
                'DISK_CREATE_FOLDER_ERROR' . (!empty($errors) ? ': ' . implode(' | ', $errors) : '')
            );
        }

        return $this->normalizeFolder($context, $createdFolder);
    }

    public function rename(DiskContext $context, string $entityType, int $entityId, string $newName): array
    {
        $safeName = DiskNameSanitizer::sanitizeFolderName($newName, 'Новый объект');

        if ($entityType === 'folder') {
            $folder = $this->getFolderById($entityId);
            $folder->rename($safeName, $context->currentUserId);

            return $this->normalizeFolder($context, $folder);
        }

        if ($entityType === 'file') {
            $file = $this->getFileById($entityId);
            $file->rename($safeName, $context->currentUserId);

            return $this->normalizeFile($context, $file);
        }

        throw new RuntimeException('INVALID_ENTITY_TYPE');
    }

    public function delete(DiskContext $context, array $items): array
    {
        $deleted = [];

        foreach ($items as $item) {
            $entityType = (string)($item['entityType'] ?? '');
            $id = (int)($item['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            if ($entityType === 'folder') {
                $folder = $this->getFolderById($id);
                $folder->markDeleted($context->currentUserId);
                $deleted[] = ['entityType' => 'folder', 'id' => $id];
            } elseif ($entityType === 'file') {
                $file = $this->getFileById($id);
                $file->markDeleted($context->currentUserId);
                $deleted[] = ['entityType' => 'file', 'id' => $id];
            }
        }

        return $deleted;
    }

    public function move(DiskContext $context, array $items, int $targetFolderId): array
    {
        $targetFolder = $this->getFolderById($targetFolderId);
        $result = [];

        foreach ($items as $item) {
            $entityType = (string)($item['entityType'] ?? '');
            $id = (int)($item['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            if ($entityType === 'folder') {
                $folder = $this->getFolderById($id);
                $folder->moveTo($targetFolder, $context->currentUserId);
                $result[] = ['entityType' => 'folder', 'id' => $id];
            } elseif ($entityType === 'file') {
                $file = $this->getFileById($id);
                $file->moveTo($targetFolder, $context->currentUserId);
                $result[] = ['entityType' => 'file', 'id' => $id];
            }
        }

        return $result;
    }

    public function copy(DiskContext $context, array $items, int $targetFolderId): array
    {
        $targetFolder = $this->getFolderById($targetFolderId);
        $result = [];

        foreach ($items as $item) {
            $entityType = (string)($item['entityType'] ?? '');
            $id = (int)($item['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            if ($entityType === 'folder') {
                $folder = $this->getFolderById($id);
                $copy = $folder->copyTo($targetFolder, $context->currentUserId, true);

                if ($copy instanceof Folder) {
                    $result[] = [
                        'entityType' => 'folder',
                        'id' => (int)$copy->getId(),
                    ];
                }
            } elseif ($entityType === 'file') {
                $file = $this->getFileById($id);
                $copy = $file->copyTo($targetFolder, $context->currentUserId, true);

                if ($copy instanceof File) {
                    $result[] = [
                        'entityType' => 'file',
                        'id' => (int)$copy->getId(),
                    ];
                }
            }
        }

        return $result;
    }

    public function search(DiskContext $context, int $rootFolderId, string $query, array $options = []): array
    {
        $query = mb_strtolower(trim($query));

        if ($query === '') {
            return [];
        }

        $result = [];
        $this->searchRecursive($context, $rootFolderId, $query, $result);

        return $result;
    }

    public function getDownloadUrl(DiskContext $context, int $fileId): string
    {
        $file = $this->getFileById($fileId);

        return $this->buildDownloadUrl($file);
    }

    protected function searchRecursive(DiskContext $context, int $folderId, string $query, array &$result): void
    {
        $folder = $this->getFolderById($folderId);

        $children = $folder->getChildren(
            \Bitrix\Disk\Driver::getInstance()->getFakeSecurityContext($this->currentUserId)
        );

        foreach ($children as $child) {
            $name = mb_strtolower((string)$child->getName());

            if (mb_strpos($name, $query) !== false) {
                if ($child instanceof Folder) {
                    $result[] = $this->normalizeFolder($context, $child);
                } elseif ($child instanceof File) {
                    $result[] = $this->normalizeFile($context, $child);
                }
            }

            if ($child instanceof Folder) {
                $this->searchRecursive($context, (int)$child->getId(), $query, $result);
            }
        }
    }

    protected function normalizeFolder(DiskContext $context, Folder $folder): array
    {
        return [
            'id' => (int)$folder->getId(),
            'entityType' => 'folder',
            'name' => (string)$folder->getName(),
            'extension' => '',
            'mimeType' => 'inode/directory',
            'size' => 0,
            'downloadUrl' => '',
            'previewUrl' => '',
            'previewMode' => 'folder',
            'createdAt' => $this->normalizeDate($folder->getCreateTime()),
            'updatedAt' => $this->normalizeDate($folder->getUpdateTime()),
            'createdBy' => (int)$folder->getCreatedBy(),
        ];
    }

    protected function normalizeFile(DiskContext $context, File $file): array
    {
        $name = (string)$file->getName();
        $extension = (string)$file->getExtension();
        $mimeType = $this->detectMimeTypeByExtension($extension);
        $isOffice = $this->isOfficeDocument($extension);

        return [
            'id' => (int)$file->getId(),
            'entityType' => 'file',
            'name' => $name,
            'originalName' => $name,
            'extension' => $extension,
            'mimeType' => $mimeType,
            'size' => (int)$file->getSize(),
            'downloadUrl' => $this->buildScopedDownloadUrl($context, $file),
            'previewUrl' => $isOffice
                ? $this->buildScopedOfficePreviewUrl($context, $file)
                : $this->buildPreviewUrl($context, $file),
            'previewMode' => $isOffice ? 'office' : 'browser',
            'createdAt' => $this->normalizeDate($file->getCreateTime()),
            'updatedAt' => $this->normalizeDate($file->getUpdateTime()),
            'createdBy' => (int)$file->getCreatedBy(),
        ];
    }

    protected function buildScopedDownloadUrl(DiskContext $context, File $file): string
    {
        return '/local/sitebuilder/components/disk/api.php?'
            . http_build_query([
                'action' => 'download',
                'siteId' => (int)$context->siteId,
                'pageId' => (int)$context->pageId,
                'blockId' => (int)$context->blockId,
                'fileId' => (int)$file->getId(),
            ]);
    }

    protected function buildDownloadUrl(File $file): string
    {
        $driver = \Bitrix\Disk\Driver::getInstance();
        $urlManager = $driver->getUrlManager();

        if (is_object($urlManager) && method_exists($urlManager, 'getUrlForDownloadFile')) {
            return (string)$urlManager->getUrlForDownloadFile($file, true);
        }

        return '/bitrix/tools/disk/downloadFile/' . (int)$file->getId() . '/?ncc=1';
    }

    protected function buildScopedOfficePreviewUrl(DiskContext $context, File $file): string
    {
        return '/local/sitebuilder/components/disk/office_preview.php?'
            . http_build_query([
                'siteId' => (int)$context->siteId,
                'pageId' => (int)$context->pageId,
                'blockId' => (int)$context->blockId,
                'fileId' => (int)$file->getId(),
            ]);
    }

    protected function buildPreviewUrl(DiskContext $context, File $file): string
    {
        return '/local/sitebuilder/components/disk/preview.php'
            . '?siteId=' . (int)$context->siteId
            . '&pageId=' . (int)$context->pageId
            . '&blockId=' . (int)$context->blockId
            . '&fileId=' . (int)$file->getId();
    }

    protected function detectMimeTypeByExtension(string $extension): string
    {
        $extension = mb_strtolower(trim($extension));

        $map = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'zip' => 'application/zip',
            'rar' => 'application/vnd.rar',
            '7z' => 'application/x-7z-compressed',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
        ];

        return $map[$extension] ?? 'application/octet-stream';
    }

    protected function isOfficeDocument(string $extension): bool
    {
        $extension = mb_strtolower(trim($extension));

        return in_array($extension, [
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'odt',
            'ods',
            'odp',
            'rtf',
            'csv',
        ], true);
    }

    protected function normalizeDate($value): string
    {
        if ($value instanceof \Bitrix\Main\Type\DateTime) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value)) {
            return $value;
        }

        return '';
    }

    public function isFolderInsideRoot($arg1, $arg2, $arg3 = null): bool
    {
        if ($arg1 instanceof DiskContext) {
            $folderId = (int)$arg2;
            $rootFolderId = (int)$arg3;
        } else {
            $folderId = (int)$arg1;
            $rootFolderId = (int)$arg2;
        }

        if ($folderId <= 0 || $rootFolderId <= 0) {
            return false;
        }

        if ($folderId === $rootFolderId) {
            return true;
        }

        $current = Folder::loadById($folderId);

        while ($current instanceof Folder) {
            $currentId = (int)$current->getId();

            if ($currentId === $rootFolderId) {
                return true;
            }

            $parentId = (int)$current->getParentId();
            if ($parentId <= 0) {
                break;
            }

            $current = Folder::loadById($parentId);
        }

        return false;
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
}
