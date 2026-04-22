<?php

if (!function_exists('sb_page_find_by_id')) {
    function sb_page_find_by_id(array $pages, int $id): ?array
    {
        foreach ($pages as $p) {
            if ((int)($p['id'] ?? 0) === $id) {
                return $p;
            }
        }
        return null;
    }
}

if (!function_exists('sb_page_find_index_by_id')) {
    function sb_page_find_index_by_id(array $pages, int $id): int
    {
        foreach ($pages as $k => $p) {
            if ((int)($p['id'] ?? 0) === $id) {
                return (int)$k;
            }
        }
        return -1;
    }
}

if (!function_exists('sb_page_is_descendant')) {
    function sb_page_is_descendant(array $pages, int $pageId, int $possibleParentId): bool
    {
        $current = sb_page_find_by_id($pages, $possibleParentId);
        $safety = 0;

        while ($current && $safety < 1000) {
            $parentId = (int)($current['parentId'] ?? 0);
            if ($parentId === 0) {
                return false;
            }
            if ($parentId === $pageId) {
                return true;
            }
            $current = sb_page_find_by_id($pages, $parentId);
            $safety++;
        }

        return false;
    }
}

if ($action === 'page.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_viewer($siteId);

    $pages = array_values(array_filter(sb_read_pages(), static function ($p) use ($siteId) {
        return (int)($p['siteId'] ?? 0) === $siteId;
    }));

    usort($pages, static function ($a, $b) {
        $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
        if ($sortCmp !== 0) {
            return $sortCmp;
        }
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });

    sb_json_ok([
        'pages' => array_values(array_map('sb_normalize_page_record', $pages)),
    ]);
}

if ($action === 'page.create') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $parentId = (int)($_POST['parentId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    if ($title === '') {
        sb_json_error('TITLE_REQUIRED', 422);
    }

    sb_require_editor($siteId);

    $pages = sb_read_pages();

    if ($parentId > 0) {
        $parent = sb_page_find_by_id($pages, $parentId);
        if (!$parent || (int)($parent['siteId'] ?? 0) !== $siteId) {
            sb_json_error('PARENT_PAGE_NOT_FOUND', 404);
        }
    }

    if ($slug === '') {
        $slug = sb_slugify($title);
    }

    $id = sb_next_id($pages, 'id');

    $maxSort = 0;
    foreach ($pages as $p) {
        if (
            (int)($p['siteId'] ?? 0) === $siteId &&
            (int)($p['parentId'] ?? 0) === $parentId
        ) {
            $maxSort = max($maxSort, (int)($p['sort'] ?? 0));
        }
    }

    $page = sb_normalize_page_record([
        'id' => $id,
        'siteId' => $siteId,
        'title' => $title,
        'slug' => $slug,
        'parentId' => $parentId,
        'sort' => $maxSort > 0 ? ($maxSort + 10) : 10,
        'status' => 'draft',
        'publishedAt' => null,
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
    ]);

    $pages[] = $page;
    sb_write_pages($pages);

    sb_json_ok([
        'page' => $page,
    ]);
}

if ($action === 'page.updateMeta') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $parentId = isset($_POST['parentId']) ? (int)$_POST['parentId'] : null;

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    if ($title === '') {
        sb_json_error('TITLE_REQUIRED', 422);
    }

    $pages = sb_read_pages();
    $index = sb_page_find_index_by_id($pages, $id);
    if ($index < 0) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $page = $pages[$index];
    $siteId = (int)($page['siteId'] ?? 0);

    sb_require_editor($siteId);

    if ($slug === '') {
        $slug = sb_slugify($title);
    }

    if ($parentId !== null) {
        if ($parentId === $id) {
            sb_json_error('PAGE_CANNOT_BE_OWN_PARENT', 422);
        }

        if ($parentId > 0) {
            $parent = sb_page_find_by_id($pages, $parentId);
            if (!$parent || (int)($parent['siteId'] ?? 0) !== $siteId) {
                sb_json_error('PARENT_PAGE_NOT_FOUND', 404);
            }

            if (sb_page_is_descendant($pages, $id, $parentId)) {
                sb_json_error('CYCLIC_PARENT_RELATION', 422);
            }
        }

        $page['parentId'] = $parentId;
    }

    $page['title'] = $title;
    $page['slug'] = $slug;
    $page['updatedAt'] = date('c');

    $pages[$index] = sb_normalize_page_record($page);
    sb_write_pages($pages);

    sb_json_ok([
        'page' => $pages[$index],
    ]);
}

