<?php

final class GlobalBlockService
{
    private static ?array $templateCache = null;

    public static function list(int $siteId): array
    {
        $items = array_values(array_filter(
            self::templates(),
            static function (array $item) use ($siteId): bool {
                return (string)($item['kind'] ?? '') === 'global_block'
                    && (int)($item['siteId'] ?? 0) === $siteId;
            }
        ));

        usort($items, static function (array $a, array $b): int {
            $aTime = strtotime((string)($a['updatedAt'] ?? $a['createdAt'] ?? '')) ?: 0;
            $bTime = strtotime((string)($b['updatedAt'] ?? $b['createdAt'] ?? '')) ?: 0;
            return $aTime === $bTime
                ? (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0)
                : $bTime <=> $aTime;
        });

        $usage = self::usageMap($siteId);

        return array_map(static function (array $item) use ($usage): array {
            $record = self::publicRecord($item);
            $record['usageCount'] = (int)($usage[(int)($record['id'] ?? 0)] ?? 0);
            return $record;
        }, $items);
    }

    public static function get(int $globalBlockId, ?int $siteId = null): ?array
    {
        foreach (self::templates() as $item) {
            if ((string)($item['kind'] ?? '') !== 'global_block') {
                continue;
            }
            if ((int)($item['id'] ?? 0) !== $globalBlockId) {
                continue;
            }
            if ($siteId !== null && (int)($item['siteId'] ?? 0) !== $siteId) {
                return null;
            }
            return $item;
        }
        return null;
    }

