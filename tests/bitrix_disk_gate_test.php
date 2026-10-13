<?php
/**
 * Verify that physical Bitrix Disk ACL mode checks SiteBuilder Disk permission,
 * not unrelated permission to view the containing page.
 * All Bitrix classes are in-memory doubles; no production side effects.
 */
namespace Bitrix\Disk {
    class Folder
    {
        public static int $loads = 0;
        public static function loadById(int $id): ?self
        {
            ++self::$loads;
            return $id === 1001 ? new self() : null;
        }
    }

    class Driver {}
}

namespace Bitrix\Disk\Security {
    class DiskSecurityContext
    {
        public static bool $allowRead = true;
        public static bool $allowWrite = false;
        public function __construct(int $userId) {}
        public function canRead($folder): bool { return self::$allowRead; }
        public function canAdd($folder): bool { return self::$allowWrite; }
        public function canUpdate($folder): bool { return self::$allowWrite; }
        public function canRename($folder): bool { return self::$allowWrite; }
        public function canDelete($folder): bool { return self::$allowWrite; }
        public function canShare($folder): bool { return false; }
        public function canChangeRights($folder): bool { return false; }
        public function canChangeSettings($folder): bool { return false; }
    }
}

namespace Bitrix\Main {
    class Application {}
}

namespace {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }

    class PageAccessService
    {
        public static bool $canViewDisk = false;
        public static bool $canViewPage = false;
        public static int $diskChecks = 0;
        public static int $pageChecks = 0;

        public static function canViewDisk(int $siteId, int $pageId, int $userId): bool
        {
            ++self::$diskChecks;
            return self::$canViewDisk;
        }

        public static function canViewPage(int $siteId, int $pageId, int $userId): bool
        {
            ++self::$pageChecks;
            return self::$canViewPage;
        }
    }

    require __DIR__ . '/../components/disk/lib/DiskContext.php';
    require __DIR__ . '/../components/disk/lib/BitrixDiskRightsService.php';

    $checks = 0;
    function eq($expected, $actual, string $why): void
    {
        global $checks;
        ++$checks;
        if ($expected !== $actual) {
            throw new \RuntimeException(
                $why . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true)
            );
        }
    }

    $ctx = new \DiskContext(13, 31, 80, 17);
    $base = [
        'canView' => false,
        'canManageAccess' => false,
        'canEditSettings' => false,
    ];

    // Regression: a user with can_disk_view=true and can_view=false
    // should be admitted when the actual Bitrix folder ACL also allows read.
    PageAccessService::$canViewPage = false;
    PageAccessService::$canViewDisk = true;
    \Bitrix\Disk\Security\DiskSecurityContext::$allowRead = true;
    \Bitrix\Disk\Security\DiskSecurityContext::$allowWrite = false;

    $permissions = \BitrixDiskRightsService::resolvePermissions($ctx, 1001, $base);
    eq(true, $permissions['canView'], 'A disk-only SiteBuilder grant remains usable');
    eq(false, $permissions['canDelete'], 'Read-only folder ACL stays read-only');
    eq('bitrix_disk_acl', $permissions['accessSource'], 'ACL source is explicit');
    eq(1, PageAccessService::$diskChecks, 'Calls Disk permission resolver');
    eq(0, PageAccessService::$pageChecks, 'Does not use page view as a Disk gate');

    // Conversely, permission to view a page does NOT authorize opening Disk.
    PageAccessService::$canViewPage = true;
    PageAccessService::$canViewDisk = false;
    \Bitrix\Disk\Folder::$loads = 0;
    $permissions = \BitrixDiskRightsService::resolvePermissions($ctx, 1001, $base);
    eq(false, $permissions['canView'], 'Page-only grant must not open disk');
    eq(0, \Bitrix\Disk\Folder::$loads, 'Denies before loading physical folder ACL');
    eq('none', $permissions['effectiveTaskName'], 'Task is none without Disk permission');

    // Physical Bitrix folder ACL must still veto a permitted SiteBuilder user.
    PageAccessService::$canViewDisk = true;
    \Bitrix\Disk\Security\DiskSecurityContext::$allowRead = false;
    $permissions = \BitrixDiskRightsService::resolvePermissions($ctx, 1001, $base);
    eq(false, $permissions['canView'], 'Bitrix ACL can still deny a Disk grantee');
    eq(false, $permissions['canDownload'], 'Denied folder cannot be downloaded');

    // Access to a nonexistent folder never becomes an implicit allowance.
    $permissions = \BitrixDiskRightsService::resolvePermissions($ctx, 0, $base);
    eq(false, $permissions['canView'], 'Invalid folder cannot be read');
    eq('none', $permissions['effectiveTaskName'], 'Invalid folder has no task');

    echo "Bitrix Disk gate checks passed ($checks checks)\n";
}
