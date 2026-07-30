<?php
$documentRoot = getenv('DOCUMENT_ROOT') ?: realpath(__DIR__ . '/../../..');
if (!$documentRoot) { fwrite(STDERR, "DOCUMENT_ROOT_REQUIRED\n"); exit(2); }
$_SERVER['DOCUMENT_ROOT'] = rtrim($documentRoot, '/');
define('NO_KEEP_STATISTIC', true);define('NO_AGENT_STATISTIC', true);define('NOT_CHECK_PERMISSIONS', true);
require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/json.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/helpers.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/storage_db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/storage_db_extra.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/sitebuilder/lib/IntegrityCheckService.php';
$options = getopt('', ['site:']);$siteId=(int)($options['site']??0);if($siteId<=0){fwrite(STDERR,"Usage: --site=ID\n");exit(2);}try{$result=IntegrityCheckService::run($siteId,0);echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;exit((int)($result['errorsCount']??0)>0?1:0);}catch(Throwable $e){error_log('SiteBuilder integrity CLI failed: '.$e->getMessage());fwrite(STDERR,"INTEGRITY_CHECK_FAILED\n");exit(2);}
