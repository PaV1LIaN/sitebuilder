<?php

function sb_read_sites(): array
{
    $rows = sb_db_fetch_all("
        SELECT
            id,
            name,
            slug,
            section_id,
            home_page_id,
            disk_folder_id,
            top_menu_id,
            bitrix_group_id,
            bitrix_group_created_by,
            bitrix_group_created_at,
            settings_json,
            layout_json,
            created_by,
            created_at,
            updated_by,
            updated_at
        FROM sitebuilder.site
        ORDER BY id ASC
    ");

    return array_map('sb_map_site_row', $rows);
}

function sb_write_sites(array $sites): bool
{
    $pdo = sb_db();
    $pdo->beginTransaction();

    try {
        $existingRows = sb_db_fetch_all("SELECT id FROM sitebuilder.site");
        $existingIds = array_map('intval', array_column($existingRows, 'id'));

        $incomingIds = [];

        foreach ($sites as $site) {
            $id = (int)($site['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $incomingIds[] = $id;

            sb_db_execute("
                INSERT INTO sitebuilder.site (
                    id,
                    name,
                    slug,
                    section_id,
                    home_page_id,
                    disk_folder_id,
                    top_menu_id,
                    bitrix_group_id,
                    bitrix_group_created_by,
                    bitrix_group_created_at,
                    settings_json,
                    layout_json,
                    created_by,
                    created_at,
                    updated_by,
                    updated_at
                ) VALUES (
                    :id,
                    :name,
                    :slug,
                    :section_id,
                    :home_page_id,
                    :disk_folder_id,
                    :top_menu_id,
                    :bitrix_group_id,
                    :bitrix_group_created_by,
                    :bitrix_group_created_at,
                    :settings_json::jsonb,
                    :layout_json::jsonb,
                    :created_by,
                    :created_at,
                    :updated_by,
                    :updated_at
                )
                ON CONFLICT (id)
                DO UPDATE SET
                    name = EXCLUDED.name,
                    slug = EXCLUDED.slug,
                    section_id = EXCLUDED.section_id,
                    home_page_id = EXCLUDED.home_page_id,
                    disk_folder_id = EXCLUDED.disk_folder_id,
                    top_menu_id = EXCLUDED.top_menu_id,
                    bitrix_group_id = EXCLUDED.bitrix_group_id,
                    bitrix_group_created_by = EXCLUDED.bitrix_group_created_by,
                    bitrix_group_created_at = EXCLUDED.bitrix_group_created_at,
                    settings_json = EXCLUDED.settings_json,
                    layout_json = EXCLUDED.layout_json,
                    created_by = EXCLUDED.created_by,
                    created_at = EXCLUDED.created_at,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = EXCLUDED.updated_at
            ", [
                ':id' => $id,
                ':name' => (string)($site['name'] ?? ''),
                ':slug' => (string)($site['slug'] ?? ''),
                ':section_id' => !empty($site['sectionId']) ? (int)$site['sectionId'] : null,
                ':home_page_id' => !empty($site['homePageId']) ? (int)$site['homePageId'] : null,
                ':disk_folder_id' => !empty($site['diskFolderId']) ? (int)$site['diskFolderId'] : null,
                ':top_menu_id' => !empty($site['topMenuId']) ? (int)$site['topMenuId'] : null,
                ':bitrix_group_id' => !empty($site['bitrixGroupId']) ? (int)$site['bitrixGroupId'] : null,
                ':bitrix_group_created_by' => !empty($site['bitrixGroupCreatedBy']) ? (int)$site['bitrixGroupCreatedBy'] : null,
                ':bitrix_group_created_at' => !empty($site['bitrixGroupCreatedAt']) ? (string)$site['bitrixGroupCreatedAt'] : null,
                ':settings_json' => json_encode($site['settings'] ?? [], JSON_UNESCAPED_UNICODE),
                ':layout_json' => json_encode($site['layout'] ?? [], JSON_UNESCAPED_UNICODE),
                ':created_by' => isset($site['createdBy']) ? (int)$site['createdBy'] : null,
                ':created_at' => (string)($site['createdAt'] ?? date('c')),
                ':updated_by' => isset($site['updatedBy']) ? (int)$site['updatedBy'] : null,
                ':updated_at' => (string)($site['updatedAt'] ?? date('c')),
            ]);
        }

        $idsToDelete = array_diff($existingIds, $incomingIds);
        if (!empty($idsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $stmt = $pdo->prepare("DELETE FROM sitebuilder.site WHERE id IN ($placeholders)");
            $stmt->execute(array_values($idsToDelete));
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function sb_read_pages(): array
{
    $rows = sb_db_fetch_all("
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
            updated_at
        FROM sitebuilder.page
        ORDER BY site_id ASC, sort ASC, id ASC
    ");

    return array_map('sb_map_page_row', $rows);
}

function sb_write_pages(array $pages): bool
{
    $pdo = sb_db();
    $pdo->beginTransaction();

    try {
        $existingRows = sb_db_fetch_all("SELECT id FROM sitebuilder.page");
        $existingIds = array_map('intval', array_column($existingRows, 'id'));

        $incomingIds = [];

        foreach ($pages as $page) {
            $id = (int)($page['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $incomingIds[] = $id;

            sb_db_execute("
                INSERT INTO sitebuilder.page (
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
                    updated_at
                ) VALUES (
                    :id,
                    :site_id,
                    :title,
                    :slug,
                    :parent_id,
                    :sort,
                    :status,
                    :published_at,
                    :created_by,
                    :created_at,
                    :updated_by,
                    :updated_at
                )
                ON CONFLICT (id)
                DO UPDATE SET
                    site_id = EXCLUDED.site_id,
                    title = EXCLUDED.title,
                    slug = EXCLUDED.slug,
                    parent_id = EXCLUDED.parent_id,
                    sort = EXCLUDED.sort,
                    status = EXCLUDED.status,
                    published_at = EXCLUDED.published_at,
                    created_by = EXCLUDED.created_by,
                    created_at = EXCLUDED.created_at,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = EXCLUDED.updated_at
            ", [
                ':id' => $id,
                ':site_id' => (int)($page['siteId'] ?? 0),
                ':title' => (string)($page['title'] ?? ''),
                ':slug' => (string)($page['slug'] ?? ''),
                ':parent_id' => !empty($page['parentId']) ? (int)$page['parentId'] : null,
                ':sort' => (int)($page['sort'] ?? 500),
                ':status' => (string)($page['status'] ?? 'draft'),
                ':published_at' => !empty($page['publishedAt']) ? (string)$page['publishedAt'] : null,
                ':created_by' => isset($page['createdBy']) ? (int)$page['createdBy'] : null,
                ':created_at' => (string)($page['createdAt'] ?? date('c')),
                ':updated_by' => isset($page['updatedBy']) ? (int)$page['updatedBy'] : null,
                ':updated_at' => (string)($page['updatedAt'] ?? date('c')),
            ]);
        }

        $idsToDelete = array_diff($existingIds, $incomingIds);
        if (!empty($idsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $stmt = $pdo->prepare("DELETE FROM sitebuilder.page WHERE id IN ($placeholders)");
            $stmt->execute(array_values($idsToDelete));
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function sb_read_blocks(): array
{
    $rows = sb_db_fetch_all("
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
            updated_at
        FROM sitebuilder.block
        ORDER BY page_id ASC, sort ASC, id ASC
    ");

    return array_map('sb_map_block_row', $rows);
}

function sb_write_blocks(array $blocks): bool
{
    $pdo = sb_db();
    $pdo->beginTransaction();

    try {
        $existingRows = sb_db_fetch_all("SELECT id FROM sitebuilder.block");
        $existingIds = array_map('intval', array_column($existingRows, 'id'));

        $incomingIds = [];

        foreach ($blocks as $block) {
            $id = (int)($block['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $incomingIds[] = $id;

            sb_db_execute("
                INSERT INTO sitebuilder.block (
                    id,
                    page_id,
                    type,
                    sort,
                    content_json,
                    props_json,
                    created_by,
                    created_at,
                    updated_by,
                    updated_at
                ) VALUES (
                    :id,
                    :page_id,
                    :type,
                    :sort,
                    :content_json::jsonb,
                    :props_json::jsonb,
                    :created_by,
                    :created_at,
                    :updated_by,
                    :updated_at
                )
                ON CONFLICT (id)
                DO UPDATE SET
                    page_id = EXCLUDED.page_id,
                    type = EXCLUDED.type,
                    sort = EXCLUDED.sort,
                    content_json = EXCLUDED.content_json,
                    props_json = EXCLUDED.props_json,
                    created_by = EXCLUDED.created_by,
                    created_at = EXCLUDED.created_at,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = EXCLUDED.updated_at
            ", [
                ':id' => $id,
                ':page_id' => (int)($block['pageId'] ?? 0),
                ':type' => (string)($block['type'] ?? ''),
                ':sort' => (int)($block['sort'] ?? 500),
                ':content_json' => json_encode($block['content'] ?? [], JSON_UNESCAPED_UNICODE),
                ':props_json' => json_encode($block['props'] ?? [], JSON_UNESCAPED_UNICODE),
                ':created_by' => isset($block['createdBy']) ? (int)$block['createdBy'] : null,
                ':created_at' => (string)($block['createdAt'] ?? date('c')),
                ':updated_by' => isset($block['updatedBy']) ? (int)$block['updatedBy'] : null,
                ':updated_at' => (string)($block['updatedAt'] ?? date('c')),
            ]);
        }

        $idsToDelete = array_diff($existingIds, $incomingIds);
        if (!empty($idsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $stmt = $pdo->prepare("DELETE FROM sitebuilder.block WHERE id IN ($placeholders)");
            $stmt->execute(array_values($idsToDelete));
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function sb_map_site_row(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'slug' => (string)$row['slug'],
        'sectionId' => !empty($row['section_id']) ? (int)$row['section_id'] : 0,
        'homePageId' => !empty($row['home_page_id']) ? (int)$row['home_page_id'] : 0,
        'diskFolderId' => !empty($row['disk_folder_id']) ? (int)$row['disk_folder_id'] : 0,
        'topMenuId' => !empty($row['top_menu_id']) ? (int)$row['top_menu_id'] : 0,

        'bitrixGroupId' => !empty($row['bitrix_group_id']) ? (int)$row['bitrix_group_id'] : 0,
        'bitrixGroupCreatedBy' => !empty($row['bitrix_group_created_by']) ? (int)$row['bitrix_group_created_by'] : 0,
        'bitrixGroupCreatedAt' => !empty($row['bitrix_group_created_at']) ? (string)$row['bitrix_group_created_at'] : '',
        'bitrixGroupUrl' => !empty($row['bitrix_group_id'])
            ? '/workgroups/group/' . (int)$row['bitrix_group_id'] . '/'
            : '',

        'settings' => sb_json_decode_assoc($row['settings_json'] ?? '{}'),
        'layout' => sb_json_decode_assoc($row['layout_json'] ?? '{}'),
        'createdBy' => isset($row['created_by']) ? (int)$row['created_by'] : 0,
        'createdAt' => (string)($row['created_at'] ?? ''),
        'updatedBy' => isset($row['updated_by']) ? (int)$row['updated_by'] : 0,
        'updatedAt' => (string)($row['updated_at'] ?? ''),
    ];
}

function sb_map_page_row(array $row): array
{
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
    ];
}

function sb_map_block_row(array $row): array
{
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
    ];
}