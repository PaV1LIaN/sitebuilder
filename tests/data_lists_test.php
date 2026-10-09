<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/fixtures/lists_runtime.php';
require __DIR__ . '/../lib/DataListService.php';
set_error_handler(static function($severity,$message,$file,$line){ throw new ErrorException($message,0,$severity,$file,$line); });
lists_setup();
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; echo "PASS $label\n"; }
function rejected(callable $fn, int $status, string $label): void {
    try { $fn(); } catch (DataListException $e) { check($e->status === $status,$label); return; }
    throw new RuntimeException('Expected rejection: ' . $label);
}
function call_list(string $action, array $data = [], int $user = 1, int $block = 100): array {
    $page = [100 => 10,101 => 11,200 => 20][$block];
    $input = array_merge(['blockId'=>$block,'pageId'=>$page,'siteId'=>$page===20?2:1],$data);
    lists_query('BEGIN');
    try { $result = DataListService::dispatch($action,$input,$user); lists_query('COMMIT'); return $result; }
    catch (Throwable $e) { lists_query('ROLLBACK'); throw $e; }
}
function bind_list(int $block, int $id, array $extra = []): void {
    sb_db_execute('UPDATE block SET content_json=:json WHERE id=:block',[':json'=>json_encode(array_merge(['listId'=>$id],$extra)),':block'=>$block]);
}
$fields = [];
foreach (DataListService::TYPES as $type) $fields[]=['id'=>$type,'label'=>$type,'type'=>$type,'required'=>$type==='text','options'=>$type==='choice'?['Новая','Готово']:[]];
$list = call_list('create',['title'=>'Заявки','fields'=>$fields])['list']; $id=$list['id'];
check(count($list['fields'])===8 && $list['ownerPageId']===10,'create typed dataset');
$people=call_list('people',['query'=>'Иванова Анна']);
check($people['people'][0]['id']===7 && ListsUserTable::$query['filter'][0]['%LAST_NAME']==='Иванова'
    && ListsUserTable::$query['filter'][1]['%NAME']==='Анна' && ListsUserTable::$query['limit']===20,'employee search uses active users and all name tokens');
