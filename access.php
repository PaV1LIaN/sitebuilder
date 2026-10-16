<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/AccessUserIdentity.php';
sitebuilder_require_auth();
if (!AccessUserIdentity::canOpenAdmin()) { http_response_code(403); exit('Нет прав на управление доступом.'); }
global $APPLICATION;
CJSCore::Init(['ajax']);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
$base = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$view = in_array($_GET['view'] ?? '', ['objects','groups','employees'], true) ? $_GET['view'] : 'objects';
$initial = [];
foreach (['siteId','pageId','blockId','folderId','groupId','ruleId','userId'] as $key) {
    $id = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
    if ($id !== false && $id > 0 && $id <= 2147483647) $initial[$key] = $id;
}
$initial['kind'] = in_array($_GET['kind'] ?? '', ['site','page','disk','folder'], true) ? $_GET['kind'] : 'site';
define('SB_ACCESS_CONSOLE_VIEW', true);
$config = ['basePath'=>$base,'sessid'=>bitrix_sessid(),'view'=>$view,'initial'=>$initial];
?>
<!doctype html><html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Управление доступом — Sitebuilder</title>
<?php $APPLICATION->ShowHead(); ?>
<link rel="stylesheet" href="<?= $h($base) ?>/assets/admin/groups.css?v=1">
<link rel="stylesheet" href="<?= $h($base) ?>/assets/admin/access-console.css?v=4">
</head><body class="sbg-body">
<?php require __DIR__ . '/views/access/' . ($view === 'groups' ? 'groups' : 'console') . '.php'; ?>
<script id="<?= $view==='groups'?'sbg-config':'uc-config' ?>" type="application/json"><?= json_encode($config, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= $h($base) ?>/assets/admin/<?= $view==='groups'?'groups':'access-console' ?>.js?v=4" defer></script>
</body></html>
