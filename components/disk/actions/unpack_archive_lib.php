<?php

use Bitrix\Disk\File;
use Bitrix\Disk\Folder;

if (!function_exists('sb_disk_unpack_jobs_dir')) {
    function sb_disk_unpack_jobs_dir(): string
    {
        $dir = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/upload/sitebuilder/disk_unpack_jobs';

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}

if (!function_exists('sb_disk_unpack_job_path')) {
    function sb_disk_unpack_job_path(string $jobId): string
    {
        $jobId = preg_replace('/[^a-f0-9]/i', '', $jobId);

        if ($jobId === '') {
            throw new RuntimeException('INVALID_UNPACK_JOB_ID');
        }

        return sb_disk_unpack_jobs_dir() . '/' . $jobId . '.json';
    }
}

if (!function_exists('sb_disk_unpack_save_job')) {
    function sb_disk_unpack_save_job(array $job): void
    {
        $jobId = (string)($job['id'] ?? '');

        if ($jobId === '') {
            throw new RuntimeException('EMPTY_UNPACK_JOB_ID');
        }

        file_put_contents(
            sb_disk_unpack_job_path($jobId),
            json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}

if (!function_exists('sb_disk_unpack_load_job')) {
    function sb_disk_unpack_load_job(string $jobId): array
    {
        $path = sb_disk_unpack_job_path($jobId);

        if (!is_file($path)) {
            throw new RuntimeException('UNPACK_JOB_NOT_FOUND');
        }

        $json = json_decode((string)file_get_contents($path), true);

        if (!is_array($json)) {
            throw new RuntimeException('UNPACK_JOB_BROKEN');
        }

        return $json;
    }
}

if (!function_exists('sb_disk_unpack_delete_job')) {
    function sb_disk_unpack_delete_job(string $jobId): void
    {
        $path = sb_disk_unpack_job_path($jobId);

        if (is_file($path)) {
            @unlink($path);
        }
    }
}

if (!function_exists('sb_disk_unpack_sanitize_name')) {
    function sb_disk_unpack_sanitize_name(string $name, string $fallback = 'item'): string
    {
        $name = trim($name);

        if ($name === '') {
            $name = $fallback;
        }

        if (class_exists('DiskNameSanitizer') && method_exists('DiskNameSanitizer', 'sanitizeFolderName')) {
            return DiskNameSanitizer::sanitizeFolderName($name, $fallback);
        }

        $name = str_replace(["\0", '/', '\\'], '_', $name);
        $name = trim($name);

        return $name !== '' ? $name : $fallback;
    }
}

if (!function_exists('sb_disk_unpack_get_file_path')) {
    function sb_disk_unpack_get_file_path(File $file): string
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
}

if (!function_exists('sb_disk_unpack_safe_path_parts')) {
    function sb_disk_unpack_safe_path_parts(string $path): array
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

            $safeParts[] = sb_disk_unpack_sanitize_name($part, 'item');
        }

        return $safeParts;
    }
}

if (!function_exists('sb_disk_unpack_find_child_folder')) {
    function sb_disk_unpack_find_child_folder(Folder $parent, string $name, $securityContext): ?Folder
    {
        $children = $parent->getChildren($securityContext);

        foreach ($children as $child) {
            if ($child instanceof Folder && (string)$child->getName() === $name) {
                return $child;
            }
        }

        return null;
    }
}

if (!function_exists('sb_disk_unpack_name_exists')) {
    function sb_disk_unpack_name_exists(Folder $parent, string $name, $securityContext): bool
    {
        $children = $parent->getChildren($securityContext);

        foreach ($children as $child) {
            if ((string)$child->getName() === $name) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('sb_disk_unpack_unique_name')) {
    function sb_disk_unpack_unique_name(Folder $parent, string $name, $securityContext): string
    {
        $name = sb_disk_unpack_sanitize_name($name, 'item');

        if (!sb_disk_unpack_name_exists($parent, $name, $securityContext)) {
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

            if (!sb_disk_unpack_name_exists($parent, $candidate, $securityContext)) {
                return $candidate;
            }
        }

        return $baseName . ' (' . time() . ')' . ($extension !== '' ? '.' . $extension : '');
    }
}

if (!function_exists('sb_disk_unpack_ensure_folder_path')) {
    function sb_disk_unpack_ensure_folder_path(
        DiskContext $context,
        $securityContext,
        Folder $rootFolder,
        array $pathParts
    ): array {
        if (empty($pathParts)) {
            return [$rootFolder, 0];
        }

        $current = $rootFolder;
        $createdCount = 0;

        foreach ($pathParts as $part) {
            $part = sb_disk_unpack_sanitize_name($part, 'Папка');

            $existing = sb_disk_unpack_find_child_folder($current, $part, $securityContext);

            if ($existing instanceof Folder) {
                $current = $existing;
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
        }

        return [$current, $createdCount];
    }
}

if (!function_exists('sb_disk_unpack_detect_mime')) {
    function sb_disk_unpack_detect_mime(string $name): string
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
}