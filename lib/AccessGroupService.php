<?php

declare(strict_types=1);

require_once __DIR__ . '/AccessGroupPolicy.php';
require_once __DIR__ . '/AccessUserIdentity.php';
require_once __DIR__ . '/AccessUserResolver.php';
require_once __DIR__ . '/AccessAdminService.php';

/** Group management, separate from authorization. Every mutation has a version and replay receipt. */
final class AccessGroupService
{
    private static function all(string $sql, array $params = []): array
    {
        $stmt = sb_db()->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function handle(string $action, array $input, int $actor): array
    {
        if ($actor <= 0 || (!AccessUserIdentity::isPortalAdmin($actor) && !sitebuilder_is_site_admin_user_id($actor))
            || $actor === (int)(sitebuilder_auth_config()['guest_user_id'] ?? 0)) {
            throw new AccessAdminException('ACCESS_DENIED', [], 403);
        }
        if (!AccessGroupPolicy::available()) throw new AccessAdminException('GROUP_MIGRATION_REQUIRED', [], 409);
        return match ($action) {
            'group.list' => self::listing($input),
            'group.view' => self::view($input),
            'group.history' => self::history($input),
            'group.target' => ['target'=>AccessAdminService::target((array)($input['target']??[]),$actor)],
            'group.create', 'group.rename', 'group.delete', 'group.members.add', 'group.members.remove',
            'group.rule.put', 'group.rule.remove' => self::mutate($action, $input, $actor),
            default => throw new AccessAdminException('UNKNOWN_ACTION'),
        };
    }

    private static function listing(array $input): array
    {
        $query = trim((string)($input['query'] ?? ''));
        if (mb_strlen($query) > 160) throw new AccessAdminException('INVALID_GROUP_NAME');
        $offset = max(0, min(100000, (int)($input['offset'] ?? 0)));
        $params = [':q' => '%' . str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $query) . '%'];
        $rows = self::all("SELECT g.*,
            (SELECT count(*) FROM sitebuilder.access_group_member m WHERE m.group_id=g.id) AS members_count,
            (SELECT count(*) FROM sitebuilder.access_group_rule r WHERE r.group_id=g.id) AS rules_count
            FROM sitebuilder.access_group g WHERE g.name ILIKE :q ORDER BY lower(g.name),g.id
            LIMIT 101 OFFSET $offset", $params);
        return ['groups'=>array_slice($rows,0,100),'hasMore'=>count($rows)>100,'offset'=>$offset];
    }

    private static function group(int $id, bool $lock = false): array
    {
        $row = self::all('SELECT * FROM sitebuilder.access_group WHERE id=:id' . ($lock ? ' FOR UPDATE' : ''), [':id'=>$id])[0] ?? null;
        if (!$row) throw new AccessAdminException('GROUP_NOT_FOUND', ['groupId'=>$id], 404);
        return $row;
    }

    private static function view(array $input): array
    {
        $id = (int)($input['groupId'] ?? 0); $group = self::group($id);
        $offset = max(0,min(1000000,(int)($input['offset'] ?? 0)));
        $members = self::all("SELECT user_id FROM sitebuilder.access_group_member WHERE group_id=:id
            ORDER BY user_id LIMIT 101 OFFSET $offset", [':id'=>$id]);
        $profiles = AccessUserResolver::profiles(array_column(array_slice($members,0,100),'user_id'));
        $rows = [];
        foreach (array_slice($members,0,100) as $member) {
            $uid = (int)$member['user_id'];
            $rows[] = $profiles[$uid] ?? ['id'=>$uid,'name'=>'Пользователь #'.$uid.' (не найден)','active'=>false,'login'=>''];
        }
        $count = self::all('SELECT count(*) AS n FROM sitebuilder.access_group_member WHERE group_id=:id', [':id'=>$id]);
        return ['group'=>$group, 'members'=>$rows,'memberCount'=>(int)$count[0]['n'],'offset'=>$offset,
            'hasMore'=>count($members)>100,
            'rules'=>self::all('SELECT * FROM sitebuilder.access_group_rule WHERE group_id=:id ORDER BY site_id,kind,title,id', [':id'=>$id])];
    }

    private static function history(array $input): array
    {
        $id = (int)($input['groupId'] ?? 0);
        if ($id <= 0) throw new AccessAdminException('GROUP_NOT_FOUND', [], 404);
        $offset = max(0,min(100000,(int)($input['offset'] ?? 0)));
        $rows = self::all("SELECT id,actor_user_id,action,details_json,created_at FROM sitebuilder.audit_log
            WHERE entity_type='access_group' AND entity_id=:id AND action LIKE 'accessGroup.%'
            ORDER BY id DESC LIMIT 51 OFFSET $offset", [':id'=>$id]);
        foreach ($rows as &$row) { $row['details'] = sb_json_decode_assoc($row['details_json']); unset($row['details_json']); }
        unset($row);
        return ['events'=>array_slice($rows,0,50),'hasMore'=>count($rows)>50,'offset'=>$offset];
    }

    private static function name(mixed $name): string
    {
        if (!is_string($name) || !mb_check_encoding($name,'UTF-8')) throw new AccessAdminException('INVALID_GROUP_NAME');
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160 || preg_match('/[\x00-\x1f\x7f]/u',$name)) throw new AccessAdminException('INVALID_GROUP_NAME');
        return $name;
    }

    /** No ownership/admin roles can be assigned via group capability flags. */
    public static function rule(array $input, int $actor): array
    {
        $target = AccessAdminService::target((array)($input['target'] ?? []), $actor);
        $disk = in_array($target['kind'], ['disk','folder'], true);
        foreach (['canView','canEdit','canDelete','includeChildren','openPage'] as $key) {
            if (!is_bool($input[$key] ?? null)) throw new AccessAdminException('INVALID_GROUP_RULE', ['field'=>$key]);
        }
        if (!$input['canView'] || (!$disk && ($input['canDelete'] || $input['openPage']))) {
            throw new AccessAdminException('INVALID_GROUP_RULE');
        }
        return ['site_id'=>$target['siteId'],'kind'=>$target['kind'],'page_id'=>$target['pageId'],
            'block_id'=>$target['blockId'],'folder_id'=>$target['folderId'],
            'root_folder_id'=>$disk ? $target['rootFolderId'] : 0,'permission_mode'=>$target['mode'],
            'can_view'=>true,'can_edit'=>$input['canEdit'],'can_delete'=>$input['canDelete'],
            'include_children'=>$input['includeChildren'],'open_page'=>$input['openPage'],'title'=>$target['title']];
    }

    private static function mutate(string $action, array $input, int $actor): array
    {
        $request = (string)($input['requestId'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D',$request)) throw new AccessAdminException('REVISION_REQUIRED');
        $hash = hash('sha256',json_encode([$action,$input,$actor], JSON_THROW_ON_ERROR));
        $pdo = sb_db();
        if ($pdo->inTransaction()) throw new AccessAdminException('GROUP_NESTED_TRANSACTION', [], 409);
        $pdo->beginTransaction();
        $id = (int)($input['groupId'] ?? 0);
        try {
            $pdo->exec("SET LOCAL lock_timeout='5s'");
            // Serializes a replay with its original request, including group creation/deletion.
            $lock = $pdo->prepare('SELECT pg_advisory_xact_lock(761340,:key)');
            $lock->execute([':key'=>(int)(hexdec(substr($request,0,7)))]);
            $previous = self::all("SELECT details_json FROM sitebuilder.audit_log
                WHERE request_id=:request AND actor_user_id=:actor AND action LIKE 'accessGroup.%' ORDER BY id DESC LIMIT 1",
                [':request'=>$request,':actor'=>$actor])[0] ?? null;
            if ($previous) {
                $stored = sb_json_decode_assoc($previous['details_json']);
                if (!hash_equals($stored['hash'] ?? '',$hash)) throw new AccessAdminException('REQUEST_ID_REUSED', [], 409);
                $pdo->commit(); return $stored['receipt'] + ['replayed'=>true];
            }
            $before = []; $after = []; $saved = 0;
            if ($action === 'group.create') {
                $group = self::all('INSERT INTO sitebuilder.access_group(name,created_by) VALUES(:name,:actor) RETURNING *',
                    [':name'=>self::name($input['name'] ?? null),':actor'=>$actor])[0];
                $id = (int)$group['id']; $after = $group; $saved = 1;
            } else {
                $group = self::group($id, true);
                if (!is_int($input['version'] ?? null) || $input['version'] !== (int)$group['version']) {
                    throw new AccessAdminException('GROUP_VERSION_CONFLICT', ['groupId'=>$id,'currentVersion'=>(int)$group['version']], 409);
                }
                if ($action === 'group.rename') {
                    $before = ['name'=>$group['name']]; $after=['name'=>self::name($input['name'] ?? null)];
                    self::all('UPDATE sitebuilder.access_group SET name=:name WHERE id=:id RETURNING id', [':name'=>$after['name'],':id'=>$id]);
                    $saved = 1;
                } elseif ($action === 'group.delete') {
                    $before = ['group'=>$group,
                        'members'=>self::all('SELECT user_id FROM sitebuilder.access_group_member WHERE group_id=:id', [':id'=>$id]),
                        'rules'=>self::all('SELECT * FROM sitebuilder.access_group_rule WHERE group_id=:id', [':id'=>$id])];
                    self::all('DELETE FROM sitebuilder.access_group WHERE id=:id RETURNING id', [':id'=>$id]);
                    $saved = 1;
                } elseif (in_array($action,['group.members.add','group.members.remove'],true)) {
                    $ids = $input['userIds'] ?? [];
                    if (!is_array($ids) || !$ids || count($ids)>500) throw new AccessAdminException('USER_BATCH_LIMIT');
                    foreach ($ids as $uid) if (!is_int($uid) || $uid<=0 || $uid>2147483647) throw new AccessAdminException('INVALID_CHANGE_USER');
                    $ids=array_values(array_unique($ids));
                    if ($action === 'group.members.add') {
                        $profiles=AccessUserResolver::profiles($ids); $errors=[];
                        foreach ($ids as $uid) {
                            if (empty($profiles[$uid]['active']) || $uid===(int)(sitebuilder_auth_config()['guest_user_id']??0)) {
                                $errors[]=['userId'=>$uid,'name'=>$profiles[$uid]['name']??('ID '.$uid),'error'=>'USER_NOT_ACTIVE_OR_GUEST'];
                            }
                        }
                        if ($errors) throw new AccessAdminException('USER_VALIDATION_FAILED',['users'=>$errors,'groupId'=>$id]);
                        $changed=self::all('INSERT INTO sitebuilder.access_group_member(group_id,user_id,added_by)
                            SELECT :id,value::bigint,:actor FROM jsonb_array_elements_text(CAST(:users AS jsonb))
                            ON CONFLICT DO NOTHING RETURNING user_id', [':id'=>$id,':actor'=>$actor,':users'=>json_encode($ids)]);
                        $after=['userIds'=>array_map('intval',array_column($changed,'user_id'))];
                    } else {
                        $changed=self::all('DELETE FROM sitebuilder.access_group_member WHERE group_id=:id
                            AND user_id IN (SELECT value::bigint FROM jsonb_array_elements_text(CAST(:users AS jsonb))) RETURNING user_id',
                            [':id'=>$id,':users'=>json_encode($ids)]);
                        $before=['userIds'=>array_map('intval',array_column($changed,'user_id'))];
                    }
                    $saved=count($changed);
                } elseif ($action==='group.rule.put') {
                    $rule=self::rule($input,$actor);
                    $before=self::all('SELECT * FROM sitebuilder.access_group_rule WHERE group_id=:g AND site_id=:s
                        AND kind=:k AND page_id=:p AND block_id=:b AND folder_id=:f',
                        [':g'=>$id,':s'=>$rule['site_id'],':k'=>$rule['kind'],':p'=>$rule['page_id'],':b'=>$rule['block_id'],':f'=>$rule['folder_id']]);
                    $count=self::all('SELECT count(*) AS n FROM sitebuilder.access_group_rule WHERE group_id=:id',[':id'=>$id]);
                    if (!$before && (int)$count[0]['n']>=1000) throw new AccessAdminException('GROUP_RULE_LIMIT');
                    $after=self::all('INSERT INTO sitebuilder.access_group_rule
                        (group_id,site_id,kind,page_id,block_id,folder_id,root_folder_id,permission_mode,
                         can_view,can_edit,can_delete,include_children,open_page,title,updated_by)
                        SELECT :g,x.site_id,x.kind,x.page_id,x.block_id,x.folder_id,x.root_folder_id,x.permission_mode,
                            x.can_view,x.can_edit,x.can_delete,x.include_children,x.open_page,x.title,:actor
                        FROM jsonb_to_record(CAST(:rule AS jsonb)) AS x(site_id bigint,kind text,page_id bigint,block_id bigint,
                            folder_id bigint,root_folder_id bigint,permission_mode text,can_view boolean,can_edit boolean,
                            can_delete boolean,include_children boolean,open_page boolean,title text)
                        ON CONFLICT (group_id,site_id,kind,page_id,block_id,folder_id) DO UPDATE SET
                            root_folder_id=EXCLUDED.root_folder_id,permission_mode=EXCLUDED.permission_mode,
                            can_view=EXCLUDED.can_view,can_edit=EXCLUDED.can_edit,can_delete=EXCLUDED.can_delete,
                            include_children=EXCLUDED.include_children,open_page=EXCLUDED.open_page,title=EXCLUDED.title,
                            updated_by=EXCLUDED.updated_by,updated_at=now() RETURNING *',
                        [':g'=>$id,':actor'=>$actor,':rule'=>json_encode($rule,JSON_THROW_ON_ERROR)]);
                    $saved=1;
                } elseif ($action==='group.rule.remove') {
                    $before=self::all('DELETE FROM sitebuilder.access_group_rule WHERE id=:r AND group_id=:g RETURNING *',
                        [':r'=>(int)($input['ruleId']??0),':g'=>$id]);
                    $saved=count($before);
                }
                if ($action!=='group.delete') {
                    $group=self::all('UPDATE sitebuilder.access_group SET version=version+1,updated_at=now() WHERE id=:id RETURNING *',[':id'=>$id])[0];
                }
            }
            $receipt=['groupId'=>$id,'version'=>(int)$group['version'],'saved'=>$saved,'deleted'=>$action==='group.delete'];
            self::all("INSERT INTO sitebuilder.audit_log(request_id,actor_user_id,actor_access_code,action,entity_type,entity_id,details_json)
                VALUES(:r,:a,:u,:action,'access_group',:id,CAST(:details AS jsonb)) RETURNING id", [
                    ':r'=>$request,':a'=>$actor,':u'=>'U'.$actor,':action'=>'accessGroup.'.substr($action,6),':id'=>$id,
                    ':details'=>json_encode(['hash'=>$hash,'groupName'=>$group['name'],'before'=>$before,'after'=>$after,'receipt'=>$receipt],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $pdo->commit(); AccessGroupPolicy::reset();
            return $receipt;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && ($e->errorInfo[0]??'')==='23505') {
                throw new AccessAdminException('GROUP_NAME_EXISTS', ['groupId'=>$id], 409);
            }
            throw $e;
        }
    }
}
