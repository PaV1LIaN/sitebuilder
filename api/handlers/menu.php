<?php

global $USER;

if ($action === 'menu.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_viewer($siteId);

    $menus = array_values(array_filter(sb_read_menus(), static function ($m) use ($siteId) {
        return (int)($m['siteId'] ?? 0) === $siteId;
    }));

    $menus = array_map('sb_normalize_menu_record', $menus);

    usort($menus, static function ($a, $b) {
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });

    $site = sb_find_site($siteId);
    $topMenuId = $site ? (int)($site['topMenuId'] ?? 0) : 0;

    sb_json_ok([
        'menus' => $menus,
        'topMenuId' => $topMenuId,
    ]);
}

if ($action === 'menu.create') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    if ($name === '') {
        sb_json_error('NAME_REQUIRED', 422);
    }

    sb_require_editor($siteId);

    $menus = sb_read_menus();

    $menu = [
        'id' => sb_next_menu_id($menus),
        'siteId' => $siteId,
        'name' => $name,
        'items' => [],
        'createdBy' => (int)$USER->GetID(),
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'updatedBy' => (int)$USER->GetID(),
    ];

    $menus[] = $menu;
    sb_write_menus($menus);

    sb_json_ok([
        'menu' => sb_normalize_menu_record($menu),
    ]);
}

if ($action === 'menu.update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));

    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }
    if ($name === '') {
        sb_json_error('NAME_REQUIRED', 422);
    }

    $menu = sb_find_menu($id);
    if (!$menu) {
        sb_json_error('MENU_NOT_FOUND', 404);
    }

    $siteId = (int)($menu['siteId'] ?? 0);
    sb_require_editor($siteId);

    $menus = sb_read_menus();
    $updated = null;

    foreach ($menus as &$m) {
        if ((int)($m['id'] ?? 0) === $id) {
            $m['name'] = $name;
            $m['updatedAt'] = date('c');
            $m['updatedBy'] = (int)$USER->GetID();
            $updated = $m;
            break;
        }
    }
    unset($m);

    if (!$updated) {
        sb_json_error('MENU_NOT_FOUND', 404);
    }

    sb_write_menus($menus);

    sb_json_ok([
        'menu' => sb_normalize_menu_record($updated),
    ]);
}

if ($action === 'menu.delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    $menu = sb_find_menu($id);
    if (!$menu) {
        sb_json_error('MENU_NOT_FOUND', 404);
    }

    $siteId = (int)($menu['siteId'] ?? 0);
    sb_require_editor($siteId);

    $menus = sb_read_menus();
    $before = count($menus);

    $menus = array_values(array_filter($menus, static function ($m) use ($id) {
        return (int)($m['id'] ?? 0) !== $id;
    }));

    if (count($menus) === $before) {
        sb_json_error('MENU_NOT_FOUND', 404);
    }

    sb_write_menus($menus);

    $sites = sb_read_sites();
    $sitesChanged = false;

    foreach ($sites as &$s) {
        if ((int)($s['id'] ?? 0) === $siteId && (int)($s['topMenuId'] ?? 0) === $id) {
            $s['topMenuId'] = 0;
            $s['updatedAt'] = date('c');
            $s['updatedBy'] = (int)$USER->GetID();
            $sitesChanged = true;
        }
    }
    unset($s);

    if ($sitesChanged) {
        sb_write_sites($sites);
    }

    sb_json_ok();
}

if ($action === 'menu.setTop') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $menuId = (int)($_POST['menuId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_editor($siteId);

    if ($menuId > 0) {
        $menu = sb_find_menu($menuId);
        if (!$menu || (int)($menu['siteId'] ?? 0) !== $siteId) {
            sb_json_error('MENU_NOT_IN_SITE', 422);
        }
    }

    $sites = sb_read_sites();
    $found = false;

    foreach ($sites as &$s) {
        if ((int)($s['id'] ?? 0) === $siteId) {
            $s['topMenuId'] = $menuId;
            $s['updatedAt'] = date('c');
            $s['updatedBy'] = (int)$USER->GetID();
            $found = true;
            break;
        }
    }
    unset($s);

    if (!$found) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }

    sb_write_sites($sites);
    sb_json_ok();
}

