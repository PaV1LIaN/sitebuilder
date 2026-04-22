<?php

global $USER;

if ($action === 'layout.get') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_viewer($siteId);

    $layout = sb_layout_ensure_record($siteId);

    sb_json_ok([
        'layout' => $layout,
        'handler' => 'layout',
        'file' => __FILE__,
    ]);
}

if ($action === 'layout.updateSettings') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_editor($siteId);

    $settingsRaw = $_POST['settings'] ?? null;
    if ($settingsRaw === null) {
        sb_json_error('SETTINGS_REQUIRED', 422);
    }

    if (is_array($settingsRaw)) {
        $settings = $settingsRaw;
    } else {
        $settings = json_decode((string)$settingsRaw, true);
        if (!is_array($settings)) {
            sb_json_error('BAD_SETTINGS_JSON', 422);
        }
    }

    $allowedKeys = [
        'showHeader',
        'showFooter',
        'showLeft',
        'showRight',
        'leftWidth',
        'rightWidth',
        'leftMode',
    ];

    $filtered = [];
    foreach ($allowedKeys as $key) {
        if (array_key_exists($key, $settings)) {
            $filtered[$key] = $settings[$key];
        }
    }

    if (isset($filtered['showHeader'])) {
        $filtered['showHeader'] = (bool)$filtered['showHeader'];
    }
    if (isset($filtered['showFooter'])) {
        $filtered['showFooter'] = (bool)$filtered['showFooter'];
    }
    if (isset($filtered['showLeft'])) {
        $filtered['showLeft'] = (bool)$filtered['showLeft'];
    }
    if (isset($filtered['showRight'])) {
        $filtered['showRight'] = (bool)$filtered['showRight'];
    }
    if (isset($filtered['leftWidth'])) {
        $filtered['leftWidth'] = max(120, min(800, (int)$filtered['leftWidth']));
    }
    if (isset($filtered['rightWidth'])) {
        $filtered['rightWidth'] = max(120, min(800, (int)$filtered['rightWidth']));
    }
    if (isset($filtered['leftMode'])) {
        $filtered['leftMode'] = in_array((string)$filtered['leftMode'], ['blocks', 'menu'], true)
            ? (string)$filtered['leftMode']
            : 'blocks';
    }

    $layouts = sb_read_layouts();
    $updated = null;
    $found = false;

    foreach ($layouts as &$layout) {
        if ((int)($layout['siteId'] ?? 0) !== $siteId) {
            continue;
        }

        $layout = sb_normalize_layout_record($layout);
        $layout['settings'] = array_merge($layout['settings'], $filtered);
        $layout['updatedAt'] = date('c');
        $layout['updatedBy'] = (int)$USER->GetID();

        $updated = $layout;
        $found = true;
        break;
    }
    unset($layout);

    if (!$found) {
        $updated = sb_layout_default_record($siteId);
        $updated['settings'] = array_merge($updated['settings'], $filtered);
        $updated['createdAt'] = date('c');
        $updated['createdBy'] = (int)$USER->GetID();
        $updated['updatedAt'] = date('c');
        $updated['updatedBy'] = (int)$USER->GetID();
        $layouts[] = $updated;
    }

    sb_write_layouts($layouts);

    sb_json_ok([
        'layout' => sb_normalize_layout_record($updated),
        'handler' => 'layout',
        'file' => __FILE__,
    ]);
}

if ($action === 'layout.block.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $zone = trim((string)($_POST['zone'] ?? ''));

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    if (!sb_layout_valid_zone($zone)) {
        sb_json_error('BAD_ZONE', 422);
    }

    sb_require_viewer($siteId);

    $layout = sb_layout_ensure_record($siteId);

    sb_json_ok([
        'blocks' => array_values($layout['zones'][$zone] ?? []),
        'zone' => $zone,
        'handler' => 'layout',
        'file' => __FILE__,
    ]);
}

