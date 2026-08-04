<?php

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;

DiskCsrf::validateFromRequest();

$data = disk_read_json_body();
$currentUserId = DiskCurrentUser::requireId();

$context = DiskContextFactory::fromArray([
    'siteId' => (int)($data['siteId'] ?? 0),
    'pageId' => (int)($data['pageId'] ?? 0),
    'blockId' => (int)($data['blockId'] ?? 0),
    'currentUserId' => $currentUserId,
]);

DiskValidator::assertContext($context);

$settings = DiskSettingsRepository::ensureExistsForBlock(
    $context->blockId,
    $context->siteId,
    $context->pageId,
    $context->currentUserId
);

$rootFolderId = DiskRootResolver::resolve($context, $settings);

if ($rootFolderId === null || $rootFolderId <= 0) {
    throw new RuntimeException('ROOT_FOLDER_NOT_RESOLVED');
}

$fileId = (int)($data['fileId'] ?? 0);
if ($fileId <= 0) {
    throw new RuntimeException('INVALID_FILE_ID');
}

$file = File::loadById($fileId);

if (!$file instanceof File) {
    throw new RuntimeException('DISK_FILE_NOT_FOUND');
}

$sourceParentId = (int)$file->getParentId();
DiskValidator::assertFolderInsideRoot($sourceParentId, $rootFolderId, $context);
$permissions = DiskValidator::assertCanForFolder(
    $context,
    $settings,
    $sourceParentId,
    (int)$rootFolderId,
    'canUpload'
);
DiskValidator::assertCan($permissions, 'canView');

$extension = mb_strtolower((string)$file->getExtension());

if ($extension !== 'zip') {
    throw new RuntimeException('ONLY_ZIP_SUPPORTED');
}

if (!class_exists('ZipArchive')) {
    throw new RuntimeException('ZIP_EXTENSION_NOT_INSTALLED');
}

$zipPath = sb_disk_archive_get_file_path($file);

$zip = new ZipArchive();
$openResult = $zip->open($zipPath);

if ($openResult !== true) {
    throw new RuntimeException('ZIP_OPEN_ERROR');
}

$securityContext = Driver::getInstance()->getFakeSecurityContext($context->currentUserId);

/*
 * Распаковываем НЕ в новую папку,
 * а прямо туда, где лежит сам архив.
 */
$targetFolder = Folder::loadById($sourceParentId);

if (!$targetFolder instanceof Folder) {
    $zip->close();
    throw new RuntimeException('ARCHIVE_PARENT_FOLDER_NOT_FOUND');
}


$allowedExtensions = $settings['allowedExtensions'] ?? [];
$allowedExtensions = is_array($allowedExtensions) ? array_map('mb_strtolower', $allowedExtensions) : [];

$maxFileSize = (int)($settings['maxFileSize'] ?? 0);

$maxFiles = 500;
$maxTotalSize = 512 * 1024 * 1024;

$totalSize = 0;
$extractedFiles = 0;
$createdFolders = 0;

$folderCache = [
    '' => $targetFolder,
];

