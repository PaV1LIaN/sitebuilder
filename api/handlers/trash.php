<?php

global $USER;

if (!function_exists('sb_trash_require_site')) {
    function sb_trash_require_site(int $siteId): void
    {
        if ($siteId <= 0) sb_json_error('SITE_ID_REQUIRED', 422);
        sb_require_content_manager($siteId);
    }
}

if ($action === 'trash.list') {
    $siteId=(int)($_POST['siteId']??0);
    sb_trash_require_site($siteId);
    sb_json_ok(['items'=>RecycleBinService::listActive($siteId)]);
}

if ($action === 'trash.get') {
    $id=(int)($_POST['id']??0);
    if($id<=0)sb_json_error('ID_REQUIRED',422);
    $item=RecycleBinService::get($id,true,false);
    if(!$item)sb_json_error('RECYCLE_ITEM_NOT_FOUND',404);
    sb_trash_require_site((int)$item['siteId']);
    sb_json_ok(['item'=>$item]);
}

if ($action === 'trash.restore') {
    $id=(int)($_POST['id']??0);
    if($id<=0)sb_json_error('ID_REQUIRED',422);
    $item=RecycleBinService::get($id,false,false);
    if(!$item)sb_json_error('RECYCLE_ITEM_NOT_FOUND',404);
    sb_trash_require_site((int)$item['siteId']);
    try {
        $result=RecycleBinService::restore($id,(int)$USER->GetID());
        sb_json_ok(['result'=>$result]);
    } catch (RuntimeException $e) {
        $known=['RECYCLE_ITEM_NOT_FOUND','RECYCLE_ITEM_ALREADY_RESTORED','RECYCLE_TYPE_NOT_SUPPORTED','RECYCLE_SNAPSHOT_EMPTY','RECYCLE_ENTITY_ID_CONFLICT','RECYCLE_PAGE_TREE_CYCLE','RECYCLE_PAGE_ID_INVALID','SITE_NOT_FOUND'];
        if(in_array($e->getMessage(),$known,true))sb_json_error($e->getMessage(),422);
        throw $e;
    }
}

if ($action === 'trash.purge') {
    $id=(int)($_POST['id']??0);
    if($id<=0)sb_json_error('ID_REQUIRED',422);
    $item=RecycleBinService::get($id,false,false);
    if(!$item)sb_json_error('RECYCLE_ITEM_NOT_FOUND',404);
    sb_trash_require_site((int)$item['siteId']);
    if(!RecycleBinService::purge($id))sb_json_error('RECYCLE_ITEM_NOT_FOUND',404);
    sb_json_ok(['purged'=>true,'id'=>$id,'siteId'=>(int)$item['siteId']]);
}

sb_json_error('UNKNOWN_TRASH_ACTION',400);
