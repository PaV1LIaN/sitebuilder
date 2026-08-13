<?php

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);

require_once $_SERVER['DOCUMENT_ROOT']
    . '/bitrix/modules/main/include/prolog_before.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__
    . '/lib/FormSubmissionService.php';
require_once __DIR__
    . '/lib/FormExportService.php';

global $USER;

sitebuilder_require_auth();

$siteId =
    (int)(
        $_GET['siteId']
        ?? 0
    );

if ($siteId <= 0) {
    http_response_code(400);
    exit('siteId required');
}

if (!$USER->IsAdmin()) {
    sb_require_content_manager(
        $siteId
    );
}

$format =
    strtolower(
        trim(
            (string)(
                $_GET['format']
                ?? 'csv'
            )
        )
    );

if (
    !in_array(
        $format,
        ['csv', 'xlsx'],
        true
    )
) {
    http_response_code(400);
    exit('Unsupported export format');
}

$filters = [
    'status' =>
        trim(
            (string)(
                $_GET['status']
                ?? ''
            )
        ),
    'blockId' =>
        (int)(
            $_GET['blockId']
            ?? 0
        ),
    'search' =>
        trim(
            (string)(
                $_GET['q']
                ?? ''
            )
        ),
];

$items =
    FormSubmissionService::listForExport(
        $siteId,
        $filters,
        5000
    );

FormExportService::download(
    $format,
    $siteId,
    $items
);
