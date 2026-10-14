<?php

declare(strict_types=1);

require_once __DIR__ . '/PageAccessPolicy.php';
require_once __DIR__ . '/AccessNativeDiskService.php';

final class AccessAdminException extends RuntimeException
{
    public function __construct(string $code, public array $details = [], public int $httpStatus = 422)
    {
        parent::__construct($code);
    }
}

/** Direct administration of local ACL and native Bitrix Disk permissions. */
final class AccessAdminService
{
    public const BATCH_SIZE = 100;

    private static function all(string $sql, array $params = []): array
    {
        $stmt = sb_db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function target(array $input, int $actor): array
    {
        $kind = (string)($input['kind'] ?? 'site');
        $siteId = (int)($input['siteId'] ?? 0);
        if (!in_array($kind, ['site', 'page', 'disk', 'folder'], true) || $siteId <= 0) {
            throw new AccessAdminException('INVALID_TARGET');
        }
        $site = self::all('SELECT id,name,bitrix_group_id FROM sitebuilder.site WHERE id=:id', [':id' => $siteId])[0] ?? null;
        if (!$site) {
            throw new AccessAdminException('SITE_NOT_FOUND', [], 404);
        }
        $target = ['kind' => $kind, 'siteId' => $siteId, 'pageId' => 0, 'blockId' => 0, 'folderId' => 0,
            'title' => (string)$site['name'], 'siteName' => (string)$site['name'],
            'workgroupId' => (int)($site['bitrix_group_id'] ?? 0), 'mode' => 'local', 'editable' => true];
        if ($kind === 'site') {
            return $target;
        }
        $pageId = (int)($input['pageId'] ?? 0);
        $page = self::all('SELECT id,title FROM sitebuilder.page WHERE site_id=:site AND id=:page',
            [':site' => $siteId, ':page' => $pageId])[0] ?? null;
        if (!$page) {
            throw new AccessAdminException('PAGE_NOT_IN_SITE', [], 404);
        }
        $target['pageId'] = $pageId;
        $target['pageDenyAvailable'] = PageAccessPolicy::available();
        $target['title'] .= ' / ' . $page['title'];
        if ($kind === 'page') {
            return $target;
        }
        $blockId = (int)($input['blockId'] ?? 0);
        $context = new DiskContext($siteId, $pageId, $blockId, $actor);
        DiskValidator::assertBlockBelongsToContext($context);
        $settings = DiskSettingsRepository::getByBlockId($blockId);
        $root = (int)DiskRootResolver::resolve($context, $settings, false);
        if ($root <= 0) {
            throw new AccessAdminException('DISK_ROOT_NOT_CONFIGURED');
        }
        $folderId = $kind === 'disk' ? $root : (int)($input['folderId'] ?? 0);
        DiskValidator::assertFolderInsideRoot($folderId, $root, $context);
        $folder = \Bitrix\Disk\Folder::loadById($folderId);
        if (!$folder instanceof \Bitrix\Disk\Folder) {
            throw new AccessAdminException('FOLDER_NOT_FOUND', [], 404);
        }
        // Do not follow a link to a different real object when writing local rules.
        if (method_exists($folder, 'getRealObject')) {
            $realFolder = $folder->getRealObject();
            if (!$realFolder instanceof \Bitrix\Disk\Folder || (int)$realFolder->getId() !== $folderId) {
                throw new AccessAdminException('LINKED_FOLDER_NOT_SUPPORTED');
            }
        }
        return array_merge($target, [
            'blockId' => $blockId, 'folderId' => $folderId, 'rootFolderId' => $root,
            'mode' => (string)($settings['permissionMode'] ?? 'inherit_site'),
            'editable' => ($settings['permissionMode'] ?? '') === 'custom'
                || (($settings['permissionMode'] ?? '') === 'bitrix_disk' && AccessUserIdentity::isPortalAdmin($actor)),
            'title' => $target['title'] . ' / ' . (string)$folder->getName(), 'settings' => $settings,
        ]);
    }

    public static function tree(array $input, int $actor): array
    {
        if (empty($input['siteId'])) {
            return ['nodes' => array_map(static fn(array $row): array => [
                'kind' => 'site', 'siteId' => (int)$row['id'], 'title' => (string)$row['name'], 'expandable' => true,
            ], self::all('SELECT id,name FROM sitebuilder.site ORDER BY name,id'))];
        }
        $target = self::target($input, $actor);
        if ($target['kind'] === 'site') {
            $nodes = [];
            foreach (self::all('SELECT id,title,parent_id FROM sitebuilder.page WHERE site_id=:id ORDER BY sort,id',
                [':id' => $target['siteId']]) as $row) {
                $nodes[] = ['kind' => 'page', 'siteId' => $target['siteId'], 'pageId' => (int)$row['id'],
                    'parentPageId' => (int)($row['parent_id'] ?? 0), 'title' => (string)$row['title'], 'expandable' => false];
            }
            foreach (self::all("SELECT b.id,b.page_id,b.props_json,p.title FROM sitebuilder.block b
                JOIN sitebuilder.page p ON p.id=b.page_id WHERE p.site_id=:id AND b.type='disk' ORDER BY p.sort,b.sort,b.id",
                [':id' => $target['siteId']]) as $row) {
                $props = sb_json_decode_assoc($row['props_json']);
                $nodes[] = ['kind' => 'disk', 'siteId' => $target['siteId'], 'pageId' => (int)$row['page_id'],
                    'blockId' => (int)$row['id'], 'title' => (string)($props['title'] ?? 'Диск') . ' — ' . $row['title'],
                    'expandable' => true];
            }
            return ['nodes' => $nodes];
        }
        if ($target['kind'] === 'page') {
            return ['nodes' => []];
        }
        $folder = \Bitrix\Disk\Folder::loadById($target['folderId']);
        $children = $folder->getChildren(\Bitrix\Disk\Driver::getInstance()->getFakeSecurityContext($actor), [
            'filter' => ['=TYPE' => \Bitrix\Disk\Internals\ObjectTable::TYPE_FOLDER],
            'order' => ['ID' => 'ASC'], 'limit' => 101, 'offset' => max(0, (int)($input['offset'] ?? 0)),
        ]);
        $nodes = [];
        foreach ($children as $child) {
            if ($child instanceof \Bitrix\Disk\Folder) {
                $nodes[] = ['kind' => 'folder', 'siteId' => $target['siteId'], 'pageId' => $target['pageId'],
                    'blockId' => $target['blockId'], 'folderId' => (int)$child->getId(),
                    'title' => (string)$child->getName(), 'expandable' => true];
            }
        }
        return ['nodes' => array_slice($nodes, 0, 100), 'hasMore' => count($nodes) > 100,
            'nextOffset' => max(0, (int)($input['offset'] ?? 0)) + 100];
    }

    public static function direct(array $target): array
    {
        $params = [':site' => $target['siteId']];
        $sql = 'SELECT * FROM sitebuilder.access WHERE site_id=:site';
        if ($target['kind'] === 'page') {
            $sql = 'SELECT * FROM sitebuilder.page_access WHERE site_id=:site AND page_id=:page';
            $params[':page'] = $target['pageId'];
        } elseif (in_array($target['kind'], ['disk', 'folder'], true)) {
            if ($target['mode'] === 'bitrix_disk') {
                return AccessNativeDiskService::direct(BitrixDiskRightsService::getDirectSnapshot($target['folderId']));
            }
            if ($target['mode'] !== 'custom') {
                return [];
            }
            $sql = 'SELECT * FROM sitebuilder.disk_folder_access WHERE site_id=:site AND block_id=:block AND folder_id=:folder';
            $params[':block'] = $target['blockId'];
            $params[':folder'] = $target['folderId'];
        }
        $rows = [];
        foreach (self::all($sql . ' ORDER BY access_code', $params) as $row) {
            $rows[(string)$row['access_code']] = $row;
        }
        return $rows;
    }

    public static function revision(array $target, array $direct): string
    {
        ksort($direct, SORT_STRING);
        return hash('sha256', json_encode([$target, $direct], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function bool($value): bool
    {
        return $value === true || in_array(strtolower((string)$value), ['1', 't', 'true', 'y'], true);
    }

    public static function assignment(array $target, ?array $row): array
    {
        if ($target['kind'] !== 'page') {
            return ['role' => (string)($row['role'] ?? 'INHERIT')];
        }
        return [
            'role' => self::bool($row['deny_view'] ?? false) ? 'DENY'
                : (self::bool($row['can_edit'] ?? false) ? 'EDITOR' : (self::bool($row['can_view'] ?? false) ? 'VIEWER' : 'INHERIT')),
            'diskRole' => self::bool($row['can_disk_edit'] ?? false) ? 'EDITOR' : (self::bool($row['can_disk_view'] ?? false) ? 'VIEWER' : 'INHERIT'),
            'includeChildren' => self::bool($row['include_children'] ?? false),
        ];
    }

    private static function candidateIds(array $target, array $direct): array
    {
        $ids = [];
        $add = static function (string $code) use (&$ids): void {
            if (preg_match('/^U([1-9][0-9]*)$/', $code, $match)) {
                $ids[(int)$match[1]] = true;
            }
        };
        foreach (array_keys($direct) as $code) {
            $add($code);
        }
        foreach (self::all('SELECT access_code FROM sitebuilder.access WHERE site_id=:site', [':site' => $target['siteId']]) as $row) {
            $add($row['access_code']);
        }
        if ($target['pageId'] > 0) {
            $parents = PageAccessRepository::getPageAndParentIds($target['siteId'], $target['pageId']);
            $parentSet = array_fill_keys(array_map('intval', $parents), true);
            foreach (self::all('SELECT page_id,access_code,include_children FROM sitebuilder.page_access WHERE site_id=:site',
                [':site' => $target['siteId']]) as $row) {
                if (isset($parentSet[(int)$row['page_id']])
                    && ((int)$row['page_id'] === $target['pageId'] || self::bool($row['include_children']))) {
                    $add($row['access_code']);
                }
            }
        }
        if (in_array($target['kind'], ['disk', 'folder'], true) && $target['mode'] === 'custom') {
            // Only the selected folder's ancestry; no unrelated descendants in the matrix.
            $path = [$target['folderId'] => true];
            $id = $target['folderId'];
            for ($depth = 0; $id !== $target['rootFolderId'] && $depth < 200; ++$depth) {
                $folder = \Bitrix\Disk\Folder::loadById($id);
                $id = $folder ? (int)$folder->getParentId() : 0;
                if ($id <= 0 || isset($path[$id])) { break; }
                $path[$id] = true;
            }
            foreach (self::all('SELECT folder_id,access_code FROM sitebuilder.disk_folder_access WHERE site_id=:site AND block_id=:block',
                [':site' => $target['siteId'], ':block' => $target['blockId']]) as $row) {
                if (isset($path[(int)$row['folder_id']])) { $add($row['access_code']); }
            }
        }
        if (in_array($target['kind'], ['disk', 'folder'], true) && $target['mode'] === 'bitrix_disk') {
            // Parent ACL can be above the block root. Do not miss inherited personal assignments.
            $id = $target['folderId']; $seen = [];
            for ($depth = 0; $id > 0 && !isset($seen[$id]) && $depth < 200; ++$depth) {
                $seen[$id] = true;
                $folder = \Bitrix\Disk\Folder::loadById($id);
                $id = $folder ? (int)$folder->getParentId() : 0;
                if ($id > 0 && !isset($seen[$id])) {
                    foreach (array_keys(BitrixDiskRightsService::getDirectSnapshot($id)['byAccessCode']) as $code) { $add($code); }
                }
            }
        }
        if ($target['workgroupId'] > 0 && \Bitrix\Main\Loader::includeModule('socialnetwork') && class_exists('CSocNetUserToGroup')) {
            $result = \CSocNetUserToGroup::GetList(['ID' => 'ASC'], ['GROUP_ID' => $target['workgroupId']], false, false, ['USER_ID', 'ROLE']);
            while ($row = $result->Fetch()) {
                if (in_array((string)$row['ROLE'], ['A', 'E', 'K'], true)) { $ids[(int)$row['USER_ID']] = true; }
            }
        }
        foreach ((array)(sitebuilder_auth_config()['admin_user_ids'] ?? []) as $id) {
            if (sitebuilder_is_site_admin_user_id((int)$id)) { $ids[(int)$id] = true; }
        }
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public static function effective(array $target, int $userId): array
    {
        $global = (string)(sb_get_role($target['siteId'], 'U' . $userId) ?? '');
        $source = sitebuilder_is_site_admin_user_id($userId) ? 'Администратор Sitebuilder'
            : (sb_get_role_from_access_table($target['siteId'], 'U' . $userId) !== null
                ? 'Прямая роль сайта' : ($global !== '' ? 'Рабочая группа #' . $target['workgroupId'] : 'Нет роли сайта'));
        if (AccessUserIdentity::isPortalAdmin($userId)) {
            $global = 'PORTAL_ADMIN';
            $source = 'Администратор Битрикс';
        }
        if ($target['kind'] === 'site') {
            return ['role' => $global ?: 'NONE', 'source' => $source];
        }
        $page = PageAccessService::getPageAccessInfo($target['siteId'], $target['pageId'], $userId);
        if ($target['kind'] === 'page') {
            return ['role' => $page['canEdit'] ? 'EDITOR' : ($page['canView'] ? 'VIEWER' : 'NONE'),
                'diskRole' => $page['canDiskEdit'] ? 'EDITOR' : ($page['canDiskView'] ? 'VIEWER' : 'NONE'),
                'source' => PageAccessService::isPageDenied($target['siteId'], $target['pageId'], $userId)
                    ? 'Явный запрет страницы или её родителя (включая Диск)'
                    : $source . '; также учитываются правила страницы и её родителей'];
        }
        $context = new DiskContext($target['siteId'], $target['pageId'], $target['blockId'], $userId);
        $permissions = DiskPermissionService::resolve($context, $target['settings'], $target['folderId'], $target['rootFolderId']);
        $nativeRoles = ['disk_access_full' => 'FULL', 'disk_access_edit' => 'EDITOR', 'disk_access_add' => 'ADD', 'disk_access_read' => 'VIEWER'];
        $effectiveRole = $nativeRoles[$permissions['effectiveTaskName'] ?? ''] ?? ($permissions['canDelete'] || $permissions['canUpload'] ? 'EDITOR' : 'VIEWER');
        return ['role' => !$permissions['canView'] ? 'NONE' : $effectiveRole,
            'source' => !empty($permissions['folderRuleId'])
                ? 'Правило папки #' . $permissions['folderRuleId']
                : (($permissions['accessSource'] ?? '') === 'bitrix_disk_acl' ? 'Штатный ACL Битрикс.Диска' : $source),
            'permissions' => $permissions,
            'warning' => !$page['canView'] ? 'Не может открыть страницу с диском. Проверьте запрет и право просмотра страницы.'
                : (!$page['canDiskView'] ? 'Нет права на Диск страницы: одного назначения папки недостаточно.' : '')];
    }

    public static function view(array $input, int $actor): array
    {
        $target = self::target($input, $actor);
        $direct = self::direct($target);
        $ids = self::candidateIds($target, $direct);
        $offset = max(0, (int)($input['offset'] ?? 0));
        $extra = array_slice(array_values(array_filter(array_map('intval', (array)($input['userIds'] ?? [])),
            static fn(int $id): bool => $id > 0)), 0, self::BATCH_SIZE);
        $selected = $extra ?: array_slice($ids, $offset, 100);
        $profiles = AccessUserResolver::profiles($selected);
        $rows = [];
        foreach ($selected as $id) {
            $rows[] = [
                'user' => $profiles[$id] ?? ['id' => $id, 'name' => 'Пользователь #' . $id . ' (не найден)', 'active' => false],
                'assignment' => self::assignment($target, $direct['U' . $id] ?? null),
                'effective' => self::effective($target, $id),
                'protected' => AccessUserIdentity::isPortalAdmin($id) || sitebuilder_is_site_admin_user_id($id),
            ];
        }
        $note = 'Снятие прямого назначения возвращает наследование. Членство в группах Битрикса не изменяется.';
        if ($target['kind'] === 'page') {
            $note .= ' Разрешения добавляются к роли сайта. «Нет доступа» закрывает страницу и её Диск; администраторы сохраняют доступ. Ближайшее явное правило страницы определяет запрет. Флаг «Дочерние» относится и к странице, и к её диску.';
            if (empty($target['pageDenyAvailable'])) { $note .= ' Для нового запрета примените миграцию 20261014_011_page_access_deny.sql. Остальные права работают без неё.'; }
        } elseif ($target['mode'] === 'inherit_site') {
            $note = 'Диск наследует доступ. Меняйте роль сайта или «Диск страницы» в правах страницы. Индивидуальный режим включается в настройках блока; здесь режим не переключается.';
        } elseif ($target['mode'] === 'bitrix_disk') {
            $note = 'Штатные права Битрикс.Диска. Меняются только выбранные пользователи; группы и остальные назначения сохраняются. Изменение физической папки действует во всех местах её использования, в том числе вне Sitebuilder. Для проверки сотрудника с доступом через группу добавьте его поиском.';
            if (!$target['editable']) { $note .= ' Изменять штатные права здесь может только администратор Битрикс.'; }
        } elseif ($target['mode'] === 'custom') {
            $note .= ' Правило действует на вложенные папки до более близкого правила. Администраторы сайта сохраняют доступ. Сначала нужен доступ к диску страницы.';
        }
        return ['target' => $target, 'revision' => self::revision($target, $direct), 'rows' => $rows,
            'total' => count($ids), 'offset' => $offset, 'hasMore' => $offset + 100 < count($ids), 'note' => $note];
    }

    public static function normalizeChanges(array $target, array $changes, array $direct): array
    {
        if (!$changes || count($changes) > self::BATCH_SIZE) { throw new AccessAdminException('CHANGE_BATCH_LIMIT'); }
        $result = [];
        $allowed = $target['kind'] === 'site' ? ['INHERIT', 'VIEWER', 'EDITOR', 'ADMIN', 'OWNER']
            : ($target['kind'] === 'page' ? ['INHERIT', 'VIEWER', 'EDITOR', ...(empty($target['pageDenyAvailable']) ? [] : ['DENY'])]
                : (($target['mode'] ?? '') === 'bitrix_disk' ? ['INHERIT', 'DENY', 'VIEWER', 'ADD', 'EDITOR', 'FULL'] : ['INHERIT', 'DENY', 'VIEWER', 'EDITOR']));
        foreach ($changes as $index => $change) {
            if (!is_array($change)) { throw new AccessAdminException('INVALID_CHANGE_USER', ['row' => $index + 1]); }
            $id = $change['userId'] ?? 0;
            $error = ['row' => $index + 1, 'userId' => $id];
            if (!is_int($id) || $id <= 0 || $id > 2147483647 || isset($result[$id])) {
                throw new AccessAdminException('INVALID_CHANGE_USER', $error);
            }
            $role = $change['role'] ?? '';
            if (!is_string($role) || !in_array($role, $allowed, true)) {
                throw new AccessAdminException('INVALID_CHANGE_ROLE', $error);
            }
            $record = ['id' => $id, 'access_code' => 'U' . $id, 'role' => $role];
            if ($target['kind'] === 'page') {
                $old = self::assignment($target, $direct['U' . $id] ?? null);
                $diskRole = $change['diskRole'] ?? $old['diskRole'];
                $children = $change['includeChildren'] ?? $old['includeChildren'];
                if (!in_array($diskRole, ['INHERIT', 'VIEWER', 'EDITOR'], true) || !is_bool($children)) {
                    throw new AccessAdminException('INVALID_PAGE_CHANGE', $error);
                }
                $record += ['deny_view' => $role === 'DENY', 'can_view' => in_array($role, ['VIEWER', 'EDITOR'], true), 'can_edit' => $role === 'EDITOR',
                    'can_disk_view' => $diskRole !== 'INHERIT', 'can_disk_edit' => $diskRole === 'EDITOR',
                    'include_children' => $children];
            }
            $record['remove'] = $role === 'INHERIT' && ($target['kind'] !== 'page' || !$record['can_disk_view']);
            $result[$id] = $record;
        }
        return $result;
    }

    private static function writeRows(array $target, array $changes, int $actor): void
    {
        $params = [':site' => $target['siteId'], ':actor' => $actor,
            ':rows' => json_encode(array_values($changes), JSON_THROW_ON_ERROR)];
        $input = 'WITH input AS (SELECT * FROM jsonb_to_recordset(CAST(:rows AS jsonb)) AS x(
            id integer,access_code text,role text,remove boolean,can_view boolean,can_edit boolean,
            can_disk_view boolean,can_disk_edit boolean,include_children boolean,deny_view boolean)) ';
        if ($target['kind'] === 'site') {
            $table = 'sitebuilder.access'; $where = 't.site_id=:site';
            $sql = "INSERT INTO $table AS t (site_id,access_code,role,created_by,updated_by,created_at,updated_at)
                SELECT :site,access_code,role,:actor,:actor,NOW(),NOW() FROM input WHERE NOT remove
                ON CONFLICT (site_id,access_code) DO UPDATE SET role=EXCLUDED.role,updated_by=EXCLUDED.updated_by,updated_at=NOW()
                WHERE t.role IS DISTINCT FROM EXCLUDED.role";
        } elseif ($target['kind'] === 'page') {
            $table = 'sitebuilder.page_access'; $where = 't.site_id=:site AND t.page_id=:page';
            $params[':page'] = $target['pageId'];
            $deny = !empty($target['pageDenyAvailable']);
            $denyColumn = $deny ? ',deny_view' : '';
            $denyUpdate = $deny ? ',deny_view=EXCLUDED.deny_view' : '';
            $denyOld = $deny ? ',t.deny_view' : '';
            $denyNew = $deny ? ',EXCLUDED.deny_view' : '';
            $sql = "INSERT INTO $table AS t (site_id,page_id,access_code,can_view,can_edit,can_disk_view,can_disk_edit,include_children$denyColumn,created_by,created_at,updated_at)
                SELECT :site,:page,access_code,can_view,can_edit,can_disk_view,can_disk_edit,include_children$denyColumn,:actor,NOW(),NOW() FROM input WHERE NOT remove
                ON CONFLICT (site_id,page_id,access_code) DO UPDATE SET can_view=EXCLUDED.can_view,can_edit=EXCLUDED.can_edit,
                can_disk_view=EXCLUDED.can_disk_view,can_disk_edit=EXCLUDED.can_disk_edit,include_children=EXCLUDED.include_children$denyUpdate,updated_at=NOW()
                WHERE (t.can_view,t.can_edit,t.can_disk_view,t.can_disk_edit,t.include_children$denyOld) IS DISTINCT FROM
                (EXCLUDED.can_view,EXCLUDED.can_edit,EXCLUDED.can_disk_view,EXCLUDED.can_disk_edit,EXCLUDED.include_children$denyNew)";
        } else {
            $table = 'sitebuilder.disk_folder_access'; $where = 't.site_id=:site AND t.block_id=:block AND t.folder_id=:folder';
            $params[':block'] = $target['blockId']; $params[':folder'] = $target['folderId'];
            $sql = "INSERT INTO $table AS t (site_id,block_id,folder_id,access_code,role,created_by,updated_by,created_at,updated_at)
                SELECT :site,:block,:folder,access_code,role,:actor,:actor,NOW(),NOW() FROM input WHERE NOT remove
                ON CONFLICT (block_id,folder_id,access_code) DO UPDATE SET role=EXCLUDED.role,updated_by=EXCLUDED.updated_by,updated_at=NOW()
                WHERE t.role IS DISTINCT FROM EXCLUDED.role";
        }
        // At most two ACL writes per packet, irrespective of user count.
        $stmt = sb_db()->prepare($input . $sql); $stmt->execute($params);
        unset($params[':actor']);
        $stmt = sb_db()->prepare($input . "DELETE FROM $table t USING input i WHERE $where AND t.access_code=i.access_code AND i.remove");
        $stmt->execute($params);
    }

    public static function apply(array $input, int $actor): array
    {
        $requestId = (string)($input['requestId'] ?? '');
        $expected = (string)($input['revision'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $requestId) || !preg_match('/^[a-f0-9]{64}$/', $expected)) {
            throw new AccessAdminException('REVISION_REQUIRED');
        }
        $target = self::target($input, $actor);
        if (!$target['editable']) { throw new AccessAdminException('MODE_READ_ONLY'); }
        if ($target['mode'] === 'bitrix_disk') { return AccessNativeDiskService::apply($input, $actor, $target); }
        $hash = hash('sha256', json_encode([$actor, $input], JSON_THROW_ON_ERROR));
        $pdo = sb_db();
        $started = sb_db_transaction_scope_begin();
        try {
            $pdo->exec("SET LOCAL lock_timeout='5s'");
            RequestLockService::lockMutation($target['kind'] === 'site' ? 'site.accessSet' : 'pageAccess.save', $target);
            sb_access_lock_site($pdo, $target['siteId']);
            $target = self::target($input, $actor); // Revalidate context after acquiring lifecycle/access locks.
            if (!$target['editable']) { throw new AccessAdminException('MODE_READ_ONLY'); }
            $previous = self::all("SELECT details_json FROM sitebuilder.audit_log WHERE request_id=:request
                AND action='accessAdmin.apply' AND site_id=:site ORDER BY id DESC LIMIT 1",
                [':request' => $requestId, ':site' => $target['siteId']])[0] ?? null;
            if ($previous) {
                $details = sb_json_decode_assoc($previous['details_json']);
                if (!hash_equals((string)($details['hash'] ?? ''), $hash)) { throw new AccessAdminException('REQUEST_ID_REUSED', [], 409); }
                sb_db_transaction_scope_commit($started);
                return $details['receipt'] + ['replayed' => true];
            }
            $direct = self::direct($target);
            if (!hash_equals(self::revision($target, $direct), $expected)) {
                throw new AccessAdminException('ACCESS_VERSION_CONFLICT', [], 409);
            }
            $changes = self::normalizeChanges($target, (array)($input['changes'] ?? []), $direct);
            $profiles = AccessUserResolver::profiles(array_keys($changes));
            $guest = (int)(sitebuilder_auth_config()['guest_user_id'] ?? 0);
            $errors = [];
            foreach ($changes as $id => $change) {
                if (!$change['remove'] && (empty($profiles[$id]['active']) || $id === $guest)) {
                    $errors[] = ['userId' => $id, 'name' => $profiles[$id]['name'] ?? ('ID ' . $id),
                        'error' => $id === $guest ? 'GUEST_ASSIGNMENT_FORBIDDEN' : 'USER_NOT_ACTIVE'];
                }
            }
            if ($errors) { throw new AccessAdminException('USER_VALIDATION_FAILED', ['users' => $errors]); }
            if ($target['kind'] === 'site') {
                $owners = array_filter($direct, static fn(array $r): bool => $r['role'] === 'OWNER');
                foreach ($changes as $change) {
                    unset($owners[$change['access_code']]);
                    if ($change['role'] === 'OWNER') { $owners[$change['access_code']] = $change; }
                }
                $hadOwner = count(array_filter($direct, static fn(array $r): bool => $r['role'] === 'OWNER')) > 0;
                if ($hadOwner && !$owners) { throw new AccessAdminException('LAST_OWNER_PROTECTED'); }
            }
            self::writeRows($target, $changes, $actor);
            PageAccessPolicy::resetCache();
            $newDirect = self::direct($target);
            $receipt = ['saved' => count($changes), 'userIds' => array_keys($changes),
                'revision' => self::revision($target, $newDirect), 'requestId' => $requestId];
            $auditRows = [];
            foreach ($changes as $id => $change) {
                $auditRows[] = ['userId' => $id, 'name' => $profiles[$id]['name'] ?? ('ID ' . $id),
                    'before' => $direct['U' . $id] ?? null, 'after' => $newDirect['U' . $id] ?? null];
            }
            // Audit and local ACL share the same transaction. A failed audit rolls the packet back.
            $stmt = $pdo->prepare("INSERT INTO sitebuilder.audit_log
                (request_id,site_id,actor_user_id,actor_access_code,action,entity_type,entity_id,page_id,details_json)
                VALUES (:request,:site,:actor,:code,'accessAdmin.apply',:kind,:entity,:page,CAST(:details AS jsonb))");
            $stmt->execute([':request' => $requestId, ':site' => $target['siteId'], ':actor' => $actor, ':code' => 'U' . $actor,
                ':kind' => $target['kind'], ':entity' => $target['folderId'] ?: ($target['pageId'] ?: $target['siteId']),
                ':page' => $target['pageId'] ?: null,
                ':details' => json_encode(['hash' => $hash, 'target' => $target, 'receipt' => $receipt, 'users' => $auditRows], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            sb_db_transaction_scope_commit($started);
            return $receipt;
        } catch (Throwable $e) {
            sb_db_transaction_scope_rollback($started);
            throw $e;
        }
    }
}