rejected(fn()=>call_list('people',['query'=>'Анна'],2),403,'employee search requires editor access');
bind_list(100,$id); bind_list(101,$id);
$values=['text'=>'Отчёт 100%','textarea'=>'Описание','number'=>'10.5','date'=>'2028-02-29','choice'=>'Новая','checkbox'=>false,'person'=>['id'=>7,'name'=>'Подмена'],'url'=>'/local/sitebuilder/s/test/'];
$save=['listId'=>$id,'schemaVersion'=>1,'values'=>$values,'requestKey'=>'request_00000000001'];
$item=call_list('saveRecord',$save)['itemId'];
check($item===call_list('saveRecord',$save)['itemId'] && call_list('records')['total']===1,'repeated create has one row');
$data=call_list('records',[],2,101);
check($data['items'][0]['values']['person']['name']==='Иванова Анна' && !$data['list']['canEdit'],'shared view uses canonical employee and read-only role');
rejected(fn()=>call_list('saveRecord',$save,2),403,'reader cannot write');
rejected(fn()=>call_list('records',[],0),401,'anonymous cannot read');
rejected(fn()=>call_list('records',[],9),403,'denied user cannot read');
PageAccessService::$denied=[10]; rejected(fn()=>call_list('records',[],2,101),403,'source page ACL follows shared list'); PageAccessService::$denied=[];
bind_list(200,$id); rejected(fn()=>call_list('records',[],1,200),403,'cross-site list reference rejected');
sb_db_execute("UPDATE page SET status='draft' WHERE id=10"); rejected(fn()=>call_list('records',[],2,101),403,'draft source hidden to readers');
check(call_list('records')['total']===1,'editor can inspect draft source'); sb_db_execute("UPDATE page SET status='published' WHERE id=10");
rejected(fn()=>call_list('records',['pageId'=>11]),403,'block and page binding validated');
rejected(fn()=>call_list('saveRecord',array_merge($save,['listId'=>$id+1])),409,'stale block binding rejected');
rejected(fn()=>call_list('records',['deleted'=>true],2),403,'recycle bin private to editors');
foreach (['text'=>'  ','number'=>'1e10','date'=>'2027-02-29','choice'=>'Удалённый вариант','checkbox'=>'false','url'=>'javascript:alert(1)','person'=>999] as $field=>$bad) {
    rejected(fn()=>call_list('saveRecord',array_merge($save,['requestKey'=>'invalid_00000000001','values'=>array_merge($values,[$field=>$bad])])),422,'invalid '.$field.' rejected');
}
check(call_list('records',['query'=>'%'])['total']===1 && call_list('records',['query'=>"%' OR 1=1 --"])['total']===0,'search literals are safely bound');
call_list('saveRecord',array_merge($save,['requestKey'=>'request_00000000002','values'=>array_merge($values,['text'=>'Второй','number'=>'2','choice'=>'Готово'])]));
check(call_list('records',['sortBy'=>'number'])['items'][0]['values']['number']==='2','numeric sort, not string sort');
bind_list(101,$id,['filters'=>['choice'=>'Новая']]);
check(call_list('records',[],2,101)['total']===1 && call_list('records',['filters'=>['choice'=>'Готово']],2,101)['total']===0,'saved and visitor filters intersect');
check(call_list('records',['filters'=>['number'=>'2.0']])['total']===1,'numeric filter accepts equivalent decimals');
rejected(fn()=>call_list('records',['filters'=>['unknown'=>'x']]),409,'removed filter field reports conflict');
$edit=array_merge($save,['itemId'=>$item,'version'=>1]);call_list('saveRecord',$edit);
rejected(fn()=>call_list('saveRecord',$edit),409,'stale record version cannot overwrite');
call_list('deleteRecord',['listId'=>$id,'schemaVersion'=>1,'itemId'=>$item,'version'=>2]);
check(call_list('records')['total']===1 && call_list('records',['deleted'=>true])['total']===1,'delete moves row to recycle bin');
rejected(fn()=>call_list('saveRecord',array_merge($edit,['version'=>3])),409,'deleted row must be restored first');
$changed=$fields; $changed[2]['type']='text';
rejected(fn()=>call_list('schema',['listId'=>$id,'version'=>1,'title'=>'Заявки','fields'=>$changed]),422,'type changes cannot destroy existing values');
$changed=$fields; $changed[4]['options']=['Готово'];
rejected(fn()=>call_list('schema',['listId'=>$id,'version'=>1,'title'=>'Заявки','fields'=>$changed]),422,'choice changes validate deleted rows too');
call_list('restoreRecord',['listId'=>$id,'schemaVersion'=>1,'itemId'=>$item,'version'=>3]);
check(call_list('records')['total']===2,'restore brings back record');
$fields[]=['id'=>'comment','label'=>'Комментарий','type'=>'text','required'=>false];
$updated=call_list('schema',['listId'=>$id,'version'=>1,'title'=>'Реестр заявок','fields'=>$fields])['list'];
check($updated['version']===2 && count($updated['fields'])===9,'add optional field and rename without losing rows');
rejected(fn()=>call_list('saveRecord',array_merge($edit,['version'=>4])),409,'stale schema prevents obsolete form writes');
rejected(fn()=>call_list('schema',['listId'=>$id,'version'=>1,'title'=>'Old','fields'=>$fields]),409,'stale schema settings cannot overwrite');
call_list('deleteRecord',['listId'=>$id,'schemaVersion'=>2,'itemId'=>$item,'version'=>4]);
$snapshot=DataListService::exportForSite(1);check(count($snapshot)===1 && count($snapshot[0]['items'])===2,'snapshot exports shared dataset once with deleted row');
lists_query('BEGIN');$map=DataListService::importForSite(2,[10=>20],$snapshot,1);lists_query('COMMIT');bind_list(200,$map[$id]);
check($map[$id]!==$id && call_list('records',[],1,200)['total']===1 && call_list('records',['deleted'=>true],1,200)['total']===1,'restore remaps dataset IDs and retains deleted row');
check(call_list('records',[],1,200)['items'][0]['values']['number']==='2','restored values remain intact');
for($i=0;$i<30;$i++)call_list('saveRecord',array_merge($save,['schemaVersion'=>2,'requestKey'=>'pagination_'.str_pad((string)$i,16,'0',STR_PAD_LEFT)]));
$page=call_list('records',['page'=>999,'pageSize'=>10]);check($page['page']===4 && count($page['items'])===1,'pagination clamps invalid page and preserves remainder');
foreach (['//evil.test','/\\evil.test','https://name:secret@example.com',"https://example.com\n/path"] as $url) check(!DataListService::safeUrl($url),'unsafe URL rejected: '.json_encode($url));
rejected(fn()=>DataListService::fields([['id'=>'x','label'=>'x','type'=>'text'],['id'=>'x','label'=>'y','type'=>'text']]),422,'duplicate field codes rejected');
echo "$checks checks passed\n";
