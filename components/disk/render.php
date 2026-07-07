<?php

global $USER;

$site = $context['site'] ?? [];
$currentPage = $context['currentPage'] ?? [];

$siteId = (int)($site['id'] ?? 0);
$pageId = (int)($currentPage['id'] ?? 0);
$blockId = (int)($block['id'] ?? 0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/components/disk/class.php';

$component = new SitebuilderDiskComponent([
    'SITE_ID' => $siteId,
    'PAGE_ID' => $pageId,
    'BLOCK_ID' => $blockId,
    'CURRENT_USER_ID' => is_object($USER) ? (int)$USER->GetID() : 0,
]);

$component->execute();