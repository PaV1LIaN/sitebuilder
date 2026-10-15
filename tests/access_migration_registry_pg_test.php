<?php
declare(strict_types=1);

// Never use this test against any real Bitrix database. It DROPS the isolated schema.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!getenv('SB_TEST_PG_DSN')) { echo "SKIP migration registry PostgreSQL: isolated DSN required\n"; exit; }
if (getenv('SB_ACCESS_TEST_ALLOW_RESET') !== '1') {
    throw new RuntimeException('Explicit isolated DB reset permission required');
}
$pdo = new PDO(
    getenv('SB_TEST_PG_DSN'),
    getenv('SB_TEST_PG_USER') ?: 'postgres',
    getenv('SB_TEST_PG_PASSWORD') ?: 'postgres',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'sitebuilder_access_test') {
    throw new RuntimeException('Refusing to run outside sitebuilder_access_test database');
}
$root = sys_get_temp_dir() . '/sb-migration-pg-' . bin2hex(random_bytes(6));
mkdir($root . '/local/php_interface/lib', 0700, true);
file_put_contents($root . '/local/php_interface/lib/pg_master.php', '<?php');
$_SERVER['DOCUMENT_ROOT'] = $root;
$GLOBALS['migrationTestPDO'] = $pdo;
function getPDO(): PDO { return $GLOBALS['migrationTestPDO']; }
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/MigrationService.php';

$checks = 0;
function migrationAssert(bool $condition, string $message): void {
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
try {
    $pdo->exec('DROP SCHEMA IF EXISTS sitebuilder CASCADE');
    $pdo->exec('CREATE SCHEMA sitebuilder; CREATE TABLE sitebuilder.page_access(id bigint PRIMARY KEY)');
    // Use the real historical registry structure, not a simplified mock.
    $pdo->exec(file_get_contents(__DIR__ . '/../migrations/20260730_008_migration_registry_and_deployment_runs.sql'));
    $entries = MigrationService::manifest();
    $stmt = $pdo->prepare("INSERT INTO sitebuilder.schema_migration
        (migration_key,stage_number,filename,title,checksum,source,metadata_json)
        VALUES(:key,:stage,:filename,:title,:checksum,'executed','{}'::jsonb)");
    foreach ($entries as $entry) {
        if ($entry['stage'] === 24) continue;
        $stmt->execute([
            ':key' => $entry['key'], ':stage' => $entry['stage'],
            ':filename' => basename($entry['file']), ':title' => $entry['title'],
            ':checksum' => hash_file('sha256', $entry['file']),
        ]);
    }
    $legacy = [
        [22, '20260827_011_unified_access_reconciliation', '20260827_011_unified_access_reconciliation.sql'],
        [23, '20260911_012_data_lists', '20260911_012_data_lists.sql'],
    ];
    foreach ($legacy as [$stage,$key,$filename]) {
        $stmt->execute([
            ':key' => $key, ':stage' => $stage, ':filename' => $filename,
            ':title' => $key, ':checksum' => hash('sha256', $key),
        ]);
    }
    $status = MigrationService::status();
    migrationAssert($status['pendingCount'] === 1, 'Only stage 24 must be pending');
    migrationAssert($status['unrecognizedCount'] === 2, 'Old migrations must stay visible');
    migrationAssert($status['conflictCount'] === 0, 'Stage 24 is available');

    // A future unknown migration on stage 24 must be detected before altering page_access.
    $stmt->execute([
        ':key' => 'a_future_external_migration', ':stage' => 24,
        ':filename' => 'external.sql', ':title' => 'External migration',
        ':checksum' => str_repeat('a',64),
    ]);
    $status = MigrationService::status();
    migrationAssert($status['conflictCount'] === 1, 'Occupied stage detected by status');
    migrationAssert(($status['items'][count($status['items'])-1]['state'] ?? '') === 'conflict', 'Conflict is displayed on stage 24');
    try {
        MigrationService::applyPending(99);
        throw new RuntimeException('Expected MIGRATION_STAGE_CONFLICT');
    } catch (SiteBuilderMigrationException $e) {
        migrationAssert($e->getMessage() === 'MIGRATION_STAGE_CONFLICT', 'Conflict exposed without generic SQL failure');
        migrationAssert($e->details()['stageNumber'] === 24, 'Conflict contains stage number');
        migrationAssert($e->details()['existingMigrationKey'] === 'a_future_external_migration', 'Conflict identifies occupant');
    }
    migrationAssert($pdo->query("SELECT count(*) FROM information_schema.columns
        WHERE table_schema='sitebuilder' AND table_name='page_access' AND column_name='deny_view'")->fetchColumn() == 0,
        'No DDL on an occupied stage');
    $pdo->exec("DELETE FROM sitebuilder.schema_migration WHERE stage_number = 24 AND migration_key='a_future_external_migration'");

    $done = MigrationService::applyPending(99);
    migrationAssert($done['appliedCount'] === 1, 'Only the new migration is applied');
    $status = MigrationService::status();
    migrationAssert($status['pendingCount'] === 0 && $status['ready'] === true, 'Registry ready after migration');
    migrationAssert($status['unrecognizedCount'] === 2 && $status['conflictCount'] === 0,
        'Historical entries remain and are visible');
    migrationAssert($pdo->query("SELECT count(*) FROM sitebuilder.schema_migration WHERE stage_number IN (22,23)")->fetchColumn() == 2,
        'Historical stage registrations unchanged');
    migrationAssert($pdo->query("SELECT count(*) FROM information_schema.columns
        WHERE table_schema='sitebuilder' AND table_name='page_access' AND column_name='deny_view'")->fetchColumn() == 1,
        'Deny column created');
    migrationAssert($pdo->query("SELECT count(*) FROM sitebuilder.schema_migration
        WHERE stage_number=24 AND migration_key='20261014_011_page_access_deny'")->fetchColumn() == 1,
        'New stage 24 registered');
    $repeat = MigrationService::applyPending(99);
    migrationAssert($repeat['appliedCount'] === 0, 'Repeat deployment is idempotent');
    echo "Migration compatibility PostgreSQL checks passed ($checks checks)\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('DROP SCHEMA IF EXISTS sitebuilder CASCADE');
    unlink($root . '/local/php_interface/lib/pg_master.php');
    rmdir($root . '/local/php_interface/lib');
    rmdir($root . '/local/php_interface');
    rmdir($root . '/local');
    rmdir($root);
}
