<?php

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

        $menus = self::menusForSite($siteId);

        $layout = function_exists('sb_layout_ensure_record')
            ? sb_layout_ensure_record($siteId)
            : ['siteId' => $siteId, 'settings' => [], 'zones' => []];

        $layout = self::prepareLayoutForSnapshot($layout);

        $now = date('c');
        $templates = sb_read_templates();

        $template = [
            'id' => sb_next_template_id($templates),
            'kind' => 'site',
            'name' => $name,
            'description' => trim($description),
            'sourceSiteId' => $siteId,
            'sourceSiteName' => (string)($site['name'] ?? ''),
            'payload' => [
                'site' => self::prepareSiteForSnapshot($site),
                'pages' => array_map([self::class, 'preparePageForSnapshot'], $pages),
                'blocks' => $blocks,
                'layout' => $layout,
                'menus' => array_map([self::class, 'prepareMenuForSnapshot'], $menus),
            ],
            'createdBy' => $userId,
            'createdAt' => $now,
            'updatedBy' => $userId,
            'updatedAt' => $now,
        ];

        $templates[] = $template;
        sb_write_templates($templates);

        return self::publicTemplateRecord($template);
    }

    public static function delete(int $templateId): void
    {
        $templates = sb_read_templates();
        $before = count($templates);

        $templates = array_values(array_filter($templates, static function ($template) use ($templateId) {
            return (int)($template['id'] ?? 0) !== $templateId;
        }));

        if (count($templates) === $before) {
            throw new RuntimeException('TEMPLATE_NOT_FOUND');
        }

        sb_write_templates($templates);
    }

    public static function rename(int $templateId, string $name, string $description, int $userId): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('NAME_REQUIRED');
        }

        $templates = sb_read_templates();
        $updated = null;

        foreach ($templates as &$template) {
            if ((int)($template['id'] ?? 0) !== $templateId) {
                continue;
            }

            $template['name'] = $name;
            $template['description'] = trim($description);
            $template['updatedBy'] = $userId;
            $template['updatedAt'] = date('c');
            $updated = $template;
            break;
        }
        unset($template);

        if (!$updated) {
            throw new RuntimeException('TEMPLATE_NOT_FOUND');
        }

        sb_write_templates($templates);

        return self::publicTemplateRecord($updated);
    }

    public static function createSiteFromTemplate(int $templateId, string $siteName, string $slug, int $sectionId, int $userId): array
    {
        $template = self::getTemplate($templateId);
        if (!$template || (string)($template['kind'] ?? 'site') !== 'site') {
            throw new RuntimeException('TEMPLATE_NOT_FOUND');
        }

        $payload = is_array($template['payload'] ?? null) ? $template['payload'] : [];
        $snapshotSite = is_array($payload['site'] ?? null) ? $payload['site'] : [];

        $siteName = trim($siteName);
        if ($siteName === '') {
            $siteName = (string)($snapshotSite['name'] ?? $template['name'] ?? 'Новый сайт');
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
        $siteId = sb_next_id($sites, 'id');
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
            'settings' => is_array($snapshotSite['settings'] ?? null) ? $snapshotSite['settings'] : [],
            'layout' => is_array($snapshotSite['layout'] ?? null) ? $snapshotSite['layout'] : [],
        ];

        $bitrixGroupId = 0;
        $bitrixGroupError = '';

        $groupServicePath = $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/SiteBitrixGroupService.php';
        if (file_exists($groupServicePath)) {
            require_once $groupServicePath;
        }

        if (class_exists('SiteBitrixGroupService')) {
            try {
                $bitrixGroupId = (int)SiteBitrixGroupService::createForSite($site, $userId);

                if ($bitrixGroupId > 0) {
                    $site['bitrixGroupId'] = $bitrixGroupId;
                    $site['bitrixGroupCreatedBy'] = $userId;
                    $site['bitrixGroupCreatedAt'] = $now;
                }
            } catch (Throwable $e) {
                $bitrixGroupError = $e->getMessage();
            }
        }

        $sites[] = $site;
        sb_write_sites($sites);

        $pageIdMap = self::copyPages($siteId, $payload, $userId);
        self::copyBlocks($pageIdMap, $payload, $userId);

        $homeOldId = (int)($snapshotSite['homePageId'] ?? 0);
        if ($homeOldId > 0 && isset($pageIdMap[$homeOldId])) {
            self::updateSiteField($siteId, 'homePageId', (int)$pageIdMap[$homeOldId], $userId);
        } else {
            $firstNewPageId = !empty($pageIdMap) ? (int)reset($pageIdMap) : 0;
            if ($firstNewPageId > 0) {
                self::updateSiteField($siteId, 'homePageId', $firstNewPageId, $userId);
            }
        }

        self::copyLayout($siteId, $payload, $userId);
        self::copyMenus($siteId, $pageIdMap, $payload, $userId, $snapshotSite);
        self::grantOwnerAccess($siteId, $userId, $now);

        return [
            'site' => sb_find_site($siteId) ?: $site,
            'template' => self::publicTemplateRecord($template),
            'bitrixGroupId' => $bitrixGroupId,
            'bitrixGroupError' => $bitrixGroupError,
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
            'settings' => is_array($site['settings'] ?? null) ? $site['settings'] : [],
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
        ];
    }

    protected static function prepareBlockForSnapshot(array $block): array
    {
        $block = sb_normalize_block_record($block);

        return [
            'oldId' => (int)($block['id'] ?? 0),
            'oldPageId' => (int)($block['pageId'] ?? 0),
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
        $pages = sb_read_pages();
        $templatePages = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];
        $nextPageId = sb_next_id($pages, 'id');
        $now = date('c');
        $map = [];
        $newPages = [];

        foreach ($templatePages as $page) {
            $oldId = (int)($page['oldId'] ?? 0);
            $newId = $nextPageId++;
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

        $pages = array_merge($pages, $newPages);
        sb_write_pages($pages);

        return $map;
    }

    protected static function copyBlocks(array $pageIdMap, array $payload, int $userId): void
    {
        $blocks = sb_read_blocks();
        $templateBlocks = is_array($payload['blocks'] ?? null) ? $payload['blocks'] : [];
        $nextBlockId = sb_next_block_id($blocks);
        $now = date('c');

        foreach ($templateBlocks as $block) {
            $oldPageId = (int)($block['oldPageId'] ?? 0);

            if (!isset($pageIdMap[$oldPageId])) {
                continue;
            }

            $blocks[] = sb_normalize_block_record([
                'id' => $nextBlockId++,
                'pageId' => (int)$pageIdMap[$oldPageId],
                'type' => (string)($block['type'] ?? 'text'),
                'sort' => (int)($block['sort'] ?? 500),
                'content' => self::sanitizeDiskData($block['content'] ?? []),
                'props' => self::sanitizeDiskData($block['props'] ?? []),
                'createdBy' => $userId,
                'createdAt' => $now,
                'updatedBy' => $userId,
                'updatedAt' => $now,
            ]);
        }

        sb_write_blocks($blocks);
    }

    protected static function copyLayout(int $siteId, array $payload, int $userId): void
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
            foreach (($layout['zones'][$zone] ?? []) as &$block) {
                $block['content'] = self::sanitizeDiskData($block['content'] ?? []);
                $block['props'] = self::sanitizeDiskData($block['props'] ?? []);
                $block['createdBy'] = $userId;
                $block['createdAt'] = date('c');
                $block['updatedBy'] = $userId;
                $block['updatedAt'] = date('c');
            }
            unset($block);
        }

        $layouts = sb_read_layouts();

        $layouts = array_values(array_filter($layouts, static function ($item) use ($siteId) {
            return (int)($item['siteId'] ?? 0) !== $siteId;
        }));

        $layouts[] = $layout;

        sb_write_layouts($layouts);
    }

    protected static function copyMenus(int $siteId, array $pageIdMap, array $payload, int $userId, array $snapshotSite): void
    {
        if (!function_exists('sb_read_menus') || !function_exists('sb_write_menus')) {
            return;
        }

        $snapshotMenus = is_array($payload['menus'] ?? null) ? $payload['menus'] : [];

        if (empty($snapshotMenus)) {
            return;
        }

        $now = date('c');
        $menus = sb_read_menus();
        $nextMenuId = function_exists('sb_next_menu_id') ? sb_next_menu_id($menus) : (count($menus) + 1);
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
            $newMenuId = $nextMenuId++;

            $items = [];

            foreach ((array)($menu['items'] ?? []) as $item) {
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

            $menus[] = [
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

        sb_write_menus($menus);

        if ($newTopMenuId > 0) {
            self::updateSiteField($siteId, 'topMenuId', $newTopMenuId, $userId);
        }
    }

    protected static function updateSiteField(int $siteId, string $field, $value, int $userId): void
    {
        $allowed = ['homePageId', 'topMenuId'];

        if (!in_array($field, $allowed, true)) {
            return;
        }

        $sites = sb_read_sites();

        foreach ($sites as &$site) {
            if ((int)($site['id'] ?? 0) !== $siteId) {
                continue;
            }

            $site[$field] = $value;
            $site['updatedBy'] = $userId;
            $site['updatedAt'] = date('c');
            break;
        }
        unset($site);

        sb_write_sites($sites);
    }

    protected static function grantOwnerAccess(int $siteId, int $userId, string $now): void
    {
        $access = sb_read_access();

        $access[] = [
            'siteId' => $siteId,
            'accessCode' => 'U' . $userId,
            'role' => 'OWNER',
            'createdBy' => $userId,
            'createdAt' => $now,
            'updatedBy' => $userId,
            'updatedAt' => $now,
        ];

        sb_write_access($access);
    }
}