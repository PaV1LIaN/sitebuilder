<?php

declare(strict_types=1);

/** Additive LOCAL group grants. No materialization into U{id} or Bitrix G{id}. */
final class AccessGroupPolicy
{
    private static ?bool $ready = null;
    private static array $rows = [];
    private static array $pages = [];
    private static array $folders = [];

    public static function reset(): void
    {
        self::$ready = null;
        self::$rows = self::$pages = self::$folders = [];
    }

    public static function available(): bool
    {
        if (!function_exists('sb_db')) return false;
        if (self::$ready === null) {
            $row = sb_db()->query("SELECT to_regclass('sitebuilder.access_group') AS g,
                to_regclass('sitebuilder.access_group_member') AS m,
                to_regclass('sitebuilder.access_group_rule') AS r")->fetch(PDO::FETCH_ASSOC);
            self::$ready = !empty($row['g']) && !empty($row['m']) && !empty($row['r']);
            if (!self::$ready && (!empty($row['g']) || !empty($row['m']) || !empty($row['r']))) {
                throw new RuntimeException('ACCESS_GROUP_SCHEMA_INCOMPLETE');
            }
        }
        return self::$ready;
    }

    public static function flag(mixed $value): bool
    {
        return $value === true || in_array($value, [1, '1', 't', 'true'], true);
    }

    /** One query per (site, subject) per HTTP request. Invalid page/block references never grant access. */
    public static function rules(int $siteId, int $userId): array
    {
        if ($siteId <= 0 || $userId <= 0 || !self::available()) return [];
        $key = $siteId . ':' . $userId;
        if (!isset(self::$rows[$key])) self::prime($siteId, [$userId]);
        return self::$rows[$key];
    }

    public static function prime(int $siteId, array $userIds): void
    {
        if ($siteId <= 0 || !$userIds || !self::available()) return;
        $ids = array_values(array_unique(array_filter(array_map('intval',$userIds), static fn(int $id): bool => $id>0)));
        foreach (array_chunk($ids,500) as $chunk) {
            $stmt = sb_db()->prepare("SELECT r.*,g.name AS group_name,m.user_id AS subject_id
                FROM sitebuilder.access_group_rule r JOIN sitebuilder.access_group g ON g.id=r.group_id
                JOIN sitebuilder.access_group_member m ON m.group_id=g.id
                WHERE m.user_id IN (SELECT value::bigint FROM jsonb_array_elements_text(CAST(:users AS jsonb)))
                AND r.site_id=:site AND (r.kind='site' OR EXISTS
                  (SELECT 1 FROM sitebuilder.page p WHERE p.id=r.page_id AND p.site_id=r.site_id))
                AND (r.block_id=0 OR EXISTS (SELECT 1 FROM sitebuilder.block b
                  WHERE b.id=r.block_id AND b.page_id=r.page_id AND b.type='disk')) ORDER BY r.id");
            $stmt->execute([':users'=>json_encode($chunk),':site'=>$siteId]);
            foreach ($chunk as $id) self::$rows[$siteId.':'.$id] = [];
            while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) self::$rows[$siteId.':'.(int)$row['subject_id']][] = $row;
        }
    }

    public static function sources(int $siteId, int $userId): array
    {
        $groups = [];
        foreach (self::rules($siteId, $userId) as $row) {
            $groups[(int)$row['group_id']] = ['id' => (int)$row['group_id'], 'name' => $row['group_name']];
        }
        return array_values($groups);
    }

    public static function pagePath(int $siteId, int $pageId): array
    {
        if (!isset(self::$pages[$siteId])) {
            $stmt = sb_db()->prepare('SELECT id,parent_id FROM sitebuilder.page WHERE site_id=:site');
            $stmt->execute([':site' => $siteId]);
            self::$pages[$siteId] = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) self::$pages[$siteId][(int)$row['id']] = (int)$row['parent_id'];
        }
        $path = []; $cursor = $pageId;
        while ($cursor > 0 && isset(self::$pages[$siteId][$cursor])) {
            if (isset($path[$cursor]) || count($path) >= 100) throw new RuntimeException('PAGE_HIERARCHY_INVALID');
            $path[$cursor] = count($path); $cursor = self::$pages[$siteId][$cursor];
        }
        return $path;
    }

