<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/json.php';
require_once __DIR__ . '/PageSectionRepository.php';
require_once __DIR__ . '/RequestLockService.php';
require_once __DIR__ . '/OutboxService.php';

/**
 * Атомарное удаление сайта и всех связанных записей PostgreSQL.
 *
 * Секции страниц хранятся в PostgreSQL и удаляются в общей транзакции.
 * Только templates.json остаётся внешним файловым хранилищем и очищается
 * после COMMIT под эксклюзивной файловой блокировкой.
 */
final class SiteDeletionService
{
    public static function delete(int $siteId, int $actorUserId = 0): array
    {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('INVALID_SITE_ID');
        }

        $pdo = sb_db();

        if ($pdo->inTransaction()) {
            throw new RuntimeException('SITE_DELETE_REQUIRES_OWN_TRANSACTION');
        }

        $pdo->beginTransaction();

        try {
            /*
             * Exclusive lifecycle-lock ждёт завершения всех обычных операций,
             * которые держат shared-lock этого сайта, но не блокирует другие сайты.
             */
            RequestLockService::lockSiteExclusive($siteId);

            $siteStmt = $pdo->prepare("
                SELECT id,name,slug,disk_folder_id,bitrix_group_id,bitrix_group_created_by,
                       bitrix_group_created_at,created_by
                FROM sitebuilder.site
                WHERE id = :site_id
                FOR UPDATE
            ");
            $siteStmt->execute([
                ':site_id' => $siteId,
            ]);

            $siteRow = $siteStmt->fetch(PDO::FETCH_ASSOC);
            if (!$siteRow) {
                throw new RuntimeException('SITE_NOT_FOUND');
            }

            $siteSnapshot = [
                'id' => (int)$siteRow['id'],
                'name' => (string)$siteRow['name'],
                'slug' => (string)$siteRow['slug'],
                'diskFolderId' => !empty($siteRow['disk_folder_id']) ? (int)$siteRow['disk_folder_id'] : 0,
                'bitrixGroupId' => !empty($siteRow['bitrix_group_id']) ? (int)$siteRow['bitrix_group_id'] : 0,
                'bitrixGroupCreatedBy' => !empty($siteRow['bitrix_group_created_by']) ? (int)$siteRow['bitrix_group_created_by'] : 0,
                'bitrixGroupCreatedAt' => (string)($siteRow['bitrix_group_created_at'] ?? ''),
                'createdBy' => !empty($siteRow['created_by']) ? (int)$siteRow['created_by'] : 0,
            ];
            $actorUserId = $actorUserId > 0 ? $actorUserId : (int)$siteSnapshot['createdBy'];

            /*
             * После exclusive lifecycle-lock активного worker сайта уже нет.
             * Отменяем старые pending/retry задания и в той же транзакции
             * ставим идемпотентную очистку внешних ресурсов.
             */
            $cancelledJobs = OutboxService::cancelPendingForSite($siteId, $actorUserId);
            $cleanupJobs = OutboxService::enqueueSiteCleanup($siteSnapshot, $actorUserId);

            /*
             * site.home_page_id и site.top_menu_id могут ссылаться на записи,
             * которые удаляются ниже. Обнуляем их до удаления дочерних сущностей.
             */
            self::executeCount($pdo, "
                UPDATE sitebuilder.site
                SET
                    home_page_id = NULL,
                    top_menu_id = NULL
                WHERE id = :site_id
            ", $siteId);

            $counts = ['cancelledJobs' => $cancelledJobs];

            /*
             * История не имеет внешнего ключа на site, чтобы сохранять снимки
             * удалённых страниц и блоков. При полном удалении сайта она больше
             * не нужна и должна исчезнуть в той же транзакции.
             */
            $counts['recycleBin'] = self::executeCount($pdo, "
                DELETE FROM sitebuilder.recycle_bin
                WHERE site_id = :site_id
            ", $siteId);

            $counts['revisions'] = self::executeCount($pdo, "
                DELETE FROM sitebuilder.entity_revision
                WHERE site_id = :site_id
            ", $siteId);

            $counts['pageAccess'] = self::executeCount($pdo, "
                DELETE FROM sitebuilder.page_access
                WHERE site_id = :site_id
            ", $siteId);

            $counts['pageSections'] = self::executeCount($pdo, "
                DELETE FROM sitebuilder.page_section
                WHERE site_id = :site_id
            ", $siteId);

            $counts['formSubmissions'] = self::executeCount($pdo, "
                DELETE FROM sitebuilder.form_submission
                WHERE site_id = :site_id
            ", $siteId);

            $counts['blocks'] = self::executeCount($pdo, "
                DELETE FROM sitebuilder.block AS block_row
                USING sitebuilder.page AS page_row
                WHERE block_row.page_id = page_row.id
                  AND page_row.site_id = :site_id
            ", $siteId);

            $counts['pages'] = self::executeCount($pdo, "
                DELETE FROM sitebuilder.page
                WHERE site_id = :site_id
            ", $siteId);

            $counts['access'] = self::executeCount($pdo, "
                DELETE FROM sitebuilder.access
                WHERE site_id = :site_id
            ", $siteId);

            $counts['menus'] = self::executeCount($pdo, "
                DELETE FROM sitebuilder.menu
                WHERE site_id = :site_id
            ", $siteId);

            $counts['layouts'] = self::executeCount($pdo, "
                DELETE FROM sitebuilder.layout
                WHERE site_id = :site_id
            ", $siteId);

            $counts['sites'] = self::executeCount($pdo, "
                DELETE FROM sitebuilder.site
                WHERE id = :site_id
            ", $siteId);

            if ($counts['sites'] !== 1) {
                throw new RuntimeException('SITE_DELETE_FAILED');
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        $warnings = [];

        try {
            $counts['templates'] = (int)sb_mutate_json_file(
                'templates.json',
                static function (array &$rows) use ($siteId): int {
                    $before = count($rows);
                    $rows = array_values(array_filter(
                        $rows,
                        static fn($row): bool => !is_array($row)
                            || (int)($row['siteId'] ?? 0) !== $siteId
                    ));

                    return $before - count($rows);
                },
                'Cannot clean templates.json'
            );
        } catch (Throwable $e) {
            $counts['templates'] = 0;
            error_log('SiteBuilder templates cleanup after site.delete failed: ' . $e->getMessage());
            $warnings[] = 'TEMPLATES_CLEANUP_FAILED';
        }

        return [
            'siteId' => $siteId,
            'deleted' => true,
            'counts' => $counts,
            'cleanupJobs' => array_map(
                static fn(array $job): array => [
                    'id' => (int)($job['id'] ?? 0),
                    'jobType' => (string)($job['jobType'] ?? ''),
                    'status' => (string)($job['status'] ?? ''),
                ],
                $cleanupJobs
            ),
            'warnings' => $warnings,
        ];
    }

    private static function executeCount(PDO $pdo, string $sql, int $siteId): int
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':site_id' => $siteId,
        ]);

        return $stmt->rowCount();
    }

}
