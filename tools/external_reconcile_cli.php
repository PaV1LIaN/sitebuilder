<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$documentRoot = (string)(getenv('DOCUMENT_ROOT') ?: dirname(__DIR__, 3));
$_SERVER['DOCUMENT_ROOT'] = rtrim($documentRoot, '/');
define('NO_KEEP_STATISTIC', true);define('NO_AGENT_STATISTIC', true);define('NOT_CHECK_PERMISSIONS', true);
require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/ExternalResourceReconcileService.php';
$options=getopt('', ['site::','mode::']);$siteId=max(0,(int)($options['site']??0));$mode=(string)($options['mode']??'audit');
try{$result=ExternalResourceReconcileService::run($siteId,$mode,0,0);echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;exit((int)($result['anomalies']??0)>0?1:0);}catch(Throwable $e){error_log('SiteBuilder external reconcile CLI failed: '.$e->getMessage());fwrite(STDERR,"EXTERNAL_RECONCILE_FAILED\n");exit(2);}
