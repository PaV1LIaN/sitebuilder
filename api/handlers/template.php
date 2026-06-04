<?php

global $USER;

if ($action === 'template.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_viewer($siteId);

    $templates = array_map('sb_normalize_template_record', sb_templates_for_site($siteId));

    sb_json_ok([
        'templates' => $templates,
    ]);
}

if ($action === 'template.createFromPage') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $pageId = (int)($_POST['pageId'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    if ($pageId <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }
    if ($name === '') {
        sb_json_error('NAME_REQUIRED', 422);
    }

    sb_require_content_manager($siteId);

    $page = sb_find_page($pageId);
    if (!$page || (int)($page['siteId'] ?? 0) !== $siteId) {
        sb_json_error('PAGE_NOT_IN_SITE', 422);
    }

    $pageBlocks = sb_blocks_for_page($pageId);

    $storedBlocks = [];
    foreach ($pageBlocks as $block) {
        $copy = sb_normalize_block_record($block);
        unset($copy['pageId']);
        $storedBlocks[] = $copy;
    }

    $templates = sb_read_templates();

    $template = [
        'id' => sb_next_template_id($templates),
        'siteId' => $siteId,
        'name' => $name,
        'sourcePageId' => $pageId,
        'blocks' => $storedBlocks,
        'createdBy' => (int)$USER->GetID(),
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'updatedBy' => (int)$USER->GetID(),
    ];

    $templates[] = $template;
    sb_write_templates($templates);

    sb_json_ok([
        'template' => sb_normalize_template_record($template),
    ]);
}

if ($action === 'template.applyToPage') {
    $templateId = (int)($_POST['templateId'] ?? 0);
    $pageId = (int)($_POST['pageId'] ?? 0);

    if ($templateId <= 0) {
        sb_json_error('TEMPLATE_ID_REQUIRED', 422);
    }
    if ($pageId <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    $template = sb_find_template($templateId);
    if (!$template) {
        sb_json_error('TEMPLATE_NOT_FOUND', 404);
    }

    $page = sb_find_page($pageId);
    if (!$page) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $siteId = (int)($page['siteId'] ?? 0);
    if ((int)($template['siteId'] ?? 0) !== $siteId) {
        sb_json_error('TEMPLATE_NOT_IN_SITE', 422);
    }

    sb_require_content_manager($siteId);

    $blocks = sb_read_blocks();

    $blocks = array_values(array_filter($blocks, static function ($b) use ($pageId) {
        return (int)($b['pageId'] ?? 0) !== $pageId;
    }));

    $nextBlockId = sb_next_block_id($blocks);

    $templateBlocks = $template['blocks'] ?? [];
    usort($templateBlocks, static function ($a, $b) {
        $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
        if ($sortCmp !== 0) {
            return $sortCmp;
        }
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });

    $sort = 10;
    foreach ($templateBlocks as $tplBlock) {
        $newBlock = sb_normalize_block_record($tplBlock);
        $newBlock['id'] = $nextBlockId++;
        $newBlock['pageId'] = $pageId;
        $newBlock['sort'] = $sort;
        $newBlock['createdBy'] = (int)$USER->GetID();
        $newBlock['createdAt'] = date('c');
        $newBlock['updatedAt'] = date('c');
        $newBlock['updatedBy'] = (int)$USER->GetID();
        $blocks[] = $newBlock;
        $sort += 10;
    }

    sb_write_blocks($blocks);

    sb_json_ok([
        'blocks' => array_map('sb_normalize_block_record', sb_blocks_for_page($pageId)),
    ]);
}

if ($action === 'template.rename') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));

    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }
    if ($name === '') {
        sb_json_error('NAME_REQUIRED', 422);
    }

    $template = sb_find_template($id);
    if (!$template) {
        sb_json_error('TEMPLATE_NOT_FOUND', 404);
    }

    $siteId = (int)($template['siteId'] ?? 0);
    sb_require_content_manager($siteId);

    $templates = sb_read_templates();
    $updated = null;

    foreach ($templates as &$tpl) {
        if ((int)($tpl['id'] ?? 0) === $id) {
            $tpl['name'] = $name;
            $tpl['updatedAt'] = date('c');
            $tpl['updatedBy'] = (int)$USER->GetID();
            $updated = $tpl;
            break;
        }
    }
    unset($tpl);

    if (!$updated) {
        sb_json_error('TEMPLATE_NOT_FOUND', 404);
    }

    sb_write_templates($templates);

    sb_json_ok([
        'template' => sb_normalize_template_record($updated),
    ]);
}

if ($action === 'template.delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    $template = sb_find_template($id);
    if (!$template) {
        sb_json_error('TEMPLATE_NOT_FOUND', 404);
    }

    $siteId = (int)($template['siteId'] ?? 0);
    sb_require_content_manager($siteId);

    $templates = sb_read_templates();
    $before = count($templates);

    $templates = array_values(array_filter($templates, static function ($tpl) use ($id) {
        return (int)($tpl['id'] ?? 0) !== $id;
    }));

    if (count($templates) === $before) {
        sb_json_error('TEMPLATE_NOT_FOUND', 404);
    }

    sb_write_templates($templates);

    sb_json_ok();
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'template',
    'action' => $action,
]);