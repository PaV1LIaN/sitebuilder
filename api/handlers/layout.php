<?php

global $USER;

if (!function_exists('sb_layout_handler_get_or_create')) {
    function sb_layout_handler_get_or_create(int $siteId, int $userId): array
    {
        $layout = RevisionService::getLayout($siteId, false);
        if ($layout) return sb_normalize_layout_record($layout);

        $default = sb_layout_default_record($siteId);
        $stmt = sb_db()->prepare("\n            INSERT INTO sitebuilder.layout (site_id,settings_json,zones_json,created_by,created_at,updated_by,updated_at,version)\n            VALUES (:site_id,CAST(:settings AS jsonb),CAST(:zones AS jsonb),:user_id,NOW(),:user_id,NOW(),1)\n            ON CONFLICT (site_id) DO NOTHING\n            RETURNING site_id,settings_json,zones_json,created_by,created_at,updated_by,updated_at,version\n        ");
        $stmt->execute([
            ':site_id'=>$siteId,
            ':settings'=>json_encode($default['settings'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ':zones'=>json_encode($default['zones'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ':user_id'=>$userId>0?$userId:null,
        ]);
        $row=$stmt->fetch();
        $layout=$row?sb_map_layout_row($row):RevisionService::getLayout($siteId,false);
        if(!$layout) throw new RuntimeException('LAYOUT_CREATE_FAILED');
        if($row) RevisionService::recordLayout($layout,'create',$userId);
        return sb_normalize_layout_record($layout);
    }
}

if (!function_exists('sb_layout_handler_require')) {
    function sb_layout_handler_require(int $siteId): array
    {
        if ($siteId <= 0) sb_json_error('SITE_ID_REQUIRED', 422);
        sb_require_content_manager($siteId);
        return sb_layout_handler_get_or_create($siteId, (int)($GLOBALS['USER']->GetID()));
    }
}

if ($action === 'layout.get') {
    $siteId=(int)($_POST['siteId']??0);
    if($siteId<=0) sb_json_error('SITE_ID_REQUIRED',422);
    sb_require_viewer($siteId);
    $layout=sb_layout_handler_get_or_create($siteId,(int)$USER->GetID());
    sb_json_ok(['layout'=>$layout,'handler'=>'layout']);
}

if ($action === 'layout.updateSettings') {
    $siteId=(int)($_POST['siteId']??0);
    $layout=sb_layout_handler_require($siteId);
    $raw=$_POST['settings']??null;
    if($raw===null) sb_json_error('SETTINGS_REQUIRED',422);
    $settings=is_array($raw)?$raw:json_decode((string)$raw,true);
    if(!is_array($settings)) sb_json_error('BAD_SETTINGS_JSON',422);

    $allowed=['showHeader','showFooter','showLeft','showRight','leftWidth','rightWidth','leftMode'];
    $filtered=[];
    foreach($allowed as $key) if(array_key_exists($key,$settings)) $filtered[$key]=$settings[$key];
    foreach(['showHeader','showFooter','showLeft','showRight'] as $key) if(isset($filtered[$key])) $filtered[$key]=(bool)$filtered[$key];
    if(isset($filtered['leftWidth'])) $filtered['leftWidth']=max(120,min(800,(int)$filtered['leftWidth']));
    if(isset($filtered['rightWidth'])) $filtered['rightWidth']=max(120,min(800,(int)$filtered['rightWidth']));
    if(isset($filtered['leftMode'])) $filtered['leftMode']=in_array((string)$filtered['leftMode'],['blocks','menu'],true)?(string)$filtered['leftMode']:'blocks';
    $layout['settings']=array_merge($layout['settings'],$filtered);
    $saved=RevisionService::saveLayout($layout,RevisionService::requireExpectedVersion($_POST['expectedVersion']??null),(int)$USER->GetID(),'settings_update');
    sb_json_ok(['layout'=>sb_normalize_layout_record($saved),'handler'=>'layout']);
}

if ($action === 'layout.block.list') {
    $siteId=(int)($_POST['siteId']??0); $zone=trim((string)($_POST['zone']??''));
    if($siteId<=0) sb_json_error('SITE_ID_REQUIRED',422);
    if(!sb_layout_valid_zone($zone)) sb_json_error('BAD_ZONE',422);
    sb_require_viewer($siteId);
    $layout=sb_layout_handler_get_or_create($siteId,(int)$USER->GetID());
    sb_json_ok(['blocks'=>array_values($layout['zones'][$zone]??[]),'zone'=>$zone,'layoutVersion'=>(int)$layout['version']]);
}

if ($action === 'layout.block.create') {
    $siteId=(int)($_POST['siteId']??0); $zone=trim((string)($_POST['zone']??'')); $type=trim((string)($_POST['type']??'text'));
    if(!sb_layout_valid_zone($zone)) sb_json_error('BAD_ZONE',422);
    if($type==='') sb_json_error('TYPE_REQUIRED',422);
    $layout=sb_layout_handler_require($siteId);
    $now=date('c');
    $block=[
        'id'=>sb_layout_next_block_id($layout),'type'=>$type,'sort'=>sb_layout_next_block_sort($layout,$zone),
        'content'=>sb_default_block_content($type),'props'=>[],'createdBy'=>(int)$USER->GetID(),'createdAt'=>$now,'updatedBy'=>(int)$USER->GetID(),'updatedAt'=>$now,
    ];
    $layout['zones'][$zone][]=$block;
    $saved=RevisionService::saveLayout($layout,RevisionService::requireExpectedVersion($_POST['expectedVersion']??null),(int)$USER->GetID(),'block_create');
    sb_json_ok(['block'=>sb_normalize_block_record($block),'zone'=>$zone,'layout'=>sb_normalize_layout_record($saved)]);
}

if ($action === 'layout.block.update') {
    $siteId=(int)($_POST['siteId']??0); $id=(int)($_POST['id']??0);
    if($id<=0) sb_json_error('ID_REQUIRED',422);
    $layout=sb_layout_handler_require($siteId);
    $contentRaw=$_POST['content']??null; $propsRaw=$_POST['props']??null; $typeRaw=$_POST['type']??null;
    $newContent=null; $newProps=null; $newType=null;
    if($contentRaw!==null){$newContent=is_array($contentRaw)?$contentRaw:json_decode((string)$contentRaw,true);if(!is_array($newContent))sb_json_error('BAD_CONTENT_JSON',422);}
    if($propsRaw!==null){$newProps=is_array($propsRaw)?$propsRaw:json_decode((string)$propsRaw,true);if(!is_array($newProps))sb_json_error('BAD_PROPS_JSON',422);}
    if($typeRaw!==null){$newType=trim((string)$typeRaw);if($newType==='')sb_json_error('TYPE_REQUIRED',422);}
    $found=false; $updatedBlock=null;
    foreach(['header','footer','left','right'] as $zone){
        foreach($layout['zones'][$zone] as &$block){
            if((int)($block['id']??0)!==$id)continue;
            if($newType!==null)$block['type']=$newType; if($newContent!==null)$block['content']=$newContent; if($newProps!==null)$block['props']=$newProps;
            $block['updatedAt']=date('c');$block['updatedBy']=(int)$USER->GetID();$updatedBlock=$block;$found=true;break 2;
        } unset($block);
    }
    unset($block);
    if(!$found) sb_json_error('BLOCK_NOT_FOUND',404);
    $saved=RevisionService::saveLayout($layout,RevisionService::requireExpectedVersion($_POST['expectedVersion']??null),(int)$USER->GetID(),'block_update');
    sb_json_ok(['block'=>sb_normalize_block_record($updatedBlock),'layout'=>sb_normalize_layout_record($saved)]);
}

if ($action === 'layout.block.delete') {
    $siteId=(int)($_POST['siteId']??0); $id=(int)($_POST['id']??0); if($id<=0)sb_json_error('ID_REQUIRED',422);
    $layout=sb_layout_handler_require($siteId);$found=false;
    foreach(['header','footer','left','right'] as $zone){$before=count($layout['zones'][$zone]);$layout['zones'][$zone]=array_values(array_filter($layout['zones'][$zone],static fn(array $b):bool=>(int)($b['id']??0)!==$id));if(count($layout['zones'][$zone])!==$before){$found=true;break;}}
    if(!$found)sb_json_error('BLOCK_NOT_FOUND',404);
    $saved=RevisionService::saveLayout($layout,RevisionService::requireExpectedVersion($_POST['expectedVersion']??null),(int)$USER->GetID(),'block_delete');
    sb_json_ok(['layout'=>sb_normalize_layout_record($saved)]);
}

if ($action === 'layout.block.move') {
    $siteId=(int)($_POST['siteId']??0);$id=(int)($_POST['id']??0);$dir=trim((string)($_POST['dir']??''));
    if($id<=0)sb_json_error('ID_REQUIRED',422);if(!in_array($dir,['up','down'],true))sb_json_error('DIR_REQUIRED',422);
    $layout=sb_layout_handler_require($siteId);$found=false;$changed=false;
    foreach(['header','footer','left','right'] as $zone){
        $siblings=$layout['zones'][$zone];$pos=null;foreach($siblings as $i=>$block)if((int)($block['id']??0)===$id){$pos=$i;break;}
        if($pos===null)continue;$found=true;$swap=$dir==='up'?$pos-1:$pos+1;
        if(isset($siblings[$swap])){$a=(int)($layout['zones'][$zone][$pos]['sort']??500);$b=(int)($layout['zones'][$zone][$swap]['sort']??500);$layout['zones'][$zone][$pos]['sort']=$b;$layout['zones'][$zone][$swap]['sort']=$a;$changed=true;}break;
    }
    if(!$found)sb_json_error('BLOCK_NOT_FOUND',404);
    $expected=RevisionService::requireExpectedVersion($_POST['expectedVersion']??null);
    if($changed)$saved=RevisionService::saveLayout($layout,$expected,(int)$USER->GetID(),'block_move');else{RevisionService::assertExpected($layout,$expected,RevisionService::ENTITY_LAYOUT);$saved=$layout;}
    sb_json_ok(['layout'=>sb_normalize_layout_record($saved)]);
}

sb_json_error('UNKNOWN_LAYOUT_ACTION',400);
