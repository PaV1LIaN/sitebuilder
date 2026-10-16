<?php
// Backward-compatible alias. Only allowlisted local query parameters are forwarded.
$query = ['view'=>'groups'];
foreach (['groupId','ruleId','siteId','pageId','blockId','folderId'] as $key) {
    $id = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
    if ($id !== false && $id > 0) $query[$key] = $id;
}
if (in_array($_GET['kind'] ?? '', ['site','page','disk','folder'], true)) $query['kind']=$_GET['kind'];
header('Cache-Control: no-store, private');
header('Location: /local/sitebuilder/access.php?' . http_build_query($query), true, 302);
exit;
