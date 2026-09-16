<?php

// php tests/admin_access_test.php [populated|missing|invalid]
// PHP 8 with pdo_sqlite, mbstring and ctype. Real authorization/services/handlers;
// isolated SQLite storage and Bitrix identity fixtures. No live portal is used.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
$checks = 0;
function check(bool $condition, string $message): void {
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException('Assertion failed: ' . $message);
}
class AdminTestResponse extends RuntimeException {
    public function __construct(public array $data, public int $status) { parent::__construct($data['error'] ?? 'OK'); }
}
function sb_json_response(array $data, int $status = 200): void { throw new AdminTestResponse($data, $status); }
function response(callable $callback, string $error, int $status): void {
    try { $callback(); } catch (AdminTestResponse $result) {
        check(($result->data['error'] ?? '') === $error && $result->status === $status,
            "expected $status $error, got {$result->status} {$result->getMessage()}");
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $error);
}
class AdminTestRows {
    public function __construct(private array $rows) {}
    public function Fetch() { return array_shift($this->rows) ?: false; }
}
class CUser {
    public function __construct(private int $id, private bool $authorized = true, private bool $nativeAdmin = false) {}
    public function GetID(): int { return $this->id; }
    public function IsAuthorized(): bool { return $this->authorized; }
    public function IsAdmin(): bool { return $this->nativeAdmin; }
    public function GetUserGroupArray(): array { return self::GetUserGroup($this->id); }
    public static function GetUserGroup(int $id): array { return $id === 1 ? [1, 2] : [2]; }
    public static function GetList(&$by, &$order, $filter, $options) {
        return new AdminTestRows(($filter['GROUPS_ID'] ?? 0) === 1 ? [['ID' => 1]] : []);
    }
}
class AdminTestUserTable {
    public static function getList(array $query): AdminTestRows {
        $rows = [];
        foreach ($query['filter']['@ID'] as $id) {
            $rows[] = ['ID' => $id, 'ACTIVE' => $id === 789 ? 'N' : 'Y', 'LOGIN' => 'user' . $id];
        }
        return new AdminTestRows($rows);
    }
}
class_alias(AdminTestUserTable::class, 'Bitrix\\Main\\UserTable');
class CJSCore { public static function Init(array $modules): void {} }
class CUtil { public static function JSEscape(string $value): string { return addslashes($value); } }
function bitrix_sessid(): string { return 'admin-test-session'; }
function bitrix_sessid_post(): string { return '<input type="hidden" name="sessid" value="admin-test-session">'; }
$APPLICATION = new class { public function ShowHead(): void {} };

$source = dirname(__DIR__);
$scenario = $argv[1] ?? 'populated';
$config = ['guest_user_id' => 665];
if ($scenario === 'populated') {
    $config['admin_user_ids'] = [123, '456', 123, 789, 665, 0, -1, true, 1.9, '7abc', '1e3', [], null, str_repeat('9', 40)];
} elseif ($scenario === 'invalid') {
    $config['admin_user_ids'] = '123';
} elseif ($scenario !== 'missing') {
    throw new RuntimeException('Unknown scenario');
}
$root = sys_get_temp_dir() . '/sb-admin-' . bin2hex(random_bytes(6));
$app = $root . '/local/sitebuilder';
mkdir($app . '/config', 0777, true);
mkdir($root . '/local/php_interface/lib', 0777, true);
mkdir($root . '/bitrix/modules/main/include', 0777, true);
file_put_contents($app . '/config/auth.php', '<?php return ' . var_export($config, true) . ';');
file_put_contents($root . '/local/php_interface/lib/pg_master.php', '<?php function getPDO(): PDO { return $GLOBALS["adminTestDb"]; }');
file_put_contents($root . '/bitrix/modules/main/include/prolog_before.php', '<?php');
foreach (['lib', 'api', 'components'] as $directory) symlink($source . '/' . $directory, $app . '/' . $directory);
register_shutdown_function(static function () use ($root): void {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) unlink($entry->getPathname());
        else rmdir($entry->getPathname());
    }
    rmdir($root);
});
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['REQUEST_URI'] = '/local/sitebuilder/index.php';
require $source . '/lib/auth.php';

