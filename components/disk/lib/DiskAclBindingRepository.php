<?php

declare(strict_types=1);

/**
 * Реестр семантических назначений точного режима Битрикс24.Диска.
 *
 * RightsManager удаляет избыточный отрицательный ACL, если у пользователя и
 * без него нет унаследованного чтения. Реестр сохраняет намерение `none`,
 * чтобы после перечитывания отличать его от выбранного пользователем inherit.
 */
final class DiskAclBindingRepository
{
    private const TARGET_TYPE = 'disk_acl';

    /** @return array<string,array<string,mixed>> accessCode => binding */
    public static function listManagedForFolder(
        int $siteId,
        int $folderId
    ): array {
        if ($siteId <= 0 || $folderId <= 0) {
            return [];
        }

        try {
            $rows = DiskDb::fetchAll(
                "SELECT access_code, desired_level, applied_level,
                        last_external_level, status, managed
                 FROM sitebuilder.access_sync_binding
                 WHERE site_id = :site_id
                   AND target_type = :target_type
                   AND target_id = :target_id
                   AND managed = TRUE
                 ORDER BY access_code",
                [
                    ':site_id' => $siteId,
                    ':target_type' => self::TARGET_TYPE,
                    ':target_id' => $folderId,
                ]
            );
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() === '42P01') {
                return [];
            }
            throw $exception;
        }

        $result = [];
        foreach ($rows as $row) {
            $accessCode = mb_strtoupper(trim((string)($row['access_code'] ?? '')));
            if (!preg_match('/^U[1-9]\d*$/', $accessCode)) {
                continue;
            }
            $result[$accessCode] = [
                'accessCode' => $accessCode,
                'desiredLevel' => (string)($row['desired_level'] ?? ''),
                'appliedLevel' => (string)($row['applied_level'] ?? ''),
                'lastExternalLevel' => (string)($row['last_external_level'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
            ];
        }

        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param array<int,string> $requestedRights Семантическое назначение.
     * @param array<int,string> $externalRights Фактическое прямое ACL после set().
     */
    public static function saveManagedIntents(
        DiskContext $context,
        int $folderId,
        array $requestedRights,
        array $externalRights
    ): void {
        if (
            $context->siteId <= 0
            || $folderId <= 0
            || empty($requestedRights)
        ) {
            return;
        }

        $pdo = DiskDb::getConnection();
        $ownsTransaction = !$pdo->inTransaction();

        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }

            $statement = $pdo->prepare(
                "INSERT INTO sitebuilder.access_sync_binding (
                    site_id, target_type, target_id, access_code,
                    desired_level, applied_level, last_external_level,
                    status, managed, last_run_id, last_error_code,
                    metadata_json, first_managed_at, last_checked_at,
                    last_applied_at, updated_at
                 ) VALUES (
                    :site_id, :target_type, :target_id, :access_code,
                    :desired_level, :applied_level, :last_external_level,
                    :status, TRUE, NULL, NULL,
                    CAST(:metadata_json AS jsonb), NOW(), NOW(), NOW(), NOW()
                 )
                 ON CONFLICT (site_id, target_type, target_id, access_code)
                 DO UPDATE SET
                    desired_level = EXCLUDED.desired_level,
                    applied_level = EXCLUDED.applied_level,
                    last_external_level = EXCLUDED.last_external_level,
                    status = EXCLUDED.status,
                    managed = TRUE,
                    last_error_code = NULL,
                    metadata_json = EXCLUDED.metadata_json,
                    first_managed_at = COALESCE(
                        sitebuilder.access_sync_binding.first_managed_at,
                        NOW()
                    ),
                    last_checked_at = NOW(),
                    last_applied_at = NOW(),
                    updated_at = NOW()"
            );

            foreach ($requestedRights as $userId => $taskName) {
                $userId = (int)$userId;
                $taskName = trim((string)$taskName);
                if ($userId <= 0 || $taskName === '') {
                    throw new RuntimeException('INVALID_DISK_RIGHT_ROW');
                }

                $statement->execute([
                    ':site_id' => $context->siteId,
                    ':target_type' => self::TARGET_TYPE,
                    ':target_id' => $folderId,
                    ':access_code' => 'U' . $userId,
                    ':desired_level' => $taskName,
                    ':applied_level' => $taskName,
                    ':last_external_level' => (string)(
                        $externalRights[$userId]
                        ?? BitrixDiskRightsService::INHERIT
                    ),
                    ':status' => $taskName === BitrixDiskRightsService::INHERIT
                        ? 'removed'
                        : 'synced',
                    ':metadata_json' => json_encode([
                        'source' => 'disk_access_matrix',
                        'pageId' => $context->pageId,
                        'blockId' => $context->blockId,
                        'actorUserId' => $context->currentUserId,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (PDOException $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string)$exception->getCode() === '42P01') {
                throw new RuntimeException(
                    'DISK_ACL_INTENT_STORAGE_UNAVAILABLE',
                    0,
                    $exception
                );
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
