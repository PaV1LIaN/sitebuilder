<?php

require_once __DIR__ . '/bootstrap.php';

$action = (string)($_POST['action'] ?? '');

if ($action === 'ping') {
    require __DIR__ . '/handlers/common.php';
    exit;
}

if (
    $action === 'site.list' ||
    $action === 'site.get' ||
    $action === 'site.create' ||
    $action === 'site.update' ||
    $action === 'site.delete' ||
    $action === 'site.setHome' ||
    $action === 'site.syncAccess' ||
    $action === 'site.ensureGroup' ||
    $action === 'site.accessList' ||
    $action === 'site.accessSet' ||
    $action === 'site.accessRemove' ||
    $action === 'site.appearanceGet' ||
    $action === 'site.appearanceUpdate' ||
    $action === 'site.appearanceUpload' ||
    $action === 'site.appearanceRemove'
) {
    require __DIR__ . '/handlers/site.php';
    exit;
}

if (
    $action === 'page.list' ||
    $action === 'page.create' ||
    $action === 'page.delete' ||
    $action === 'page.duplicate' ||
    $action === 'page.updateMeta' ||
    $action === 'page.setStatus' ||
    $action === 'page.setParent' ||
    $action === 'page.move'
) {
    require __DIR__ . '/handlers/page.php';
    exit;
}

if (
    $action === 'menu.list' ||
    $action === 'menu.create' ||
    $action === 'menu.update' ||
    $action === 'menu.delete' ||
    $action === 'menu.setTop' ||
    $action === 'menu.item.add' ||
    $action === 'menu.item.update' ||
    $action === 'menu.item.delete' ||
    $action === 'menu.item.move'
) {
    require __DIR__ . '/handlers/menu.php';
    exit;
}

if (
    $action === 'block.list' ||
    $action === 'block.create' ||
    $action === 'block.update' ||
    $action === 'block.delete' ||
    $action === 'block.duplicate' ||
    $action === 'block.move' ||
    $action === 'block.reorder'
) {
    require __DIR__ . '/handlers/block.php';
    exit;
}

if (
    $action === 'file.list' ||
    $action === 'file.upload' ||
    $action === 'file.delete'
) {
    require __DIR__ . '/handlers/file.php';
    exit;
}

if (
    $action === 'layout.get' ||
    $action === 'layout.updateSettings' ||
    $action === 'layout.block.list' ||
    $action === 'layout.block.create' ||
    $action === 'layout.block.update' ||
    $action === 'layout.block.delete' ||
    $action === 'layout.block.move'
) {
    require __DIR__ . '/handlers/layout.php';
    exit;
}

if (strpos($action, 'page.') === 0) {
    require __DIR__ . '/handlers/page.php';
    exit;
}

if (
    $action === 'section.list' ||
    $action === 'section.create' ||
    $action === 'section.update' ||
    $action === 'section.delete' ||
    $action === 'site.setSection'
) {
    require __DIR__ . '/handlers/section.php';
    exit;
}

if (
    $action === 'template.list' ||
    $action === 'template.get' ||
    $action === 'template.createFromSite' ||
    $action === 'template.update' ||
    $action === 'template.delete' ||
    $action === 'template.createSite'
) {
    require __DIR__ . '/handlers/template.php';
    exit;
}

if (
    $action === 'user.search'
) {
    require __DIR__ . '/handlers/user.php';
    exit;
}

sb_json_error('UNKNOWN_ACTION', 400, [
    'action' => $action,
    'file' => __FILE__,
]);
