<?php

require_once __DIR__ . '/json.php';
require_once __DIR__ . '/helpers.php';

use Bitrix\Main\Loader;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Storage;
use Bitrix\Disk\Folder;
use Bitrix\Disk\File;
use Bitrix\Disk\Security\FakeSecurityContext;

if (!function_exists('sb_disk_require_module')) {
    function sb_disk_require_module(): void
    {
        if (!Loader::includeModule('disk')) {
            throw new RuntimeException('Disk module is not installed');
        }
    }
}

if (!function_exists('sb_disk_security_context')) {
    function sb_disk_security_context(?int $userId = null)
    {
        global $USER;

        if ($userId === null) {
            $userId = is_object($USER) && method_exists($USER, 'GetID')
                ? (int)$USER->GetID()
                : 0;
        }

        if (class_exists(FakeSecurityContext::class)) {
            return new FakeSecurityContext(max(0, $userId));
        }

        return null;
    }
}

if (!function_exists('sb_disk_common_storage')) {
    function sb_disk_common_storage(): ?Storage
    {
        sb_disk_require_module();

        if (method_exists(Storage::class, 'loadByEntity')) {
            $storage = Storage::loadByEntity('common', 0);
            if ($storage) {
                return $storage;
            }
        }

        if (class_exists(Driver::class)) {
            $driver = Driver::getInstance();

            if (method_exists($driver, 'getStorageByCommonId')) {
                $storage = $driver->getStorageByCommonId('shared_files_' . SITE_ID);
                if ($storage) {
                    return $storage;
                }
            }
        }

        return null;
    }
}

if (!function_exists('sb_disk_root_folder')) {
    function sb_disk_root_folder(): Folder
    {
        $storage = sb_disk_common_storage();
        if (!$storage) {
            throw new RuntimeException('Common Disk storage not found');
        }

        $root = $storage->getRootObject();
        if (!$root instanceof Folder) {
            throw new RuntimeException('Common Disk root folder not found');
        }

        return $root;
    }
}

