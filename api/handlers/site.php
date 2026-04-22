<?php

global $USER;

if ($action === 'site.list') {
    $sites = sb_read_sites();
    $myCode = sb_user_access_code();

    $access = sb_read_access();
    $allowedSiteIds = [];

    foreach ($access as $r) {
        if ((string)($r['accessCode'] ?? '') === $myCode) {
            $sid = (int)($r['siteId'] ?? 0);
            if ($sid > 0) {
                $allowedSiteIds[$sid] = true;
            }
        }
    }

    $sites = array_values(array_filter($sites, static function ($s) use ($allowedSiteIds) {
        return isset($allowedSiteIds[(int)($s['id'] ?? 0)]);
    }));

    usort($sites, static function ($a, $b) {
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });

    sb_json_ok([
        'sites' => $sites,
        'handler' => 'site',
        'file' => __FILE__,
    ]);
}

if ($action === 'site.get') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_viewer($siteId);

    $site = sb_find_site($siteId);
    if (!$site) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }

    sb_json_ok([
        'site' => $site,
        'handler' => 'site',
        'file' => __FILE__,
    ]);
}

if ($action === 'site.create') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        sb_json_error('NAME_REQUIRED', 422);
    }

    $sites = sb_read_sites();

    $maxId = 0;
    foreach ($sites as $s) {
        $maxId = max($maxId, (int)($s['id'] ?? 0));
    }
    $id = $maxId + 1;

    $slug = trim((string)($_POST['slug'] ?? ''));
    $slug = $slug === '' ? sb_slugify($name) : sb_slugify($slug);

    $existing = array_map(static function ($x) {
        return (string)($x['slug'] ?? '');
    }, $sites);

    $base = $slug !== '' ? $slug : 'site';
    $slug = $base;
    $i = 2;
    while (in_array($slug, $existing, true)) {
        $slug = $base . '-' . $i;
        $i++;
    }

    $site = [
        'id' => $id,
        'name' => $name,
        'slug' => $slug,
        'createdBy' => (int)$USER->GetID(),
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'updatedBy' => (int)$USER->GetID(),
        'homePageId' => 0,
        'diskFolderId' => 0,
        'topMenuId' => 0,
        'settings' => [
            'containerWidth' => 1100,
            'accent' => '#2563eb',
            'logoFileId' => 0,
        ],
        'layout' => [
            'showHeader' => true,
            'showFooter' => true,
            'showLeft' => false,
            'showRight' => false,
            'leftWidth' => 260,
            'rightWidth' => 260,
            'leftMode' => 'blocks',
        ],
    ];

    $sites[] = $site;
    sb_write_sites($sites);

    $access = sb_read_access();
    $access[] = [
        'siteId' => $id,
        'accessCode' => 'U' . (int)$USER->GetID(),
        'role' => 'OWNER',
        'createdBy' => (int)$USER->GetID(),
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'updatedBy' => (int)$USER->GetID(),
    ];
    sb_write_access($access);

    sb_json_ok([
        'site' => $site,
        'handler' => 'site',
        'file' => __FILE__,
    ]);
}

if ($action === 'site.update') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_editor($siteId);

    $site = sb_find_site($siteId);
    if (!$site) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $containerWidth = (int)($_POST['containerWidth'] ?? 0);
    $accent = trim((string)($_POST['accent'] ?? ''));
    $logoFileId = (int)($_POST['logoFileId'] ?? 0);

    if ($name === '') {
        $name = (string)($site['name'] ?? '');
    }

    if ($slug === '') {
        $slug = (string)($site['slug'] ?? '');
    }
    $slug = sb_slugify($slug);
    if ($slug === '') {
        $slug = 'site-' . $siteId;
    }

    $sites = sb_read_sites();

    $existing = array_map(
        static function ($x) {
            return (string)($x['slug'] ?? '');
        },
        array_filter($sites, static function ($s) use ($siteId) {
            return (int)($s['id'] ?? 0) !== $siteId;
        })
    );

    $base = $slug;
    $i = 2;
    while (in_array($slug, $existing, true)) {
        $slug = $base . '-' . $i;
        $i++;
    }

    if ($containerWidth <= 0) {
        $containerWidth = (int)($site['settings']['containerWidth'] ?? 1100);
    }
    $containerWidth = max(320, min(1920, $containerWidth));

    if ($accent === '') {
        $accent = (string)($site['settings']['accent'] ?? '#2563eb');
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $accent = '#2563eb';
    }

    $updated = null;

    foreach ($sites as &$s) {
        if ((int)($s['id'] ?? 0) !== $siteId) {
            continue;
        }

        $settings = isset($s['settings']) && is_array($s['settings']) ? $s['settings'] : [];
        $settings['containerWidth'] = $containerWidth;
        $settings['accent'] = $accent;
        $settings['logoFileId'] = $logoFileId;

        $s['name'] = $name;
        $s['slug'] = $slug;
        $s['settings'] = $settings;
        $s['updatedAt'] = date('c');
        $s['updatedBy'] = (int)$USER->GetID();

        $updated = $s;
        break;
    }
    unset($s);

    if (!$updated) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }

    sb_write_sites($sites);

    sb_json_ok([
        'site' => $updated,
        'handler' => 'site',
        'file' => __FILE__,
    ]);
}

