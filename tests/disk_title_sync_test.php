<?php

// php tests/disk_title_sync_test.php — real title service/adapter, fixture Disk and PG boundary.
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    final class TitleFixture
    {
        public static array $objects = [];
        public static array $blocks = [];
        public static array $jobs = [];
        public static array $callbacks = [];
        public static int $writes = 0;
        public static bool $admin = true;
        public static string $failure = '';
        public static function reset(): void
        {
            self::$objects = [
                1 => ['name' => 'Общий диск', 'parent' => 0],
                10 => ['name' => 'rpa', 'parent' => 1],
                20 => ['name' => 'Блок Документы', 'parent' => 10],
                21 => ['name' => 'Архив', 'parent' => 10],
                22 => ['name' => 'Договоры', 'parent' => 20],
                30 => ['name' => 'Чужая папка', 'parent' => 1],
            ];
            self::$blocks = [5 => ['id' => 5, 'pageId' => 2, 'type' => 'disk', 'version' => 7,
                'props' => ['title' => 'Документы', 'rootMode' => 'block', 'rootFolderId' => 20]]];
            self::$jobs = self::$callbacks = [];
            self::$writes = 0; self::$admin = true; self::$failure = '';
        }
    }
    class RevisionService
    {
        public static function getBlock(int $id, bool $lock = false): ?array { return TitleFixture::$blocks[$id] ?? null; }
        public static function getSite(int $id): ?array { return $id === 1 ? ['id' => 1, 'diskFolderId' => 10] : null; }
        public static function getPage(int $id): ?array { return $id === 2 ? ['id' => 2, 'siteId' => 1] : null; }
    }
    class OutboxService
    {
        public const JOB_DISK_TITLE_SYNC = 'disk.title.sync';
        public static function enqueue($type, $siteId, $payload, $key, $userId, $priority): array
        {
            $id = count(TitleFixture::$jobs) + 1;
            return TitleFixture::$jobs[$id] = ['id' => $id, 'siteId' => $siteId, 'payload' => $payload, 'status' => 'pending'];
        }
        public static function get(int $id): ?array { return TitleFixture::$jobs[$id] ?? null; }
    }
    class TitlePDO extends \PDO
    {
        private bool $active = false;
        public function __construct() {}
        public function setAttribute(int $attribute, mixed $value): bool { return true; }
        public function exec(string $statement): int|false { return 0; }
        public function beginTransaction(): bool { $this->active = true; return true; }
        public function commit(): bool { $this->active = false; return true; }
        public function rollBack(): bool { $this->active = false; return true; }
        public function inTransaction(): bool { return $this->active; }
    }
    function getPDO(): \PDO { static $pdo; return $pdo ??= new TitlePDO(); }
    function sb_get_role(int $siteId, ?string $code = null): ?string { return null; }
    $USER = new class {
        public function IsAuthorized(): bool { return true; }
        public function GetID(): int { return 2; }
        public function IsAdmin(): bool { return TitleFixture::$admin; }
    };
    $fixtureRoot = sys_get_temp_dir() . '/sb-title-' . bin2hex(random_bytes(5));
    mkdir($fixtureRoot . '/local/sitebuilder/config', 0777, true);
    mkdir($fixtureRoot . '/local/php_interface/lib', 0777, true);
    file_put_contents($fixtureRoot . '/local/php_interface/lib/pg_master.php', '<?php');
    file_put_contents($fixtureRoot . '/local/sitebuilder/config/auth.php', '<?php return ["admin_user_ids"=>[],"guest_user_id"=>0];');
    $_SERVER['DOCUMENT_ROOT'] = $fixtureRoot;
    register_shutdown_function(static function () use ($fixtureRoot): void {
        unlink($fixtureRoot . '/local/sitebuilder/config/auth.php');
        unlink($fixtureRoot . '/local/php_interface/lib/pg_master.php');
        foreach (['/local/sitebuilder/config', '/local/sitebuilder', '/local/php_interface/lib', '/local/php_interface', '/local', ''] as $suffix) rmdir($fixtureRoot . $suffix);
    });
    function check(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    function rejects(callable $cb, string $message): void
    {
        try { $cb(); } catch (\RuntimeException $e) {
            check(str_starts_with($e->getMessage(), $message), $e->getMessage() . ' != ' . $message); return;
        }
        throw new \RuntimeException('Expected ' . $message);
    }
}
namespace Bitrix\Main { class Loader { public static function includeModule($module): bool { return true; } } }
namespace Bitrix\Disk {
    class Folder
    {
        public function __construct(private int $id) {}
        public static function loadById(int $id): ?self { return isset(\TitleFixture::$objects[$id]) ? new self($id) : null; }
        public function getId(): int { return $this->id; }
        public function getName(): string { return \TitleFixture::$objects[$this->id]['name']; }
        public function getParentId(): int { return \TitleFixture::$objects[$this->id]['parent']; }
        public function isDeleted(): bool { return !empty(\TitleFixture::$objects[$this->id]['deleted']); }
        public function getCreateTime(): string { return ''; }
        public function getUpdateTime(): string { return ''; }
        public function getCreatedBy(): int { return 2; }
        public function getErrors(): array { return ['Имя уже занято']; }
        public function getStorage(): object { return new class { public function getSecurityContext($id): int { return $id; } }; }
        public function canRename($context): bool { return !empty(\TitleFixture::$objects[$this->id]['nativeRename']); }
        public function rename($name, $userId): bool
        {
            if (\TitleFixture::$failure === 'false') return false;
            if (\TitleFixture::$failure === 'unconfirmed') return true;
            foreach (\TitleFixture::$objects as $id => $row) {
                if ($id !== $this->id && $row['parent'] === $this->getParentId() && $row['name'] === $name) return false;
            }
            \TitleFixture::$objects[$this->id]['name'] = $name;
            \TitleFixture::$writes++;
            return true;
        }
    }
}
namespace {
    require_once __DIR__ . '/../lib/DiskTitleSyncService.php';
    require_once __DIR__ . '/../components/disk/lib/DiskContext.php';
    require_once __DIR__ . '/../components/disk/lib/DiskBitrixStorageAdapter.php';

