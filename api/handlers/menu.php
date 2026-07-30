<?php

global $USER;

if (!function_exists('sb_menu_parse_item_input')) {
    function sb_menu_parse_item_input(int $siteId): array
    {
        $title = trim((string)($_POST['title'] ?? ''));
        $type = trim((string)($_POST['type'] ?? 'page'));
        $pageId = (int)($_POST['pageId'] ?? 0);
        $url = trim((string)($_POST['url'] ?? ''));
        $target = trim((string)($_POST['target'] ?? '_self'));

        if ($title === '') sb_json_error('TITLE_REQUIRED', 422);
        if (!in_array($type, ['page', 'url'], true)) sb_json_error('BAD_ITEM_TYPE', 422);
        if (!in_array($target, ['_self', '_blank'], true)) $target = '_self';

        if ($type === 'page') {
            if ($pageId <= 0) sb_json_error('PAGE_ID_REQUIRED', 422);
            $page = sb_find_page($pageId);
            if (!$page || (int)($page['siteId'] ?? 0) !== $siteId) {
                sb_json_error('PAGE_NOT_IN_SITE', 422);
            }
            $url = '';
        } else {
            $pageId = 0;
            if ($url === '') sb_json_error('URL_REQUIRED', 422);
        }

        return compact('title', 'type', 'pageId', 'url', 'target');
    }
}

if (!function_exists('sb_menu_require')) {
    function sb_menu_require(int $menuId, bool $forUpdate = false): array
    {
        if ($menuId <= 0) sb_json_error('MENU_ID_REQUIRED', 422);
        $menu = RevisionService::getMenu($menuId, $forUpdate);
        if (!$menu) sb_json_error('MENU_NOT_FOUND', 404);
        sb_require_content_manager((int)$menu['siteId']);
        return sb_normalize_menu_record($menu);
    }
}

if ($action === 'menu.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) sb_json_error('SITE_ID_REQUIRED', 422);
    sb_require_viewer($siteId);

    $menus = array_values(array_filter(sb_read_menus(), static fn(array $m): bool => (int)($m['siteId'] ?? 0) === $siteId));
    $menus = array_map('sb_normalize_menu_record', $menus);
    usort($menus, static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);

    $site = RevisionService::getSite($siteId, false);
    if (!$site) sb_json_error('SITE_NOT_FOUND', 404);

    sb_json_ok([
        'menus' => $menus,
        'topMenuId' => (int)($site['topMenuId'] ?? 0),
        'siteVersion' => (int)($site['version'] ?? 1),
    ]);
}

if ($action === 'menu.create') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($siteId <= 0) sb_json_error('SITE_ID_REQUIRED', 422);
    if ($name === '') sb_json_error('NAME_REQUIRED', 422);
    sb_require_content_manager($siteId);

    $id = RevisionService::nextEntityId(RevisionService::ENTITY_MENU);
    $userId = (int)$USER->GetID();
    $stmt = sb_db()->prepare("\n        INSERT INTO sitebuilder.menu (id,site_id,name,items_json,created_by,created_at,updated_by,updated_at,version)\n        VALUES (:id,:site_id,:name,'[]'::jsonb,:user_id,NOW(),:user_id,NOW(),1)\n        RETURNING id,site_id,name,items_json,created_by,created_at,updated_by,updated_at,version\n    ");
    $stmt->execute([':id'=>$id, ':site_id'=>$siteId, ':name'=>$name, ':user_id'=>$userId]);
    $menu = sb_map_menu_row($stmt->fetch());
    RevisionService::recordMenu($menu, 'create', $userId);
    sb_json_ok(['menu' => sb_normalize_menu_record($menu)]);
}

if ($action === 'menu.update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($id <= 0) sb_json_error('ID_REQUIRED', 422);
    if ($name === '') sb_json_error('NAME_REQUIRED', 422);
    $menu = sb_menu_require($id, false);
    $menu['name'] = $name;
    $saved = RevisionService::saveMenu($menu, RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null), (int)$USER->GetID(), 'rename');
    sb_json_ok(['menu' => sb_normalize_menu_record($saved)]);
}

if ($action === 'menu.delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) sb_json_error('ID_REQUIRED', 422);
    $expectedVersion = RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null);
    $menu = sb_menu_require($id, true);
    RevisionService::assertExpected($menu, $expectedVersion, RevisionService::ENTITY_MENU);

    $siteId = (int)$menu['siteId'];
    $site = RevisionService::getSite($siteId, true);
    if (!$site) sb_json_error('SITE_NOT_FOUND', 404);
    $userId = (int)$USER->GetID();

    if ((int)($site['topMenuId'] ?? 0) === $id) {
        $expectedSiteVersion = RevisionService::requireExpectedVersion($_POST['expectedSiteVersion'] ?? null);
        $site['topMenuId'] = 0;
        $site = RevisionService::saveSite($site, $expectedSiteVersion, $userId, 'top_menu_clear');
    }

    RevisionService::recordDeletedMenu($menu, $userId);
    $stmt = sb_db()->prepare('DELETE FROM sitebuilder.menu WHERE id=:id AND site_id=:site_id AND version=:version');
    $stmt->execute([':id'=>$id, ':site_id'=>$siteId, ':version'=>$expectedVersion]);
    if ($stmt->rowCount() !== 1) {
        $latest = RevisionService::getMenu($id, false);
        throw new SiteBuilderVersionConflictException(
            RevisionService::ENTITY_MENU,
            $id,
            $expectedVersion,
            (int)($latest['version'] ?? 0)
        );
    }

    sb_json_ok([
        'deleted' => true,
        'id' => $id,
        'siteId' => $siteId,
        'siteVersion' => (int)($site['version'] ?? 1),
    ]);
}

