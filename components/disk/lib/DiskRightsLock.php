<?php

declare(strict_types=1);

/** Shared by both Sitebuilder native-ACL editors; coordinates application cluster nodes. */
final class DiskRightsLock
{
    private const NAMESPACE_ID = 761328;

    public static function acquire(int $folderId): array
    {
        if ($folderId <= 0 || $folderId > 2147483647) {
            throw new RuntimeException('INVALID_DISK_FOLDER_ID');
        }
        $pdo = sb_db();
        $transactional = method_exists($pdo, 'inTransaction') && $pdo->inTransaction();
        $function = $transactional ? 'pg_try_advisory_xact_lock' : 'pg_try_advisory_lock';
        $stmt = $pdo->prepare('SELECT ' . $function . '(:namespace, :folder)');
        $stmt->execute([':namespace' => self::NAMESPACE_ID, ':folder' => $folderId]);
        if (!in_array($stmt->fetchColumn(), [true, 1, '1', 't', 'true'], true)) {
            throw new RuntimeException('DISK_RIGHTS_BUSY');
        }
        return [$pdo, $folderId, $transactional];
    }

    public static function release(array $lock): void
    {
        [$pdo, $folderId, $transactional] = $lock;
        if ($transactional) { return; }
        try {
            $stmt = $pdo->prepare('SELECT pg_advisory_unlock(:namespace, :folder)');
            $stmt->execute([':namespace' => self::NAMESPACE_ID, ':folder' => $folderId]);
        } catch (Throwable $error) {
            error_log('Sitebuilder native ACL unlock: ' . $error->getMessage());
        }
    }
}
