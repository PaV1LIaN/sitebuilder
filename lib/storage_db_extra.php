<?php

/**
 * Работа с глобальными ролями SiteBuilder.
 *
 * Изменения выполняются точечно и блокируются на уровне одного сайта.
 * Это предотвращает потерю чужих изменений из-за схемы
 * "прочитать всю таблицу -> изменить массив -> перезаписать всю таблицу".
 */

function sb_access_allowed_roles(): array
{
    return [
        'VIEWER',
        'EDITOR',
        'ADMIN',
        'OWNER',
    ];
}

function sb_access_normalize_role(string $role): string
{
    $role = strtoupper(trim($role));

    if (!in_array($role, sb_access_allowed_roles(), true)) {
        throw new RuntimeException('INVALID_ROLE');
    }

    return $role;
}

function sb_access_normalize_code(string $accessCode): string
{
    $accessCode = trim($accessCode);

    if (
        $accessCode === ''
        || mb_strlen($accessCode) > 128
        || preg_match('/[\\x00-\\x20]/u', $accessCode)
    ) {
        throw new RuntimeException('INVALID_ACCESS_CODE');
    }

    return $accessCode;
}

function sb_access_lock_site(PDO $pdo, int $siteId): void
{
    if ($siteId <= 0) {
        throw new RuntimeException('INVALID_SITE_ID');
    }

    /*
     * Двухключевой advisory lock PostgreSQL.
     * Первый ключ — пространство SiteBuilder access,
     * второй — ID сайта.
     */
    $stmt = $pdo->prepare(
        'SELECT pg_advisory_xact_lock(761234, CAST(:site_id AS integer))'
    );
    $stmt->execute([
        ':site_id' => $siteId,
    ]);
}

function sb_access_begin_transaction(PDO $pdo, int $siteId): bool
{
    $startedHere = !$pdo->inTransaction();

    if ($startedHere) {
        $pdo->beginTransaction();
    }

    sb_access_lock_site($pdo, $siteId);

    return $startedHere;
}

function sb_access_finish_transaction(PDO $pdo, bool $startedHere): void
{
    if ($startedHere && $pdo->inTransaction()) {
        $pdo->commit();
    }
}

function sb_access_rollback_transaction(PDO $pdo, bool $startedHere): void
{
    if ($startedHere && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

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
        FROM sitebuilder.access
        ORDER BY site_id ASC, access_code ASC
    ");

    return array_map('sb_map_access_row', $rows);
}

function sb_find_access_row(int $siteId, string $accessCode): ?array
{
    if ($siteId <= 0) {
        return null;
    }

    $accessCode = trim($accessCode);

    if ($accessCode === '') {
        return null;
    }

    $row = sb_db_fetch_one("
        SELECT
            id,
            site_id,
            access_code,
            role,
            created_by,
            created_at,
            updated_by,
            updated_at
        FROM sitebuilder.access
        WHERE site_id = :site_id
          AND access_code = :access_code
        LIMIT 1
    ", [
        ':site_id' => $siteId,
        ':access_code' => $accessCode,
    ]);

    return $row ? sb_map_access_row($row) : null;
}

function sb_access_rows_for_site(int $siteId): array
{
    if ($siteId <= 0) {
        return [];
    }

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
        FROM sitebuilder.access
        WHERE site_id = :site_id
        ORDER BY access_code ASC
    ", [
        ':site_id' => $siteId,
    ]);

    return array_map('sb_map_access_row', $rows);
}

function sb_count_site_owners(int $siteId): int
{
    if ($siteId <= 0) {
        return 0;
    }

    $row = sb_db_fetch_one("
        SELECT COUNT(*) AS cnt
        FROM sitebuilder.access
        WHERE site_id = :site_id
          AND role = 'OWNER'
    ", [
        ':site_id' => $siteId,
    ]);

    return (int)($row['cnt'] ?? 0);
}

/**
 * Атомарно назначает роль одной записи доступа.
 *
 * options:
 * - allowOwnerAssignment: разрешить назначение OWNER;
 * - allowOwnerDowngrade: разрешить понижение существующего OWNER;
 * - protectLastOwner: не позволять понизить последнего OWNER.
 */