try {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);

        if (!is_array($stat)) {
            continue;
        }

        $rawName = (string)($stat['name'] ?? '');

        if ($rawName === '') {
            continue;
        }

        $rawName = str_replace('\\', '/', $rawName);
        $rawName = trim($rawName);

        if ($rawName === '' || str_starts_with($rawName, '__MACOSX/')) {
            continue;
        }

        if (basename($rawName) === '.DS_Store') {
            continue;
        }

        $isDir = str_ends_with($rawName, '/');
        $pathParts = sb_disk_archive_safe_path_parts($rawName);

        if (empty($pathParts)) {
            continue;
        }

        if ($isDir) {
            [, $newFolders] = sb_disk_archive_ensure_folder_path(
                $context,
                $securityContext,
                $targetFolder,
                $folderCache,
                $pathParts
            );

            $createdFolders += $newFolders;
            continue;
        }

        if ($extractedFiles >= $maxFiles) {
            throw new RuntimeException('ARCHIVE_TOO_MANY_FILES');
        }

        $fileSize = (int)($stat['size'] ?? 0);

        if ($fileSize < 0) {
            $fileSize = 0;
        }

        if ($maxFileSize > 0 && $fileSize > $maxFileSize) {
            throw new RuntimeException('ARCHIVE_FILE_TOO_LARGE: ' . $rawName);
        }

        $totalSize += $fileSize;

        if ($totalSize > $maxTotalSize) {
            throw new RuntimeException('ARCHIVE_TOTAL_SIZE_TOO_LARGE');
        }

        $fileName = array_pop($pathParts);
        $safeFileName = DiskNameSanitizer::sanitizeFolderName($fileName, 'file');

        if (!empty($allowedExtensions)) {
            $fileExt = mb_strtolower(pathinfo($safeFileName, PATHINFO_EXTENSION));

            if (!in_array($fileExt, $allowedExtensions, true)) {
                throw new RuntimeException('EXTENSION_NOT_ALLOWED_IN_ARCHIVE: ' . $safeFileName);
            }
        }

        [$destinationFolder, $newFolders] = sb_disk_archive_ensure_folder_path(
            $context,
            $securityContext,
            $targetFolder,
            $folderCache,
            $pathParts
        );

        $createdFolders += $newFolders;

        $safeFileName = sb_disk_archive_unique_name($destinationFolder, $safeFileName, $securityContext);

        $stream = $zip->getStream($rawName);

        if (!is_resource($stream)) {
            throw new RuntimeException('ZIP_READ_ENTRY_ERROR: ' . $rawName);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'sb_zip_');

        if ($tmpFile === false) {
            fclose($stream);
            throw new RuntimeException('TEMP_FILE_CREATE_ERROR');
        }

        $out = fopen($tmpFile, 'wb');

        if (!is_resource($out)) {
            fclose($stream);
            @unlink($tmpFile);
            throw new RuntimeException('TEMP_FILE_OPEN_ERROR');
        }

        stream_copy_to_stream($stream, $out);

        fclose($stream);
        fclose($out);

        $uploadFile = [
            'name' => $safeFileName,
            'type' => sb_disk_archive_detect_mime($safeFileName),
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFile) ?: 0,
        ];

        $createdFile = $destinationFolder->uploadFile(
            $uploadFile,
            [
                'NAME' => $safeFileName,
                'CREATED_BY' => $context->currentUserId,
            ],
            []
        );

        @unlink($tmpFile);

        if (!$createdFile instanceof File) {
            throw new RuntimeException('DISK_UPLOAD_EXTRACTED_FILE_ERROR: ' . $safeFileName);
        }

        $extractedFiles++;
    }
} finally {
    $zip->close();
}

DiskResponse::success([
    'targetFolder' => [
        'id' => (int)$targetFolder->getId(),
        'name' => (string)$targetFolder->getName(),
    ],
    'extractedFiles' => $extractedFiles,
    'createdFolders' => $createdFolders,
    'totalSize' => $totalSize,
]);

function sb_disk_archive_get_file_path(File $file): string
{
    $fileId = 0;

    if (method_exists($file, 'getFileId')) {
        $fileId = (int)$file->getFileId();
    }

    if ($fileId <= 0 && method_exists($file, 'getFile')) {
        $fileData = $file->getFile();

        if (is_array($fileData)) {
            $fileId = (int)($fileData['ID'] ?? 0);
        }
    }

    if ($fileId <= 0) {
        throw new RuntimeException('BITRIX_FILE_ID_NOT_FOUND');
    }

    $fileArray = CFile::MakeFileArray($fileId);

    if (!is_array($fileArray)) {
        throw new RuntimeException('BITRIX_FILE_ARRAY_NOT_FOUND');
    }

    $path = (string)($fileArray['tmp_name'] ?? '');

    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('BITRIX_FILE_PATH_NOT_FOUND');
    }

    return $path;
}

