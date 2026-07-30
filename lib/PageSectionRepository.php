<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/RevisionService.php';

/**
 * PostgreSQL-хранилище секций страниц.
 *
 * Начиная с этапа 7 файл /upload/sitebuilder/page_sections.json используется
 * только как источник одноразового импорта. Рабочие операции выполняются
 * транзакционно в sitebuilder.page_section.
 */
final class PageSectionRepository
{
    private const LEGACY_FILE_PATH = '/upload/sitebuilder/page_sections.json';
    private const LOCK_NAMESPACE = 761242;

    public static function readAll(): array
    {
        $rows = sb_db_fetch_all("
            SELECT id,site_id,page_id,type,title,sort,layout_json,props_json,
                   created_by,created_at,updated_by,updated_at,version
            FROM sitebuilder.page_section
            ORDER BY site_id ASC,page_id ASC,sort ASC,id ASC
        ");

        return array_map([self::class, 'mapRow'], $rows);
    }

    /**
     * Массовая перезапись отключена: она обходила optimistic locking.
     */
    public static function writeAll(array $items): void
    {
        throw new RuntimeException('PAGE_SECTION_BULK_WRITE_DISABLED');
    }

    public static function listForSite(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $rows = sb_db_fetch_all("
            SELECT id,site_id,page_id,type,title,sort,layout_json,props_json,
                   created_by,created_at,updated_by,updated_at,version
            FROM sitebuilder.page_section
            WHERE site_id=:site_id
            ORDER BY page_id ASC,sort ASC,id ASC
        ", [':site_id' => $siteId]);

        return array_map([self::class, 'mapRow'], $rows);
    }

    public static function listForPageIds(array $pageIds): array
    {
        $ids = self::normalizeIds($pageIds);
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = sb_db()->prepare("
            SELECT id,site_id,page_id,type,title,sort,layout_json,props_json,
                   created_by,created_at,updated_by,updated_at,version
            FROM sitebuilder.page_section
            WHERE page_id IN ({$placeholders})
            ORDER BY page_id ASC,sort ASC,id ASC
        ");
        $stmt->execute($ids);

        return array_map([self::class, 'mapRow'], $stmt->fetchAll());
    }

    public static function deleteByPageIds(array $pageIds): int
    {
        $ids = self::normalizeIds($pageIds);
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = sb_db()->prepare("DELETE FROM sitebuilder.page_section WHERE page_id IN ({$placeholders})");
        $stmt->execute($ids);

        return $stmt->rowCount();
    }

    public static function deleteBySiteId(int $siteId): int
    {
        if ($siteId <= 0) {
            return 0;
        }

        $stmt = sb_db()->prepare('DELETE FROM sitebuilder.page_section WHERE site_id=:site_id');
        $stmt->execute([':site_id' => $siteId]);

        return $stmt->rowCount();
    }

    /**
     * Создаёт секции из шаблона и возвращает oldId => newId.
     */
    public static function appendTemplateSections(
        int $siteId,
        array $pageIdMap,
        array $templateSections,
        int $userId
    ): array {
        if ($siteId <= 0 || empty($pageIdMap) || empty($templateSections)) {
            return [];
        }

        $sectionIdMap = [];
        $stmt = sb_db()->prepare("
            INSERT INTO sitebuilder.page_section (
                site_id,page_id,type,title,sort,layout_json,props_json,
                created_by,created_at,updated_by,updated_at,version
            ) VALUES (
                :site_id,:page_id,:type,:title,:sort,CAST(:layout AS jsonb),CAST(:props AS jsonb),
                :created_by,NOW(),:updated_by,NOW(),1
            )
            RETURNING id,site_id,page_id,type,title,sort,layout_json,props_json,
                      created_by,created_at,updated_by,updated_at,version
        ");

        foreach ($templateSections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $oldPageId = (int)($section['oldPageId'] ?? 0);
            if ($oldPageId <= 0 || !isset($pageIdMap[$oldPageId])) {
                continue;
            }

            $normalized = self::normalize([
                'siteId' => $siteId,
                'pageId' => (int)$pageIdMap[$oldPageId],
                'type' => 'section',
                'title' => (string)($section['title'] ?? 'Секция'),
                'sort' => (int)($section['sort'] ?? 500),
                'layout' => is_array($section['layout'] ?? null) ? $section['layout'] : [],
                'props' => is_array($section['props'] ?? null) ? $section['props'] : [],
            ]);

            $stmt->execute([
                ':site_id' => $siteId,
                ':page_id' => $normalized['pageId'],
                ':type' => $normalized['type'],
                ':title' => $normalized['title'],
                ':sort' => $normalized['sort'],
                ':layout' => self::encodeJson($normalized['layout']),
                ':props' => self::encodeJson($normalized['props']),
                ':created_by' => $userId > 0 ? $userId : null,
                ':updated_by' => $userId > 0 ? $userId : null,
            ]);

            $saved = self::mapRow($stmt->fetch() ?: []);
            $oldSectionId = (int)($section['oldId'] ?? 0);
            if ($oldSectionId > 0 && $saved['id'] > 0) {
                $sectionIdMap[$oldSectionId] = $saved['id'];
            }
        }

        return $sectionIdMap;
    }

    public static function listForPage(int $siteId, int $pageId): array
    {
        if ($siteId <= 0 || $pageId <= 0) {
            return [];
        }

        $rows = sb_db_fetch_all("
            SELECT id,site_id,page_id,type,title,sort,layout_json,props_json,
                   created_by,created_at,updated_by,updated_at,version
            FROM sitebuilder.page_section
            WHERE site_id=:site_id AND page_id=:page_id
            ORDER BY sort ASC,id ASC
        ", [
            ':site_id' => $siteId,
            ':page_id' => $pageId,
        ]);

        return array_map([self::class, 'mapRow'], $rows);
    }

    public static function getById(int $sectionId, bool $forUpdate = false): ?array
    {
        if ($sectionId <= 0) {
            return null;
        }

        $sql = "
            SELECT id,site_id,page_id,type,title,sort,layout_json,props_json,
                   created_by,created_at,updated_by,updated_at,version
            FROM sitebuilder.page_section
            WHERE id=:id
        ";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $row = sb_db_fetch_one($sql, [':id' => $sectionId]);
        return $row ? self::mapRow($row) : null;
    }

    public static function ensureDefaultForPage(int $siteId, int $pageId, int $userId = 0): array
    {
        $startedHere = sb_db_transaction_scope_begin();

        try {
            self::lockPage($pageId);
            $sections = self::listForPageForUpdate($siteId, $pageId);

            if (!empty($sections)) {
                sb_db_transaction_scope_commit($startedHere);
                return $sections[0];
            }

            $section = self::insert([
                'siteId' => $siteId,
                'pageId' => $pageId,
                'type' => 'section',
                'title' => 'Основная секция',
                'sort' => 10,
                'layout' => [
                    'container' => 'default',
                    'columns' => 1,
                    'gap' => 24,
                ],
                'props' => [
                    'backgroundColor' => '',
                    'backgroundImage' => '',
                    'paddingTop' => 40,
                    'paddingBottom' => 40,
                    'minHeight' => 0,
                ],
            ], $userId);

            self::migratePageBlocksToSection($pageId, (int)$section['id']);
            sb_db_transaction_scope_commit($startedHere);

            return $section;
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }
    }

    public static function create(
        int $siteId,
        int $pageId,
        string $title,
        array $layout,
        array $props,
        int $userId
    ): array {
        $startedHere = sb_db_transaction_scope_begin();

        try {
            self::lockPage($pageId);
            $maxSort = (int)(sb_db_fetch_one("
                SELECT COALESCE(MAX(sort),0) AS max_sort
                FROM sitebuilder.page_section
                WHERE site_id=:site_id AND page_id=:page_id
            ", [':site_id' => $siteId, ':page_id' => $pageId])['max_sort'] ?? 0);

            $section = self::insert([
                'siteId' => $siteId,
                'pageId' => $pageId,
                'type' => 'section',
                'title' => trim($title) !== '' ? trim($title) : 'Новая секция',
                'sort' => $maxSort > 0 ? $maxSort + 10 : 10,
                'layout' => $layout,
                'props' => $props,
            ], $userId);

            sb_db_transaction_scope_commit($startedHere);
            return $section;
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }
    }

    public static function update(
        int $sectionId,
        array $fields,
        int $userId,
        int $expectedVersion
    ): array {
        $section = self::getById($sectionId, true);
        if (!$section) {
            throw new RuntimeException('PAGE_SECTION_NOT_FOUND');
        }

        self::assertVersion($section, $expectedVersion);

        if (array_key_exists('title', $fields)) {
            $title = trim((string)$fields['title']);
            $section['title'] = $title !== '' ? $title : 'Секция';
        }
        if (array_key_exists('layout', $fields) && is_array($fields['layout'])) {
            $section['layout'] = array_merge($section['layout'], $fields['layout']);
        }
        if (array_key_exists('props', $fields) && is_array($fields['props'])) {
            $section['props'] = array_merge($section['props'], $fields['props']);
        }

        $section = self::normalize($section);
        $stmt = sb_db()->prepare("
            UPDATE sitebuilder.page_section
            SET title=:title,layout_json=CAST(:layout AS jsonb),props_json=CAST(:props AS jsonb),
                updated_by=:updated_by,updated_at=NOW(),version=version+1
            WHERE id=:id AND version=:expected_version
            RETURNING id,site_id,page_id,type,title,sort,layout_json,props_json,
                      created_by,created_at,updated_by,updated_at,version
        ");
        $stmt->execute([
            ':id' => $sectionId,
            ':title' => $section['title'],
            ':layout' => self::encodeJson($section['layout']),
            ':props' => self::encodeJson($section['props']),
            ':updated_by' => $userId > 0 ? $userId : null,
            ':expected_version' => $expectedVersion,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            $current = self::getById($sectionId, false);
            throw new SiteBuilderVersionConflictException(
                'page_section',
                $sectionId,
                $expectedVersion,
                max(1, (int)($current['version'] ?? 1))
            );
        }

        return self::mapRow($row);
    }

    public static function move(int $sectionId, string $dir, int $userId, array $versionMap): bool
    {
        if (!in_array($dir, ['up', 'down'], true)) {
            throw new RuntimeException('INVALID_DIR');
        }

        $startedHere = sb_db_transaction_scope_begin();

        try {
            $current = self::getById($sectionId, true);
            if (!$current) {
                throw new RuntimeException('PAGE_SECTION_NOT_FOUND');
            }

            self::lockPage((int)$current['pageId']);
            $siblings = self::listForPageForUpdate((int)$current['siteId'], (int)$current['pageId']);
            $position = null;
            foreach ($siblings as $index => $sibling) {
                if ((int)$sibling['id'] === $sectionId) {
                    $position = $index;
                    break;
                }
            }

            if ($position === null) {
                throw new RuntimeException('PAGE_SECTION_NOT_FOUND_IN_SIBLINGS');
            }

            $swapPosition = $dir === 'up' ? $position - 1 : $position + 1;
            if (!isset($siblings[$swapPosition])) {
                sb_db_transaction_scope_commit($startedHere);
                return false;
            }

            $first = $siblings[$position];
            $second = $siblings[$swapPosition];
            self::assertVersion(
                $first,
                RevisionService::requireVersionFromMap($versionMap, (int)$first['id'])
            );
            self::assertVersion(
                $second,
                RevisionService::requireVersionFromMap($versionMap, (int)$second['id'])
            );

            $stmt = sb_db()->prepare("
                UPDATE sitebuilder.page_section
                SET sort=:sort,updated_by=:updated_by,updated_at=NOW(),version=version+1
                WHERE id=:id AND version=:expected_version
            ");

            foreach ([[$first, $second['sort']], [$second, $first['sort']]] as [$section, $newSort]) {
                $stmt->execute([
                    ':id' => (int)$section['id'],
                    ':sort' => (int)$newSort,
                    ':updated_by' => $userId > 0 ? $userId : null,
                    ':expected_version' => (int)$section['version'],
                ]);
                if ($stmt->rowCount() !== 1) {
                    $fresh = self::getById((int)$section['id'], false);
                    throw new SiteBuilderVersionConflictException(
                        'page_section',
                        (int)$section['id'],
                        (int)$section['version'],
                        max(1, (int)($fresh['version'] ?? 1))
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

    public static function delete(int $sectionId, int $userId, int $expectedVersion): void
    {
        $startedHere = sb_db_transaction_scope_begin();

        try {
            $section = self::getById($sectionId, true);
            if (!$section) {
                throw new RuntimeException('PAGE_SECTION_NOT_FOUND');
            }
            self::assertVersion($section, $expectedVersion);
            self::lockPage((int)$section['pageId']);

            $sections = self::listForPageForUpdate((int)$section['siteId'], (int)$section['pageId']);
            if (count($sections) <= 1) {
                throw new RuntimeException('CANNOT_DELETE_LAST_SECTION');
            }

            $targetSectionId = 0;
            foreach ($sections as $candidate) {
                if ((int)$candidate['id'] !== $sectionId) {
                    $targetSectionId = (int)$candidate['id'];
                    break;
                }
            }
            if ($targetSectionId <= 0) {
                throw new RuntimeException('TARGET_SECTION_NOT_FOUND');
            }

            self::moveBlocksFromSection($sectionId, $targetSectionId, $userId);

            $stmt = sb_db()->prepare('DELETE FROM sitebuilder.page_section WHERE id=:id AND version=:version');
            $stmt->execute([':id' => $sectionId, ':version' => $expectedVersion]);
            if ($stmt->rowCount() !== 1) {
                $fresh = self::getById($sectionId, false);
                throw new SiteBuilderVersionConflictException(
                    'page_section',
                    $sectionId,
                    $expectedVersion,
                    max(1, (int)($fresh['version'] ?? 1))
                );
            }

            sb_db_transaction_scope_commit($startedHere);
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }
    }

    public static function assignBlock(
        int $blockId,
        int $sectionId,
        int $column,
        int $userId,
        int $expectedVersion
    ): array {
        $section = self::getById($sectionId);
        if (!$section) {
            throw new RuntimeException('PAGE_SECTION_NOT_FOUND');
        }

        $column = max(1, min(4, $column));
        $block = RevisionService::getBlock($blockId, true);
        if (!$block) {
            throw new RuntimeException('BLOCK_NOT_FOUND');
        }
        if ((int)$block['pageId'] !== (int)$section['pageId']) {
            throw new RuntimeException('BLOCK_AND_SECTION_PAGE_MISMATCH');
        }

        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        $currentSectionId = (int)($props['_placement']['sectionId'] ?? $props['sectionId'] ?? 0);
        $currentColumn = (int)($props['_placement']['column'] ?? $props['column'] ?? 1);
        if ($currentSectionId === $sectionId && $currentColumn === $column) {
            return $block;
        }

        $props['sectionId'] = $sectionId;
        $props['column'] = $column;
        $props['_placement'] = ['sectionId' => $sectionId, 'column' => $column];
        $block['props'] = $props;

        return RevisionService::saveBlock(
            $block,
            RevisionService::requireExpectedVersion($expectedVersion),
            $userId,
            'placement_change'
        );
    }

    /**
     * Восстанавливает секции из корзины. Старый ID сохраняется, если свободен.
     */
    public static function restoreSnapshots(array $sections, int $userId): array
    {
        if (empty($sections)) {
            return [];
        }

        $usedRows = sb_db_fetch_all('SELECT id FROM sitebuilder.page_section FOR UPDATE');
        $used = [];
        foreach ($usedRows as $row) {
            $used[(int)$row['id']] = true;
        }

        $map = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $oldId = (int)($section['id'] ?? 0);
            if ($oldId <= 0) {
                continue;
            }

            $newId = $oldId;
            if (isset($used[$newId])) {
                $newId = self::nextSequenceId();
                while (isset($used[$newId])) {
                    $newId = self::nextSequenceId();
                }
            }
            $used[$newId] = true;
            $map[$oldId] = $newId;

            $normalized = self::normalize($section);
            $stmt = sb_db()->prepare("
                INSERT INTO sitebuilder.page_section (
                    id,site_id,page_id,type,title,sort,layout_json,props_json,
                    created_by,created_at,updated_by,updated_at,version
                ) VALUES (
                    :id,:site_id,:page_id,:type,:title,:sort,CAST(:layout AS jsonb),CAST(:props AS jsonb),
                    :created_by,:created_at,:updated_by,NOW(),:version
                )
            ");
            $stmt->execute([
                ':id' => $newId,
                ':site_id' => $normalized['siteId'],
                ':page_id' => $normalized['pageId'],
                ':type' => $normalized['type'],
                ':title' => $normalized['title'],
                ':sort' => $normalized['sort'],
                ':layout' => self::encodeJson($normalized['layout']),
                ':props' => self::encodeJson($normalized['props']),
                ':created_by' => $normalized['createdBy'] > 0 ? $normalized['createdBy'] : ($userId > 0 ? $userId : null),
                ':created_at' => $normalized['createdAt'] !== '' ? $normalized['createdAt'] : date('c'),
                ':updated_by' => $userId > 0 ? $userId : null,
                ':version' => max(1, (int)$normalized['version'] + 1),
            ]);
        }

        self::syncSequence();
        return $map;
    }

    /**
     * Одноразово переносит legacy JSON в PostgreSQL. Повторный запуск безопасен.
     */
    public static function importLegacyJson(): array
    {
        $path = self::legacyFilePath();
        if (!is_file($path)) {
            return ['found' => false, 'total' => 0, 'imported' => 0, 'existing' => 0, 'updated' => 0, 'skipped' => 0, 'backup' => ''];
        }

        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_SH)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('PAGE_SECTION_LEGACY_LOCK_FAILED');
        }

        $backupPath = $path . '.stage7-backup-' . date('Ymd-His');
        try {
            $raw = file_get_contents($path);
            if (!is_string($raw)) {
                throw new RuntimeException('PAGE_SECTION_JSON_READ_FAILED');
            }
            if (!@copy($path, $backupPath)) {
                throw new RuntimeException('PAGE_SECTION_BACKUP_FAILED');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $items = json_decode($raw, true);
        if (!is_array($items)) {
            throw new RuntimeException('PAGE_SECTION_JSON_INVALID');
        }

        $startedHere = sb_db_transaction_scope_begin();
        $imported = 0;
        $updated = 0;
        $skipped = 0;

        try {
            $stmt = sb_db()->prepare("
                INSERT INTO sitebuilder.page_section (
                    id,site_id,page_id,type,title,sort,layout_json,props_json,
                    created_by,created_at,updated_by,updated_at,version
                )
                SELECT
                    :id,:site_id,:page_id,:type,:title,:sort,CAST(:layout AS jsonb),CAST(:props AS jsonb),
                    :created_by,:created_at,:updated_by,:updated_at,1
                WHERE EXISTS (SELECT 1 FROM sitebuilder.site WHERE id=:check_site_id)
                  AND EXISTS (SELECT 1 FROM sitebuilder.page WHERE id=:check_page_id AND site_id=:check_page_site_id)
                ON CONFLICT (id) DO NOTHING
                RETURNING id
            ");

            foreach ($items as $item) {
                if (!is_array($item)) {
                    $skipped++;
                    continue;
                }
                $normalized = self::normalize($item);
                if ($normalized['id'] <= 0 || $normalized['siteId'] <= 0 || $normalized['pageId'] <= 0) {
                    $skipped++;
                    continue;
                }

                $stmt->execute([
                    ':id' => $normalized['id'],
                    ':site_id' => $normalized['siteId'],
                    ':page_id' => $normalized['pageId'],
                    ':type' => $normalized['type'],
                    ':title' => $normalized['title'],
                    ':sort' => $normalized['sort'],
                    ':layout' => self::encodeJson($normalized['layout']),
                    ':props' => self::encodeJson($normalized['props']),
                    ':created_by' => $normalized['createdBy'] > 0 ? $normalized['createdBy'] : null,
                    ':created_at' => $normalized['createdAt'] !== '' ? $normalized['createdAt'] : date('c'),
                    ':updated_by' => $normalized['updatedBy'] > 0 ? $normalized['updatedBy'] : null,
                    ':updated_at' => $normalized['updatedAt'] !== '' ? $normalized['updatedAt'] : date('c'),
                    ':check_site_id' => $normalized['siteId'],
                    ':check_page_id' => $normalized['pageId'],
                    ':check_page_site_id' => $normalized['siteId'],
                ]);
                $result = $stmt->fetch();
                if ($result) {
                    $imported++;
                } else {
                    /* Повторный запуск не затирает более новые изменения. */
                    $updated++;
                }
            }

            self::syncSequence();
            sb_db_transaction_scope_commit($startedHere);
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($startedHere);
            throw $e;
        }

        return [
            'found' => true,
            'total' => count($items),
            'imported' => $imported,
            'existing' => $updated,
            /* Старый ключ оставлен для совместимости с уже открытой страницей миграции. */
            'updated' => $updated,
            'skipped' => $skipped,
            'backup' => $backupPath,
        ];
    }

    private static function insert(array $section, int $userId): array
    {
        $section = self::normalize($section);
        $stmt = sb_db()->prepare("
            INSERT INTO sitebuilder.page_section (
                site_id,page_id,type,title,sort,layout_json,props_json,
                created_by,created_at,updated_by,updated_at,version
            ) VALUES (
                :site_id,:page_id,:type,:title,:sort,CAST(:layout AS jsonb),CAST(:props AS jsonb),
                :created_by,NOW(),:updated_by,NOW(),1
            )
            RETURNING id,site_id,page_id,type,title,sort,layout_json,props_json,
                      created_by,created_at,updated_by,updated_at,version
        ");
        $stmt->execute([
            ':site_id' => $section['siteId'],
            ':page_id' => $section['pageId'],
            ':type' => $section['type'],
            ':title' => $section['title'],
            ':sort' => $section['sort'],
            ':layout' => self::encodeJson($section['layout']),
            ':props' => self::encodeJson($section['props']),
            ':created_by' => $userId > 0 ? $userId : null,
            ':updated_by' => $userId > 0 ? $userId : null,
        ]);

        return self::mapRow($stmt->fetch() ?: []);
    }

    private static function listForPageForUpdate(int $siteId, int $pageId): array
    {
        $rows = sb_db_fetch_all("
            SELECT id,site_id,page_id,type,title,sort,layout_json,props_json,
                   created_by,created_at,updated_by,updated_at,version
            FROM sitebuilder.page_section
            WHERE site_id=:site_id AND page_id=:page_id
            ORDER BY sort ASC,id ASC
            FOR UPDATE
        ", [':site_id' => $siteId, ':page_id' => $pageId]);

        return array_map([self::class, 'mapRow'], $rows);
    }

    private static function moveBlocksFromSection(int $fromSectionId, int $toSectionId, int $userId): void
    {
        foreach (sb_read_blocks() as $block) {
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $blockSectionId = (int)($props['_placement']['sectionId'] ?? $props['sectionId'] ?? $block['sectionId'] ?? 0);
            if ($blockSectionId !== $fromSectionId) {
                continue;
            }

            $locked = RevisionService::getBlock((int)$block['id'], true);
            if (!$locked) {
                throw new RuntimeException('BLOCK_NOT_FOUND');
            }
            $lockedProps = is_array($locked['props'] ?? null) ? $locked['props'] : [];
            $lockedProps['sectionId'] = $toSectionId;
            $lockedProps['column'] = 1;
            $lockedProps['_placement'] = ['sectionId' => $toSectionId, 'column' => 1];
            $locked['props'] = $lockedProps;

            RevisionService::saveBlock(
                $locked,
                (int)$locked['version'],
                $userId,
                'section_delete_reassign'
            );
        }
    }

    private static function migratePageBlocksToSection(int $pageId, int $sectionId): void
    {
        foreach (sb_read_blocks() as $block) {
            if ((int)($block['pageId'] ?? 0) !== $pageId) {
                continue;
            }
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $currentSectionId = (int)($props['_placement']['sectionId'] ?? $props['sectionId'] ?? $block['sectionId'] ?? 0);
            if ($currentSectionId > 0) {
                continue;
            }

            $locked = RevisionService::getBlock((int)$block['id'], true);
            if (!$locked) {
                throw new RuntimeException('BLOCK_NOT_FOUND');
            }
            $lockedProps = is_array($locked['props'] ?? null) ? $locked['props'] : [];
            $lockedProps['sectionId'] = $sectionId;
            $lockedProps['column'] = 1;
            $lockedProps['_placement'] = ['sectionId' => $sectionId, 'column' => 1];
            $locked['props'] = $lockedProps;

            RevisionService::saveBlock(
                $locked,
                (int)$locked['version'],
                0,
                'section_auto_assign'
            );
        }
    }

    private static function lockPage(int $pageId): void
    {
        if ($pageId <= 0) {
            throw new InvalidArgumentException('INVALID_PAGE_ID');
        }
        $stmt = sb_db()->prepare('SELECT pg_advisory_xact_lock(' . self::LOCK_NAMESPACE . ',CAST(:page_id AS integer))');
        $stmt->execute([':page_id' => $pageId]);
    }

    private static function assertVersion(array $section, int $expectedVersion): void
    {
        $expectedVersion = RevisionService::requireExpectedVersion($expectedVersion);
        $currentVersion = max(1, (int)($section['version'] ?? 1));
        if ($expectedVersion !== $currentVersion) {
            throw new SiteBuilderVersionConflictException(
                'page_section',
                (int)($section['id'] ?? 0),
                $expectedVersion,
                $currentVersion
            );
        }
    }

    private static function mapRow(array $row): array
    {
        return self::normalize([
            'id' => (int)($row['id'] ?? 0),
            'siteId' => (int)($row['site_id'] ?? $row['siteId'] ?? 0),
            'pageId' => (int)($row['page_id'] ?? $row['pageId'] ?? 0),
            'type' => (string)($row['type'] ?? 'section'),
            'title' => (string)($row['title'] ?? 'Секция'),
            'sort' => (int)($row['sort'] ?? 500),
            'layout' => sb_json_decode_assoc($row['layout_json'] ?? $row['layout'] ?? []),
            'props' => sb_json_decode_assoc($row['props_json'] ?? $row['props'] ?? []),
            'createdBy' => (int)($row['created_by'] ?? $row['createdBy'] ?? 0),
            'createdAt' => (string)($row['created_at'] ?? $row['createdAt'] ?? ''),
            'updatedBy' => (int)($row['updated_by'] ?? $row['updatedBy'] ?? 0),
            'updatedAt' => (string)($row['updated_at'] ?? $row['updatedAt'] ?? ''),
            'version' => max(1, (int)($row['version'] ?? 1)),
        ]);
    }

    private static function normalize(array $item): array
    {
        $layout = is_array($item['layout'] ?? null) ? $item['layout'] : [];
        $props = is_array($item['props'] ?? null) ? $item['props'] : [];
        $columns = max(1, min(4, (int)($layout['columns'] ?? 1)));

        return [
            'id' => (int)($item['id'] ?? 0),
            'siteId' => (int)($item['siteId'] ?? 0),
            'pageId' => (int)($item['pageId'] ?? 0),
            'type' => (string)($item['type'] ?? 'section'),
            'title' => (string)($item['title'] ?? 'Секция'),
            'sort' => (int)($item['sort'] ?? 500),
            'layout' => array_merge([
                'container' => 'default',
                'columns' => $columns,
                'gap' => 24,
            ], $layout, ['columns' => $columns]),
            'props' => array_merge([
                'backgroundColor' => '',
                'backgroundImage' => '',
                'paddingTop' => 40,
                'paddingBottom' => 40,
                'minHeight' => 0,
            ], $props),
            'createdBy' => (int)($item['createdBy'] ?? 0),
            'createdAt' => (string)($item['createdAt'] ?? ''),
            'updatedBy' => (int)($item['updatedBy'] ?? 0),
            'updatedAt' => (string)($item['updatedAt'] ?? ''),
            'version' => max(1, (int)($item['version'] ?? 1)),
        ];
    }

    private static function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    private static function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function nextSequenceId(): int
    {
        return (int)sb_db()->query("SELECT nextval(pg_get_serial_sequence('sitebuilder.page_section','id'))")->fetchColumn();
    }

    private static function syncSequence(): void
    {
        sb_db()->exec("
            SELECT setval(
                pg_get_serial_sequence('sitebuilder.page_section','id'),
                COALESCE(max_id,1),
                max_id IS NOT NULL
            )
            FROM (SELECT MAX(id) AS max_id FROM sitebuilder.page_section) seq_state
        ");
    }

    private static function legacyFilePath(): string
    {
        return rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . self::LEGACY_FILE_PATH;
    }
}