function sb_set_access_role(
    int $siteId,
    string $accessCode,
    string $role,
    int $actorUserId,
    array $options = []
): array {
    if ($siteId <= 0) {
        throw new RuntimeException('INVALID_SITE_ID');
    }

    $accessCode = sb_access_normalize_code($accessCode);
    $role = sb_access_normalize_role($role);

    $allowOwnerAssignment = !empty($options['allowOwnerAssignment']);
    $allowOwnerDowngrade = !empty($options['allowOwnerDowngrade']);
    $protectLastOwner = !array_key_exists('protectLastOwner', $options)
        || !empty($options['protectLastOwner']);

    $pdo = sb_db();
    $startedHere = sb_access_begin_transaction($pdo, $siteId);

    try {
        $stmt = $pdo->prepare("
            SELECT
                id,
                site_id,
                access_code,
                role,
                created_by,
                created_at,
                updated_by,
                updated_at
            FROM sitebuilder.access
            WHERE site_id = :site_id
              AND access_code = :access_code
            FOR UPDATE
        ");
        $stmt->execute([
            ':site_id' => $siteId,
            ':access_code' => $accessCode,
        ]);

        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $existingRole = strtoupper(trim((string)($existing['role'] ?? '')));

        if (
            $role === 'OWNER'
            && $existingRole !== 'OWNER'
            && !$allowOwnerAssignment
        ) {
            throw new RuntimeException('OWNER_ASSIGNMENT_FORBIDDEN');
        }

        if ($existingRole === 'OWNER' && $role !== 'OWNER') {
            if (!$allowOwnerDowngrade) {
                throw new RuntimeException('CANNOT_DOWNGRADE_OWNER');
            }

            if ($protectLastOwner) {
                $ownerStmt = $pdo->prepare("
                    SELECT COUNT(*) AS cnt
                    FROM sitebuilder.access
                    WHERE site_id = :site_id
                      AND role = 'OWNER'
                ");
                $ownerStmt->execute([
                    ':site_id' => $siteId,
                ]);

                $ownerCount = (int)($ownerStmt->fetchColumn() ?: 0);

                if ($ownerCount <= 1) {
                    throw new RuntimeException('LAST_OWNER_CANNOT_BE_DOWNGRADED');
                }
            }
        }

        $now = date('c');

        $upsert = $pdo->prepare("
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
                updated_by = EXCLUDED.updated_by,
                updated_at = EXCLUDED.updated_at
            RETURNING
                id,
                site_id,
                access_code,
                role,
                created_by,
                created_at,
                updated_by,
                updated_at
        ");

        $upsert->execute([
            ':site_id' => $siteId,
            ':access_code' => $accessCode,
            ':role' => $role,
            ':created_by' => $actorUserId > 0 ? $actorUserId : null,
            ':created_at' => $now,
            ':updated_by' => $actorUserId > 0 ? $actorUserId : null,
            ':updated_at' => $now,
        ]);

        $saved = $upsert->fetch(PDO::FETCH_ASSOC);

        if (!$saved) {
            throw new RuntimeException('ACCESS_ROLE_SAVE_FAILED');
        }

        sb_access_finish_transaction($pdo, $startedHere);

        return [
            'row' => sb_map_access_row($saved),
            'created' => $existing === null,
            'updated' => $existing !== null,
        ];
    } catch (Throwable $e) {
        sb_access_rollback_transaction($pdo, $startedHere);
        throw $e;
    }
}

/**
 * Добавляет роль только при отсутствии записи.
 * Существующее прямое назначение никогда не перезаписывается.
 */
function sb_add_access_role_if_missing(
    int $siteId,
    string $accessCode,
    string $role,
    int $actorUserId
): array {
    if ($siteId <= 0) {
        throw new RuntimeException('INVALID_SITE_ID');
    }

    $accessCode = sb_access_normalize_code($accessCode);
    $role = sb_access_normalize_role($role);

    $pdo = sb_db();
    $startedHere = sb_access_begin_transaction($pdo, $siteId);

    try {
        $now = date('c');

        $stmt = $pdo->prepare("
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
            DO NOTHING
            RETURNING
                id,
                site_id,
                access_code,
                role,
                created_by,
                created_at,
                updated_by,
                updated_at
        ");

        $stmt->execute([
            ':site_id' => $siteId,
            ':access_code' => $accessCode,
            ':role' => $role,
            ':created_by' => $actorUserId > 0 ? $actorUserId : null,
            ':created_at' => $now,
            ':updated_by' => $actorUserId > 0 ? $actorUserId : null,
            ':updated_at' => $now,
        ]);

        $inserted = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($inserted) {
            sb_access_finish_transaction($pdo, $startedHere);

            return [
                'row' => sb_map_access_row($inserted),
                'created' => true,
            ];
        }

        $existingStmt = $pdo->prepare("
            SELECT
                id,
                site_id,
                access_code,
                role,
                created_by,
                created_at,
                updated_by,
                updated_at
            FROM sitebuilder.access
            WHERE site_id = :site_id
              AND access_code = :access_code
            LIMIT 1
        ");
        $existingStmt->execute([
            ':site_id' => $siteId,
            ':access_code' => $accessCode,
        ]);

        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            throw new RuntimeException('ACCESS_ROLE_READ_AFTER_CONFLICT_FAILED');
        }

        sb_access_finish_transaction($pdo, $startedHere);

        return [
            'row' => sb_map_access_row($existing),
            'created' => false,
        ];
    } catch (Throwable $e) {
        sb_access_rollback_transaction($pdo, $startedHere);
        throw $e;
    }
}

/**
 * Атомарно удаляет одну запись доступа.
 */
function sb_delete_access_row(
    int $siteId,
    string $accessCode,
    array $options = []
): ?array {
    if ($siteId <= 0) {
        throw new RuntimeException('INVALID_SITE_ID');
    }

    $accessCode = sb_access_normalize_code($accessCode);
    $allowOwnerRemoval = !empty($options['allowOwnerRemoval']);
    $protectLastOwner = !array_key_exists('protectLastOwner', $options)
        || !empty($options['protectLastOwner']);

    $pdo = sb_db();
    $startedHere = sb_access_begin_transaction($pdo, $siteId);

    try {
        $stmt = $pdo->prepare("
            SELECT
                id,
                site_id,
                access_code,
                role,
                created_by,
                created_at,
                updated_by,
                updated_at
            FROM sitebuilder.access
            WHERE site_id = :site_id
              AND access_code = :access_code
            FOR UPDATE
        ");
        $stmt->execute([
            ':site_id' => $siteId,
            ':access_code' => $accessCode,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$row) {
            sb_access_finish_transaction($pdo, $startedHere);
            return null;
        }

        $role = strtoupper(trim((string)($row['role'] ?? '')));

        if ($role === 'OWNER') {
            if ($protectLastOwner) {
                $ownerStmt = $pdo->prepare("
                    SELECT COUNT(*) AS cnt
                    FROM sitebuilder.access
                    WHERE site_id = :site_id
                      AND role = 'OWNER'
                ");
                $ownerStmt->execute([
                    ':site_id' => $siteId,
                ]);

                $ownerCount = (int)($ownerStmt->fetchColumn() ?: 0);

                if ($ownerCount <= 1) {
                    throw new RuntimeException('LAST_OWNER_CANNOT_BE_REMOVED');
                }
            }

            if (!$allowOwnerRemoval) {
                throw new RuntimeException('OWNER_DELETE_FORBIDDEN');
            }
        }

        $delete = $pdo->prepare("
            DELETE FROM sitebuilder.access
            WHERE site_id = :site_id
              AND access_code = :access_code
        ");
        $delete->execute([
            ':site_id' => $siteId,
            ':access_code' => $accessCode,
        ]);

        sb_access_finish_transaction($pdo, $startedHere);

        return sb_map_access_row($row);
    } catch (Throwable $e) {
        sb_access_rollback_transaction($pdo, $startedHere);
        throw $e;
    }
}

function sb_delete_access_for_site(int $siteId): int
{
    if ($siteId <= 0) {
        return 0;
    }

    $pdo = sb_db();
    $startedHere = sb_access_begin_transaction($pdo, $siteId);

    try {
        $stmt = $pdo->prepare("
            DELETE FROM sitebuilder.access
            WHERE site_id = :site_id
        ");
        $stmt->execute([
            ':site_id' => $siteId,
        ]);

        $deleted = $stmt->rowCount();
        sb_access_finish_transaction($pdo, $startedHere);

        return $deleted;
    } catch (Throwable $e) {
        sb_access_rollback_transaction($pdo, $startedHere);
        throw $e;
    }
}

/**
 * Заменяет права только одного сайта.
 * Другие сайты никогда не затрагиваются.
 */
function sb_replace_site_access_rows(
    int $siteId,
    array $rows,
    bool $deleteMissing = true,
    int $actorUserId = 0
): bool {
    if ($siteId <= 0) {
        throw new RuntimeException('INVALID_SITE_ID');
    }

    $normalized = [];

    foreach ($rows as $row) {
        $rowSiteId = (int)($row['siteId'] ?? $siteId);

        if ($rowSiteId !== $siteId) {
            continue;
        }

        $accessCode = sb_access_normalize_code(
            (string)($row['accessCode'] ?? '')
        );
        $role = sb_access_normalize_role(
            (string)($row['role'] ?? '')
        );

        $normalized[$accessCode] = [
            'siteId' => $siteId,
            'accessCode' => $accessCode,
            'role' => $role,
            'createdBy' => isset($row['createdBy'])
                ? (int)$row['createdBy']
                : $actorUserId,
            'createdAt' => (string)($row['createdAt'] ?? date('c')),
            'updatedBy' => isset($row['updatedBy'])
                ? (int)$row['updatedBy']
                : $actorUserId,
            'updatedAt' => (string)($row['updatedAt'] ?? date('c')),
        ];
    }

    $pdo = sb_db();
    $startedHere = sb_access_begin_transaction($pdo, $siteId);

    try {
        $existingStmt = $pdo->prepare("
            SELECT
                id,
                site_id,
                access_code,
                role,
                created_by,
                created_at,
                updated_by,
                updated_at
            FROM sitebuilder.access
            WHERE site_id = :site_id
            FOR UPDATE
        ");
        $existingStmt->execute([
            ':site_id' => $siteId,
        ]);

        $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
        $existingByCode = [];

        foreach ($existingRows as $existing) {
            $existingByCode[(string)$existing['access_code']] = $existing;
        }

        /*
         * Даже при строгой синхронизации нельзя оставить сайт без OWNER.
         * Если входной набор не содержит владельца, сохраняем существующих.
         */
        $incomingHasOwner = false;

        foreach ($normalized as $row) {
            if ($row['role'] === 'OWNER') {
                $incomingHasOwner = true;
                break;
            }
        }

        if (!$incomingHasOwner) {
            foreach ($existingByCode as $code => $existing) {
                if (strtoupper((string)$existing['role']) !== 'OWNER') {
                    continue;
                }

                $normalized[$code] = [
                    'siteId' => $siteId,
                    'accessCode' => $code,
                    'role' => 'OWNER',
                    'createdBy' => isset($existing['created_by'])
                        ? (int)$existing['created_by']
                        : 0,
                    'createdAt' => (string)($existing['created_at'] ?? date('c')),
                    'updatedBy' => isset($existing['updated_by'])
                        ? (int)$existing['updated_by']
                        : 0,
                    'updatedAt' => (string)($existing['updated_at'] ?? date('c')),
                ];
            }
        }

        $upsert = $pdo->prepare("
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
                updated_by = EXCLUDED.updated_by,
                updated_at = EXCLUDED.updated_at
        ");

        foreach ($normalized as $row) {
            $upsert->execute([
                ':site_id' => $siteId,
                ':access_code' => $row['accessCode'],
                ':role' => $row['role'],
                ':created_by' => $row['createdBy'] > 0 ? $row['createdBy'] : null,
                ':created_at' => $row['createdAt'],
                ':updated_by' => $row['updatedBy'] > 0 ? $row['updatedBy'] : null,
                ':updated_at' => $row['updatedAt'],
            ]);
        }

        if ($deleteMissing) {
            $keepCodes = array_fill_keys(array_keys($normalized), true);
            $deleteStmt = $pdo->prepare("
                DELETE FROM sitebuilder.access
                WHERE site_id = :site_id
                  AND access_code = :access_code
            ");

            foreach ($existingByCode as $code => $existing) {
                if (isset($keepCodes[$code])) {
                    continue;
                }

                $deleteStmt->execute([
                    ':site_id' => $siteId,
                    ':access_code' => $code,
                ]);
            }
        }

        sb_access_finish_transaction($pdo, $startedHere);
        return true;
    } catch (Throwable $e) {
        sb_access_rollback_transaction($pdo, $startedHere);
        throw $e;
    }
}

/**
 * Устаревший полный replace всей таблицы.
 * Оставлен только для обратной совместимости и миграций.
 * Рабочие API должны использовать точечные функции выше.
 */
function sb_write_access(array $rows): bool
{
    $pdo = sb_db();
    $startedHere = !$pdo->inTransaction();

    if ($startedHere) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->query('SELECT pg_advisory_xact_lock(761234, 0)');

        $grouped = [];

        foreach ($rows as $row) {
            $siteId = (int)($row['siteId'] ?? 0);

            if ($siteId <= 0) {
                continue;
            }

            $grouped[$siteId][] = $row;
        }

        $existingSiteRows = sb_db_fetch_all("
            SELECT DISTINCT site_id
            FROM sitebuilder.access
        ");

        $allSiteIds = array_values(array_unique(array_merge(
            array_map('intval', array_keys($grouped)),
            array_map('intval', array_column($existingSiteRows, 'site_id'))
        )));

        foreach ($allSiteIds as $siteId) {
            sb_replace_site_access_rows(
                $siteId,
                $grouped[$siteId] ?? [],
                true,
                0
            );
        }

        if ($startedHere && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return true;
    } catch (Throwable $e) {
        if ($startedHere && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
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
            updated_at,
            version
        FROM menu
        ORDER BY site_id ASC, id ASC
    ");

    return array_map('sb_map_menu_row', $rows);
}

function sb_write_menus(array $menus): bool
{
    $startedHere = sb_db_transaction_scope_begin();

    try {
        foreach ($menus as $menu) {
            $id = (int)($menu['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $stmt = sb_db()->prepare("\n                INSERT INTO sitebuilder.menu (\n                    id,site_id,name,items_json,created_by,created_at,updated_by,updated_at,version\n                ) VALUES (\n                    :id,:site_id,:name,CAST(:items_json AS jsonb),\n                    :created_by,:created_at,:updated_by,:updated_at,:version\n                )\n                RETURNING id,site_id,name,items_json,created_by,created_at,updated_by,updated_at,version\n            ");
            $stmt->execute([
                ':id' => $id,
                ':site_id' => (int)($menu['siteId'] ?? 0),
                ':name' => (string)($menu['name'] ?? ''),
                ':items_json' => json_encode(array_values($menu['items'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':created_by' => isset($menu['createdBy']) ? (int)$menu['createdBy'] : null,
                ':created_at' => (string)($menu['createdAt'] ?? date('c')),
                ':updated_by' => isset($menu['updatedBy']) ? (int)$menu['updatedBy'] : null,
                ':updated_at' => (string)($menu['updatedAt'] ?? date('c')),
                ':version' => max(1, (int)($menu['version'] ?? 1)),
            ]);

            $row = $stmt->fetch();
            if ($row && class_exists('RevisionService')) {
                $saved = sb_map_menu_row($row);
                RevisionService::recordMenu(
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
            updated_at,
            version
        FROM layout
        ORDER BY site_id ASC
    ");

    return array_map('sb_map_layout_row', $rows);
}

function sb_write_layouts(array $layouts): bool
{
    $startedHere = sb_db_transaction_scope_begin();

    try {
        foreach ($layouts as $layout) {
            $siteId = (int)($layout['siteId'] ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $stmt = sb_db()->prepare("\n                INSERT INTO sitebuilder.layout (\n                    site_id,settings_json,zones_json,created_by,created_at,updated_by,updated_at,version\n                ) VALUES (\n                    :site_id,CAST(:settings_json AS jsonb),CAST(:zones_json AS jsonb),\n                    :created_by,:created_at,:updated_by,:updated_at,:version\n                )\n                RETURNING site_id,settings_json,zones_json,created_by,created_at,updated_by,updated_at,version\n            ");
            $stmt->execute([
                ':site_id' => $siteId,
                ':settings_json' => json_encode($layout['settings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':zones_json' => json_encode($layout['zones'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':created_by' => isset($layout['createdBy']) ? (int)$layout['createdBy'] : null,
                ':created_at' => (string)($layout['createdAt'] ?? date('c')),
                ':updated_by' => isset($layout['updatedBy']) ? (int)$layout['updatedBy'] : null,
                ':updated_at' => (string)($layout['updatedAt'] ?? date('c')),
                ':version' => max(1, (int)($layout['version'] ?? 1)),
            ]);

            $row = $stmt->fetch();
            if ($row && class_exists('RevisionService')) {
                $saved = sb_map_layout_row($row);
                RevisionService::recordLayout(
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
        'version' => max(1, (int)($row['version'] ?? 1)),
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
        'version' => max(1, (int)($row['version'] ?? 1)),
    ];
}