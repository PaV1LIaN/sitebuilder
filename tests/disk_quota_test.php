<?php

// php tests/disk_quota_test.php (PHP 8, pdo, mbstring).
// Real quota service, adapter and upload/copy/move handlers; native storage and PG are fixtures.
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    set_error_handler(static function ($severity, $message, $file, $line) {
        if (error_reporting() & $severity) throw new \ErrorException($message, 0, $severity, $file, $line);
        return false;
    });
    // Use native ZIP when available; otherwise a metadata/stream fixture tests the same action contracts.
    if (!class_exists('ZipArchive')) {
        class ZipArchive {
            public const CREATE = 1;
            public const OVERWRITE = 2;
            public int $numFiles = 0;
            private array $entries = [];
            private string $path = '';
            private bool $writing = false;
            public function open($path, $flags = 0): bool {
                $this->path = $path; $this->writing = $flags !== 0;
                $this->entries = $this->writing ? [] : json_decode(file_get_contents($path), true);
                $this->numFiles = count($this->entries); return true;
            }
            public function addFromString($name, $content): bool {
                $this->entries[] = ['name' => $name, 'content' => $content];
                $this->numFiles = count($this->entries); return true;
            }
            public function statIndex($index): array {
                $row = $this->entries[$index]; return ['name' => $row['name'], 'size' => strlen($row['content'])];
            }
            public function getStream($name) {
                foreach ($this->entries as $entry) if ($entry['name'] === $name) {
                    $stream = fopen('php://temp', 'w+b'); fwrite($stream, $entry['content']); rewind($stream); return $stream;
                }
                return false;
            }
            public function close(): bool {
                if ($this->writing) file_put_contents($this->path, json_encode($this->entries));
                return true;
            }
        }
    }
    class CFile { public static function MakeFileArray($id): array { return ['tmp_name' => $GLOBALS['quotaArchivePath']]; } }
    final class QuotaFixture
    {
        public static array $objects = [];
        public static array $limits = [];
        public static array $locks = [];
        public static int $writes = 0;
        public static QuotaPDO $pdo;
        public static array $settings = [];
        public static function reset(): void
        {
            self::$objects = [
                1 => ['type' => 'folder', 'parent' => 0],
                10 => ['type' => 'folder', 'parent' => 1],
                11 => ['type' => 'folder', 'parent' => 10],
                15 => ['type' => 'folder', 'parent' => 10, 'deleted' => true],
                20 => ['type' => 'folder', 'parent' => 1],
                99 => ['type' => 'folder', 'parent' => 0],
                101 => ['type' => 'file', 'parent' => 10, 'size' => 60, 'hidden' => true],
                102 => ['type' => 'file', 'parent' => 11, 'size' => 30],
                103 => ['type' => 'file', 'parent' => 10, 'size' => 500, 'deleted' => true],
                104 => ['type' => 'file', 'parent' => 15, 'size' => 500],
                105 => ['type' => 'file', 'parent' => 20, 'size' => 40],
            ];
            self::$limits = [self::limit(10, 100)];
            self::$locks = [];
            self::$writes = 0;
            self::$pdo = new QuotaPDO(1);
            self::$settings = ['maxFileSize' => 1000, 'maxDiskSize' => 100];
        }
        public static function limit(int $root, int $bytes, string $mode = 'block'): array
        {
            return ['props_json' => json_encode(['rootMode' => $mode, 'rootFolderId' => $root, 'maxDiskSize' => $bytes]),
                    'disk_folder_id' => 10];
        }
    }
    class QuotaPDO extends \PDO
    {
        public function __construct(public int $owner) {}
        public function prepare(string $query, array $options = []): \PDOStatement|false
        { return new QuotaStatement($this->owner, $query); }
    }
    class QuotaStatement extends \PDOStatement
    {
        private bool $result = false;
        public function __construct(private int $owner, private string $sql) {}
        public function execute(?array $params = null): bool
        {
            check(($params[':namespace'] ?? 0) === 761340, 'separate advisory-lock namespace');
            $id = $params[':folder_id'];
            if (str_contains($this->sql, 'pg_try_advisory_lock')) {
                $held = QuotaFixture::$locks[$id] ?? null;
                $this->result = $held === null || $held === $this->owner;
                if ($this->result) QuotaFixture::$locks[$id] = $this->owner;
            } elseif (str_contains($this->sql, 'pg_advisory_unlock')) {
                check((QuotaFixture::$locks[$id] ?? null) === $this->owner, 'unlock belongs to the writer');
                unset(QuotaFixture::$locks[$id]);
                $this->result = true;
            } else { throw new \RuntimeException('Unexpected SQL'); }
            return true;
        }
        public function fetchColumn(int $column = 0): mixed { return $this->result; }
    }
    function sb_db(): \PDO { return QuotaFixture::$pdo; }
    function sb_db_fetch_all(string $sql, array $params = []): array
    {
        check(str_contains($sql, "b.type = 'disk'") && str_contains($sql, 'JOIN sitebuilder.page'), 'quota lookup is scoped to disk blocks');
        return QuotaFixture::$limits;
    }
    function check(bool $condition, string $message): void
    { if (!$condition) throw new \RuntimeException('Assertion failed: ' . $message); }
    function rejects(callable $callback, string $message): \Throwable
    {
        try { $callback(); } catch (\Throwable $e) {
            check($e->getMessage() === $message, 'expected ' . $message . ', got ' . $e->getMessage());
            return $e;
        }
        throw new \RuntimeException('Expected rejection: ' . $message);
    }
    class DiskCsrf { public static function validateFromRequest(): void {} }
    class DiskCurrentUser { public static function requireId(): int { return 2; } }
    class DiskSettingsRepository {
        public static function ensureExistsForBlock(...$args): array { return QuotaFixture::$settings; }
        public static function getByBlockId(int $id): array { return QuotaFixture::$settings; }
        public static function save($id, $settings, $version, $userId): void {
            check($version === 7, 'settings use expectedVersion');
            QuotaFixture::$settings = DiskSitebuilderBridge::normalizeDiskProps($settings);
        }
    }
    class BlockRepository { public static function getById($id): array { return ['version' => 8]; } }
    class DiskPermissionService { public static function resolve(...$args): array { return ['canEditSettings' => true]; } }
    class RevisionService { public static function requireExpectedVersion($value): int { return (int)$value; } }
    class OutboxService { public static function enqueueUnifiedAccessReconcile(...$args): array { return []; } }
    function sb_db_transaction_scope_begin(): bool { return true; }
    function sb_db_transaction_scope_commit($started): void {}
    function sb_db_transaction_scope_rollback($started): void {}
    function disk_normalize_bool($value): bool { return (bool)$value; }
    class DiskRootResolver { public static function resolve(...$args): int { return 10; } }
    class DiskValidator {
        public static function assertContext(...$args): void {}
        public static function assertFolderInsideRoot(...$args): void {}
        public static function assertItemsInsideRoot(...$args): void {}
        public static function assertFileInsideRoot(...$args): void {}
        public static function assertCanForItemParents(...$args): void {}
        public static function assertCanForFolder(...$args): array { return ['canView' => true]; }
        public static function assertCan(...$args): void {}
    }
    class QuotaResponse extends \Exception {
        public function __construct(public array $data) { parent::__construct('RESPONSE'); }
    }
    class DiskResponse { public static function success(array $data): void { throw new QuotaResponse($data); } }
    function disk_read_json_body(): array { return $GLOBALS['quotaPayload'] ?? []; }
    function sb_disk_release_session_lock(): void {}
}

