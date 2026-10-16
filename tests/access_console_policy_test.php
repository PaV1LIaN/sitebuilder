<?php
/** Compare authorization before/after extracting shared source matching. No real database or Bitrix. */
namespace Bitrix\Disk {
    final class Folder {
        public static array $parents=[100=>0,110=>100,111=>110,120=>100,200=>0,210=>200];
        public function __construct(private int $id){}
        public static function loadById(int $id):?self{return isset(self::$parents[$id])?new self($id):null;}
        public function getId():int{return $this->id;}
        public function getParentId():int{return self::$parents[$this->id];}
        public function getRealObject():self{return $this;}
    }
}
namespace {
    if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
    function sb_db(){throw new RuntimeException('Unexpected database call in pure test');}
    require __DIR__.'/../lib/AccessGroupPolicy.php';
    require __DIR__.'/fixtures/access_group_policy_stage3.php';
    $checks=0;
    function same($a,$b):void{global $checks;++$checks;if($a!==$b)throw new RuntimeException('Policy changed: '.var_export([$a,$b],true));}
    function seed(string $class,array $rows):void{
        foreach(['ready'=>true,'rows'=>['1:12'=>$rows],'pages'=>[1=>[10=>0,11=>10,12=>0]],'folders'=>[]] as $name=>$value){
            $prop=new ReflectionProperty($class,$name);$prop->setValue(null,$value);
        }
    }
    mt_srand(74321);
    for($case=0;$case<150;$case++){
        $rows=[];
        for($n=0;$n<9;$n++){
            $kind=['site','page','disk','folder'][mt_rand(0,3)];
            $rows[]=['id'=>$n+1,'group_id'=>$n%3+1,'group_name'=>'Group '.($n%3+1),'title'=>'Rule '.$n,
                'kind'=>$kind,'site_id'=>1,'page_id'=>[0,10,11,12][mt_rand(0,3)],'block_id'=>[50,51][mt_rand(0,1)],
                'folder_id'=>[100,110,111,120,210][mt_rand(0,4)],'root_folder_id'=>[100,200][mt_rand(0,1)],
                'permission_mode'=>['custom','inherit_site','bitrix_disk'][mt_rand(0,2)],
                'can_edit'=>[true,false,'t','f'][mt_rand(0,3)],'can_delete'=>(bool)mt_rand(0,1),
                'include_children'=>(bool)mt_rand(0,1),'open_page'=>(bool)mt_rand(0,1)];
        }
        foreach(['AccessGroupPolicy','AccessGroupPolicyStage3'] as $class)seed($class,$rows);
        foreach([10,11,12,404] as $page)same(AccessGroupPolicyStage3::page(1,$page,12),AccessGroupPolicy::page(1,$page,12));
        foreach([100,110,111,120,210] as $folder)foreach(['custom','inherit_site','bitrix_disk'] as $mode)
            same(AccessGroupPolicyStage3::disk(1,10,50,$folder,100,12,$mode),AccessGroupPolicy::disk(1,10,50,$folder,100,12,$mode));
    }
    echo "PASS shared group policy equivalence: $checks comparisons\n";
}
