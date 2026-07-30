<?php
define('NO_KEEP_STATISTIC',true);define('NO_AGENT_STATISTIC',true);define('NOT_CHECK_PERMISSIONS',true);
require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/ExternalJobWorker.php';
sitebuilder_require_bitrix_admin();
$result=null;$error='';if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){if(!check_bitrix_sessid())$error='Сессия устарела.';else try{$result=ExternalJobWorker::runBatch((int)($_POST['limit']??20));}catch(Throwable $e){error_log('SiteBuilder queue worker failed: '.$e->getMessage());$error='Worker завершился с ошибкой. Подробности записаны в журнал PHP.';}}
function sbQEsc(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="ru"><head><meta charset="UTF-8"><title>Worker SiteBuilder</title><style>body{font-family:Arial;background:#f3f6fb;padding:32px}.card{max-width:760px;margin:auto;background:#fff;padding:28px;border-radius:16px}pre{background:#0f172a;color:#e2e8f0;padding:16px;border-radius:10px}button,input{padding:10px}</style></head><body><div class="card"><h1>Очередь внешних операций</h1><p>Обрабатывает создание и очистку групп/папок, членство пользователей и синхронизацию прав.</p><?php if($error):?><p style="color:#991b1b"><?=sbQEsc($error)?></p><?php endif?><?php if(is_array($result)):?><pre><?=sbQEsc(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))?></pre><?php endif?><form method="post"><?=bitrix_sessid_post()?><label>Лимит <input type="number" name="limit" min="1" max="200" value="20"></label> <button>Запустить</button></form></div></body></html>
