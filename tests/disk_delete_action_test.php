<?php
// Execute the production delete action AND production DiskValidator. Only
// Bitrix models / storage boundaries are replaced with in-memory test doubles.
namespace Bitrix\Disk {
    abstract class TestObject {
        public array $errors = [];
        public function __construct(public int $id, public int $parentId, public string $name, public bool $deleted = false, public string $mode = 'ok') {}
        public static function loadById(int $id): ?static {
            $item = \DeleteStore::$objects[$id] ?? null;
            return $item instanceof static ? clone $item : null;
        }
        public function getId(): int { return $this->id; }
        public function getParentId(): int { return $this->parentId; }
        public function getName(): string { return $this->name; }
        public function isDeleted(): bool { return $this->deleted; }
        public function getErrors(): array {
            if (\DeleteStore::$diagnosticsFail) { throw new \RuntimeException('diagnostic errors failed'); }
            return $this->errors;
        }
        public function delete(...$args) { throw new \RuntimeException('Permanent deletion forbidden'); }
        public function markDeleted(int $actor): bool {
            \DeleteStore::$calls[] = [$this->id, $actor];
            if ($this->mode === 'false') {
                $this->errors = [new \RuntimeException('Файл заблокирован другим пользователем', 423)];
                return false;
            }
            if ($this->mode === 'throw') { throw new \RuntimeException('SQL internal /private/server secret'); }
            if ($this->mode === 'bad_utf8') { throw new \RuntimeException("invalid byte: \xff"); }
            if ($this->mode === 'long_error') { throw new \RuntimeException(str_repeat('я', 900)); }
            $this->deleted = \DeleteStore::$objects[$this->id]->deleted = true;
            if ($this instanceof Folder) {
                foreach (\DeleteStore::$objects as $child) {
                    if ($child->parentId === $this->id) { $child->deleted = true; }
                }
            }
            if ($this->mode === 'after_throw') { throw new \RuntimeException('AFTER_DELETE_EVENT_FAILED'); }
            if ($this->mode === 'output') { echo '<b>third party debug output</b>'; }
            if (\DeleteStore::$afterMark) { (\DeleteStore::$afterMark)($this->id); }
            return true;
        }
    }
    class File extends TestObject {}
    class Folder extends TestObject {}
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    require __DIR__ . '/../components/disk/lib/DiskContext.php';
    require __DIR__ . '/../components/disk/lib/DiskValidator.php';

