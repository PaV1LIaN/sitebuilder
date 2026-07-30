<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$documentRoot = getenv('DOCUMENT_ROOT') ?: dirname(__DIR__, 3);
$_SERVER['DOCUMENT_ROOT'] = rtrim($documentRoot, '/');
define('NO_KEEP_STATISTIC',true);define('NO_AGENT_STATISTIC',true);define('NOT_CHECK_PERMISSIONS',true);
require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/QueueMonitorService.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/SystemAlertService.php';
$siteId=0;foreach($argv as $arg){if(preg_match('/^--site=(\d+)$/',$arg,$m))$siteId=(int)$m[1];}
try{$health=QueueMonitorService::health($siteId);SystemAlertService::synchronizeQueueHealth($health);echo json_encode($health,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;$status=(string)($health['status']??'critical');exit($status==='healthy'?0:($status==='warning'?1:2));}catch(Throwable $e){error_log('SiteBuilder queue health failed: '.$e->getMessage());fwrite(STDERR,"QUEUE_HEALTH_FAILED\n");exit(2);}
