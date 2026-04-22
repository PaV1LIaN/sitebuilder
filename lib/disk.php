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
    function sb_disk_security_context()
    {
        global $USER;

        if (class_exists(FakeSecurityContext::class)) {
            return new FakeSecurityContext((int)$USER->GetID());
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
    function sb_disk_add_subfolder(Folder $parent, string $name): Folder
    {
        $fields = ['NAME' => $name];
        $securityContext = sb_disk_security_context();

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

if (!function_exists('sb_disk_get_or_create_sitebuilder_root')) {
    function sb_disk_get_or_create_sitebuilder_root(): Folder
    {
        $root = sb_disk_root_folder();

        $folder = sb_disk_find_child_folder_by_name($root, 'SiteBuilder');
        if ($folder) {
            return $folder;
        }

        return sb_disk_add_subfolder($root, 'SiteBuilder');
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

if (!function_exists('sb_disk_ensure_site_folder')) {
    function sb_disk_ensure_site_folder(int $siteId): Folder
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

        $sitebuilderRoot = sb_disk_get_or_create_sitebuilder_root();
        $folderName = sb_disk_site_folder_name($site);

        $folder = sb_disk_find_child_folder_by_name($sitebuilderRoot, $folderName);
        if (!$folder) {
            $folder = sb_disk_add_subfolder($sitebuilderRoot, $folderName);
        }

        $sites = sb_read_sites();
        foreach ($sites as &$s) {
            if ((int)($s['id'] ?? 0) === $siteId) {
                $s['diskFolderId'] = (int)$folder->getId();
                $s['updatedAt'] = date('c');
                break;
            }
        }
        unset($s);
        sb_write_sites($sites);

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

if (!function_exists('sb_disk_file_download_url')) {
    function sb_disk_file_download_url(File $file): string
    {
        $fileId = (int)$file->getId();
        return '/bitrix/tools/disk/downloadFile.php?objectId=' . $fileId;
    }
}