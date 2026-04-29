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

    public static function save(int $blockId, array $settings): bool
    {
        $current = self::getByBlockId($blockId);
        $merged = array_merge($current, $settings);
        $normalized = DiskSitebuilderBridge::normalizeDiskProps($merged);

        return DiskSitebuilderBridge::saveBlockProps($blockId, $normalized);
    }

    public static function ensureExistsForBlock(int $blockId, int $siteId, int $pageId, ?int $createdBy = null): array
    {
        $block = DiskSitebuilderBridge::getBlockById($blockId);
        if (!$block) {
            throw new RuntimeException('BLOCK_NOT_FOUND');
        }

        $props = $block['props'] ?? [];
        $normalized = DiskSitebuilderBridge::normalizeDiskProps($props);

        if (($block['props'] ?? []) !== $normalized) {
            DiskSitebuilderBridge::saveBlockProps($blockId, $normalized);
        }

        return $normalized;
    }
}