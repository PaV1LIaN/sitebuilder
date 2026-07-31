<?php

require_once __DIR__ . '/OutboxService.php';
require_once __DIR__ . '/RevisionService.php';
require_once __DIR__ . '/GlobalBlockService.php';

class SiteTemplateService
{
    public static function listSiteTemplates(): array
    {
        $items = array_values(array_filter(sb_read_templates(), static function ($template) {
            return (string)($template['kind'] ?? 'site') === 'site';
        }));

        usort($items, static function ($a, $b) {
            $aTime = strtotime((string)($a['updatedAt'] ?? $a['createdAt'] ?? '')) ?: 0;
            $bTime = strtotime((string)($b['updatedAt'] ?? $b['createdAt'] ?? '')) ?: 0;

            if ($aTime !== $bTime) {
                return $bTime <=> $aTime;
            }

            return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
        });

        return array_map([self::class, 'publicTemplateRecord'], $items);
    }

    public static function getTemplate(int $templateId): ?array
    {
        foreach (sb_read_templates() as $template) {
            if ((int)($template['id'] ?? 0) === $templateId) {
                return $template;
            }
        }

        return null;
    }

    public static function createFromSite(int $siteId, string $name, string $description, int $userId): array
    {
        $site = sb_find_site($siteId);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('NAME_REQUIRED');
        }

        $payloadSnapshot = self::buildSitePayload($siteId);
        $now = date('c');

        $template = sb_mutate_json_file(
            'templates.json',
            static function (array &$templates) use (
                $name,
                $description,
                $siteId,
                $site,
                $payloadSnapshot,
                $userId,
                $now
            ): array {
                $template = [
                    'id' => sb_next_template_id($templates),
                    'kind' => 'site',
                    'name' => $name,
                    'description' => trim($description),
                    'sourceSiteId' => $siteId,
                    'sourceSiteName' => (string)($site['name'] ?? ''),
                    'payload' => $payloadSnapshot,
                    'createdBy' => $userId,
                    'createdAt' => $now,
                    'updatedBy' => $userId,
                    'updatedAt' => $now,
                ];

                $templates[] = $template;
                return $template;
            },
            'Cannot save templates.json'
        );

