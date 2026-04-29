<?php

class BlockRepository
{
    public static function getById(int $blockId): ?array
    {
        return DiskSitebuilderBridge::getBlockById($blockId);
    }

    public static function getDiskBlockByContext(int $siteId, int $pageId, int $blockId): ?array
    {
        $block = self::getById($blockId);
        if (!$block) {
            return null;
        }

        if ((string)($block['type'] ?? '') !== 'disk') {
            return null;
        }

        if ((int)($block['pageId'] ?? 0) !== $pageId) {
            return null;
        }

        $page = DiskSitebuilderBridge::getPageById($pageId);
        if (!$page) {
            return null;
        }

        if ((int)($page['siteId'] ?? 0) !== $siteId) {
            return null;
        }

        return $block;
    }

    public static function updateSettingsJson(int $blockId, array $settings): bool
    {
        return DiskSitebuilderBridge::saveBlockProps($blockId, $settings);
    }
}