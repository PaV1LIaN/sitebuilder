<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessRepository.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessService.php';

global $USER;

if (!is_object($USER) || !$USER->IsAuthorized()) {
    throw new RuntimeException('AUTH_REQUIRED');
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    $data = $_REQUEST;
}

$siteId = (int)($data['siteId'] ?? 0);
$pageId = (int)($data['pageId'] ?? 0);
$currentUserId = (int)$USER->GetID();

if ($siteId <= 0) {
    throw new RuntimeException('INVALID_SITE_ID');
}

if ($pageId <= 0) {
    throw new RuntimeException('INVALID_PAGE_ID');
}

/*
 * Смотреть список прав может только тот,
 * кто имеет право редактировать страницу
 * или глобально редактировать сайт.
 */
if (!PageAccessService::canEditPage($siteId, $pageId, $currentUserId)) {
    throw new RuntimeException('PAGE_ACCESS_DENIED');
}

$items = PageAccessRepository::listByPage($siteId, $pageId);

echo json_encode([
    'ok' => true,
    'data' => [
        'items' => $items,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);