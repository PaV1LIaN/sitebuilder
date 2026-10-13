<?php
/**
 * Production admin config and readonly Disk settings regression.
 * No Bitrix bootstrap or PostgreSQL connection is required.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
function verify($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true)
            . ', actual ' . var_export($actual, true));
    }
}

$config = require __DIR__ . '/../config/auth.php';
verify([40, 41, 96, 97, 98, 99], $config['admin_user_ids'] ?? null, 'Default portal SiteBuilder admins');
verify(665, $config['guest_user_id'] ?? null, 'Technical guest ID');
verify('/local/sitebuilder/', $config['allowed_return_prefix'] ?? null, 'Allowed return prefix');

class DiskSitebuilderBridge
{
    public static ?array $block = null;
    public static int $writes = 0;
    public static array $lastSaved = [];

    public static function getBlockById(int $blockId): ?array
    {
        return self::$block;
    }

    public static function normalizeDiskProps(array $props): array
    {
        return [
            'rootMode' => $props['rootMode'] ?? 'site',
            'permissionMode' => $props['permissionMode'] ?? 'inherit_site',
            'rootFolderId' => $props['rootFolderId'] ?? null,
            'allowDelete' => !empty($props['allowDelete']),
        ];
    }

    public static function saveBlockProps(int $blockId, array $props, ?int $expectedVersion = null, int $userId = 0): bool
    {
        ++self::$writes;
        self::$lastSaved = $props;
        return true;
    }
}

require __DIR__ . '/../components/disk/lib/DiskSettingsRepository.php';

DiskSitebuilderBridge::$block = [
    'version' => 8,
    'props' => [
        'permissionMode' => 'bitrix_disk',
        'rootFolderId' => 123,
        'allowDelete' => true,
    ],
];

for ($i = 0; $i < 3; ++$i) {
    $value = DiskSettingsRepository::ensureExistsForBlock(80, 13, 31, 99);
    verify('bitrix_disk', $value['permissionMode'], 'Disk permission mode retained');
    verify(123, $value['rootFolderId'], 'Existing root is retained');
    verify(true, $value['allowDelete'], 'Deletion setting retained');
}
verify(0, DiskSitebuilderBridge::$writes, 'Reading Disk settings must not write to DB');

DiskSitebuilderBridge::$block = ['version' => 2, 'props' => []];
$value = DiskSettingsRepository::ensureExistsForBlock(80, 13, 31, 99);
verify('inherit_site', $value['permissionMode'], 'Default mode is returned without persisting it');
verify(0, DiskSitebuilderBridge::$writes, 'Even missing props do not trigger writes');

// Saving through explicit admin settings action remains supported.
verify(true, DiskSettingsRepository::save(80, ['allowDelete' => true], 2, 99), 'Explicit save works');
verify(1, DiskSitebuilderBridge::$writes, 'Explicit admin save persists once');
verify(true, DiskSitebuilderBridge::$lastSaved['allowDelete'], 'Admin change reaches storage');

DiskSitebuilderBridge::$block = null;
try {
    DiskSettingsRepository::ensureExistsForBlock(9000, 13, 31, 99);
    throw new RuntimeException('Expected missing block error');
} catch (RuntimeException $e) {
    verify('BLOCK_NOT_FOUND', $e->getMessage(), 'Missing block remains error');
}

echo "SiteBuilder deployment config and read-only Disk settings checks passed\n";