    public static function page(int $siteId, int $pageId, int $userId): array
    {
        $caps = ['view' => false, 'edit' => false, 'diskView' => false, 'diskEdit' => false];
        $rows = self::rules($siteId, $userId);
        if (!$rows) return $caps;
        $path = self::pagePath($siteId, $pageId);
        if (!isset($path[$pageId])) return $caps;
        foreach ($rows as $row) {
            $id = (int)$row['page_id'];
            if ($row['kind'] === 'site' || ($row['kind'] === 'page' && isset($path[$id])
                && ($id === $pageId || self::flag($row['include_children'])))) {
                $caps['view'] = true;
                $caps['edit'] = $caps['edit'] || self::flag($row['can_edit']);
            } elseif (in_array($row['kind'], ['disk','folder'], true) && $id === $pageId) {
                // This enables the component shell only. DiskPermissionService enforces block/folder scope.
                $caps['diskView'] = true;
                $caps['diskEdit'] = $caps['diskEdit'] || self::flag($row['can_edit']);
                $caps['view'] = $caps['view'] || self::flag($row['open_page']);
            }
        }
        return $caps;
    }

    public static function hasPageAccess(int $siteId, int $userId): bool
    {
        foreach (self::rules($siteId, $userId) as $row) {
            if (in_array($row['kind'], ['site','page'], true) || self::flag($row['open_page'])) return true;
        }
        return false;
    }

    /** Current physical ancestry; stored paths are never used to authorize moved folders. */
    public static function folderPath(int $folderId, int $rootId): array
    {
        $key = $folderId . ':' . $rootId;
        if (isset(self::$folders[$key])) return self::$folders[$key];
        $path = []; $id = $folderId;
        while ($id > 0) {
            if (isset($path[$id]) || count($path) >= 200) throw new RuntimeException('FOLDER_HIERARCHY_INVALID');
            $path[$id] = count($path);
            if ($id === $rootId) return self::$folders[$key] = $path;
            $folder = \Bitrix\Disk\Folder::loadById($id);
            if (!$folder) break;
            if (method_exists($folder, 'getRealObject')) {
                $real = $folder->getRealObject();
                if (!$real || (int)$real->getId() !== $id) break;
            }
            $id = (int)$folder->getParentId();
        }
        return self::$folders[$key] = [];
    }

    public static function disk(int $siteId, int $pageId, int $blockId, int $folderId,
        int $rootId, int $userId, string $mode): array
    {
        $caps = ['view' => false, 'edit' => false, 'delete' => false, 'traverse' => false, 'groups' => []];
        $rows = self::rules($siteId, $userId);
        if (!$rows || $folderId <= 0 || $rootId <= 0) return $caps;
        $path = self::folderPath($folderId, $rootId);
        if (!$path) return $caps;
        $sources = [];
        foreach ($rows as $row) {
            if (!in_array($row['kind'], ['disk','folder'], true) || (int)$row['page_id'] !== $pageId
                || (int)$row['block_id'] !== $blockId || (int)$row['root_folder_id'] !== $rootId
                || $row['permission_mode'] !== $mode) continue;
            $at = (int)$row['folder_id'];
            if (isset($path[$at]) && ($at === $folderId || self::flag($row['include_children']))) {
                $caps['view'] = true;
                $caps['edit'] = $caps['edit'] || self::flag($row['can_edit']);
                $caps['delete'] = $caps['delete'] || self::flag($row['can_delete']);
                $sources[(int)$row['group_id']] = $row['group_name'];
            } elseif (isset(self::folderPath($at, $rootId)[$folderId])) {
                // Ancestors are navigable, but their files, writes and sibling folders remain forbidden.
                $caps['traverse'] = true;
                $sources[(int)$row['group_id']] = $row['group_name'];
            }
        }
        $caps['groups'] = array_values($sources);
        return $caps;
    }
}
