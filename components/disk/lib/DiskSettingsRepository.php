<?php

class DiskSettingsRepository
{
    public static function getByBlockId(int $blockId): array
    {
        $block = DiskSitebuilderBridge::getBlockById($blockId);
        if (!$block) {
            throw new RuntimeException('BLOCK_NOT_FOUND');
        }

        return DiskSitebuilderBridge::normalizeDiskProps($block['props'] ?? []);
    }

    public static function createDefault(array $data): bool
    {
        $blockId = (int)($data['block_id'] ?? 0);
        if ($blockId <= 0) {
            throw new RuntimeException('INVALID_BLOCK_ID');
        }

        $default = DiskSitebuilderBridge::normalizeDiskProps([]);
        return DiskSitebuilderBridge::saveBlockProps($blockId, $default);
    }

    public static function save(
        int $blockId,
        array $settings,
        ?int $expectedVersion = null,
        int $userId = 0
    ): bool
    {
        $current = self::getByBlockId($blockId);
        $merged = array_merge($current, $settings);
        $normalized = DiskSitebuilderBridge::normalizeDiskProps($merged);

        return DiskSitebuilderBridge::saveBlockProps(
            $blockId,
            $normalized,
            $expectedVersion,
            $userId
        );
    }

    public static function ensureExistsForBlock(int $blockId, int $siteId, int $pageId, ?int $createdBy = null): array
    {
        /*
         * A Disk bootstrap/list/view/delete request must never rewrite the
         * block's props or advance its revision just to fill defaults.
         * The previous implementation could invoke RevisionService::saveBlock()
         * on page view for a non-administrator. When that write failed,
         * class.php caught it and rendered canView=false even for a real
         * Bitrix admin. Return the normalized settings read-only instead.
         *
         * Persist actual settings only through save() and createDefault().
         */
        return self::getByBlockId($blockId);
    }
}