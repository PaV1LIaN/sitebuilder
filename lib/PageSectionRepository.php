<?php

class PageSectionRepository
{
    protected const FILE_PATH = '/upload/sitebuilder/page_sections.json';

    public static function readAll(): array
    {
        $path = self::filePath();

        if (!file_exists($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        $data = json_decode((string)$raw, true);

        if (!is_array($data)) {
            return [];
        }

        return array_values(array_map([self::class, 'normalize'], $data));
    }

    public static function writeAll(array $items): void
    {
        $path = self::filePath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $items = array_values(array_map([self::class, 'normalize'], $items));

        file_put_contents(
            $path,
            json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    public static function listForPage(int $siteId, int $pageId): array
    {
        $items = array_values(array_filter(self::readAll(), static function ($item) use ($siteId, $pageId) {
            return (int)($item['siteId'] ?? 0) === $siteId
                && (int)($item['pageId'] ?? 0) === $pageId;
        }));

        usort($items, static function ($a, $b) {
            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);

            if ($sortCmp !== 0) {
                return $sortCmp;
            }

            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return $items;
    }

    public static function getById(int $sectionId): ?array
    {
        foreach (self::readAll() as $item) {
            if ((int)($item['id'] ?? 0) === $sectionId) {
                return $item;
            }
        }

        return null;
    }

    public static function ensureDefaultForPage(int $siteId, int $pageId, int $userId = 0): array
    {
        $sections = self::listForPage($siteId, $pageId);

        if (!empty($sections)) {
            self::migratePageBlocksToSection($pageId, (int)$sections[0]['id']);
            return $sections[0];
        }

        $now = date('c');
        $items = self::readAll();

        $section = self::normalize([
            'id' => self::nextId($items),
            'siteId' => $siteId,
            'pageId' => $pageId,
            'type' => 'section',
            'title' => 'Основная секция',
            'sort' => 10,
            'layout' => [
                'container' => 'default',
                'columns' => 1,
                'gap' => 24,
            ],
            'props' => [
                'backgroundColor' => '',
                'backgroundImage' => '',
                'paddingTop' => 40,
                'paddingBottom' => 40,
                'minHeight' => 0,
            ],
            'createdBy' => $userId,
            'createdAt' => $now,
            'updatedBy' => $userId,
            'updatedAt' => $now,
        ]);

        $items[] = $section;
        self::writeAll($items);

        self::migratePageBlocksToSection($pageId, (int)$section['id']);

        return $section;
    }

    public static function create(int $siteId, int $pageId, string $title, array $layout, array $props, int $userId): array
    {
        $title = trim($title);

        if ($title === '') {
            $title = 'Новая секция';
        }

        $items = self::readAll();

        $maxSort = 0;
        foreach ($items as $item) {
            if (
                (int)($item['siteId'] ?? 0) === $siteId &&
                (int)($item['pageId'] ?? 0) === $pageId
            ) {
                $maxSort = max($maxSort, (int)($item['sort'] ?? 0));
            }
        }

        $now = date('c');

        $section = self::normalize([
            'id' => self::nextId($items),
            'siteId' => $siteId,
            'pageId' => $pageId,
            'type' => 'section',
            'title' => $title,
            'sort' => $maxSort > 0 ? $maxSort + 10 : 10,
            'layout' => $layout,
            'props' => $props,
            'createdBy' => $userId,
            'createdAt' => $now,
            'updatedBy' => $userId,
            'updatedAt' => $now,
        ]);

        $items[] = $section;
        self::writeAll($items);

        return $section;
    }

    public static function update(int $sectionId, array $fields, int $userId): array
    {
        $items = self::readAll();
        $updated = null;

        foreach ($items as &$item) {
            if ((int)($item['id'] ?? 0) !== $sectionId) {
                continue;
            }

            if (array_key_exists('title', $fields)) {
                $title = trim((string)$fields['title']);
                $item['title'] = $title !== '' ? $title : 'Секция';
            }

            if (array_key_exists('layout', $fields) && is_array($fields['layout'])) {
                $item['layout'] = array_merge(
                    is_array($item['layout'] ?? null) ? $item['layout'] : [],
                    $fields['layout']
                );
            }

            if (array_key_exists('props', $fields) && is_array($fields['props'])) {
                $item['props'] = array_merge(
                    is_array($item['props'] ?? null) ? $item['props'] : [],
                    $fields['props']
                );
            }

            $item['updatedBy'] = $userId;
            $item['updatedAt'] = date('c');

            $item = self::normalize($item);
            $updated = $item;
            break;
        }
        unset($item);

        if (!$updated) {
            throw new RuntimeException('PAGE_SECTION_NOT_FOUND');
        }

        self::writeAll($items);

        return $updated;
    }

    public static function move(int $sectionId, string $dir, int $userId): bool
    {
        if (!in_array($dir, ['up', 'down'], true)) {
            throw new RuntimeException('INVALID_DIR');
        }

        $items = self::readAll();

        $current = null;
        foreach ($items as $item) {
            if ((int)($item['id'] ?? 0) === $sectionId) {
                $current = $item;
                break;
            }
        }

        if (!$current) {
            throw new RuntimeException('PAGE_SECTION_NOT_FOUND');
        }

        $siteId = (int)$current['siteId'];
        $pageId = (int)$current['pageId'];

        $siblings = [];
        foreach ($items as $index => $item) {
            if (
                (int)($item['siteId'] ?? 0) === $siteId &&
                (int)($item['pageId'] ?? 0) === $pageId
            ) {
                $siblings[] = [
                    'index' => $index,
                    'row' => $item,
                ];
            }
        }

        usort($siblings, static function ($a, $b) {
            $sortCmp = (int)($a['row']['sort'] ?? 500) <=> (int)($b['row']['sort'] ?? 500);

            if ($sortCmp !== 0) {
                return $sortCmp;
            }

            return (int)($a['row']['id'] ?? 0) <=> (int)($b['row']['id'] ?? 0);
        });

        $pos = null;
        foreach ($siblings as $i => $sibling) {
            if ((int)($sibling['row']['id'] ?? 0) === $sectionId) {
                $pos = $i;
                break;
            }
        }

        if ($pos === null) {
            throw new RuntimeException('PAGE_SECTION_NOT_FOUND_IN_SIBLINGS');
        }

        $swapPos = $dir === 'up' ? $pos - 1 : $pos + 1;

        if (!isset($siblings[$swapPos])) {
            return false;
        }

        $aIndex = $siblings[$pos]['index'];
        $bIndex = $siblings[$swapPos]['index'];

        $aSort = (int)($items[$aIndex]['sort'] ?? 500);
        $bSort = (int)($items[$bIndex]['sort'] ?? 500);

        $items[$aIndex]['sort'] = $bSort;
        $items[$aIndex]['updatedBy'] = $userId;
        $items[$aIndex]['updatedAt'] = date('c');

        $items[$bIndex]['sort'] = $aSort;
        $items[$bIndex]['updatedBy'] = $userId;
        $items[$bIndex]['updatedAt'] = date('c');

        self::writeAll($items);

        return true;
    }

    public static function delete(int $sectionId, int $userId): void
    {
        $items = self::readAll();

        $section = null;
        foreach ($items as $item) {
            if ((int)($item['id'] ?? 0) === $sectionId) {
                $section = $item;
                break;
            }
        }

        if (!$section) {
            throw new RuntimeException('PAGE_SECTION_NOT_FOUND');
        }

        $siteId = (int)$section['siteId'];
        $pageId = (int)$section['pageId'];

        $sections = self::listForPage($siteId, $pageId);

        if (count($sections) <= 1) {
            throw new RuntimeException('CANNOT_DELETE_LAST_SECTION');
        }

        $targetSectionId = 0;
        foreach ($sections as $s) {
            if ((int)$s['id'] !== $sectionId) {
                $targetSectionId = (int)$s['id'];
                break;
            }
        }

        if ($targetSectionId <= 0) {
            throw new RuntimeException('TARGET_SECTION_NOT_FOUND');
        }

        self::moveBlocksFromSection($sectionId, $targetSectionId, $userId);

        $items = array_values(array_filter($items, static function ($item) use ($sectionId) {
            return (int)($item['id'] ?? 0) !== $sectionId;
        }));

        self::writeAll($items);
    }

    public static function assignBlock(int $blockId, int $sectionId, int $column, int $userId): array
    {
        $section = self::getById($sectionId);
    
        if (!$section) {
            throw new RuntimeException('PAGE_SECTION_NOT_FOUND');
        }
    
        $column = max(1, min(4, $column));
    
        $blocks = sb_read_blocks();
        $updated = null;
    
        foreach ($blocks as &$block) {
            if ((int)($block['id'] ?? 0) !== $blockId) {
                continue;
            }
    
            if ((int)($block['pageId'] ?? 0) !== (int)$section['pageId']) {
                throw new RuntimeException('BLOCK_AND_SECTION_PAGE_MISMATCH');
            }
    
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
    
            $props['sectionId'] = $sectionId;
            $props['column'] = $column;
            $props['_placement'] = [
                'sectionId' => $sectionId,
                'column' => $column,
            ];
    
            $block['sectionId'] = $sectionId;
            $block['column'] = $column;
            $block['props'] = $props;
            $block['updatedBy'] = $userId;
            $block['updatedAt'] = date('c');
    
            $updated = $block;
            break;
        }
        unset($block);
    
        if (!$updated) {
            throw new RuntimeException('BLOCK_NOT_FOUND');
        }
    
        sb_write_blocks($blocks);
    
        return $updated;
    }

    protected static function moveBlocksFromSection(int $fromSectionId, int $toSectionId, int $userId): void
    {
        $blocks = sb_read_blocks();
    
        foreach ($blocks as &$block) {
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
    
            $blockSectionId = (int)($block['sectionId'] ?? 0);
    
            if ($blockSectionId <= 0) {
                $blockSectionId = (int)($props['sectionId'] ?? 0);
            }
    
            if ($blockSectionId <= 0 && is_array($props['_placement'] ?? null)) {
                $blockSectionId = (int)($props['_placement']['sectionId'] ?? 0);
            }
    
            if ($blockSectionId !== $fromSectionId) {
                continue;
            }
    
            $props['sectionId'] = $toSectionId;
            $props['column'] = 1;
            $props['_placement'] = [
                'sectionId' => $toSectionId,
                'column' => 1,
            ];
    
            $block['sectionId'] = $toSectionId;
            $block['column'] = 1;
            $block['props'] = $props;
            $block['updatedBy'] = $userId;
            $block['updatedAt'] = date('c');
        }
        unset($block);
    
        sb_write_blocks($blocks);
    }

    protected static function migratePageBlocksToSection(int $pageId, int $sectionId): void
    {
        if (!function_exists('sb_read_blocks') || !function_exists('sb_write_blocks')) {
            return;
        }
    
        $blocks = sb_read_blocks();
        $changed = false;
    
        foreach ($blocks as &$block) {
            if ((int)($block['pageId'] ?? 0) !== $pageId) {
                continue;
            }
    
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
    
            $currentSectionId = (int)($block['sectionId'] ?? 0);
    
            if ($currentSectionId <= 0) {
                $currentSectionId = (int)($props['sectionId'] ?? 0);
            }
    
            if ($currentSectionId <= 0 && is_array($props['_placement'] ?? null)) {
                $currentSectionId = (int)($props['_placement']['sectionId'] ?? 0);
            }
    
            if ($currentSectionId > 0) {
                continue;
            }
    
            $props['sectionId'] = $sectionId;
            $props['column'] = 1;
            $props['_placement'] = [
                'sectionId' => $sectionId,
                'column' => 1,
            ];
    
            $block['sectionId'] = $sectionId;
            $block['column'] = 1;
            $block['props'] = $props;
    
            $changed = true;
        }
        unset($block);
    
        if ($changed) {
            sb_write_blocks($blocks);
        }
    }

    protected static function normalize(array $item): array
    {
        $columns = (int)($item['layout']['columns'] ?? 1);
        $columns = max(1, min(4, $columns));

        $layout = is_array($item['layout'] ?? null) ? $item['layout'] : [];
        $props = is_array($item['props'] ?? null) ? $item['props'] : [];

        return [
            'id' => (int)($item['id'] ?? 0),
            'siteId' => (int)($item['siteId'] ?? 0),
            'pageId' => (int)($item['pageId'] ?? 0),
            'type' => (string)($item['type'] ?? 'section'),
            'title' => (string)($item['title'] ?? 'Секция'),
            'sort' => (int)($item['sort'] ?? 500),
            'layout' => array_merge([
                'container' => 'default',
                'columns' => $columns,
                'gap' => 24,
            ], $layout),
            'props' => array_merge([
                'backgroundColor' => '',
                'backgroundImage' => '',
                'paddingTop' => 40,
                'paddingBottom' => 40,
                'minHeight' => 0,
            ], $props),
            'createdBy' => (int)($item['createdBy'] ?? 0),
            'createdAt' => (string)($item['createdAt'] ?? ''),
            'updatedBy' => (int)($item['updatedBy'] ?? 0),
            'updatedAt' => (string)($item['updatedAt'] ?? ''),
        ];
    }

    protected static function nextId(array $items): int
    {
        $max = 0;

        foreach ($items as $item) {
            $max = max($max, (int)($item['id'] ?? 0));
        }

        return $max + 1;
    }

    protected static function filePath(): string
    {
        return rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . self::FILE_PATH;
    }
}