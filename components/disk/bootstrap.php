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
    require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/db.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/json.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/response.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/access.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/RevisionService.php';

    \Bitrix\Main\Loader::includeModule('disk');

    require_once __DIR__ . '/lib/helpers.php';
    require_once __DIR__ . '/lib/DiskNameSanitizer.php';
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
    require_once __DIR__ . '/lib/DiskSitebuilderBridge.php';
}

if (!function_exists('sb_disk_release_session_lock')) {
  function sb_disk_release_session_lock(): void
  {
      /*
       * После проверки авторизации, sessid и прав
       * освобождаем lock PHP/Bitrix-сессии.
       *
       * Иначе большой upload/распаковка держит сессию,
       * а остальные страницы Bitrix в этом же браузере ждут 60 секунд
       * и падают с "Unable to get session lock".
       */
      try {
          if (class_exists('\Bitrix\Main\Application')) {
              $session = \Bitrix\Main\Application::getInstance()->getSession();

              if (method_exists($session, 'save')) {
                  $session->save();
                  return;
              }
          }
      } catch (Throwable $e) {
          // fallback ниже
      }

      if (session_status() === PHP_SESSION_ACTIVE) {
          @session_write_close();
      }
  }
}