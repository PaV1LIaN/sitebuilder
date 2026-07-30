<?php

class DiskSitebuilderBridge
{
    public static function getSiteById(int $siteId): ?array
    {
        $row = DiskDb::fetchOne("
            SELECT
                id,
                name,
                slug,
                home_page_id,
                disk_folder_id,
                top_menu_id,
                settings_json,
                layout_json,
                created_by,
                created_at,
                updated_by,
                updated_at,
                version
            FROM sitebuilder.site
            WHERE id = :id
            LIMIT 1
        ", [
            ':id' => $siteId,
        ]);

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'slug' => (string)$row['slug'],
            'homePageId' => !empty($row['home_page_id']) ? (int)$row['home_page_id'] : 0,
            'diskFolderId' => !empty($row['disk_folder_id']) ? (int)$row['disk_folder_id'] : 0,
            'topMenuId' => !empty($row['top_menu_id']) ? (int)$row['top_menu_id'] : 0,
            'settings' => sb_json_decode_assoc($row['settings_json'] ?? '{}'),
            'layout' => sb_json_decode_assoc($row['layout_json'] ?? '{}'),
            'createdBy' => isset($row['created_by']) ? (int)$row['created_by'] : 0,
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedBy' => isset($row['updated_by']) ? (int)$row['updated_by'] : 0,
            'updatedAt' => (string)($row['updated_at'] ?? ''),
            'version' => max(1, (int)($row['version'] ?? 1)),
        ];
    }

    public static function getPageById(int $pageId): ?array
    {
        $row = DiskDb::fetchOne("
            SELECT
                id,
                site_id,
                title,
                slug,
                parent_id,
                sort,
                status,
                published_at,
                created_by,
                created_at,
                updated_by,
                updated_at,
                version
            FROM sitebuilder.page
            WHERE id = :id
            LIMIT 1
        ", [
            ':id' => $pageId,
        ]);

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'siteId' => (int)$row['site_id'],
            'title' => (string)$row['title'],
            'slug' => (string)$row['slug'],
            'parentId' => !empty($row['parent_id']) ? (int)$row['parent_id'] : 0,
            'sort' => (int)($row['sort'] ?? 500),
            'status' => (string)($row['status'] ?? 'draft'),
            'publishedAt' => !empty($row['published_at']) ? (string)$row['published_at'] : null,
            'createdBy' => isset($row['created_by']) ? (int)$row['created_by'] : 0,
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedBy' => isset($row['updated_by']) ? (int)$row['updated_by'] : 0,
            'updatedAt' => (string)($row['updated_at'] ?? ''),
            'version' => max(1, (int)($row['version'] ?? 1)),
        ];
    }

    public static function getBlockById(int $blockId): ?array
    {
        $row = DiskDb::fetchOne("
            SELECT
                id,
                page_id,
                type,
                sort,
                content_json,
                props_json,
                created_by,
                created_at,
                updated_by,
                updated_at,
                version
            FROM sitebuilder.block
            WHERE id = :id
            LIMIT 1
        ", [
            ':id' => $blockId,
        ]);

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'pageId' => (int)$row['page_id'],
            'type' => (string)$row['type'],
            'sort' => (int)($row['sort'] ?? 500),
            'content' => sb_json_decode_assoc($row['content_json'] ?? '{}'),
            'props' => sb_json_decode_assoc($row['props_json'] ?? '{}'),
            'createdBy' => isset($row['created_by']) ? (int)$row['created_by'] : 0,
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedBy' => isset($row['updated_by']) ? (int)$row['updated_by'] : 0,
            'updatedAt' => (string)($row['updated_at'] ?? ''),
            'version' => max(1, (int)($row['version'] ?? 1)),
        ];
    }

    public static function saveBlockProps(
        int $blockId,
        array $props,
        ?int $expectedVersion = null,
        int $userId = 0
    ): bool {
        $startedHere = sb_db_transaction_scope_begin();

        try {
            $block = RevisionService::getBlock($blockId, false);
            if (!$block) {
                throw new RuntimeException('BLOCK_NOT_FOUND');
            }

            $block['props'] = $props;
            RevisionService::saveBlock(
                $block,
                $expectedVersion !== null
                    ? RevisionService::requireExpectedVersion($expectedVersion)
                    : (int)$block['version'],
                $userId,
                'disk_settings_update'
            );

            sb_db_transaction_scope_commit($startedHere);
            return true;
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }
    }

    public static function updateSiteDiskFolderId(
        int $siteId,
        int $folderId,
        int $userId = 0
    ): bool {
        $startedHere = sb_db_transaction_scope_begin();

        try {
            $site = RevisionService::getSite($siteId, false);
            if (!$site) {
                throw new RuntimeException('SITE_NOT_FOUND');
            }

            if ((int)($site['diskFolderId'] ?? 0) === $folderId) {
                sb_db_transaction_scope_commit($startedHere);
                return true;
            }

            $site['diskFolderId'] = $folderId;
            RevisionService::saveSite(
                $site,
                (int)$site['version'],
                $userId,
                'disk_root_initialized'
            );

            sb_db_transaction_scope_commit($startedHere);
            return true;
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }
    }

    public static function normalizeDiskProps(array $props): array
    {
        return [
            'title' => trim((string)($props['title'] ?? 'Файлы')),
            'rootMode' => in_array((string)($props['rootMode'] ?? 'site'), ['site', 'block'], true)
                ? (string)$props['rootMode']
                : 'site',
            'rootFolderId' => !empty($props['rootFolderId']) ? (int)$props['rootFolderId'] : null,
            'viewMode' => in_array((string)($props['viewMode'] ?? 'table'), ['table', 'grid'], true)
                ? (string)$props['viewMode']
                : 'table',
            'allowUpload' => !array_key_exists('allowUpload', $props) || !empty($props['allowUpload']),
            'allowCreateFolder' => !array_key_exists('allowCreateFolder', $props) || !empty($props['allowCreateFolder']),
            'allowRename' => !array_key_exists('allowRename', $props) || !empty($props['allowRename']),
            'allowDelete' => !empty($props['allowDelete']),
            'allowDownload' => !array_key_exists('allowDownload', $props) || !empty($props['allowDownload']),
            'showSearch' => !array_key_exists('showSearch', $props) || !empty($props['showSearch']),
            'showBreadcrumbs' => !array_key_exists('showBreadcrumbs', $props) || !empty($props['showBreadcrumbs']),
            'defaultSort' => trim((string)($props['defaultSort'] ?? 'updatedAt')),
            'defaultSortDirection' => strtolower((string)($props['defaultSortDirection'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            'allowedExtensions' => is_array($props['allowedExtensions'] ?? null) ? array_values($props['allowedExtensions']) : [],
            'maxFileSize' => max(0, (int)($props['maxFileSize'] ?? 52428800)),
            'permissionMode' => in_array((string)($props['permissionMode'] ?? 'inherit_site'), ['inherit_site', 'custom'], true)
                ? (string)$props['permissionMode']
                : 'inherit_site',
            'useSiteRootFallback' => !array_key_exists('useSiteRootFallback', $props) || !empty($props['useSiteRootFallback']),
        ];
    }
}