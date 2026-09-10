<?php

// Standalone regression tests: php tests/appearance_upload_test.php [--no-virtual-io]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/db.php';
require_once (getenv('SB_APPEARANCE_TEST_SOURCE') ?: __DIR__ . '/../lib/SiteAppearanceService.php');

class COption
{
    public static string $uploadDir = 'upload';

    public static function GetOptionString($module, $option, $default): string
    {
        return self::$uploadDir;
    }
}

if (!in_array('--no-virtual-io', $argv, true)) {
    class CBXVirtualIo
    {
        public static array $physicalNames = [];

        public static function GetInstance(): self
        {
            return new self();
        }

        public function ClearCache(): void
        {
            clearstatcache();
        }

        public function FileExists(string $path): bool
        {
            return is_file(self::$physicalNames[$path] ?? $path);
        }
    }
}

class CFile
{
    public static array $records = [];
    public static array $paths = [];
    public static array $deleted = [];
    public static array $overrides = [];
    public static bool $writeFile = true;
    public static bool $mapPhysicalName = false;
    public static int $nextId = 100;

    public static function SaveFile(array $file, string $directory): int
    {
        $id = ++self::$nextId;
        $subdir = $directory . '/' . $id;
        $relativePath = '/' . trim(COption::$uploadDir, '/') . '/' . $subdir . '/';
        $path = $_SERVER['DOCUMENT_ROOT'] . $relativePath . $file['name'];
        if (self::$mapPhysicalName) {
            $physicalPath = dirname($path) . '/physical-name.png';
            CBXVirtualIo::$physicalNames[$path] = $physicalPath;
            $path = $physicalPath;
        }
        self::$paths[$id] = $path;
        if (self::$writeFile) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            copy($file['tmp_name'], $path);
        }
        self::$records[$id] = array_merge([
            'ID' => $id,
            'SUBDIR' => $subdir,
            'FILE_NAME' => $file['name'],
            'SRC' => $relativePath . rawurlencode($file['name']),
            'HANDLER_ID' => null,
        ], self::$overrides);
        return $id;
    }

    public static function GetFileArray(int $id)
    {
        return self::$records[$id] ?? false;
    }

    public static function GetPath(int $id): string
    {
        return self::$records[$id]['SRC'] ?? '/old.png';
    }

    public static function Delete(int $id): void
    {
        self::$deleted[] = $id;
        if (isset(self::$paths[$id]) && is_file(self::$paths[$id])) {
            unlink(self::$paths[$id]);
        }
        unset(self::$records[$id]);
    }
}

class RevisionService
{
    public static array $site;

    public static function getSite(int $id, bool $includeDeleted): array
    {
        return self::$site;
    }

    public static function requireExpectedVersion(int $version): int
    {
        return $version;
    }