    $tests = [];
    $tests['explicit settings save aligns an existing legacy root without changing its ID or contents'] = static function (): void {
        $old = TitleFixture::$blocks[5]; $next = $old;
        $change = DiskTitleSyncService::prepare($old, $next, 2, 'disk_settings_save');
        check($change['fromName'] === 'Блок Документы' && $change['title'] === 'Документы', 'migration intent');
        check(TitleFixture::$writes === 0, 'no native writes before commit');
        $scope = sb_db_transaction_scope_begin();
        DiskTitleSyncService::enqueue($change, 8);
        check(count($GLOBALS['SB_SCOPE_AFTER_COMMIT']) === 1 && TitleFixture::$writes === 0, 'delivery registered after commit');
        sb_db_transaction_scope_rollback($scope);
        check(empty($GLOBALS['SB_SCOPE_AFTER_COMMIT']), 'rollback discards immediate delivery');
        DiskTitleSyncService::execute(TitleFixture::$jobs[1]);
        check(TitleFixture::$objects[20]['name'] === 'Документы', 'portal title');
        check(TitleFixture::$objects[22]['parent'] === 20, 'contents stay in the same root');
        DiskTitleSyncService::execute(TitleFixture::$jobs[1]);
        check(TitleFixture::$writes === 1, 'retry is idempotent');
    };
    $tests['portal rename is read immediately without rewriting props or queuing a reverse rename'] = static function (): void {
        TitleFixture::$objects[20]['name'] = 'Название с портала';
        check(DiskTitleSyncService::folderName(20, 'Старое') === 'Название с портала', 'portal name displayed');
        check(TitleFixture::$writes === 0 && TitleFixture::$jobs === [], 'read has no mutations');
        rejects(static fn() => DiskTitleSyncService::assertUnchanged(20, 'Блок Документы'), 'DISK_TITLE_CONFLICT');
        DiskTitleSyncService::assertUnchanged(20, 'Название с портала');
    };
    $tests['normalization on a viewer request never renames a folder'] = static function (): void {
        TitleFixture::$admin = false;
        $old = TitleFixture::$blocks[5]; $next = $old; $next['props']['title'] = 'Другое';
        check(DiskTitleSyncService::prepare($old, $next, 2, 'disk_settings_update') === null, 'automatic normalization ignored');
    };
    $tests['editor title changes are normalized once for both sides'] = static function (): void {
        $old = TitleFixture::$blocks[5]; $next = $old; $next['props']['title'] = '  Отчёты / 2026 * ';
        $change = DiskTitleSyncService::prepare($old, $next, 2, 'content_update');
        check($next['props']['title'] === 'Отчёты 2026' && $change['title'] === $next['props']['title'], 'same sanitized title');
        TitleFixture::$blocks[5] = $next;
        DiskTitleSyncService::execute(['siteId' => 1, 'payload' => $change]);
        check(TitleFixture::$objects[20]['name'] === 'Отчёты 2026', 'editor updates native name');
    };
    $tests['switching roots adopts the selected folder instead of renaming it'] = static function (): void {
        $old = TitleFixture::$blocks[5]; $next = $old; $next['props']['rootFolderId'] = 21;
        check(DiskTitleSyncService::prepare($old, $next, 2, 'disk_settings_save') === null, 'no rename on rebind');
        check(DiskTitleSyncService::folderName(21, 'Документы') === 'Архив', 'selected folder name');
    };
    $tests['site-mode blocks share the name of the same site root'] = static function (): void {
        $old = TitleFixture::$blocks[5]; $old['props']['rootMode'] = 'site'; $next = $old;
        $change = DiskTitleSyncService::prepare($old, $next, 2, 'disk_settings_save');
        check($change['folderId'] === 10, 'site root is the rename target');
        TitleFixture::$blocks[5] = $next;
        DiskTitleSyncService::execute(['siteId' => 1, 'payload' => $change]);
        $other = ['props' => ['rootMode' => 'site', 'title' => 'Другой заголовок']];
        check(DiskTitleSyncService::folderName(DiskTitleSyncService::rootId($other, ['diskFolderId' => 10]), '') === 'Документы', 'second block sees the same root name');
    };
    $tests['stale jobs cannot rename a rebound root or overwrite a newer title'] = static function (): void {
        $old = TitleFixture::$blocks[5]; $next = $old;
        $change = DiskTitleSyncService::prepare($old, $next, 2, 'disk_settings_save');
        TitleFixture::$blocks[5]['props']['rootFolderId'] = 21;
        check(DiskTitleSyncService::execute(['siteId' => 1, 'payload' => $change])['skipped'], 'rebound job skipped');
        TitleFixture::$blocks[5] = $old;
        TitleFixture::$blocks[5]['props']['title'] = 'Новое название';
        check(DiskTitleSyncService::execute(['siteId' => 1, 'payload' => $change])['skipped'], 'older title skipped');
        check(TitleFixture::$writes === 0, 'stale jobs did not write');
    };
    $tests['a portal rename made after enqueue wins over a delayed job'] = static function (): void {
        $old = TitleFixture::$blocks[5]; $next = $old;
        $change = DiskTitleSyncService::prepare($old, $next, 2, 'disk_settings_save');
        TitleFixture::$objects[20]['name'] = 'Изменено на портале';
        rejects(static fn() => DiskTitleSyncService::execute(['siteId' => 1, 'payload' => $change]), 'DISK_TITLE_CONFLICT');
        check(TitleFixture::$writes === 0, 'portal title protected');
    };
    $tests['rename denial and duplicate sibling names cannot return success'] = static function (): void {
        $context = new DiskContext(1, 2, 5, 2);
        $adapter = new DiskBitrixStorageAdapter(2);
        rejects(static fn() => $adapter->rename($context, 'folder', 20, 'Архив'), 'DISK_RENAME_FAILED');
        TitleFixture::$failure = 'false';
        rejects(static fn() => $adapter->rename($context, 'folder', 20, 'Новое'), 'DISK_RENAME_FAILED');
        TitleFixture::$failure = 'unconfirmed';
        rejects(static fn() => $adapter->rename($context, 'folder', 20, 'Новое'), 'DISK_RENAME_NOT_CONFIRMED');
        check(TitleFixture::$writes === 0, 'failed rename did not mutate');
    };
    $tests['nested rename updates the same portal object and native breadcrumbs'] = static function (): void {
        $context = new DiskContext(1, 2, 5, 2);
        $adapter = new DiskBitrixStorageAdapter(2);
        $item = $adapter->rename($context, 'folder', 22, 'Договоры 2026');
        $crumbs = $adapter->getBreadcrumbs($context, 22);
        check($item['id'] === 22 && $item['name'] === 'Договоры 2026', 'same native ID');
        check(end($crumbs)['name'] === 'Договоры 2026', 'breadcrumbs use native name');
    };
    $tests['rename requires management rights, including when a queued job runs later'] = static function (): void {
        $old = TitleFixture::$blocks[5]; $next = $old;
        $change = DiskTitleSyncService::prepare($old, $next, 2, 'disk_settings_save');
        TitleFixture::$admin = false;
        rejects(static fn() => DiskTitleSyncService::execute(['siteId' => 1, 'payload' => $change]), 'ACCESS_DENIED');
        rejects(static function () use ($old, $next): void { DiskTitleSyncService::prepare($old, $next, 2, 'disk_settings_save'); }, 'ACCESS_DENIED');
    };
    $tests['foreign configured roots need native rename permission and storage roots are protected'] = static function (): void {
        $old = TitleFixture::$blocks[5]; $old['props']['rootFolderId'] = 30; $next = $old;
        rejects(static function () use ($old, $next): void { DiskTitleSyncService::prepare($old, $next, 2, 'disk_settings_save'); }, 'ACCESS_DENIED');
        TitleFixture::$objects[30]['nativeRename'] = true;
        check(DiskTitleSyncService::prepare($old, $next, 2, 'disk_settings_save')['folderId'] === 30, 'native permission permits bound folder');
        $old['props']['rootFolderId'] = 1; $next = $old;
        rejects(static function () use ($old, $next): void { DiskTitleSyncService::prepare($old, $next, 2, 'disk_settings_save'); }, 'DISK_STORAGE_ROOT_RENAME_FORBIDDEN');
    };
    $tests['missing or trashed roots are never replaced by a similarly named folder'] = static function (): void {
        TitleFixture::$objects[20]['deleted'] = true;
        $old = TitleFixture::$blocks[5]; $next = $old;
        rejects(static function () use ($old, $next): void { DiskTitleSyncService::prepare($old, $next, 2, 'disk_settings_save'); }, 'DISK_FOLDER_NOT_FOUND');
        check(DiskTitleSyncService::folderName(20, 'Файлы') === 'Файлы', 'safe fallback for deleted root');
    };
    foreach ($tests as $name => $test) { TitleFixture::reset(); $test(); echo 'PASS: ' . $name . "\n"; }
    echo 'PASS: ' . count($tests) . " disk name scenarios. Live Bitrix/PG integration is not simulated.\n";
}
