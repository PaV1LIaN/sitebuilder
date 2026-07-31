<?php

global $USER;

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/GlobalBlockService.php';

if (!function_exists('sb_global_block_fail')) {
    function sb_global_block_fail(Throwable $e, string $action): never
    {
        $code = trim($e->getMessage());
        $statuses = [
            'NAME_REQUIRED' => 422,
            'BLOCK_NOT_FOUND' => 404,
            'BLOCK_NOT_IN_SITE' => 409,
            'GLOBAL_BLOCK_NOT_FOUND' => 404,
            'GLOBAL_BLOCK_SOURCE_REQUIRED' => 422,
            'GLOBAL_BLOCK_IN_USE' => 409,
        ];
        if (isset($statuses[$code])) {
            sb_json_error($code, $statuses[$code], ['action' => $action]);
        }
        error_log('SiteBuilder global block error [' . $action . ']: ' . $e->getMessage());
        sb_json_error('GLOBAL_BLOCK_OPERATION_FAILED', 500, ['action' => $action]);
    }
}

$siteId = (int)($_POST['siteId'] ?? 0);
if ($siteId <= 0) {
    sb_json_error('SITE_ID_REQUIRED', 422);
}

sb_require_content_manager($siteId);

try {
    if ($action === 'globalBlock.list') {
        sb_json_ok(['items' => GlobalBlockService::list($siteId)]);
    }

    if ($action === 'globalBlock.create') {
        $blockId = (int)($_POST['blockId'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        sb_json_ok(['item' => GlobalBlockService::createFromBlock($siteId, $blockId, $name, (int)$USER->GetID())]);
    }

    if ($action === 'globalBlock.update') {
        $globalBlockId = (int)($_POST['globalBlockId'] ?? 0);
        $blockId = (int)($_POST['blockId'] ?? 0);
        sb_json_ok(['item' => GlobalBlockService::updateFromBlock($siteId, $globalBlockId, $blockId, (int)$USER->GetID())]);
    }

    if ($action === 'globalBlock.rename') {
        $globalBlockId = (int)($_POST['globalBlockId'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        sb_json_ok(['item' => GlobalBlockService::rename($siteId, $globalBlockId, $name, (int)$USER->GetID())]);
    }

    if ($action === 'globalBlock.delete') {
        $globalBlockId = (int)($_POST['globalBlockId'] ?? 0);
        GlobalBlockService::delete($siteId, $globalBlockId);
        sb_json_ok(['deleted' => true, 'globalBlockId' => $globalBlockId]);
    }
} catch (Throwable $e) {
    sb_global_block_fail($e, $action);
}

sb_json_error('UNKNOWN_GLOBAL_BLOCK_ACTION', 400, ['action' => $action]);