if ($action === 'page.setParent') {
    $id = (int)($_POST['id'] ?? 0);
    $parentId = (int)($_POST['parentId'] ?? 0);

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    $pages = sb_read_pages();
    $index = sb_page_find_index_by_id($pages, $id);
    if ($index < 0) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $page = $pages[$index];
    $siteId = (int)($page['siteId'] ?? 0);

    sb_require_editor($siteId);

    if ($parentId === $id) {
        sb_json_error('PAGE_CANNOT_BE_OWN_PARENT', 422);
    }

    if ($parentId > 0) {
        $parent = sb_page_find_by_id($pages, $parentId);
        if (!$parent || (int)($parent['siteId'] ?? 0) !== $siteId) {
            sb_json_error('PARENT_PAGE_NOT_FOUND', 404);
        }

        if (sb_page_is_descendant($pages, $id, $parentId)) {
            sb_json_error('CYCLIC_PARENT_RELATION', 422);
        }
    }

    $page['parentId'] = $parentId;
    $page['updatedAt'] = date('c');

    $pages[$index] = sb_normalize_page_record($page);
    sb_write_pages($pages);

    sb_json_ok([
        'page' => $pages[$index],
    ]);
}

if ($action === 'page.setStatus') {
    $id = (int)($_POST['id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    if (!in_array($status, ['draft', 'published'], true)) {
        sb_json_error('INVALID_STATUS', 422);
    }

    $pages = sb_read_pages();
    $index = sb_page_find_index_by_id($pages, $id);
    if ($index < 0) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $page = $pages[$index];
    $siteId = (int)($page['siteId'] ?? 0);

    sb_require_editor($siteId);

    $page['status'] = $status;
    $page['publishedAt'] = $status === 'published' ? date('c') : null;
    $page['updatedAt'] = date('c');

    $pages[$index] = sb_normalize_page_record($page);
    sb_write_pages($pages);

    sb_json_ok([
        'page' => $pages[$index],
    ]);
}

if ($action === 'page.move') {
    $id = (int)($_POST['id'] ?? 0);
    $dir = trim((string)($_POST['dir'] ?? ''));

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    if (!in_array($dir, ['up', 'down'], true)) {
        sb_json_error('INVALID_DIR', 422);
    }

    $pages = sb_read_pages();
    $page = sb_page_find_by_id($pages, $id);
    if (!$page) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $siteId = (int)($page['siteId'] ?? 0);
    $parentId = (int)($page['parentId'] ?? 0);

    sb_require_editor($siteId);

    $siblings = [];
    foreach ($pages as $k => $p) {
        if (
            (int)($p['siteId'] ?? 0) === $siteId &&
            (int)($p['parentId'] ?? 0) === $parentId
        ) {
            $siblings[] = [
                'index' => $k,
                'row' => $p,
            ];
        }
    }

    usort($siblings, static function ($a, $b) {
        $sortCmp = (int)($a['row']['sort'] ?? 500) <=> (int)($b['row']['sort'] ?? 500);
        if ($sortCmp !== 0) {
            return $sortCmp;
        }
        return (int)($a['row']['id'] ?? 0) <=> (int)($b['row']['id'] ?? 0);
    });

    $pos = null;
    for ($i = 0; $i < count($siblings); $i++) {
        if ((int)($siblings[$i]['row']['id'] ?? 0) === $id) {
            $pos = $i;
            break;
        }
    }

    if ($pos === null) {
        sb_json_error('PAGE_NOT_FOUND_IN_SIBLINGS', 404);
    }

    $swapPos = $dir === 'up' ? $pos - 1 : $pos + 1;
    if (!isset($siblings[$swapPos])) {
        sb_json_ok(['moved' => false]);
    }

    $aIndex = $siblings[$pos]['index'];
    $bIndex = $siblings[$swapPos]['index'];

    $aSort = (int)($pages[$aIndex]['sort'] ?? 500);
    $bSort = (int)($pages[$bIndex]['sort'] ?? 500);

    $pages[$aIndex]['sort'] = $bSort;
    $pages[$aIndex]['updatedAt'] = date('c');

    $pages[$bIndex]['sort'] = $aSort;
    $pages[$bIndex]['updatedAt'] = date('c');

    $pages[$aIndex] = sb_normalize_page_record($pages[$aIndex]);
    $pages[$bIndex] = sb_normalize_page_record($pages[$bIndex]);

    sb_write_pages($pages);

    sb_json_ok(['moved' => true]);
}

if ($action === 'page.delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    $pages = sb_read_pages();
    $page = sb_page_find_by_id($pages, $id);
    if (!$page) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $siteId = (int)($page['siteId'] ?? 0);
    sb_require_editor($siteId);

    $idsToDelete = [$id => true];
    $changed = true;
    $safety = 0;

    while ($changed && $safety < 1000) {
        $changed = false;
        foreach ($pages as $p) {
            $pid = (int)($p['id'] ?? 0);
            $parentId = (int)($p['parentId'] ?? 0);
            if ($pid > 0 && !isset($idsToDelete[$pid]) && isset($idsToDelete[$parentId])) {
                $idsToDelete[$pid] = true;
                $changed = true;
            }
        }
        $safety++;
    }

    $pages = array_values(array_filter($pages, static function ($p) use ($idsToDelete) {
        return !isset($idsToDelete[(int)($p['id'] ?? 0)]);
    }));
    sb_write_pages($pages);

    $blocks = sb_read_blocks();
    $blocks = array_values(array_filter($blocks, static function ($b) use ($idsToDelete) {
        return !isset($idsToDelete[(int)($b['pageId'] ?? 0)]);
    }));
    sb_write_blocks($blocks);

    sb_json_ok([
        'deleted' => true,
        'deletedPageIds' => array_map('intval', array_keys($idsToDelete)),
    ]);
}

if ($action === 'page.duplicate') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    $pages = sb_read_pages();
    $source = sb_page_find_by_id($pages, $id);
    if (!$source) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $siteId = (int)($source['siteId'] ?? 0);
    sb_require_editor($siteId);

    $newId = sb_next_id($pages, 'id');

    $maxSort = 0;
    foreach ($pages as $p) {
        if (
            (int)($p['siteId'] ?? 0) === $siteId &&
            (int)($p['parentId'] ?? 0) === (int)($source['parentId'] ?? 0)
        ) {
            $maxSort = max($maxSort, (int)($p['sort'] ?? 0));
        }
    }

    $copy = sb_normalize_page_record([
        'id' => $newId,
        'siteId' => $siteId,
        'title' => (string)($source['title'] ?? '') . ' (копия)',
        'slug' => sb_slugify((string)($source['slug'] ?? 'page') . '-' . $newId),
        'parentId' => (int)($source['parentId'] ?? 0),
        'sort' => $maxSort > 0 ? ($maxSort + 10) : ((int)($source['sort'] ?? 10) + 10),
        'status' => 'draft',
        'publishedAt' => null,
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
    ]);

    $pages[] = $copy;
    sb_write_pages($pages);

    $blocks = sb_read_blocks();
    $sourceBlocks = array_values(array_filter($blocks, static function ($b) use ($id) {
        return (int)($b['pageId'] ?? 0) === $id;
    }));

    foreach ($sourceBlocks as $b) {
        $newBlockId = sb_next_id($blocks, 'id');
        $newBlock = sb_normalize_block_record([
            'id' => $newBlockId,
            'pageId' => $newId,
            'type' => (string)($b['type'] ?? 'text'),
            'sort' => (int)($b['sort'] ?? 500),
            'content' => is_array($b['content'] ?? null) ? $b['content'] : [],
            'props' => is_array($b['props'] ?? null) ? $b['props'] : [],
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
        ]);
        $blocks[] = $newBlock;
    }

    sb_write_blocks($blocks);

    sb_json_ok([
        'page' => $copy,
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'page',
    'action' => $action,
]);