if (!function_exists('sb_disk_get_children')) {
    function sb_disk_get_children(Folder $folder): array
    {
        $securityContext = sb_disk_security_context();

        try {
            if ($securityContext) {
                return (array)$folder->getChildren($securityContext);
            }
        } catch (Throwable $e) {
        }

        try {
            return (array)$folder->getChildren();
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sb_disk_find_child_folder_by_name')) {
    function sb_disk_find_child_folder_by_name(Folder $parent, string $name): ?Folder
    {
        foreach (sb_disk_get_children($parent) as $child) {
            if ($child instanceof Folder && (string)$child->getName() === $name) {
                return $child;
            }
        }
        return null;
    }
}

if (!function_exists('sb_disk_add_subfolder')) {
    function sb_disk_add_subfolder(
        Folder $parent,
        string $name,
        ?int $creatorUserId = null
    ): Folder {
        $fields = ['NAME' => $name];
        if ($creatorUserId !== null && $creatorUserId > 0) {
            $fields['CREATED_BY'] = $creatorUserId;
        }
        $securityContext = sb_disk_security_context($creatorUserId);

        try {
            $created = $parent->addSubFolder($fields, []);
            if ($created instanceof Folder) {
                return $created;
            }
            if (is_array($created) && isset($created['OBJECT']) && $created['OBJECT'] instanceof Folder) {
                return $created['OBJECT'];
            }
        } catch (Throwable $e) {
        }

        try {
            if ($securityContext) {
                $created = $parent->addSubFolder($fields, $securityContext);
                if ($created instanceof Folder) {
                    return $created;
                }
                if (is_array($created) && isset($created['OBJECT']) && $created['OBJECT'] instanceof Folder) {
                    return $created['OBJECT'];
                }
            }
        } catch (Throwable $e) {
        }

        throw new RuntimeException('Cannot create Disk folder: ' . $name);
    }
}

if (!function_exists('sb_disk_get_sitebuilder_root')) {
    /** Возвращает служебную папку без её создания. */
    function sb_disk_get_sitebuilder_root(): ?Folder
    {
        return sb_disk_find_child_folder_by_name(sb_disk_root_folder(), 'SiteBuilder');
    }
}

if (!function_exists('sb_disk_get_or_create_sitebuilder_root')) {
    function sb_disk_get_or_create_sitebuilder_root(?int $creatorUserId = null): Folder
    {
        $folder = sb_disk_get_sitebuilder_root();
        if ($folder) {
            return $folder;
        }

        return sb_disk_add_subfolder(sb_disk_root_folder(), 'SiteBuilder', $creatorUserId);
    }
}

if (!function_exists('sb_disk_load_folder_by_id')) {
    function sb_disk_load_folder_by_id(int $folderId): ?Folder
    {
        if ($folderId <= 0) {
            return null;
        }

        sb_disk_require_module();

        $folder = Folder::loadById($folderId);
        return $folder instanceof Folder ? $folder : null;
    }
}

if (!function_exists('sb_disk_load_file_by_id')) {
    function sb_disk_load_file_by_id(int $fileId): ?File
    {
        if ($fileId <= 0) {
            return null;
        }

        sb_disk_require_module();

        $file = File::loadById($fileId);
        return $file instanceof File ? $file : null;
    }
}

if (!function_exists('sb_disk_site_folder_name')) {
    function sb_disk_site_folder_name(array $site): string
    {
        $slug = trim((string)($site['slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        $id = (int)($site['id'] ?? 0);
        return $id > 0 ? ('site-' . $id) : 'sitebuilder-site';
    }
}

if (!function_exists('sb_disk_get_site_folder')) {
    /**
     * Возвращает уже привязанную папку сайта без создания объектов
     * в Битрикс.Диске и без изменения строки сайта.
     */
    function sb_disk_get_site_folder(int $siteId): ?Folder
    {
        if ($siteId <= 0) {
            return null;
        }

        $site = sb_find_site($siteId);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $folderId = (int)($site['diskFolderId'] ?? 0);
        if ($folderId <= 0) {
            return null;
        }

        return sb_disk_load_folder_by_id($folderId);
    }
}

if (!function_exists('sb_disk_ensure_site_folder')) {
    function sb_disk_ensure_site_folder(int $siteId, ?int $actorUserId = null): Folder
    {
        $site = sb_find_site($siteId);
        if (!$site) {
            throw new RuntimeException('Site not found');
        }

        $folderId = (int)($site['diskFolderId'] ?? 0);
        if ($folderId > 0) {
            $folder = sb_disk_load_folder_by_id($folderId);
            if ($folder) {
                return $folder;
            }
        }

        global $USER;
        $userId = $actorUserId !== null
            ? max(0, $actorUserId)
            : (is_object($USER) && $USER->IsAuthorized() ? (int)$USER->GetID() : 0);

        $sitebuilderRoot = sb_disk_get_or_create_sitebuilder_root($userId);
        $folderName = sb_disk_site_folder_name($site);

        $folder = sb_disk_find_child_folder_by_name($sitebuilderRoot, $folderName);
        if (!$folder) {
            $folder = sb_disk_add_subfolder($sitebuilderRoot, $folderName, $userId);
        }

        $site['diskFolderId'] = (int)$folder->getId();

        /*
         * Это автоматическая системная модификация. Берём актуальную строку
         * и меняем только diskFolderId, поэтому пользовательские настройки
         * не перезаписываются устаревшим снимком.
         */
        $currentSite = RevisionService::getSite($siteId, false);
        if (!$currentSite) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }
        $currentSite['diskFolderId'] = (int)$folder->getId();
        RevisionService::saveSite(
            $currentSite,
            (int)$currentSite['version'],
            $userId,
            'disk_folder_attach'
        );

        return $folder;
    }
}

if (!function_exists('sb_disk_upload_file_to_folder')) {
  function sb_disk_upload_file_to_folder(Folder $folder, array $fileArray): File
  {
      $securityContext = sb_disk_security_context();

      $name = (string)($fileArray['name'] ?? 'file');
      $tmpName = (string)($fileArray['tmp_name'] ?? '');
      $type = (string)($fileArray['type'] ?? 'application/octet-stream');
      $size = (int)($fileArray['size'] ?? 0);

      if ($tmpName === '' || !file_exists($tmpName)) {
          throw new RuntimeException('Uploaded tmp file not found');
      }

      $uploadFile = [
          'name' => $name,
          'tmp_name' => $tmpName,
          'type' => $type,
          'size' => $size,
          'error' => 0,
      ];

      $fields = [
          'NAME' => $name,
          'CREATED_BY' => (function () {
              global $USER;
              return is_object($USER) ? (int)$USER->GetID() : 0;
          })(),
      ];

      $attemptErrors = [];

      try {
          $created = $folder->uploadFile($uploadFile, $fields, [], true);
          if ($created instanceof File) {
              return $created;
          }
          if (is_array($created) && isset($created['OBJECT']) && $created['OBJECT'] instanceof File) {
              return $created['OBJECT'];
          }
      } catch (Throwable $e) {
          $attemptErrors[] = 'uploadFile(file, fields, [], true): ' . $e->getMessage();
      }

      try {
          $created = $folder->uploadFile($uploadFile, $fields, []);
          if ($created instanceof File) {
              return $created;
          }
          if (is_array($created) && isset($created['OBJECT']) && $created['OBJECT'] instanceof File) {
              return $created['OBJECT'];
          }
      } catch (Throwable $e) {
          $attemptErrors[] = 'uploadFile(file, fields, []): ' . $e->getMessage();
      }

      try {
          $created = $folder->uploadFile($uploadFile, $fields);
          if ($created instanceof File) {
              return $created;
          }
          if (is_array($created) && isset($created['OBJECT']) && $created['OBJECT'] instanceof File) {
              return $created['OBJECT'];
          }
      } catch (Throwable $e) {
          $attemptErrors[] = 'uploadFile(file, fields): ' . $e->getMessage();
      }

      if ($securityContext) {
          try {
              $created = $folder->uploadFile($uploadFile, $fields, $securityContext);
              if ($created instanceof File) {
                  return $created;
              }
              if (is_array($created) && isset($created['OBJECT']) && $created['OBJECT'] instanceof File) {
                  return $created['OBJECT'];
              }
          } catch (Throwable $e) {
              $attemptErrors[] = 'uploadFile(file, fields, securityContext): ' . $e->getMessage();
          }

          try {
              $created = $folder->uploadFile($uploadFile, $securityContext);
              if ($created instanceof File) {
                  return $created;
              }
              if (is_array($created) && isset($created['OBJECT']) && $created['OBJECT'] instanceof File) {
                  return $created['OBJECT'];
              }
          } catch (Throwable $e) {
              $attemptErrors[] = 'uploadFile(file, securityContext): ' . $e->getMessage();
          }
      }

      throw new RuntimeException('Cannot upload file to Disk folder. Attempts: ' . implode(' | ', $attemptErrors));
  }
}

if (!function_exists('sb_disk_file_belongs_to_site')) {
    function sb_disk_file_belongs_to_site(int $siteId, int $fileId): bool
    {
        $site = sb_find_site($siteId);
        if (!$site) {
            return false;
        }

        $siteFolderId = (int)($site['diskFolderId'] ?? 0);
        if ($siteFolderId <= 0) {
            return false;
        }

        $file = sb_disk_load_file_by_id($fileId);
        if (!$file) {
            return false;
        }

        $parentId = (int)$file->getParentId();
        return $parentId === $siteFolderId;
    }
}

if (!function_exists('sb_disk_delete_file')) {
    function sb_disk_delete_file(File $file): bool
    {
        $securityContext = sb_disk_security_context();

        try {
            if ($securityContext) {
                return (bool)$file->delete($securityContext);
            }
        } catch (Throwable $e) {
        }

        try {
            return (bool)$file->delete();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('sb_disk_delete_managed_site_folder')) {
    /**
     * Идемпотентно удаляет точную папку по сохранённому ID.
     * Разрешено удалять только прямого потомка служебной папки SiteBuilder.
     */
    function sb_disk_delete_managed_site_folder(
        int $folderId,
        ?int $actorUserId = null
    ): array {
        if ($folderId <= 0) {
            throw new InvalidArgumentException('INVALID_DISK_FOLDER_ID');
        }

        $folder = sb_disk_load_folder_by_id($folderId);
        if (!$folder) {
            return ['deleted' => false, 'alreadyMissing' => true, 'folderId' => $folderId];
        }

        $rootId = (int)$folder->getParentId();
        $sitebuilderRoot = sb_disk_load_folder_by_id($rootId);
        $commonRoot = sb_disk_root_folder();
        if (
            !$sitebuilderRoot
            || $folderId === $rootId
            || (string)$sitebuilderRoot->getName() !== 'SiteBuilder'
            || (int)$sitebuilderRoot->getParentId() !== (int)$commonRoot->getId()
        ) {
            throw new RuntimeException('DISK_FOLDER_NOT_MANAGED');
        }

        $name = (string)$folder->getName();
        $securityContext = sb_disk_security_context($actorUserId);
        $errors = [];

        if ($securityContext) {
            try {
                $result = $folder->delete($securityContext);
                if ($result !== false) {
                    return ['deleted' => true, 'alreadyMissing' => false, 'folderId' => $folderId, 'name' => $name];
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($actorUserId !== null && $actorUserId > 0) {
            try {
                $result = $folder->delete($actorUserId);
                if ($result !== false) {
                    return ['deleted' => true, 'alreadyMissing' => false, 'folderId' => $folderId, 'name' => $name];
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        try {
            $result = $folder->delete();
            if ($result !== false) {
                return ['deleted' => true, 'alreadyMissing' => false, 'folderId' => $folderId, 'name' => $name];
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        error_log('SiteBuilder Disk folder delete failed #' . $folderId . ': ' . implode(' | ', $errors));
        throw new RuntimeException('DISK_FOLDER_DELETE_FAILED');
    }
}


if (!function_exists('sb_disk_inspect_managed_site_folder')) {
    /** Проверяет существование и принадлежность папки служебному корню SiteBuilder. */
    function sb_disk_inspect_managed_site_folder(int $folderId): ?array
    {
        if ($folderId <= 0) {
            return null;
        }
        $folder = sb_disk_load_folder_by_id($folderId);
        if (!$folder) {
            return null;
        }
        $parentId = (int)$folder->getParentId();
        $sitebuilderRoot = sb_disk_get_sitebuilder_root();
        $managed = $sitebuilderRoot && $parentId === (int)$sitebuilderRoot->getId();
        return [
            'id' => (int)$folder->getId(),
            'name' => (string)$folder->getName(),
            'parentId' => $parentId,
            'managed' => (bool)$managed,
        ];
    }
}

if (!function_exists('sb_disk_list_managed_site_folders')) {
    /** Перечисляет прямые дочерние папки служебного корня SiteBuilder. */
    function sb_disk_list_managed_site_folders(): array
    {
        $root = sb_disk_get_sitebuilder_root();
        if (!$root) {
            return [];
        }
        $rows = [];
        foreach (sb_disk_get_children($root) as $child) {
            if (!$child instanceof Folder) {
                continue;
            }
            $rows[] = [
                'id' => (int)$child->getId(),
                'name' => (string)$child->getName(),
                'parentId' => (int)$child->getParentId(),
                'managed' => true,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $rows;
    }
}

if (!function_exists('sb_disk_file_download_url')) {
    function sb_disk_file_download_url(File $file): string
    {
        $fileId = (int)$file->getId();
        return '/bitrix/tools/disk/downloadFile.php?objectId=' . $fileId;
    }
}