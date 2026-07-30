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
    ]);
}

if ($action === 'section.delete') {
    sb_section_require_admin();

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    $pdo = sb_db();
    $startedHere = sb_db_transaction_scope_begin();

    try {
        $versionMap = RevisionService::decodeVersionMap(
            $_POST['expectedVersions'] ?? null
        );

        $affectedRows = sb_db_fetch_all("
            SELECT id
            FROM sitebuilder.site
            WHERE section_id = :id
            ORDER BY id ASC
            FOR UPDATE
        ", [':id' => $id]);

        foreach ($affectedRows as $affectedRow) {
            $affectedSiteId = (int)$affectedRow['id'];
            $site = RevisionService::getSite($affectedSiteId, false);
            if (!$site) {
                throw new RuntimeException('SITE_NOT_FOUND');
            }

            $site['sectionId'] = 0;
            RevisionService::saveSite(
                $site,
                RevisionService::requireVersionFromMap(
                    $versionMap,
                    $affectedSiteId
                ),
                (int)$USER->GetID(),
                'section_deleted'
            );
        }

        $st = $pdo->prepare("
            DELETE FROM sitebuilder.site_section
            WHERE id = :id
        ");
        $st->execute([':id' => $id]);

        sb_db_transaction_scope_commit($startedHere);

        sb_json_ok([
            'deleted' => true,
            'id' => $id,
            'handler' => 'section',
        ]);
    } catch (SiteBuilderVersionConflictException|InvalidArgumentException $e) {
        sb_db_transaction_scope_rollback($startedHere);
        throw $e;
    } catch (PDOException $e) {
        sb_db_transaction_scope_rollback($startedHere);
        $sqlState = sb_db_exception_sqlstate($e);
        if ($sqlState === '55P03') {
            sb_json_error('RESOURCE_BUSY', 423);
        }
        if ($sqlState === '40P01' || $sqlState === '40001') {
            sb_json_error('RETRY_TRANSACTION', 409);
        }
        error_log('SiteBuilder section.delete database error [' . $sqlState . ']: ' . $e->getMessage());
        sb_json_error('SECTION_DELETE_FAILED', 500);
    } catch (Throwable $e) {
        sb_db_transaction_scope_rollback($startedHere);
        error_log('SiteBuilder section.delete failed: ' . $e->getMessage());

        sb_json_error('SECTION_DELETE_FAILED', 500, [
            'handler' => 'section',
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

    $site = RevisionService::getSite($siteId, false);
    if (!$site) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }
    $site['sectionId'] = $sectionId;
    $site = RevisionService::saveSite(
        $site,
        RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null),
        (int)$USER->GetID(),
        'section_change'
    );

    sb_json_ok([
        'site' => $site,
        'handler' => 'section',
        'action' => 'site.setSection',
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'section',
    'action' => $action,
]);