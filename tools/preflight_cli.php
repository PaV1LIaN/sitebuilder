<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$documentRoot = getenv('DOCUMENT_ROOT') ?: realpath(__DIR__ . '/../../..');
if (!$documentRoot) {
    fwrite(STDERR, "DOCUMENT_ROOT_REQUIRED\n");
    exit(2);
}

$_SERVER['DOCUMENT_ROOT'] = rtrim($documentRoot, '/');
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/MigrationService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PreflightService.php';

try {
    $result = PreflightService::run(0);
    echo json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    if ((int)($result['errorsCount'] ?? 0) > 0) {
        exit(2);
    }
    if ((int)($result['warningsCount'] ?? 0) > 0) {
        exit(1);
    }
    exit(0);
} catch (Throwable $e) {
    error_log('SiteBuilder preflight CLI failed: ' . $e->getMessage());
    fwrite(STDERR, "PREFLIGHT_FAILED\n");
    exit(2);
}
