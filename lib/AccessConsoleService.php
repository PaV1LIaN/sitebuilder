<?php

declare(strict_types=1);

require_once __DIR__ . '/AccessGroupService.php';
require_once __DIR__ . '/PageAccessService.php';

/** Read model of ONE access system. All writes remain versioned AccessGroupService operations. */
final class AccessConsoleService
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
            'console.object' => self::object($input, $actor),
            'console.employee' => self::employee($input),
            'console.explain' => self::explain($input, $actor),
            default => throw new AccessAdminException('UNKNOWN_ACTION'),
        };
    }

    private static function offset(array $input, string $key = 'offset'): int
    {
        return max(0, min(100000, (int)($input[$key] ?? 0)));
    }

    private static function subject(array $input): array
    {
        $id = $input['userId'] ?? 0;
        if (!is_int($id) || $id <= 0 || $id > 2147483647) throw new AccessAdminException('INVALID_CHANGE_USER');
        $profile = AccessUserResolver::profiles([$id])[$id] ?? null;
        if (!$profile) throw new AccessAdminException('USER_NOT_FOUND', ['userId'=>$id], 404);
        return $profile;
    }

    private static function target(array $input, int $actor): array
    {
        return AccessAdminService::target((array)($input['target'] ?? []), $actor);
    }

    /** Validation matches AccessGroupPolicy::prime: orphaned pages / blocks never become grants. */
    private static function rules(int $site): array
    {
        $rows = self::all("SELECT r.*,g.name AS group_name,g.version AS group_version
            FROM sitebuilder.access_group_rule r JOIN sitebuilder.access_group g ON g.id=r.group_id
            WHERE r.site_id=:site AND (r.kind='site' OR EXISTS
                (SELECT 1 FROM sitebuilder.page p WHERE p.id=r.page_id AND p.site_id=r.site_id))
              AND (r.block_id=0 OR EXISTS
                (SELECT 1 FROM sitebuilder.block b WHERE b.id=r.block_id AND b.page_id=r.page_id AND b.type='disk'))
            ORDER BY g.name,g.id,r.id LIMIT 5001", [':site'=>$site]);
        if (count($rows) > 5000) throw new AccessAdminException('CONSOLE_RULE_LIMIT', [], 409);
        return $rows;
    }

    private static function compactMatch(array $match): array
    {
        $r = $match['rule'];
        return [
            'groupId'=>(int)$r['group_id'], 'groupName'=>(string)$r['group_name'],
            'version'=>(int)($r['group_version'] ?? 0), 'ruleId'=>(int)$r['id'],
            'title'=>(string)$r['title'], 'scope'=>$match['scope'], 'caps'=>$match['caps'],
            'target'=>['kind'=>$r['kind'],'siteId'=>(int)$r['site_id'],'pageId'=>(int)$r['page_id'],
                'blockId'=>(int)$r['block_id'],'folderId'=>(int)$r['folder_id']],
            'flags'=>['canView'=>true,'canEdit'=>AccessGroupPolicy::flag($r['can_edit']),
                'canDelete'=>AccessGroupPolicy::flag($r['can_delete']),
                'includeChildren'=>AccessGroupPolicy::flag($r['include_children']),
                'openPage'=>AccessGroupPolicy::flag($r['open_page'])],
        ];
    }

    private static function object(array $input, int $actor): array
    {
        $target = self::target($input,$actor);
        $matches = AccessGroupPolicy::matching($target,self::rules($target['siteId']));
        $offset = self::offset($input);
        // This section is deliberately ONLY direct legacy entries, not an effective user matrix.
        $direct = AccessAdminService::direct($target);
        $legacyOffset = self::offset($input,'legacyOffset');
        $legacyRows = array_slice($direct,$legacyOffset,100,true);
        $ids = [];
        foreach ($legacyRows as $code=>$row) if (preg_match('/^U([1-9][0-9]*)$/D',(string)$code,$m)) $ids[]=(int)$m[1];
        $profiles = AccessUserResolver::profiles($ids);
        $legacy = [];
        foreach ($legacyRows as $code=>$row) {
            $id = preg_match('/^U([1-9][0-9]*)$/D',(string)$code,$m) ? (int)$m[1] : 0;
            $legacy[] = ['code'=>$code,'userId'=>$id,'name'=>$profiles[$id]['name'] ?? $code,
                'assignment'=>AccessAdminService::assignment($target,$row)];
        }
        return ['target'=>$target,'rules'=>array_map([self::class,'compactMatch'],array_slice($matches,$offset,100)),
            'total'=>count($matches),'offset'=>$offset,'hasMore'=>$offset+100<count($matches),
            'legacy'=>$legacy,'legacyCount'=>count($direct),'legacyOffset'=>$legacyOffset,
            'legacyHasMore'=>$legacyOffset+100<count($direct)];
    }

    private static function employee(array $input): array
    {
        $user = self::subject($input); $id=$user['id']; $offset=self::offset($input);
        $groups=self::all("SELECT g.id,g.name,g.version,
            (SELECT count(*) FROM sitebuilder.access_group_rule r WHERE r.group_id=g.id) AS rules_count
            FROM sitebuilder.access_group g JOIN sitebuilder.access_group_member m ON m.group_id=g.id
            WHERE m.user_id=:user ORDER BY lower(g.name),g.id LIMIT 101 OFFSET $offset",[':user'=>$id]);
        $count=self::all('SELECT count(*) AS n FROM sitebuilder.access_group_member WHERE user_id=:user',[':user'=>$id]);
        $legacyOffset=self::offset($input,'legacyOffset');
        $deny=PageAccessPolicy::available()?'pa.deny_view':'FALSE';
        $union="SELECT 'site' AS kind,a.site_id,0::bigint AS page_id,0::bigint AS block_id,0::bigint AS folder_id,
                s.name AS title,a.role AS role,'INHERIT' AS disk_role,FALSE AS children
              FROM sitebuilder.access a JOIN sitebuilder.site s ON s.id=a.site_id WHERE a.access_code=:code
            UNION ALL SELECT 'page',pa.site_id,pa.page_id,0,0,s.name||' / '||p.title,
                CASE WHEN $deny THEN 'DENY' WHEN pa.can_edit THEN 'EDITOR' WHEN pa.can_view THEN 'VIEWER' ELSE 'INHERIT' END,
                CASE WHEN pa.can_disk_edit THEN 'EDITOR' WHEN pa.can_disk_view THEN 'VIEWER' ELSE 'INHERIT' END,pa.include_children
              FROM sitebuilder.page_access pa JOIN sitebuilder.page p ON p.id=pa.page_id AND p.site_id=pa.site_id
              JOIN sitebuilder.site s ON s.id=pa.site_id WHERE pa.access_code=:code
            UNION ALL SELECT 'folder',fa.site_id,b.page_id,fa.block_id,fa.folder_id,
                s.name||' / папка #'||fa.folder_id,fa.role,'INHERIT',TRUE
              FROM sitebuilder.disk_folder_access fa JOIN sitebuilder.site s ON s.id=fa.site_id
              JOIN sitebuilder.block b ON b.id=fa.block_id WHERE fa.access_code=:code";
        $personal=self::all("SELECT * FROM ($union) a ORDER BY site_id,kind,page_id,block_id,folder_id
            LIMIT 101 OFFSET $legacyOffset",[':code'=>'U'.$id]);
        return ['user'=>$user,'groups'=>array_slice($groups,0,100),'groupCount'=>(int)$count[0]['n'],
            'offset'=>$offset,'hasMore'=>count($groups)>100,'legacy'=>array_slice($personal,0,100),
            'legacyOffset'=>$legacyOffset,'legacyHasMore'=>count($personal)>100,
            'protected'=>AccessUserIdentity::isPortalAdmin($id)||sitebuilder_is_site_admin_user_id($id),
            'guest'=>$id===(int)(sitebuilder_auth_config()['guest_user_id']??0)];
    }

    private static function source(string $type, string $label, array $extra=[]): array
    {
        return ['type'=>$type,'label'=>$label]+$extra;
    }

    /** Returns provenance, never writes legacy ACL and never infers a denial from an unchecked flag. */
    private static function legacySources(array $target, int $user): array
    {
        $role=(string)(sb_get_role($target['siteId'],'U'.$user)??'');
        $direct=self::all('SELECT role FROM sitebuilder.access WHERE site_id=:site AND access_code=:code',
            [':site'=>$target['siteId'],':code'=>'U'.$user]);
        $kind=sitebuilder_is_site_admin_user_id($user)?'protected':($direct?'personal':'workgroup');
        $name=$kind==='protected'?'Администратор Sitebuilder':($direct?'Личная роль сайта '.$role:
            'Рабочая группа Битрикс #'.$target['workgroupId'].' → '.$role);
        $base=$role!==''?self::source($kind,$name,['role'=>$role,'siteId'=>$target['siteId']]):null;
        $rows=[];
        if ($target['pageId']>0) {
            $path=AccessGroupPolicy::pagePath($target['siteId'],$target['pageId']);
            foreach (self::all('SELECT pa.*,p.title AS page_title FROM sitebuilder.page_access pa
                JOIN sitebuilder.page p ON p.id=pa.page_id AND p.site_id=pa.site_id
                WHERE pa.site_id=:site AND pa.access_code=:code',
                [':site'=>$target['siteId'],':code'=>'U'.$user]) as $row) {
                $at=(int)$row['page_id'];
                if (isset($path[$at]) && ($at===$target['pageId']||AccessGroupPolicy::flag($row['include_children']))) $rows[]=$row;
            }
        }
        return ['role'=>$role,'base'=>$base,'pages'=>$rows];
    }

    private static function pageGrants(array $legacy, string $action, array $target): array
    {
        $rank=['VIEWER'=>1,'EDITOR'=>2,'ADMIN'=>3,'OWNER'=>4][$legacy['role']]??0;
        $need=in_array($action,['edit','manage'],true)?3:($action==='diskEdit'?2:1);
        $sources=[];
        if ($rank >= $need && $legacy['base']) $sources[]=$legacy['base'];
        $fields=match($action) {'view'=>['can_view','can_edit'],'edit'=>['can_edit'],
            'diskView'=>['can_disk_view','can_disk_edit'],'diskEdit'=>['can_disk_edit'], default=>[]};
        foreach ($legacy['pages'] as $r) {
            foreach ($fields as $key) if (AccessGroupPolicy::flag($r[$key])) {
                $sources[]=self::source('personal',((int)$r['page_id']===$target['pageId']?'Личное правило страницы: ':'Личное правило родителя: ').$r['page_title'],
                    ['pageId'=>(int)$r['page_id']]); break;
            }
        }
        return $sources;
    }

    private static function groupGrants(array $matches, string $cap): array
    {
        $out=[];
        foreach ($matches as $m) if (!empty($m['caps'][$cap])) {
            $c=self::compactMatch($m);
            $out[]=self::source('group',$c['groupName'].' — '.$c['title'],
                ['groupId'=>$c['groupId'],'ruleId'=>$c['ruleId'],'scope'=>$c['scope']]);
        }
        return $out;
    }

    private static function explain(array $input, int $actor): array
    {
        $user=self::subject($input); $id=$user['id']; $t=self::target($input,$actor);
        $matches=AccessGroupPolicy::matching($t,AccessGroupPolicy::rules($t['siteId'],$id));
        $legacy=self::legacySources($t,$id); $rank=['VIEWER'=>1,'EDITOR'=>2,'ADMIN'=>3,'OWNER'=>4][$legacy['role']]??0;
        $portal=AccessUserIdentity::isPortalAdmin($id); $protected=$portal||$rank>=3;
        $admin=self::source('protected',$portal?'Администратор Битрикс':'Администратор / владелец сайта');
        $caps=[]; $warnings=[];
        if (!$user['active']) $warnings[]='Учётная запись неактивна. Ниже показаны сохранённые разрешения, а не возможность входа.';
        $disk=in_array($t['kind'],['disk','folder'],true);
        if (!$disk) {
            if ($t['kind']==='page') {
                $effective=PageAccessService::getPageAccessInfo($t['siteId'],$t['pageId'],$id);
                $allowed=['view'=>$effective['canView'],'edit'=>$effective['canEdit'],
                    'diskView'=>$effective['canDiskView'],'diskEdit'=>$effective['canDiskEdit']];
                $denied=PageAccessService::isPageDenied($t['siteId'],$t['pageId'],$id);
                $restriction=$denied?PageAccessPolicy::restriction($t['siteId'],$t['pageId'],$id):null;
                $labels=['view'=>'Просмотр страницы','edit'=>'Редактирование страницы',
                    'diskView'=>'Доступ к компоненту Диска','diskEdit'=>'Изменения в разрешённых областях Диска'];
                $warnings[]='Разрешение компонента не открывает все папки: выберите конкретный Диск или папку для проверки файловых операций.';
            } else {
                $labels=['view'=>'Просмотр страниц сайта','edit'=>'Редактирование страниц сайта'];
                $allowed=['view'=>$protected||$rank>0||self::groupGrants($matches,'view')!==[],
                    'edit'=>$protected||self::groupGrants($matches,'edit')!==[]]; $denied=false; $restriction=null;
                $warnings[]='Это назначения на уровне сайта. Ограничения и исключения проверяйте на конкретной странице или папке.';
            }
            foreach ($labels as $key=>$label) {
                $sources=$protected?[$admin]:array_merge(self::pageGrants($legacy,$key,$t),self::groupGrants($matches,$key));
                $limits=$denied?['Явный персональный запрет страницы #'.(int)$restriction['pageId']]:[];
                $caps[]=self::cap($key,$label,(bool)$allowed[$key],$sources,$limits);
            }
        } else {
            $ctx=new DiskContext($t['siteId'],$t['pageId'],$t['blockId'],$id);
            $effective=DiskPermissionService::resolve($ctx,$t['settings'],$t['folderId'],$t['rootFolderId'],true);
            $trace=$effective['explanation']; $protected=$trace['protected'];
            $map=['canView'=>['Просмотр папки','view'],'canUpload'=>['Загрузка файлов','edit'],
                'canCreateFolder'=>['Создание папок','edit'],'canRename'=>['Переименование','edit'],
                'canEditFile'=>['Изменение файлов','edit'],'canDelete'=>['Удаление','delete'],
                'canDownload'=>['Скачивание','view'],'canTraverse'=>['Проход к разрешённой подпапке','traverse']];
            foreach ($map as $key=>[$label,$flag]) {
                $sources=$protected?[$admin]:self::groupGrants($matches,$flag); $limits=[];
                if (!$protected && $key!=='canTraverse') {
                    if ($t['mode']==='bitrix_disk' && $trace['legacyEligible'] && !empty($trace['native'][$key])) {
                        $sources[]=self::source('native','Штатные права Битрикс.Диска; доступ к компоненту по старым назначениям');
                    } elseif ($t['mode']!=='bitrix_disk' && !empty($trace['local'][$key])) {
                        if ($trace['folderRule']) $sources[]=self::source('personal',
                            'Личное правило папки #'.(int)$trace['folderRule']['folderId'].' → '.$trace['folderRule']['role']);
                        else $sources=array_merge($sources,self::pageGrants($legacy,
                            in_array($key,['canView','canDownload'],true)?'diskView':'diskEdit',$t));
                        if (!$sources) $sources[]=self::source('legacy','Старый механизм прав сайта / групп Битрикса');
                    }
                }
                if ($trace['pageDenied'] && !$protected) $limits[]='Явный персональный запрет страницы или её родителя';
                if (!$trace['pageVisible'] && !$protected) $warnings[]='Страница с Диском недоступна: разрешение папки само по себе не открывает страницу.';
                if (!$protected && $t['mode']==='custom' && ($trace['folderRule']['role']??'')==='DENY') {
                    $limits[]='Личный запрет папки #'.(int)$trace['folderRule']['folderId'];
                }
                if (!$protected && $t['mode']==='bitrix_disk') {
                    if (empty($trace['native'][$key==='canTraverse'?'canView':$key])) $limits[]='Операция не разрешена штатными правами Битрикс.Диска';
                    $warnings[]='Штатные права Битрикс.Диска — отдельное ограничение. Этот экран их не изменяет и не перечисляет все источники нативного ACL.';
                }
                if (array_key_exists($key,$trace['blockRestrictions']) && !$trace['blockRestrictions'][$key]) {
                    $limits[]='Операция выключена в настройках блока Диска';
                }
                if ($key==='canTraverse' && !empty($effective['canView'])) $limits[]='Проход без чтения не нужен: сама папка уже доступна';
                $caps[]=self::cap($key,$label,(bool)$effective[$key],$sources,$limits);
            }
        }
        return ['user'=>$user,'target'=>$t,'capabilities'=>$caps,'warnings'=>array_values(array_unique($warnings)),
            'groupRules'=>array_map([self::class,'compactMatch'],$matches)];
    }

    private static function cap(string $key,string $label,bool $allowed,array $sources,array $limits): array
    {
        return ['key'=>$key,'label'=>$label,'allowed'=>$allowed,'sources'=>$sources,'limits'=>$limits,
            'note'=>!$allowed && !$sources && !$limits?'Нет действующего источника разрешения':'' ];
    }
}
