<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/RevisionService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessService.php';

global $USER;

function sb_history_current_user_id(): int
{
    global $USER;
    if (!is_object($USER) || !$USER->IsAuthorized()) sb_json_error('AUTH_REQUIRED',401);
    $id=(int)$USER->GetID(); if($id<=0)sb_json_error('AUTH_REQUIRED',401); return $id;
}

function sb_history_can_edit_site(int $siteId,int $userId): bool
{
    global $USER;
    return (is_object($USER)&&$USER->IsAdmin()) || PageAccessService::hasGlobalSiteAccess($siteId,$userId,'edit');
}

function sb_history_require_entity_edit(string $entityType,int $entityId,?array $revision=null): array
{
    $userId=sb_history_current_user_id();
    $entityType=RevisionService::normalizeEntityType($entityType);

    if($entityType===RevisionService::ENTITY_SITE){
        $entity=RevisionService::getSite($entityId,false);$siteId=(int)($entity['id']??($revision['siteId']??0));
        if($siteId<=0)sb_json_error('SITE_NOT_FOUND',404);
        if(!sb_history_can_edit_site($siteId,$userId))sb_json_error('SITE_EDIT_ACCESS_DENIED',403);
        return compact('entityType','entityId','siteId','entity','userId')+['pageId'=>0];
    }

    if($entityType===RevisionService::ENTITY_MENU){
        $entity=RevisionService::getMenu($entityId,false);$siteId=(int)($entity['siteId']??($revision['siteId']??0));
        if($siteId<=0)sb_json_error('MENU_CONTEXT_NOT_FOUND',404);
        if(!sb_history_can_edit_site($siteId,$userId))sb_json_error('SITE_EDIT_ACCESS_DENIED',403);
        return compact('entityType','entityId','siteId','entity','userId')+['pageId'=>0];
    }

    if($entityType===RevisionService::ENTITY_LAYOUT){
        $entity=RevisionService::getLayout($entityId,false);$siteId=(int)($entity['siteId']??($revision['siteId']??$entityId));
        if($siteId<=0)sb_json_error('LAYOUT_CONTEXT_NOT_FOUND',404);
        if(!sb_history_can_edit_site($siteId,$userId))sb_json_error('SITE_EDIT_ACCESS_DENIED',403);
        return compact('entityType','entityId','siteId','entity','userId')+['pageId'=>0];
    }

    if($entityType===RevisionService::ENTITY_PAGE){
        $entity=RevisionService::getPage($entityId,false);$siteId=(int)($entity['siteId']??($revision['siteId']??0));
        if($siteId<=0)sb_json_error('SITE_NOT_FOUND',404);
        $allowed=$entity?PageAccessService::canEditPage($siteId,$entityId,$userId):sb_history_can_edit_site($siteId,$userId);
        if(!$allowed)sb_json_error('PAGE_EDIT_ACCESS_DENIED',403);
        return compact('entityType','entityId','siteId','entity','userId')+['pageId'=>$entityId];
    }

    $entity=RevisionService::getBlock($entityId,false);
    $pageId=(int)($entity['pageId']??($revision['pageId']??0));
    $page=$pageId>0?RevisionService::getPage($pageId,false):null;
    $siteId=(int)($page['siteId']??($revision['siteId']??0));
    if($siteId<=0||$pageId<=0)sb_json_error('BLOCK_CONTEXT_NOT_FOUND',404);
    $allowed=$entity&&$page?PageAccessService::canEditPage($siteId,$pageId,$userId):sb_history_can_edit_site($siteId,$userId);
    if(!$allowed)sb_json_error('PAGE_EDIT_ACCESS_DENIED',403);
    return compact('entityType','entityId','siteId','pageId','entity','userId');
}

