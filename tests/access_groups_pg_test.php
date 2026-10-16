<?php
/** Isolated real PostgreSQL + test doubles for Bitrix. Never run against bx/production. */
namespace Bitrix\Main {
    class UserTable {
        public static function getList(array $options) {
            $rows=[];
            foreach ($options['filter']['@ID'] ?? [] as $id) {
                if ($id>0 && $id<10000) $rows[]=['ID'=>$id,'ACTIVE'=>$id===999?'N':'Y','LOGIN'=>'user'.$id,
                    'LAST_NAME'=>'Employee','NAME'=>(string)$id,'SECOND_NAME'=>'','EMAIL'=>'user'.$id.'@example.test'];
            }
            return new class($rows) { private int $i=0; public function __construct(private array $rows) {} public function fetch(){return $this->rows[$this->i++]??false;} };
        }
    }
}
namespace Bitrix\Disk {
    class Folder {
        public static array $parents=[100=>0,110=>100,111=>110,120=>100,200=>0,210=>200];
        public function __construct(private int $id) {}
        public static function loadById(int $id): ?self { return array_key_exists($id,self::$parents)?new self($id):null; }
        public function getId(): int {return $this->id;}
        public function getParentId(): int {return self::$parents[$this->id];}
        public function getRealObject(): self {return $this;}
        public function getName(): string {return 'Folder '.$this->id;}
        public function getChildren(...$args): array { $r=[];foreach(self::$parents as $id=>$parent)if($parent===$this->id)$r[]=new self($id);return $r; }
    }
    class Driver { public static function getInstance():self{return new self;} public function getFakeSecurityContext(int $id):int{return $id;} }
}
namespace Bitrix\Disk\Internals { class ObjectTable { const TYPE_FOLDER=2; } }
namespace {
    if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
    if(!getenv('SB_TEST_PG_DSN')){echo "SKIP groups PG: isolated DSN required\n";exit;}
    if(getenv('SB_ACCESS_TEST_ALLOW_RESET')!=='1')throw new RuntimeException('Explicit isolated reset required');
    $pdo=new PDO(getenv('SB_TEST_PG_DSN'),getenv('SB_TEST_PG_USER')?:'postgres',getenv('SB_TEST_PG_PASSWORD')?:'postgres',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if($pdo->query('SELECT current_database()')->fetchColumn()!=='sitebuilder_access_test')throw new RuntimeException('Refuse non-test database');
    $root=sys_get_temp_dir().'/sbg-pg-'.bin2hex(random_bytes(6));mkdir($root.'/local/php_interface/lib',0700,true);
    file_put_contents($root.'/local/php_interface/lib/pg_master.php','<?php');$_SERVER['DOCUMENT_ROOT']=$root;
    function getPDO():PDO{return $GLOBALS['pdo'];}
    function sitebuilder_auth_config():array{return ['guest_user_id'=>665,'admin_user_ids'=>[40]];}
    function sitebuilder_is_site_admin_user_id(int $id):bool{return $id===40;}
    class CUser { public static function GetUserGroup(int $id):array{return $id===99?[1,2]:[2];} }
    $USER=new class{public function GetID():int{return 99;}public function IsAdmin():bool{return true;}public function IsAuthorized():bool{return true;}};
    require __DIR__.'/../lib/db.php';
    function sb_get_role(int $siteId,?string $code=null):?string{
        if($code==='U40')return 'ADMIN';$s=sb_db()->prepare('SELECT role FROM sitebuilder.access WHERE site_id=:s AND access_code=:c');
        $s->execute([':s'=>$siteId,':c'=>$code]);return $s->fetchColumn()?:null;
    }
    function sb_role_rank(?string $role):int{return ['VIEWER'=>1,'EDITOR'=>2,'ADMIN'=>3,'OWNER'=>4][$role??'']??0;}
    require __DIR__.'/../components/disk/lib/DiskContext.php';
    require __DIR__.'/../lib/PageAccessService.php';
    class DiskSettingsRepository { public static string $mode='custom'; public static function getByBlockId(int $id):array{return ['permissionMode'=>self::$mode,'allowUpload'=>true,'allowCreateFolder'=>true,'allowRename'=>true,'allowDelete'=>true,'allowDownload'=>true];} }
    class DiskRootResolver { public static function resolve(DiskContext $c,array $s,bool $auto=false):int{return 100;} }
    class BlockRepository {
        public static function getDiskBlockByContext(int $s,int $p,int $b):?array{
            $st=sb_db()->prepare("SELECT b.* FROM sitebuilder.block b JOIN sitebuilder.page p ON p.id=b.page_id WHERE b.id=:b AND p.id=:p AND p.site_id=:s AND b.type='disk'");
            $st->execute([':s'=>$s,':p'=>$p,':b'=>$b]);return $st->fetch(PDO::FETCH_ASSOC)?:null;
        }
    }
    class DiskBitrixStorageAdapter {
        public function __construct(int $id){}public function isFolderInsideRoot($c,int $id,int $root):bool{return !!AccessGroupPolicy::folderPath($id,$root);}
    }
    class SiteAccessRepository {public static function getUserRole(int $s,int $u):?string{return ['VIEWER'=>'site_viewer','EDITOR'=>'site_editor','ADMIN'=>'site_admin','OWNER'=>'site_admin'][sb_get_role($s,'U'.$u)??'']??null;}}
    class FolderAccessRepository {
        const ROLE_EDITOR='EDITOR';const ROLE_VIEWER='VIEWER';public static ?array $rule=null;
        public static function resolveEffectiveRole(int $b,int $f,int $r,int $u):?array{return self::$rule;}
    }
    class BitrixDiskRightsService {
        public static bool $read=true;public static bool $write=true;
        public static function resolvePermissions($c,int $id,array $base):array{
            return array_merge($base,['canView'=>self::$read,'canDownload'=>self::$read,'canUpload'=>self::$write,'canCreateFolder'=>self::$write,
                'canRename'=>self::$write,'canEditFile'=>self::$write,'canDelete'=>self::$write,'accessSource'=>'bitrix_disk_acl']);
        }
    }
    require __DIR__.'/../components/disk/lib/DiskPermissionService.php';
    require __DIR__.'/../components/disk/lib/DiskValidator.php';
    require __DIR__.'/../lib/AccessGroupService.php';
    require __DIR__.'/../lib/AccessConsoleService.php';
    require __DIR__.'/../lib/MigrationService.php';
    $checks=0;
    function eq($expected,$actual,string $label):void{global $checks;++$checks;if($expected!==$actual)throw new RuntimeException($label.': '.var_export($actual,true));}
    function bad(callable $f,string $code):void{try{$f();}catch(Throwable $e){eq($code,$e->getMessage(),'Expected '.$code);return;}throw new RuntimeException('Missing '.$code);}
    function callGroup(string $action,array $input=[]):array{return AccessGroupService::handle('group.'.$action,$input,99);}
    function mutation(string $action,int $id,array $input=[]):array{
        $version=$id?(int)sb_db()->query('SELECT version FROM sitebuilder.access_group WHERE id='.$id)->fetchColumn():0;
        return callGroup($action,$input+['groupId'=>$id,'version'=>$version,'requestId'=>bin2hex(random_bytes(16))]);
    }
    function putRule(int $g,array $target,array $caps=[]):array{
        return mutation('rule.put',$g,['target'=>$target]+$caps+['canView'=>true,'canEdit'=>false,'canDelete'=>false,'includeChildren'=>true,'openPage'=>false]);
    }
    function perms(int $folder=110,int $user=12,string $mode='custom',int $block=50):array{
        return DiskPermissionService::resolve(new DiskContext(1,10,$block,$user),['permissionMode'=>$mode,'allowUpload'=>true,'allowCreateFolder'=>true,
            'allowRename'=>true,'allowDelete'=>true,'allowDownload'=>true],$folder,100);
    }
    function consoleRead(string $action,array $input):array{return AccessConsoleService::handle('console.'.$action,$input,99);}
    function cap(array $result,string $key):array{return array_values(array_filter($result['capabilities'],fn($r)=>$r['key']===$key))[0];}
    function explainFolder(array $target,int $user=12):array{return consoleRead('explain',['target'=>$target,'userId'=>$user]);}
    try{
        $pdo->exec('DROP SCHEMA IF EXISTS sitebuilder CASCADE; CREATE SCHEMA sitebuilder');
        $pdo->exec("CREATE TABLE sitebuilder.site(id bigint PRIMARY KEY,name text,bitrix_group_id bigint);
            CREATE TABLE sitebuilder.page(id bigint PRIMARY KEY,site_id bigint,title text,parent_id bigint);
            CREATE TABLE sitebuilder.block(id bigint PRIMARY KEY,page_id bigint,type text,props_json jsonb);
            CREATE TABLE sitebuilder.access(site_id bigint,access_code text,role text,PRIMARY KEY(site_id,access_code));
            CREATE TABLE sitebuilder.page_access(id bigserial PRIMARY KEY,site_id bigint,page_id bigint,access_code text,
                can_view boolean DEFAULT false,can_edit boolean DEFAULT false,can_disk_view boolean DEFAULT false,
                can_disk_edit boolean DEFAULT false,include_children boolean DEFAULT false,deny_view boolean DEFAULT false);
            CREATE TABLE sitebuilder.disk_folder_access(site_id bigint,block_id bigint,folder_id bigint,access_code text,role text);
            CREATE TABLE sitebuilder.audit_log(id bigserial PRIMARY KEY,request_id text,actor_user_id bigint,actor_access_code text,
                action text,entity_type text,entity_id bigint,details_json jsonb,created_at timestamptz DEFAULT now());
            INSERT INTO sitebuilder.site VALUES(1,'Test site',NULL),(2,'Other site',NULL);
            INSERT INTO sitebuilder.page VALUES(10,1,'Page',NULL),(11,1,'Child',10),(12,1,'Sibling',NULL),(20,2,'Other',NULL);
            INSERT INTO sitebuilder.block VALUES(50,10,'disk','{}'),(51,10,'disk','{}'),(60,20,'disk','{}');");
        bad(fn()=>callGroup('list'),'GROUP_MIGRATION_REQUIRED');
        $pdo->exec(file_get_contents(__DIR__.'/../migrations/20260730_008_migration_registry_and_deployment_runs.sql'));
        $st=$pdo->prepare("INSERT INTO sitebuilder.schema_migration(migration_key,stage_number,filename,checksum) VALUES(:k,:s,:f,:c)");
        foreach(MigrationService::manifest() as $entry){if($entry['stage']===25)continue;$st->execute([':k'=>$entry['key'],':s'=>$entry['stage'],':f'=>basename($entry['file']),':c'=>hash_file('sha256',$entry['file'])]);}
        foreach([22=>'20260827_011_unified_access_reconciliation',23=>'20260911_012_data_lists'] as $stage=>$key)$st->execute([':k'=>$key,':s'=>$stage,':f'=>$key.'.sql',':c'=>str_repeat('a',64)]);
        // Bootstrap must choose registry stage 13, not falsely mark the last migration as executed.
        $boot=MigrationService::bootstrap(99);eq(1,$boot['appliedCount'],'Only group migration actually executed by bootstrap');
        AccessGroupPolicy::reset();eq(true,AccessGroupPolicy::available(),'All three real tables exist after bootstrap');
        $again=MigrationService::applyPending(99);eq(0,$again['appliedCount'],'Migration rerun no-op');
        bad(fn()=>AccessGroupService::handle('group.list',[],12),'ACCESS_DENIED');
        $create=['name'=>'Supply','requestId'=>bin2hex(random_bytes(16))];
        $g=callGroup('create',$create)['groupId'];$replay=callGroup('create',$create);eq(true,$replay['replayed'],'Create replay');
        bad(fn()=>callGroup('create',['name'=>'Different']+array_diff_key($create,['name'=>true])),'REQUEST_ID_REUSED');
        bad(fn()=>mutation('create',0,['name'=>'supply']),'GROUP_NAME_EXISTS');
        bad(fn()=>mutation('create',0,['name'=>"bad\nname"]),'INVALID_GROUP_NAME');
        $list=callGroup('list');eq(1,count($list['groups']),'Only one group');
        $m=mutation('members.add',$g,['userIds'=>[12,13,12]]);eq(2,$m['saved'],'Duplicate membership deduplicated');
        eq(0,mutation('members.add',$g,['userIds'=>[12]])['saved'],'No duplicate insert');
        bad(fn()=>mutation('members.add',$g,['userIds'=>[14,999]]),'USER_VALIDATION_FAILED');
        eq(2,callGroup('view',['groupId'=>$g])['memberCount'],'Whole invalid batch rolls back');
        bad(fn()=>mutation('members.add',$g,['userIds'=>[665]]),'USER_VALIDATION_FAILED');
        bad(fn()=>callGroup('rename',['groupId'=>$g,'version'=>1,'name'=>'stale','requestId'=>bin2hex(random_bytes(16))]),'GROUP_VERSION_CONFLICT');
        $page=['kind'=>'page','siteId'=>1,'pageId'=>10];
        putRule($g,$page,['canEdit'=>true]);
        eq(true,PageAccessService::canViewPage(1,10,12),'Group grants page read');
        eq(true,PageAccessService::canEditPage(1,11,12),'Group grants inherited page edit');
        eq(false,PageAccessService::canViewPage(1,12,12),'Sibling not granted');
        eq(false,PageAccessService::canEditDisk(1,10,12),'Page edit is not disk edit');
        eq(true,PageAccessService::hasAnyPageAccess(1,12),'Group site visible in site list');
        bad(fn()=>AccessConsoleService::handle('console.employee',['userId'=>12],12),'ACCESS_DENIED');
        $info=consoleRead('employee',['userId'=>12]);eq(1,$info['groupCount'],'Console membership');eq([], $info['legacy'],'No personal rows fabricated');
        $obj=consoleRead('object',['target'=>$page]);eq(1,$obj['total'],'Object sees existing group rule');eq($g,$obj['rules'][0]['groupId'],'Same group identity');
        $childInfo=consoleRead('object',['target'=>['kind'=>'page','siteId'=>1,'pageId'=>11]]);
        eq('inherited',$childInfo['rules'][0]['scope'],'Inherited source labelled');
        eq(0,consoleRead('object',['target'=>['kind'=>'page','siteId'=>1,'pageId'=>12]])['total'],'Unrelated page not a source');
        $pageCheck=consoleRead('explain',['target'=>$page,'userId'=>12]);
        eq(true,cap($pageCheck,'view')['allowed'],'Console uses actual page view');eq(true,cap($pageCheck,'edit')['allowed'],'Console uses actual page edit');
        eq($g,cap($pageCheck,'edit')['sources'][0]['groupId'],'Exact page edit source');
        eq(false,cap($pageCheck,'diskEdit')['allowed'],'Page edit not conflated with disk');
        $folder=['kind'=>'folder','siteId'=>1,'pageId'=>10,'blockId'=>50,'folderId'=>110];
        putRule($g,$folder,['canEdit'=>true,'openPage'=>true]);
        $v=perms();eq(true,$v['canUpload'],'Group editing allows upload');eq(false,$v['canDelete'],'Edit does NOT imply delete');
        $readOnlyAuditCount=(int)$pdo->query('SELECT count(*) FROM sitebuilder.audit_log')->fetchColumn();
        $ex=explainFolder($folder);
        foreach($v as $key=>$value){if(in_array($key,['canView','canUpload','canCreateFolder','canRename','canEditFile','canDelete','canDownload','canTraverse'],true))eq($value,cap($ex,$key)['allowed'],'Explain matches resolver '.$key);}
        eq($g,cap($ex,'canUpload')['sources'][0]['groupId'],'Exact upload source');
        eq([],cap($ex,'canDelete')['sources'],'No invented delete source');
        eq($readOnlyAuditCount,(int)$pdo->query('SELECT count(*) FROM sitebuilder.audit_log')->fetchColumn(),'Explain has no write side effect');
        eq(false,$v['canManageAccess'],'Group never gets admin rights');eq(false,$v['canEditSettings'],'No settings grant');
        eq(true,perms(111)['canView'],'Allowed child');eq(false,perms(120)['canView'],'Sibling folder hidden');
        eq(false,perms(110,12,'custom',51)['canView'],'Same page other disk not granted');
        $ancestor=perms(100);eq(false,$ancestor['canView'],'Ancestor files not readable');eq(true,$ancestor['canTraverse'],'Ancestor navigable');
        $ancestorEx=explainFolder(['kind'=>'folder','siteId'=>1,'pageId'=>10,'blockId'=>50,'folderId'=>100]);
        eq(false,cap($ancestorEx,'canView')['allowed'],'Console no ancestor file access');
        eq(true,cap($ancestorEx,'canTraverse')['allowed'],'Console only ancestor passage');
        eq('traverse',cap($ancestorEx,'canTraverse')['sources'][0]['scope'],'Passage source not read source');
        eq(false,$ancestor['canDownload'],'Ancestor files cannot download');eq(false,$ancestor['canUpload'],'Ancestor no upload');
        $visible=DiskValidator::filterVisibleItems(new DiskContext(1,10,50,12),['permissionMode'=>'custom','allowDownload'=>true],
            [['entityType'=>'folder','id'=>110],['entityType'=>'folder','id'=>120],['entityType'=>'file','id'=>900]],100,100);
        eq([110],array_column($visible,'id'),'Root listing only allowed branch');
        bad(fn()=>putRule($g,['kind'=>'folder','siteId'=>2,'pageId'=>10,'blockId'=>50,'folderId'=>110]),'PAGE_NOT_IN_SITE');
        bad(fn()=>putRule($g,['kind'=>'folder','siteId'=>1,'pageId'=>10,'blockId'=>50,'folderId'=>210]),'FOLDER_OUT_OF_SCOPE');
        $g2=mutation('create',0,['name'=>'Deletion team'])['groupId'];mutation('members.add',$g2,['userIds'=>[12]]);
        putRule($g2,$folder,['canDelete'=>true,'openPage'=>true]);eq(true,perms()['canDelete'],'Other group adds delete');
        $ex=explainFolder($folder);
        eq([$g2],array_column(cap($ex,'canDelete')['sources'],'groupId'),'Only delete group named as delete source');
        eq([$g],array_column(cap($ex,'canUpload')['sources'],'groupId'),'Only edit group named as upload source');
        mutation('members.remove',$g2,['userIds'=>[12]]);eq(false,perms()['canDelete'],'Removing one group revokes only its delete');
        eq(true,perms()['canUpload'],'Other group edit remains');
        $pdo->exec("INSERT INTO sitebuilder.page_access(site_id,page_id,access_code,deny_view) VALUES(1,10,'U12',true)");PageAccessPolicy::resetCache();
        eq(false,PageAccessService::canViewPage(1,10,12),'Personal page deny outranks group');eq(false,perms()['canView'],'Deny blocks group disk');
        $ex=consoleRead('explain',['target'=>$page,'userId'=>12]);eq(false,cap($ex,'view')['allowed'],'Console honours personal deny');
        eq(true,count(cap($ex,'view')['limits'])>0,'Console explains active deny');
        $ex=explainFolder($folder);eq(false,cap($ex,'canView')['allowed'],'Folder explanation respects page deny');
        $pdo->exec('DELETE FROM sitebuilder.page_access');PageAccessPolicy::resetCache();
        FolderAccessRepository::$rule=['role'=>'DENY','folderId'=>110,'inherited'=>false];eq(false,perms()['canView'],'Personal folder deny wins');FolderAccessRepository::$rule=null;
        // Pins to root/mode and real current folder hierarchy.
        eq(false,perms(110,12,'inherit_site')['canView'],'Mode change needs explicit rule update');
        \Bitrix\Disk\Folder::$parents[110]=200;AccessGroupPolicy::reset();eq(false,perms()['canView'],'Moved-out folder does not keep scoped grant');
        \Bitrix\Disk\Folder::$parents[110]=100;AccessGroupPolicy::reset();
        DiskSettingsRepository::$mode='bitrix_disk';putRule($g,$folder,['canEdit'=>true,'openPage'=>true]);
        eq(true,perms(110,12,'bitrix_disk')['canUpload'],'Native intersection allows requested edit');
        eq(false,perms(110,12,'bitrix_disk')['canDelete'],'Native broad ACL does not manufacture group delete');
        eq(false,perms(120,12,'bitrix_disk')['canView'],'Native broad ACL does not expose sibling for group-only user');
        BitrixDiskRightsService::$read=false;BitrixDiskRightsService::$write=false;
        eq(false,perms(110,12,'bitrix_disk')['canView'],'Group never bypasses native denial');
        $nativeEx=explainFolder($folder);eq(false,cap($nativeEx,'canUpload')['allowed'],'Console respects native intersection');
        eq(true,count(cap($nativeEx,'canUpload')['limits'])>0,'Native blocker explained');
        BitrixDiskRightsService::$read=true;BitrixDiskRightsService::$write=true;
        DiskSettingsRepository::$mode='custom';putRule($g,$folder,['openPage'=>true]);
        // Refresh group rules after versioned mutations, not a stale permission cache.
        eq(false,perms()['canUpload'],'Editing removed without reload worker');
        $pdo->exec("ALTER TABLE sitebuilder.audit_log ADD CONSTRAINT reject_group_rename CHECK(action<>'accessGroup.rename') NOT VALID");
        try{mutation('rename',$g,['name'=>'Must rollback']);throw new RuntimeException('Expected audit failure');}catch(PDOException $e){eq('23514',$e->errorInfo[0],'Audit failure');}
        eq('Supply',callGroup('view',['groupId'=>$g])['group']['name'],'Audit failure rolls back name');
        $pdo->exec('ALTER TABLE sitebuilder.audit_log DROP CONSTRAINT reject_group_rename');
        $many=range(1000,1499);eq(500,mutation('members.add',$g,['userIds'=>$many])['saved'],'500 users one database packet');
        eq(true,callGroup('view',['groupId'=>$g])['hasMore'],'Member pagination');
        eq(502,callGroup('view',['groupId'=>$g])['memberCount'],'No total truncation');
        eq(0,(int)$pdo->query('SELECT count(*) FROM sitebuilder.access')->fetchColumn(),'No personal ACL materialization');
        mutation('members.add',$g,['userIds'=>[14]]);
        $pdo->exec("INSERT INTO sitebuilder.access VALUES(1,'U14','VIEWER')");
        mutation('members.remove',$g,['userIds'=>[14]]);
        eq(true,PageAccessService::canViewPage(1,10,14),'Personal permission survives member removal');
        eq(1,(int)$pdo->query("SELECT count(*) FROM sitebuilder.access WHERE access_code='U14'")->fetchColumn(),'Personal row remains unchanged');
        eq(true,PageAccessService::canEditAnyPage(1,12),'Group page editor can enter editor');
        mutation('delete',$g);eq(false,PageAccessService::canViewPage(1,10,12),'Deleted group page grant revoked');
        eq(true,PageAccessService::canViewPage(1,10,14),'Personal access survives group deletion');
        eq(0,(int)$pdo->query('SELECT count(*) FROM sitebuilder.access_group_member WHERE group_id='.$g)->fetchColumn(),'Members cascade deleted');
        eq(0,(int)$pdo->query('SELECT count(*) FROM sitebuilder.access_group_rule WHERE group_id='.$g)->fetchColumn(),'Rules cascade deleted');
        eq(true,count(callGroup('history',['groupId'=>$g])['events'])>0,'Audit remains after group deletion');
        eq(1,count(callGroup('list')['groups']),'Other group preserved');
        $personal=consoleRead('employee',['userId'=>14]);eq(0,$personal['groupCount'],'Console sees removed membership');
        eq('VIEWER',$personal['legacy'][0]['role'],'Legacy record preserved and displayed');
        echo "Access group PostgreSQL checks passed ($checks checks)\n";
    } finally {
        if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP SCHEMA IF EXISTS sitebuilder CASCADE');
        unlink($root.'/local/php_interface/lib/pg_master.php');rmdir($root.'/local/php_interface/lib');rmdir($root.'/local/php_interface');rmdir($root.'/local');rmdir($root);
    }
}