namespace Bitrix\Main {
    class Loader { public static function includeModule($name): bool { return true; } }
}

namespace Bitrix\Disk {
    class FixtureObject
    {
        public function __construct(protected int $id) {}
        public function getId(): int { return $this->id; }
        public function getParentId(): int { return \QuotaFixture::$objects[$this->id]['parent']; }
        public function getName(): string { return \QuotaFixture::$objects[$this->id]['name'] ?? ($this->id . '.txt'); }
        public function isDeleted(): bool { return !empty(\QuotaFixture::$objects[$this->id]['deleted']); }
        public function getCreateTime(): string { return ''; }
        public function getUpdateTime(): string { return ''; }
        public function getCreatedBy(): int { return 2; }
        public function moveTo($target, $userId): bool {
            \QuotaFixture::$writes++;
            \QuotaFixture::$objects[$this->id]['parent'] = $target->getId();
            return true;
        }
    }
    class Folder extends FixtureObject
    {
        public static function loadById($id): ?self {
            return (\QuotaFixture::$objects[$id]['type'] ?? '') === 'folder' ? new self((int)$id) : null;
        }
        public function getChildren($security): array {
            \check($security === 'all-files', 'hidden files count against quota');
            $result = [];
            foreach (\QuotaFixture::$objects as $id => $row) {
                if ($row['parent'] === $this->id) $result[] = $row['type'] === 'folder' ? new self($id) : new File($id);
            }
            return $result;
        }
        public function uploadFile($file, $fields, $rights): File {
            \QuotaFixture::$writes++;
            $id = max(array_keys(\QuotaFixture::$objects)) + 1;
            \QuotaFixture::$objects[$id] = ['type' => 'file', 'parent' => $this->id, 'size' => filesize($file['tmp_name']), 'name' => $file['name']];
            return new File($id);
        }
    }
    class File extends FixtureObject
    {
        public static function loadById($id): ?self {
            return (\QuotaFixture::$objects[$id]['type'] ?? '') === 'file' ? new self((int)$id) : null;
        }
        public function getSize(): int { return \QuotaFixture::$objects[$this->id]['size']; }
        public function getExtension(): string { return pathinfo($this->getName(), PATHINFO_EXTENSION); }
        public function getFileId(): int { return $this->id; }
        public function copyTo($target, $userId, $autoRename): self {
            \QuotaFixture::$writes++;
            $id = max(array_keys(\QuotaFixture::$objects)) + 1;
            \QuotaFixture::$objects[$id] = \QuotaFixture::$objects[$this->id];
            \QuotaFixture::$objects[$id]['parent'] = $target->getId();
            return new self($id);
        }
    }
    class Driver {
        public static function getInstance(): self { return new self(); }
        public function getFakeSecurityContext($user): string { return 'all-files'; }
    }
}