    public static function createFromBlock(int $siteId, int $blockId, string $name, int $userId): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('NAME_REQUIRED');
        }

        $block = self::blockInSite($siteId, $blockId);
        $now = date('c');
        $snapshot = self::snapshotBlock($block);

        $record = sb_mutate_json_file(
            'templates.json',
            static function (array &$items) use ($siteId, $name, $userId, $now, $snapshot): array {
                $record = [
                    'id' => sb_next_template_id($items),
                    'kind' => 'global_block',
                    'siteId' => $siteId,
                    'name' => $name,
                    'payload' => ['block' => $snapshot],
                    'createdBy' => $userId,
                    'createdAt' => $now,
                    'updatedBy' => $userId,
                    'updatedAt' => $now,
                ];
                $items[] = $record;
                return $record;
            },
            'Cannot save global block'
        );

        self::invalidateTemplateCache();
        self::rollbackRemoveRecord((int)($record['id'] ?? 0), $siteId);
        return self::publicRecord($record);
    }

    public static function updateFromBlock(int $siteId, int $globalBlockId, int $blockId, int $userId): array
    {
        $before = self::get($globalBlockId, $siteId);
        if (!$before) {
            throw new RuntimeException('GLOBAL_BLOCK_NOT_FOUND');
        }
        $block = self::blockInSite($siteId, $blockId);
        $snapshot = self::snapshotBlock($block);

        $record = sb_mutate_json_file(
            'templates.json',
            static function (array &$items) use ($siteId, $globalBlockId, $userId, $snapshot): array {
                foreach ($items as &$item) {
                    if (
                        (string)($item['kind'] ?? '') !== 'global_block'
                        || (int)($item['id'] ?? 0) !== $globalBlockId
                        || (int)($item['siteId'] ?? 0) !== $siteId
                    ) {
                        continue;
                    }
                    $item['payload'] = ['block' => $snapshot];
                    $item['updatedBy'] = $userId;
                    $item['updatedAt'] = date('c');
                    $updated = $item;
                    unset($item);
                    return $updated;
                }
                unset($item);
                throw new RuntimeException('GLOBAL_BLOCK_NOT_FOUND');
            },
            'Cannot update global block'
        );

        self::invalidateTemplateCache();
        self::rollbackRestoreRecord($before);
        return self::publicRecord($record);
    }

    public static function rename(int $siteId, int $globalBlockId, string $name, int $userId): array
    {
        $before = self::get($globalBlockId, $siteId);
        if (!$before) {
            throw new RuntimeException('GLOBAL_BLOCK_NOT_FOUND');
        }
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('NAME_REQUIRED');
        }

        $record = sb_mutate_json_file(
            'templates.json',
            static function (array &$items) use ($siteId, $globalBlockId, $name, $userId): array {
                foreach ($items as &$item) {
                    if (
                        (string)($item['kind'] ?? '') !== 'global_block'
                        || (int)($item['id'] ?? 0) !== $globalBlockId
                        || (int)($item['siteId'] ?? 0) !== $siteId
                    ) {
                        continue;
                    }
                    $item['name'] = $name;
                    $item['updatedBy'] = $userId;
                    $item['updatedAt'] = date('c');
                    $updated = $item;
                    unset($item);
                    return $updated;
                }
                unset($item);
                throw new RuntimeException('GLOBAL_BLOCK_NOT_FOUND');
            },
            'Cannot rename global block'
        );

        self::invalidateTemplateCache();
        self::rollbackRestoreRecord($before);
        return self::publicRecord($record);
    }

    public static function delete(int $siteId, int $globalBlockId): void
    {
        $before = self::get($globalBlockId, $siteId);
        if (!$before) {
            throw new RuntimeException('GLOBAL_BLOCK_NOT_FOUND');
        }
        if (self::usageCount($siteId, $globalBlockId) > 0) {
            throw new RuntimeException('GLOBAL_BLOCK_IN_USE');
        }

        sb_mutate_json_file(
            'templates.json',
            static function (array &$items) use ($siteId, $globalBlockId): void {
                $before = count($items);
                $items = array_values(array_filter(
                    $items,
                    static function (array $item) use ($siteId, $globalBlockId): bool {
                        return !(
                            (string)($item['kind'] ?? '') === 'global_block'
                            && (int)($item['siteId'] ?? 0) === $siteId
                            && (int)($item['id'] ?? 0) === $globalBlockId
                        );
                    }
                ));
                if (count($items) === $before) {
                    throw new RuntimeException('GLOBAL_BLOCK_NOT_FOUND');
                }
            },
            'Cannot delete global block'
        );
        self::invalidateTemplateCache();
        self::rollbackRestoreRecord($before);
    }

    public static function usageCount(int $siteId, int $globalBlockId): int
    {
        return (int)(self::usageMap($siteId)[$globalBlockId] ?? 0);
    }

    public static function exportForSite(int $siteId): array
    {
        $items = [];
        foreach (self::templates() as $item) {
            if (
                (string)($item['kind'] ?? '') !== 'global_block'
                || (int)($item['siteId'] ?? 0) !== $siteId
            ) {
                continue;
            }
            $block = is_array($item['payload']['block'] ?? null) ? $item['payload']['block'] : [];
            if (!$block || (string)($block['type'] ?? '') === 'global') {
                continue;
            }
            $items[] = [
                'oldId' => (int)($item['id'] ?? 0),
                'name' => (string)($item['name'] ?? 'Глобальный блок'),
                'block' => self::snapshotBlock($block),
            ];
        }
        return $items;
    }

    public static function importForSite(int $siteId, array $definitions, int $userId): array
    {
        $definitions = array_values(array_filter($definitions, static function ($item): bool {
            return is_array($item)
                && (int)($item['oldId'] ?? 0) > 0
                && is_array($item['block'] ?? null)
                && (string)($item['block']['type'] ?? '') !== 'global';
        }));
        if (!$definitions) {
            return [];
        }

        $map = sb_mutate_json_file(
            'templates.json',
            static function (array &$items) use ($siteId, $definitions, $userId): array {
                $map = [];
                $now = date('c');
                foreach ($definitions as $definition) {
                    $oldId = (int)$definition['oldId'];
                    $newId = sb_next_template_id($items);
                    $items[] = [
                        'id' => $newId,
                        'kind' => 'global_block',
                        'siteId' => $siteId,
                        'name' => trim((string)($definition['name'] ?? '')) ?: 'Глобальный блок',
                        'payload' => ['block' => self::snapshotBlock((array)$definition['block'])],
                        'createdBy' => $userId,
                        'createdAt' => $now,
                        'updatedBy' => $userId,
                        'updatedAt' => $now,
                    ];
                    $map[$oldId] = $newId;
                }
                return $map;
            },
            'Cannot import global blocks'
        );
        self::invalidateTemplateCache();
        return $map;
    }

    public static function deleteForSite(int $siteId): int
    {
        $deleted = sb_mutate_json_file(
            'templates.json',
            static function (array &$items) use ($siteId): int {
                $before = count($items);
                $items = array_values(array_filter($items, static function (array $item) use ($siteId): bool {
                    return !(
                        (string)($item['kind'] ?? '') === 'global_block'
                        && (int)($item['siteId'] ?? 0) === $siteId
                    );
                }));
                return $before - count($items);
            },
            'Cannot clean global blocks'
        );
        self::invalidateTemplateCache();
        return $deleted;
    }

    private static function usageMap(int $siteId): array
    {
        $usage = [];
        $pageIds = [];
        foreach (sb_read_pages() as $page) {
            if ((int)($page['siteId'] ?? 0) === $siteId) {
                $pageIds[(int)($page['id'] ?? 0)] = true;
            }
        }
        foreach (sb_read_blocks() as $block) {
            if (!isset($pageIds[(int)($block['pageId'] ?? 0)])) {
                continue;
            }
            self::countReference($usage, $block);
        }
        if (function_exists('sb_find_layout')) {
            $layout = sb_find_layout($siteId);
            if (is_array($layout)) {
                foreach ((array)($layout['zones'] ?? []) as $zoneBlocks) {
                    foreach ((array)$zoneBlocks as $block) {
                        if (is_array($block)) {
                            self::countReference($usage, $block);
                        }
                    }
                }
            }
        }
        return $usage;
    }

    private static function countReference(array &$usage, array $block): void
    {
        if ((string)($block['type'] ?? '') !== 'global') {
            return;
        }
        $content = is_array($block['content'] ?? null) ? $block['content'] : [];
        $id = (int)($content['globalBlockId'] ?? 0);
        if ($id > 0) {
            $usage[$id] = (int)($usage[$id] ?? 0) + 1;
        }
    }

    public static function publicRecord(array $record): array
    {
        $block = is_array($record['payload']['block'] ?? null) ? $record['payload']['block'] : [];
        return [
            'id' => (int)($record['id'] ?? 0),
            'siteId' => (int)($record['siteId'] ?? 0),
            'name' => (string)($record['name'] ?? 'Глобальный блок'),
            'block' => $block,
            'blockType' => (string)($block['type'] ?? ''),
            'createdBy' => (int)($record['createdBy'] ?? 0),
            'createdAt' => (string)($record['createdAt'] ?? ''),
            'updatedBy' => (int)($record['updatedBy'] ?? 0),
            'updatedAt' => (string)($record['updatedAt'] ?? ''),
        ];
    }

    private static function rollbackRemoveRecord(int $globalBlockId, int $siteId): void
    {
        if ($globalBlockId <= 0 || !function_exists('sb_db_after_rollback')) {
            return;
        }
        sb_db_after_rollback(static function () use ($globalBlockId, $siteId): void {
            try {
                sb_mutate_json_file(
                    'templates.json',
                    static function (array &$items) use ($globalBlockId, $siteId): void {
                        $items = array_values(array_filter($items, static function (array $item) use ($globalBlockId, $siteId): bool {
                            return !(
                                (string)($item['kind'] ?? '') === 'global_block'
                                && (int)($item['id'] ?? 0) === $globalBlockId
                                && (int)($item['siteId'] ?? 0) === $siteId
                            );
                        }));
                    },
                    'Cannot rollback global block create'
                );
                self::invalidateTemplateCache();
            } catch (Throwable $e) {
                error_log('SiteBuilder global block create rollback failed: ' . $e->getMessage());
            }
        });
    }

    private static function rollbackRestoreRecord(array $record): void
    {
        if (!function_exists('sb_db_after_rollback')) {
            return;
        }
        sb_db_after_rollback(static function () use ($record): void {
            try {
                sb_mutate_json_file(
                    'templates.json',
                    static function (array &$items) use ($record): void {
                        $recordId = (int)($record['id'] ?? 0);
                        foreach ($items as $index => $item) {
                            if (
                                (string)($item['kind'] ?? '') === 'global_block'
                                && (int)($item['id'] ?? 0) === $recordId
                            ) {
                                $items[$index] = $record;
                                return;
                            }
                        }
                        $items[] = $record;
                    },
                    'Cannot rollback global block mutation'
                );
                self::invalidateTemplateCache();
            } catch (Throwable $e) {
                error_log('SiteBuilder global block rollback failed: ' . $e->getMessage());
            }
        });
    }

    private static function templates(): array
    {
        if (self::$templateCache === null) {
            self::$templateCache = sb_read_templates();
        }
        return self::$templateCache;
    }

    private static function invalidateTemplateCache(): void
    {
        self::$templateCache = null;
    }

    private static function blockInSite(int $siteId, int $blockId): array
    {
        $block = sb_find_block($blockId);
        if (!$block) {
            throw new RuntimeException('BLOCK_NOT_FOUND');
        }
        $page = sb_find_page((int)($block['pageId'] ?? 0));
        if (!$page || (int)($page['siteId'] ?? 0) !== $siteId) {
            throw new RuntimeException('BLOCK_NOT_IN_SITE');
        }
        if ((string)($block['type'] ?? '') === 'global') {
            throw new RuntimeException('GLOBAL_BLOCK_SOURCE_REQUIRED');
        }
        return $block;
    }

    private static function snapshotBlock(array $block): array
    {
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        unset($props['sectionId'], $props['column'], $props['_placement']);

        return [
            'type' => (string)($block['type'] ?? 'text'),
            'content' => is_array($block['content'] ?? null) ? $block['content'] : [],
            'props' => $props,
        ];
    }
}
