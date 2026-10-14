<?php
// Isolated PostgreSQL integration; native Bitrix API and its transaction are test doubles.
namespace Bitrix\Disk {
    class Folder {
        public function __construct(private int $id) {}
        public static function loadById(int $id): ?self { return $id === 100 ? new self($id) : null; }
        public function getId(): int { return $this->id; }
        public function getParentId(): int { return 0; }
        public function getRealObject(): self { return $this; }
        public function getName(): string { return 'Test folder'; }
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
    class UserTable {
        public static array $inactive = [];
        public static function getList(array $options) {
            $rows = [];
            foreach ($options['filter']['@ID'] as $id) {
                if ($id > 0 && $id < 1000) $rows[] = ['ID'=>$id,'ACTIVE'=>in_array($id,self::$inactive,true)?'N':'Y',
                    'LOGIN'=>'user'.$id,'EMAIL'=>'user'.$id.'@example.test','LAST_NAME'=>'Employee','NAME'=>(string)$id,'SECOND_NAME'=>''];
            }
            return new class($rows) {
                private int $i=0;
                public function __construct(private array $rows) {}
                public function fetch() { return $this->rows[$this->i++] ?? false; }
            };
        }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    if (!getenv('SB_TEST_PG_DSN')) { echo "SKIP native journal PostgreSQL: isolated DSN required\n"; exit; }
    if (getenv('SB_ACCESS_TEST_ALLOW_RESET') !== '1') throw new RuntimeException('Explicit isolated DB reset permission required');
    $pdo = new PDO(getenv('SB_TEST_PG_DSN'),getenv('SB_TEST_PG_USER')?:'postgres',getenv('SB_TEST_PG_PASSWORD')?:'postgres',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'sitebuilder_access_test') throw new RuntimeException('Refusing non-test database');
    $root = sys_get_temp_dir().'/sb-native-pg-'.bin2hex(random_bytes(6));
    mkdir($root.'/local/php_interface/lib',0700,true);
    file_put_contents($root.'/local/php_interface/lib/pg_master.php','<?php'); $_SERVER['DOCUMENT_ROOT']=$root;
    function getPDO(): PDO { return $GLOBALS['pdo']; }
    function sitebuilder_auth_config(): array { return ['guest_user_id'=>665,'admin_user_ids'=>[]]; }
    function sitebuilder_is_site_admin_user_id(int $id): bool { return false; }
    class CUser { public static function GetUserGroup(int $id): array { return $id===99 ? [1] : [2,7]; } }
    class DiskValidator {
        public static function assertBlockBelongsToContext($context): void {
            if ($context->siteId!==1 || $context->pageId!==10 || $context->blockId!==50) throw new RuntimeException('BLOCK_NOT_IN_PAGE');
        }
        public static function assertFolderInsideRoot(int $folder,int $root,$context): void {
            if ($folder!==$root) throw new RuntimeException('FOLDER_NOT_IN_ROOT');
        }
    }
    class DiskSettingsRepository { public static function getByBlockId(int $id): array { return ['permissionMode'=>'bitrix_disk']; } }
    class DiskRootResolver { public static function resolve($context,array $settings,bool $create): int { return 100; } }
    class NativeManager {
        public const TASK_READ='read', TASK_ADD='add', TASK_EDIT='edit', TASK_FULL='full';
        public array $rights=[['ACCESS_CODE'=>'G7','TASK_ID'=>144,'NEGATIVE'=>false]];
        public int $appends=0;
        public bool $failAppend=false;
        public function getTaskIdByName(string $name): int { return ['read'=>37,'add'=>61,'edit'=>88,'full'=>144][$name]??0; }
        public function getSpecificRights($folder): array { return $this->rights; }
        public function revokeByAccessCodes($folder,array $codes): bool {
            $this->rights=array_values(array_filter($this->rights,fn($r)=>!in_array($r['ACCESS_CODE'],$codes,true))); return true;
        }
        public function append($folder,array $rights): bool {
            ++$this->appends; array_push($this->rights,...$rights); return !$this->failAppend;
        }
    }
    class NativeConnection {
        private ?array $backup=null;
        public bool $loseCommit=false;
        public function __construct(private NativeManager $manager) {}
        public function startTransaction(): void { $this->backup=$this->manager->rights; }
        public function commitTransaction(): void {
            $this->backup=null; if ($this->loseCommit) throw new RuntimeException('Lost native COMMIT reply');
        }
        public function rollbackTransaction(): void {
            if ($this->backup!==null) { $this->manager->rights=$this->backup; $this->backup=null; }
        }
    }
    $manager=new NativeManager(); $native=new NativeConnection($manager);
    \Bitrix\Disk\Driver::$manager=$manager; \Bitrix\Main\Application::$connection=$native;
    require __DIR__.'/../lib/db.php';
    require __DIR__.'/../lib/storage_db_extra.php';
    require __DIR__.'/../lib/RequestLockService.php';
    require __DIR__.'/../lib/AccessUserIdentity.php';
    require __DIR__.'/../lib/AccessUserResolver.php';
    require __DIR__.'/../components/disk/lib/DiskContext.php';
    require __DIR__.'/../components/disk/lib/BitrixDiskRightsService.php';
    require __DIR__.'/../lib/AccessAdminService.php';
    $pdo->exec("DROP SCHEMA IF EXISTS sitebuilder CASCADE; CREATE SCHEMA sitebuilder;
        CREATE TABLE sitebuilder.site(id bigint PRIMARY KEY,name text,bitrix_group_id bigint);
        CREATE TABLE sitebuilder.page(id bigint PRIMARY KEY,site_id bigint,title text,parent_id bigint);
        CREATE TABLE sitebuilder.block(id bigint PRIMARY KEY,page_id bigint,type text,props_json jsonb);
        CREATE TABLE sitebuilder.page_access(id bigserial PRIMARY KEY,deny_view boolean DEFAULT false);
        CREATE TABLE sitebuilder.audit_log(id bigserial PRIMARY KEY,request_id text,site_id bigint,actor_user_id bigint,actor_access_code text,
            action text,entity_type text,entity_id bigint,page_id bigint,details_json jsonb,created_at timestamptz DEFAULT now());
        INSERT INTO sitebuilder.site VALUES(1,'Test',NULL);
        INSERT INTO sitebuilder.page VALUES(10,1,'Page',NULL);
        INSERT INTO sitebuilder.block VALUES(50,10,'disk','{}')");
    $checks=0;
    function eq($expected,$actual,string $why): void { global $checks; ++$checks; if ($expected!==$actual) throw new RuntimeException($why.': '.var_export($actual,true)); }
    function raises(callable $fn,string $code): Throwable {
        try { $fn(); } catch(Throwable $e) { eq($code,$e->getMessage(),'Expected error'); return $e; }
        throw new RuntimeException('Expected error '.$code);
    }
    function request(string $role): array {
        $target=AccessAdminService::target(['kind'=>'folder','siteId'=>1,'pageId'=>10,'blockId'=>50,'folderId'=>100],99);
        return $target+['requestId'=>bin2hex(random_bytes(16)),'revision'=>AccessAdminService::revision($target,AccessAdminService::direct($target)),
            'changes'=>[['userId'=>12,'role'=>$role]]];
    }
    function journal(array $request): array {
        $st=sb_db()->prepare('SELECT details_json FROM sitebuilder.audit_log WHERE request_id=:id');
        $st->execute([':id'=>$request['requestId']]); return json_decode($st->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    }
    try {
        $request=request('VIEWER'); $receipt=AccessAdminService::apply($request,99);
        eq(1,$receipt['saved'],'Initial native write committed');
        eq('complete',journal($request)['state'],'PG journal completed');
        eq(true,AccessAdminService::apply($request,99)['replayed'],'Completed receipt replayed');
        eq(1,$manager->appends,'Replay did not touch native ACL');
        $reuse=$request; $reuse['changes'][0]['role']='EDITOR';
        raises(fn()=>AccessAdminService::apply($reuse,99),'REQUEST_ID_REUSED');
        $request=request('EDITOR'); $manager->failAppend=true;
        $error=raises(fn()=>AccessAdminService::apply($request,99),'NATIVE_PACKET_FAILED');
        eq('append',$error->details['phase'],'Native failure phase exposed');
        eq([2,7],$error->details['users'][0]['groups'],'Affected subject groups, not actor groups');
        eq('pending',journal($request)['state'],'Pending journal survives native rollback');
        eq('VIEWER',AccessNativeDiskService::direct(BitrixDiskRightsService::getDirectSnapshot(100))['U12']['role'],'Failed native transaction rolled back');
        $manager->failAppend=false; AccessAdminService::apply($request,99);
        eq('complete',journal($request)['state'],'Pending-before retry completes');
        eq(2,(int)$pdo->query('SELECT count(*) FROM sitebuilder.audit_log')->fetchColumn(),'Retry reused original journal row');
        $request=request('ADD'); $native->loseCommit=true;
        raises(fn()=>AccessAdminService::apply($request,99),'NATIVE_PACKET_FAILED');
        $native->loseCommit=false; $appendCount=$manager->appends;
        \Bitrix\Main\UserTable::$inactive=[12];
        $receipt=AccessAdminService::apply($request,99);
        eq(true,$receipt['recovered'],'Lost native commit reply recovered despite now-inactive subject');
        eq($appendCount,$manager->appends,'Already-applied recovery does not rewrite native ACL');
        \Bitrix\Main\UserTable::$inactive=[];
        $request=request('FULL');
        $pdo->exec("ALTER TABLE sitebuilder.audit_log ADD CONSTRAINT block_complete CHECK(details_json->>'state'<>'complete') NOT VALID");
        try { AccessAdminService::apply($request,99); throw new RuntimeException('Expected receipt write failure'); }
        catch(PDOException $e) { eq('23514',$e->errorInfo[0],'PG completion failed after native success'); }
        eq('pending',journal($request)['state'],'Durable before/after receipt survived failed PG completion');
        $pdo->exec('ALTER TABLE sitebuilder.audit_log DROP CONSTRAINT block_complete');
        $appendCount=$manager->appends;
        eq(true,AccessAdminService::apply($request,99)['recovered'],'Native success with lost PG receipt recovered');
        eq($appendCount,$manager->appends,'Receipt repair does not issue another native write');
        $request=request('DENY'); $manager->failAppend=true;
        raises(fn()=>AccessAdminService::apply($request,99),'NATIVE_PACKET_FAILED'); $manager->failAppend=false;
        $manager->rights[]=['ACCESS_CODE'=>'U20','TASK_ID'=>37,'NEGATIVE'=>false];
        $current=BitrixDiskRightsService::getDirectSnapshot(100)['rightsRevision'];
        raises(fn()=>AccessAdminService::apply($request,99),'NATIVE_RECONCILIATION_REQUIRED');
        eq($current,BitrixDiskRightsService::getDirectSnapshot(100)['rightsRevision'],'Ambiguous external state is never overwritten');
        $other=new PDO(getenv('SB_TEST_PG_DSN'),getenv('SB_TEST_PG_USER')?:'postgres',getenv('SB_TEST_PG_PASSWORD')?:'postgres');
        $other->query('SELECT pg_advisory_lock(761328,100)');
        try { raises(fn()=>AccessAdminService::apply(request('VIEWER'),99),'DISK_RIGHTS_BUSY'); }
        finally { $other->query('SELECT pg_advisory_unlock(761328,100)'); }
        eq(true,isset(AccessAdminService::direct(AccessAdminService::target($request,99))['G7']),'Group assignment retained through every case');
        raises(fn()=>AccessAdminService::apply(request('VIEWER'),40),'MODE_READ_ONLY');
        echo "Native journal PostgreSQL checks passed ($checks checks; real PostgreSQL, native Bitrix double)\n";
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec('DROP SCHEMA sitebuilder CASCADE');
        unlink($root.'/local/php_interface/lib/pg_master.php');
        rmdir($root.'/local/php_interface/lib');rmdir($root.'/local/php_interface');rmdir($root.'/local');rmdir($root);
    }
}
