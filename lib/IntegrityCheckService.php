<?php

require_once __DIR__ . '/db.php';

/** Проверка логической целостности модели SiteBuilder без изменения данных. */
final class IntegrityCheckService
{
    public static function listRuns(int $siteId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $params = [];
        $where = '';
        if ($siteId > 0) {
            $where = 'WHERE site_id=:site_id';
            $params[':site_id'] = $siteId;
        }
        $stmt = sb_db()->prepare("\n            SELECT id,site_id,status,actor_user_id,checked_sites,checked_pages,checked_blocks,\n                   checked_sections,checked_menus,errors_count,warnings_count,summary_json,error_code,\n                   started_at,finished_at,duration_ms\n            FROM sitebuilder.integrity_check_run\n            {$where}\n            ORDER BY id DESC\n            LIMIT :limit\n        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'mapRun'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public static function getRun(int $id): ?array
    {
        $row = sb_db_fetch_one("\n            SELECT * FROM sitebuilder.integrity_check_run WHERE id=:id\n        ", [':id' => $id]);
        return $row ? self::mapRun($row, true) : null;
    }

    public static function run(int $siteId, int $actorUserId = 0): array
    {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('SITE_ID_REQUIRED');
        }
        $site = sb_find_site($siteId);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $stmt = sb_db()->prepare("\n            INSERT INTO sitebuilder.integrity_check_run (site_id,status,actor_user_id,started_at)\n            VALUES (:site_id,'running',:actor_user_id,NOW())\n            RETURNING id\n        ");
        $stmt->execute([
            ':site_id' => $siteId,
            ':actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ]);
        $runId = (int)$stmt->fetchColumn();
        if ($runId <= 0) {
            throw new RuntimeException('INTEGRITY_RUN_CREATE_FAILED');
        }

        $started = microtime(true);
        try {
            $inspection = self::inspectSite($siteId);
            $durationMs = (int)round((microtime(true) - $started) * 1000);
            $update = sb_db()->prepare("\n                UPDATE sitebuilder.integrity_check_run\n                SET status='succeeded',checked_sites=1,checked_pages=:pages,checked_blocks=:blocks,\n                    checked_sections=:sections,checked_menus=:menus,errors_count=:errors,\n                    warnings_count=:warnings,issues_json=CAST(:issues AS jsonb),\n                    summary_json=CAST(:summary AS jsonb),finished_at=NOW(),duration_ms=:duration_ms\n                WHERE id=:id\n            ");
            $update->execute([
                ':id' => $runId,
                ':pages' => (int)$inspection['counts']['pages'],
                ':blocks' => (int)$inspection['counts']['blocks'],
                ':sections' => (int)$inspection['counts']['sections'],
                ':menus' => (int)$inspection['counts']['menus'],
                ':errors' => (int)$inspection['errorsCount'],
                ':warnings' => (int)$inspection['warningsCount'],
                ':issues' => self::encode($inspection['issues']),
                ':summary' => self::encode($inspection['summary']),
                ':duration_ms' => $durationMs,
            ]);
            return self::getRun($runId) ?: ($inspection + ['id' => $runId]);
        } catch (Throwable $e) {
            $durationMs = (int)round((microtime(true) - $started) * 1000);
            $failedAt = date('c');
            $startedAt = date('c', (int)floor($started));

            /*
             * Ошибка API приводит к rollback request-транзакции. Поэтому
             * запись failed сохраняем после rollback отдельной autocommit-
             * операцией; иначе история неудачных проверок исчезала бы.
             */
            $persistFailure = static function () use (
                $runId,
                $siteId,
                $actorUserId,
                $durationMs,
                $startedAt,
                $failedAt
            ): void {
                try {
                    sb_db_execute("
                        INSERT INTO sitebuilder.integrity_check_run (
                            id,site_id,status,actor_user_id,error_code,started_at,finished_at,duration_ms
                        ) VALUES (
                            :id,:site_id,'failed',:actor_user_id,'INTEGRITY_CHECK_FAILED',
                            :started_at,:finished_at,:duration_ms
                        )
                        ON CONFLICT (id) DO UPDATE SET
                            status='failed',error_code='INTEGRITY_CHECK_FAILED',
                            finished_at=EXCLUDED.finished_at,duration_ms=EXCLUDED.duration_ms
                    ", [
                        ':id' => $runId,
                        ':site_id' => $siteId,
                        ':actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
                        ':started_at' => $startedAt,
                        ':finished_at' => $failedAt,
                        ':duration_ms' => $durationMs,
                    ]);
                } catch (Throwable $writeError) {
                    error_log('SiteBuilder integrity failed-run write failed: ' . $writeError->getMessage());
                }
            };

            if (!empty($GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE']) || sb_db()->inTransaction()) {
                sb_db_after_rollback($persistFailure);
            } else {
                $persistFailure();
            }
            throw $e;
        }
    }

    /** Возвращает результат без записи отдельного запуска — используется пакетом backup. */
    public static function inspectSite(int $siteId): array
    {
        $site = sb_db_fetch_one('SELECT * FROM sitebuilder.site WHERE id=:id', [':id' => $siteId]);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $pages = sb_db_fetch_all('SELECT * FROM sitebuilder.page WHERE site_id=:id ORDER BY id', [':id' => $siteId]);
        $pageIds = array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $pages), true);
        $blocks = sb_db_fetch_all("\n            SELECT b.* FROM sitebuilder.block b\n            JOIN sitebuilder.page p ON p.id=b.page_id\n            WHERE p.site_id=:id ORDER BY b.id\n        ", [':id' => $siteId]);
        $sections = sb_db_fetch_all('SELECT * FROM sitebuilder.page_section WHERE site_id=:id ORDER BY id', [':id' => $siteId]);
        $menus = sb_db_fetch_all('SELECT * FROM sitebuilder.menu WHERE site_id=:id ORDER BY id', [':id' => $siteId]);
        $layout = sb_db_fetch_one('SELECT * FROM sitebuilder.layout WHERE site_id=:id', [':id' => $siteId]);
        $access = sb_db_fetch_all('SELECT access_code,role FROM sitebuilder.access WHERE site_id=:id', [':id' => $siteId]);
        $pageAccess = sb_db_fetch_all('SELECT * FROM sitebuilder.page_access WHERE site_id=:id', [':id' => $siteId]);

        $issues = [];
        $add = static function (string $severity, string $code, string $entityType, int $entityId, array $details = []) use (&$issues): void {
            $issues[] = [
                'severity' => $severity,
                'code' => $code,
                'entityType' => $entityType,
                'entityId' => $entityId,
                'details' => $details,
            ];
        };

        $homePageId = (int)($site['home_page_id'] ?? 0);
        if ($homePageId > 0 && !isset($pageIds[$homePageId])) {
            $add('error', 'INVALID_HOME_PAGE', 'site', $siteId, ['homePageId' => $homePageId]);
        }
        $menuIds = array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $menus), true);
        $topMenuId = (int)($site['top_menu_id'] ?? 0);
        if ($topMenuId > 0 && !isset($menuIds[$topMenuId])) {
            $add('error', 'INVALID_TOP_MENU', 'site', $siteId, ['topMenuId' => $topMenuId]);
        }
        if (!$layout) {
            $add('error', 'LAYOUT_MISSING', 'site', $siteId);
        }
        $owners = array_values(array_filter($access, static fn(array $row): bool => strtoupper((string)$row['role']) === 'OWNER'));
        if (empty($owners)) {
            $add('error', 'OWNER_MISSING', 'site', $siteId);
        }

