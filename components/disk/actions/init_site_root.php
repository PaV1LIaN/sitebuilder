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

$role = SiteAccessRepository::getUserRole($siteId, $currentUserId);
if (!DiskCurrentUser::isAdmin() && !in_array($role, ['site_admin', 'site_editor'], true)) {
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