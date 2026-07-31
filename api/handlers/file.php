<?php

global $USER;

if ($action === 'file.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_viewer($siteId);

    try {
        $folder = sb_disk_get_site_folder($siteId);
        $children = $folder ? sb_disk_get_children($folder) : [];

        $files = [];
        foreach ($children as $child) {
            if (!$child instanceof \Bitrix\Disk\File) {
                continue;
            }

            $name = (string)$child->getName();
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $imageExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
            $isImage = in_array($extension, $imageExtensions, true);

            $files[] = [
                'id' => (int)$child->getId(),
                'name' => $name,
                'extension' => $extension,
                'isImage' => $isImage,
                'size' => (int)$child->getSize(),
                'createTime' => method_exists($child, 'getCreateTime') && $child->getCreateTime()
                    ? $child->getCreateTime()->format('c')
                    : '',
                'updateTime' => method_exists($child, 'getUpdateTime') && $child->getUpdateTime()
                    ? $child->getUpdateTime()->format('c')
                    : '',
                'downloadUrl' => sb_disk_file_download_url($child),
                'previewUrl' => $isImage
                    ? '/local/sitebuilder/media_preview.php?siteId=' . $siteId . '&fileId=' . (int)$child->getId()
                    : '',
            ];
        }

        usort($files, static function ($a, $b) {
            return strcmp((string)$a['name'], (string)$b['name']);
        });

        sb_json_ok([
            'files' => $files,
            'folderId' => $folder ? (int)$folder->getId() : 0,
        ]);
    } catch (Throwable $e) {
        error_log(sprintf(
            'SiteBuilder %s failed: %s in %s:%d',
            $action,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        sb_json_error('DISK_ERROR', 500);
    }
}

if ($action === 'file.upload') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_editor($siteId);

    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        sb_json_error('FILE_REQUIRED', 422);
    }

    $upload = $_FILES['file'];

    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        sb_json_error('UPLOAD_ERROR', 422, [
            'phpUploadError' => (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE),
        ]);
    }

    if (!is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
        sb_json_error('BAD_UPLOADED_FILE', 422);
    }

    try {
        $folder = sb_disk_ensure_site_folder($siteId);
        $file = sb_disk_upload_file_to_folder($folder, $upload);

        if (function_exists('sb_db_after_rollback')) {
            sb_db_after_rollback(static function () use ($file): void {
                if ($file instanceof \Bitrix\Disk\File) {
                    sb_disk_delete_file($file);
                }
            });
        }

        $uploadedName = (string)$file->getName();
        $uploadedExtension = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));
        $uploadedIsImage = in_array(
            $uploadedExtension,
            ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'],
            true
        );

        sb_json_ok([
            'file' => [
                'id' => (int)$file->getId(),
                'name' => $uploadedName,
                'extension' => $uploadedExtension,
                'isImage' => $uploadedIsImage,
                'size' => (int)$file->getSize(),
                'downloadUrl' => sb_disk_file_download_url($file),
                'previewUrl' => $uploadedIsImage
                    ? '/local/sitebuilder/media_preview.php?siteId=' . $siteId . '&fileId=' . (int)$file->getId()
                    : '',
            ],
            'folderId' => (int)$folder->getId(),
        ]);
    } catch (Throwable $e) {
        error_log(sprintf(
            'SiteBuilder %s failed: %s in %s:%d',
            $action,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        sb_json_error('DISK_ERROR', 500);
    }
}

if ($action === 'file.delete') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $fileId = (int)($_POST['fileId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    if ($fileId <= 0) {
        sb_json_error('FILE_ID_REQUIRED', 422);
    }

    sb_require_editor($siteId);

    try {
        if (!sb_disk_file_belongs_to_site($siteId, $fileId)) {
            sb_json_error('FILE_NOT_IN_SITE', 422);
        }

        $file = sb_disk_load_file_by_id($fileId);
        if (!$file) {
            sb_json_error('FILE_NOT_FOUND', 404);
        }

        $ok = sb_disk_delete_file($file);
        if (!$ok) {
            sb_json_error('DELETE_FAILED', 500);
        }

        sb_json_ok();
    } catch (Throwable $e) {
        error_log(sprintf(
            'SiteBuilder %s failed: %s in %s:%d',
            $action,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        sb_json_error('DISK_ERROR', 500);
    }
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'file',
    'action' => $action,
]);