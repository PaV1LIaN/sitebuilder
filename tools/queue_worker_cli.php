<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$documentRoot = getenv('DOCUMENT_ROOT') ?: dirname(__DIR__, 3);
$_SERVER['DOCUMENT_ROOT'] = rtrim($documentRoot, '/');
define('NO_KEEP_STATISTIC',true);define('NO_AGENT_STATISTIC',true);define('NOT_CHECK_PERMISSIONS',true);
require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/ExternalJobWorker.php';
$limit=20;$workerId=null;
foreach($argv as $arg){
    if(preg_match('/^--limit=(\d+)$/',$arg,$m))$limit=(int)$m[1];
    if(preg_match('/^--worker=([A-Za-z0-9_.:@-]{1,120})$/',$arg,$m))$workerId=$m[1];
}
try{echo json_encode(ExternalJobWorker::runBatch($limit,$workerId),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;exit(0);}catch(Throwable $e){error_log('SiteBuilder CLI queue worker failed: '.$e->getMessage());fwrite(STDERR,"QUEUE_WORKER_FAILED\n");exit(1);}
