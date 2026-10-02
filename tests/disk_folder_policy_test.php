<?php
// Real permission policy, folder rule repository, validators and read endpoints.
// Only the portal objects/identity and block settings are fixtures.
namespace Bitrix\Disk {
    class Folder {
        public static array $parents = [1 => 0, 20 => 1, 21 => 20, 22 => 20, 23 => 21, 24 => 21, 25 => 22, 99 => 1];
        public function __construct(private int $id) {}
        public static function loadById($id) { return isset(self::$parents[$id]) ? new self($id) : null; }
        public function getId() { return $this->id; }
        public function getParentId() { return self::$parents[$this->id]; }
        public function isDeleted() { return false; }
        public function getChildren($security) {
            $result = [];
            foreach (self::$parents as $id => $parent) if ($parent === $this->id) $result[] = new self($id);
            return $result;
        }
    }
    class File {
        public static array $parents = [1000 => 20, 1100 => 21, 1200 => 22, 2300 => 23];
        public function __construct(private int $id) {}
        public static function loadById($id) { return isset(self::$parents[$id]) ? new self($id) : null; }
        public function getParentId() { return self::$parents[$this->id]; }
    }
    class Driver {
        public static function getInstance() { return new self(); }
        public function getFakeSecurityContext($id) { return $id; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    foreach (['DiskDb', 'DiskContext', 'DiskSitebuilderBridge', 'FolderAccessRepository', 'DiskPermissionService', 'DiskValidator'] as $name) {
        require_once __DIR__ . '/../components/disk/lib/' . $name . '.php';
    }
    $checks = 0;
    function check($ok, $message) { $GLOBALS['checks']++; if (!$ok) throw new \RuntimeException($message); }
    function denied(callable $fn, $code = 'ACCESS_DENIED') {
        try { $fn(); } catch (\RuntimeException $e) { check($e->getMessage() === $code, $e->getMessage() . ' !== ' . $code); return; }
        throw new \RuntimeException('Expected ' . $code);
    }
    class DiskCurrentUser {
        public static bool $admin = false;
        public static function requireId() { return 5; }
        public static function isAdmin() { return self::$admin; }
        public static function isBitrixAdmin() { return self::$admin; }
    }
    class SiteAccessRepository { public static function getUserRole(...$args) { return null; } }
    class PageAccessService {
        public static bool $allowed = true;
        public static function canViewDisk(...$args) { return self::$allowed; }
        public static function canEditDisk(...$args) { return self::$allowed; }
        public static function canViewPage(...$args) { return self::$allowed; }
    }
    class SiteRepository { public static function getById($id) { return $id === 1 ? ['id' => 1] : null; } }
    class BlockRepository {
        public static function getDiskBlockByContext($site, $page, $block) { return $site === 1 && $page === 2 && $block === 5 ? ['id' => 5] : null; }
    }
    class DiskCsrf { public static function validateFromRequest() {} }
    class DiskSettingsRepository {
        public static array $raw = ['requireFolderAccess' => 1, 'allowDelete' => true];
        public static function getByBlockId($id) { return DiskSitebuilderBridge::normalizeDiskProps(self::$raw); }
        public static function ensureExistsForBlock(...$args) { return self::getByBlockId(5); }
    }
    class DiskRootResolver { public static function resolve(...$args) { return 20; } }
    class DiskTitleSyncService { public static function folderName(...$args) { return 'Root'; } }
    class BitrixDiskRightsService {
        public static int $calls = 0;
        public static function resolvePermissions($context, $folder, $base) {
            self::$calls++;
            return array_merge($base, ['canView' => false, 'canDownload' => false]);
        }
    }
    class DiskBitrixStorageAdapter {
        public function __construct($id) {}
        public function isFolderInsideRoot($context, $folder, $root) {
            while ($folder > 0) {
                if ($folder === $root) return true;
                $folder = \Bitrix\Disk\Folder::$parents[$folder] ?? 0;
            }
            return false;
        }
        public function getBreadcrumbs($context, $folder) {
            $result = [];
            while ($folder > 0) { array_unshift($result, ['id' => $folder, 'name' => 'Folder ' . $folder]); $folder = \Bitrix\Disk\Folder::$parents[$folder] ?? 0; }
            return $result;
        }
        public function listItems($context, $folder, $options) {
            return array_values(array_filter($this->search($context, 20, '', []), static fn($item) => $item['parentId'] === $folder));
        }
        public function search(...$args) {
            $result = [];
            foreach (['folder' => \Bitrix\Disk\Folder::$parents, 'file' => \Bitrix\Disk\File::$parents] as $type => $rows) {
                foreach ($rows as $id => $parent) if ($id !== 20 && $id !== 1) $result[] = ['id' => $id, 'entityType' => $type, 'name' => $type . $id, 'parentId' => $parent];
            }
            return $result;
        }
    }
    class Response extends \Exception { public function __construct(public array $data) {} }
    class DiskResponse { public static function success($data, $meta = []) { throw new Response($data); } }
    function disk_read_json_body() { return $GLOBALS['payload']; }
    function endpoint($action, $data = []) {
        $GLOBALS['payload'] = $data + ['siteId' => 1, 'pageId' => 2, 'blockId' => 5];
        try { require __DIR__ . '/../components/disk/actions/' . $action . '.php'; }
        catch (Response $result) { return $result->data; }
        throw new \RuntimeException('Missing response');
    }
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec("ATTACH DATABASE ':memory:' AS sitebuilder");
    $pdo->sqliteCreateFunction('NOW', static fn() => microtime(true));
    $pdo->sqliteCreateFunction('hashtextextended', static fn($key, $seed) => 1);
    $pdo->sqliteCreateFunction('pg_advisory_xact_lock', static fn($key) => 1);
    $pdo->exec('CREATE TABLE sitebuilder.disk_folder_access (id INTEGER PRIMARY KEY, site_id INT, block_id INT, folder_id INT,
        access_code TEXT, role TEXT, created_by INT, created_at TEXT, updated_by INT, updated_at TEXT, UNIQUE(block_id,folder_id,access_code))');
    DiskDb::setConnection($pdo);
    $context = new DiskContext(1, 2, 5, 5);
    $settings = DiskSettingsRepository::getByBlockId(5);
    $resolve = static fn($folder, $config = null) => DiskPermissionService::resolve($context, $config ?? DiskSettingsRepository::getByBlockId(5), $folder, 20);
    check(DiskSitebuilderBridge::normalizeDiskProps([])['requireFolderAccess'] === false, 'New disks default to flag 0');
    foreach (['custom', 'bitrix_disk'] as $mode) {
        check(DiskSitebuilderBridge::normalizeDiskProps(['permissionMode' => $mode])['requireFolderAccess'], 'Existing ACL mode stays enabled');
    }
    check($settings['requireFolderAccess'] && $settings['permissionMode'] === 'custom', 'Flag 1 activates folder rules');
    check($resolve(20)['canBrowse'] && !$resolve(20)['canView'] && !$resolve(20)['canUpload'], 'Root is navigation only without a grant');
    check(endpoint('list')['items'] === [], 'No rules means empty root, including root files');
    denied(static fn() => endpoint('list', ['currentFolderId' => 22]));
    denied(static fn() => endpoint('download', ['fileId' => 1000]));
    FolderAccessRepository::setUserRole(1, 5, 21, 5, 'EDITOR', 1);
    FolderAccessRepository::setUserRole(1, 5, 23, 5, 'DENY', 1);
    FolderAccessRepository::setUserRole(1, 5, 25, 5, 'VIEWER', 1);
    check(array_column(endpoint('list')['items'], 'id') === [21], 'Only granted folders are listed at root');
    check(array_column(endpoint('list', ['currentFolderId' => 21])['items'], 'id') === [24, 1100], 'Inherited folders and files visible; DENY child hidden');
    check($resolve(24)['folderRuleInherited'] && $resolve(24)['canUpload'], 'Parent grant inherited');
    check(!$resolve(23)['canView'], 'Child DENY overrides parent');
    denied(static fn() => endpoint('download', ['fileId' => 2300]));
    denied(static fn() => endpoint('download', ['fileId' => 1200]));
    $search = array_column(endpoint('search', ['query' => 'folder'])['items'], 'id');
    check($search === [21, 24, 25, 1100], 'Search filters each result, including files and outside-root objects');
    $linked = endpoint('list', ['currentFolderId' => 25]);
    check(array_column($linked['breadcrumbs'], 'id') === [20, 25], 'Direct granted child does not expose denied ancestor names');
    check($resolve(25)['canView'] && !$resolve(25)['canUpload'], 'Viewer stays read only');
    denied(static fn() => endpoint('list', ['currentFolderId' => 99]), 'FOLDER_OUT_OF_SCOPE');
    denied(static fn() => endpoint('list', ['blockId' => 99]), 'BLOCK_CONTEXT_MISMATCH');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    denied(static fn() => endpoint('get_internal_link', ['entityType' => 'file', 'entityId' => 1200]));
    // Parent access cannot copy, move, rename or delete a forbidden subtree.
    FolderAccessRepository::setUserRole(1, 5, 20, 5, 'EDITOR', 1);
    foreach (['canView', 'canRename', 'canDelete'] as $key) {
        denied(static fn() => DiskValidator::assertCanForItemParent($context, $settings, 'folder', 21, 20, $key));
    }
    check($resolve(22)['canView'], 'Explicit root grant inherits to previously unassigned folder');
    foreach ([0, '0', false] as $flag) {
        DiskSettingsRepository::$raw = ['requireFolderAccess' => $flag, 'permissionMode' => 'custom', 'allowDelete' => true];
        check(array_column(endpoint('list')['items'], 'id') === [21, 22, 1000], 'Flag 0 exposes full structure to page members');
        check($resolve(23)['canView'], 'Saved DENY is inactive when flag is 0');
    }
    check(count(FolderAccessRepository::listForFolder(1, 5, 23)) === 1, 'Disabling preserves saved rules');
    DiskSettingsRepository::$raw['requireFolderAccess'] = true;
    check(!$resolve(23)['canView'], 'Re-enabling reapplies saved rules');
    PageAccessService::$allowed = false;
    foreach ([0, 1] as $flag) {
        foreach (['inherit_site', 'custom', 'bitrix_disk'] as $mode) {
            $p = $resolve(21, ['requireFolderAccess' => $flag, 'permissionMode' => $mode]);
            check(!$p['canBrowse'] && !$p['canView'] && !$p['canUpload'], 'Folder/native grant cannot replace page Disk admission');
        }
    }
    check(BitrixDiskRightsService::$calls === 0, 'No native resolution without page admission');
    PageAccessService::$allowed = true;
    check(!$resolve(21, ['requireFolderAccess' => 1, 'permissionMode' => 'bitrix_disk'])['canView'], 'Native mode retains native restriction');
    check($resolve(21, ['requireFolderAccess' => 0, 'permissionMode' => 'bitrix_disk'])['canView'], 'Flag 0 selects page inheritance even from native mode');
    DiskCurrentUser::$admin = true;
    check($resolve(23)['canManageAccess'] && $resolve(23)['canView'], 'Admin can recover access');
    echo 'PASS: ' . $checks . " folder policy checks (flags, inheritance, list/search, direct links/download, subtrees, page admission, native ACL and admin).\n";
}
