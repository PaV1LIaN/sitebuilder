<?php
/**
 * Runs without Bitrix or PostgreSQL; verifies local config isolation and
 * the effective role for explicitly configured global SiteBuilder admins.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function sb_find_access_row(int $siteId, string $accessCode): ?array
{
    return null;
}

function sameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': ' . var_export($actual, true)
        );
    }
}

$root = sys_get_temp_dir() . '/sb-auth-test-' . bin2hex(random_bytes(6));
$dir = $root . '/local/sitebuilder/config';
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException('Unable to create auth fixture directory');
}

file_put_contents(
    $dir . '/auth.php',
    "<?php return ['admin_user_ids' => [40], 'guest_user_id' => 55];\n"
);
file_put_contents(
    $dir . '/auth.local.php',
    "<?php return ['admin_user_ids' => [41,96,97,98,99,665], 'guest_user_id' => 665];\n"
);
$_SERVER['DOCUMENT_ROOT'] = $root;

try {
    require __DIR__ . '/../lib/auth.php';
    require __DIR__ . '/../lib/access.php';

    $config = sitebuilder_auth_config();
    sameValue(665, $config['guest_user_id'], 'Local guest ID overrides repo defaults');
    sameValue([41,96,97,98,99,665], $config['admin_user_ids'], 'Local admin IDs override repo defaults');

    foreach ([41, 96, 97, 98, 99] as $id) {
        sameValue(true, sitebuilder_is_site_admin_user_id($id), 'Custom admin ID recognized ' . $id);
        sameValue('ADMIN', sb_get_role(1, 'U' . $id), 'Custom admin has ADMIN role ' . $id);
    }

    sameValue(false, sitebuilder_is_site_admin_user_id(665), 'Technical guest cannot be global admin');
    sameValue(null, sb_get_role(1, 'U665'), 'Technical guest has no implicit site role');
    sameValue(null, sb_get_role(1, 'U40'), 'Old admin list is overridden by local file');
    sameValue(null, sb_get_role(1, 'U100'), 'Regular user has no implicit site role');
    sameValue(null, sb_get_role(0, 'U41'), 'Invalid site ID cannot authorize an admin');
    sameValue(true, sb_role_allows('ADMIN', 'manage_access'), 'Global admin can manage site access');
    sameValue(false, sb_role_allows('ADMIN', 'owner'), 'Global admin is not site owner');

    $USER = new class {
        public function IsAdmin(): bool { return true; }
    };
    sb_require_site_role(1, 4); // Portal admin's separate bypass must work.

    echo "SiteBuilder local auth and global admin checks passed\n";
} finally {
    @unlink($dir . '/auth.php');
    @unlink($dir . '/auth.local.php');
    @rmdir($dir);
    @rmdir($root . '/local/sitebuilder');
    @rmdir($root . '/local');
    @rmdir($root);
}