namespace {
    require __DIR__ . '/../components/disk/lib/DiskContext.php';
    require __DIR__ . '/../components/disk/lib/DiskNameSanitizer.php';
    require __DIR__ . '/../components/disk/lib/DiskSitebuilderBridge.php';
    require __DIR__ . '/../components/disk/lib/DiskBitrixStorageAdapter.php';
    require __DIR__ . '/../components/disk/lib/DiskQuotaService.php';

    $context = new DiskContext(1, 2, 3, 2);
    $tests = [];
    $tests['normalization preserves the limit, defaults to unlimited'] = static function () {
        check(DiskSitebuilderBridge::normalizeDiskProps([])['maxDiskSize'] === 0, 'default');
        check(DiskSitebuilderBridge::normalizeDiskProps(['maxDiskSize' => 104857600])['maxDiskSize'] === 104857600, 'round trip');
        foreach ([-1, 1.5, 'invalid', null, [], '9007199254740992'] as $value) {
            rejects(static fn() => DiskQuotaService::validateLimit($value), 'INVALID_MAX_DISK_SIZE');
        }
        check(DiskQuotaService::validateLimit('104857600') === 104857600, 'integer strings accepted');
    };
    $tests['usage includes descendants and hidden files, excludes trash'] = static function () {
        check(DiskQuotaService::folderSize(10, 2) === 90, 'usage is 90 bytes');
    };
    $tests['settings API persists the cap and preserves it for an older client'] = static function () {
        $GLOBALS['quotaPayload'] = ['siteId' => 1, 'pageId' => 2, 'blockId' => 3, 'expectedVersion' => 7,
            'settings' => ['viewMode' => 'table', 'permissionMode' => 'inherit_site', 'maxDiskSize' => 104857600]];
        try { require __DIR__ . '/../components/disk/actions/save_settings.php'; }
        catch (QuotaResponse $response) { check($response->data['settings']['maxDiskSize'] === 104857600, 'saved cap returned'); }
        unset($GLOBALS['quotaPayload']['settings']['maxDiskSize']);
        try { require __DIR__ . '/../components/disk/actions/save_settings.php'; }
        catch (QuotaResponse $response) { check($response->data['settings']['maxDiskSize'] === 104857600, 'omitted cap preserved'); }
        $GLOBALS['quotaPayload']['settings']['maxDiskSize'] = 0;
        try { require __DIR__ . '/../components/disk/actions/save_settings.php'; }
        catch (QuotaResponse $response) { check($response->data['settings']['maxDiskSize'] === 0, 'cap disabled explicitly'); }
    };
    $tests['invalid cap is rejected before settings are saved'] = static function () {
        $GLOBALS['quotaPayload'] = ['siteId' => 1, 'pageId' => 2, 'blockId' => 3, 'expectedVersion' => 7,
            'settings' => ['viewMode' => 'table', 'permissionMode' => 'inherit_site', 'maxDiskSize' => -1]];
        rejects(static function () { require __DIR__ . '/../components/disk/actions/save_settings.php'; }, 'INVALID_MAX_DISK_SIZE');
        check(QuotaFixture::$settings['maxDiskSize'] === 100, 'invalid input does not reset cap');
    };
    $tests['exact capacity succeeds; one byte over fails'] = static function () use ($context) {
        DiskQuotaService::assertAdditional($context, 10, 10);
        $e = rejects(static fn() => DiskQuotaService::assertAdditional($context, 10, 11), 'DISK_QUOTA_EXCEEDED');
        check([$e->limitBytes, $e->usedBytes, $e->incomingBytes] === [100, 90, 11], 'error size details');
        check(!QuotaFixture::$locks, 'lock released on quota rejection');
    };
    $tests['zero means unlimited even for an existing oversized disk'] = static function () use ($context) {
        QuotaFixture::$limits = [QuotaFixture::limit(10, 0)];
        DiskQuotaService::assertAdditional($context, 10, 100000);
    };
    $tests['shared roots honor the smallest configured cap'] = static function () use ($context) {
        QuotaFixture::$limits[] = QuotaFixture::limit(10, 95, 'site');
        rejects(static fn() => DiskQuotaService::assertAdditional($context, 11, 6), 'DISK_QUOTA_EXCEEDED');
    };
    $tests['nested root and ancestor quotas both apply'] = static function () use ($context) {
        QuotaFixture::$limits[] = QuotaFixture::limit(11, 35);
        rejects(static fn() => DiskQuotaService::assertAdditional($context, 11, 6), 'DISK_QUOTA_EXCEEDED');
        DiskQuotaService::assertAdditional($context, 10, 6);
    };
    $tests['unrelated folder quota is ignored'] = static function () use ($context) {
        QuotaFixture::$limits[] = QuotaFixture::limit(20, 1);
        DiskQuotaService::assertAdditional($context, 10, 10);
    };
    $tests['moving within a full disk adds zero bytes'] = static function () use ($context) {
        QuotaFixture::$limits = [QuotaFixture::limit(10, 80)];
        $GLOBALS['quotaPayload'] = ['siteId' => 1, 'pageId' => 2, 'blockId' => 3, 'items' => [['entityType' => 'file', 'id' => 101]], 'targetFolderId' => 11];
        try { require __DIR__ . '/../components/disk/actions/move.php'; } catch (QuotaResponse $response) {}
        check(QuotaFixture::$objects[101]['parent'] === 11, 'internal move succeeds');
    };
    $tests['moving into a nested capped root counts incoming bytes'] = static function () use ($context) {
        QuotaFixture::$limits[] = QuotaFixture::limit(11, 50);
        $items = [['entityType' => 'file', 'id' => 101]];
        rejects(static fn() => DiskQuotaService::write($context, 11,
            static fn(int $root): int => DiskQuotaService::itemsSize($context, $items, $root),
            static function () { throw new \RuntimeException('write must not run'); }), 'DISK_QUOTA_EXCEEDED');
    };
    $tests['copy cannot bypass quota'] = static function () {
        $GLOBALS['quotaPayload'] = ['siteId' => 1, 'pageId' => 2, 'blockId' => 3, 'items' => [['entityType' => 'file', 'id' => 102]], 'targetFolderId' => 10];
        rejects(static function () { require __DIR__ . '/../components/disk/actions/copy.php'; }, 'DISK_QUOTA_EXCEEDED');
        check(QuotaFixture::$writes === 0, 'no partial copy');
        QuotaFixture::$limits = [QuotaFixture::limit(10, 120)];
        try { require __DIR__ . '/../components/disk/actions/copy.php'; } catch (QuotaResponse $response) {}
        check(DiskQuotaService::folderSize(10, 2) === 120, 'successful copy consumes quota');
    };
    $tests['copy folder and multiple files count the full batch'] = static function () use ($context) {
        check(DiskQuotaService::itemsSize($context, [['entityType' => 'folder', 'id' => 11], ['entityType' => 'file', 'id' => 101]]) === 90, 'recursive batch');
    };
    $tests['upload checks actual temporary file bytes and rejects entire batch'] = static function () {
        $a = tempnam(sys_get_temp_dir(), 'sb_quota_');
        $b = tempnam(sys_get_temp_dir(), 'sb_quota_');
        try {
            file_put_contents($a, str_repeat('a', 6));
            file_put_contents($b, str_repeat('b', 5));
            $_POST = ['siteId' => 1, 'pageId' => 2, 'blockId' => 3, 'currentFolderId' => 10];
            $_FILES = ['files' => ['name' => ['a.txt', 'b.txt'], 'type' => ['text/plain', 'text/plain'],
                'tmp_name' => [$a, $b], 'error' => [0, 0], 'size' => [1, 1]]];
            rejects(static function () { require __DIR__ . '/../components/disk/actions/upload.php'; }, 'DISK_QUOTA_EXCEEDED');
            check(QuotaFixture::$writes === 0, 'no partial upload');
            QuotaFixture::$limits = [QuotaFixture::limit(10, 101)];
            try { require __DIR__ . '/../components/disk/actions/upload.php'; } catch (QuotaResponse $response) {}
            check(DiskQuotaService::folderSize(10, 2) === 101, 'entire batch fits exactly');
        } finally { unlink($a); unlink($b); }
    };
    $tests['concurrent writers cannot both spend the remaining capacity'] = static function () use ($context) {
        DiskQuotaService::write($context, 10, static fn(int $root): int => 8, static function () use ($context) {
            $first = QuotaFixture::$pdo;
            QuotaFixture::$pdo = new QuotaPDO(2);
            try { rejects(static fn() => DiskQuotaService::assertAdditional($context, 11, 8), 'DISK_QUOTA_BUSY'); }
            finally { QuotaFixture::$pdo = $first; }
            QuotaFixture::$objects[120] = ['type' => 'file', 'parent' => 10, 'size' => 8];
        });
        QuotaFixture::$pdo = new QuotaPDO(2);
        rejects(static fn() => DiskQuotaService::assertAdditional($context, 11, 8), 'DISK_QUOTA_EXCEEDED');
        check(!QuotaFixture::$locks, 'no lock leak');
    };
    $tests['writer errors release the quota lock'] = static function () use ($context) {
        rejects(static fn() => DiskQuotaService::write($context, 10, static fn(int $root): int => 5,
            static function () { throw new \RuntimeException('WRITE_FAILED'); }), 'WRITE_FAILED');
        check(!QuotaFixture::$locks, 'released after storage failure');
        DiskQuotaService::assertAdditional($context, 10, 5);
    };
    $tests['cyclic roots fail safely'] = static function () use ($context) {
        QuotaFixture::$objects[10]['parent'] = 11;
        rejects(static fn() => DiskQuotaService::assertAdditional($context, 10, 1), 'FOLDER_OUT_OF_SCOPE');
    };
    function archiveFixture(callable $test): void
    {
        $dir = sys_get_temp_dir() . '/sb-quota-archive-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $_SERVER['DOCUMENT_ROOT'] = $dir;
        $GLOBALS['quotaArchivePath'] = $dir . '/fixture.zip';
        $zip = new \ZipArchive();
        $zip->open($GLOBALS['quotaArchivePath'], \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('unpacked.txt', str_repeat('a', 11));
        $zip->close();
        QuotaFixture::$objects[106] = ['type' => 'file', 'parent' => 10, 'size' => 10, 'name' => 'fixture.zip'];
        $GLOBALS['quotaPayload'] = ['siteId' => 1, 'pageId' => 2, 'blockId' => 3, 'fileId' => 106];
        try { $test(); }
        finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
            rmdir($dir);
        }
    }
    $tests['legacy archive extraction checks total bytes before writing'] = static function () {
        archiveFixture(static function () {
            QuotaFixture::$limits = [QuotaFixture::limit(10, 110)];
            rejects(static function () { require __DIR__ . '/../components/disk/actions/unpack_archive.php'; }, 'DISK_QUOTA_EXCEEDED');
            check(QuotaFixture::$writes === 0, 'legacy ZIP writes nothing when total does not fit');
        });
    };
    $tests['archive start rejects insufficient space before creating a job'] = static function () {
        archiveFixture(static function () {
            QuotaFixture::$limits = [QuotaFixture::limit(10, 110)];
            rejects(static function () { require __DIR__ . '/../components/disk/actions/unpack_archive_start.php'; }, 'DISK_QUOTA_EXCEEDED');
            check(!is_dir($_SERVER['DOCUMENT_ROOT'] . '/upload'), 'no job created');
        });
    };
    $tests['archive step rechecks current capacity and can resume after space is freed'] = static function () {
        archiveFixture(static function () {
            QuotaFixture::$limits = [QuotaFixture::limit(10, 111)];
            try { require __DIR__ . '/../components/disk/actions/unpack_archive_start.php'; }
            catch (QuotaResponse $response) { $jobId = $response->data['jobId']; }
            QuotaFixture::$limits = [QuotaFixture::limit(10, 110)];
            $GLOBALS['quotaPayload'] = ['jobId' => $jobId];
            rejects(static function () { require __DIR__ . '/../components/disk/actions/unpack_archive_step.php'; }, 'DISK_QUOTA_EXCEEDED');
            check(QuotaFixture::$writes === 0, 'step does not use stale job limit');
            QuotaFixture::$limits = [QuotaFixture::limit(10, 111)];
            try { require __DIR__ . '/../components/disk/actions/unpack_archive_step.php'; }
            catch (QuotaResponse $response) { check($response->data['done'], 'archive complete'); }
            check(DiskQuotaService::folderSize(10, 2) === 111, 'actual extracted bytes consume quota');
        });
    };
    $tests['capacity snapshot includes hidden usage and deduplicates shared caps'] = static function () use ($context) {
        QuotaFixture::$limits[] = QuotaFixture::limit(10, 95, 'site');
        check(DiskQuotaService::status($context, 10, 11) === [
            'usedBytes' => 90, 'limitBytes' => 95, 'availableBytes' => 5, 'hasAdditionalLimit' => false,
        ], 'same disk stats when browsing a subfolder');
    };
    $tests['nested and ancestor limits constrain available bytes without exposing their folders'] = static function () use ($context) {
        QuotaFixture::$limits[] = QuotaFixture::limit(11, 35);
        $quota = DiskQuotaService::status($context, 10, 11);
        check($quota['usedBytes'] === 90 && $quota['limitBytes'] === 100 && $quota['availableBytes'] === 5 && $quota['hasAdditionalLimit'], 'nested cap');
        QuotaFixture::$limits = [QuotaFixture::limit(1, 132)];
        check(DiskQuotaService::status($context, 10, 11) === [
            'usedBytes' => 90, 'limitBytes' => 0, 'availableBytes' => 2, 'hasAdditionalLimit' => true,
        ], 'parent cap only exposes remaining bytes, not external names or totals');
        rejects(static fn() => DiskQuotaService::status($context, 10, 99), 'FOLDER_OUT_OF_SCOPE');
    };
    $tests['unlimited, overfull and emptied disks have unambiguous stats'] = static function () use ($context) {
        QuotaFixture::$limits = [];
        check(DiskQuotaService::status($context, 10, 10)['availableBytes'] === null, 'unlimited is null, not zero');
        QuotaFixture::$limits = [QuotaFixture::limit(10, 80)];
        check(DiskQuotaService::status($context, 10, 10)['availableBytes'] === 0, 'overfull clamps at zero');
        QuotaFixture::$objects[101]['deleted'] = true;
        QuotaFixture::$objects[102]['deleted'] = true;
        $quota = DiskQuotaService::status($context, 10, 10);
        check($quota['usedBytes'] === 0 && $quota['availableBytes'] === 80, 'deletion immediately frees capacity');
    };
    $tests['metadata preflight checks whole batch, exact fit and empty files'] = static function () use ($context) {
        $quota = DiskQuotaService::status($context, 10, 11);
        $files = [['name' => 'a.txt', 'size' => 6], ['name' => 'b.txt', 'size' => 5]];
        $result = DiskQuotaService::checkUpload($files, QuotaFixture::$settings, $quota);
        check(!$result['fits'] && $result['reason'] === 'DISK_QUOTA_EXCEEDED' && $result['incomingBytes'] === 11, 'whole batch rejected');
        $files[1]['size'] = 4;
        check(DiskQuotaService::checkUpload($files, [], $quota)['fits'], 'exact fit');
        $quota['availableBytes'] = 0;
        check(DiskQuotaService::checkUpload([['name' => 'empty.txt', 'size' => 0]], [], $quota)['fits'], 'empty file fits full disk');
        check(QuotaFixture::$writes === 0 && !QuotaFixture::$locks, 'preflight has no writes or reserved space');
    };
    $tests['preflight applies current file size and extension settings'] = static function () use ($context) {
        $quota = DiskQuotaService::status($context, 10, 10);
        $file = [['name' => 'a.TXT', 'size' => 6]];
        check(DiskQuotaService::checkUpload($file, ['maxFileSize' => 5], $quota)['reason'] === 'FILE_TOO_LARGE', 'single-file cap');
        check(DiskQuotaService::checkUpload($file, ['allowedExtensions' => ['pdf']], $quota)['reason'] === 'EXTENSION_NOT_ALLOWED', 'type rejected');
        check(DiskQuotaService::checkUpload($file, ['allowedExtensions' => ['txt']], $quota)['fits'], 'extension case matches upload handler');
        foreach ([[], [['name' => 'a', 'size' => -1]], [['name' => 'a', 'size' => '1']], [['name' => 'a', 'size' => 1.5]], [['name' => '', 'size' => 1]]] as $bad) {
            rejects(static fn() => DiskQuotaService::checkUpload($bad, [], $quota), 'INVALID_UPLOAD_METADATA');
        }
    };
    $tests['successful preflight does not bypass later server quota checks'] = static function () use ($context) {
        $quota = DiskQuotaService::status($context, 10, 10);
        check(DiskQuotaService::checkUpload([['name' => 'a.txt', 'size' => 10]], [], $quota)['fits'], 'initial fit');
        QuotaFixture::$objects[120] = ['type' => 'file', 'parent' => 10, 'size' => 1];
        rejects(static fn() => DiskQuotaService::assertAdditional($context, 10, 10), 'DISK_QUOTA_EXCEEDED');
    };
    foreach ($tests as $name => $test) {
        QuotaFixture::reset();
        $test();
        echo "PASS: $name\n";
    }
    echo count($tests) . " quota scenarios passed.\n";
}