function sb_disk_archive_safe_path_parts(string $path): array
{
    $path = str_replace('\\', '/', $path);
    $path = trim($path, "/ \t\n\r\0\x0B");

    if ($path === '') {
        return [];
    }

    if (preg_match('~(^|/)\.\.($|/)~', $path)) {
        throw new RuntimeException('ARCHIVE_UNSAFE_PATH');
    }

    if (preg_match('~^[a-zA-Z]:~', $path)) {
        throw new RuntimeException('ARCHIVE_UNSAFE_PATH');
    }

    $parts = explode('/', $path);
    $safeParts = [];

    foreach ($parts as $part) {
        $part = trim($part);

        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }

        $safeParts[] = DiskNameSanitizer::sanitizeFolderName($part, 'item');
    }

    return $safeParts;
}

function sb_disk_archive_ensure_folder_path(
    DiskContext $context,
    $securityContext,
    Folder $rootFolder,
    array &$folderCache,
    array $pathParts
): array {
    if (empty($pathParts)) {
        return [$rootFolder, 0];
    }

    $current = $rootFolder;
    $cacheKey = '';
    $createdCount = 0;

    foreach ($pathParts as $part) {
        $part = DiskNameSanitizer::sanitizeFolderName($part, 'Папка');
        $cacheKey = $cacheKey === '' ? $part : $cacheKey . '/' . $part;

        if (isset($folderCache[$cacheKey]) && $folderCache[$cacheKey] instanceof Folder) {
            $current = $folderCache[$cacheKey];
            continue;
        }

        $existing = sb_disk_archive_find_child_folder($current, $part, $securityContext);

        if ($existing instanceof Folder) {
            $current = $existing;
            $folderCache[$cacheKey] = $current;
            continue;
        }

        $created = $current->addSubFolder([
            'NAME' => $part,
            'CREATED_BY' => $context->currentUserId,
        ], [], true);

        if (!$created instanceof Folder) {
            throw new RuntimeException('DISK_CREATE_EXTRACT_FOLDER_ERROR: ' . $part);
        }

        $createdCount++;
        $current = $created;
        $folderCache[$cacheKey] = $current;
    }

    return [$current, $createdCount];
}

function sb_disk_archive_find_child_folder(Folder $parent, string $name, $securityContext): ?Folder
{
    $children = $parent->getChildren($securityContext);

    foreach ($children as $child) {
        if ($child instanceof Folder && (string)$child->getName() === $name) {
            return $child;
        }
    }

    return null;
}

function sb_disk_archive_name_exists(Folder $parent, string $name, $securityContext): bool
{
    $children = $parent->getChildren($securityContext);

    foreach ($children as $child) {
        if ((string)$child->getName() === $name) {
            return true;
        }
    }

    return false;
}

function sb_disk_archive_unique_name(Folder $parent, string $name, $securityContext): string
{
    $name = DiskNameSanitizer::sanitizeFolderName($name, 'item');

    if (!sb_disk_archive_name_exists($parent, $name, $securityContext)) {
        return $name;
    }

    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $baseName = $extension !== ''
        ? mb_substr($name, 0, -(mb_strlen($extension) + 1))
        : $name;

    for ($i = 1; $i <= 999; $i++) {
        $candidate = $extension !== ''
            ? $baseName . ' (' . $i . ').' . $extension
            : $baseName . ' (' . $i . ')';

        if (!sb_disk_archive_name_exists($parent, $candidate, $securityContext)) {
            return $candidate;
        }
    }

    return $baseName . ' (' . time() . ')' . ($extension !== '' ? '.' . $extension : '');
}

function sb_disk_archive_detect_mime(string $name): string
{
    $ext = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $map = [
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'zip' => 'application/zip',
    ];

    return $map[$ext] ?? 'application/octet-stream';
}