function sb_history_user_map(array $items): array
{
    $ids=[];foreach($items as $item){$id=(int)($item['createdBy']??0);if($id>0)$ids[$id]=true;}
    $users=[];foreach(array_keys($ids) as $id){$row=CUser::GetByID((int)$id)->Fetch();if(!$row)continue;$name=trim(implode(' ',array_filter([(string)($row['LAST_NAME']??''),(string)($row['NAME']??''),(string)($row['SECOND_NAME']??'')])));$users[(int)$id]=['id'=>(int)$id,'name'=>$name!==''?$name:(string)($row['LOGIN']??('Пользователь #'.$id)),'login'=>(string)($row['LOGIN']??'')];}
    return $users;
}

function sb_history_unique_site_slug(string $slug, int $siteId): string
{
    $base = sb_slugify($slug);
    if ($base === '') {
        $base = 'site-' . $siteId;
    }

    $candidate = $base;
    $suffix = 1;

    while (sb_db_fetch_one(
        'SELECT id FROM sitebuilder.site WHERE slug=:slug AND id<>:id',
        [':slug' => $candidate, ':id' => $siteId]
    )) {
        $candidate = $base . '-restored-' . $siteId;
        if ($suffix > 1) {
            $candidate .= '-' . $suffix;
        }
        $suffix++;
    }

    return $candidate;
}

function sb_history_unique_page_slug(int $siteId, string $slug, int $pageId): string
{
    $base = sb_slugify($slug);
    if ($base === '') {
        $base = 'page-' . $pageId;
    }

    $candidate = $base;
    $suffix = 1;

    while (sb_db_fetch_one(
        'SELECT id FROM sitebuilder.page WHERE site_id=:site_id AND slug=:slug AND id<>:id',
        [':site_id' => $siteId, ':slug' => $candidate, ':id' => $pageId]
    )) {
        $candidate = $base . '-restored-' . $pageId;
        if ($suffix > 1) {
            $candidate .= '-' . $suffix;
        }
        $suffix++;
    }

    return $candidate;
}

if($action==='history.list'){
    $type=(string)($_POST['entityType']??'');$id=(int)($_POST['entityId']??0);
    if($id<=0)sb_json_error('ENTITY_ID_REQUIRED',422);
    sb_history_require_entity_edit($type,$id);
    $items=RevisionService::list($type,$id,(int)($_POST['limit']??50),(int)($_POST['offset']??0));
    sb_json_ok(['items'=>$items,'users'=>sb_history_user_map($items)]);
}

if($action==='history.get'){
    $id=(int)($_POST['revisionId']??0);if($id<=0)sb_json_error('REVISION_ID_REQUIRED',422);
    $revision=RevisionService::getRevision($id);if(!$revision)sb_json_error('REVISION_NOT_FOUND',404);
    sb_history_require_entity_edit((string)$revision['entityType'],(int)$revision['entityId'],$revision);
    sb_json_ok(['revision'=>$revision,'users'=>sb_history_user_map([$revision])]);
}

