<?php

declare(strict_types=1);

/** Explicit page denial is additive to the legacy schema, not inferred from empty flags. */
final class PageAccessPolicy
{
    private static ?bool $available = null;
    private static array $pages = [];
    private static array $rules = [];

    public static function available(): bool
    {
        if (self::$available === null) {
            $stmt = sb_db()->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_schema='sitebuilder' AND table_name='page_access' AND column_name='deny_view')");
            self::$available = self::bool($stmt->fetchColumn());
        }
        return self::$available;
    }

    public static function resetCache(): void
    {
        self::$available = null;
        self::$pages = self::$rules = [];
    }

    public static function bool($value): bool
    {
        return $value === true || in_array(strtolower((string)$value), ['1', 'true', 't', 'y'], true);
    }

    /** Nearest explicit view/edit/deny rule; disk-only and empty legacy rules do not cancel a denial. */
    public static function decide(array $path, array $rules): ?array
    {
        foreach ($path as $depth => $id) {
            $row = $rules[$id] ?? null;
            if (!$row || ($depth > 0 && !self::bool($row['include_children'] ?? false))) {
                continue;
            }
            if (self::bool($row['deny_view'] ?? false)) {
                return ['denied' => true, 'pageId' => (int)$id, 'inherited' => $depth > 0];
            }
            if (self::bool($row['can_view'] ?? false) || self::bool($row['can_edit'] ?? false)) {
                return ['denied' => false, 'pageId' => (int)$id, 'inherited' => $depth > 0];
            }
        }
        return null;
    }

    public static function restriction(int $siteId, int $pageId, int $userId): ?array
    {
        if ($siteId <= 0 || $pageId <= 0 || $userId <= 0 || !self::available()) {
            return null;
        }
        if (!isset(self::$pages[$siteId])) {
            $stmt = sb_db()->prepare('SELECT id,parent_id FROM sitebuilder.page WHERE site_id=:site');
            $stmt->execute([':site' => $siteId]);
            self::$pages[$siteId] = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                self::$pages[$siteId][(int)$row['id']] = (int)($row['parent_id'] ?? 0);
            }
        }
        $key = $siteId . ':' . $userId;
        if (!isset(self::$rules[$key])) {
            $stmt = sb_db()->prepare('SELECT page_id,can_view,can_edit,include_children,deny_view
                FROM sitebuilder.page_access WHERE site_id=:site AND access_code=:code');
            $stmt->execute([':site' => $siteId, ':code' => 'U' . $userId]);
            self::$rules[$key] = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                self::$rules[$key][(int)$row['page_id']] = $row;
            }
        }
        $path = []; $seen = []; $id = $pageId;
        while ($id > 0 && array_key_exists($id, self::$pages[$siteId])) {
            if (isset($seen[$id]) || count($path) >= 100) {
                throw new RuntimeException('PAGE_HIERARCHY_INVALID');
            }
            $seen[$id] = true; $path[] = $id; $id = self::$pages[$siteId][$id];
        }
        return self::decide($path, self::$rules[$key]);
    }
}