    public static function saveSite(array $site, int $version, int $userId, string $operation): array
    {
        if ($version !== self::$site['version']) {
            throw new RuntimeException('VERSION_CONFLICT');
        }
        $site['version']++;
        return self::$site = $site;
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function resetUpload(): void
{
    COption::$uploadDir = 'upload';
    CFile::$deleted = [];
    CFile::$overrides = [];
    CFile::$writeFile = true;
    CFile::$mapPhysicalName = false;
    RevisionService::$site = ['id' => 22, 'version' => 5, 'settings' => ['logoFileId' => 7, 'backgroundFileId' => 8]];
    $GLOBALS['SB_REQUEST_TRANSACTION_ACTIVE'] = true;
    $GLOBALS['SB_REQUEST_AFTER_COMMIT'] = [];
    $GLOBALS['SB_REQUEST_AFTER_ROLLBACK'] = [];
}

function uploadFixture(string $name = 'logo.png', string $type = 'logo', int $version = 5): array
{
    return SiteAppearanceService::upload(22, $type, [
        'name' => $name,
        'tmp_name' => $GLOBALS['fixturePath'],
        'size' => filesize($GLOBALS['fixturePath']),
        'type' => 'image/png',
        'error' => UPLOAD_ERR_OK,
    ], 1, $version);
}

function expectError(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        check($e->getMessage() === $code, 'Expected ' . $code . ', got ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected ' . $code);
}

$testRoot = sys_get_temp_dir() . '/sb-appearance-' . bin2hex(random_bytes(8));
mkdir($testRoot, 0775, true);
$_SERVER['DOCUMENT_ROOT'] = $testRoot;
$GLOBALS['fixturePath'] = $testRoot . '/fixture.png';
file_put_contents($GLOBALS['fixturePath'], base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='));
$failures = 0;
$tests = [];

foreach (['logo.png', 'логотип компании.png', 'logo + 100%20 #1.png'] as $name) {
    $tests['upload ' . $name] = static function () use ($name): void {
        $result = uploadFixture($name);
        $newId = $result['logoFileId'];
        check(is_file(CFile::$paths[$newId]), 'Uploaded bytes were removed');
        check($result['siteVersion'] === 6 && $newId > 100, 'Appearance was not updated');
        check(CFile::$deleted === [], 'Old logo deleted before commit');
        sb_db_run_callbacks('SB_REQUEST_AFTER_COMMIT');
        check(CFile::$deleted === [7], 'Commit must delete only the old logo');
    };
}
$tests['custom upload directory and rewritten SRC'] = static function (): void {
    COption::$uploadDir = 'custom/uploads';
    CFile::$overrides = ['SRC' => '/images/logo?version=1'];
    uploadFixture();
    check(is_dir($_SERVER['DOCUMENT_ROOT'] . '/custom/uploads/sitebuilder/appearance'), 'Configured directory was not prepared');
};
if (class_exists('CBXVirtualIo')) {
    $tests['Bitrix logical and physical names differ'] = static function (): void {
        CFile::$mapPhysicalName = true;
        $result = uploadFixture('логотип.png');
        check(is_file(CFile::$paths[$result['logoFileId']]), 'Virtual IO file was deleted');
    };
}
foreach ([['HANDLER_ID' => 3], ['SRC' => 'https://cdn.example.test/logo.png'], ['SRC' => '//cdn.example.test/logo.png']] as $i => $record) {
    $tests['remote storage ' . $i] = static function () use ($record): void {
        CFile::$writeFile = false;
        CFile::$overrides = $record;
        uploadFixture();
        check(CFile::$deleted === [], 'Remote asset was deleted by a local check');
    };
}
$tests['missing local file preserves old logo'] = static function (): void {
    CFile::$writeFile = false;
    // Even an existing file at SRC cannot stand in for the stored file.
    CFile::$overrides = ['SRC' => '/fixture.png'];
    expectError(static fn() => uploadFixture(), 'FILE_PHYSICAL_SAVE_FAILED');
    check(RevisionService::$site['settings']['logoFileId'] === 7, 'Old logo changed');
    check(CFile::$deleted === [CFile::$nextId], 'Only the failed new file should be deleted');
    check($GLOBALS['SB_REQUEST_AFTER_COMMIT'] === [], 'Old logo cleanup was scheduled after failure');
};
$tests['missing file metadata is rejected'] = static function (): void {
    CFile::$overrides = ['FILE_NAME' => ''];
    expectError(static fn() => uploadFixture(), 'FILE_RECORD_NOT_FOUND');
};
$tests['version conflict rolls back new file only'] = static function (): void {
    expectError(static fn() => uploadFixture('logo.png', 'logo', 4), 'VERSION_CONFLICT');
    sb_db_run_callbacks('SB_REQUEST_AFTER_ROLLBACK');
    check(CFile::$deleted === [CFile::$nextId], 'Rollback deleted the wrong file');
    check(RevisionService::$site['settings']['logoFileId'] === 7, 'Conflict replaced the old logo');
};
$tests['background upload shares the fix'] = static function (): void {
    $result = uploadFixture('фон сайта.png', 'background');
    check($result['backgroundFileId'] > 100 && $result['logoFileId'] === 7, 'Wrong appearance asset updated');
    sb_db_run_callbacks('SB_REQUEST_AFTER_COMMIT');
    check(CFile::$deleted === [8], 'Background cleanup deleted the wrong asset');
};

try {
    foreach ($tests as $label => $test) {
        resetUpload();
        try {
            $test();
            echo 'PASS ' . $label . PHP_EOL;
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDERR, 'FAIL ' . $label . ': ' . $e->getMessage() . PHP_EOL);
        }
    }
} finally {
    $paths = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($paths as $path) {
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }
    rmdir($testRoot);
}

echo count($tests) . ' tests, ' . $failures . ' failures' . PHP_EOL;
exit($failures > 0 ? 1 : 0);
