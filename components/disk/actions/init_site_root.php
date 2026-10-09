<?php

DiskCsrf::validateFromRequest();
$data = disk_read_json_body();

$currentUserId = DiskCurrentUser::requireId();

$siteId = (int)($data['siteId'] ?? 0);
if ($siteId <= 0) {
    throw new RuntimeException('INVALID_SITE_ID');
}

$site = SiteRepository::getById($siteId);
if (!$site) {
    throw new RuntimeException('SITE_NOT_FOUND');
}

if (!DiskCurrentUser::isBitrixAdmin()) {
    throw new RuntimeException('ACCESS_DENIED');
}

$folderId = SiteDiskInitializer::ensureSiteRootFolder(
    $siteId,
    $currentUserId,
    (string)$site['name']
);

DiskResponse::success([
    'rootFolderId' => $folderId,
]);