    class DeleteStore {
        public static array $objects = [], $calls = [], $deniedParents = [];
        public static bool $admin = false, $csrf = true, $diagnosticsFail = false;
        public static int $user = 42, $root = 10, $settingsReads = 0;
        public static $afterMark = null;
        public static function reset(): void {
            self::$objects = [
                10 => new \Bitrix\Disk\Folder(10, 1, 'site root'),
                11 => new \Bitrix\Disk\Folder(11, 10, 'subfolder'),
                99 => new \Bitrix\Disk\Folder(99, 1, 'outside root'),
                100 => new \Bitrix\Disk\File(100, 10, 'Первый.txt'),
                101 => new \Bitrix\Disk\File(101, 11, 'Второй.txt'),
                102 => new \Bitrix\Disk\File(102, 10, 'Третий.txt'),
            ];
            self::$calls = self::$deniedParents = [];
            self::$admin = self::$diagnosticsFail = false;
            self::$csrf = true; self::$user = 42; self::$root = 10;
            self::$settingsReads = 0; self::$afterMark = null;
        }
    }
    class DiskCurrentUser {
        public static function requireId(): int {
            if (DeleteStore::$user <= 0) { throw new \RuntimeException('NOT_AUTHORIZED'); }
            return DeleteStore::$user;
        }
        public static function isAdmin(): bool {
            if (DeleteStore::$diagnosticsFail) { throw new \RuntimeException('admin lookup failed'); }
            return DeleteStore::$admin;
        }
        public static function getGroupIds(): array {
            if (DeleteStore::$diagnosticsFail) { throw new \RuntimeException('groups lookup failed'); }
            return [2, 7];
        }
    }
    class CUser { public static function GetByID($id) {
        if (DeleteStore::$diagnosticsFail) { throw new \RuntimeException('user lookup failed'); }
        return new class { public function Fetch() { return ['LAST_NAME' => 'Тестов', 'NAME' => 'Иван']; } };
    } }
    class DiskCsrf { public static function validateFromRequest(): void {
        if (!DeleteStore::$csrf) { throw new \RuntimeException('BAD_SESSID'); }
    } }
    class DiskSettingsRepository {
        public static function getByBlockId($id): array { ++DeleteStore::$settingsReads; return ['permissionMode' => 'custom']; }
        public static function ensureExistsForBlock(...$args) { throw new \RuntimeException('Delete must not write settings'); }
        public static function save(...$args) { throw new \RuntimeException('Delete must not write settings'); }
    }
    class DiskRootResolver { public static function resolve(...$args) { return DeleteStore::$root ?: null; } }
    class SiteRepository { public static function getById($id) { return $id === 1 ? ['id' => 1] : null; } }
    class BlockRepository { public static function getDiskBlockByContext($site, $page, $block) {
        return $site === 1 && $page === 2 && $block === 3 ? ['id' => 3] : null;
    } }
    class DiskPermissionService { public static function resolve($context, $settings, $folder, $root): array {
        return ['canView' => true, 'canDelete' => !in_array($folder, DeleteStore::$deniedParents, true), 'role' => 'site_editor'];
    } }
    class DiskBitrixStorageAdapter {
        public function __construct($actor) {}
        public function isFolderInsideRoot($context, $folder, $root): bool {
            for ($depth = 0; $depth < 20; ++$depth) {
                if ($folder === $root) { return true; }
                $row = DeleteStore::$objects[$folder] ?? null;
                if (!$row instanceof \Bitrix\Disk\Folder) { return false; }
                $folder = $row->parentId;
            }
            return false;
        }
        public function delete(...$args) { throw new \RuntimeException('Old unchecked adapter delete must not be used'); }
    }
    class DeleteResponse extends \RuntimeException {
        public function __construct(public array $payload) { parent::__construct('captured'); }
    }
    class DiskResponse {
        public static function success($data = [], $meta = []): void { throw new DeleteResponse(['ok' => true, 'data' => $data, 'meta' => $meta]); }
        public static function error($code, $message = '', $details = []): void {
            throw new DeleteResponse(['ok' => false, 'error' => $code, 'message' => $message, 'details' => $details]);
        }
    }
    function disk_read_json_body() { return $GLOBALS['deleteBody']; }
    function deleteItem(int $id, string $type = 'file'): array { return ['entityType' => $type, 'id' => $id]; }
    function runDelete(array $items, array $extra = []): array {
        $GLOBALS['deleteBody'] = $extra + ['siteId' => 1, 'pageId' => 2, 'blockId' => 3, 'items' => $items];
        http_response_code(200);
        try { require __DIR__ . '/../components/disk/actions/delete.php'; }
        catch (DeleteResponse $r) {
            // Exactly what the production JSON encoder can encode, including UTF-8.
            json_encode($r->payload, JSON_THROW_ON_ERROR);
            return $r->payload + ['status' => http_response_code()];
        }
        throw new \RuntimeException('No response');
    }
    $checks = 0;
    function expect($expected, $actual, string $message): void {
        ++$GLOBALS['checks'];
        if ($expected !== $actual) { throw new \RuntimeException($message . ': ' . var_export($actual, true)); }
    }
    $oldLog = ini_get('error_log');
    $log = tempnam(sys_get_temp_dir(), 'sb-delete-test-');
    ini_set('error_log', $log);
    try {
        DeleteStore::reset();
        $r = runDelete([deleteItem(100)]);
        expect(true, $r['ok'], 'file success'); expect([[100, 42]], DeleteStore::$calls, 'actor preserved');
        expect([deleteItem(100)], $r['data']['result'], 'legacy success shape');
        expect(1, DeleteStore::$settingsReads, 'settings read once without writing');

        DeleteStore::reset();
        $r = runDelete([deleteItem(11, 'folder'), deleteItem(101)]);
        expect(true, $r['ok'], 'folder then selected child'); expect([[11, 42]], DeleteStore::$calls, 'child is not trashed twice');
        expect([deleteItem(101)], $r['meta']['alreadyDeleted'], 'child already deleted by folder');

        DeleteStore::reset();
        $r = runDelete([['entityType' => ' file ', 'id' => '100'], deleteItem(100)]);
        expect(true, $r['ok'], 'canonicalize type and numeric id'); expect(1, count(DeleteStore::$calls), 'deduplicate');
        $r = runDelete([deleteItem(100)]);
        expect(true, $r['ok'], 'retry on already trashed item'); expect(1, count(DeleteStore::$calls), 'retry does not emit another delete');

        DeleteStore::reset(); DeleteStore::$objects[100]->mode = 'false'; DeleteStore::$admin = true;
        $r = runDelete([deleteItem(100)]);
        expect(false, $r['ok'], 'native false is not success'); expect(409, $r['status'], 'native rejection status');
        expect('BITRIX_DELETE_REJECTED', $r['error'], 'native rejection code');
        expect('Файл заблокирован другим пользователем', $r['details']['bitrixErrors'][0]['message'], 'native errors');
        expect([2, 7], $r['details']['bitrixGroupIds'], 'admin can diagnose memberships');
        expect(true, $r['details']['effectivePermissions']['canDelete'], 'effective rights captured');
        expect('Тестов Иван', $r['details']['userName'], 'error actor name');

        DeleteStore::reset(); DeleteStore::$objects[101]->mode = 'throw';
        $r = runDelete([deleteItem(100), deleteItem(101), deleteItem(102)]);
        expect(false, $r['ok'], 'batch stops on error'); expect([deleteItem(100)], $r['details']['deletedBeforeFailure'], 'partial success');
        expect(deleteItem(101), $r['details']['failedItem'], 'exact failed item'); expect([[100, 42], [101, 42]], DeleteStore::$calls, 'third item not attempted');
        expect(false, isset($r['details']['technicalError']), 'no raw error for regular user');
        expect(false, str_contains($r['message'], 'secret'), 'no secrets in regular message');
        expect(false, isset($r['details']['bitrixGroupIds']), 'memberships admin-only');
        expect(true, $r['details']['reloadRequired'], 'partial failure requires reload');

        DeleteStore::reset(); DeleteStore::$objects[100]->mode = 'after_throw';
        $r = runDelete([deleteItem(100)]);
        expect(false, $r['ok'], 'post-mutation failure not swallowed'); expect('in_trash', $r['details']['currentItemState'], 'fresh state after exception');
        expect([], $r['details']['deletedBeforeFailure'], 'uncertain item not reported as completed');
        expect(1, count(DeleteStore::$calls), 'no destructive fallback or auto retry');

        DeleteStore::reset(); DeleteStore::$deniedParents = [11];
        $r = runDelete([deleteItem(100), deleteItem(101)]);
        expect(403, $r['status'], 'permission denial'); expect([], DeleteStore::$calls, 'whole batch preflight');
        expect(deleteItem(101), $r['details']['failedItem'], 'failed preflight identifies item');
        expect('permissions', $r['details']['stage'], 'denied stage');

        DeleteStore::reset(); DeleteStore::$objects[101]->parentId = 99;
        $r = runDelete([deleteItem(100), deleteItem(101)]);
        expect('FOLDER_OUT_OF_SCOPE', $r['error'], 'cross-site delete forbidden'); expect([], DeleteStore::$calls, 'no mutation on invalid scope');
        $r = runDelete([deleteItem(10, 'folder')]);
        expect('ROOT_FOLDER_OPERATION_FORBIDDEN', $r['error'], 'root protected');

        DeleteStore::reset(); DeleteStore::$afterMark = static function ($id) { DeleteStore::$objects[101]->parentId = 99; };
        $r = runDelete([deleteItem(100), deleteItem(101)]);
        expect('FOLDER_OUT_OF_SCOPE', $r['error'], 'recheck concurrent move'); expect([[100, 42]], DeleteStore::$calls, 'do not delete moved item');

        DeleteStore::reset(); DeleteStore::$afterMark = static function ($id) { DeleteStore::$deniedParents = [11]; };
        $r = runDelete([deleteItem(100), deleteItem(101)]);
        expect('ACCESS_DENIED', $r['error'], 'recheck concurrent revocation'); expect([[100, 42]], DeleteStore::$calls, 'do not delete after revocation');

        DeleteStore::reset(); DeleteStore::$csrf = false;
        $r = runDelete([deleteItem(100)]);
        expect('BAD_SESSID', $r['error'], 'csrf retained'); expect([], DeleteStore::$calls, 'csrf has no mutations');
        DeleteStore::reset(); DeleteStore::$user = 0;
        $r = runDelete([deleteItem(100)]);
        expect(401, $r['status'], 'authentication retained'); expect([], DeleteStore::$calls, 'no anonymous deletion');
        DeleteStore::reset();
        $r = runDelete([deleteItem(100)], ['pageId' => 7]);
        expect('BLOCK_CONTEXT_MISMATCH', $r['error'], 'block context retained'); expect([], DeleteStore::$calls, 'context mismatch no deletion');

        foreach ([[], [['entityType' => 'file', 'id' => -1]], [['entityType' => 'file', 'id' => '1e2']], [['entityType' => 'file', 'id' => []]], [['entityType' => [], 'id' => 100]], ['bad-item']] as $items) {
            DeleteStore::reset(); $r = runDelete($items);
            expect(422, $r['status'], 'malformed request rejected'); expect([], DeleteStore::$calls, 'no mutation for malformed request');
        }
        DeleteStore::reset(); $r = runDelete([deleteItem(700)]);
        expect(404, $r['status'], 'missing file');
        DeleteStore::reset(); DeleteStore::$root = 0; $r = runDelete([deleteItem(100)]);
        expect('root', $r['details']['stage'], 'unresolved root tracked'); expect([], DeleteStore::$calls, 'root failure no mutation');

        DeleteStore::reset(); DeleteStore::$diagnosticsFail = true; DeleteStore::$objects[100]->mode = 'throw';
        $r = runDelete([deleteItem(100)]);
        expect('DISK_DELETE_FAILED', $r['error'], 'diagnostic failure does not mask original');
        expect(true, (bool)$r['details']['diagnosticId'], 'correlation id still present');

        DeleteStore::reset(); DeleteStore::$objects[100]->mode = 'output';
        ob_start(); $r = runDelete([deleteItem(100)]); $noise = ob_get_clean();
        expect(true, $r['ok'], 'third party output cannot corrupt response'); expect('', $noise, 'delete output buffered');

        DeleteStore::reset(); DeleteStore::$admin = true; DeleteStore::$objects[100]->mode = 'bad_utf8';
        $r = runDelete([deleteItem(100)]); expect(false, $r['ok'], 'invalid UTF-8 still returns JSON');
        DeleteStore::reset(); DeleteStore::$admin = true; DeleteStore::$objects[100]->mode = 'long_error';
        $r = runDelete([deleteItem(100)]); expect(false, $r['ok'], 'multibyte diagnostic truncation still returns JSON');

        $logged = file_get_contents($log);
        expect(true, str_contains($logged, 'SQL internal /private/server secret'), 'technical cause retained in server log');
        expect(true, str_contains($logged, 'bitrixGroupIds'), 'groups retained in server log');
        echo 'Disk delete action: ' . $checks . " checks passed\n";
    } finally { ini_set('error_log', $oldLog); @unlink($log); }
}
