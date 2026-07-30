<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';

sitebuilder_require_bitrix_admin();

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';

function sb_migrate_read_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function sb_migrate_upsert_sites(array $sites): void
{
    foreach ($sites as $site) {
        sb_db_execute("
            INSERT INTO sitebuilder.site (
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
                updated_at
            ) VALUES (
                :id,
                :name,
                :slug,
                :home_page_id,
                :disk_folder_id,
                :top_menu_id,
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
                home_page_id = EXCLUDED.home_page_id,
                disk_folder_id = EXCLUDED.disk_folder_id,
                top_menu_id = EXCLUDED.top_menu_id,
                settings_json = EXCLUDED.settings_json,
                layout_json = EXCLUDED.layout_json,
                created_by = EXCLUDED.created_by,
                created_at = EXCLUDED.created_at,
                updated_by = EXCLUDED.updated_by,
                updated_at = EXCLUDED.updated_at
        ", [
            ':id' => (int)($site['id'] ?? 0),
            ':name' => (string)($site['name'] ?? ''),
            ':slug' => (string)($site['slug'] ?? ''),
            ':home_page_id' => !empty($site['homePageId']) ? (int)$site['homePageId'] : null,
            ':disk_folder_id' => !empty($site['diskFolderId']) ? (int)$site['diskFolderId'] : null,
            ':top_menu_id' => !empty($site['topMenuId']) ? (int)$site['topMenuId'] : null,
            ':settings_json' => json_encode($site['settings'] ?? [], JSON_UNESCAPED_UNICODE),
            ':layout_json' => json_encode($site['layout'] ?? [], JSON_UNESCAPED_UNICODE),
            ':created_by' => isset($site['createdBy']) ? (int)$site['createdBy'] : null,
            ':created_at' => (string)($site['createdAt'] ?? date('Y-m-d H:i:s')),
            ':updated_by' => isset($site['updatedBy']) ? (int)$site['updatedBy'] : null,
            ':updated_at' => (string)($site['updatedAt'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    sb_db()->exec("SELECT setval(pg_get_serial_sequence('site', 'id'), COALESCE((SELECT MAX(id) FROM sitebuilder.site), 1), true)");
}

function sb_migrate_upsert_pages(array $pages): void
{
    foreach ($pages as $page) {
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
            ':id' => (int)($page['id'] ?? 0),
            ':site_id' => (int)($page['siteId'] ?? 0),
            ':title' => (string)($page['title'] ?? ''),
            ':slug' => (string)($page['slug'] ?? ''),
            ':parent_id' => !empty($page['parentId']) ? (int)$page['parentId'] : null,
            ':sort' => (int)($page['sort'] ?? 500),
            ':status' => (string)($page['status'] ?? 'draft'),
            ':published_at' => !empty($page['publishedAt']) ? (string)$page['publishedAt'] : null,
            ':created_by' => isset($page['createdBy']) ? (int)$page['createdBy'] : null,
            ':created_at' => (string)($page['createdAt'] ?? date('Y-m-d H:i:s')),
            ':updated_by' => isset($page['updatedBy']) ? (int)$page['updatedBy'] : null,
            ':updated_at' => (string)($page['updatedAt'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    sb_db()->exec("SELECT setval(pg_get_serial_sequence('page', 'id'), COALESCE((SELECT MAX(id) FROM sitebuilder.page), 1), true)");
}

function sb_migrate_upsert_blocks(array $blocks): void
{
    foreach ($blocks as $block) {
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
            ':id' => (int)($block['id'] ?? 0),
            ':page_id' => (int)($block['pageId'] ?? 0),
            ':type' => (string)($block['type'] ?? ''),
            ':sort' => (int)($block['sort'] ?? 500),
            ':content_json' => json_encode($block['content'] ?? [], JSON_UNESCAPED_UNICODE),
            ':props_json' => json_encode($block['props'] ?? [], JSON_UNESCAPED_UNICODE),
            ':created_by' => isset($block['createdBy']) ? (int)$block['createdBy'] : null,
            ':created_at' => (string)($block['createdAt'] ?? date('Y-m-d H:i:s')),
            ':updated_by' => isset($block['updatedBy']) ? (int)$block['updatedBy'] : null,
            ':updated_at' => (string)($block['updatedAt'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    sb_db()->exec("SELECT setval(pg_get_serial_sequence('block', 'id'), COALESCE((SELECT MAX(id) FROM sitebuilder.block), 1), true)");
}

function sb_migrate_upsert_access(array $rows): void
{
    foreach ($rows as $row) {
        sb_db_execute("
            INSERT INTO sitebuilder.access (
                site_id,
                access_code,
                role,
                created_by,
                created_at,
                updated_by,
                updated_at
            ) VALUES (
                :site_id,
                :access_code,
                :role,
                :created_by,
                :created_at,
                :updated_by,
                :updated_at
            )
            ON CONFLICT (site_id, access_code)
            DO UPDATE SET
                role = EXCLUDED.role,
                created_by = EXCLUDED.created_by,
                created_at = EXCLUDED.created_at,
                updated_by = EXCLUDED.updated_by,
                updated_at = EXCLUDED.updated_at
        ", [
            ':site_id' => (int)($row['siteId'] ?? 0),
            ':access_code' => (string)($row['accessCode'] ?? ''),
            ':role' => (string)($row['role'] ?? ''),
            ':created_by' => isset($row['createdBy']) ? (int)$row['createdBy'] : null,
            ':created_at' => (string)($row['createdAt'] ?? date('Y-m-d H:i:s')),
            ':updated_by' => isset($row['updatedBy']) ? (int)$row['updatedBy'] : null,
            ':updated_at' => (string)($row['updatedAt'] ?? date('Y-m-d H:i:s')),
        ]);
    }
}

function sb_migrate_upsert_menus(array $menus): void
{
    foreach ($menus as $menu) {
        sb_db_execute("
            INSERT INTO sitebuilder.menu (
                id,
                site_id,
                name,
                items_json,
                created_by,
                created_at,
                updated_by,
                updated_at
            ) VALUES (
                :id,
                :site_id,
                :name,
                :items_json::jsonb,
                :created_by,
                :created_at,
                :updated_by,
                :updated_at
            )
            ON CONFLICT (id)
            DO UPDATE SET
                site_id = EXCLUDED.site_id,
                name = EXCLUDED.name,
                items_json = EXCLUDED.items_json,
                created_by = EXCLUDED.created_by,
                created_at = EXCLUDED.created_at,
                updated_by = EXCLUDED.updated_by,
                updated_at = EXCLUDED.updated_at
        ", [
            ':id' => (int)($menu['id'] ?? 0),
            ':site_id' => (int)($menu['siteId'] ?? 0),
            ':name' => (string)($menu['name'] ?? ''),
            ':items_json' => json_encode(array_values($menu['items'] ?? []), JSON_UNESCAPED_UNICODE),
            ':created_by' => isset($menu['createdBy']) ? (int)$menu['createdBy'] : null,
            ':created_at' => (string)($menu['createdAt'] ?? date('Y-m-d H:i:s')),
            ':updated_by' => isset($menu['updatedBy']) ? (int)$menu['updatedBy'] : null,
            ':updated_at' => (string)($menu['updatedAt'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    sb_db()->exec("SELECT setval(pg_get_serial_sequence('menu', 'id'), COALESCE((SELECT MAX(id) FROM sitebuilder.menu), 1), true)");
}

function sb_migrate_upsert_layouts(array $layouts): void
{
    foreach ($layouts as $layout) {
        sb_db_execute("
            INSERT INTO sitebuilder.layout (
                site_id,
                settings_json,
                zones_json,
                created_by,
                created_at,
                updated_by,
                updated_at
            ) VALUES (
                :site_id,
                :settings_json::jsonb,
                :zones_json::jsonb,
                :created_by,
                :created_at,
                :updated_by,
                :updated_at
            )
            ON CONFLICT (site_id)
            DO UPDATE SET
                settings_json = EXCLUDED.settings_json,
                zones_json = EXCLUDED.zones_json,
                created_by = EXCLUDED.created_by,
                created_at = EXCLUDED.created_at,
                updated_by = EXCLUDED.updated_by,
                updated_at = EXCLUDED.updated_at
        ", [
            ':site_id' => (int)($layout['siteId'] ?? 0),
            ':settings_json' => json_encode($layout['settings'] ?? [], JSON_UNESCAPED_UNICODE),
            ':zones_json' => json_encode($layout['zones'] ?? [], JSON_UNESCAPED_UNICODE),
            ':created_by' => isset($layout['createdBy']) ? (int)$layout['createdBy'] : null,
            ':created_at' => (string)($layout['createdAt'] ?? date('Y-m-d H:i:s')),
            ':updated_by' => isset($layout['updatedBy']) ? (int)$layout['updatedBy'] : null,
            ':updated_at' => (string)($layout['updatedAt'] ?? date('Y-m-d H:i:s')),
        ]);
    }
}

$storageDir = $_SERVER['DOCUMENT_ROOT'] . '/upload/sitebuilder';

$sites = sb_migrate_read_json_file($storageDir . '/sites.json');
$pages = sb_migrate_read_json_file($storageDir . '/pages.json');
$blocks = sb_migrate_read_json_file($storageDir . '/blocks.json');
$access = sb_migrate_read_json_file($storageDir . '/access.json');
$menus = sb_migrate_read_json_file($storageDir . '/menus.json');
$layouts = sb_migrate_read_json_file($storageDir . '/layouts.json');

echo '<pre>';

try {
    sb_db()->beginTransaction();

    sb_migrate_upsert_sites($sites);
    echo "sites migrated: " . count($sites) . PHP_EOL;

    sb_migrate_upsert_pages($pages);
    echo "pages migrated: " . count($pages) . PHP_EOL;

    sb_migrate_upsert_blocks($blocks);
    echo "blocks migrated: " . count($blocks) . PHP_EOL;

    sb_migrate_upsert_access($access);
    echo "access migrated: " . count($access) . PHP_EOL;

    sb_migrate_upsert_menus($menus);
    echo "menus migrated: " . count($menus) . PHP_EOL;

    sb_migrate_upsert_layouts($layouts);
    echo "layouts migrated: " . count($layouts) . PHP_EOL;

    sb_db()->commit();
    echo PHP_EOL . "DONE" . PHP_EOL;
} catch (Throwable $e) {
    sb_db()->rollBack();
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
}

echo '</pre>';