if ($action === 'site.delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    sb_require_owner($id);

    $sites = sb_read_sites();
    $before = count($sites);

    $sites = array_values(array_filter($sites, static function ($s) use ($id) {
        return (int)($s['id'] ?? 0) !== $id;
    }));

    if (count($sites) === $before) {
        sb_json_error('NOT_FOUND', 404);
    }

    sb_write_sites($sites);

    $pages = sb_read_pages();
    $deletedPageIds = [];
    foreach ($pages as $p) {
        if ((int)($p['siteId'] ?? 0) === $id) {
            $deletedPageIds[(int)($p['id'] ?? 0)] = true;
        }
    }

    $pages = array_values(array_filter($pages, static function ($p) use ($id) {
        return (int)($p['siteId'] ?? 0) !== $id;
    }));
    sb_write_pages($pages);

    $blocks = sb_read_blocks();
    $blocks = array_values(array_filter($blocks, static function ($b) use ($deletedPageIds) {
        return !isset($deletedPageIds[(int)($b['pageId'] ?? 0)]);
    }));
    sb_write_blocks($blocks);

    $access = sb_read_access();
    $access = array_values(array_filter($access, static function ($r) use ($id) {
        return (int)($r['siteId'] ?? 0) !== $id;
    }));
    sb_write_access($access);

    $menus = sb_read_menus();
    $menus = array_values(array_filter($menus, static function ($m) use ($id) {
        return (int)($m['siteId'] ?? 0) !== $id;
    }));
    sb_write_menus($menus);

    $templates = sb_read_templates();
    $templates = array_values(array_filter($templates, static function ($tpl) use ($id) {
        return (int)($tpl['siteId'] ?? 0) !== $id;
    }));
    sb_write_templates($templates);

    $layouts = sb_read_layouts();
    $layouts = array_values(array_filter($layouts, static function ($layout) use ($id) {
        return (int)($layout['siteId'] ?? 0) !== $id;
    }));
    sb_write_layouts($layouts);

    sb_json_ok([
        'handler' => 'site',
        'file' => __FILE__,
    ]);
}

if ($action === 'site.setHome') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $pageId = (int)($_POST['pageId'] ?? 0);

    if ($siteId <= 0 || $pageId <= 0) {
        sb_json_error('SITE_PAGE_REQUIRED', 422);
    }

    sb_require_editor($siteId);

    $page = sb_find_page($pageId);
    if (!$page || (int)($page['siteId'] ?? 0) !== $siteId) {
        sb_json_error('PAGE_NOT_IN_SITE', 422);
    }

    $sites = sb_read_sites();
    $found = false;

    foreach ($sites as $i => $s) {
        if ((int)($s['id'] ?? 0) === $siteId) {
            $sites[$i]['homePageId'] = $pageId;
            $sites[$i]['updatedAt'] = date('c');
            $sites[$i]['updatedBy'] = (int)$USER->GetID();
            $found = true;
            break;
        }
    }

    if (!$found) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }

    sb_write_sites($sites);

    sb_json_ok([
        'handler' => 'site',
        'file' => __FILE__,
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'site',
    'action' => $action,
    'file' => __FILE__,
]);