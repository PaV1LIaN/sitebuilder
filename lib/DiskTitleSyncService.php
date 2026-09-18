<?php

require_once __DIR__ . '/../components/disk/lib/DiskNameSanitizer.php';

/** Names belong to Disk objects; block props retain the last requested title. */
final class DiskTitleSyncService
{
    private static array $requestJobs = [];

    public static function folderName(?int $folderId, string $fallback): string
    {
        if (!$folderId) {
            return $fallback;
        }
        $folder = self::folder($folderId);
        return $folder ? (string)$folder->getName() : $fallback;
    }

    public static function assertUnchanged(int $folderId, string $expectedName): void
    {
        if (self::folderName($folderId, '') !== $expectedName) {
            throw new RuntimeException('DISK_TITLE_CONFLICT');
        }
    }

    public static function rootId(array $block, array $site): int
    {
        $props = (array)($block['props'] ?? []);
        return ($props['rootMode'] ?? 'site') === 'block' && !empty($props['rootFolderId'])
            ? (int)$props['rootFolderId'] : (int)($site['diskFolderId'] ?? 0);
    }

    /** Called after version validation, before saving the block. No Disk writes. */
    public static function prepare(array $current, array &$updated, int $userId, string $operation): ?array
    {
        if (($updated['type'] ?? '') !== 'disk'
            || !in_array($operation, ['content_update', 'disk_settings_save', 'restore'], true)) {
            return null;
        }
        $title = (string)($updated['props']['title'] ?? 'Файлы');
        if ($title === (string)($current['props']['title'] ?? 'Файлы') && $operation !== 'disk_settings_save') {
            return null;
        }
        $title = DiskNameSanitizer::sanitizeFolderName($title, 'Файлы');
        $updated['props']['title'] = $title;
        $page = RevisionService::getPage((int)$updated['pageId']);
        $site = $page ? RevisionService::getSite((int)$page['siteId']) : null;
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }
        $folderId = self::rootId($updated, $site);
        // Selecting a different existing root adopts its name, never renames it.
        if ($folderId <= 0 || $folderId !== self::rootId($current, $site)) {
            return null;
        }
        $folder = self::folder($folderId);
        if (!$folder) {
            throw new RuntimeException('DISK_FOLDER_NOT_FOUND');
        }
        if ((string)$folder->getName() === $title) {
            return null;
        }
        self::assertRenameAllowed($site, $folder, $userId);
        return [
            'siteId' => (int)$site['id'],
            'blockId' => (int)$updated['id'],
            'folderId' => $folderId,
            'fromName' => (string)$folder->getName(),
            'title' => $title,
            'actorUserId' => $userId,
        ];
    }

    public static function enqueue(array $change, int $version): void
    {
        if (!class_exists('OutboxService')) {
            require_once __DIR__ . '/OutboxService.php';
        }
        $job = OutboxService::enqueue(
            OutboxService::JOB_DISK_TITLE_SYNC,
            (int)$change['siteId'],
            $change,
            'block:' . $change['blockId'] . ':disk-title:' . $version,
            (int)$change['actorUserId'],
            70
        );
        self::$requestJobs[(int)$change['blockId']] = (int)$job['id'];
        sb_db_after_commit(static function () use ($job): void {
            require_once __DIR__ . '/ExternalJobWorker.php';
            ExternalJobWorker::runDiskTitleJob((int)$job['id']);
        });
    }

    public static function requestStatus(int $blockId): ?array
    {
        $id = self::$requestJobs[$blockId] ?? 0;
        $job = $id ? OutboxService::get($id) : null;
        return $job ? [
            'id' => $id,
            'status' => $job['status'],
            'error' => $job['lastErrorCode'] ?? '',
        ] : null;
    }

    /** Worker holds a folder lock; lock the block so a new edit cannot race it. */
    public static function execute(array $job): array
    {
        $change = (array)$job['payload'];
        $startedHere = sb_db_transaction_scope_begin();
        try {
            $block = RevisionService::getBlock((int)$change['blockId'], true);
            $site = RevisionService::getSite((int)$job['siteId']);
            $page = $block ? RevisionService::getPage((int)$block['pageId']) : null;
            if (!$block || !$site || !$page || (int)$page['siteId'] !== (int)$site['id']
                || ($block['type'] ?? '') !== 'disk'
                || self::rootId($block, $site) !== (int)$change['folderId']
                || (string)($block['props']['title'] ?? '') !== (string)$change['title']) {
                $result = ['skipped' => true, 'reason' => 'superseded'];
            } else {
                $folder = self::folder((int)$change['folderId']);
                if (!$folder) {
                    throw new RuntimeException('DISK_FOLDER_NOT_FOUND');
                }
                if ((string)$folder->getName() === (string)$change['title']) {
                    $result = ['folderId' => (int)$folder->getId(), 'unchanged' => true];
                } else {
                    if ((string)$folder->getName() !== (string)$change['fromName']) {
                        throw new RuntimeException('DISK_TITLE_CONFLICT');
                    }
                    self::assertRenameAllowed($site, $folder, (int)$change['actorUserId']);
                    self::renameObject($folder, (string)$change['title'], (int)$change['actorUserId']);
                    $result = ['folderId' => (int)$folder->getId(), 'name' => (string)$folder->getName()];
                }
            }
            sb_db_transaction_scope_commit($startedHere);
            return $result;
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }
    }

    public static function renameObject(object $object, string $name, int $userId): void
    {
        if ((string)$object->getName() === $name) {
            return;
        }
        if (!$object->rename($name, $userId)) {
            $errors = [];
            foreach ((array)$object->getErrors() as $error) {
                $errors[] = is_object($error) && method_exists($error, 'getMessage') ? $error->getMessage() : (string)$error;
            }
            throw new RuntimeException('DISK_RENAME_FAILED' . ($errors ? ': ' . implode(' | ', $errors) : ''));
        }
        if ((string)$object->getName() !== $name) {
            throw new RuntimeException('DISK_RENAME_NOT_CONFIRMED');
        }
    }

    private static function folder(int $id): ?\Bitrix\Disk\Folder
    {
        if (!\Bitrix\Main\Loader::includeModule('disk')) {
            throw new RuntimeException('DISK_MODULE_NOT_INSTALLED');
        }
        $folder = \Bitrix\Disk\Folder::loadById($id);
        return $folder instanceof \Bitrix\Disk\Folder && !$folder->isDeleted() ? $folder : null;
    }

    private static function assertRenameAllowed(array $site, \Bitrix\Disk\Folder $folder, int $userId): void
    {
        require_once __DIR__ . '/access.php';
        $bitrixAdmin = $userId > 0 && class_exists('CUser')
            && in_array(1, array_map('intval', (array)CUser::GetUserGroup($userId)), true);
        if ($userId <= 0 || (!$bitrixAdmin && !sitebuilder_is_admin($userId)
            && !sb_role_allows(sb_get_role((int)$site['id'], 'U' . $userId), 'admin'))) {
            throw new RuntimeException('ACCESS_DENIED');
        }
        if ((int)$folder->getParentId() <= 0) {
            throw new RuntimeException('DISK_STORAGE_ROOT_RENAME_FORBIDDEN');
        }
        $siteRootId = (int)($site['diskFolderId'] ?? 0);
        $current = $folder;
        $visited = [];
        while ($current && !isset($visited[(int)$current->getId()])) {
            $id = (int)$current->getId();
            if ($id === $siteRootId) {
                return;
            }
            $visited[$id] = true;
            $parentId = (int)$current->getParentId();
            $current = $parentId > 0 ? self::folder($parentId) : null;
        }
        // A configured folder outside this site's tree needs native permission.
        if (!$folder->canRename($folder->getStorage()->getSecurityContext($userId))) {
            throw new RuntimeException('ACCESS_DENIED');
        }
    }
}
