<?php

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;

require_once __DIR__ . '/unpack_archive_lib.php';

DiskCsrf::validateFromRequest();

$data = disk_read_json_body();
$currentUserId = DiskCurrentUser::requireId();

$jobId = (string)($data['jobId'] ?? '');

if ($jobId === '') {
    throw new RuntimeException('INVALID_UNPACK_JOB_ID');
}

$job = sb_disk_unpack_load_job($jobId);

if ((int)($job['currentUserId'] ?? 0) !== $currentUserId) {
    throw new RuntimeException('UNPACK_JOB_ACCESS_DENIED');
}

$context = DiskContextFactory::fromArray([
    'siteId' => (int)($job['siteId'] ?? 0),
    'pageId' => (int)($job['pageId'] ?? 0),
    'blockId' => (int)($job['blockId'] ?? 0),
    'currentUserId' => $currentUserId,
]);

DiskValidator::assertContext($context);

$rootFolderId = (int)($job['rootFolderId'] ?? 0);
$targetFolderId = (int)($job['targetFolderId'] ?? 0);
$fileId = (int)($job['fileId'] ?? 0);

if ($rootFolderId <= 0 || $targetFolderId <= 0 || $fileId <= 0) {
    throw new RuntimeException('UNPACK_JOB_INVALID_DATA');
}

DiskValidator::assertFolderInsideRoot($targetFolderId, $rootFolderId, $context);

$file = File::loadById($fileId);

if (!$file instanceof File) {
    throw new RuntimeException('DISK_FILE_NOT_FOUND');
}

$targetFolder = Folder::loadById($targetFolderId);

if (!$targetFolder instanceof Folder) {
    throw new RuntimeException('TARGET_FOLDER_NOT_FOUND');
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

$securityContext = Driver::getInstance()->getFakeSecurityContext($context->currentUserId);

$entries = is_array($job['entries'] ?? null) ? $job['entries'] : [];
$totalEntries = count($entries);
$index = (int)($job['index'] ?? 0);

$settings = is_array($job['settings'] ?? null) ? $job['settings'] : [];
$allowedExtensions = is_array($settings['allowedExtensions'] ?? null)
    ? array_map('mb_strtolower', $settings['allowedExtensions'])
    : [];

$batchLimit = 1;
$startedAt = microtime(true);
$timeLimit = 4.0;

$processedThisStep = 0;
$lastEntryName = '';

try {
    while ($index < $totalEntries) {
        if ($processedThisStep >= $batchLimit) {
            break;
        }

        if ((microtime(true) - $startedAt) >= $timeLimit) {
            break;
        }

        $entry = $entries[$index] ?? null;

        if (!is_array($entry)) {
            $index++;
            continue;
        }

        $rawName = (string)($entry['rawName'] ?? '');
        $isDir = !empty($entry['isDir']);
        $size = (int)($entry['size'] ?? 0);

        $lastEntryName = basename($rawName);

        $pathParts = sb_disk_unpack_safe_path_parts($rawName);

        if (empty($pathParts)) {
            $index++;
            continue;
        }

        if ($isDir) {
            [, $newFolders] = sb_disk_unpack_ensure_folder_path(
                $context,
                $securityContext,
                $targetFolder,
                $pathParts
            );

            $job['createdFolders'] = (int)($job['createdFolders'] ?? 0) + $newFolders;
            $index++;
            $processedThisStep++;
            continue;
        }

        $fileName = array_pop($pathParts);
        $safeFileName = sb_disk_unpack_sanitize_name((string)$fileName, 'file');

        if (!empty($allowedExtensions)) {
            $fileExt = mb_strtolower(pathinfo($safeFileName, PATHINFO_EXTENSION));

            if (!in_array($fileExt, $allowedExtensions, true)) {
                throw new RuntimeException('EXTENSION_NOT_ALLOWED_IN_ARCHIVE: ' . $safeFileName);
            }
        }

        [$destinationFolder, $newFolders] = sb_disk_unpack_ensure_folder_path(
            $context,
            $securityContext,
            $targetFolder,
            $pathParts
        );

        $job['createdFolders'] = (int)($job['createdFolders'] ?? 0) + $newFolders;

        $safeFileName = sb_disk_unpack_unique_name($destinationFolder, $safeFileName, $securityContext);

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
            'type' => sb_disk_unpack_detect_mime($safeFileName),
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

        $job['extractedFiles'] = (int)($job['extractedFiles'] ?? 0) + 1;
        $job['processedSize'] = (int)($job['processedSize'] ?? 0) + max(0, $size);

        $index++;
        $processedThisStep++;
    }
} finally {
    $zip->close();
}

$job['index'] = $index;

$done = $index >= $totalEntries;
$percent = $totalEntries > 0 ? (int)floor(($index / $totalEntries) * 100) : 100;

if ($done) {
    $percent = 100;
    sb_disk_unpack_delete_job($jobId);
} else {
    sb_disk_unpack_save_job($job);
}

$nextEntryName = '';

if (!$done && isset($entries[$index]) && is_array($entries[$index])) {
    $nextEntryName = basename((string)($entries[$index]['rawName'] ?? ''));
}

DiskResponse::success([
    'jobId' => $jobId,
    'done' => $done,
    'index' => $index,
    'totalEntries' => $totalEntries,
    'percent' => $percent,
    'lastEntryName' => $lastEntryName,
    'nextEntryName' => $nextEntryName,

    'targetFolder' => [
        'id' => (int)$targetFolder->getId(),
        'name' => (string)$targetFolder->getName(),
    ],

    'extractedFiles' => (int)($job['extractedFiles'] ?? 0),
    'createdFolders' => (int)($job['createdFolders'] ?? 0),
    'processedSize' => (int)($job['processedSize'] ?? 0),
    'totalSize' => (int)($job['totalSize'] ?? 0),
]);