<?php

if (!defined('SITEBUILDER_DISK_BOOTSTRAP')) {
    define('SITEBUILDER_DISK_BOOTSTRAP', true);

    if (!defined('NO_KEEP_STATISTIC')) {
        define('NO_KEEP_STATISTIC', true);
    }
    if (!defined('NO_AGENT_STATISTIC')) {
        define('NO_AGENT_STATISTIC', true);
    }
    if (!defined('DisableEventsCheck')) {
        define('DisableEventsCheck', true);
    }

    require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

    \Bitrix\Main\Loader::includeModule('disk');

    require_once __DIR__ . '/lib/helpers.php';
    require_once __DIR__ . '/lib/DiskDb.php';
    require_once __DIR__ . '/lib/DiskContext.php';
    require_once __DIR__ . '/lib/DiskResponse.php';
    require_once __DIR__ . '/lib/DiskCurrentUser.php';
    require_once __DIR__ . '/lib/DiskCsrf.php';
    require_once __DIR__ . '/lib/BlockRepository.php';
    require_once __DIR__ . '/lib/SiteRepository.php';
    require_once __DIR__ . '/lib/SiteAccessRepository.php';
    require_once __DIR__ . '/lib/DiskSettingsRepository.php';
    require_once __DIR__ . '/lib/DiskRootResolver.php';
    require_once __DIR__ . '/lib/DiskValidator.php';
    require_once __DIR__ . '/lib/DiskPermissionService.php';
    require_once __DIR__ . '/lib/SiteDiskInitializer.php';
    require_once __DIR__ . '/lib/BlockDiskInitializer.php';
    require_once __DIR__ . '/lib/DiskStorageAdapterInterface.php';
    require_once __DIR__ . '/lib/DiskBitrixStorageAdapter.php';
}