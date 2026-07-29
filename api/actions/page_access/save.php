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

$siteId = (int)($data['siteId'] ?? 0);
$pageId = (int)($data['pageId'] ?? 0);
$accessCode = (string)($data['accessCode'] ?? '');

$canView = !empty($data['canView']);
$canEdit = !empty($data['canEdit']);
$includeChildren = !empty($data['includeChildren']);

$currentUserId = (int)$USER->GetID();

if ($siteId <= 0) {
    throw new RuntimeException('INVALID_SITE_ID');
}

if ($pageId <= 0) {
    throw new RuntimeException('INVALID_PAGE_ID');
}

if ($accessCode === '') {
    throw new RuntimeException('EMPTY_ACCESS_CODE');
}

if (!$canView && !$canEdit) {
    throw new RuntimeException('EMPTY_PAGE_PERMISSION');
}

/*
 * Редактирование автоматически включает чтение.
 */
if ($canEdit) {
    $canView = true;
}

/*
 * Выдавать права может только тот,
 * кто сам может редактировать страницу.
 */
if (!PageAccessService::canEditPage($siteId, $pageId, $currentUserId)) {
    throw new RuntimeException('PAGE_ACCESS_DENIED');
}

$item = PageAccessRepository::save(
    $siteId,
    $pageId,
    $accessCode,
    $canView,
    $canEdit,
    $includeChildren,
    $currentUserId
);

echo json_encode([
    'ok' => true,
    'data' => [
        'item' => $item,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);