$USER = null;
check(!sitebuilder_is_admin(), 'missing session is not admin');
$USER = new CUser(123, false, true);
check(!sitebuilder_is_admin(), 'unauthorized session cannot use stale identity or admin flag');
$USER = new CUser(0, true, true);
check(!sitebuilder_is_admin(), 'zero ID is not admin');
$USER = new CUser(7);
$_GET['admin_user_ids'] = $_POST['admin_user_ids'] = [7];
check(!sitebuilder_is_admin(), 'request data cannot configure admin access');
$USER = new CUser(1, true, true);
check(sitebuilder_is_admin(), 'native Bitrix admin retains access');
check(!sitebuilder_is_admin(7), 'current admin privilege is not applied to another user');
$USER = new CUser(665, true, true);
check(!sitebuilder_is_admin(), 'technical guest never uses admin bypass');

if ($scenario !== 'populated') {
    check(sitebuilder_admin_user_ids() === [], 'missing or malformed setting gives no extra privileges');
    $USER = new CUser(123);
    check(!sitebuilder_is_admin(), 'old config does not create administrators');
    echo "OK $scenario: $checks checks\n";
    exit;
}
check(sitebuilder_admin_user_ids() === [123, 456, 789], 'only distinct positive IDs remain; guest and malformed values are excluded');
$USER = new CUser(123);
check(sitebuilder_is_admin(), 'listed user is admin without a native admin flag');
check(!$USER->IsAdmin() && CUser::GetUserGroup(123) === [2], 'Bitrix roles are not changed');
check(sitebuilder_is_admin(456), 'explicit listed user is recognized');
check(!sitebuilder_is_admin(665) && !sitebuilder_is_admin(-1), 'guest and invalid explicit IDs are rejected');