if ($action === 'menu.setTop') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $menuId = (int)($_POST['menuId'] ?? 0);
    if ($siteId <= 0) sb_json_error('SITE_ID_REQUIRED', 422);
    sb_require_content_manager($siteId);

    if ($menuId > 0) {
        $menu = RevisionService::getMenu($menuId, false);
        if (!$menu || (int)$menu['siteId'] !== $siteId) sb_json_error('MENU_NOT_IN_SITE', 422);
    }

    $site = RevisionService::getSite($siteId, false);
    if (!$site) sb_json_error('SITE_NOT_FOUND', 404);
    $site['topMenuId'] = $menuId;
    $saved = RevisionService::saveSite($site, RevisionService::requireExpectedVersion($_POST['expectedSiteVersion'] ?? null), (int)$USER->GetID(), 'top_menu_change');
    sb_json_ok(['site' => $saved, 'topMenuId' => (int)$saved['topMenuId'], 'siteVersion' => (int)$saved['version']]);
}

if ($action === 'menu.item.add') {
    $menuId = (int)($_POST['menuId'] ?? 0);
    $menu = sb_menu_require($menuId, false);
    $itemData = sb_menu_parse_item_input((int)$menu['siteId']);
    $items = array_values($menu['items'] ?? []);
    $items[] = array_merge([
        'id' => sb_next_menu_item_id($items),
        'sort' => sb_menu_next_item_sort($items),
    ], $itemData);
    $menu['items'] = $items;
    $saved = RevisionService::saveMenu($menu, RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null), (int)$USER->GetID(), 'item_add');
    sb_json_ok(['menu' => sb_normalize_menu_record($saved)]);
}

if ($action === 'menu.item.update') {
    $menuId = (int)($_POST['menuId'] ?? 0);
    $itemId = (int)($_POST['itemId'] ?? 0);
    if ($itemId <= 0) sb_json_error('ITEM_ID_REQUIRED', 422);
    $menu = sb_menu_require($menuId, false);
    $itemData = sb_menu_parse_item_input((int)$menu['siteId']);
    $found = false;
    foreach ($menu['items'] as &$item) {
        if ((int)($item['id'] ?? 0) !== $itemId) continue;
        $sort = (int)($item['sort'] ?? 10);
        $item = array_merge(['id'=>$itemId, 'sort'=>$sort], $itemData);
        $found = true;
        break;
    }
    unset($item);
    if (!$found) sb_json_error('ITEM_NOT_FOUND', 404);
    $saved = RevisionService::saveMenu($menu, RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null), (int)$USER->GetID(), 'item_update');
    sb_json_ok(['menu' => sb_normalize_menu_record($saved)]);
}

if ($action === 'menu.item.delete') {
    $menuId = (int)($_POST['menuId'] ?? 0);
    $itemId = (int)($_POST['itemId'] ?? 0);
    if ($itemId <= 0) sb_json_error('ITEM_ID_REQUIRED', 422);
    $menu = sb_menu_require($menuId, false);
    $before = count($menu['items']);
    $menu['items'] = array_values(array_filter($menu['items'], static fn(array $item): bool => (int)($item['id'] ?? 0) !== $itemId));
    if (count($menu['items']) === $before) sb_json_error('ITEM_NOT_FOUND', 404);
    $saved = RevisionService::saveMenu($menu, RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null), (int)$USER->GetID(), 'item_delete');
    sb_json_ok(['menu' => sb_normalize_menu_record($saved)]);
}

if ($action === 'menu.item.move') {
    $menuId = (int)($_POST['menuId'] ?? 0);
    $itemId = (int)($_POST['itemId'] ?? 0);
    $dir = trim((string)($_POST['dir'] ?? ''));
    if ($itemId <= 0) sb_json_error('ITEM_ID_REQUIRED', 422);
    if (!in_array($dir, ['up','down'], true)) sb_json_error('DIR_REQUIRED', 422);
    $menu = sb_menu_require($menuId, false);
    $items = array_values($menu['items']);
    usort($items, static function(array $a,array $b): int { $c=(int)($a['sort']??0)<=>(int)($b['sort']??0); return $c!==0?$c:(int)$a['id']<=>(int)$b['id']; });
    $pos = null;
    foreach ($items as $i=>$item) if ((int)($item['id']??0)===$itemId) { $pos=$i; break; }
    if ($pos === null) sb_json_error('ITEM_NOT_FOUND', 404);
    $swap = $dir==='up' ? $pos-1 : $pos+1;
    if (isset($items[$swap])) {
        $a=(int)($items[$pos]['sort']??0); $b=(int)($items[$swap]['sort']??0);
        $items[$pos]['sort']=$b; $items[$swap]['sort']=$a;
        $menu['items']=$items;
        $saved=RevisionService::saveMenu($menu,RevisionService::requireExpectedVersion($_POST['expectedVersion']??null),(int)$USER->GetID(),'item_move');
    } else {
        RevisionService::assertExpected($menu,RevisionService::requireExpectedVersion($_POST['expectedVersion']??null),RevisionService::ENTITY_MENU);
        $saved=$menu;
    }
    sb_json_ok(['menu'=>sb_normalize_menu_record($saved)]);
}

sb_json_error('UNKNOWN_MENU_ACTION', 400);
