<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/SiteTemplateService.php';
require_once __DIR__ . '/IntegrityCheckService.php';
require_once __DIR__ . '/PageAccessRepository.php';

/** Переносимые резервные копии контента одного сайта. */
final class BackupService
{
    public const FORMAT = 'sitebuilder-site-backup';
    public const FORMAT_VERSION = 1;
    private const LOCK_NAMESPACE = 761325;

    public static function config(): array
    {
        static $config = null;
        if (is_array($config)) {
            return $config;
        }
        $path = dirname(__DIR__) . '/config/backup.php';
        $loaded = is_file($path) ? require $path : [];
        $config = array_merge([
            'absolute_directory' => '',
            'relative_directory' => '/upload/sitebuilder/backups',
            'max_uncompressed_bytes' => 50 * 1024 * 1024,
            'max_stored_bytes' => 25 * 1024 * 1024,
            'retention_days' => 90,
            'list_limit' => 100,
        ], is_array($loaded) ? $loaded : []);
        $config['absolute_directory'] = trim((string)$config['absolute_directory']);
        $config['relative_directory'] = '/' . trim((string)$config['relative_directory'], '/');
        $config['max_uncompressed_bytes'] = max(1024 * 1024, (int)$config['max_uncompressed_bytes']);
        $config['max_stored_bytes'] = max(1024 * 1024, (int)$config['max_stored_bytes']);
        $config['retention_days'] = max(1, min(3650, (int)$config['retention_days']));
        $config['list_limit'] = max(1, min(200, (int)$config['list_limit']));
        return $config;
    }

    public static function list(int $siteId, int $limit = 0): array
    {
        $limit = $limit > 0 ? $limit : (int)self::config()['list_limit'];
        $limit = max(1, min(200, $limit));
        $stmt = sb_db()->prepare("\n            SELECT * FROM sitebuilder.site_backup\n            WHERE original_site_id=:site_id AND deleted_at IS NULL\n            ORDER BY id DESC LIMIT :limit\n        ");
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public static function get(int $id): ?array
    {
        $row = sb_db_fetch_one('SELECT * FROM sitebuilder.site_backup WHERE id=:id', [':id' => $id]);
        return $row ? self::mapRow($row) : null;
    }

    public static function create(int $siteId, bool $includeAccess, int $actorUserId): array
    {
        $site = sb_find_site($siteId);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }
        self::requireStorage();

        $insert = sb_db()->prepare("\n            INSERT INTO sitebuilder.site_backup (\n                original_site_id,site_name,site_slug,status,format_version,include_access,\n                created_by,created_at,expires_at,metadata_json\n            ) VALUES (\n                :site_id,:site_name,:site_slug,'creating',:format_version,CAST(:include_access AS boolean),\n                :created_by,NOW(),NOW() + (CAST(:retention_days AS integer) * INTERVAL '1 day'),'{}'::jsonb\n            ) RETURNING id\n        ");
        $insert->execute([
            ':site_id' => $siteId,
            ':site_name' => (string)($site['name'] ?? ''),
            ':site_slug' => (string)($site['slug'] ?? ''),
            ':format_version' => self::FORMAT_VERSION,
            ':include_access' => $includeAccess ? 'true' : 'false',
            ':created_by' => $actorUserId > 0 ? $actorUserId : null,
            ':retention_days' => (int)self::config()['retention_days'],
        ]);
        $backupId = (int)$insert->fetchColumn();
        if ($backupId <= 0) {
            throw new RuntimeException('BACKUP_RECORD_CREATE_FAILED');
        }

        $package = self::buildPackage($siteId, $includeAccess, $actorUserId);
        $json = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $payloadSize = strlen($json);
        if ($payloadSize > (int)self::config()['max_uncompressed_bytes']) {
            throw new RuntimeException('BACKUP_TOO_LARGE');
        }
        $compression = function_exists('gzencode') ? 'gzip' : 'none';
        $body = $compression === 'gzip' ? gzencode($json, 6) : $json;
        if (!is_string($body)) {
            throw new RuntimeException('BACKUP_COMPRESSION_FAILED');
        }
        $fileSize = strlen($body);
        if ($fileSize > (int)self::config()['max_stored_bytes']) {
            throw new RuntimeException('BACKUP_STORED_FILE_TOO_LARGE');
        }

        $relativeName = sprintf('site-%d-backup-%d-%s.json%s', $siteId, $backupId, bin2hex(random_bytes(6)), $compression === 'gzip' ? '.gz' : '');
        $absolute = self::directory() . '/' . $relativeName;
        self::atomicWrite($absolute, $body);
        sb_db_after_rollback(static function () use ($absolute): void {
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        });

        $sha = hash('sha256', $body);
        $metadata = [
            'counts' => $package['manifest']['counts'],
            'integrity' => $package['diagnostics']['integrity']['summary'] ?? [],
            'diskFilesIncluded' => false,
            'externalResourcesIncluded' => false,
        ];
        sb_db_execute("\n            UPDATE sitebuilder.site_backup\n            SET status='ready',storage_path=:storage_path,compression=:compression,sha256=:sha256,\n                file_size=:file_size,payload_size=:payload_size,metadata_json=CAST(:metadata AS jsonb),\n                error_code=NULL\n            WHERE id=:id\n        ", [
            ':id' => $backupId,
            ':storage_path' => $relativeName,
            ':compression' => $compression,
            ':sha256' => $sha,
            ':file_size' => $fileSize,
            ':payload_size' => $payloadSize,
            ':metadata' => self::encode($metadata),
        ]);

        return self::get($backupId) ?: throw new RuntimeException('BACKUP_RECORD_NOT_FOUND');
    }