if ($action === 'layout.block.create') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $zone = trim((string)($_POST['zone'] ?? ''));
    $type = trim((string)($_POST['type'] ?? 'text'));

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    if (!sb_layout_valid_zone($zone)) {
        sb_json_error('BAD_ZONE', 422);
    }
    if ($type === '') {
        sb_json_error('TYPE_REQUIRED', 422);
    }

    sb_require_editor($siteId);

    $layouts = sb_read_layouts();
    $updatedLayout = null;
    $newBlock = null;
    $found = false;

    foreach ($layouts as &$layout) {
        if ((int)($layout['siteId'] ?? 0) !== $siteId) {
            continue;
        }

        $layout = sb_normalize_layout_record($layout);

        $newBlock = [
            'id' => sb_layout_next_block_id($layout),
            'type' => $type,
            'sort' => sb_layout_next_block_sort($layout, $zone),
            'content' => sb_default_block_content($type),
            'props' => [],
            'createdBy' => (int)$USER->GetID(),
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
            'updatedBy' => (int)$USER->GetID(),
        ];

        $layout['zones'][$zone][] = $newBlock;
        $layout['updatedAt'] = date('c');
        $layout['updatedBy'] = (int)$USER->GetID();

        $updatedLayout = $layout;
        $found = true;
        break;
    }
    unset($layout);

    if (!$found) {
        $updatedLayout = sb_layout_default_record($siteId);

        $newBlock = [
            'id' => 1,
            'type' => $type,
            'sort' => 10,
            'content' => sb_default_block_content($type),
            'props' => [],
            'createdBy' => (int)$USER->GetID(),
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
            'updatedBy' => (int)$USER->GetID(),
        ];

        $updatedLayout['zones'][$zone][] = $newBlock;
        $updatedLayout['createdAt'] = date('c');
        $updatedLayout['createdBy'] = (int)$USER->GetID();
        $updatedLayout['updatedAt'] = date('c');
        $updatedLayout['updatedBy'] = (int)$USER->GetID();

        $layouts[] = $updatedLayout;
    }

    sb_write_layouts($layouts);

    sb_json_ok([
        'block' => sb_normalize_block_record($newBlock),
        'zone' => $zone,
        'layout' => sb_normalize_layout_record($updatedLayout),
        'handler' => 'layout',
        'file' => __FILE__,
    ]);
}

