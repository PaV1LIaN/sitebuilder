<?php
// Runs the production HTTP action. The in-memory UserTable evaluates its nested
// AND/OR filter before applying the limit; it does not return canned search hits.
namespace Bitrix\Main {
    class UserTable
    {
        public static array $users = [];
        public static int $calls = 0;
        public static function getList(array $options)
        {
            ++self::$calls;
            $matches = static function (array $row, array $filter) use (&$matches): bool {
                $checks = [];
                foreach ($filter as $key => $value) {
                    if ($key === 'LOGIC') { continue; }
                    if (is_int($key)) { $checks[] = $matches($row, $value); continue; }
                    $actual = (string)($row[substr($key, 1)] ?? '');
                    $checks[] = $key[0] === '%' ? mb_stripos($actual, $value, 0, 'UTF-8') !== false : $actual === $value;
                }
                return ($filter['LOGIC'] ?? 'AND') === 'OR' ? in_array(true, $checks, true) : !in_array(false, $checks, true);
            };
            $rows = array_values(array_filter(self::$users, static fn($row) => $matches($row, $options['filter'])));
            usort($rows, static function ($a, $b) use ($options) {
                foreach ($options['order'] as $field => $direction) {
                    $order = $a[$field] <=> $b[$field];
                    if ($order) { return $direction === 'DESC' ? -$order : $order; }
                }
                return 0;
            });
            return new \SearchRows(array_slice($rows, 0, $options['limit']));
        }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    require __DIR__ . '/../components/disk/lib/DiskContext.php';
    class SearchRows {
        public function __construct(private array $rows) {}
        public function fetch() { return array_shift($this->rows) ?: false; }
    }
    class CUser {
        public static function GetByID($id) {
            ++\Bitrix\Main\UserTable::$calls;
            return new SearchRows(array_values(array_filter(\Bitrix\Main\UserTable::$users, static fn($row) => $row['ID'] === $id)));
        }
    }
    class DiskCsrf {
        public static bool $valid = true;
        public static function validateFromRequest() { if (!self::$valid) { throw new \RuntimeException('BAD_CSRF'); } }
    }
    class DiskCurrentUser { public static function requireId() { return 1; } }
    class DiskSettingsRepository {
        public static function getByBlockId($id) { return ['permissionMode' => 'custom']; }
        public static function ensureExistsForBlock(...$args) { throw new \RuntimeException('Search must not rewrite existing settings'); }
    }
    class DiskRootResolver { public static function resolve(...$args) { return 20; } }
    class DiskPermissionService {
        public static bool $allowed = true;
        public static function resolve(...$args) { return ['canManageAccess' => self::$allowed]; }
    }
    class DiskValidator {
        public static function assertContext($context) {
            if ($context->siteId !== 1 || $context->pageId !== 2 || $context->blockId !== 5) { throw new \RuntimeException('INVALID_CONTEXT'); }
        }
        public static function assertFolderInsideRoot($folder, $root, $context) {
            if ($folder !== 20) { throw new \RuntimeException('FOLDER_OUT_OF_SCOPE'); }
        }
        public static function assertCan($permissions, $capability) {
            if (empty($permissions[$capability])) { throw new \RuntimeException('ACCESS_DENIED'); }
        }
    }
    class SearchResponse extends \RuntimeException { public function __construct(public array $data) {} }
    class DiskResponse { public static function success($data) { throw new SearchResponse($data); } }
    function disk_read_json_body() { return $GLOBALS['searchBody']; }
    function searchUsers(string $query, array $extra = []): array {
        $GLOBALS['searchBody'] = $extra + ['query' => $query, 'siteId' => 1, 'pageId' => 2, 'blockId' => 5];
        try { require __DIR__ . '/../components/disk/actions/user_search.php'; }
        catch (SearchResponse $response) { return array_column($response->data['users'], 'id'); }
        throw new \RuntimeException('No response');
    }
    $checks = 0;
    function same($expected, $actual, string $message): void {
        ++$GLOBALS['checks'];
        if ($expected !== $actual) { throw new \RuntimeException($message . ': ' . json_encode($actual, JSON_UNESCAPED_UNICODE)); }
    }
    \Bitrix\Main\UserTable::$users = [
        ['ID'=>1,'ACTIVE'=>'Y','LAST_NAME'=>'Кузнецов','NAME'=>'Иван','SECOND_NAME'=>'Иванович','LOGIN'=>'ivanov','EMAIL'=>'ivanov@example.test'],
        ['ID'=>2,'ACTIVE'=>'Y','LAST_NAME'=>'Кузнецов','NAME'=>'Петр','SECOND_NAME'=>'Петрович','LOGIN'=>'petr','EMAIL'=>'petr@example.test'],
        ['ID'=>3,'ACTIVE'=>'Y','LAST_NAME'=>'Сидоров','NAME'=>'Иван','SECOND_NAME'=>'Иванович','LOGIN'=>'sidorov','EMAIL'=>'sidorov@example.test'],
        ['ID'=>4,'ACTIVE'=>'N','LAST_NAME'=>'Кузнецов','NAME'=>'Иван','SECOND_NAME'=>'Иванович','LOGIN'=>'disabled','EMAIL'=>'disabled@example.test'],
        ['ID'=>5,'ACTIVE'=>'Y','LAST_NAME'=>'Смирнов','NAME'=>'Иван Иванович','SECOND_NAME'=>'','LOGIN'=>'old domain','EMAIL'=>'old@example.test'],
    ];
    same([1,2], searchUsers('Кузнецов'), 'Surname search');
    same([1], searchUsers('Кузнецов Иван'), 'Adding first name must retain the matching user');
    foreach (['Кузнецов Иван Иванович','Иван Иванович Кузнецов','КУЗНЕЦОВ ИВАН ИВАНОВИЧ',"  Кузнецов\tИван\u{00A0}Иванович  ",'Кузнецов Ива Иванови'] as $query) {
        same([1], searchUsers($query), 'All FIO words across separate fields: ' . $query);
    }
    same([5], searchUsers('Смирнов Иван Иванович'), 'Two name words stored in one field');
    same([], searchUsers('Кузнецов Сидоров'), 'All words must belong to the same user');
    same([], searchUsers('Кузнецов Павел'), 'Different first name must not match');
    same([2], searchUsers('petr'), 'Login search');
    same([2], searchUsers('petr@example.test'), 'Email search');
    same([5], searchUsers('old domain'), 'Whole login branch with spaces');
    same([1], searchUsers('1'), 'Exact numeric ID');
    foreach (['4','disabled','disabled@example.test'] as $query) { same([], searchUsers($query), 'Inactive user excluded'); }
    foreach (['','А'] as $query) {
        $before = \Bitrix\Main\UserTable::$calls;
        same([], searchUsers($query), 'Empty or short query');
        same($before, \Bitrix\Main\UserTable::$calls, 'No directory scan for short query');
    }
    for ($id=10; $id<45; ++$id) {
        \Bitrix\Main\UserTable::$users[] = ['ID'=>$id,'ACTIVE'=>'Y','LAST_NAME'=>'Общий','NAME'=>'Иван','SECOND_NAME'=>'Тестович','LOGIN'=>'test'.$id,'EMAIL'=>'test'.$id.'@example.test'];
    }
    \Bitrix\Main\UserTable::$users[] = ['ID'=>45,'ACTIVE'=>'Y','LAST_NAME'=>'Общий','NAME'=>'Яков','SECOND_NAME'=>'Тестович','LOGIN'=>'last','EMAIL'=>'last@example.test'];
    same(20, count(searchUsers('Общий')), 'Result limit');
    same([45], searchUsers('Общий Яков Тестович'), 'Filter full name before limiting surname candidates');
    foreach (['csrf','permission','folder','context'] as $guard) {
        DiskCsrf::$valid = $guard !== 'csrf';
        DiskPermissionService::$allowed = $guard !== 'permission';
        $before = \Bitrix\Main\UserTable::$calls;
        $extra = $guard === 'folder' ? ['folderId'=>999] : ($guard === 'context' ? ['pageId'=>999] : []);
        $error = null;
        try { searchUsers('Кузнецов Иван', $extra); } catch (\RuntimeException $e) { $error = $e->getMessage(); }
        same(['csrf'=>'BAD_CSRF','permission'=>'ACCESS_DENIED','folder'=>'FOLDER_OUT_OF_SCOPE','context'=>'INVALID_CONTEXT'][$guard], $error, 'Guard rejects request');
        same($before, \Bitrix\Main\UserTable::$calls, 'Guard runs before directory query');
    }
    echo "PASS: {$checks} checks; production user search action, evaluated filters, FIO, identity lookup, limits and guards.\n";
}
