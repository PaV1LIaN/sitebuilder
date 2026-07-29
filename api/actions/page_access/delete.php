<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessRepository.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessService.php';

global $USER;

if (!is_object($USER) || !$USER->IsAuthorized()) {
    throw new RuntimeException('AUTH_REQUIRED');
}

if (!check_bitrix_sessid()) {
    throw new RuntimeException('BAD_SESSID');
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    $data = $_REQUEST;
}

$id = (int)($data['id'] ?? 0);
$siteId = (int)($data['siteId'] ?? 0);
$pageId = (int)($data['pageId'] ?? 0);

$currentUserId = (int)$USER->GetID();

if ($id <= 0) {
    throw new RuntimeException('INVALID_PAGE_ACCESS_ID');
}

if ($siteId <= 0) {
    throw new RuntimeException('INVALID_SITE_ID');
}

if ($pageId <= 0) {
    throw new RuntimeException('INVALID_PAGE_ID');
}

/*
 * Удалять права может только тот,
 * кто может редактировать страницу.
 */
if (!PageAccessService::canEditPage($siteId, $pageId, $currentUserId)) {
    throw new RuntimeException('PAGE_ACCESS_DENIED');
}

PageAccessRepository::delete($id, $siteId, $pageId);

echo json_encode([
    'ok' => true,
    'data' => [
        'deleted' => true,
        'id' => $id,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