if($action==='history.restore'){
    $revisionId=(int)($_POST['revisionId']??0);if($revisionId<=0)sb_json_error('REVISION_ID_REQUIRED',422);
    $expected=RevisionService::requireExpectedVersion($_POST['expectedVersion']??null);
    $revision=RevisionService::getRevision($revisionId);if(!$revision)sb_json_error('REVISION_NOT_FOUND',404);
    if((string)$revision['operation']==='delete')sb_json_error('DELETED_ENTITY_RESTORE_NOT_SUPPORTED',422);
    $context=sb_history_require_entity_edit((string)$revision['entityType'],(int)$revision['entityId'],$revision);
    $snapshot=is_array($revision['snapshot']??null)?$revision['snapshot']:[];
    $current=$context['entity'];if(!$current)sb_json_error('ENTITY_NOT_FOUND',404);
    $userId=(int)$context['userId'];$type=$context['entityType'];

    if($type===RevisionService::ENTITY_SITE){
        $restored=$current;
        foreach(['name','settings','layout'] as $field)if(array_key_exists($field,$snapshot))$restored[$field]=$snapshot[$field];
        if(array_key_exists('sectionId',$snapshot)){
            $sectionId=(int)$snapshot['sectionId'];
            if($sectionId<=0||sb_db_fetch_one('SELECT id FROM sitebuilder.site_section WHERE id=:id',[':id'=>$sectionId]))$restored['sectionId']=$sectionId;
        }
        $restored['slug']=sb_history_unique_site_slug(
            (string)($snapshot['slug']??$current['slug']),
            (int)$current['id']
        );
        $home=(int)($snapshot['homePageId']??0);if($home<=0||($p=RevisionService::getPage($home))&&(int)$p['siteId']===(int)$current['id'])$restored['homePageId']=$home;
        $top=(int)($snapshot['topMenuId']??0);if($top<=0||($m=RevisionService::getMenu($top))&&(int)$m['siteId']===(int)$current['id'])$restored['topMenuId']=$top;
        $saved=RevisionService::saveSite($restored,$expected,$userId,'restore',$revisionId);
    } elseif($type===RevisionService::ENTITY_MENU){
        $restored=$current;if(array_key_exists('name',$snapshot))$restored['name']=$snapshot['name'];
        if(is_array($snapshot['items']??null)){
            $items=[];foreach($snapshot['items'] as $item){if(!is_array($item))continue;if((string)($item['type']??'')==='page'){$p=RevisionService::getPage((int)($item['pageId']??0));if(!$p||(int)$p['siteId']!==(int)$current['siteId'])continue;}$items[]=$item;}$restored['items']=$items;
        }
        $saved=RevisionService::saveMenu($restored,$expected,$userId,'restore',$revisionId);
    } elseif($type===RevisionService::ENTITY_LAYOUT){
        $restored=$current;if(is_array($snapshot['settings']??null))$restored['settings']=$snapshot['settings'];if(is_array($snapshot['zones']??null))$restored['zones']=$snapshot['zones'];
        $saved=RevisionService::saveLayout(sb_normalize_layout_record($restored),$expected,$userId,'restore',$revisionId);
    } elseif($type===RevisionService::ENTITY_PAGE){
        $parentId=(int)($snapshot['parentId']??0);if($parentId===(int)$current['id'])sb_json_error('PAGE_CANNOT_BE_OWN_PARENT',422);
        if($parentId>0){$parent=RevisionService::getPage($parentId);if(!$parent||(int)$parent['siteId']!==(int)$current['siteId'])sb_json_error('PARENT_PAGE_NOT_FOUND',404);if(!sb_history_can_edit_site((int)$current['siteId'],$userId)&&!PageAccessService::canEditPage((int)$current['siteId'],$parentId,$userId))sb_json_error('PARENT_PAGE_EDIT_ACCESS_DENIED',403);if(sb_page_is_descendant((int)$current['siteId'],$parentId,(int)$current['id']))sb_json_error('CYCLIC_PARENT_RELATION',422);}
        elseif((int)($current['parentId']??0)>0&&!sb_history_can_edit_site((int)$current['siteId'],$userId)){sb_json_error('ROOT_PAGE_MOVE_ACCESS_DENIED',403);}
        $restored=$current;foreach(['title','sort','status','publishedAt','seo'] as $field)if(array_key_exists($field,$snapshot))$restored[$field]=$snapshot[$field];$restored['parentId']=$parentId;
        $restored['slug']=sb_history_unique_page_slug(
            (int)$current['siteId'],
            (string)($snapshot['slug']??$current['slug']),
            (int)$current['id']
        );
        $saved=RevisionService::savePage($restored,$expected,$userId,'restore',$revisionId);
    } else {
        $restored=$current;foreach(['type','sort','content','props'] as $field)if(array_key_exists($field,$snapshot))$restored[$field]=$snapshot[$field];
        $saved=RevisionService::saveBlock($restored,$expected,$userId,'restore',$revisionId);
    }

    sb_json_ok(['entity'=>$saved,'restoredFromRevisionId'=>$revisionId]);
}

sb_json_error('UNKNOWN_HISTORY_ACTION',400);