$adminTestDb = new PDO('sqlite::memory:');
$adminTestDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$adminTestDb->exec("ATTACH DATABASE ':memory:' AS sitebuilder;
CREATE TABLE sitebuilder.site (id INTEGER PRIMARY KEY, bitrix_group_id INTEGER);
CREATE TABLE sitebuilder.page (id INTEGER PRIMARY KEY, site_id INTEGER, parent_id INTEGER);
CREATE TABLE sitebuilder.access (id INTEGER PRIMARY KEY, site_id INTEGER, access_code TEXT, role TEXT,
 created_by INTEGER, created_at TEXT, updated_by INTEGER, updated_at TEXT);
CREATE TABLE sitebuilder.page_access (site_id INTEGER, page_id INTEGER, access_code TEXT,
 can_view INTEGER, can_edit INTEGER, can_disk_view INTEGER, can_disk_edit INTEGER, include_children INTEGER);
INSERT INTO sitebuilder.site VALUES (1, 0), (2, 0);
INSERT INTO sitebuilder.page VALUES (10, 1, 0), (20, 2, 0);
INSERT INTO sitebuilder.access (site_id, access_code, role) VALUES (1, 'U8', 'VIEWER'), (1, 'U9', 'ADMIN'), (1, 'U665', 'VIEWER');");
require $source . '/lib/access.php';
require $source . '/lib/helpers.php';
require $source . '/lib/PageAccessService.php';
foreach (['DiskDb', 'DiskContext', 'DiskCurrentUser', 'SiteAccessRepository', 'DiskPermissionService', 'DiskPageUserRepository'] as $name) {
    require $source . '/components/disk/lib/' . $name . '.php';
}
DiskDb::setConnection($adminTestDb);

foreach ([123, 456, 1] as $id) {
    $USER = new CUser($id, true, $id === 1);
    sb_require_sitebuilder_admin();
    sb_require_owner(1);
    check(PageAccessService::canViewPage(1, 10, $id), 'admin can view pages');
    check(PageAccessService::canEditPage(1, 10, $id), 'admin can edit without ACL rows');
    check(PageAccessService::canEditDisk(1, 10, $id), 'admin can edit disk');
    check(PageAccessService::hasGlobalSiteAccess(2, $id, 'owner'), 'admin can manage another site');
    check(!PageAccessService::canEditPage(1, 10, 7), 'checking another user does not inherit admin access');
    check(DiskCurrentUser::isAdmin(), 'disk component uses the same admin policy');
    $context = new DiskContext(1, 10, 100, $id);
    foreach (['inherit_site', 'custom', 'bitrix_disk'] as $mode) {
        $permissions = DiskPermissionService::resolve($context, ['permissionMode' => $mode,
            'allowUpload' => true, 'allowCreateFolder' => true, 'allowRename' => true,
            'allowDelete' => true, 'allowDownload' => true], 1000, 1000);
        check($permissions['canManageAccess'] && $permissions['canUpload'] && $permissions['canDelete'], 'admin retains disk permissions in ' . $mode);
        check($permissions['role'] === ($id === 1 ? 'bitrix_admin' : 'site_admin'), 'configured admin is not mislabelled as a portal admin');
    }
}
foreach ([7, 8, 665] as $id) {
    $USER = new CUser($id);
    response(fn() => sb_require_sitebuilder_admin(), 'SITEBUILDER_ADMIN_REQUIRED', 403);
    response(fn() => sb_require_owner(1), 'ACCESS_DENIED', 403);
    check(!PageAccessService::canEditPage(1, 10, $id), 'ordinary user cannot edit');
    check(!DiskCurrentUser::isAdmin(), 'ordinary user is not a disk admin');
}
$USER = new CUser(8);
check(PageAccessService::canViewPage(1, 10, 8), 'existing viewer role still works');
$USER = new CUser(9);
sb_require_admin(1);
check(PageAccessService::canEditPage(1, 10, 9), 'existing site admin role still works');
check(!PageAccessService::hasGlobalSiteAccess(2, 9, 'admin'), 'site role does not turn into global administrator');
$USER = new CUser(123);
check(!sb_is_bitrix_admin(), 'portal-only helper is not widened');
$rows = DiskPageUserRepository::listUsersWithPageAccess(1, 10, true);
$byId = array_column($rows, null, 'userId');
check(isset($byId[123], $byId[456]), 'configured admins appear in disk ACL reconciliation without site rows');
check(!isset($byId[789]), 'inactive listed accounts are excluded from reconciliation');
check($byId[123]['globalRole'] === 'OWNER' && !$byId[123]['isBitrixAdmin'], 'reconciliation has full site access without portal admin flag');
check(!$byId[8]['pageAccess']['canEdit'] && !$byId[665]['pageAccess']['canDiskEdit'], 'matrix preserves viewer and guest permissions');

// The real handlers must enforce the same policy as the UI. Empty payloads stop
// immediately after authorization, so no real create/delete operation is run.
function handler(string $file, string $action): void {
    $_POST = [];
    require dirname(__DIR__) . '/api/handlers/' . $file . '.php';
}
foreach ([123, 1, 7, 665] as $id) {
    $USER = new CUser($id, true, $id === 1);
    $allowed = in_array($id, [123, 1], true);
    response(fn() => handler('site', 'site.create'), $allowed ? 'NAME_REQUIRED' : 'SITEBUILDER_ADMIN_REQUIRED', $allowed ? 422 : 403);
    response(fn() => handler('template', 'template.createFromSite'), $allowed ? 'SITE_ID_REQUIRED' : 'SITEBUILDER_ADMIN_REQUIRED', $allowed ? 422 : 403);
    ob_start();
    require $source . '/index.php';
    $html = ob_get_clean();
    check(str_contains($html, 'id="createSiteQuickBtn"') === $allowed, 'dashboard creation button matches server permissions');
    check(str_contains($html, '/deployment.php') === ($id === 1), 'deployment link remains limited to native portal admins');
}
$USER = new CUser(123);
response(fn() => handler('section', 'section.create'), 'NAME_REQUIRED', 422);
$USER = new CUser(7);
response(fn() => sb_section_require_admin(), 'SITEBUILDER_ADMIN_REQUIRED', 403);
try { handler('page_access', '__load_functions__'); } catch (AdminTestResponse $result) {}
$USER = new CUser(123);
check(sb_page_access_can_manage(1, 10, 123), 'configured admin manages page access');
check(!sb_page_access_can_manage(1, 20, 123), 'page ownership validation is still enforced');
$USER = new CUser(8);
check(!sb_page_access_can_manage(1, 10, 8), 'viewer cannot manage page access');
check((int)$adminTestDb->query('SELECT COUNT(*) FROM sitebuilder.access')->fetchColumn() === 3, 'admin checks do not write site roles');

echo "OK $scenario: $checks checks\n";
