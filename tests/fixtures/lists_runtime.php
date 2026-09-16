<?php
// Isolated database/Bitrix fixtures shared by CLI and HTTP contract tests.
function lists_query(string $sql, array $params = [], bool $script = false): array {
    static $pipes, $process;
    if (!$process) {
        $process = proc_open(['python3', __DIR__ . '/lists_sqlite_bridge.py', getenv('LISTS_TEST_DB') ?: ':memory:'],
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['file', 'php://stderr', 'w']], $pipes);
    }
    fwrite($pipes[0], json_encode(['sql' => $sql, 'params' => $params, 'script' => $script], JSON_THROW_ON_ERROR) . "\n");
    $reply = json_decode(fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
    if (isset($reply['error'])) throw new RuntimeException($reply['error'] . "\n" . $sql);
    return $reply['rows'];
}
function sb_db_fetch_all(string $sql, array $params = []): array { return lists_query($sql, $params); }
function sb_db_fetch_one(string $sql, array $params = []): ?array { return lists_query($sql, $params)[0] ?? null; }
function sb_db_execute(string $sql, array $params = []): bool { lists_query($sql, $params); return true; }
function sb_json_decode_assoc($value): array { return is_array($value) ? $value : json_decode($value, true, 512, JSON_THROW_ON_ERROR); }
function sb_db_transaction_scope_begin(): bool { lists_query('BEGIN'); return true; }
function sb_db_transaction_scope_commit(bool $started): void { if ($started) lists_query('COMMIT'); }
function sb_db_transaction_scope_rollback(bool $started): void { if ($started) lists_query('ROLLBACK'); }
class PageAccessService {
    public static array $denied = [];
    public static function canViewPage($site, $page, $user): bool { return $user > 0 && $user !== 9 && !in_array($page, self::$denied, true); }
    public static function canEditPage($site, $page, $user): bool { return $user === 1 && self::canViewPage($site, $page, $user); }
}
class ListsUserResult {
    public function __construct(private array $rows) {}
    public function Fetch() { return array_shift($this->rows) ?: false; }
}
class CUser {
    public static function GetByID($id) { return new ListsUserResult($id === 7 ? [['ID' => 7, 'NAME' => 'Анна', 'LAST_NAME' => 'Иванова', 'LOGIN' => 'anna', 'ACTIVE' => 'Y']] : []); }
    public static function GetList(&$by, &$order, $filter, $options) { return self::GetByID(7); }
}
class ListsUserTable {
    public static array $query = [];
    public static function getList(array $query) { self::$query = $query; return CUser::GetByID(7); }
}
class_alias(ListsUserTable::class, 'Bitrix\Main\UserTable');
function lists_setup(): void {
    lists_query("CREATE TABLE site(id INTEGER PRIMARY KEY);
        CREATE TABLE page(id INTEGER PRIMARY KEY,site_id INTEGER,status TEXT);
        CREATE TABLE block(id INTEGER PRIMARY KEY,page_id INTEGER,type TEXT,content_json TEXT);
        CREATE TABLE data_list(id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER,owner_page_id INTEGER,title TEXT,fields_json TEXT,
            version INTEGER DEFAULT 1,created_by INTEGER,updated_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE data_list_item(id INTEGER PRIMARY KEY AUTOINCREMENT,list_id INTEGER,values_json TEXT,version INTEGER DEFAULT 1,
            created_by INTEGER,updated_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT,request_key TEXT,UNIQUE(list_id,request_key));
        INSERT INTO site VALUES(1),(2);
        INSERT INTO page VALUES(10,1,'published'),(11,1,'published'),(20,2,'published');
        INSERT INTO block VALUES(100,10,'list','{}'),(101,11,'list','{}'),(200,20,'list','{}');", [], true);
}
