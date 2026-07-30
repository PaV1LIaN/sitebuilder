<?php

global $USER;

$servicePath = $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/SiteTemplateService.php';
if (file_exists($servicePath)) {
    require_once $servicePath;
}

if (!class_exists('SiteTemplateService')) {
    sb_json_error('SiteTemplateService.php не подключен', 500);
}

if (!function_exists('sb_template_handle_exception')) {
    function sb_template_handle_exception(Throwable $e, string $action): never
    {
        if ($e instanceof SiteBuilderResourceBusyException) {
            sb_json_error('RESOURCE_BUSY', 423, $e->context());
        }

        if ($e instanceof PDOException) {
            $sqlState = sb_db_exception_sqlstate($e);
            if ($sqlState === '55P03') {
                sb_json_error('RESOURCE_BUSY', 423);
            }
            if ($sqlState === '40P01' || $sqlState === '40001') {
                sb_json_error('RETRY_TRANSACTION', 409);
            }
            error_log('SiteBuilder template database error [' . $sqlState . ']: ' . $e->getMessage());
            sb_json_error('INTERNAL_ERROR', 500, ['action' => $action]);
        }

        $error = trim($e->getMessage());
        $statuses = [
            'TEMPLATE_NOT_FOUND' => 404,
            'SITE_NOT_FOUND' => 404,
            'NAME_REQUIRED' => 422,
            'SITE_FIELD_NOT_ALLOWED' => 422,
        ];

        if (isset($statuses[$error])) {
            sb_json_error($error, $statuses[$error], ['action' => $action]);
        }

        error_log('SiteBuilder template operation failed [' . $action . ']: ' . $error);
        sb_json_error('TEMPLATE_OPERATION_FAILED', 500, ['action' => $action]);
    }
}

if ($action === 'template.list') {
    sb_json_ok([
        'templates' => SiteTemplateService::listSiteTemplates(),
        'handler' => 'template',
        'action' => $action,
    ]);
}

if ($action === 'template.get') {
    $templateId = (int)($_POST['templateId'] ?? $_POST['id'] ?? 0);

    if ($templateId <= 0) {
        sb_json_error('TEMPLATE_ID_REQUIRED', 422);
    }

    $template = SiteTemplateService::getTemplate($templateId);
    if (!$template || (string)($template['kind'] ?? 'site') !== 'site') {
        sb_json_error('TEMPLATE_NOT_FOUND', 404);
    }

    sb_json_ok([
        'template' => SiteTemplateService::publicTemplateRecord($template),
        'handler' => 'template',
        'action' => $action,
    ]);
}

if ($action === 'template.createFromSite') {
    sb_require_bitrix_admin();

    $siteId = (int)($_POST['siteId'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    if ($name === '') {
        sb_json_error('NAME_REQUIRED', 422);
    }

    try {
        $template = SiteTemplateService::createFromSite(
            $siteId,
            $name,
            $description,
            (int)$USER->GetID()
        );

        sb_json_ok([
            'template' => $template,
            'handler' => 'template',
            'action' => $action,
        ]);
    } catch (Throwable $e) {
        sb_template_handle_exception($e, $action);
    }
}

if ($action === 'template.update') {
    sb_require_bitrix_admin();

    $templateId = (int)($_POST['templateId'] ?? $_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($templateId <= 0) {
        sb_json_error('TEMPLATE_ID_REQUIRED', 422);
    }

    if ($name === '') {
        sb_json_error('NAME_REQUIRED', 422);
    }

    try {
        $template = SiteTemplateService::rename($templateId, $name, $description, (int)$USER->GetID());

        sb_json_ok([
            'template' => $template,
            'handler' => 'template',
            'action' => $action,
        ]);
    } catch (Throwable $e) {
        sb_template_handle_exception($e, $action);
    }
}

if ($action === 'template.delete') {
    sb_require_bitrix_admin();

    $templateId = (int)($_POST['templateId'] ?? $_POST['id'] ?? 0);

    if ($templateId <= 0) {
        sb_json_error('TEMPLATE_ID_REQUIRED', 422);
    }

    try {
        SiteTemplateService::delete($templateId);

        sb_json_ok([
            'deleted' => true,
            'handler' => 'template',
            'action' => $action,
        ]);
    } catch (Throwable $e) {
        sb_template_handle_exception($e, $action);
    }
}

if ($action === 'template.createSite') {
    sb_require_bitrix_admin();

    $templateId = (int)($_POST['templateId'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $sectionId = (int)($_POST['sectionId'] ?? 0);

    if ($templateId <= 0) {
        sb_json_error('TEMPLATE_ID_REQUIRED', 422);
    }

    try {
        $result = SiteTemplateService::createSiteFromTemplate(
            $templateId,
            $name,
            $slug,
            $sectionId,
            (int)$USER->GetID()
        );

        sb_json_ok($result + [
            'handler' => 'template',
            'action' => $action,
        ]);
    } catch (Throwable $e) {
        sb_template_handle_exception($e, $action);
    }
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'template',
    'action' => $action,
]);