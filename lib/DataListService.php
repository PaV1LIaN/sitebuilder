<?php

declare(strict_types=1);

final class DataListException extends RuntimeException
{
    public function __construct(string $message, public int $status = 422, public array $details = [])
    { parent::__construct($message); }
}

final class DataListService
{
    public const TYPES = ['text', 'textarea', 'number', 'date', 'choice', 'checkbox', 'person', 'url'];

    /** Site snapshots keep datasets once, even when several blocks reference them. */
    public static function exportForSite(int $siteId): array
    {
        if (empty(sb_db_fetch_one("SELECT to_regclass('sitebuilder.data_list') AS relation")['relation'])) return [];
        $result = [];
        foreach (sb_db_fetch_all('SELECT l.* FROM sitebuilder.data_list l JOIN sitebuilder.page p
            ON p.id=l.owner_page_id AND p.site_id=l.site_id WHERE l.site_id=:site ORDER BY l.id', [':site' => $siteId]) as $row) {
            $items = [];
            foreach (sb_db_fetch_all('SELECT values_json,created_at,updated_at,deleted_at FROM sitebuilder.data_list_item
                WHERE list_id=:list ORDER BY id', [':list' => (int)$row['id']]) as $item) {
                $items[] = ['values' => sb_json_decode_assoc($item['values_json']), 'createdAt' => $item['created_at'],
                    'updatedAt' => $item['updated_at'], 'deletedAt' => $item['deleted_at']];
            }
            $result[] = ['oldId' => (int)$row['id'], 'oldPageId' => (int)$row['owner_page_id'],
                'title' => $row['title'], 'fields' => sb_json_decode_assoc($row['fields_json']), 'items' => $items];
        }
        return $result;
    }

    public static function importForSite(int $siteId, array $pageIdMap, array $lists, int $userId): array
    {
        $map = [];
        foreach ($lists as $list) {
            if (!is_array($list) || !isset($pageIdMap[(int)($list['oldPageId'] ?? 0)])
                || (int)($list['oldId'] ?? 0) <= 0 || isset($map[(int)$list['oldId']])) {
                throw new DataListException('Некорректный список в резервной копии.');
            }
            $fields = self::fields($list['fields'] ?? null);
            $title = self::text($list['title'] ?? '', 160, 'название списка');
            if ($title === '' || !is_array($list['items'] ?? null)) throw new DataListException('Некорректный список в резервной копии.');
            $row = sb_db_fetch_one('INSERT INTO sitebuilder.data_list(site_id,owner_page_id,title,fields_json,created_by,updated_by)
                VALUES(:site,:page,:title,CAST(:fields AS jsonb),:creator,:updater) RETURNING id', [
                ':site' => $siteId, ':page' => $pageIdMap[(int)$list['oldPageId']], ':title' => $title,
                ':fields' => self::json($fields), ':creator' => $userId, ':updater' => $userId]);
            $map[(int)$list['oldId']] = (int)$row['id'];
            foreach ($list['items'] as $item) {
                if (!is_array($item) || !is_array($item['values'] ?? null)) throw new DataListException('Некорректная запись в резервной копии.');
                $values = array_intersect_key($item['values'], array_column($fields, null, 'id'));
                // A native site backup keeps the portal's employee IDs, including former employees.
                foreach ($fields as $field) {
                    $person = $values[$field['id']] ?? null;
                    if ($field['type'] !== 'person' || $person === null) continue;
                    if (!is_array($person) || !is_int($person['id'] ?? null) || $person['id'] <= 0) {
                        throw new DataListException('Некорректный сотрудник в резервной копии.');
                    }
                    $values[$field['id']] = ['id' => $person['id'], 'name' => self::text($person['name'] ?? '', 500, 'сотрудник'),
                        'login' => self::text($person['login'] ?? '', 255, 'логин')];
                }
                $values = self::values($fields, $values, $values);
                $dates = [];
                foreach (['createdAt', 'updatedAt', 'deletedAt'] as $key) {
                    $date = $item[$key] ?? null;
                    if ($date === null && $key === 'deletedAt') { $dates[$key] = null; continue; }
                    if (!is_string($date) || strlen($date) > 64 || strtotime($date) === false) throw new DataListException('Некорректная дата в резервной копии.');
                    $dates[$key] = date('c', strtotime($date));
                }
                sb_db_execute('INSERT INTO sitebuilder.data_list_item(list_id,values_json,created_by,updated_by,created_at,updated_at,deleted_at)
                    VALUES(:list,CAST(:values AS jsonb),:creator,:updater,:created,:updated,:deleted)', [
                    ':list' => (int)$row['id'], ':values' => self::json((object)$values), ':creator' => $userId, ':updater' => $userId,
                    ':created' => $dates['createdAt'], ':updated' => $dates['updatedAt'], ':deleted' => $dates['deletedAt']]);
            }
        }
        return $map;
    }

