<?php
namespace Bitrix\Main\Entity {
    class ExpressionField { public function __construct(...$args) {} }
}
namespace Bitrix\Main {
    class UserTable {
        public static array $rows = [];
        public static int $queries = 0;
        public static function getList(array $options) {
            ++self::$queries;
            $rows = array_filter(self::$rows, static function (array $row) use ($options): bool {
                if (isset($options['filter']['@ID'])) return in_array($row['ID'], $options['filter']['@ID'], true);
                return $row['ACTIVE'] === 'Y' && $row['ID'] !== 665;
            });
            return new class(array_values($rows)) {
                private int $i = 0;
                public function __construct(private array $rows) {}
                public function fetch() { return $this->rows[$this->i++] ?? false; }
            };
        }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    if (!extension_loaded('mbstring')) { echo "SKIP access admin unit: mbstring required\n"; exit; }
    function sitebuilder_auth_config(): array { return ['guest_user_id' => 665, 'admin_user_ids' => [40]]; }
    require __DIR__ . '/../lib/AccessUserResolver.php';
    require __DIR__ . '/../lib/AccessAdminService.php';
    require_once __DIR__ . '/../lib/MigrationService.php';
    $checks = 0;
    function eq($expected, $actual, string $label): void {
        global $checks; ++$checks;
        if ($expected !== $actual) throw new \RuntimeException($label . ': ' . var_export($actual, true));
    }
    function raises(callable $callback, string $message): void {
        try { $callback(); } catch (\Throwable $e) { eq($message, $e->getMessage(), 'Expected exception'); return; }
        throw new \RuntimeException('Missing expected error ' . $message);
    }
    $user = ['ID' => 12, 'ACTIVE' => 'Y', 'LOGIN' => 'IVANOV', 'EMAIL' => 'Ivanov@example.org',
        'LAST_NAME' => 'Иванов', 'NAME' => 'Иван', 'SECOND_NAME' => 'Иванович'];
    $other = $user; $other['ID'] = 13; $other['LOGIN'] = 'ivanov2'; $other['EMAIL'] = 'other@example.org';
    $inactive = $user; $inactive['ID'] = 14; $inactive['ACTIVE'] = 'N';
    $guest = $user; $guest['ID'] = 665; $guest['LOGIN'] = 'guest';
    \Bitrix\Main\UserTable::$rows = [$user, $other, $inactive, $guest];
    eq(true, AccessUserResolver::matches('иванов ИВАН иванович', $user), 'Cyrillic exact name');
    eq(true, AccessUserResolver::matches('Иван Иванович Иванов', $user), 'Name order');
    eq(false, AccessUserResolver::matches('Иванов', $user), 'Surname is not identity');
    eq(false, AccessUserResolver::matches('Иванов Иван', $user), 'Incomplete FIO is not auto-selected');
    eq(true, AccessUserResolver::matches('ivanov@example.org', $user), 'Case insensitive email');
    eq(true, AccessUserResolver::matches('ivanov', $user), 'Case insensitive login');
    eq(true, AccessUserResolver::matches('U12', $user), 'Bitrix access code');
    eq(true, AccessUserResolver::matches('ID #12', $user), 'Explicit ID');
    eq(false, AccessUserResolver::matches('1', $user), 'ID not substring');
    $lines = AccessUserResolver::parse("\xEF\xBB\xBF12\r\n\r\n Иванов\tИван\xC2\xA0Иванович ;ivanov@example.org\n");
    eq([1,3,4], array_column($lines, 'line'), 'Original row numbers');
    eq('Иванов Иван Иванович', $lines[1]['input'], 'Excel tabs and NBSP');
    $rows = AccessUserResolver::resolve(AccessUserResolver::parse("12\nИванов Иван Иванович\nivanov@example.org\nmissing\n665"));
    eq(['found','ambiguous','duplicate','not_found','not_found'], array_column($rows, 'status'), 'Exact resolution outcomes');
    eq(2, $rows[1]['candidateCount'], 'Homonyms never auto-picked');
    eq(1, \Bitrix\Main\UserTable::$queries, 'One ORM query per resolver packet');
    eq(5000, count(AccessUserResolver::parse(str_repeat("12\n", 5000))), '5000 pasted rows accepted');
    raises(fn() => AccessUserResolver::parse(str_repeat("12\n", 5001)), 'USER_LIST_LIMIT');
    raises(fn() => AccessUserResolver::resolve(array_fill(0, 101, ['line' => 1, 'input' => '12'])), 'USER_BATCH_LIMIT');
    $page = ['kind' => 'page'];
    $old = ['U12' => ['can_view' => true, 'can_edit' => false, 'can_disk_view' => true,
        'can_disk_edit' => true, 'include_children' => true]];
    $change = AccessAdminService::normalizeChanges($page, [['userId' => 12, 'role' => 'INHERIT']], $old)[12];
    eq(true, $change['can_disk_edit'], 'Page-only update preserves disk edit');
    eq(true, $change['include_children'], 'Page update preserves inheritance');
    eq(false, $change['remove'], 'Disk-only record not deleted');
    $change = AccessAdminService::normalizeChanges($page, [['userId' => 12, 'role' => 'INHERIT', 'diskRole' => 'INHERIT']], $old)[12];
    eq(true, $change['remove'], 'Empty page record removed');
    raises(fn() => AccessAdminService::normalizeChanges($page, [['userId' => 12, 'role' => 'DENY']], []), 'INVALID_CHANGE_ROLE');
    raises(fn() => AccessAdminService::normalizeChanges($page, [['userId' => '12', 'role' => 'VIEWER']], []), 'INVALID_CHANGE_USER');
    raises(fn() => AccessAdminService::normalizeChanges($page, [['userId' => 12, 'role' => 'VIEWER'], ['userId' => 12, 'role' => 'VIEWER']], []), 'INVALID_CHANGE_USER');
    raises(fn() => AccessAdminService::normalizeChanges($page, [['userId' => 12, 'role' => 'VIEWER', 'includeChildren' => 'false']], []), 'INVALID_PAGE_CHANGE');
    $old['U12']['can_view'] = 'f'; $old['U12']['can_disk_edit'] = 'false';
    eq('INHERIT', AccessAdminService::assignment($page, $old['U12'])['role'], 'PostgreSQL false strings');
    $a = ['U12' => ['role' => 'VIEWER'], 'U13' => ['role' => 'EDITOR']]; $b = array_reverse($a, true);
    eq(AccessAdminService::revision($page, $a), AccessAdminService::revision($page, $b), 'Stable revision independent of row order');
    $b['U12']['role'] = 'EDITOR';
    eq(false, AccessAdminService::revision($page, $a) === AccessAdminService::revision($page, $b), 'Revision catches changes');
    // A copied production schema may already use stage 22 for a legacy unified-access migration.
    // Our additional page denial must not reuse that unique stage number.
    $manifest = MigrationService::manifest();
    $stages = array_column($manifest, 'stage');
    eq(count($stages), count(array_unique($stages)), 'Migration stages remain unique in the manifest');
    $denyMigrations = array_values(array_filter(
        $manifest,
        static fn(array $entry): bool => $entry['key'] === '20261014_011_page_access_deny'
    ));
    eq(1, count($denyMigrations), 'Deny migration registered once');
    eq(24, $denyMigrations[0]['stage'], 'Deny migration avoids historical stages 22 and 23');

    echo "Access admin unit checks passed ($checks checks)\n";
}
