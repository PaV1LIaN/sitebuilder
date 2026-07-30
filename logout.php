<?php

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT']
    . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/auth.php';

global $USER;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'METHOD_NOT_ALLOWED';
    exit;
}

if (!check_bitrix_sessid()) {
    http_response_code(403);
    echo 'BAD_SESSID';
    exit;
}

if (is_object($USER) && $USER->IsAuthorized()) {
    $USER->Logout();
}

LocalRedirect((string)sitebuilder_auth_config()['login_url']);
exit;