    /**
     * Импортирует ранее скачанный пакет в реестр указанного сайта.
     * Права из загруженного файла намеренно удаляются: числовые U/G-коды
     * не являются переносимыми между порталами.
     */
    public static function import(int $registrySiteId, array $upload, int $actorUserId): array
    {
        $registrySite = sb_find_site($registrySiteId);
        if (!$registrySite) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new RuntimeException('BACKUP_UPLOAD_TOO_LARGE');
            }
            throw new RuntimeException('BACKUP_UPLOAD_FAILED');
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            throw new RuntimeException('BACKUP_UPLOAD_MISSING');
        }

        $reportedSize = (int)($upload['size'] ?? 0);
        if ($reportedSize <= 0 || $reportedSize > (int)self::config()['max_stored_bytes']) {
            throw new RuntimeException('BACKUP_UPLOAD_TOO_LARGE');
        }

        $uploadedBody = file_get_contents($tmpName);
        if (!is_string($uploadedBody) || $uploadedBody === '') {
            throw new RuntimeException('BACKUP_FILE_EMPTY');
        }
        if (strlen($uploadedBody) > (int)self::config()['max_stored_bytes']) {
            throw new RuntimeException('BACKUP_UPLOAD_TOO_LARGE');
        }

        $sourceCompression = str_starts_with($uploadedBody, "\x1f\x8b") ? 'gzip' : 'none';
        $package = self::decodePackageBody($uploadedBody, $sourceCompression);

        /* Чужие числовые user/group IDs никогда не восстанавливаем из импорта. */
        unset($package['access']);
        $package['manifest']['includeAccess'] = false;
        $package['importedAt'] = date('c');
        $package['importedBy'] = $actorUserId;

        $json = self::encode($package);
        $payloadSize = strlen($json);
        if ($payloadSize > (int)self::config()['max_uncompressed_bytes']) {
            throw new RuntimeException('BACKUP_TOO_LARGE');
        }

        $compression = function_exists('gzencode') ? 'gzip' : 'none';
        $body = $compression === 'gzip' ? gzencode($json, 6) : $json;
        if (!is_string($body)) {
            throw new RuntimeException('BACKUP_COMPRESSION_FAILED');
        }
        $fileSize = strlen($body);
        if ($fileSize > (int)self::config()['max_stored_bytes']) {
            throw new RuntimeException('BACKUP_STORED_FILE_TOO_LARGE');
        }

        self::requireStorage();
        $manifest = is_array($package['manifest'] ?? null) ? $package['manifest'] : [];
        $sourceName = trim((string)($manifest['sourceSiteName'] ?? $package['payload']['site']['name'] ?? 'Импортированная копия'));
        $sourceSlug = trim((string)($manifest['sourceSiteSlug'] ?? $package['payload']['site']['slug'] ?? ''));

        $insert = sb_db()->prepare("\n            INSERT INTO sitebuilder.site_backup (\n                original_site_id,site_name,site_slug,status,format_version,include_access,\n                created_by,created_at,expires_at,metadata_json\n            ) VALUES (\n                :site_id,:site_name,:site_slug,'creating',:format_version,FALSE,\n                :created_by,NOW(),NOW() + (CAST(:retention_days AS integer) * INTERVAL '1 day'),'{}'::jsonb\n            ) RETURNING id\n        ");
        $insert->execute([
            ':site_id' => $registrySiteId,
            ':site_name' => $sourceName !== '' ? $sourceName : 'Импортированная копия',
            ':site_slug' => $sourceSlug,
            ':format_version' => self::FORMAT_VERSION,
            ':created_by' => $actorUserId > 0 ? $actorUserId : null,
            ':retention_days' => (int)self::config()['retention_days'],
        ]);
        $backupId = (int)$insert->fetchColumn();
        if ($backupId <= 0) {
            throw new RuntimeException('BACKUP_RECORD_CREATE_FAILED');
        }

        $relativeName = sprintf(
            'site-%d-import-%d-%s.json%s',
            $registrySiteId,
            $backupId,
            bin2hex(random_bytes(6)),
            $compression === 'gzip' ? '.gz' : ''
        );
        $absolute = self::directory() . '/' . $relativeName;
        self::atomicWrite($absolute, $body);
        sb_db_after_rollback(static function () use ($absolute): void {
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        });

        $metadata = [
            'counts' => is_array($manifest['counts'] ?? null) ? $manifest['counts'] : [],
            'integrity' => $package['diagnostics']['integrity']['summary'] ?? [],
            'diskFilesIncluded' => false,
            'externalResourcesIncluded' => false,
            'imported' => true,
            'sourceSiteId' => (int)($manifest['sourceSiteId'] ?? 0),
            'sourceCreatedAt' => (string)($package['createdAt'] ?? ''),
            'accessDiscardedOnImport' => true,
        ];
        sb_db_execute("\n            UPDATE sitebuilder.site_backup\n            SET status='ready',storage_path=:storage_path,compression=:compression,sha256=:sha256,\n                file_size=:file_size,payload_size=:payload_size,metadata_json=CAST(:metadata AS jsonb),\n                error_code=NULL\n            WHERE id=:id\n        ", [
            ':id' => $backupId,
            ':storage_path' => $relativeName,
            ':compression' => $compression,
            ':sha256' => hash('sha256', $body),
            ':file_size' => $fileSize,
            ':payload_size' => $payloadSize,
            ':metadata' => self::encode($metadata),
        ]);

        return self::get($backupId) ?: throw new RuntimeException('BACKUP_RECORD_NOT_FOUND');
    }

    public static function verify(int $id): array
    {
        $record = self::get($id);
        if (!$record || $record['deletedAt'] !== '') {
            throw new RuntimeException('BACKUP_NOT_FOUND');
        }
        try {
            $package = self::loadPackage($record);
            sb_db_execute("\n                UPDATE sitebuilder.site_backup SET status='ready',verified_at=NOW(),error_code=NULL WHERE id=:id\n            ", [':id' => $id]);
            return ['valid' => true, 'backup' => self::get($id), 'manifest' => $package['manifest'] ?? []];
        } catch (Throwable $e) {
            sb_db_execute("\n                UPDATE sitebuilder.site_backup SET status='corrupt',verified_at=NOW(),error_code='BACKUP_VERIFY_FAILED' WHERE id=:id\n            ", [':id' => $id]);
            return ['valid' => false, 'backup' => self::get($id), 'error' => 'BACKUP_VERIFY_FAILED'];
        }
    }

    public static function restore(
        int $id,
        string $siteName,
        string $slug,
        int $sectionId,
        bool $restoreAccess,
        int $actorUserId
    ): array {
        $record = self::get($id);
        if (!$record || $record['deletedAt'] !== '' || $record['status'] !== 'ready') {
            throw new RuntimeException('BACKUP_NOT_READY');
        }
        $package = self::loadPackage($record);
        $payload = is_array($package['payload'] ?? null) ? $package['payload'] : [];
        if ($sectionId > 0 && !sb_db_fetch_one('SELECT id FROM sitebuilder.site_section WHERE id=:id', [':id' => $sectionId])) {
            throw new RuntimeException('SECTION_NOT_FOUND');
        }
        $result = SiteTemplateService::createSiteFromPayload($payload, $siteName, $slug, $sectionId, $actorUserId);
        $newSiteId = (int)($result['site']['id'] ?? 0);
        if ($newSiteId <= 0) {
            throw new RuntimeException('BACKUP_RESTORE_SITE_CREATE_FAILED');
        }

        $accessResult = ['siteAccess' => 0, 'pageAccess' => 0, 'skipped' => 0];
        if ($restoreAccess && !empty($record['includeAccess'])) {
            $accessResult = self::restoreAccess(
                $newSiteId,
                is_array($result['pageIdMap'] ?? null) ? $result['pageIdMap'] : [],
                is_array($package['access'] ?? null) ? $package['access'] : [],
                $actorUserId
            );
        }

        sb_db_execute("\n            UPDATE sitebuilder.site_backup\n            SET last_restored_site_id=:site_id,restored_by=:restored_by,restored_at=NOW()\n            WHERE id=:id\n        ", [':id' => $id, ':site_id' => $newSiteId, ':restored_by' => $actorUserId > 0 ? $actorUserId : null]);

        $result['backup'] = self::get($id);
        $result['accessRestore'] = $accessResult;
        return $result;
    }

    public static function delete(int $id, int $actorUserId): array
    {
        $record = self::get($id);
        if (!$record || $record['deletedAt'] !== '') {
            throw new RuntimeException('BACKUP_NOT_FOUND');
        }
        $absolute = self::pathForRecord($record);
        sb_db_execute("\n            UPDATE sitebuilder.site_backup\n            SET status='deleted',deleted_by=:deleted_by,deleted_at=NOW() WHERE id=:id\n        ", [':id' => $id, ':deleted_by' => $actorUserId > 0 ? $actorUserId : null]);
        sb_db_after_commit(static function () use ($absolute): void {
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        });
        return self::get($id) ?: $record;
    }

    public static function downloadPath(int $id): array
    {
        $record = self::get($id);
        if (!$record || $record['status'] !== 'ready' || $record['deletedAt'] !== '') {
            throw new RuntimeException('BACKUP_NOT_READY');
        }
        $absolute = self::pathForRecord($record);
        if (!is_file($absolute)) {
            throw new RuntimeException('BACKUP_FILE_NOT_FOUND');
        }
        return ['record' => $record, 'path' => $absolute];
    }

    public static function cleanupExpired(int $limit = 200): int
    {
        $limit = max(1, min(2000, $limit));
        $stmt = sb_db()->prepare("
            SELECT id FROM sitebuilder.site_backup
            WHERE deleted_at IS NULL AND expires_at IS NOT NULL AND expires_at<NOW()
              AND status IN ('ready','corrupt','failed')
            ORDER BY id ASC LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $candidateIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $deleted = 0;

        foreach ($candidateIds as $backupId) {
            /* Один порядок блокировок с backup.verify/delete исключает гонку файла. */
            $lock = sb_db()->prepare("
                SELECT pg_advisory_xact_lock(
                    CAST(:namespace AS integer),CAST(:backup_id AS integer)
                )
            ");
            $lock->execute([
                ':namespace' => self::LOCK_NAMESPACE,
                ':backup_id' => $backupId,
            ]);

            $row = sb_db_fetch_one("
                SELECT * FROM sitebuilder.site_backup
                WHERE id=:id AND deleted_at IS NULL AND expires_at IS NOT NULL AND expires_at<NOW()
                  AND status IN ('ready','corrupt','failed')
                FOR UPDATE
            ", [':id' => $backupId]);
            if (!$row) {
                continue;
            }

            $mapped = self::mapRow($row);
            $absolute = self::pathForRecord($mapped);
            sb_db_execute("
                UPDATE sitebuilder.site_backup
                SET status='deleted',deleted_at=NOW() WHERE id=:id
            ", [':id' => $backupId]);
            sb_db_after_commit(static function () use ($absolute): void {
                if (is_file($absolute)) {
                    @unlink($absolute);
                }
            });
            $deleted++;
        }

        return $deleted;
    }

    private static function buildPackage(int $siteId, bool $includeAccess, int $actorUserId): array
    {
        $site = sb_find_site($siteId) ?: throw new RuntimeException('SITE_NOT_FOUND');
        $payload = SiteTemplateService::buildSitePayload($siteId);
        $integrity = IntegrityCheckService::inspectSite($siteId);
        $pages = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];
        $blocks = is_array($payload['blocks'] ?? null) ? $payload['blocks'] : [];
        $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $menus = is_array($payload['menus'] ?? null) ? $payload['menus'] : [];

        $package = [
            'format' => self::FORMAT,
            'formatVersion' => self::FORMAT_VERSION,
            'createdAt' => date('c'),
            'createdBy' => $actorUserId,
            'manifest' => [
                'sourceSiteId' => $siteId,
                'sourceSiteName' => (string)($site['name'] ?? ''),
                'sourceSiteSlug' => (string)($site['slug'] ?? ''),
                'sourceSiteVersion' => (int)($site['version'] ?? 1),
                'counts' => [
                    'pages' => count($pages),
                    'blocks' => count($blocks),
                    'sections' => count($sections),
                    'menus' => count($menus),
                ],
                'includeAccess' => $includeAccess,
                'diskFilesIncluded' => false,
                'externalResourcesIncluded' => false,
            ],
            'payload' => $payload,
            'diagnostics' => [
                'integrity' => $integrity,
                'phpVersion' => PHP_VERSION,
                'generatedAt' => date('c'),
            ],
        ];
        if ($includeAccess) {
            $package['access'] = self::snapshotAccess($siteId);
        }
        return $package;
    }

    private static function snapshotAccess(int $siteId): array
    {
        $siteRows = sb_db_fetch_all("\n            SELECT access_code,role FROM sitebuilder.access WHERE site_id=:site_id ORDER BY access_code\n        ", [':site_id' => $siteId]);
        $pageRows = sb_db_fetch_all("\n            SELECT page_id,access_code,can_view,can_edit,can_disk_view,can_disk_edit,include_children\n            FROM sitebuilder.page_access WHERE site_id=:site_id ORDER BY page_id,access_code\n        ", [':site_id' => $siteId]);
        return [
            'site' => array_map(static fn(array $row): array => [
                'accessCode' => (string)$row['access_code'],
                'role' => (string)$row['role'],
            ], $siteRows),
            'pages' => array_map(static fn(array $row): array => [
                'oldPageId' => (int)$row['page_id'],
                'accessCode' => (string)$row['access_code'],
                'canView' => self::boolValue($row['can_view'] ?? false),
                'canEdit' => self::boolValue($row['can_edit'] ?? false),
                'canDiskView' => self::boolValue($row['can_disk_view'] ?? false),
                'canDiskEdit' => self::boolValue($row['can_disk_edit'] ?? false),
                'includeChildren' => self::boolValue($row['include_children'] ?? false),
            ], $pageRows),
        ];
    }

    private static function restoreAccess(int $newSiteId, array $pageIdMap, array $snapshot, int $actorUserId): array
    {
        $counts = ['siteAccess' => 0, 'pageAccess' => 0, 'skipped' => 0];
        foreach ((array)($snapshot['site'] ?? []) as $row) {
            if (!is_array($row)) { $counts['skipped']++; continue; }
            $code = strtoupper(trim((string)($row['accessCode'] ?? '')));
            $role = strtoupper(trim((string)($row['role'] ?? '')));
            if (!preg_match('/^(U|G)[1-9]\d*$/', $code) || !in_array($role, ['VIEWER','EDITOR','ADMIN'], true)) {
                $counts['skipped']++; continue;
            }
            sb_add_access_role_if_missing($newSiteId, $code, $role, $actorUserId);
            $counts['siteAccess']++;
        }
        foreach ((array)($snapshot['pages'] ?? []) as $row) {
            if (!is_array($row)) { $counts['skipped']++; continue; }
            $oldPageId = (int)($row['oldPageId'] ?? 0);
            $newPageId = (int)($pageIdMap[$oldPageId] ?? 0);
            $code = strtoupper(trim((string)($row['accessCode'] ?? '')));
            if ($newPageId <= 0 || !preg_match('/^(U|G)[1-9]\d*$/', $code)) {
                $counts['skipped']++; continue;
            }
            try {
                PageAccessRepository::save(
                    $newSiteId,
                    $newPageId,
                    $code,
                    !empty($row['canView']),
                    !empty($row['canEdit']),
                    !empty($row['includeChildren']),
                    $actorUserId,
                    !empty($row['canDiskView']),
                    !empty($row['canDiskEdit'])
                );
                $counts['pageAccess']++;
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'EMPTY_PAGE_PERMISSION') {
                    $counts['skipped']++;
                    continue;
                }
                throw $e;
            }
        }
        return $counts;
    }

    private static function loadPackage(array $record): array
    {
        $absolute = self::pathForRecord($record);
        if (!is_file($absolute)) {
            throw new RuntimeException('BACKUP_FILE_NOT_FOUND');
        }
        $body = file_get_contents($absolute);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException('BACKUP_FILE_EMPTY');
        }
        if (!hash_equals((string)$record['sha256'], hash('sha256', $body))) {
            throw new RuntimeException('BACKUP_CHECKSUM_MISMATCH');
        }
        return self::decodePackageBody($body, (string)$record['compression']);
    }

    private static function decodePackageBody(string $body, string $compression): array
    {
        if (!in_array($compression, ['gzip', 'none'], true)) {
            throw new RuntimeException('BACKUP_COMPRESSION_INVALID');
        }
        if ($compression === 'gzip' && !function_exists('gzdecode')) {
            throw new RuntimeException('BACKUP_GZIP_NOT_SUPPORTED');
        }

        $max = (int)self::config()['max_uncompressed_bytes'];
        $json = $compression === 'gzip' ? gzdecode($body, $max + 1) : $body;
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('BACKUP_DECOMPRESSION_FAILED');
        }
        if (strlen($json) > $max) {
            throw new RuntimeException('BACKUP_TOO_LARGE');
        }

        try {
            $package = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('BACKUP_JSON_INVALID', 0, $e);
        }
        if (
            !is_array($package)
            || ($package['format'] ?? '') !== self::FORMAT
            || (int)($package['formatVersion'] ?? 0) !== self::FORMAT_VERSION
        ) {
            throw new RuntimeException('BACKUP_FORMAT_INVALID');
        }
        if (!is_array($package['payload'] ?? null) || !is_array($package['payload']['site'] ?? null)) {
            throw new RuntimeException('BACKUP_PAYLOAD_INVALID');
        }

        foreach (['pages', 'blocks', 'sections', 'menus'] as $key) {
            if (isset($package['payload'][$key]) && !is_array($package['payload'][$key])) {
                throw new RuntimeException('BACKUP_PAYLOAD_INVALID');
            }
        }

        return $package;
    }

    private static function requireStorage(): void
    {
        $dir = self::storageDirectory();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('BACKUP_DIRECTORY_CREATE_FAILED');
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('BACKUP_DIRECTORY_NOT_WRITABLE');
        }
    }

    /** Абсолютный приватный каталог новых резервных копий. */
    public static function storageDirectory(): string
    {
        $root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($root === '') {
            throw new RuntimeException('DOCUMENT_ROOT_REQUIRED');
        }

        $configured = trim((string)(self::config()['absolute_directory'] ?? ''));
        if ($configured !== '') {
            if (!str_starts_with($configured, '/')) {
                throw new RuntimeException('BACKUP_ABSOLUTE_DIRECTORY_REQUIRED');
            }
            return rtrim($configured, '/');
        }

        return rtrim(dirname($root), '/') . '/sitebuilder-private/backups';
    }

    /** Каталог, использовавшийся до этапа 13 внутри DOCUMENT_ROOT. */
    public static function legacyStorageDirectory(): string
    {
        $root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($root === '') {
            throw new RuntimeException('DOCUMENT_ROOT_REQUIRED');
        }
        return $root . self::config()['relative_directory'];
    }

    /**
     * Переносит существующие копии этапа 12 из web-каталога в приватный.
     * Имена в БД не меняются, поэтому операция безопасна для download/restore.
     */
    public static function migrateLegacyFiles(): array
    {
        self::requireStorage();
        $sourceDir = self::legacyStorageDirectory();
        $targetDir = self::storageDirectory();

        if ($sourceDir === $targetDir || !is_dir($sourceDir)) {
            return ['moved' => 0, 'existing' => 0, 'missing' => 0, 'conflicts' => 0];
        }

        $lockPath = $targetDir . '/.legacy-migration.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('BACKUP_MIGRATION_LOCK_FAILED');
        }

        $result = ['moved' => 0, 'existing' => 0, 'missing' => 0, 'conflicts' => 0];

        try {
            $rows = sb_db_fetch_all("
                SELECT storage_path, sha256
                FROM sitebuilder.site_backup
                WHERE deleted_at IS NULL AND storage_path <> ''
                ORDER BY id
            ");

            foreach ($rows as $row) {
                $name = basename((string)($row['storage_path'] ?? ''));
                if ($name === '' || $name !== (string)($row['storage_path'] ?? '')) {
                    $result['conflicts']++;
                    continue;
                }

                $source = $sourceDir . '/' . $name;
                $target = $targetDir . '/' . $name;
                $expectedSha = strtolower(trim((string)($row['sha256'] ?? '')));

                if (is_file($target)) {
                    $targetSha = hash_file('sha256', $target);
                    $sourceSha = is_file($source) ? hash_file('sha256', $source) : false;
                    $matchesExpected = $expectedSha !== ''
                        && is_string($targetSha)
                        && hash_equals($expectedSha, strtolower($targetSha));
                    $matchesSource = $expectedSha === ''
                        && is_string($targetSha)
                        && is_string($sourceSha)
                        && hash_equals(strtolower($sourceSha), strtolower($targetSha));
                    $targetOnlyWithoutExpected = $expectedSha === '' && !is_file($source) && is_string($targetSha);

                    if ($matchesExpected || $matchesSource || $targetOnlyWithoutExpected) {
                        $result['existing']++;
                        if (is_file($source)) {
                            @unlink($source);
                        }
                    } else {
                        $result['conflicts']++;
                    }
                    continue;
                }

                if (!is_file($source)) {
                    $result['missing']++;
                    continue;
                }

                $sourceSha = hash_file('sha256', $source);
                if ($expectedSha !== '' && (!is_string($sourceSha) || !hash_equals($expectedSha, strtolower($sourceSha)))) {
                    $result['conflicts']++;
                    continue;
                }

                if (!@rename($source, $target)) {
                    $tmpTarget = $target . '.tmp-' . bin2hex(random_bytes(6));
                    if (!@copy($source, $tmpTarget)) {
                        @unlink($tmpTarget);
                        $result['conflicts']++;
                        continue;
                    }

                    $tmpSha = hash_file('sha256', $tmpTarget);
                    if (!is_string($tmpSha) || !is_string($sourceSha) || !hash_equals(strtolower($sourceSha), strtolower($tmpSha))) {
                        @unlink($tmpTarget);
                        $result['conflicts']++;
                        continue;
                    }

                    @chmod($tmpTarget, 0640);
                    if (!@rename($tmpTarget, $target)) {
                        @unlink($tmpTarget);
                        $result['conflicts']++;
                        continue;
                    }
                    @unlink($source);
                }

                @chmod($target, 0640);
                $result['moved']++;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $result;
    }

    private static function directory(): string
    {
        return self::storageDirectory();
    }

    private static function pathForRecord(array $record): string
    {
        $name = basename((string)($record['storagePath'] ?? ''));
        if ($name === '' || $name !== (string)($record['storagePath'] ?? '')) {
            throw new RuntimeException('BACKUP_PATH_INVALID');
        }

        $privatePath = self::storageDirectory() . '/' . $name;
        if (is_file($privatePath)) {
            return $privatePath;
        }

        $legacyPath = self::legacyStorageDirectory() . '/' . $name;
        return is_file($legacyPath) ? $legacyPath : $privatePath;
    }

    private static function atomicWrite(string $path, string $body): void
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        $written = file_put_contents($tmp, $body, LOCK_EX);
        if ($written !== strlen($body)) {
            @unlink($tmp);
            throw new RuntimeException('BACKUP_WRITE_FAILED');
        }
        @chmod($tmp, 0640);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('BACKUP_RENAME_FAILED');
        }
    }

    private static function mapRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'originalSiteId' => (int)$row['original_site_id'],
            'siteName' => (string)$row['site_name'],
            'siteSlug' => (string)$row['site_slug'],
            'status' => (string)$row['status'],
            'formatVersion' => (int)$row['format_version'],
            'includeAccess' => self::boolValue($row['include_access'] ?? false),
            'storagePath' => (string)$row['storage_path'],
            'compression' => (string)$row['compression'],
            'sha256' => (string)$row['sha256'],
            'fileSize' => (int)$row['file_size'],
            'payloadSize' => (int)$row['payload_size'],
            'metadata' => sb_json_decode_assoc($row['metadata_json'] ?? []),
            'errorCode' => (string)($row['error_code'] ?? ''),
            'createdBy' => (int)($row['created_by'] ?? 0),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'verifiedAt' => (string)($row['verified_at'] ?? ''),
            'expiresAt' => (string)($row['expires_at'] ?? ''),
            'lastRestoredSiteId' => (int)($row['last_restored_site_id'] ?? 0),
            'restoredBy' => (int)($row['restored_by'] ?? 0),
            'restoredAt' => (string)($row['restored_at'] ?? ''),
            'deletedBy' => (int)($row['deleted_by'] ?? 0),
            'deletedAt' => (string)($row['deleted_at'] ?? ''),
        ];
    }

    private static function boolValue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true', 'y', 'yes', 'on'], true);
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