if ($action === 'menu.item.add') {
    $menuId = (int)($_POST['menuId'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $type = trim((string)($_POST['type'] ?? 'page'));
    $pageId = (int)($_POST['pageId'] ?? 0);
    $url = trim((string)($_POST['url'] ?? ''));
    $target = trim((string)($_POST['target'] ?? '_self'));

    if ($menuId <= 0) {
        sb_json_error('MENU_ID_REQUIRED', 422);
    }
    if ($title === '') {
        sb_json_error('TITLE_REQUIRED', 422);
    }
    if (!in_array($type, ['page', 'url'], true)) {
        sb_json_error('BAD_ITEM_TYPE', 422);
    }
    if (!in_array($target, ['_self', '_blank'], true)) {
        $target = '_self';
    }

    $menu = sb_find_menu($menuId);
    if (!$menu) {
        sb_json_error('MENU_NOT_FOUND', 404);
    }

    $siteId = (int)($menu['siteId'] ?? 0);
    sb_require_editor($siteId);

    if ($type === 'page') {
        if ($pageId <= 0) {
            sb_json_error('PAGE_ID_REQUIRED', 422);
        }

        $page = sb_find_page($pageId);
        if (!$page || (int)($page['siteId'] ?? 0) !== $siteId) {
            sb_json_error('PAGE_NOT_IN_SITE', 422);
        }

        $url = '';
    } else {
        $pageId = 0;
    }

    $menus = sb_read_menus();
    $updatedMenu = null;

    foreach ($menus as &$m) {
        if ((int)($m['id'] ?? 0) !== $menuId) {
            continue;
        }

        if (!isset($m['items']) || !is_array($m['items'])) {
            $m['items'] = [];
        }

        $item = [
            'id' => sb_next_menu_item_id($m['items']),
            'title' => $title,
            'type' => $type,
            'pageId' => $pageId,
            'url' => $url,
            'target' => $target,
            'sort' => sb_menu_next_item_sort($m['items']),
        ];

        $m['items'][] = $item;
        $m['updatedAt'] = date('c');
        $m['updatedBy'] = (int)$USER->GetID();
        $updatedMenu = $m;
        break;
    }
    unset($m);

    if (!$updatedMenu) {
        sb_json_error('MENU_NOT_FOUND', 404);
    }

    sb_write_menus($menus);

    sb_json_ok([
        'menu' => sb_normalize_menu_record($updatedMenu),
    ]);
}

if ($action === 'menu.item.update') {
    $menuId = (int)($_POST['menuId'] ?? 0);
    $itemId = (int)($_POST['itemId'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $type = trim((string)($_POST['type'] ?? 'page'));
    $pageId = (int)($_POST['pageId'] ?? 0);
    $url = trim((string)($_POST['url'] ?? ''));
    $target = trim((string)($_POST['target'] ?? '_self'));

    if ($menuId <= 0) {
        sb_json_error('MENU_ID_REQUIRED', 422);
    }
    if ($itemId <= 0) {
        sb_json_error('ITEM_ID_REQUIRED', 422);
    }
    if ($title === '') {
        sb_json_error('TITLE_REQUIRED', 422);
    }
    if (!in_array($type, ['page', 'url'], true)) {
        sb_json_error('BAD_ITEM_TYPE', 422);
    }
    if (!in_array($target, ['_self', '_blank'], true)) {
        $target = '_self';
    }

    $menu = sb_find_menu($menuId);
    if (!$menu) {
        sb_json_error('MENU_NOT_FOUND', 404);
    }

    $siteId = (int)($menu['siteId'] ?? 0);
    sb_require_editor($siteId);

    if ($type === 'page') {
        if ($pageId <= 0) {
            sb_json_error('PAGE_ID_REQUIRED', 422);
        }

        $page = sb_find_page($pageId);
        if (!$page || (int)($page['siteId'] ?? 0) !== $siteId) {
            sb_json_error('PAGE_NOT_IN_SITE', 422);
        }

        $url = '';
    } else {
        $pageId = 0;
    }

    $menus = sb_read_menus();
    $updatedMenu = null;
    $foundItem = false;

    foreach ($menus as &$m) {
        if ((int)($m['id'] ?? 0) !== $menuId) {
            continue;
        }

        if (!isset($m['items']) || !is_array($m['items'])) {
            $m['items'] = [];
        }

        foreach ($m['items'] as &$item) {
            if ((int)($item['id'] ?? 0) !== $itemId) {
                continue;
            }

            $item['title'] = $title;
            $item['type'] = $type;
            $item['pageId'] = $pageId;
            $item['url'] = $url;
            $item['target'] = $target;

            $foundItem = true;
            break;
        }
        unset($item);

        if ($foundItem) {
            $m['updatedAt'] = date('c');
            $m['updatedBy'] = (int)$USER->GetID();
            $updatedMenu = $m;
            break;
        }
    }
    unset($m);

    if (!$foundItem || !$updatedMenu) {
        sb_json_error('ITEM_NOT_FOUND', 404);
    }

    sb_write_menus($menus);

    sb_json_ok([
        'menu' => sb_normalize_menu_record($updatedMenu),
    ]);
}

if ($action === 'menu.item.delete') {
    $menuId = (int)($_POST['menuId'] ?? 0);
    $itemId = (int)($_POST['itemId'] ?? 0);

    if ($menuId <= 0) {
        sb_json_error('MENU_ID_REQUIRED', 422);
    }
    if ($itemId <= 0) {
        sb_json_error('ITEM_ID_REQUIRED', 422);
    }

    $menu = sb_find_menu($menuId);
    if (!$menu) {
        sb_json_error('MENU_NOT_FOUND', 404);
    }

    $siteId = (int)($menu['siteId'] ?? 0);
    sb_require_editor($siteId);

    $menus = sb_read_menus();
    $updatedMenu = null;
    $foundItem = false;

    foreach ($menus as &$m) {
        if ((int)($m['id'] ?? 0) !== $menuId) {
            continue;
        }

        if (!isset($m['items']) || !is_array($m['items'])) {
            $m['items'] = [];
        }

        $before = count($m['items']);
        $m['items'] = array_values(array_filter($m['items'], static function ($item) use ($itemId) {
            return (int)($item['id'] ?? 0) !== $itemId;
        }));

        if (count($m['items']) !== $before) {
            $foundItem = true;
            $m['updatedAt'] = date('c');
            $m['updatedBy'] = (int)$USER->GetID();
            $updatedMenu = $m;
        }

        break;
    }
    unset($m);

    if (!$foundItem) {
        sb_json_error('ITEM_NOT_FOUND', 404);
    }

    sb_write_menus($menus);

    sb_json_ok([
        'menu' => sb_normalize_menu_record($updatedMenu),
    ]);
}

if ($action === 'menu.item.move') {
    $menuId = (int)($_POST['menuId'] ?? 0);
    $itemId = (int)($_POST['itemId'] ?? 0);
    $dir = trim((string)($_POST['dir'] ?? ''));

    if ($menuId <= 0) {
        sb_json_error('MENU_ID_REQUIRED', 422);
    }
    if ($itemId <= 0) {
        sb_json_error('ITEM_ID_REQUIRED', 422);
    }
    if ($dir !== 'up' && $dir !== 'down') {
        sb_json_error('DIR_REQUIRED', 422);
    }

    $menu = sb_find_menu($menuId);
    if (!$menu) {
        sb_json_error('MENU_NOT_FOUND', 404);
    }

    $siteId = (int)($menu['siteId'] ?? 0);
    sb_require_editor($siteId);

    $menus = sb_read_menus();
    $updatedMenu = null;
    $foundMenu = false;

    foreach ($menus as &$m) {
        if ((int)($m['id'] ?? 0) !== $menuId) {
            continue;
        }

        $foundMenu = true;

        if (!isset($m['items']) || !is_array($m['items'])) {
            $m['items'] = [];
        }

        usort($m['items'], static function ($a, $b) {
            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
            if ($sortCmp !== 0) {
                return $sortCmp;
            }
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        $pos = null;
        for ($i = 0, $cnt = count($m['items']); $i < $cnt; $i++) {
            if ((int)($m['items'][$i]['id'] ?? 0) === $itemId) {
                $pos = $i;
                break;
            }
        }

        if ($pos === null) {
            sb_json_error('ITEM_NOT_FOUND', 404);
        }

        if ($dir === 'up' && $pos === 0) {
            $updatedMenu = $m;
            break;
        }

        if ($dir === 'down' && $pos === count($m['items']) - 1) {
            $updatedMenu = $m;
            break;
        }

        $swapPos = ($dir === 'up') ? $pos - 1 : $pos + 1;

        $sortA = (int)($m['items'][$pos]['sort'] ?? 500);
        $sortB = (int)($m['items'][$swapPos]['sort'] ?? 500);

        $m['items'][$pos]['sort'] = $sortB;
        $m['items'][$swapPos]['sort'] = $sortA;
        $m['updatedAt'] = date('c');
        $m['updatedBy'] = (int)$USER->GetID();

        $updatedMenu = $m;
        break;
    }
    unset($m);

    if (!$foundMenu) {
        sb_json_error('MENU_NOT_FOUND', 404);
    }

    sb_write_menus($menus);

    sb_json_ok([
        'menu' => sb_normalize_menu_record($updatedMenu),
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'menu',
    'action' => $action,
]);