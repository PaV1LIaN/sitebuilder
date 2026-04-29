<?php

global $USER;

function sb_section_require_admin(): void
{
    global $USER;

    if (!$USER || !$USER->IsAdmin()) {
        sb_json_error('BITRIX_ADMIN_REQUIRED', 403);
    }
}

function sb_section_normalize_row(array $row): array
{
    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => (string)($row['name'] ?? ''),
        'sort' => (int)($row['sort'] ?? 500),
        'createdBy' => isset($row['created_by']) ? (int)$row['created_by'] : 0,
        'createdAt' => (string)($row['created_at'] ?? ''),
        'updatedBy' => isset($row['updated_by']) ? (int)$row['updated_by'] : 0,
        'updatedAt' => (string)($row['updated_at'] ?? ''),
    ];
}

if ($action === 'section.list') {
    $rows = sb_db_fetch_all("
        SELECT
            id,
            name,
            sort,
            created_by,
            created_at,
            updated_by,
            updated_at
        FROM sitebuilder.site_section
        ORDER BY sort ASC, id ASC
    ");

    sb_json_ok([
        'sections' => array_map('sb_section_normalize_row', $rows),
        'handler' => 'section',
        'file' => __FILE__,
    ]);
}

if ($action === 'section.create') {
    sb_section_require_admin();

    $name = trim((string)($_POST['name'] ?? ''));
    $sort = (int)($_POST['sort'] ?? 500);

    if ($name === '') {
        sb_json_error('NAME_REQUIRED', 422);
    }

    $currentUserId = (int)$USER->GetID();

    $pdo = sb_db();

    $st = $pdo->prepare("
        INSERT INTO sitebuilder.site_section (
            name,
            sort,
            created_by,
            created_at,
            updated_by,
            updated_at
        ) VALUES (
            :name,
            :sort,
            :created_by,
            now(),
            :updated_by,
            now()
        )
        RETURNING
            id,
            name,
            sort,
            created_by,
            created_at,
            updated_by,
            updated_at
    ");

    $st->execute([
        ':name' => $name,
        ':sort' => $sort,
        ':created_by' => $currentUserId,
        ':updated_by' => $currentUserId,
    ]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    sb_json_ok([
        'section' => sb_section_normalize_row($row ?: []),
        'handler' => 'section',
        'file' => __FILE__,
    ]);
}

if ($action === 'section.update') {
    sb_section_require_admin();

    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $sort = (int)($_POST['sort'] ?? 500);

    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    if ($name === '') {
        sb_json_error('NAME_REQUIRED', 422);
    }

    $currentUserId = (int)$USER->GetID();

    $pdo = sb_db();

    $st = $pdo->prepare("
        UPDATE sitebuilder.site_section
        SET
            name = :name,
            sort = :sort,
            updated_by = :updated_by,
            updated_at = now()
        WHERE id = :id
        RETURNING
            id,
            name,
            sort,
            created_by,
            created_at,
            updated_by,
            updated_at
    ");

    $st->execute([
        ':id' => $id,
        ':name' => $name,
        ':sort' => $sort,
        ':updated_by' => $currentUserId,
    ]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        sb_json_error('SECTION_NOT_FOUND', 404);
    }

    sb_json_ok([
        'section' => sb_section_normalize_row($row),
        'handler' => 'section',
        'file' => __FILE__,
    ]);
}

if ($action === 'section.delete') {
    sb_section_require_admin();

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    $pdo = sb_db();
    $pdo->beginTransaction();

    try {
        $st = $pdo->prepare("
            UPDATE sitebuilder.site
            SET section_id = NULL
            WHERE section_id = :id
        ");
        $st->execute([':id' => $id]);

        $st = $pdo->prepare("
            DELETE FROM sitebuilder.site_section
            WHERE id = :id
        ");
        $st->execute([':id' => $id]);

        $pdo->commit();

        sb_json_ok([
            'deleted' => true,
            'id' => $id,
            'handler' => 'section',
            'file' => __FILE__,
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();

        sb_json_error($e->getMessage(), 500, [
            'handler' => 'section',
            'file' => __FILE__,
        ]);
    }
}

if ($action === 'site.setSection') {
    sb_section_require_admin();

    $siteId = (int)($_POST['siteId'] ?? 0);
    $sectionId = (int)($_POST['sectionId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    if ($sectionId > 0) {
        $exists = sb_db_fetch_all("
            SELECT id
            FROM sitebuilder.site_section
            WHERE id = :id
            LIMIT 1
        ", [
            ':id' => $sectionId,
        ]);

        if (empty($exists)) {
            sb_json_error('SECTION_NOT_FOUND', 404);
        }
    }

    sb_db_execute("
        UPDATE sitebuilder.site
        SET
            section_id = :section_id,
            updated_by = :updated_by,
            updated_at = now()
        WHERE id = :site_id
    ", [
        ':site_id' => $siteId,
        ':section_id' => $sectionId > 0 ? $sectionId : null,
        ':updated_by' => (int)$USER->GetID(),
    ]);

    $site = sb_find_site($siteId);

    sb_json_ok([
        'site' => $site,
        'handler' => 'section',
        'action' => 'site.setSection',
        'file' => __FILE__,
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'section',
    'action' => $action,
    'file' => __FILE__,
]);