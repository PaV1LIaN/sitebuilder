<?php
define('NO_KEEP_STATISTIC',true);define('NO_AGENT_STATISTIC',true);define('NOT_CHECK_PERMISSIONS',true);
require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/db.php';
sitebuilder_require_bitrix_admin();
$message='';$error='';$migrationFile=dirname(__DIR__).'/migrations/20260729_005_external_cleanup_and_queue_health.sql';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 if(!check_bitrix_sessid())$error='Сессия устарела. Обновите страницу.';
 elseif(!is_file($migrationFile))$error='Файл миграции не найден.';
 else try{$sql=file_get_contents($migrationFile);if(!is_string($sql)||trim($sql)==='')throw new RuntimeException('EMPTY_MIGRATION');sb_db()->exec($sql);$message='Миграция этапа 10 применена: heartbeat и метрики очереди готовы.';}catch(Throwable $e){error_log('SiteBuilder stage 10 migration failed: '.$e->getMessage());$error='Не удалось применить миграцию. Подробности записаны в журнал PHP.';}
}
function sbStage10Escape(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Миграция SiteBuilder — этап 10</title><style>body{margin:0;padding:32px;font-family:Arial;background:#f3f6fb;color:#1f2937}.card{max-width:820px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:28px}.notice{padding:14px;margin:16px 0;border-radius:10px}.ok{background:#f0fdf4;color:#166534}.err{background:#fef2f2;color:#991b1b}button{padding:11px 18px;border:0;border-radius:9px;background:#2563eb;color:#fff;font-weight:700}</style></head><body><div class="card"><h1>Миграция этапа 10</h1><p>Создаёт таблицы heartbeat и истории запусков worker. Новые задания очистки используют существующую transactional outbox.</p><p><strong>Перед запуском:</strong> резервная копия PostgreSQL и установленный этап 9.</p><?php if($message):?><div class="notice ok"><?=sbStage10Escape($message)?></div><?php endif?><?php if($error):?><div class="notice err"><?=sbStage10Escape($error)?></div><?php endif?><form method="post"><?=bitrix_sessid_post()?><button>Применить миграцию этапа 10</button></form></div></body></html>
