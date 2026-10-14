<?php
namespace Bitrix\Main {
    class UserTable {
        public static function getList(array $options) {
            $rows = [];
            foreach ($options['filter']['@ID'] as $id) {
                if ($id > 0 && $id <= 1000) $rows[] = ['ID' => $id, 'ACTIVE' => 'Y', 'LOGIN' => 'user' . $id,
                    'EMAIL' => 'user' . $id . '@example.test', 'LAST_NAME' => 'Employee', 'NAME' => (string)$id, 'SECOND_NAME' => ''];
            }
            return new class($rows) {
                private int $i = 0;
                public function __construct(private array $rows) {}
                public function fetch() { return $this->rows[$this->i++] ?? false; }
            };
        }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    if (!getenv('SB_TEST_PG_DSN')) { echo "SKIP access admin PostgreSQL: isolated test DSN required\n"; exit; }
    if (getenv('SB_ACCESS_TEST_ALLOW_RESET') !== '1') throw new \RuntimeException('Explicit isolated DB reset permission required');
    $pdo = new \PDO(getenv('SB_TEST_PG_DSN'), getenv('SB_TEST_PG_USER') ?: 'postgres', getenv('SB_TEST_PG_PASSWORD') ?: 'postgres', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'sitebuilder_access_test') {
        throw new \RuntimeException('Refusing to run outside sitebuilder_access_test database');
    }
    $root = sys_get_temp_dir() . '/sb-access-pg-' . bin2hex(random_bytes(6));
    mkdir($root . '/local/php_interface/lib', 0700, true);
    file_put_contents($root . '/local/php_interface/lib/pg_master.php', '<?php');
    $_SERVER['DOCUMENT_ROOT'] = $root;
    function getPDO(): \PDO { return $GLOBALS['pdo']; }
    function sitebuilder_auth_config(): array { return ['guest_user_id' => 665, 'admin_user_ids' => []]; }
    require __DIR__ . '/../lib/db.php';
    require __DIR__ . '/../lib/storage_db_extra.php';
    require __DIR__ . '/../lib/RequestLockService.php';
    require __DIR__ . '/../lib/AccessUserResolver.php';
    require __DIR__ . '/../lib/AccessAdminService.php';
    $pdo->exec('DROP SCHEMA IF EXISTS sitebuilder CASCADE; CREATE SCHEMA sitebuilder');
    $pdo->exec("CREATE TABLE sitebuilder.site(id bigint PRIMARY KEY,name text NOT NULL,bitrix_group_id bigint);
        CREATE TABLE sitebuilder.page(id bigint PRIMARY KEY,site_id bigint NOT NULL REFERENCES sitebuilder.site(id),title text NOT NULL,parent_id bigint);
        CREATE TABLE sitebuilder.access(id bigserial PRIMARY KEY,site_id bigint NOT NULL REFERENCES sitebuilder.site(id),access_code text NOT NULL,
        role text NOT NULL,created_by bigint,updated_by bigint,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(site_id,access_code));
        CREATE TABLE sitebuilder.page_access(id bigserial PRIMARY KEY,site_id bigint NOT NULL REFERENCES sitebuilder.site(id),page_id bigint NOT NULL REFERENCES sitebuilder.page(id),
        access_code text NOT NULL,can_view boolean NOT NULL,can_edit boolean NOT NULL,can_disk_view boolean NOT NULL,can_disk_edit boolean NOT NULL,include_children boolean NOT NULL,
        created_by bigint,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(site_id,page_id,access_code));
        CREATE TABLE sitebuilder.audit_log(id bigserial PRIMARY KEY,request_id text NOT NULL,site_id bigint,actor_user_id bigint,actor_access_code text,action text,
        entity_type text,entity_id bigint,page_id bigint,details_json jsonb,created_at timestamptz DEFAULT now());
        INSERT INTO sitebuilder.site VALUES(1,'Test site',NULL),(2,'Other site',NULL);
        INSERT INTO sitebuilder.page VALUES(10,1,'Page',NULL),(20,2,'Other page',NULL);
        INSERT INTO sitebuilder.access(site_id,access_code,role,created_at,updated_at) VALUES(1,'U1','OWNER',now(),now())");
    $checks = 0;
    function eq($expected, $actual, string $label): void {
        global $checks; ++$checks;
        if ($expected !== $actual) throw new \RuntimeException($label . ': ' . var_export($actual, true));
    }
    function raises(callable $callback, string $expected): void {
        try { $callback(); } catch (\Throwable $e) { eq($expected, $e->getMessage(), 'Expected error'); return; }
        throw new \RuntimeException('Expected error not raised: ' . $expected);
    }
    function request(array $target, array $changes): array {
        $resolved = AccessAdminService::target($target, 99);
        return $target + ['requestId' => bin2hex(random_bytes(16)), 'revision' => AccessAdminService::revision($resolved, AccessAdminService::direct($resolved)), 'changes' => $changes];
    }
    function role(int $id): string {
        $st = sb_db()->prepare('SELECT role FROM sitebuilder.access WHERE site_id=1 AND access_code=:code');
        $st->execute([':code' => 'U' . $id]); return (string)$st->fetchColumn();
    }
    try {
        $site = ['kind' => 'site', 'siteId' => 1];
        $changes = [];
        for ($id = 2; $id <= 101; ++$id) $changes[] = ['userId' => $id, 'role' => 'VIEWER'];
        $req = request($site, $changes);
        $receipt = AccessAdminService::apply($req, 99);
        eq(100, $receipt['saved'], '100-user packet committed');
        eq(101, (int)$pdo->query('SELECT count(*) FROM sitebuilder.access')->fetchColumn(), 'No truncation and owner kept');
        eq(1, (int)$pdo->query('SELECT count(*) FROM sitebuilder.audit_log')->fetchColumn(), 'One atomic audit per packet');
        $replay = AccessAdminService::apply($req, 99);
        eq(true, $replay['replayed'], 'Retry receipt recognized');
        eq(1, (int)$pdo->query('SELECT count(*) FROM sitebuilder.audit_log')->fetchColumn(), 'Retry does not duplicate audit');
        $reused = $req; $reused['changes'][0]['role'] = 'EDITOR';
        raises(fn() => AccessAdminService::apply($reused, 99), 'REQUEST_ID_REUSED');
        $stale = $req; $stale['requestId'] = bin2hex(random_bytes(16));
        raises(fn() => AccessAdminService::apply($stale, 99), 'ACCESS_VERSION_CONFLICT');
        $invalid = request($site, [['userId' => 2, 'role' => 'EDITOR'], ['userId' => 2001, 'role' => 'EDITOR']]);
        raises(fn() => AccessAdminService::apply($invalid, 99), 'USER_VALIDATION_FAILED');
        eq('VIEWER', role(2), 'Whole invalid packet rolled back');
        raises(fn() => AccessAdminService::apply(request($site, [['userId' => 1, 'role' => 'INHERIT']]), 99), 'LAST_OWNER_PROTECTED');
        eq('OWNER', role(1), 'Last owner preserved');
        raises(fn() => AccessAdminService::apply(request($site, [['userId' => 665, 'role' => 'VIEWER']]), 99), 'USER_VALIDATION_FAILED');
        $before = $pdo->query("SELECT updated_at FROM sitebuilder.access WHERE access_code='U2'")->fetchColumn();
        AccessAdminService::apply(request($site, [['userId' => 2, 'role' => 'VIEWER']]), 99);
        eq($before, $pdo->query("SELECT updated_at FROM sitebuilder.access WHERE access_code='U2'")->fetchColumn(), 'No-op does not rewrite timestamp');
        $page = ['kind' => 'page', 'siteId' => 1, 'pageId' => 10];
        AccessAdminService::apply(request($page, [['userId' => 2, 'role' => 'VIEWER', 'diskRole' => 'EDITOR', 'includeChildren' => true]]), 99);
        AccessAdminService::apply(request($page, [['userId' => 2, 'role' => 'INHERIT']]), 99);
        $row = $pdo->query('SELECT * FROM sitebuilder.page_access')->fetch(\PDO::FETCH_ASSOC);
        eq(false, $row['can_view'], 'Direct page view cleared');
        eq(true, $row['can_disk_edit'], 'Disk edit retained');
        eq(true, $row['include_children'], 'Inherited children retained');
        AccessAdminService::apply(request($page, [['userId' => 2, 'role' => 'INHERIT', 'diskRole' => 'INHERIT']]), 99);
        eq(0, (int)$pdo->query('SELECT count(*) FROM sitebuilder.page_access')->fetchColumn(), 'Empty page rule deleted');
        raises(fn() => AccessAdminService::target(['kind' => 'page', 'siteId' => 2, 'pageId' => 10], 99), 'PAGE_NOT_IN_SITE');
        // Audit failure must roll back ACL, rather than announce false success.
        $pdo->exec("ALTER TABLE sitebuilder.audit_log ADD CONSTRAINT reject_new_audit CHECK(action <> 'accessAdmin.apply') NOT VALID");
        try { AccessAdminService::apply(request($site, [['userId' => 2, 'role' => 'EDITOR']]), 99); throw new \RuntimeException('Audit failure not propagated'); }
        catch (\PDOException $error) { eq('23514', $error->errorInfo[0], 'Audit check triggered'); }
        eq('VIEWER', role(2), 'Audit failure rolls back rights');
        $pdo->exec('ALTER TABLE sitebuilder.audit_log DROP CONSTRAINT reject_new_audit');
        AccessAdminService::apply(request($site, [['userId' => 2, 'role' => 'INHERIT']]), 99);
        eq('', role(2), 'Direct assignment removed');
        eq('VIEWER', role(3), 'Unrelated assignment untouched');
        // Five packets, not a 100-user UI cap.
        for ($offset = 0; $offset < 500; $offset += 100) {
            $rows = []; for ($id = 200 + $offset; $id < 300 + $offset; ++$id) {
                if ($id !== 665) $rows[] = ['userId' => $id, 'role' => 'EDITOR'];
            }
            AccessAdminService::apply(request($site, $rows), 99);
        }
        eq(499, (int)$pdo->query("SELECT count(*) FROM sitebuilder.access WHERE role='EDITOR'")->fetchColumn(), '499 users across five packets; technical guest excluded');
        echo "Access admin PostgreSQL checks passed ($checks checks)\n";
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec('DROP SCHEMA sitebuilder CASCADE');
        unlink($root . '/local/php_interface/lib/pg_master.php');
        rmdir($root . '/local/php_interface/lib'); rmdir($root . '/local/php_interface'); rmdir($root . '/local'); rmdir($root);
    }
}
