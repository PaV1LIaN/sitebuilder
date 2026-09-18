<?php
define('NO_KEEP_STATISTIC',true);define('NO_AGENT_STATISTIC',true);define('NOT_CHECK_PERMISSIONS',true);
require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/db.php';
sitebuilder_require_bitrix_admin();
$message='';$error='';$migrationFile=dirname(__DIR__).'/migrations/20260729_004_sequences_and_external_jobs.sql';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 if(!check_bitrix_sessid())$error='Сессия устарела. Обновите страницу.';
 elseif(!is_file($migrationFile))$error='Файл миграции не найден.';
 else try{$sql=file_get_contents($migrationFile);if(!is_string($sql)||trim($sql)==='')throw new RuntimeException('EMPTY_MIGRATION');sb_db()->exec($sql);$message='Миграция этапа 9 применена: sequences и transactional outbox готовы.';}catch(Throwable $e){error_log('SiteBuilder stage 9 migration failed: '.$e->getMessage());$error='Не удалось применить миграцию. Подробности записаны в журнал PHP.';}
}
function sbStage9Escape(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Миграция SiteBuilder — этап 9</title><link rel="stylesheet" href="../assets/tools/apply-stage9-migration.css?v=20260917"></head><body><div class="card"><h1>Миграция этапа 9</h1><p>Создаёт PostgreSQL sequences для site/page/block/menu и таблицы очереди внешних операций.</p><p><strong>Перед запуском:</strong> резервная копия PostgreSQL и установленный этап 8.</p><?php if($message):?><div class="notice ok"><?=sbStage9Escape($message)?></div><?php endif?><?php if($error):?><div class="notice err"><?=sbStage9Escape($error)?></div><?php endif?><form method="post"><?=bitrix_sessid_post()?><button>Применить миграцию этапа 9</button></form></div></body></html>
