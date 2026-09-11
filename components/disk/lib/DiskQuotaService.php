<?php

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;

final class DiskQuotaExceededException extends RuntimeException
{
    public function __construct(public int $limitBytes, public int $usedBytes, public int $incomingBytes)
    {
        parent::__construct('DISK_QUOTA_EXCEEDED');
    }
}

final class DiskQuotaService
{
    private const LOCK_NAMESPACE = 761340;

    public static function validateLimit($value): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[0-9]+$/D', $value)))
            || $value < 0 || $value > 9007199254740991) {
            throw new InvalidArgumentException('INVALID_MAX_DISK_SIZE');
        }
        return (int)$value;
    }

    private static function addSize(int $total, int $bytes): int
    {
        if ($bytes < 0 || $total > PHP_INT_MAX - $bytes) {
            throw new RuntimeException('DISK_SIZE_UNAVAILABLE');
        }
        return $total + $bytes;
    }

    public static function uploadSize(array $files): int
    {
        $total = 0;
        foreach ($files as $file) {
            $path = (string)($file['tmp_name'] ?? '');
            $bytes = $path !== '' && is_file($path) ? filesize($path) : false;
            if ($bytes === false) throw new RuntimeException('UPLOAD_TMP_FILE_NOT_FOUND');
            $total = self::addSize($total, (int)$bytes);
        }
        return $total;
    }

    public static function folderSize(int $folderId, int $userId): int
    {
        $total = 0;
        $pending = [$folderId];
        $visited = [];
        // Capacity includes hidden files, so it must not depend on the uploader's ACL.
        $security = Driver::getInstance()->getFakeSecurityContext($userId);
        while ($pending) {
            $id = array_pop($pending);
            if (isset($visited[$id])) continue;
            $visited[$id] = true;
            $folder = Folder::loadById($id);
            if (!$folder) throw new RuntimeException('DISK_FOLDER_NOT_FOUND');
            if ($folder->isDeleted()) continue;
            foreach ($folder->getChildren($security) as $child) {
                if ($child->isDeleted()) continue;
                if ($child instanceof Folder) {
                    $pending[] = (int)$child->getId();
                } elseif ($child instanceof File) {
                    $total = self::addSize($total, (int)$child->getSize());
                }
            }
        }
        return $total;
    }

    public static function itemsSize(DiskContext $context, array $items, ?int $moveIntoRoot = null): int
    {
        $total = 0;
        $adapter = new DiskBitrixStorageAdapter($context->currentUserId);
        foreach ($items as $item) {
            $isFolder = (string)($item['entityType'] ?? '') === 'folder';
            $id = (int)($item['id'] ?? 0);
            $object = $isFolder ? Folder::loadById($id) : File::loadById($id);
            if (!$object || $object->isDeleted()) throw new RuntimeException('DISK_ITEM_NOT_FOUND');
            $sourceFolderId = $isFolder ? $id : (int)$object->getParentId();
            // Moving within a quota root does not add bytes; moving into a nested root does.
            if ($moveIntoRoot !== null && $adapter->isFolderInsideRoot($context, $sourceFolderId, $moveIntoRoot)) {
                continue;
            }
            $size = $isFolder ? self::folderSize($id, $context->currentUserId) : (int)$object->getSize();
            $total = self::addSize($total, $size);
        }
        return $total;
    }

    private static function ancestors(int $folderId): array
    {
        $result = [];
        while ($folderId > 0) {
            if (isset($result[$folderId]) || count($result) >= 1000) throw new RuntimeException('FOLDER_OUT_OF_SCOPE');
            $folder = Folder::loadById($folderId);
            if (!$folder || $folder->isDeleted()) throw new RuntimeException('DISK_FOLDER_NOT_FOUND');
            $result[$folderId] = true;
            $folderId = (int)$folder->getParentId();
        }
        if (!$result) throw new RuntimeException('INVALID_FOLDER_ID');
        return $result;
    }

    private static function limitsForPath(array $ancestors): array
    {
        // The same native folder can be exposed by several blocks/sites. Honor
        // every configured ancestor cap, including a cap set through another block.
        $rows = sb_db_fetch_all("
            SELECT b.props_json, s.disk_folder_id
            FROM sitebuilder.block b
            JOIN sitebuilder.page p ON p.id = b.page_id
            JOIN sitebuilder.site s ON s.id = p.site_id
            WHERE b.type = 'disk'
              AND COALESCE(b.props_json->>'maxDiskSize', '0') <> '0'
        ");
        $limits = [];
        foreach ($rows as $row) {
            $props = is_array($row['props_json'] ?? null)
                ? $row['props_json'] : json_decode((string)($row['props_json'] ?? '{}'), true);
            if (!is_array($props)) continue;
            $limit = max(0, (int)($props['maxDiskSize'] ?? 0));
            if ($limit <= 0) continue;
            $rootId = ($props['rootMode'] ?? 'site') === 'block' && !empty($props['rootFolderId'])
                ? (int)$props['rootFolderId'] : (int)($row['disk_folder_id'] ?? 0);
            if (isset($ancestors[$rootId])) {
                $limits[$rootId] = isset($limits[$rootId]) ? min($limits[$rootId], $limit) : $limit;
            }
        }
        return $limits;
    }

    /** The size callback receives each applicable quota root. The write runs under the same lock. */
    public static function write(DiskContext $context, int $targetFolderId, callable $incomingSize, callable $write)
    {
        $ancestors = self::ancestors($targetFolderId);
        $lockId = (int)array_key_last($ancestors);
        $pdo = sb_db();
        $lock = $pdo->prepare('SELECT pg_try_advisory_lock(:namespace, :folder_id)');
        $lock->execute([':namespace' => self::LOCK_NAMESPACE, ':folder_id' => $lockId]);
        if (!in_array($lock->fetchColumn(), [true, 1, '1', 't'], true)) {
            throw new RuntimeException('DISK_QUOTA_BUSY');
        }
        try {
            $ancestors = self::ancestors($targetFolderId);
            if ((int)array_key_last($ancestors) !== $lockId) throw new RuntimeException('DISK_QUOTA_BUSY');
            foreach (self::limitsForPath($ancestors) as $rootId => $limit) {
                $incoming = (int)$incomingSize((int)$rootId);
                if ($incoming < 0) throw new RuntimeException('DISK_SIZE_UNAVAILABLE');
                if ($incoming === 0) continue;
                $used = self::folderSize((int)$rootId, $context->currentUserId);
                if ($used > $limit || $incoming > $limit - $used) {
                    throw new DiskQuotaExceededException($limit, $used, $incoming);
                }
            }
            return $write();
        } finally {
            $unlock = $pdo->prepare('SELECT pg_advisory_unlock(:namespace, :folder_id)');
            $unlock->execute([':namespace' => self::LOCK_NAMESPACE, ':folder_id' => $lockId]);
        }
    }

    public static function assertAdditional(DiskContext $context, int $folderId, int $bytes): void
    {
        self::write($context, $folderId, static fn(int $rootId): int => $bytes, static fn() => null);
    }

    public static function archiveSize(ZipArchive $zip): int
    {
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) continue;
            $name = trim(str_replace('\\', '/', (string)($stat['name'] ?? '')));
            if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') continue;
            $total = self::addSize($total, (int)($stat['size'] ?? 0));
        }
        return $total;
    }
}
