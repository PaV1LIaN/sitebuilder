<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/AccessUserIdentity.php';
class CUser {
    public static array $groups = [12 => [2, 7], 13 => [1, 2], 99 => [1, 2]];
    public static function GetUserGroup(int $id): array { return self::$groups[$id] ?? []; }
    public function GetID(): int { return 99; }
    public function IsAdmin(): bool { return true; }
    public function IsAuthorized(): bool { return true; }
    public function GetUserGroupArray(): array { return [1, 2]; }
}
$USER = new CUser();
function sitebuilder_auth_config(): array { return ['guest_user_id' => 665]; }
function sitebuilder_is_site_admin_user_id(int $id): bool { return $id === 40; }
$checks = 0;
function eq($expected, $actual, string $label): void {
    global $checks; ++$checks;
    if ($expected !== $actual) throw new RuntimeException($label . ': ' . var_export($actual, true));
}
eq(false, AccessUserIdentity::isPortalAdmin(12), 'Viewer admin does not elevate subject');
eq(true, AccessUserIdentity::isPortalAdmin(13), 'Other real portal admin recognized');
eq(true, AccessUserIdentity::isPortalAdmin(99), 'Current portal admin preserved');
eq([2,7], AccessUserIdentity::groups(12), 'Subject groups not actor groups');
eq(true, AccessUserIdentity::canOpenAdmin(), 'Portal administrator can open console');
require __DIR__ . '/../components/disk/lib/DiskContext.php';
class DiskCurrentUser { public static function isAdmin(): bool { return true; } }
class SiteAccessRepository { public static function getUserRole(int $s, int $u): ?string { return null; } }
class PageAccessService {
    public static function canEditDisk(int $s, int $p, int $u): bool { return false; }
    public static function canViewDisk(int $s, int $p, int $u): bool { return $u === 12; }
}
require __DIR__ . '/../components/disk/lib/DiskPermissionService.php';
$settings = ['permissionMode' => 'inherit_site', 'allowDownload' => true, 'allowDelete' => true];
$subject = DiskPermissionService::resolve(new DiskContext(1, 2, 3, 12), $settings, 100, 100);
eq(true, $subject['canView'], 'Subject retains own view');
eq(false, $subject['canDelete'], 'Subject does not inherit admin delete');
eq(false, $subject['canManageAccess'], 'Subject does not inherit admin management');
$portal = DiskPermissionService::resolve(new DiskContext(1, 2, 3, 13), $settings, 100, 100);
eq(true, $portal['canManageAccess'], 'Other real admin retains management');
echo "Access subject identity checks passed ($checks checks)\n";