if ($action === 'layout.block.update') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $id = (int)($_POST['id'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    sb_require_editor($siteId);

    $contentRaw = $_POST['content'] ?? null;
    $propsRaw = $_POST['props'] ?? null;
    $typeRaw = $_POST['type'] ?? null;

    $newContent = null;
    $newProps = null;
    $newType = null;

    if ($contentRaw !== null) {
        if (is_array($contentRaw)) {
            $newContent = $contentRaw;
        } else {
            $decoded = json_decode((string)$contentRaw, true);
            if (!is_array($decoded)) {
                sb_json_error('BAD_CONTENT_JSON', 422);
            }
            $newContent = $decoded;
        }
    }

    if ($propsRaw !== null) {
        if (is_array($propsRaw)) {
            $newProps = $propsRaw;
        } else {
            $decoded = json_decode((string)$propsRaw, true);
            if (!is_array($decoded)) {
                sb_json_error('BAD_PROPS_JSON', 422);
            }
            $newProps = $decoded;
        }
    }

    if ($typeRaw !== null) {
        $newType = trim((string)$typeRaw);
        if ($newType === '') {
            sb_json_error('TYPE_REQUIRED', 422);
        }
    }

    $layouts = sb_read_layouts();
    $updatedBlock = null;
    $updatedLayout = null;
    $foundLayout = false;
    $foundBlock = false;

    foreach ($layouts as &$layout) {
        if ((int)($layout['siteId'] ?? 0) !== $siteId) {
            continue;
        }

        $foundLayout = true;
        $layout = sb_normalize_layout_record($layout);

        foreach (['header', 'footer', 'left', 'right'] as $zone) {
            foreach ($layout['zones'][$zone] as &$block) {
                if ((int)($block['id'] ?? 0) !== $id) {
                    continue;
                }

                if ($newType !== null) {
                    $block['type'] = $newType;
                }
                if ($newContent !== null) {
                    $block['content'] = $newContent;
                }
                if ($newProps !== null) {
                    $block['props'] = $newProps;
                }

                $block['updatedAt'] = date('c');
                $block['updatedBy'] = (int)$USER->GetID();
                $block['_zone'] = $zone;

                $updatedBlock = $block;
                $foundBlock = true;
                break 2;
            }
            unset($block);
        }

        if ($foundBlock) {
            $layout['updatedAt'] = date('c');
            $layout['updatedBy'] = (int)$USER->GetID();
            $updatedLayout = $layout;
            break;
        }
    }
    unset($layout);

    if (!$foundLayout) {
        sb_json_error('LAYOUT_NOT_FOUND', 404);
    }

    if (!$foundBlock || !$updatedBlock) {
        sb_json_error('BLOCK_NOT_FOUND', 404);
    }

    sb_write_layouts($layouts);

    sb_json_ok([
        'block' => sb_normalize_block_record($updatedBlock),
        'layout' => sb_normalize_layout_record($updatedLayout),
        'handler' => 'layout',
        'file' => __FILE__,
    ]);
}

if ($action === 'layout.block.delete') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $id = (int)($_POST['id'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    sb_require_editor($siteId);

    $layouts = sb_read_layouts();
    $updatedLayout = null;
    $foundLayout = false;
    $foundBlock = false;

    foreach ($layouts as &$layout) {
        if ((int)($layout['siteId'] ?? 0) !== $siteId) {
            continue;
        }

        $foundLayout = true;
        $layout = sb_normalize_layout_record($layout);

        foreach (['header', 'footer', 'left', 'right'] as $zone) {
            $before = count($layout['zones'][$zone]);
            $layout['zones'][$zone] = array_values(array_filter(
                $layout['zones'][$zone],
                static function ($block) use ($id) {
                    return (int)($block['id'] ?? 0) !== $id;
                }
            ));

            if (count($layout['zones'][$zone]) !== $before) {
                $foundBlock = true;
                $layout['updatedAt'] = date('c');
                $layout['updatedBy'] = (int)$USER->GetID();
                $updatedLayout = $layout;
                break;
            }
        }

        if ($foundBlock) {
            break;
        }
    }
    unset($layout);

    if (!$foundLayout) {
        sb_json_error('LAYOUT_NOT_FOUND', 404);
    }

    if (!$foundBlock) {
        sb_json_error('BLOCK_NOT_FOUND', 404);
    }

    sb_write_layouts($layouts);

    sb_json_ok([
        'layout' => sb_normalize_layout_record($updatedLayout),
        'handler' => 'layout',
        'file' => __FILE__,
    ]);
}

if ($action === 'layout.block.move') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $id = (int)($_POST['id'] ?? 0);
    $dir = trim((string)($_POST['dir'] ?? ''));

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }
    if ($dir !== 'up' && $dir !== 'down') {
        sb_json_error('DIR_REQUIRED', 422);
    }

    sb_require_editor($siteId);

    $layouts = sb_read_layouts();
    $updatedLayout = null;
    $foundLayout = false;
    $foundBlock = false;

    foreach ($layouts as &$layout) {
        if ((int)($layout['siteId'] ?? 0) !== $siteId) {
            continue;
        }

        $foundLayout = true;
        $layout = sb_normalize_layout_record($layout);

        foreach (['header', 'footer', 'left', 'right'] as $zone) {
            $siblings = $layout['zones'][$zone];

            $pos = null;
            for ($i = 0, $cnt = count($siblings); $i < $cnt; $i++) {
                if ((int)($siblings[$i]['id'] ?? 0) === $id) {
                    $pos = $i;
                    break;
                }
            }

            if ($pos === null) {
                continue;
            }

            $foundBlock = true;

            if ($dir === 'up' && $pos === 0) {
                $updatedLayout = $layout;
                break 2;
            }

            if ($dir === 'down' && $pos === count($siblings) - 1) {
                $updatedLayout = $layout;
                break 2;
            }

            $swapPos = ($dir === 'up') ? $pos - 1 : $pos + 1;

            $sortA = (int)($siblings[$pos]['sort'] ?? 500);
            $sortB = (int)($siblings[$swapPos]['sort'] ?? 500);

            $layout['zones'][$zone][$pos]['sort'] = $sortB;
            $layout['zones'][$zone][$pos]['updatedAt'] = date('c');
            $layout['zones'][$zone][$pos]['updatedBy'] = (int)$USER->GetID();

            $layout['zones'][$zone][$swapPos]['sort'] = $sortA;
            $layout['zones'][$zone][$swapPos]['updatedAt'] = date('c');
            $layout['zones'][$zone][$swapPos]['updatedBy'] = (int)$USER->GetID();

            usort($layout['zones'][$zone], static function ($a, $b) {
                $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
                if ($sortCmp !== 0) {
                    return $sortCmp;
                }
                return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
            });

            $layout['updatedAt'] = date('c');
            $layout['updatedBy'] = (int)$USER->GetID();
            $updatedLayout = $layout;
            break 2;
        }
    }
    unset($layout);

    if (!$foundLayout) {
        sb_json_error('LAYOUT_NOT_FOUND', 404);
    }

    if (!$foundBlock) {
        sb_json_error('BLOCK_NOT_FOUND', 404);
    }

    sb_write_layouts($layouts);

    sb_json_ok([
        'layout' => sb_normalize_layout_record($updatedLayout),
        'handler' => 'layout',
        'file' => __FILE__,
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'layout',
    'action' => $action,
    'file' => __FILE__,
]);