        return self::publicTemplateRecord($template);
    }

    /**
     * Формирует переносимый снимок содержимого сайта без идентификаторов
     * внешней группы и папки Битрикс.Диска.
     */
    public static function buildSitePayload(int $siteId): array
    {
        $site = sb_find_site($siteId);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $pages = self::pagesForSite($siteId);
        $pageIds = array_fill_keys(array_map(static function ($page) {
            return (int)($page['id'] ?? 0);
        }, $pages), true);

        $blocks = [];
        foreach (sb_read_blocks() as $block) {
            $pageId = (int)($block['pageId'] ?? 0);
            if (!isset($pageIds[$pageId])) {
                continue;
            }
            $blocks[] = self::prepareBlockForSnapshot($block);
        }

        $sections = self::sectionsForSite($siteId, $pages);
        $menus = self::menusForSite($siteId);
        /* Снимок не должен скрыто создавать layout в исходном сайте. */
        $layout = function_exists('sb_find_layout') ? sb_find_layout($siteId) : null;
        if (!is_array($layout)) {
            $layout = function_exists('sb_layout_default_record')
                ? sb_layout_default_record($siteId)
                : ['siteId' => $siteId, 'settings' => [], 'zones' => []];
        }

        return [
            'site' => self::prepareSiteForSnapshot($site),
            'pages' => array_map([self::class, 'preparePageForSnapshot'], $pages),
            'sections' => array_map([self::class, 'prepareSectionForSnapshot'], $sections),
            'blocks' => $blocks,
            'globalBlocks' => GlobalBlockService::exportForSite($siteId),
            'layout' => self::prepareLayoutForSnapshot($layout),
            'menus' => array_map([self::class, 'prepareMenuForSnapshot'], $menus),
        ];
    }

    public static function delete(int $templateId): void
    {
        sb_mutate_json_file(
            'templates.json',
            static function (array &$templates) use ($templateId): void {
                $before = count($templates);
                $templates = array_values(array_filter(
                    $templates,
                    static function ($template) use ($templateId): bool {
                        return (int)($template['id'] ?? 0) !== $templateId;
                    }
                ));

                if (count($templates) === $before) {
                    throw new RuntimeException('TEMPLATE_NOT_FOUND');
                }
            },
            'Cannot update templates.json'
        );
    }

    public static function rename(int $templateId, string $name, string $description, int $userId): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('NAME_REQUIRED');
        }

        $updated = sb_mutate_json_file(
            'templates.json',
            static function (array &$templates) use (
                $templateId,
                $name,
                $description,
                $userId
            ): array {
                foreach ($templates as &$template) {
                    if ((int)($template['id'] ?? 0) !== $templateId) {
                        continue;
                    }

                    $template['name'] = $name;
                    $template['description'] = trim($description);
                    $template['updatedBy'] = $userId;
                    $template['updatedAt'] = date('c');
                    $updated = $template;
                    unset($template);

                    return $updated;
                }
                unset($template);

                throw new RuntimeException('TEMPLATE_NOT_FOUND');
            },
            'Cannot update templates.json'
        );

        return self::publicTemplateRecord($updated);
    }

    public static function createSiteFromTemplate(int $templateId, string $siteName, string $slug, int $sectionId, int $userId): array
    {
        $template = self::getTemplate($templateId);
        if (!$template || (string)($template['kind'] ?? 'site') !== 'site') {
            throw new RuntimeException('TEMPLATE_NOT_FOUND');
        }

        $payload = is_array($template['payload'] ?? null) ? $template['payload'] : [];
        if (trim($siteName) === '' && trim((string)($payload['site']['name'] ?? '')) === '') {
            $siteName = (string)($template['name'] ?? '');
        }
        $result = self::createSiteFromPayload(
            $payload,
            $siteName,
            $slug,
            $sectionId,
            $userId
        );
        $result['template'] = self::publicTemplateRecord($template);
        return $result;
    }

    /**
     * Создаёт новый сайт из переносимого снимка. Метод используется как
     * шаблонами, так и резервным восстановлением. Существующий сайт никогда
     * не перезаписывается.
     */
    public static function createSiteFromPayload(
        array $payload,
        string $siteName,
        string $slug,
        int $sectionId,
        int $userId
    ): array {
        $snapshotSite = is_array($payload['site'] ?? null) ? $payload['site'] : [];

        $siteName = trim($siteName);
        if ($siteName === '') {
            $siteName = (string)($snapshotSite['name'] ?? 'Новый сайт');
        }
        if ($siteName === '') {
            throw new RuntimeException('NAME_REQUIRED');
        }

        if (function_exists('sb_site_handler_validate_section')) {
            sb_site_handler_validate_section($sectionId);
        } elseif ($sectionId < 0) {
            $sectionId = 0;
        }

        $sites = sb_read_sites();
        $siteId = RevisionService::nextEntityId(RevisionService::ENTITY_SITE);
        $now = date('c');
        $slug = trim($slug);
        $slug = $slug === '' ? sb_slugify($siteName) : sb_slugify($slug);
        $slug = self::uniqueSiteSlug($slug, $sites);

        $site = [
            'id' => $siteId,
            'name' => $siteName,
            'slug' => $slug,
            'sectionId' => $sectionId,
            'createdBy' => $userId,
            'createdAt' => $now,
            'updatedBy' => $userId,
            'updatedAt' => $now,
            'homePageId' => 0,
            'diskFolderId' => 0,
            'topMenuId' => 0,
            'bitrixGroupId' => 0,
            'bitrixGroupCreatedBy' => 0,
            'bitrixGroupCreatedAt' => '',
            'settings' => self::sanitizePortableSettings(
                is_array($snapshotSite['settings'] ?? null) ? $snapshotSite['settings'] : []
            ),
            'layout' => is_array($snapshotSite['layout'] ?? null) ? $snapshotSite['layout'] : [],
        ];

        sb_write_sites([$site]);
        $globalBlockIdMap = GlobalBlockService::importForSite(
            $siteId,
            is_array($payload['globalBlocks'] ?? null) ? $payload['globalBlocks'] : [],
            $userId
        );
        if ($globalBlockIdMap && function_exists('sb_db_after_rollback')) {
            sb_db_after_rollback(static function () use ($siteId): void {
                try {
                    GlobalBlockService::deleteForSite($siteId);
                } catch (Throwable $e) {
                    error_log('SiteBuilder global block rollback cleanup failed: ' . $e->getMessage());
                }
            });
        }
        $pageIdMap = self::copyPages($siteId, $payload, $userId);
        $sectionIdMap = self::copySections($siteId, $pageIdMap, $payload, $userId);
        self::copyBlocks($pageIdMap, $payload, $userId, $sectionIdMap, $globalBlockIdMap);

        $homeOldId = (int)($snapshotSite['homePageId'] ?? 0);
        if ($homeOldId > 0 && isset($pageIdMap[$homeOldId])) {
            self::updateSiteField($siteId, 'homePageId', (int)$pageIdMap[$homeOldId], $userId);
        } else {
            $firstNewPageId = !empty($pageIdMap) ? (int)reset($pageIdMap) : 0;
            if ($firstNewPageId > 0) {
                self::updateSiteField($siteId, 'homePageId', $firstNewPageId, $userId);
            }
        }

        self::copyLayout($siteId, $payload, $userId, $globalBlockIdMap);
        $menuIdMap = self::copyMenus($siteId, $pageIdMap, $payload, $userId, $snapshotSite);
        self::grantOwnerAccess($siteId, $userId, $now);
        $provisioningJobs = OutboxService::enqueueSiteProvisioning($siteId, $userId);

        return [
            'site' => sb_find_site($siteId) ?: $site,
            'pageIdMap' => $pageIdMap,
            'sectionIdMap' => $sectionIdMap,
            'menuIdMap' => $menuIdMap,
            'globalBlockIdMap' => $globalBlockIdMap,
            'provisioningQueued' => true,
            'provisioningJobs' => $provisioningJobs,
        ];
    }

    public static function publicTemplateRecord(array $template): array
    {
        $payload = is_array($template['payload'] ?? null) ? $template['payload'] : [];
        $pages = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];
        $blocks = is_array($payload['blocks'] ?? null) ? $payload['blocks'] : [];

        return [
            'id' => (int)($template['id'] ?? 0),
            'kind' => (string)($template['kind'] ?? 'site'),
            'name' => (string)($template['name'] ?? ''),
            'description' => (string)($template['description'] ?? ''),
            'sourceSiteId' => (int)($template['sourceSiteId'] ?? 0),
            'sourceSiteName' => (string)($template['sourceSiteName'] ?? ''),
            'pagesCount' => count($pages),
            'blocksCount' => count($blocks),
            'createdBy' => (int)($template['createdBy'] ?? 0),
            'createdAt' => (string)($template['createdAt'] ?? ''),
            'updatedBy' => (int)($template['updatedBy'] ?? 0),
            'updatedAt' => (string)($template['updatedAt'] ?? ''),
        ];
    }

    protected static function prepareSiteForSnapshot(array $site): array
    {
        return [
            'name' => (string)($site['name'] ?? ''),
            'slug' => (string)($site['slug'] ?? ''),
            'homePageId' => (int)($site['homePageId'] ?? 0),
            'topMenuId' => (int)($site['topMenuId'] ?? 0),
            'settings' => self::sanitizePortableSettings(
                is_array($site['settings'] ?? null) ? $site['settings'] : []
            ),
            'layout' => is_array($site['layout'] ?? null) ? $site['layout'] : [],
        ];
    }

    protected static function preparePageForSnapshot(array $page): array
    {
        return [
            'oldId' => (int)($page['id'] ?? 0),
            'title' => (string)($page['title'] ?? ''),
            'slug' => (string)($page['slug'] ?? ''),
            'parentId' => (int)($page['parentId'] ?? 0),
            'sort' => (int)($page['sort'] ?? 500),
            'status' => (string)($page['status'] ?? 'draft'),
            'publishedAt' => !empty($page['publishedAt']) ? (string)$page['publishedAt'] : null,
            'seo' => is_array($page['seo'] ?? null) ? $page['seo'] : [],
        ];
    }

    protected static function prepareBlockForSnapshot(array $block): array
    {
        $rawProps = is_array($block['props'] ?? null) ? $block['props'] : [];
        $placement = is_array($rawProps['_placement'] ?? null) ? $rawProps['_placement'] : [];
    
        $sectionId = (int)($block['sectionId'] ?? 0);
    
        if ($sectionId <= 0) {
            $sectionId = (int)($rawProps['sectionId'] ?? 0);
        }
    
        if ($sectionId <= 0) {
            $sectionId = (int)($placement['sectionId'] ?? 0);
        }
    
        $column = (int)($block['column'] ?? 0);
    
        if ($column <= 0) {
            $column = (int)($rawProps['column'] ?? 0);
        }
    
        if ($column <= 0) {
            $column = (int)($placement['column'] ?? 0);
        }
    
        if ($column <= 0) {
            $column = 1;
        }
    
        $block = sb_normalize_block_record($block);
    
        return [
            'oldId' => (int)($block['id'] ?? 0),
            'oldPageId' => (int)($block['pageId'] ?? 0),
            'oldSectionId' => $sectionId,
            'sectionId' => $sectionId,
            'column' => max(1, min(4, $column)),
            'type' => (string)($block['type'] ?? 'text'),
            'sort' => (int)($block['sort'] ?? 500),
            'content' => self::sanitizeDiskData($block['content'] ?? []),
            'props' => self::sanitizeDiskData($block['props'] ?? []),
        ];
    }

    protected static function prepareLayoutForSnapshot(array $layout): array
    {
        $layout = sb_normalize_layout_record($layout);
        $layout['siteId'] = 0;

        foreach (['header', 'footer', 'left', 'right'] as $zone) {
            $blocks = [];

            foreach (($layout['zones'][$zone] ?? []) as $block) {
                $block = sb_normalize_block_record($block);
                $block['content'] = self::sanitizeDiskData($block['content'] ?? []);
                $block['props'] = self::sanitizeDiskData($block['props'] ?? []);
                $blocks[] = $block;
            }

            $layout['zones'][$zone] = $blocks;
        }

        return $layout;
    }

    protected static function prepareMenuForSnapshot(array $menu): array
    {
        $menu = sb_normalize_menu_record($menu);
        $menu['oldId'] = (int)($menu['id'] ?? 0);

        unset(
            $menu['id'],
            $menu['siteId'],
            $menu['createdBy'],
            $menu['createdAt'],
            $menu['updatedBy'],
            $menu['updatedAt']
        );

        return $menu;
    }

    protected static function sanitizePortableSettings(array $settings): array
    {
        $settings = self::sanitizeDiskData($settings);
        if (!is_array($settings)) {
            $settings = [];
        }

        /* Брендинговые CFile ID относятся к исходному порталу и не переносимы. */
        $settings['logoFileId'] = 0;
        $settings['backgroundFileId'] = 0;
        unset($settings['logoUrl'], $settings['backgroundUrl']);

        return $settings;
    }

    protected static function sanitizeDiskData($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $forbidden = [
            'rootFolderId' => true,
            'currentFolderId' => true,
            'siteRootFolderId' => true,
            'blockRootFolderId' => true,
            'diskFolderId' => true,
            'folderId' => true,
            /* CFile/Bitrix.Disk object IDs не переносим между сайтами. */
            'fileId' => true,
        ];

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && isset($forbidden[$key])) {
                continue;
            }

            $result[$key] = is_array($item) ? self::sanitizeDiskData($item) : $item;
        }

        return $result;
    }

    protected static function pagesForSite(int $siteId): array
    {
        $pages = array_values(array_filter(sb_read_pages(), static function ($page) use ($siteId) {
            return (int)($page['siteId'] ?? 0) === $siteId;
        }));

        usort($pages, static function ($a, $b) {
            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);

            if ($sortCmp !== 0) {
                return $sortCmp;
            }

            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return $pages;
    }

    protected static function sectionsForSite(int $siteId, array $pages): array
    {
        $pageIds = [];

        foreach ($pages as $page) {
            $pageId = (int)($page['id'] ?? 0);

            if ($pageId > 0) {
                $pageIds[$pageId] = true;
            }
        }

        if (empty($pageIds)) {
            return [];
        }

        $repoFile = __DIR__ . '/PageSectionRepository.php';

        if (file_exists($repoFile)) {
            require_once $repoFile;
        }

        if (!class_exists('PageSectionRepository')) {
            return [];
        }

        $sections = array_values(array_filter(
            PageSectionRepository::listForSite($siteId),
            static fn(array $section): bool => isset($pageIds[(int)($section['pageId'] ?? 0)])
        ));

        usort($sections, static function ($a, $b) {
            $pageCmp = (int)($a['pageId'] ?? 0) <=> (int)($b['pageId'] ?? 0);

            if ($pageCmp !== 0) {
                return $pageCmp;
            }

            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);

            if ($sortCmp !== 0) {
                return $sortCmp;
            }

            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return $sections;
    }

    protected static function prepareSectionForSnapshot(array $section): array
    {
        return [
            'oldId' => (int)($section['id'] ?? 0),
            'oldPageId' => (int)($section['pageId'] ?? 0),
            'title' => (string)($section['title'] ?? 'Секция'),
            'sort' => (int)($section['sort'] ?? 500),
            'layout' => is_array($section['layout'] ?? null) ? $section['layout'] : [],
            'props' => is_array($section['props'] ?? null) ? $section['props'] : [],
        ];
    }

    protected static function copySections(int $siteId, array $pageIdMap, array $payload, int $userId): array
    {
        $templateSections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];

        if (empty($templateSections)) {
            return [];
        }

        $repoFile = __DIR__ . '/PageSectionRepository.php';

        if (file_exists($repoFile)) {
            require_once $repoFile;
        }

        if (!class_exists('PageSectionRepository')) {
            return [];
        }

        return PageSectionRepository::appendTemplateSections(
            $siteId,
            $pageIdMap,
            $templateSections,
            $userId
        );
    }

    protected static function menusForSite(int $siteId): array
    {
        return array_values(array_filter(sb_read_menus(), static function ($menu) use ($siteId) {
            return (int)($menu['siteId'] ?? 0) === $siteId;
        }));
    }

    protected static function uniqueSiteSlug(string $slug, array $sites): string
    {
        $existing = array_map(static function ($site) {
            return (string)($site['slug'] ?? '');
        }, $sites);

        $base = $slug !== '' ? $slug : 'site';
        $slug = $base;
        $i = 2;

        while (in_array($slug, $existing, true)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    protected static function copyPages(int $siteId, array $payload, int $userId): array
    {
        $templatePages = is_array($payload['pages'] ?? null)
            ? array_values(array_filter($payload['pages'], 'is_array'))
            : [];
        $reservedPageIds = !empty($templatePages)
            ? RevisionService::reserveEntityIds(RevisionService::ENTITY_PAGE, count($templatePages))
            : [];
        $now = date('c');
        $map = [];
        $newPages = [];

        foreach ($templatePages as $pageIndex => $page) {
            $oldId = (int)($page['oldId'] ?? 0);
            $newId = (int)$reservedPageIds[$pageIndex];
            $map[$oldId] = $newId;

            $newPages[] = [
                'id' => $newId,
                'siteId' => $siteId,
                'title' => (string)($page['title'] ?? 'Страница'),
                'slug' => (string)($page['slug'] ?? ('page-' . $newId)),
                'parentId' => 0,
                'sort' => (int)($page['sort'] ?? 500),
                'status' => in_array((string)($page['status'] ?? 'draft'), ['draft', 'published'], true)
                    ? (string)$page['status']
                    : 'draft',
                'publishedAt' => !empty($page['publishedAt']) ? (string)$page['publishedAt'] : null,
                'seo' => is_array($page['seo'] ?? null) ? $page['seo'] : [],
                'createdBy' => $userId,
                'createdAt' => $now,
                'updatedBy' => $userId,
                'updatedAt' => $now,
                '_oldParentId' => (int)($page['parentId'] ?? 0),
            ];
        }

        foreach ($newPages as &$page) {
            $oldParentId = (int)($page['_oldParentId'] ?? 0);
            $page['parentId'] = $oldParentId > 0 && isset($map[$oldParentId])
                ? (int)$map[$oldParentId]
                : 0;

            unset($page['_oldParentId']);

            $page = sb_normalize_page_record($page);
        }
        unset($page);

        if (!empty($newPages)) {
            sb_write_pages($newPages);
        }

        return $map;
    }

    protected static function copyBlocks(
        array $pageIdMap,
        array $payload,
        int $userId,
        array $sectionIdMap = [],
        array $globalBlockIdMap = []
    ): void
    {
        $templateBlocks = is_array($payload['blocks'] ?? null)
            ? array_values(array_filter($payload['blocks'], 'is_array'))
            : [];
        $templateBlocks = array_values(array_filter($templateBlocks, static function (array $block) use ($pageIdMap): bool {
            return isset($pageIdMap[(int)($block['oldPageId'] ?? 0)]);
        }));
        $reservedBlockIds = !empty($templateBlocks)
            ? RevisionService::reserveEntityIds(RevisionService::ENTITY_BLOCK, count($templateBlocks))
            : [];
        $now = date('c');
        $newBlocks = [];
    
        foreach ($templateBlocks as $blockIndex => $block) {
            $oldPageId = (int)($block['oldPageId'] ?? 0);
    
            $props = self::sanitizeDiskData($block['props'] ?? []);
    
            if (!is_array($props)) {
                $props = [];
            }
    
            $placement = is_array($props['_placement'] ?? null) ? $props['_placement'] : [];
    
            $oldSectionId = (int)($block['oldSectionId'] ?? $block['sectionId'] ?? 0);
    
            if ($oldSectionId <= 0) {
                $oldSectionId = (int)($props['sectionId'] ?? 0);
            }
    
            if ($oldSectionId <= 0) {
                $oldSectionId = (int)($placement['sectionId'] ?? 0);
            }
    
            $column = (int)($block['column'] ?? 0);
    
            if ($column <= 0) {
                $column = (int)($props['column'] ?? 0);
            }
    
            if ($column <= 0) {
                $column = (int)($placement['column'] ?? 0);
            }
    
            if ($column <= 0) {
                $column = 1;
            }
    
            $column = max(1, min(4, $column));
    
            $newSectionId = 0;
    
            if ($oldSectionId > 0 && isset($sectionIdMap[$oldSectionId])) {
                $newSectionId = (int)$sectionIdMap[$oldSectionId];
            }
    
            if ($newSectionId > 0) {
                $props['sectionId'] = $newSectionId;
                $props['column'] = $column;
                $props['_placement'] = [
                    'sectionId' => $newSectionId,
                    'column' => $column,
                ];
            } else {
                unset($props['sectionId'], $props['column'], $props['_placement']);
            }
    
            $blockType = (string)($block['type'] ?? 'text');
            $content = self::sanitizeDiskData($block['content'] ?? []);
            if (!is_array($content)) {
                $content = [];
            }
            if ($blockType === 'global') {
                $oldGlobalBlockId = (int)($content['globalBlockId'] ?? 0);
                $content['globalBlockId'] = (int)($globalBlockIdMap[$oldGlobalBlockId] ?? 0);
                if ($content['globalBlockId'] <= 0) {
                    $content['missingGlobalBlockId'] = $oldGlobalBlockId;
                }
            }

            $newBlock = [
                'id' => (int)$reservedBlockIds[$blockIndex],
                'pageId' => (int)$pageIdMap[$oldPageId],
                'type' => $blockType,
                'sort' => (int)($block['sort'] ?? 500),
                'content' => $content,
                'props' => $props,
                'createdBy' => $userId,
                'createdAt' => $now,
                'updatedBy' => $userId,
                'updatedAt' => $now,
            ];
    
            if ($newSectionId > 0) {
                $newBlock['sectionId'] = $newSectionId;
                $newBlock['column'] = $column;
            }
    
            $newBlocks[] = sb_normalize_block_record($newBlock);
        }
    
        if (!empty($newBlocks)) {
            sb_write_blocks($newBlocks);
        }
    }

    protected static function copyLayout(
        int $siteId,
        array $payload,
        int $userId,
        array $globalBlockIdMap = []
    ): void
    {
        if (!function_exists('sb_read_layouts') || !function_exists('sb_write_layouts')) {
            return;
        }

        $snapshotLayout = is_array($payload['layout'] ?? null) ? $payload['layout'] : [];
        $layout = sb_normalize_layout_record($snapshotLayout);
        $layout['siteId'] = $siteId;
        $layout['createdBy'] = $userId;
        $layout['createdAt'] = date('c');
        $layout['updatedBy'] = $userId;
        $layout['updatedAt'] = date('c');

        foreach (['header', 'footer', 'left', 'right'] as $zone) {
            $zoneBlocks = is_array($layout['zones'][$zone] ?? null)
                ? array_values(array_filter($layout['zones'][$zone], 'is_array'))
                : [];
            foreach ($zoneBlocks as &$block) {
                $block['content'] = self::sanitizeDiskData($block['content'] ?? []);
                $block['props'] = self::sanitizeDiskData($block['props'] ?? []);
                if ((string)($block['type'] ?? '') === 'global') {
                    $content = is_array($block['content'] ?? null) ? $block['content'] : [];
                    $oldGlobalBlockId = (int)($content['globalBlockId'] ?? 0);
                    $content['globalBlockId'] = (int)($globalBlockIdMap[$oldGlobalBlockId] ?? 0);
                    if ($content['globalBlockId'] <= 0) {
                        $content['missingGlobalBlockId'] = $oldGlobalBlockId;
                    }
                    $block['content'] = $content;
                }
                $block['createdBy'] = $userId;
                $block['createdAt'] = date('c');
                $block['updatedBy'] = $userId;
                $block['updatedAt'] = date('c');
            }
            unset($block);
            $layout['zones'][$zone] = $zoneBlocks;
        }

        sb_write_layouts([$layout]);
    }

    protected static function copyMenus(int $siteId, array $pageIdMap, array $payload, int $userId, array $snapshotSite): array
    {
        if (!function_exists('sb_read_menus') || !function_exists('sb_write_menus')) {
            return [];
        }

        $snapshotMenus = is_array($payload['menus'] ?? null)
            ? array_values(array_filter($payload['menus'], 'is_array'))
            : [];

        if (empty($snapshotMenus)) {
            return [];
        }

        $now = date('c');
        $reservedMenuIds = RevisionService::reserveEntityIds(
            RevisionService::ENTITY_MENU,
            count($snapshotMenus)
        );
        $newMenus = [];
        $menuIdMap = [];
        $oldTopMenuId = (int)($snapshotSite['topMenuId'] ?? 0);
        $newTopMenuId = 0;
        $topMenuIndex = null;

        foreach ($snapshotMenus as $index => $menu) {
            if ((int)($menu['oldId'] ?? 0) === $oldTopMenuId) {
                $topMenuIndex = $index;
                break;
            }
        }

        foreach ($snapshotMenus as $menuIndex => $menu) {
            $newMenuId = (int)$reservedMenuIds[$menuIndex];
            $oldMenuId = (int)($menu['oldId'] ?? 0);
            if ($oldMenuId > 0) {
                $menuIdMap[$oldMenuId] = $newMenuId;
            }

            $items = [];

            foreach ((array)($menu['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $type = (string)($item['type'] ?? 'page');
                $oldPageId = (int)($item['pageId'] ?? 0);

                $newPageId = ($type === 'page' && $oldPageId > 0 && isset($pageIdMap[$oldPageId]))
                    ? (int)$pageIdMap[$oldPageId]
                    : 0;

                $item['pageId'] = $newPageId;
                $items[] = $item;
            }

            if ($topMenuIndex !== null && $menuIndex === $topMenuIndex) {
                $newTopMenuId = $newMenuId;
            }

            $newMenus[] = [
                'id' => $newMenuId,
                'siteId' => $siteId,
                'name' => (string)($menu['name'] ?? 'Меню'),
                'items' => $items,
                'createdBy' => $userId,
                'createdAt' => $now,
                'updatedBy' => $userId,
                'updatedAt' => $now,
            ];
        }

        if (!empty($newMenus)) {
            sb_write_menus($newMenus);
        }

        if ($newTopMenuId > 0) {
            self::updateSiteField($siteId, 'topMenuId', $newTopMenuId, $userId);
        }

        return $menuIdMap;
    }

    protected static function updateSiteField(int $siteId, string $field, $value, int $userId): void
    {
        if (!in_array($field, ['homePageId', 'topMenuId'], true)) {
            throw new InvalidArgumentException('SITE_FIELD_NOT_ALLOWED');
        }

        $site = RevisionService::getSite($siteId, false);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $site[$field] = (int)$value;
        RevisionService::saveSite(
            $site,
            (int)$site['version'],
            $userId,
            'template_' . $field . '_set'
        );
    }

    protected static function grantOwnerAccess(int $siteId, int $userId, string $now): void
    {
        sb_set_access_role(
            $siteId,
            'U' . $userId,
            'OWNER',
            $userId,
            [
                'allowOwnerAssignment' => true,
                'allowOwnerDowngrade' => true,
            ]
        );
    }
}