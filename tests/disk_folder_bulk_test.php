<?php
// Production resolver, repository and endpoint; Bitrix identity/directory are fixtures.

namespace Bitrix\Main {
    class UserTable {
        public static array $users = [];
        public static array $queries = [];
        public static function getList(array $options) {
            self::$queries[] = $options;
            $matches = static function (array $row, array $filter) use (&$matches): bool {
                $checks = [];
                foreach ($filter as $key => $value) {
                    if ($key === 'LOGIC') continue;
                    if (is_int($key)) { $checks[] = $matches($row, $value); continue; }
                    $actual = $row[substr($key, 1)] ?? '';
                    $checks[] = $key[0] === '@' ? in_array($actual, $value)
                        : ($key[0] === '%' ? mb_stripos((string)$actual, (string)$value) !== false : $actual == $value);
                }
                return ($filter['LOGIC'] ?? 'AND') === 'OR' ? in_array(true, $checks, true) : !in_array(false, $checks, true);
            };
            $rows = array_values(array_filter(self::$users, static fn($row) => $matches($row, $options['filter'])));
            $rows = array_slice($rows, 0, $options['limit'] ?? count($rows));
            return new class($rows) {
                private array $rows;
                public function __construct($rows) { $this->rows = $rows; }
                public function fetch() { return array_shift($this->rows) ?: false; }
            };
        }
    }
}

