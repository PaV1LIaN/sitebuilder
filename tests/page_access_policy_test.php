<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/PageAccessPolicy.php';
require __DIR__ . '/../lib/AccessAdminService.php';
$checks = 0;
function eq($expected, $actual, string $why): void { global $checks; ++$checks; if ($expected !== $actual) throw new RuntimeException($why . ': ' . var_export($actual,true)); }
$deny = ['deny_view' => true, 'include_children' => true];
$allow = ['can_view' => true, 'include_children' => true];
$legacy = ['can_view' => false, 'can_edit' => false, 'include_children' => true];
eq(null, PageAccessPolicy::decide([30,20,10], [30=>$legacy]), 'Empty legacy record is not a denial');
eq(true, PageAccessPolicy::decide([30,20,10], [30=>$deny])['denied'], 'Direct denial');
eq(true, PageAccessPolicy::decide([30,20,10], [20=>$deny])['inherited'], 'Parent denial inherited');
eq(20, PageAccessPolicy::decide([30,20,10], [20=>$deny])['pageId'], 'Denial source identified');
eq(null, PageAccessPolicy::decide([30,20,10], [20=>['deny_view'=>true,'include_children'=>false]]), 'Noninherited parent ignored');
eq(false, PageAccessPolicy::decide([30,20,10], [30=>$allow,20=>$deny])['denied'], 'Explicit nearer view is an exception');
eq(true, PageAccessPolicy::decide([30,20,10], [30=>$legacy,20=>$deny])['denied'], 'Empty child does not cancel denial');
eq(true, PageAccessPolicy::decide([30,20,10], [30=>['can_disk_view'=>true],20=>$deny])['denied'], 'Disk-only assignment does not cancel page denial');
eq(true, PageAccessPolicy::decide([30,20,10], [30=>$deny,20=>$allow])['denied'], 'Explicit deny wins over inherited allow');
eq(true, PageAccessPolicy::decide([30], [30=>['deny_view'=>'t','can_view'=>true]])['denied'], 'Deny wins when legacy editor also set view');
eq(false, PageAccessPolicy::bool('f'), 'PostgreSQL false');
eq(false, PageAccessPolicy::bool('false'), 'Text false');
eq(false, PageAccessPolicy::bool(false), 'Native false');
$page=['kind'=>'page','pageDenyAvailable'=>true];
$change=AccessAdminService::normalizeChanges($page,[['userId'=>12,'role'=>'DENY','diskRole'=>'EDITOR','includeChildren'=>true]],[])[12];
eq(true,$change['deny_view'],'Explicit denial persisted');
eq(false,$change['can_view'],'Denial is not a positive page grant');
eq(false,$change['can_edit'],'Denial does not imply edit');
eq(true,$change['can_disk_edit'],'Disk grant kept for when denial is removed');
eq(false,$change['remove'],'Denied page record is never mistaken for empty assignment');
eq('DENY',AccessAdminService::assignment($page,['deny_view'=>'t','can_view'=>false])['role'],'Denial displayed');
eq('INHERIT',AccessAdminService::assignment($page,['can_view'=>'f','can_edit'=>'f'])['role'],'Legacy row still inherits');
$change=AccessAdminService::normalizeChanges($page,[['userId'=>12,'role'=>'INHERIT','diskRole'=>'INHERIT']],[])[12];
eq(true,$change['remove'],'Explicitly clear denial and grants');
$native=['kind'=>'folder','mode'=>'bitrix_disk'];
foreach(['ADD','FULL','DENY','EDITOR','VIEWER','INHERIT'] as $role){ eq($role,AccessAdminService::normalizeChanges($native,[['userId'=>12,'role'=>$role]],[])[12]['role'],'Native level allowed'); }
echo "Page denial policy checks passed ($checks checks)\n";
