<?php

require_once __DIR__ . '/bootstrap.php';

$action = (string)($_POST['action'] ?? '');

/*
 * Все изменяющие API-запросы выполняются в управляемой транзакции.
 * RequestLockService выбирает точечные advisory locks по action:
 * lifecycle сайта, дерево страниц, блоки страницы, menu, layout и т. д.
 * Независимые страницы и сайты больше не сериализуются общей блокировкой.
 *
 * site.delete и maintenance.run используют собственные транзакции.
 * Удаление сайта дополнительно получает exclusive lifecycle-lock.
 */
$readOnlyActions = [
    'ping',
    'site.list',
    'site.get',
    'site.accessList',
    'site.appearanceGet',
    'file.list',
    'page.list',
    'pageAccess.list',
    'menu.list',
    'block.list',
    'section.list',
    'template.list',
    'template.get',
    'user.search',
    'history.list',
    'history.get',
    'trash.list',
    'trash.get',
    'audit.list',
    'audit.get',
    'maintenance.status',
    'pageSection.list',
    'job.list',
    'job.get',
    'job.health',
    'system.alert.list',
    'system.alert.get',
    'external.resource.list',
    'external.reconcile.list',
    'external.reconcile.get',
    'backup.list',
    'backup.get',
    'integrity.list',
    'integrity.get',
    'globalBlock.list',
];

if (
    $action !== ''
    && $action !== 'site.delete'
    && $action !== 'maintenance.run'
    && $action !== 'job.run'
    && !in_array($action, $readOnlyActions, true)
) {
    sb_db_begin_request_transaction();
    RequestLockService::lockMutation($action, $_POST);
}

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
    $action === 'page.save' ||
    $action === 'page.updateMeta' ||
    $action === 'page.setStatus' ||
    $action === 'page.setParent' ||
    $action === 'page.move' ||
    $action === 'page.reorderTree'
) {
    require __DIR__ . '/handlers/page.php';
    exit;
}

if (
    $action === 'pageAccess.list' ||
    $action === 'pageAccess.save' ||
    $action === 'pageAccess.delete'
) {
    require __DIR__ . '/handlers/page_access.php';
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
    $action === 'layout.save' ||
    $action === 'layout.block.list' ||
    $action === 'layout.block.create' ||
    $action === 'layout.block.update' ||
    $action === 'layout.block.delete' ||
    $action === 'layout.block.move' ||
    $action === 'layout.block.relocate' ||
    $action === 'layout.block.duplicate'
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
    $action === 'globalBlock.list' ||
    $action === 'globalBlock.create' ||
    $action === 'globalBlock.update' ||
    $action === 'globalBlock.rename' ||
    $action === 'globalBlock.delete'
) {
    require __DIR__ . '/handlers/global_block.php';
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
    $action === 'trash.list' ||
    $action === 'trash.get' ||
    $action === 'trash.restore' ||
    $action === 'trash.purge'
) {
    require __DIR__ . '/handlers/trash.php';
    exit;
}

if (
    $action === 'history.list' ||
    $action === 'history.get' ||
    $action === 'history.restore'
) {
    require __DIR__ . '/handlers/history.php';
    exit;
}


if (
    $action === 'audit.list' ||
    $action === 'audit.get' ||
    $action === 'maintenance.status' ||
    $action === 'maintenance.run'
) {
    require __DIR__ . '/handlers/audit.php';
    exit;
}


if (
    $action === 'job.list' ||
    $action === 'job.health' ||
    $action === 'job.get' ||
    $action === 'job.retry' ||
    $action === 'job.cancel' ||
    $action === 'job.run'
) {
    require __DIR__ . '/handlers/job.php';
    exit;
}

if (
    $action === 'system.alert.list' ||
    $action === 'system.alert.get' ||
    $action === 'system.alert.ack' ||
    $action === 'system.alert.resolve' ||
    $action === 'external.resource.list' ||
    $action === 'external.resource.cleanup' ||
    $action === 'external.reconcile.list' ||
    $action === 'external.reconcile.get' ||
    $action === 'external.reconcile.enqueue'
) {
    require __DIR__ . '/handlers/system.php';
    exit;
}


if (
    $action === 'backup.list' ||
    $action === 'backup.get' ||
    $action === 'backup.create' ||
    $action === 'backup.import' ||
    $action === 'backup.verify' ||
    $action === 'backup.restore' ||
    $action === 'backup.delete' ||
    $action === 'integrity.list' ||
    $action === 'integrity.get' ||
    $action === 'integrity.run'
) {
    require __DIR__ . '/handlers/backup.php';
    exit;
}

if (
    $action === 'user.search'
) {
    require __DIR__ . '/handlers/user.php';
    exit;
}

if (
    $action === 'pageSection.list' ||
    $action === 'pageSection.create' ||
    $action === 'pageSection.createPreset' ||
    $action === 'pageSection.update' ||
    $action === 'pageSection.move' ||
    $action === 'pageSection.reorder' ||
    $action === 'pageSection.delete' ||
    $action === 'pageSection.assignBlock'
) {
    require __DIR__ . '/handlers/page_section.php';
    exit;
}

sb_json_error('UNKNOWN_ACTION', 400, [
    'action' => $action,
]);