namespace Bitrix\Disk {
    class Folder {
        public static function loadById($id) { return new self(); }
        public function getParentId() { return 20; }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    require __DIR__ . '/../components/disk/lib/DiskDb.php';
    require __DIR__ . '/../components/disk/lib/DiskContext.php';
    require __DIR__ . '/../components/disk/lib/FolderAccessRepository.php';
    require __DIR__ . '/../components/disk/lib/FolderAccessBulkService.php';
    require __DIR__ . '/../components/disk/lib/DiskPermissionService.php';
    require __DIR__ . '/../components/disk/lib/DiskValidator.php';
    function check($condition, $message) { if (!$condition) throw new \RuntimeException($message); }
    function rejects(callable $fn, string $message): void {
        try { $fn(); } catch (\Throwable $e) { check($e->getMessage() === $message, $e->getMessage() . ' !== ' . $message); return; }
        throw new \RuntimeException('Expected failure: ' . $message);
    }
    class DiskCurrentUser {
        public static bool $admin = true;
        public static function requireId() { return 1; }
        public static function isAdmin() { return self::$admin; }
        public static function isBitrixAdmin() { return self::$admin; }
    }
    class SiteAccessRepository { public static function getUserRole($siteId, $userId) { return 'site_viewer'; } }
    class PageAccessService {
        public static function canEditDisk(...$args) { return false; }
        public static function canViewDisk(...$args) { return true; }
    }
    class SiteRepository { public static function getById($id) { return $id === 1 ? ['id' => 1] : null; } }
    class BlockRepository {
        public static function getDiskBlockByContext($site, $page, $block) { return $page === 2 && $block === 5 ? ['id' => 5] : null; }
    }
    class DiskBitrixStorageAdapter {
        public function __construct($id) {}
        public function isFolderInsideRoot($context, $folder, $root) { return in_array($folder, [21, 22]); }
    }
    class DiskSettingsRepository {
        public static string $mode = 'custom';
        public static function getByBlockId($id) { return ['permissionMode' => self::$mode, 'allowDownload' => true]; }
    }
    class DiskRootResolver { public static function resolve(...$args) { return 20; } }
    class DiskCsrf {
        public static bool $valid = true;
        public static function validateFromRequest() { if (!self::$valid) throw new \RuntimeException('BAD_CSRF'); }
    }
    class TestResponse extends \RuntimeException { public array $data; public function __construct($data) { $this->data = $data; } }
    class DiskResponse { public static function success($data) { throw new TestResponse($data); } }
    function disk_read_json_body() { return $GLOBALS['testBody']; }
    function endpoint(string $action, array $payload): array {
        $GLOBALS['testBody'] = $payload + ['siteId' => 1, 'pageId' => 2, 'blockId' => 5, 'folderId' => 20];
        try { require __DIR__ . '/../components/disk/actions/folder_access_bulk.php'; }
        catch (TestResponse $result) { return $result->data; }
        throw new \RuntimeException('No response');
    }
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec("ATTACH DATABASE ':memory:' AS sitebuilder");
    $pdo->sqliteCreateFunction('NOW', static fn() => microtime(true));
    $pdo->sqliteCreateFunction('hashtextextended', static fn($key, $seed) => 1);
    $pdo->sqliteCreateFunction('pg_advisory_xact_lock', static fn($key) => 1);
    $pdo->exec('CREATE TABLE sitebuilder.disk_folder_access (id INTEGER PRIMARY KEY, site_id INT, block_id INT, folder_id INT,
        access_code TEXT, role TEXT CHECK(role IN (\'VIEWER\',\'EDITOR\',\'DENY\')), created_by INT, created_at TEXT,
        updated_by INT, updated_at TEXT, UNIQUE(block_id,folder_id,access_code))');
    DiskDb::setConnection($pdo);
    for ($id = 1; $id <= 205; $id++) {
        \Bitrix\Main\UserTable::$users[] = ['ID' => $id, 'ACTIVE' => 'Y', 'LOGIN' => 'user' . $id,
            'LAST_NAME' => 'Фамилия' . $id, 'NAME' => 'Иван', 'SECOND_NAME' => 'Иванович', 'EMAIL' => 'u' . $id . '@example.test'];
    }
    \Bitrix\Main\UserTable::$users[1]['LAST_NAME'] = 'Фамилия1'; // Full namesake, different login.
    \Bitrix\Main\UserTable::$users[204]['ACTIVE'] = 'N';
    $rows = endpoint('folderAccessResolveUsers', ['text' => "Фамилия1 Иван Иванович\nuser5\nID 7\nmissing\nuser205"] )['rows'];
    check($rows[0]['selectedId'] === null && count($rows[0]['candidates']) > 1, 'Namesake requires choice');
    check($rows[1]['selectedId'] === 5 && $rows[2]['selectedId'] === 7, 'Exact login and ID selected');
    check(!$rows[3]['candidates'] && !$rows[4]['candidates'], 'Missing and inactive excluded');
    check(FolderAccessBulkService::resolve('Фамилия100 Иван Иванович')[0]['selectedId'] === 100, 'Full FIO spans fields');
    check(FolderAccessBulkService::resolve('Фамилия100')[0]['selectedId'] === null, 'Partial name requires confirmation');
    check(FolderAccessBulkService::resolve('Иван')[0]['truncated'], 'Truncated matches never auto-select');
    rejects(static fn() => FolderAccessBulkService::parse(str_repeat("user1\n", 201)), 'INVALID_BULK_USERS');
    rejects(static fn() => FolderAccessBulkService::validateUserIds([true]), 'INVALID_BULK_USERS');
    FolderAccessRepository::setUserRole(1, 5, 20, 150, 'EDITOR', 1);
    $revision = FolderAccessRepository::revision(FolderAccessRepository::listForFolder(1, 5, 20));
    $request = ['userIds' => array_merge(range(1, 100), [1]), 'role' => 'DENY', 'expectedRevision' => $revision];
    $saved = endpoint('folderAccessBulkSet', $request);
    check($saved['count'] === 100, '100 unique users written');
    check(count(FolderAccessRepository::listForFolder(1,5,20)) === 101, 'Unselected rule preserved');
    rejects(static fn() => endpoint('folderAccessBulkSet', $request), 'FOLDER_ACCESS_VERSION_CONFLICT');
    $context = DiskContextFactory::fromArray(['siteId'=>1,'pageId'=>2,'blockId'=>5,'currentUserId'=>5]);
    DiskCurrentUser::$admin = false;
    $permissions = DiskPermissionService::resolve($context, DiskSettingsRepository::getByBlockId(5), 20, 20);
    check(!$permissions['canView'] && !$permissions['canDownload'], 'Bulk DENY is enforced');
    check(!DiskValidator::filterVisibleItems($context, DiskSettingsRepository::getByBlockId(5), [['id'=>21,'entityType'=>'folder']], 20, 20), 'Denied child hidden');
    rejects(static fn() => DiskValidator::assertCanForFolder($context, DiskSettingsRepository::getByBlockId(5), 21, 20, 'canView'), 'ACCESS_DENIED');
    rejects(static fn() => endpoint('folderAccessResolveUsers', ['text'=>'user1']), 'ACCESS_DENIED');
    DiskCurrentUser::$admin = true;
    check(DiskPermissionService::resolve($context, DiskSettingsRepository::getByBlockId(5), 20, 20)['canView'], 'Admin recovery access retained');
    DiskCsrf::$valid = false;
    rejects(static fn() => endpoint('folderAccessBulkSet', $request), 'BAD_CSRF');
    DiskCsrf::$valid = true;
    rejects(static fn() => endpoint('folderAccessBulkSet', ['folderId'=>999] + $request), 'FOLDER_OUT_OF_SCOPE');
    rejects(static fn() => endpoint('folderAccessBulkSet', ['pageId'=>99] + $request), 'BLOCK_CONTEXT_MISMATCH');
    foreach (['inherit_site','bitrix_disk'] as $mode) {
        DiskSettingsRepository::$mode = $mode;
        rejects(static fn() => endpoint('folderAccessBulkSet', $request), 'FOLDER_ACCESS_MODE_REQUIRED');
    }
    DiskSettingsRepository::$mode = 'custom';
    rejects(static fn() => endpoint('folderAccessBulkSet', ['userIds'=>[5,205]] + $request), 'BULK_USER_NOT_ACTIVE');
    // Inject a failure halfway through the batch and prove preceding writes roll back.
    $pdo->exec("CREATE TRIGGER sitebuilder.fail_batch BEFORE INSERT ON disk_folder_access WHEN NEW.access_code = 'U104' BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
    try {
        endpoint('folderAccessBulkSet', ['userIds'=>range(101,110), 'expectedRevision'=>$saved['revision']] + $request);
        throw new \RuntimeException('Expected database failure');
    } catch (\PDOException $e) {}
    check(count(FolderAccessRepository::listForFolder(1,5,20)) === 101, 'Atomic rollback');
    $pdo->exec('DROP TRIGGER sitebuilder.fail_batch');
    $inherited = endpoint('folderAccessBulkSet', ['userIds'=>range(1,100), 'role'=>'INHERIT', 'expectedRevision'=>$saved['revision']]);
    check(count(FolderAccessRepository::listForFolder(1,5,20)) === 1, 'Bulk inheritance removes only selected rules');
    DiskCurrentUser::$admin = false;
    check(DiskPermissionService::resolve($context, DiskSettingsRepository::getByBlockId(5), 20, 20)['canView'], 'Rule cache invalidated after mutation');
    echo "PASS: 100 users, FIO and namesakes, duplicates, inactive/invalid targets, CSRF, mode guard, revisions, rollback, inheritance, deny visibility and direct access.\n";
}
