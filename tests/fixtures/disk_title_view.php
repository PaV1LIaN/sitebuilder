<?php
// Render the production template with fixture data, without a Bitrix bootstrap.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function disk_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function bitrix_sessid(): string { return 'fixture'; }
$permissions = array_fill_keys(['canView', 'canUpload', 'canCreateFolder', 'canRename', 'canDelete',
    'canDownload', 'canManageAccess', 'canEditSettings'], true);
$settings = ['title' => 'Сохранённый заголовок', 'rootMode' => 'block', 'rootFolderId' => 20,
    'viewMode' => 'table', 'showBreadcrumbs' => true, 'showSearch' => true];
$arResult = [
    'SITE_ID' => 1, 'PAGE_ID' => 2, 'BLOCK_ID' => 5, 'CURRENT_USER_ID' => 2,
    'TITLE' => $settings['title'], 'SETTINGS' => $settings, 'PERMISSIONS' => $permissions,
    'ROOT_FOLDER_ID' => 20, 'ROOT_SOURCE' => 'block', 'ERROR' => null,
    'INITIAL_STATE' => ['siteId' => 1, 'pageId' => 2, 'blockId' => 5, 'settings' => $settings,
        'permissions' => $permissions, 'rootFolderId' => 20, 'currentFolderId' => 20],
];
include __DIR__ . '/../../components/disk/template.php';
