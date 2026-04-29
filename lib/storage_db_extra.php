<?php

function sb_read_access(): array
{
    $rows = sb_db_fetch_all("
        SELECT
            id,
            site_id,
            access_code,
            role,
            created_by,
            created_at,
            updated_by,
            updated_at
        FROM access
        ORDER BY site_id ASC, access_code ASC
    ");

    return array_map('sb_map_access_row', $rows);
}

function sb_write_access(array $rows): bool
{
    $pdo = sb_db();
    $pdo->beginTransaction();

    try {
        $normalizedRows = [];
        $seen = [];

        foreach ($rows as $row) {
            $siteId = (int)($row['siteId'] ?? 0);
            $accessCode = trim((string)($row['accessCode'] ?? ''));
            $role = trim((string)($row['role'] ?? ''));

            if ($siteId <= 0 || $accessCode === '' || $role === '') {
                continue;
            }

            $key = $siteId . '|' . $accessCode;

            $normalizedRows[$key] = [
                'siteId' => $siteId,
                'accessCode' => $accessCode,
                'role' => $role,
                'createdBy' => isset($row['createdBy']) ? (int)$row['createdBy'] : null,
                'createdAt' => (string)($row['createdAt'] ?? date('Y-m-d H:i:s')),
                'updatedBy' => isset($row['updatedBy']) ? (int)$row['updatedBy'] : null,
                'updatedAt' => (string)($row['updatedAt'] ?? date('Y-m-d H:i:s')),
            ];

            $seen[] = $key;
        }

        foreach ($normalizedRows as $row) {
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
                ':site_id' => $row['siteId'],
                ':access_code' => $row['accessCode'],
                ':role' => $row['role'],
                ':created_by' => $row['createdBy'],
                ':created_at' => $row['createdAt'],
                ':updated_by' => $row['updatedBy'],
                ':updated_at' => $row['updatedAt'],
            ]);
        }

        $existingRows = sb_db_fetch_all("
            SELECT site_id, access_code
            FROM sitebuilder.access
        ");

        $keysToKeep = array_flip(array_keys($normalizedRows));

        foreach ($existingRows as $existing) {
            $key = (int)$existing['site_id'] . '|' . (string)$existing['access_code'];

            if (!isset($keysToKeep[$key])) {
                sb_db_execute("
                    DELETE FROM sitebuilder.access
                    WHERE site_id = :site_id
                      AND access_code = :access_code
                ", [
                    ':site_id' => (int)$existing['site_id'],
                    ':access_code' => (string)$existing['access_code'],
                ]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function sb_find_access_row(int $siteId, string $accessCode): ?array
{
    foreach (sb_read_access() as $row) {
        if (
            (int)($row['siteId'] ?? 0) === $siteId
            && (string)($row['accessCode'] ?? '') === $accessCode
        ) {
            return $row;
        }
    }

    return null;
}

function sb_access_rows_for_site(int $siteId): array
{
    return array_values(array_filter(sb_read_access(), static function ($row) use ($siteId) {
        return (int)($row['siteId'] ?? 0) === $siteId;
    }));
}

function sb_count_site_owners(int $siteId): int
{
    $count = 0;

    foreach (sb_read_access() as $row) {
        if (
            (int)($row['siteId'] ?? 0) === $siteId
            && (string)($row['role'] ?? '') === 'OWNER'
        ) {
            $count++;
        }
    }

    return $count;
}

function sb_read_menus(): array
{
    $rows = sb_db_fetch_all("
        SELECT
            id,
            site_id,
            name,
            items_json,
            created_by,
            created_at,
            updated_by,
            updated_at
        FROM menu
        ORDER BY site_id ASC, id ASC
    ");

    return array_map('sb_map_menu_row', $rows);
}

function sb_write_menus(array $menus): bool
{
    $pdo = sb_db();
    $pdo->beginTransaction();

    try {
        $existingRows = sb_db_fetch_all("SELECT id FROM menu");
        $existingIds = array_map('intval', array_column($existingRows, 'id'));

        $incomingIds = [];

        foreach ($menus as $menu) {
            $id = (int)($menu['id'] ?? 0);

            if ($id > 0) {
                $incomingIds[] = $id;

                sb_db_execute("
                    UPDATE menu
                    SET
                        site_id = :site_id,
                        name = :name,
                        items_json = :items_json::jsonb,
                        created_by = :created_by,
                        created_at = :created_at,
                        updated_by = :updated_by,
                        updated_at = :updated_at
                    WHERE id = :id
                ", [
                    ':id' => $id,
                    ':site_id' => (int)($menu['siteId'] ?? 0),
                    ':name' => (string)($menu['name'] ?? ''),
                    ':items_json' => json_encode(array_values($menu['items'] ?? []), JSON_UNESCAPED_UNICODE),
                    ':created_by' => isset($menu['createdBy']) ? (int)$menu['createdBy'] : null,
                    ':created_at' => (string)($menu['createdAt'] ?? date('Y-m-d H:i:s')),
                    ':updated_by' => isset($menu['updatedBy']) ? (int)$menu['updatedBy'] : null,
                    ':updated_at' => (string)($menu['updatedAt'] ?? date('Y-m-d H:i:s')),
                ]);
            } else {
                sb_db_execute("
                    INSERT INTO menu (
                        site_id,
                        name,
                        items_json,
                        created_by,
                        created_at,
                        updated_by,
                        updated_at
                    ) VALUES (
                        :site_id,
                        :name,
                        :items_json::jsonb,
                        :created_by,
                        :created_at,
                        :updated_by,
                        :updated_at
                    )
                ", [
                    ':site_id' => (int)($menu['siteId'] ?? 0),
                    ':name' => (string)($menu['name'] ?? ''),
                    ':items_json' => json_encode(array_values($menu['items'] ?? []), JSON_UNESCAPED_UNICODE),
                    ':created_by' => isset($menu['createdBy']) ? (int)$menu['createdBy'] : null,
                    ':created_at' => (string)($menu['createdAt'] ?? date('Y-m-d H:i:s')),
                    ':updated_by' => isset($menu['updatedBy']) ? (int)$menu['updatedBy'] : null,
                    ':updated_at' => (string)($menu['updatedAt'] ?? date('Y-m-d H:i:s')),
                ]);
            }
        }

        $idsToDelete = array_diff($existingIds, $incomingIds);
        if (!empty($idsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $stmt = $pdo->prepare("DELETE FROM menu WHERE id IN ($placeholders)");
            $stmt->execute(array_values($idsToDelete));
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function sb_find_menu(int $id): ?array
{
    foreach (sb_read_menus() as $menu) {
        if ((int)($menu['id'] ?? 0) === $id) {
            return $menu;
        }
    }

    return null;
}

function sb_next_menu_item_id(array $items): int
{
    $maxId = 0;

    foreach ($items as $item) {
        $maxId = max($maxId, (int)($item['id'] ?? 0));
    }

    return $maxId + 1;
}

function sb_menu_next_item_sort(array $items): int
{
    $maxSort = 0;

    foreach ($items as $item) {
        $maxSort = max($maxSort, (int)($item['sort'] ?? 0));
    }

    return $maxSort + 10;
}

function sb_read_layouts(): array
{
    $rows = sb_db_fetch_all("
        SELECT
            site_id,
            settings_json,
            zones_json,
            created_by,
            created_at,
            updated_by,
            updated_at
        FROM layout
        ORDER BY site_id ASC
    ");

    return array_map('sb_map_layout_row', $rows);
}

function sb_write_layouts(array $layouts): bool
{
    $pdo = sb_db();
    $pdo->beginTransaction();

    try {
        $existingRows = sb_db_fetch_all("SELECT site_id FROM layout");
        $existingIds = array_map('intval', array_column($existingRows, 'site_id'));

        $incomingIds = [];

        foreach ($layouts as $layout) {
            $siteId = (int)($layout['siteId'] ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $incomingIds[] = $siteId;

            $zones = $layout['zones'] ?? [];
            $settings = $layout['settings'] ?? [];

            $exists = in_array($siteId, $existingIds, true);

            if ($exists) {
                sb_db_execute("
                    UPDATE layout
                    SET
                        settings_json = :settings_json::jsonb,
                        zones_json = :zones_json::jsonb,
                        created_by = :created_by,
                        created_at = :created_at,
                        updated_by = :updated_by,
                        updated_at = :updated_at
                    WHERE site_id = :site_id
                ", [
                    ':site_id' => $siteId,
                    ':settings_json' => json_encode($settings, JSON_UNESCAPED_UNICODE),
                    ':zones_json' => json_encode($zones, JSON_UNESCAPED_UNICODE),
                    ':created_by' => isset($layout['createdBy']) ? (int)$layout['createdBy'] : null,
                    ':created_at' => (string)($layout['createdAt'] ?? date('Y-m-d H:i:s')),
                    ':updated_by' => isset($layout['updatedBy']) ? (int)$layout['updatedBy'] : null,
                    ':updated_at' => (string)($layout['updatedAt'] ?? date('Y-m-d H:i:s')),
                ]);
            } else {
                sb_db_execute("
                    INSERT INTO layout (
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
                ", [
                    ':site_id' => $siteId,
                    ':settings_json' => json_encode($settings, JSON_UNESCAPED_UNICODE),
                    ':zones_json' => json_encode($zones, JSON_UNESCAPED_UNICODE),
                    ':created_by' => isset($layout['createdBy']) ? (int)$layout['createdBy'] : null,
                    ':created_at' => (string)($layout['createdAt'] ?? date('Y-m-d H:i:s')),
                    ':updated_by' => isset($layout['updatedBy']) ? (int)$layout['updatedBy'] : null,
                    ':updated_at' => (string)($layout['updatedAt'] ?? date('Y-m-d H:i:s')),
                ]);
            }
        }

        $idsToDelete = array_diff($existingIds, $incomingIds);
        if (!empty($idsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $stmt = $pdo->prepare("DELETE FROM layout WHERE site_id IN ($placeholders)");
            $stmt->execute(array_values($idsToDelete));
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function sb_map_access_row(array $row): array
{
    return [
        'id' => isset($row['id']) ? (int)$row['id'] : 0,
        'siteId' => (int)($row['site_id'] ?? 0),
        'accessCode' => (string)($row['access_code'] ?? ''),
        'role' => (string)($row['role'] ?? ''),
        'createdBy' => isset($row['created_by']) ? (int)$row['created_by'] : 0,
        'createdAt' => (string)($row['created_at'] ?? ''),
        'updatedBy' => isset($row['updated_by']) ? (int)$row['updated_by'] : 0,
        'updatedAt' => (string)($row['updated_at'] ?? ''),
    ];
}

function sb_map_menu_row(array $row): array
{
    $items = sb_json_decode_assoc($row['items_json'] ?? '[]');
    if (!is_array($items)) {
        $items = [];
    }

    return [
        'id' => (int)$row['id'],
        'siteId' => (int)($row['site_id'] ?? 0),
        'name' => (string)($row['name'] ?? ''),
        'items' => array_values($items),
        'createdBy' => isset($row['created_by']) ? (int)$row['created_by'] : 0,
        'createdAt' => (string)($row['created_at'] ?? ''),
        'updatedBy' => isset($row['updated_by']) ? (int)$row['updated_by'] : 0,
        'updatedAt' => (string)($row['updated_at'] ?? ''),
    ];
}

function sb_map_layout_row(array $row): array
{
    $settings = sb_json_decode_assoc($row['settings_json'] ?? '{}');
    $zones = sb_json_decode_assoc($row['zones_json'] ?? '{}');

    if (!isset($zones['header']) || !is_array($zones['header'])) $zones['header'] = [];
    if (!isset($zones['footer']) || !is_array($zones['footer'])) $zones['footer'] = [];
    if (!isset($zones['left']) || !is_array($zones['left'])) $zones['left'] = [];
    if (!isset($zones['right']) || !is_array($zones['right'])) $zones['right'] = [];

    return [
        'siteId' => (int)($row['site_id'] ?? 0),
        'settings' => is_array($settings) ? $settings : [],
        'zones' => $zones,
        'createdBy' => isset($row['created_by']) ? (int)$row['created_by'] : 0,
        'createdAt' => (string)($row['created_at'] ?? ''),
        'updatedBy' => isset($row['updated_by']) ? (int)$row['updated_by'] : 0,
        'updatedAt' => (string)($row['updated_at'] ?? ''),
    ];
}