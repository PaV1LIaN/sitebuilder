<?php

use Bitrix\Disk\File;
use Bitrix\Disk\Folder;

require_once __DIR__ . '/unpack_archive_lib.php';

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

if ($sourceParentId <= 0) {
    throw new RuntimeException('ARCHIVE_PARENT_FOLDER_NOT_FOUND');
}

DiskValidator::assertFolderInsideRoot($sourceParentId, $rootFolderId, $context);
$permissions = DiskValidator::assertCanForFolder(
    $context,
    $settings,
    $sourceParentId,
    (int)$rootFolderId,
    'canUpload'
);
DiskValidator::assertCan($permissions, 'canView');

$targetFolder = Folder::loadById($sourceParentId);

if (!$targetFolder instanceof Folder) {
    throw new RuntimeException('ARCHIVE_PARENT_FOLDER_NOT_FOUND');
}

$extension = mb_strtolower((string)$file->getExtension());

if ($extension !== 'zip') {
    throw new RuntimeException('ONLY_ZIP_SUPPORTED');
}

if (!class_exists('ZipArchive')) {
    throw new RuntimeException('ZIP_EXTENSION_NOT_INSTALLED');
}

sb_disk_release_session_lock();

$zipPath = sb_disk_unpack_get_file_path($file);

$zip = new ZipArchive();
$openResult = $zip->open($zipPath);

if ($openResult !== true) {
    throw new RuntimeException('ZIP_OPEN_ERROR');
}

$allowedExtensions = $settings['allowedExtensions'] ?? [];
$allowedExtensions = is_array($allowedExtensions) ? array_map('mb_strtolower', $allowedExtensions) : [];

$maxFileSize = (int)($settings['maxFileSize'] ?? 0);

$maxFiles = 5000;
$maxTotalSize = 1024 * 1024 * 1024;

$totalSize = 0;
$totalFiles = 0;
$totalFolders = 0;
$entries = [];

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
        $pathParts = sb_disk_unpack_safe_path_parts($rawName);

        if (empty($pathParts)) {
            continue;
        }

        if ($isDir) {
            $totalFolders++;

            $entries[] = [
                'rawName' => $rawName,
                'isDir' => true,
                'size' => 0,
            ];

            continue;
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

        $fileName = end($pathParts);
        $safeFileName = sb_disk_unpack_sanitize_name((string)$fileName, 'file');

        if (!empty($allowedExtensions)) {
            $fileExt = mb_strtolower(pathinfo($safeFileName, PATHINFO_EXTENSION));

            if (!in_array($fileExt, $allowedExtensions, true)) {
                throw new RuntimeException('EXTENSION_NOT_ALLOWED_IN_ARCHIVE: ' . $safeFileName);
            }
        }

        $totalFiles++;

        if ($totalFiles > $maxFiles) {
            throw new RuntimeException('ARCHIVE_TOO_MANY_FILES');
        }

        $entries[] = [
            'rawName' => $rawName,
            'isDir' => false,
            'size' => $fileSize,
        ];
    }
} finally {
    $zip->close();
}

$jobId = bin2hex(random_bytes(16));

$job = [
    'id' => $jobId,
    'createdAt' => time(),

    'siteId' => $context->siteId,
    'pageId' => $context->pageId,
    'blockId' => $context->blockId,
    'currentUserId' => $context->currentUserId,

    'rootFolderId' => (int)$rootFolderId,
    'fileId' => (int)$file->getId(),
    'targetFolderId' => (int)$targetFolder->getId(),
    'archiveName' => (string)$file->getName(),

    'settings' => [
        'allowedExtensions' => $allowedExtensions,
        'maxFileSize' => $maxFileSize,
    ],

    'entries' => $entries,
    'index' => 0,

    'totalEntries' => count($entries),
    'totalFiles' => $totalFiles,
    'totalFolders' => $totalFolders,
    'totalSize' => $totalSize,

    'extractedFiles' => 0,
    'createdFolders' => 0,
    'processedSize' => 0,
];

sb_disk_unpack_save_job($job);

$firstEntryName = '';

if (!empty($entries[0]['rawName'])) {
    $firstEntryName = basename((string)$entries[0]['rawName']);
}

DiskResponse::success([
    'jobId' => $jobId,
    'archiveName' => (string)$file->getName(),
    'nextEntryName' => $firstEntryName,
    'targetFolder' => [
        'id' => (int)$targetFolder->getId(),
        'name' => (string)$targetFolder->getName(),
    ],
    'totalEntries' => count($entries),
    'totalFiles' => $totalFiles,
    'totalFolders' => $totalFolders,
    'totalSize' => $totalSize,
]);
