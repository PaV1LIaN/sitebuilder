<?php
/**
 * Regression coverage for the Bitrix Disk ACL administrator bypass.
 * In-memory dependencies; never touches a live Bitrix installation or DB.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../components/disk/lib/DiskContext.php';

class DiskCurrentUser
{
    public static bool $admin = false;
    public static function isAdmin(): bool
    {
        return self::$admin;
    }
}
class SiteAccessRepository
{
    public static ?string $role = null;
    public static function getUserRole(int $siteId, int $userId): ?string
    {
        return self::$role;
    }
}
class PageAccessService
{
    public static bool $edit = false;
    public static bool $view = false;

    public static function canEditDisk(int $siteId, int $pageId, int $userId): bool
    {
        return self::$edit;
    }
    public static function canViewDisk(int $siteId, int $pageId, int $userId): bool
    {
        return self::$view;
    }
}
class BitrixDiskRightsService
{
    public static int $calls = 0;
    public static bool $allowRead = false;
    public static bool $allowWrite = false;

    public static function resolvePermissions(
        DiskContext $context,
        int $folderId,
        array $base
    ): array {
        ++self::$calls;
        return array_merge($base, [
            'role' => self::$allowRead ? 'bitrix_disk_read' : 'bitrix_disk_none',
            'accessSource' => 'bitrix_disk_acl',
            'canView' => self::$allowRead,
            'canDownload' => self::$allowRead,
            'canUpload' => self::$allowWrite,
            'canCreateFolder' => self::$allowWrite,
            'canRename' => self::$allowWrite,
            'canDelete' => self::$allowWrite,
            'effectiveTaskName' => self::$allowRead ? 'disk_access_read' : 'none',
        ]);
    }
}
class FolderAccessRepository
{
    public const ROLE_EDITOR = 'EDITOR';
    public const ROLE_VIEWER = 'VIEWER';
    public static ?array $rule = null;
    public static function resolveEffectiveRole(
        int $blockId,
        int $folderId,
        int $rootFolderId,
        int $userId
    ): ?array {
        return self::$rule;
    }
}
require __DIR__ . '/../components/disk/lib/DiskPermissionService.php';

$checks = 0;
function checkSame($expected, $actual, string $label): void
{
    global $checks;
    ++$checks;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . ' Expected ' . var_export($expected, true)
            . ', received ' . var_export($actual, true)
        );
    }
}
function resetFakeRights(): void
{
    DiskCurrentUser::$admin = false;
    SiteAccessRepository::$role = null;
    PageAccessService::$edit = false;
    PageAccessService::$view = false;
    FolderAccessRepository::$rule = null;
    BitrixDiskRightsService::$allowRead = false;
    BitrixDiskRightsService::$allowWrite = false;
    BitrixDiskRightsService::$calls = 0;
}
$context = new DiskContext(13, 31, 80, 99);
$settings = [
    'permissionMode' => 'bitrix_disk',
    'allowUpload' => true,
    'allowCreateFolder' => true,
    'allowRename' => true,
    'allowDelete' => true,
    'allowDownload' => true,
];

// The original regression: Bitrix portal admin must not lose site-wide access
// just because Bitrix DiskSecurityContext denies a physical folder.
resetFakeRights();
DiskCurrentUser::$admin = true;
foreach ([1001, 1002] as $folderId) {
    $resolved = DiskPermissionService::resolve($context, $settings, $folderId, 1001);
    checkSame(true, $resolved['canView'], 'Bitrix admin can view folder ' . $folderId);
    checkSame(true, $resolved['canDelete'], 'Bitrix admin can delete in folder ' . $folderId);
    checkSame(true, $resolved['canManageAccess'], 'Bitrix admin can manage ACL');
    checkSame('bitrix_admin', $resolved['role'], 'Bitrix admin role is retained');
    checkSame('bitrix_admin', $resolved['accessSource'], 'Bitrix admin source is retained');
}
checkSame(0, BitrixDiskRightsService::$calls, 'Disk ACL must not overwrite a portal admin');

// Custom global SiteBuilder admins (admin_user_ids) must retain their role too.
resetFakeRights();
SiteAccessRepository::$role = 'site_admin';
$resolved = DiskPermissionService::resolve($context, $settings, 1001, 1001);
checkSame(true, $resolved['canView'], 'SiteBuilder admin can view');
checkSame(true, $resolved['canManageAccess'], 'SiteBuilder admin can manage ACL');
checkSame('site_admin', $resolved['role'], 'SiteBuilder admin role retained');
checkSame('global_site_role', $resolved['accessSource'], 'SiteBuilder admin source retained');
checkSame(0, BitrixDiskRightsService::$calls, 'Disk ACL must not overwrite SiteBuilder admin');

// Ordinary users are still subject to Bitrix Disk ACL including explicit deny.
resetFakeRights();
PageAccessService::$edit = true;
$resolved = DiskPermissionService::resolve($context, $settings, 1001, 1001);
checkSame(false, $resolved['canView'], 'ACL deny blocks ordinary editor');
checkSame(false, $resolved['canDelete'], 'ACL deny blocks ordinary editor deletion');
checkSame(false, $resolved['canManageAccess'], 'Ordinary editor cannot manage rights');
checkSame('bitrix_disk_acl', $resolved['accessSource'], 'ACL is source for ordinary editor');
checkSame(1, BitrixDiskRightsService::$calls, 'ACL consulted for ordinary editor');

resetFakeRights();
PageAccessService::$edit = true;
BitrixDiskRightsService::$allowRead = true;
$resolved = DiskPermissionService::resolve($context, $settings, 1001, 1001);
checkSame(true, $resolved['canView'], 'ACL grants ordinary editor read');
checkSame(false, $resolved['canDelete'], 'Read ACL does not grant delete');
checkSame(false, $resolved['canManageAccess'], 'Read ACL does not grant management');
checkSame(1, BitrixDiskRightsService::$calls, 'ACL consulted for a read-only user');

// No accidental fallback that would expose explicitly denied folders.
resetFakeRights();
PageAccessService::$view = true;
$resolved = DiskPermissionService::resolve($context, $settings, 1001, 1001);
checkSame(false, $resolved['canView'], 'Read-only site role cannot bypass a Disk ACL denial');

resetFakeRights();
PageAccessService::$view = true;
$inherit = array_merge($settings, ['permissionMode' => 'inherit_site']);
$resolved = DiskPermissionService::resolve($context, $inherit, 1001, 1001);
checkSame(true, $resolved['canView'], 'Inherited site view still works');
checkSame(0, BitrixDiskRightsService::$calls, 'Inherited mode skips Disk ACL');

// Block feature switches must remain effective even for Bitrix admins.
resetFakeRights();
DiskCurrentUser::$admin = true;
$restricted = array_merge($settings, ['allowDelete' => false]);
$resolved = DiskPermissionService::resolve($context, $restricted, 1001, 1001);
checkSame(true, $resolved['canView'], 'Block settings do not mask administrator view');
checkSame(false, $resolved['canDelete'], 'Block deletion setting still applies');

// The custom folder access DENY must still work for ordinary site users.
resetFakeRights();
PageAccessService::$view = true;
FolderAccessRepository::$rule = ['role' => 'DENY', 'folderId' => 1001, 'inherited' => false];
$custom = array_merge($settings, ['permissionMode' => 'custom']);
$resolved = DiskPermissionService::resolve($context, $custom, 1001, 1001);
checkSame(false, $resolved['canView'], 'Custom DENY still applies to normal users');

// ADMIN must never be overwritten by a custom folder DENY.
resetFakeRights();
DiskCurrentUser::$admin = true;
FolderAccessRepository::$rule = ['role' => 'DENY', 'folderId' => 1001, 'inherited' => false];
$resolved = DiskPermissionService::resolve($context, $custom, 1001, 1001);
checkSame(true, $resolved['canView'], 'Portal admin keeps access in custom mode');

echo "Disk admin and ACL isolation checks passed ($checks checks)\n";