        $parentMap = [];
        $slugGroups = [];
        foreach ($pages as $page) {
            $pageId = (int)$page['id'];
            $parentId = (int)($page['parent_id'] ?? 0);
            $parentMap[$pageId] = $parentId;
            if ($parentId > 0 && !isset($pageIds[$parentId])) {
                $add('error', 'PAGE_PARENT_INVALID', 'page', $pageId, ['parentId' => $parentId]);
            }
            $key = $parentId . '|' . mb_strtolower(trim((string)$page['slug']));
            $slugGroups[$key][] = $pageId;
        }
        foreach ($slugGroups as $key => $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $pageId) {
                    $add('error', 'DUPLICATE_PAGE_SLUG', 'page', (int)$pageId, ['group' => $key, 'pageIds' => $ids]);
                }
            }
        }
        foreach (array_keys($parentMap) as $pageId) {
            $seen = [];
            $cursor = $pageId;
            while ($cursor > 0 && isset($parentMap[$cursor])) {
                if (isset($seen[$cursor])) {
                    $add('error', 'PAGE_PARENT_CYCLE', 'page', $pageId, ['cycleAt' => $cursor]);
                    break;
                }
                $seen[$cursor] = true;
                $cursor = (int)$parentMap[$cursor];
            }
        }

        $sectionById = [];
        foreach ($sections as $section) {
            $sectionId = (int)$section['id'];
            $sectionById[$sectionId] = $section;
            $pageId = (int)$section['page_id'];
            if (!isset($pageIds[$pageId])) {
                $add('error', 'SECTION_PAGE_INVALID', 'page_section', $sectionId, ['pageId' => $pageId]);
            }
            if ((int)$section['site_id'] !== $siteId) {
                $add('error', 'SECTION_SITE_MISMATCH', 'page_section', $sectionId);
            }
        }

        foreach ($blocks as $block) {
            $blockId = (int)$block['id'];
            $pageId = (int)$block['page_id'];
            if (!isset($pageIds[$pageId])) {
                $add('error', 'BLOCK_PAGE_INVALID', 'block', $blockId, ['pageId' => $pageId]);
                continue;
            }
            $props = sb_json_decode_assoc($block['props_json'] ?? []);
            $placement = is_array($props['_placement'] ?? null) ? $props['_placement'] : [];
            $sectionId = (int)($props['sectionId'] ?? $placement['sectionId'] ?? 0);
            if ($sectionId > 0) {
                $section = $sectionById[$sectionId] ?? null;
                if (!$section) {
                    $add('error', 'BLOCK_SECTION_INVALID', 'block', $blockId, ['sectionId' => $sectionId]);
                } elseif ((int)$section['page_id'] !== $pageId) {
                    $add('error', 'BLOCK_SECTION_PAGE_MISMATCH', 'block', $blockId, ['sectionId' => $sectionId]);
                }
            }
        }

        foreach ($menus as $menu) {
            $items = sb_json_decode_assoc($menu['items_json'] ?? []);
            foreach ($items as $index => $item) {
                if (!is_array($item) || (string)($item['type'] ?? 'page') !== 'page') {
                    continue;
                }
                $pageId = (int)($item['pageId'] ?? 0);
                if ($pageId > 0 && !isset($pageIds[$pageId])) {
                    $add('error', 'MENU_PAGE_INVALID', 'menu', (int)$menu['id'], ['index' => $index, 'pageId' => $pageId]);
                }
            }
        }

        foreach ($pageAccess as $row) {
            $pageId = (int)$row['page_id'];
            if (!isset($pageIds[$pageId])) {
                $add('error', 'PAGE_ACCESS_PAGE_INVALID', 'page_access', (int)$row['id'], ['pageId' => $pageId]);
            }
        }

        if ((int)($site['bitrix_group_id'] ?? 0) <= 0) {
            $add('warning', 'BITRIX_GROUP_NOT_ATTACHED', 'site', $siteId);
        }
        if ((int)($site['disk_folder_id'] ?? 0) <= 0) {
            $add('warning', 'DISK_FOLDER_NOT_ATTACHED', 'site', $siteId);
        }

        $errors = count(array_filter($issues, static fn(array $i): bool => $i['severity'] === 'error'));
        $warnings = count($issues) - $errors;
        $counts = [
            'pages' => count($pages),
            'blocks' => count($blocks),
            'sections' => count($sections),
            'menus' => count($menus),
            'siteAccess' => count($access),
            'pageAccess' => count($pageAccess),
        ];

        return [
            'siteId' => $siteId,
            'errorsCount' => $errors,
            'warningsCount' => $warnings,
            'counts' => $counts,
            'issues' => $issues,
            'summary' => [
                'healthy' => $errors === 0,
                'errorsCount' => $errors,
                'warningsCount' => $warnings,
                'counts' => $counts,
                'checkedAt' => date('c'),
            ],
        ];
    }

    private static function mapRun(array $row, bool $includeIssues = false): array
    {
        $result = [
            'id' => (int)$row['id'],
            'siteId' => (int)($row['site_id'] ?? 0),
            'status' => (string)$row['status'],
            'actorUserId' => (int)($row['actor_user_id'] ?? 0),
            'checkedSites' => (int)($row['checked_sites'] ?? 0),
            'checkedPages' => (int)($row['checked_pages'] ?? 0),
            'checkedBlocks' => (int)($row['checked_blocks'] ?? 0),
            'checkedSections' => (int)($row['checked_sections'] ?? 0),
            'checkedMenus' => (int)($row['checked_menus'] ?? 0),
            'errorsCount' => (int)($row['errors_count'] ?? 0),
            'warningsCount' => (int)($row['warnings_count'] ?? 0),
            'summary' => sb_json_decode_assoc($row['summary_json'] ?? []),
            'errorCode' => (string)($row['error_code'] ?? ''),
            'startedAt' => (string)($row['started_at'] ?? ''),
            'finishedAt' => (string)($row['finished_at'] ?? ''),
            'durationMs' => (int)($row['duration_ms'] ?? 0),
        ];
        if ($includeIssues) {
            $issues = json_decode((string)($row['issues_json'] ?? '[]'), true);
            $result['issues'] = is_array($issues) ? $issues : [];
        }
        return $result;
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
