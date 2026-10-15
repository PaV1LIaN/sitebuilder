<?php
namespace Bitrix\Disk {
    class Folder {
        public function __construct(private int $id) {}
        public static function loadById(int $id): ?self { return $id > 0 ? new self($id) : null; }
        public function getId(): int { return $this->id; }
        public function getParentId(): int { return 0; }
        public function getRealObject(): self { return $this; }
    }
    class Driver {
        public static $manager;
        public static function getInstance(): self { return new self(); }
        public function getRightsManager() { return self::$manager; }
    }
}
namespace Bitrix\Main {
    class Application {
        public static $connection;
        public static function getConnection() { return self::$connection; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    // Test fixtures use ASCII native access/task codes; this does not test Cyrillic lookup.
    if (!function_exists('mb_strtoupper')) { function mb_strtoupper(string $s): string { return strtoupper($s); } }
    if (!function_exists('mb_strtolower')) { function mb_strtolower(string $s): string { return strtolower($s); } }
    final class LockDb {
        public bool $busy = false;
        public int $held = 0;
        public function prepare(string $sql) {
            return new class($this, $sql) {
                public function __construct(private LockDb $db, private string $sql) {}
                public function execute(array $params): bool { return true; }
                public function fetchColumn() {
                    if (str_contains($this->sql, 'try_advisory')) {
                        if ($this->db->busy) { return false; }
                        ++$this->db->held; return true;
                    }
                    return true;
                }
            };
        }
    }
    $lockDb = new LockDb();
    function sb_db() { return $GLOBALS['lockDb']; }
    final class Manager {
        public const TASK_READ = 'disk_access_read', TASK_ADD = 'disk_access_add', TASK_EDIT = 'disk_access_edit', TASK_FULL = 'disk_access_full';
        public array $rights = [];
        public int $revokes = 0, $appends = 0;
        public bool $failAppend = false;
        public function getTaskIdByName(string $name): int { return [self::TASK_READ => 37, self::TASK_ADD => 61, self::TASK_EDIT => 88, self::TASK_FULL => 144][$name] ?? 0; }
        public function getSpecificRights($folder): array { return $this->rights; }
        public function revokeByAccessCodes($folder, array $codes): bool {
            ++$this->revokes;
            $this->rights = array_values(array_filter($this->rights, fn(array $r): bool => !in_array($r['ACCESS_CODE'], $codes, true)));
            return true;
        }
        public function append($folder, array $rights): bool {
            ++$this->appends;
            if ($this->failAppend) { $this->rights[] = $rights[0]; return false; }
            array_push($this->rights, ...$rights); return true;
        }
    }
    final class Connection {
        private ?array $backup = null;
        public bool $loseCommitReply = false;
        public int $rollbacks = 0;
        public function __construct(private Manager $manager) {}
        public function startTransaction(): void { $this->backup = $this->manager->rights; }
        public function commitTransaction(): void {
            $this->backup = null;
            if ($this->loseCommitReply) { throw new RuntimeException('Simulated lost commit reply'); }
        }
        public function rollbackTransaction(): void {
            ++$this->rollbacks;
            if ($this->backup !== null) { $this->manager->rights = $this->backup; $this->backup = null; }
        }
    }
    $manager = new Manager(); $connection = new Connection($manager);
    \Bitrix\Disk\Driver::$manager = $manager; \Bitrix\Main\Application::$connection = $connection;
    require __DIR__ . '/../components/disk/lib/BitrixDiskRightsService.php';
    require __DIR__ . '/../lib/AccessNativeDiskService.php';
    $checks = 0;
    function eq($expected, $actual, string $why): void { global $checks; ++$checks; if ($expected !== $actual) { throw new RuntimeException($why . ': ' . var_export($actual, true)); } }
    function raises(callable $fn, string $code): Throwable {
        try { $fn(); } catch (Throwable $e) { eq($code, $e->getMessage(), 'Expected error'); return $e; }
        throw new RuntimeException('Expected ' . $code);
    }
    function right(string $code, int $task, bool $negative = false): array { return ['ACCESS_CODE' => $code, 'TASK_ID' => $task, 'NEGATIVE' => $negative]; }
    $manager->rights = [right('G7',144), right('U900',37), right('U901',987), right('U902',37,true), right('U903',37),right('U903',88)];
    $before = BitrixDiskRightsService::getDirectSnapshot(100);
    $batch = [];
    for ($id = 1; $id <= 100; ++$id) { $batch[] = ['userId' => $id, 'taskName' => Manager::TASK_EDIT]; }
    $after = BitrixDiskRightsService::saveSelectedRights(100, $batch, $before['rightsRevision']);
    eq(106, count($after['specificRights']), '100 users plus untouched group, users and custom rules');
    eq(1, $manager->revokes, 'One bulk revoke, not one per employee');
    eq(1, $manager->appends, 'One bulk append, not one per employee');
    eq([right('G7',144)], $after['byAccessCode']['G7'], 'Group full access preserved');
    eq([right('U900',37)], $after['byAccessCode']['U900'], 'Unselected user preserved');
    eq([right('U901',987)], $after['byAccessCode']['U901'], 'Unknown task preserved');
    eq('CUSTOM', AccessNativeDiskService::direct($after)['U902']['role'], 'Partial negative is not mislabelled full denial');
    eq('CUSTOM', AccessNativeDiskService::direct($after)['U903']['role'], 'Mixed assignment not silently simplified');
    eq('EDITOR', AccessNativeDiskService::direct($after)['U1']['role'], 'Runtime native task ID mapped');
    BitrixDiskRightsService::saveSelectedRights(100, $batch, $after['rightsRevision']);
    eq(1, $manager->revokes, 'No-op never revokes');
    eq(1, $manager->appends, 'No-op never appends');
    raises(fn() => BitrixDiskRightsService::saveSelectedRights(100, $batch, $before['rightsRevision']), 'DISK_RIGHTS_VERSION_CONFLICT');
    eq(1, $manager->appends, 'Conflict does not mutate');
    $deny = [['userId' => 1, 'taskName' => 'none']];
    $after = BitrixDiskRightsService::saveSelectedRights(100, $deny, $after['rightsRevision']);
    eq([right('U1',144,true)], $after['byAccessCode']['U1'], 'Deny uses current installation full task');
    eq('DENY', AccessNativeDiskService::direct($after)['U1']['role'], 'Exact native denial visible');
    $after = BitrixDiskRightsService::saveSelectedRights(100, [['userId' => 1, 'taskName' => 'inherit']], $after['rightsRevision']);
    eq(false, isset($after['byAccessCode']['U1']), 'Removing direct rule preserves inheritance');
    eq(true, isset($after['byAccessCode']['G7']), 'Inherit does not revoke group');
    $manager->failAppend = true;
    $unchanged = $after['rightsRevision'];
    $error = raises(fn() => BitrixDiskRightsService::saveSelectedRights(100, $batch, $unchanged), 'DISK_RIGHTS_BATCH_FAILED');
    eq('append', $error->phase, 'Precise failure phase');
    eq(100, count($error->userIds), 'All affected IDs, not a fabricated culprit');
    eq($unchanged, BitrixDiskRightsService::getDirectSnapshot(100)['rightsRevision'], 'Mid-append failure rolls the native packet back');
    $manager->failAppend = false; $connection->loseCommitReply = true;
    $snapshot = BitrixDiskRightsService::getDirectSnapshot(100);
    $plan = BitrixDiskRightsService::planSelectedRights($batch, $snapshot);
    $error = raises(fn() => BitrixDiskRightsService::saveSelectedRights(100, $batch, $snapshot['rightsRevision']), 'DISK_RIGHTS_BATCH_FAILED');
    eq('commit', $error->phase, 'Lost native commit response is distinguishable');
    $journal = ['beforeRevision' => $snapshot['rightsRevision'], 'afterRevision' => $plan['afterRevision']];
    eq('applied', AccessNativeDiskService::recoveryState($journal, BitrixDiskRightsService::getDirectSnapshot(100)['rightsRevision']), 'Durable outcome can be recovered without reapplying');
    eq('not_applied', AccessNativeDiskService::recoveryState($journal, $journal['beforeRevision']), 'Retry before native write is safe');
    eq('review_required', AccessNativeDiskService::recoveryState($journal, str_repeat('a',64)), 'Ambiguous state never blindly overwritten');
    $lockDb->busy = true;
    raises(fn() => BitrixDiskRightsService::saveSelectedRights(100, $batch, $plan['afterRevision']), 'DISK_RIGHTS_BUSY');
    $lockDb->busy = false;
    echo "Native Disk batch checks passed ($checks checks; Bitrix and transactions are doubles)\n";
}
