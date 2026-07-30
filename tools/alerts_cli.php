<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$documentRoot = (string)(getenv('DOCUMENT_ROOT') ?: dirname(__DIR__, 3));
$_SERVER['DOCUMENT_ROOT'] = rtrim($documentRoot, '/');
define('NO_KEEP_STATISTIC', true);define('NO_AGENT_STATISTIC', true);define('NOT_CHECK_PERMISSIONS', true);
require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/SystemAlertService.php';
$options=getopt('', ['site::']);$siteId=max(0,(int)($options['site']??0));
try{$result=SystemAlertService::list(['siteId'=>$siteId,'status'=>'open','limit'=>200]);echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;$critical=false;foreach($result['items'] as $item){if(($item['severity']??'')==='critical'){$critical=true;break;}}exit($critical?2:((int)$result['total']>0?1:0));}catch(Throwable $e){error_log('SiteBuilder alerts CLI failed: '.$e->getMessage());fwrite(STDERR,"ALERTS_CHECK_FAILED\n");exit(2);}
