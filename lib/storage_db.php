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
            updated_at,
            version
        FROM sitebuilder.site
        ORDER BY id ASC
    ");

    return array_map('sb_map_site_row', $rows);
}

function sb_write_sites(array $sites): bool
{
    $startedHere = sb_db_transaction_scope_begin();

    try {
        foreach ($sites as $site) {
            $id = (int)($site['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $stmt = sb_db()->prepare("\n                INSERT INTO sitebuilder.site (\n                    id,name,slug,section_id,home_page_id,disk_folder_id,top_menu_id,\n                    bitrix_group_id,bitrix_group_created_by,bitrix_group_created_at,\n                    settings_json,layout_json,created_by,created_at,updated_by,updated_at,version\n                ) VALUES (\n                    :id,:name,:slug,:section_id,:home_page_id,:disk_folder_id,:top_menu_id,\n                    :bitrix_group_id,:bitrix_group_created_by,:bitrix_group_created_at,\n                    CAST(:settings_json AS jsonb),CAST(:layout_json AS jsonb),\n                    :created_by,:created_at,:updated_by,:updated_at,:version\n                )\n                RETURNING\n                    id,name,slug,section_id,home_page_id,disk_folder_id,top_menu_id,\n                    bitrix_group_id,bitrix_group_created_by,bitrix_group_created_at,\n                    settings_json,layout_json,created_by,created_at,updated_by,updated_at,version\n            ");
            $stmt->execute([
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
                ':settings_json' => json_encode($site['settings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':layout_json' => json_encode($site['layout'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':created_by' => isset($site['createdBy']) ? (int)$site['createdBy'] : null,
                ':created_at' => (string)($site['createdAt'] ?? date('c')),
                ':updated_by' => isset($site['updatedBy']) ? (int)$site['updatedBy'] : null,
                ':updated_at' => (string)($site['updatedAt'] ?? date('c')),
                ':version' => max(1, (int)($site['version'] ?? 1)),
            ]);

            $row = $stmt->fetch();
            if ($row && class_exists('RevisionService')) {
                $saved = sb_map_site_row($row);
                RevisionService::recordSite(
                    $saved,
                    'create',
                    (int)($saved['updatedBy'] ?: $saved['createdBy'])
                );
            }
        }

        sb_db_transaction_scope_commit($startedHere);
        return true;
    } catch (Throwable $e) {
        sb_db_transaction_scope_rollback($startedHere);
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
            seo_json,
            created_by,
            created_at,
            updated_by,
            updated_at,
            version
        FROM sitebuilder.page
        ORDER BY site_id ASC, sort ASC, id ASC
    ");

    return array_map('sb_map_page_row', $rows);
}

function sb_write_pages(array $pages): bool
{
    $startedHere = sb_db_transaction_scope_begin();

    try {
        foreach ($pages as $page) {
            $id = (int)($page['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $stmt = sb_db()->prepare("\n                INSERT INTO sitebuilder.page (\n                    id,site_id,title,slug,parent_id,sort,status,published_at,seo_json,\n                    created_by,created_at,updated_by,updated_at,version\n                ) VALUES (\n                    :id,:site_id,:title,:slug,:parent_id,:sort,:status,:published_at,CAST(:seo_json AS jsonb),\n                    :created_by,:created_at,:updated_by,:updated_at,:version\n                )\n                RETURNING id,site_id,title,slug,parent_id,sort,status,published_at,seo_json,\n                          created_by,created_at,updated_by,updated_at,version\n            ");
            $stmt->execute([
                ':id' => $id,
                ':site_id' => (int)($page['siteId'] ?? 0),
                ':title' => (string)($page['title'] ?? ''),
                ':slug' => (string)($page['slug'] ?? ''),
                ':parent_id' => !empty($page['parentId']) ? (int)$page['parentId'] : null,
                ':sort' => (int)($page['sort'] ?? 500),
                ':status' => (string)($page['status'] ?? 'draft'),
                ':published_at' => !empty($page['publishedAt']) ? (string)$page['publishedAt'] : null,
                ':seo_json' => json_encode(is_array($page['seo'] ?? null) ? $page['seo'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':created_by' => isset($page['createdBy']) ? (int)$page['createdBy'] : null,
                ':created_at' => (string)($page['createdAt'] ?? date('c')),
                ':updated_by' => isset($page['updatedBy']) ? (int)$page['updatedBy'] : null,
                ':updated_at' => (string)($page['updatedAt'] ?? date('c')),
                ':version' => max(1, (int)($page['version'] ?? 1)),
            ]);

            $row = $stmt->fetch();
            if ($row && class_exists('RevisionService')) {
                $saved = sb_map_page_row($row);
                RevisionService::recordPage(
                    $saved,
                    'create',
                    (int)($saved['updatedBy'] ?: $saved['createdBy'])
                );
            }
        }

        sb_db_transaction_scope_commit($startedHere);
        return true;
    } catch (Throwable $e) {
        sb_db_transaction_scope_rollback($startedHere);
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
            updated_at,
            version
        FROM sitebuilder.block
        ORDER BY page_id ASC, sort ASC, id ASC
    ");

    return array_map('sb_map_block_row', $rows);
}

function sb_write_blocks(array $blocks): bool
{
    $startedHere = sb_db_transaction_scope_begin();

    try {
        foreach ($blocks as $block) {
            $id = (int)($block['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $stmt = sb_db()->prepare("\n                INSERT INTO sitebuilder.block (\n                    id,page_id,type,sort,content_json,props_json,\n                    created_by,created_at,updated_by,updated_at,version\n                ) VALUES (\n                    :id,:page_id,:type,:sort,CAST(:content_json AS jsonb),CAST(:props_json AS jsonb),\n                    :created_by,:created_at,:updated_by,:updated_at,:version\n                )\n                RETURNING id,page_id,type,sort,content_json,props_json,\n                          created_by,created_at,updated_by,updated_at,version\n            ");
            $stmt->execute([
                ':id' => $id,
                ':page_id' => (int)($block['pageId'] ?? 0),
                ':type' => (string)($block['type'] ?? ''),
                ':sort' => (int)($block['sort'] ?? 500),
                ':content_json' => json_encode($block['content'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':props_json' => json_encode($block['props'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':created_by' => isset($block['createdBy']) ? (int)$block['createdBy'] : null,
                ':created_at' => (string)($block['createdAt'] ?? date('c')),
                ':updated_by' => isset($block['updatedBy']) ? (int)$block['updatedBy'] : null,
                ':updated_at' => (string)($block['updatedAt'] ?? date('c')),
                ':version' => max(1, (int)($block['version'] ?? 1)),
            ]);

            $row = $stmt->fetch();
            if ($row && class_exists('RevisionService')) {
                $saved = sb_map_block_row($row);
                RevisionService::recordBlock(
                    $saved,
                    'create',
                    (int)($saved['updatedBy'] ?: $saved['createdBy'])
                );
            }
        }

        sb_db_transaction_scope_commit($startedHere);
        return true;
    } catch (Throwable $e) {
        sb_db_transaction_scope_rollback($startedHere);
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
        'version' => max(1, (int)($row['version'] ?? 1)),
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
        'seo' => sb_json_decode_assoc($row['seo_json'] ?? '{}'),
        'createdBy' => isset($row['created_by']) ? (int)$row['created_by'] : 0,
        'createdAt' => (string)($row['created_at'] ?? ''),
        'updatedBy' => isset($row['updated_by']) ? (int)$row['updated_by'] : 0,
        'updatedAt' => (string)($row['updated_at'] ?? ''),
        'version' => max(1, (int)($row['version'] ?? 1)),
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
        'version' => max(1, (int)($row['version'] ?? 1)),
    ];
}