    private static function json($value): string
    { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }

    private static function text($value, int $max, string $label): string
    {
        if (!is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw new DataListException('Некорректное значение: ' . $label);
        }
        return trim($value);
    }

    public static function fields($input): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) < 1 || count($input) > 50) {
            throw new DataListException('Добавьте от 1 до 50 полей.');
        }
        $result = []; $ids = [];
        foreach ($input as $field) {
            if (!is_array($field)) throw new DataListException('Некорректное поле.');
            $id = $field['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]{0,49}$/D', $id) || isset($ids[$id])) {
                throw new DataListException('Коды полей должны быть уникальными.');
            }
            $label = self::text($field['label'] ?? '', 120, 'название поля');
            $type = $field['type'] ?? '';
            if ($label === '' || !in_array($type, self::TYPES, true)) throw new DataListException('Укажите название и тип каждого поля.');
            $options = [];
            if ($type === 'choice') {
                if (!is_array($field['options'] ?? null) || count($field['options']) > 100) throw new DataListException('Укажите до 100 вариантов выбора.');
                foreach ($field['options'] as $option) {
                    $option = self::text($option, 160, $label);
                    if ($option !== '' && !in_array($option, $options, true)) $options[] = $option;
                }
                if (!$options) throw new DataListException('Добавьте варианты для поля «' . $label . '».');
            }
            $ids[$id] = true;
            $result[] = ['id' => $id, 'label' => $label, 'type' => $type,
                'required' => !empty($field['required']), 'options' => $options];
        }
        return $result;
    }

    public static function values(array $fields, $input, array $previous = []): array
    {
        if (!is_array($input) || ($input && array_is_list($input))) throw new DataListException('Некорректные данные записи.');
        $known = array_column($fields, null, 'id');
        if (array_diff_key($input, $known)) throw new DataListException('Состав полей изменился. Обновите список.', 409);
        $result = [];
        foreach ($fields as $field) {
            $id = $field['id']; $v = $input[$id] ?? null;
            if ($v === null || $v === '') {
                if ($field['required']) throw new DataListException('Заполните поле «' . $field['label'] . '».', 422, ['field' => $id]);
                $result[$id] = null; continue;
            }
            $bad = false;
            switch ($field['type']) {
                case 'number':
                    $bad = (!is_string($v) && !is_int($v) && !is_float($v)) || !preg_match('/^-?\d{1,15}(?:\.\d{1,6})?$/D', (string)$v);
                    if (!$bad) $v = (string)$v;
                    break;
                case 'date':
                    $bad = !is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m)
                        || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
                    break;
                case 'checkbox': $bad = !is_bool($v); break;
                case 'choice': $bad = !is_string($v) || !in_array($v, $field['options'], true); break;
                case 'person':
                    // Never trust a client-supplied employee name. Preserve former employees on unchanged edits.
                    $personId = is_array($v) ? ($v['id'] ?? 0) : $v;
                    if (!is_int($personId) || $personId <= 0) { $bad = true; break; }
                    if (is_array($previous[$id] ?? null) && ($previous[$id]['id'] ?? 0) === $personId) {
                        $v = $previous[$id]; break;
                    }
                    $person = \CUser::GetByID($personId)->Fetch();
                    if (!$person || ($person['ACTIVE'] ?? 'N') !== 'Y') { $bad = true; break; }
                    $v = self::person($person); break;
                default:
                    $v = self::text($v, $field['type'] === 'textarea' ? 10000 : 2000, $field['label']);
                    if ($field['type'] === 'url') {
                        $bad = !self::safeUrl($v);
                    }
            }
            if ($bad) throw new DataListException('Проверьте поле «' . $field['label'] . '».', 422, ['field' => $id]);
            if ($v === '' && $field['required']) throw new DataListException('Заполните поле «' . $field['label'] . '».', 422, ['field' => $id]);
            $result[$id] = $v;
        }
        return $result;
    }

    public static function safeUrl(string $value): bool
    {
        if (preg_match('/[\x00-\x20\\\\]/', $value)) return false;
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) return true;
        $url = parse_url($value);
        return is_array($url) && in_array(strtolower($url['scheme'] ?? ''), ['https', 'http'], true)
            && !empty($url['host']) && !isset($url['user']) && !isset($url['pass']);
    }

    private static function person(array $row): array
    {
        $name = trim(($row['LAST_NAME'] ?? '') . ' ' . ($row['NAME'] ?? '') . ' ' . ($row['SECOND_NAME'] ?? ''));
        return ['id' => (int)$row['ID'], 'name' => $name ?: (string)$row['LOGIN'], 'login' => (string)$row['LOGIN']];
    }

    public static function context(array $input, int $userId, bool $edit): array
    {
        if ($userId <= 0) throw new DataListException('Войдите на портал.', 401);
        $row = sb_db_fetch_one('SELECT b.id, b.content_json, p.id AS page_id, p.site_id, p.status
            FROM sitebuilder.block b JOIN sitebuilder.page p ON p.id=b.page_id
            JOIN sitebuilder.site s ON s.id=p.site_id
            WHERE b.id=:block AND p.id=:page AND p.site_id=:site AND b.type=\'list\'', [
            ':block' => (int)($input['blockId'] ?? 0), ':page' => (int)($input['pageId'] ?? 0), ':site' => (int)($input['siteId'] ?? 0)]);
        if (!$row) throw new DataListException('Список недоступен.', 403);
        $site = (int)$row['site_id']; $page = (int)$row['page_id'];
        $canEdit = PageAccessService::canEditPage($site, $page, $userId);
        if (!PageAccessService::canViewPage($site, $page, $userId) || ($edit && !$canEdit)
            || ($row['status'] !== 'published' && !$canEdit)) throw new DataListException('Нет доступа к списку.', 403);
        return ['siteId' => $site, 'pageId' => $page, 'userId' => $userId, 'canEdit' => $canEdit,
            'content' => sb_json_decode_assoc($row['content_json'])];
    }

    private static function definition(array $ctx, int $id, bool $edit = false, string $lock = ''): array
    {
        $row = sb_db_fetch_one('SELECT l.*, p.status AS page_status FROM sitebuilder.data_list l
            JOIN sitebuilder.page p ON p.id=l.owner_page_id AND p.site_id=l.site_id
            WHERE l.id=:id AND l.site_id=:site' . $lock, [':id' => $id, ':site' => $ctx['siteId']]);
        if (!$row) throw new DataListException('Список недоступен.', 403);
        $page = (int)$row['owner_page_id'];
        $canEdit = $ctx['canEdit'] && PageAccessService::canEditPage($ctx['siteId'], $page, $ctx['userId']);
        if (!PageAccessService::canViewPage($ctx['siteId'], $page, $ctx['userId']) || ($edit && !$canEdit)
            || ($row['page_status'] !== 'published' && !$canEdit)) throw new DataListException('Нет доступа к исходной странице списка.', 403);
        return ['id' => (int)$row['id'], 'title' => $row['title'], 'ownerPageId' => $page,
            'version' => (int)$row['version'], 'fields' => sb_json_decode_assoc($row['fields_json']), 'canEdit' => $canEdit];
    }

    private static function expectVersion(int $actual, $expected): void
    {
        if (!is_int($expected) || $expected !== $actual) {
            throw new DataListException('Данные изменились в другой вкладке. Обновите список перед сохранением.', 409);
        }
    }

    public static function dispatch(string $action, array $input, int $userId): array
    {
        $editorAction = in_array($action, ['catalog', 'create', 'schema', 'configure', 'people'], true);
        $write = in_array($action, ['create', 'schema', 'saveRecord', 'deleteRecord', 'restoreRecord'], true);
        $ctx = self::context($input, $userId, $editorAction || $write);
        if ($action === 'catalog') {
            $result = [];
            foreach (sb_db_fetch_all('SELECT id FROM sitebuilder.data_list WHERE site_id=:site ORDER BY title,id', [':site' => $ctx['siteId']]) as $row) {
                try { $result[] = self::definition($ctx, (int)$row['id']); }
                catch (DataListException $e) { if ($e->status !== 403) throw $e; }
            }
            return ['lists' => $result];
        }
        if ($action === 'create') {
            $title = self::text($input['title'] ?? '', 160, 'название списка');
            if ($title === '') throw new DataListException('Укажите название списка.');
            $fields = self::fields($input['fields'] ?? null);
            $row = sb_db_fetch_one('INSERT INTO sitebuilder.data_list(site_id,owner_page_id,title,fields_json,created_by,updated_by)
                VALUES(:site,:page,:title,CAST(:fields AS jsonb),:creator,:updater) RETURNING id', [
                ':site' => $ctx['siteId'], ':page' => $ctx['pageId'], ':title' => $title, ':fields' => self::json($fields), ':creator' => $userId, ':updater' => $userId]);
            return ['list' => self::definition($ctx, (int)$row['id'])];
        }
        if ($action === 'people') {
            $q = self::text($input['query'] ?? '', 100, 'поиск сотрудника');
            if (mb_strlen($q) < 2) return ['people' => []];
            $filter = ['=ACTIVE' => 'Y'];
            foreach (array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY), 0, 5) as $token) {
                $filter[] = ['LOGIC' => 'OR', '%LOGIN' => $token, '%NAME' => $token,
                    '%LAST_NAME' => $token, '%SECOND_NAME' => $token];
            }
            $rs = \Bitrix\Main\UserTable::getList(['select' => ['ID','LOGIN','NAME','LAST_NAME','SECOND_NAME'],
                'filter' => $filter, 'order' => ['LAST_NAME' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'], 'limit' => 20]);
            $people = []; while ($row = $rs->Fetch()) $people[] = self::person($row);
            return ['people' => $people];
        }
        $id = $editorAction ? (int)($input['listId'] ?? 0) : (int)($ctx['content']['listId'] ?? 0);
        if ($id <= 0) throw new DataListException('Выберите или создайте список в настройках блока.', 422);
        if ($write && !$editorAction && ($input['listId'] ?? null) !== $id) {
            throw new DataListException('В блоке выбран другой список. Обновите страницу.', 409);
        }
        $list = self::definition($ctx, $id, $write, $write ? ($action === 'schema' ? ' FOR UPDATE OF l' : ' FOR SHARE OF l') : '');
        if ($action === 'configure') return ['list' => $list];
        if ($action === 'schema') return self::saveSchema($ctx, $list, $input);
        if ($action === 'records') return self::records($ctx, $list, $input);
        if (in_array($action, ['saveRecord','deleteRecord','restoreRecord'], true)) return self::saveRecord($ctx, $list, $input, $action);
        throw new DataListException('Неизвестное действие.', 404);
    }

    private static function saveSchema(array $ctx, array $list, array $input): array
    {
        self::expectVersion($list['version'], $input['version'] ?? null);
        $fields = self::fields($input['fields'] ?? null);
        $title = self::text($input['title'] ?? '', 160, 'название списка');
        if ($title === '') throw new DataListException('Укажите название списка.');
        // Validate the new schema against every stored record, including the recycle bin.
        $next = array_column($fields, null, 'id'); $cursor = 0;
        do {
            $rows = sb_db_fetch_all('SELECT id,values_json FROM sitebuilder.data_list_item WHERE list_id=:list AND id>:cursor ORDER BY id LIMIT 200', [':list' => $list['id'], ':cursor' => $cursor]);
            foreach ($rows as $row) {
                $values = sb_json_decode_assoc($row['values_json']);
                foreach ($list['fields'] as $old) {
                    if (($values[$old['id']] ?? null) !== null && (!isset($next[$old['id']]) || $next[$old['id']]['type'] !== $old['type'])) {
                        throw new DataListException('Поле «' . $old['label'] . '» содержит данные. Очистите его перед удалением или сменой типа.');
                    }
                }
                self::values($fields, array_intersect_key($values, $next), $values);
                $cursor = (int)$row['id'];
            }
        } while (count($rows) === 200);
        sb_db_execute('UPDATE sitebuilder.data_list SET title=:title,fields_json=CAST(:fields AS jsonb),version=version+1,
            updated_by=:user,updated_at=NOW() WHERE id=:id', [':title' => $title, ':fields' => self::json($fields), ':user' => $ctx['userId'], ':id' => $list['id']]);
        return ['list' => self::definition($ctx, $list['id'])];
    }

    private static function expression(array $field, array &$params, bool $typed = false): string
    {
        $key = ':f' . count($params); $params[$key] = $field['id'];
        $expr = $field['type'] === 'person' ? '(values_json -> ' . $key . " ->> 'name')" : '(values_json ->> ' . $key . ')';
        return $typed && $field['type'] === 'number' ? 'CAST(' . $expr . ' AS numeric)' : $expr;
    }

    private static function records(array $ctx, array $list, array $input): array
    {
        $fields = array_column($list['fields'], null, 'id');
        $params = [':list' => $list['id']]; $where = ['list_id=:list'];
        $deleted = !empty($input['deleted']);
        if ($deleted && !$list['canEdit']) throw new DataListException('Нет доступа к удалённым записям.', 403);
        $where[] = $deleted ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
        $q = self::text($input['query'] ?? '', 200, 'поиск');
        if ($q !== '') {
            $parts = [];
            foreach ($fields as $field) {
                $expr = self::expression($field, $params); $key = ':q' . count($params); $params[$key] = $q;
                $parts[] = 'strpos(lower(COALESCE(' . $expr . ",'')),lower(" . $key . ')) > 0';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        // Saved block filters and visitor filters both apply. These are views, not access rules.
        foreach ([$ctx['content']['filters'] ?? [], $input['filters'] ?? []] as $filters) {
            if (!is_array($filters)) throw new DataListException('Некорректный фильтр.');
            foreach ($filters as $id => $value) {
                if ($value === '' || $value === null) continue;
                if (!isset($fields[$id])) throw new DataListException('Поле фильтра удалено. Обновите настройки блока.', 409);
                $field = $fields[$id]; $expr = self::expression($field, $params);
                $term = self::text($value, 2000, 'фильтр'); $key = ':v' . count($params); $params[$key] = $term;
                if (in_array($field['type'], ['choice','checkbox','date'], true)) $where[] = $expr . '=' . $key;
                elseif ($field['type'] === 'number') {
                    if (!preg_match('/^-?\d{1,15}(?:\.\d{1,6})?$/D', $term)) throw new DataListException('Укажите число в фильтре.');
                    $where[] = 'CAST(' . $expr . ' AS numeric)=CAST(' . $key . ' AS numeric)';
                } else $where[] = 'strpos(lower(COALESCE(' . $expr . ",'')),lower(" . $key . ')) > 0';
            }
        }
        $condition = implode(' AND ', $where);
        $total = (int)sb_db_fetch_one('SELECT COUNT(*) AS count FROM sitebuilder.data_list_item WHERE ' . $condition, $params)['count'];
        $pageSize = max(10, min(100, (int)($input['pageSize'] ?? 25)));
        $pages = max(1, (int)ceil($total / $pageSize)); $page = max(1, min($pages, (int)($input['page'] ?? 1)));
        $sort = (string)($input['sortBy'] ?? $ctx['content']['sortBy'] ?? '');
        $group = (string)($input['groupBy'] ?? $ctx['content']['groupBy'] ?? '');
        $dir = ($input['sortDir'] ?? $ctx['content']['sortDir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $order = [];
        if (isset($fields[$group])) $order[] = self::expression($fields[$group], $params, true) . ' ASC NULLS LAST';
        if (isset($fields[$sort]) && $sort !== $group) $order[] = self::expression($fields[$sort], $params, true) . ' ' . $dir . ' NULLS LAST';
        $order[] = 'id DESC';
        $rows = sb_db_fetch_all('SELECT id,values_json,version,created_at,updated_at,created_by,updated_by,deleted_at
            FROM sitebuilder.data_list_item WHERE ' . $condition . ' ORDER BY ' . implode(',', $order)
            . ' LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize), $params);
        $items = array_map(static fn($row) => [
            'id' => (int)$row['id'], 'version' => (int)$row['version'], 'values' => array_intersect_key(sb_json_decode_assoc($row['values_json']), $fields),
            'updatedAt' => $row['updated_at'], 'createdAt' => $row['created_at'], 'deleted' => $row['deleted_at'] !== null,
        ], $rows);
        return ['list' => $list, 'items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'groupBy' => isset($fields[$group]) ? $group : ''];
    }

    private static function saveRecord(array $ctx, array $list, array $input, string $action): array
    {
        self::expectVersion($list['version'], $input['schemaVersion'] ?? null);
        $id = (int)($input['itemId'] ?? 0); $item = null;
        if ($id > 0) {
            $item = sb_db_fetch_one('SELECT * FROM sitebuilder.data_list_item WHERE id=:id AND list_id=:list FOR UPDATE', [':id' => $id, ':list' => $list['id']]);
            if (!$item) throw new DataListException('Запись не найдена.', 404);
            self::expectVersion((int)$item['version'], $input['version'] ?? null);
        }
        if ($action !== 'saveRecord') {
            if (!$item) throw new DataListException('Запись не найдена.', 404);
            $restore = $action === 'restoreRecord';
            if ($restore && $item['deleted_at'] === null) throw new DataListException('Запись уже восстановлена.', 409);
            sb_db_execute('UPDATE sitebuilder.data_list_item SET deleted_at=' . ($restore ? 'NULL' : 'NOW()') . ',version=version+1,
                updated_by=:user,updated_at=NOW() WHERE id=:id AND list_id=:list', [':user' => $ctx['userId'], ':id' => $id, ':list' => $list['id']]);
            return ['itemId' => $id];
        }
        if ($item && $item['deleted_at'] !== null) throw new DataListException('Сначала восстановите удалённую запись.', 409);
        $values = self::values($list['fields'], $input['values'] ?? null, $item ? sb_json_decode_assoc($item['values_json']) : []);
        $params = [':list' => $list['id'], ':values' => self::json((object)$values), ':user' => $ctx['userId']];
        if ($item) {
            $params[':id'] = $id;
            sb_db_execute('UPDATE sitebuilder.data_list_item SET values_json=CAST(:values AS jsonb),version=version+1,
                updated_by=:user,updated_at=NOW() WHERE id=:id AND list_id=:list', $params);
        } else {
            $key = $input['requestKey'] ?? '';
            if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $key)) throw new DataListException('Обновите форму добавления записи.');
            $params[':key'] = $key; $params[':creator'] = $ctx['userId'];
            $row = sb_db_fetch_one('INSERT INTO sitebuilder.data_list_item(list_id,values_json,created_by,updated_by,request_key)
                VALUES(:list,CAST(:values AS jsonb),:creator,:user,:key)
                ON CONFLICT (list_id,request_key) DO NOTHING RETURNING id', $params);
            if (!$row) $row = sb_db_fetch_one('SELECT id FROM sitebuilder.data_list_item WHERE list_id=:list AND request_key=:key', [':list' => $list['id'], ':key' => $key]);
            $id = (int)$row['id'];
        }
        return ['itemId' => $id];
    }
}
