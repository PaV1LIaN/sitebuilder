<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$documentRoot = getenv('DOCUMENT_ROOT') ?: realpath(__DIR__ . '/../../..');
if (!$documentRoot) {
    fwrite(STDERR, "DOCUMENT_ROOT_REQUIRED\n");
    exit(2);
}

$_SERVER['DOCUMENT_ROOT'] = rtrim($documentRoot, '/');
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/MigrationService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/BackupService.php';

$options = getopt('', ['status', 'bootstrap', 'apply']);
$mode = isset($options['status']) ? 'status' : (isset($options['bootstrap']) ? 'bootstrap' : 'apply');

try {
    if ($mode === 'status') {
        $result = MigrationService::status();
    } elseif ($mode === 'bootstrap') {
        $result = MigrationService::bootstrap(0);
        $result['backupStorageMigration'] = BackupService::migrateLegacyFiles();
    } else {
        $result = MigrationService::registryReady()
            ? MigrationService::applyPending(0)
            : MigrationService::bootstrap(0);
        $result['backupStorageMigration'] = BackupService::migrateLegacyFiles();
    }

    echo json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    $status = MigrationService::status();
    exit(!empty($status['ready']) ? 0 : 1);
} catch (SiteBuilderMigrationException $e) {
    error_log('SiteBuilder migrate CLI failed: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
} catch (Throwable $e) {
    error_log('SiteBuilder migrate CLI failed: ' . $e->getMessage());
    fwrite(STDERR, "MIGRATION_FAILED\n");
    exit(2);
}
