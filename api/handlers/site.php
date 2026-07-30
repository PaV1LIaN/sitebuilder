<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/SiteAppearanceService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessRepository.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/SiteDeletionService.php';

global $USER;

$siteBitrixGroupServicePath = $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/SiteBitrixGroupService.php';
if (file_exists($siteBitrixGroupServicePath)) {
    require_once $siteBitrixGroupServicePath;
}

$siteAccessSyncServicePath = $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/SiteAccessSyncService.php';
if (file_exists($siteAccessSyncServicePath)) {
    require_once $siteAccessSyncServicePath;
}

$siteAccessManagementServicePath = $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/SiteAccessManagementService.php';
if (file_exists($siteAccessManagementServicePath)) {
    require_once $siteAccessManagementServicePath;
}

if (!function_exists('sb_site_handler_require_role')) {
    function sb_site_handler_require_role(int $siteId, int $minRank): void
    {
        global $USER;

        if ($USER && $USER->IsAdmin()) {
            return;
        }

        sb_require_site_role($siteId, $minRank);
    }
}

if (!function_exists('sb_site_handler_require_owner')) {
    function sb_site_handler_require_owner(int $siteId): void
    {
        sb_site_handler_require_role($siteId, 4);
    }
}

if (!function_exists('sb_site_handler_require_editor')) {
    function sb_site_handler_require_editor(int $siteId): void
    {
        /*
         * Историческое имя функции оставлено для совместимости.
         * Изменение настроек и структуры сайта требует ADMIN/OWNER.
         */
        sb_site_handler_require_role($siteId, 3);
    }
}

if (!function_exists('sb_site_handler_require_viewer')) {
    function sb_site_handler_require_viewer(int $siteId): void
    {
        sb_site_handler_require_role($siteId, 1);
    }
}

if (!function_exists('sb_site_handler_handle_exception')) {
    function sb_site_handler_handle_exception(Throwable $e, string $action): void
    {
        if ($e instanceof SiteBuilderVersionConflictException) {
            throw $e;
        }

        if (class_exists('SiteBuilderResourceBusyException') && $e instanceof SiteBuilderResourceBusyException) {
            throw $e;
        }

        if ($e instanceof PDOException) {
            throw $e;
        }

        $message = trim($e->getMessage());
        $context = [
            'handler' => 'site',
            'action' => $action,
        ];

        $statusByError = [
            'SITE_NOT_FOUND' => 404,
            'EMPTY_SITE_ID' => 422,
            'EMPTY_TARGET_USER_ID' => 422,
            'EMPTY_CURRENT_USER_ID' => 422,
            'EMPTY_OWNER_USER_ID' => 422,
            'EMPTY_BITRIX_GROUP_ID' => 422,
            'BITRIX_GROUP_MEMBERS_EMPTY' => 422,
            'SITE_GROUP_REQUIRED' => 422,
            'INVALID_ROLE' => 422,
            'OWNER_ASSIGNMENT_FORBIDDEN' => 409,
            'CANNOT_DOWNGRADE_OWNER' => 409,
            'LAST_OWNER_CANNOT_BE_DOWNGRADED' => 409,
            'LAST_OWNER_CANNOT_BE_REMOVED' => 409,
            'OWNER_DELETE_FORBIDDEN' => 409,
            'EMPTY_FILE' => 422,
            'FILE_TOO_LARGE' => 413,
            'BAD_FILE_EXTENSION' => 422,
            'BAD_FILE_MIME_TYPE' => 422,
            'BAD_ASSET_TYPE' => 422,
            'SOCIALNETWORK_MODULE_NOT_INSTALLED' => 503,
            'CSocNetUserToGroup_NOT_FOUND' => 503,
            'CSocNetGroup_NOT_FOUND' => 503,
        ];

        if (isset($statusByError[$message])) {
            sb_json_error($message, $statusByError[$message], $context);
        }

        if (preg_match('/^UPLOAD_ERROR_\d+$/', $message)) {
            sb_json_error($message, 422, $context);
        }

        error_log(sprintf(
            'SiteBuilder %s failed: %s in %s:%d',
            $action,
            $message,
            $e->getFile(),
            $e->getLine()
        ));

        sb_json_error('SITE_OPERATION_FAILED', 500, $context);
    }
}

if (!function_exists('sb_site_handler_get_access_context')) {
    function sb_site_handler_get_access_context(int $siteId): array
    {
        global $USER;

        if (
            $siteId <= 0
            || !is_object($USER)
            || !$USER->IsAuthorized()
        ) {
            return [
                'allowed' => false,
                'userId' => 0,
                'role' => '',
                'roleRank' => 0,
                'hasGlobalView' => false,
                'hasGlobalEdit' => false,
                'hasGlobalDiskEdit' => false,
                'hasPageAccess' => false,
            ];
        }

        $userId = (int)$USER->GetID();

        if ($USER->IsAdmin()) {
            return [
                'allowed' => true,
                'userId' => $userId,
                'role' => 'OWNER',
                'roleRank' => 4,
                'hasGlobalView' => true,
                'hasGlobalEdit' => true,
                'hasGlobalDiskEdit' => true,
                'hasPageAccess' => true,
            ];
        }

        $accessCode = PageAccessRepository::userAccessCode(
            $userId
        );

        /*
         * sb_get_role() учитывает как sitebuilder.access,
         * так и резервную роль группы Битрикс24.
         */
        $role = (string)sb_get_role(
            $siteId,
            $accessCode
        );

        $roleRank = sb_role_rank($role);

        $hasPageAccess = PageAccessService::hasAnyPageAccess(
            $siteId,
            $userId
        );

        return [
            'allowed' => $roleRank >= 1 || $hasPageAccess,
            'userId' => $userId,
            'role' => $role,
            'roleRank' => $roleRank,
            'hasGlobalView' => $roleRank >= 1,
            'hasGlobalEdit' => $roleRank >= 3,
            'hasGlobalDiskEdit' => $roleRank >= 2,
            'hasPageAccess' => $hasPageAccess,
        ];
    }
}

if (!function_exists('sb_site_handler_get_bitrix_group_id')) {
    function sb_site_handler_get_bitrix_group_id(array $site): int
    {
        return (int)(
            $site['bitrixGroupId']
            ?? $site['bitrix_group_id']
            ?? $site['BITRIX_GROUP_ID']
            ?? 0
        );
    }
}

if (!function_exists('sb_site_handler_update_bitrix_group_fields')) {
    function sb_site_handler_update_bitrix_group_fields(
        int $siteId,
        int $groupId,
        int $currentUserId,
        int $expectedVersion
    ): array {
        if ($siteId <= 0 || $groupId <= 0) {
            throw new InvalidArgumentException('SITE_GROUP_REQUIRED');
        }

        $site = RevisionService::getSite($siteId, false);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $site['bitrixGroupId'] = $groupId;
        $site['bitrixGroupCreatedBy'] = $currentUserId;
        $site['bitrixGroupCreatedAt'] = date('c');

        return RevisionService::saveSite(
            $site,
            RevisionService::requireExpectedVersion($expectedVersion),
            $currentUserId,
            'bitrix_group_attach'
        );
    }
}

if (!function_exists('sb_site_handler_validate_section')) {
    function sb_site_handler_validate_section(int $sectionId): void
    {
        if ($sectionId <= 0) {
            return;
        }

        $rows = sb_db_fetch_all("
            SELECT id
            FROM sitebuilder.site_section
            WHERE id = :id
            LIMIT 1
        ", [
            ':id' => $sectionId,
        ]);

        if (empty($rows)) {
            sb_json_error('SECTION_NOT_FOUND', 404);
        }
    }
}

if (!function_exists('sb_site_handler_map_sonet_role')) {
    function sb_site_handler_map_sonet_role(string $sonetRole): ?string
    {
        $sonetRole = trim($sonetRole);

        $ownerRole = defined('SONET_ROLES_OWNER') ? SONET_ROLES_OWNER : 'A';
        $moderatorRole = defined('SONET_ROLES_MODERATOR') ? SONET_ROLES_MODERATOR : 'E';
        $userRole = defined('SONET_ROLES_USER') ? SONET_ROLES_USER : 'K';

        if ($sonetRole === $ownerRole || $sonetRole === 'A') {
            return 'OWNER';
        }

        if ($sonetRole === $moderatorRole || $sonetRole === 'E') {
            return 'EDITOR';
        }

        if ($sonetRole === $userRole || $sonetRole === 'K') {
            return 'VIEWER';
        }

        return null;
    }
}

if (!function_exists('sb_site_handler_sync_access_fallback')) {
    function sb_site_handler_sync_access_fallback(int $siteId, int $currentUserId, bool $strict = true): array
    {
        if ($siteId <= 0) {
            throw new RuntimeException('SITE_ID_REQUIRED');
        }

        $site = sb_find_site($siteId);
        if (!$site) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        $bitrixGroupId = sb_site_handler_get_bitrix_group_id($site);

        if ($bitrixGroupId <= 0) {
            throw new RuntimeException('EMPTY_BITRIX_GROUP_ID');
        }

        if (!\Bitrix\Main\Loader::includeModule('socialnetwork')) {
            throw new RuntimeException('SOCIALNETWORK_MODULE_NOT_INSTALLED');
        }

        if (!class_exists('CSocNetUserToGroup')) {
            throw new RuntimeException('CSocNetUserToGroup_NOT_FOUND');
        }

        $targetAccess = [];
        $members = [];

        $rs = \CSocNetUserToGroup::GetList(
            ['ID' => 'ASC'],
            [
                'GROUP_ID' => $bitrixGroupId,
            ],
            false,
            false,
            [
                'ID',
                'USER_ID',
                'GROUP_ID',
                'ROLE',
            ]
        );

        while ($row = $rs->Fetch()) {
            $userId = (int)($row['USER_ID'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $sonetRole = (string)($row['ROLE'] ?? '');
            $sitebuilderRole = sb_site_handler_map_sonet_role($sonetRole);

            if ($sitebuilderRole === null) {
                continue;
            }

            $accessCode = 'U' . $userId;

            if (
                !isset($targetAccess[$accessCode])
                || sb_role_rank($sitebuilderRole) > sb_role_rank($targetAccess[$accessCode])
            ) {
                $targetAccess[$accessCode] = $sitebuilderRole;
            }
        }

        if ($currentUserId > 0) {
            $currentUserCode = 'U' . $currentUserId;

            if (!isset($targetAccess[$currentUserCode])) {
                $targetAccess[$currentUserCode] = 'OWNER';
            }
        }

        foreach ($targetAccess as $accessCode => $role) {
            $members[] = [
                'userId' => (int)preg_replace('/\D+/', '', $accessCode),
                'accessCode' => $accessCode,
                'sonetRole' => '',
                'sitebuilderRole' => $role,
            ];
        }

        $created = 0;
        $updated = 0;
        $removed = 0;
        $kept = 0;

        foreach ($targetAccess as $accessCode => $role) {
            $saveResult = sb_add_access_role_if_missing(
                $siteId,
                $accessCode,
                $role,
                $currentUserId
            );

            if (!empty($saveResult['created'])) {
                $created++;
            } else {
                /*
                 * Существующее прямое назначение SiteBuilder
                 * является авторитетным и не перезаписывается.
                 */
                $kept++;
            }
        }

        return [
            'siteId' => $siteId,
            'bitrixGroupId' => $bitrixGroupId,
            'strictRequested' => $strict,
            'strictApplied' => false,
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'kept' => $kept,
            'members' => $members,
            'targetAccess' => $targetAccess,
        ];
    }
}

if ($action === 'site.list') {
    $sites = sb_read_sites();
    $allowedSites = [];

    foreach ($sites as $site) {
        $currentSiteId = (int)($site['id'] ?? 0);

        if ($currentSiteId <= 0) {
            continue;
        }

        $accessContext =
            sb_site_handler_get_access_context(
                $currentSiteId
            );

        /*
         * Сайт показывается, если пользователь:
         *
         * 1. Имеет глобальную роль VIEWER или выше.
         * 2. Либо имеет хотя бы одно точечное право страницы.
         */
        if (!$accessContext['allowed']) {
            continue;
        }

        $site['currentUserRole'] =
            $accessContext['role'];

        $site['currentUserRoleRank'] =
            $accessContext['roleRank'];

        $site['currentUserHasGlobalView'] =
            $accessContext['hasGlobalView'];

        $site['currentUserHasGlobalEdit'] =
            $accessContext['hasGlobalEdit'];

        $site['currentUserHasGlobalDiskEdit'] =
            $accessContext['hasGlobalDiskEdit'];

        $site['currentUserHasPageAccess'] =
            $accessContext['hasPageAccess'];

        $allowedSites[] = $site;
    }

    usort(
        $allowedSites,
        static function ($a, $b) {
            return
                (int)($a['id'] ?? 0)
                <=>
                (int)($b['id'] ?? 0);
        }
    );

    sb_json_ok([
        'sites' => $allowedSites,
        'handler' => 'site',
    ]);
}

if ($action === 'site.get') {
    $siteId = (int)($_POST['siteId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    /*
     * Сначала проверяем существование сайта,
     * затем его права.
     */
    $site = sb_find_site($siteId);

    if (!$site) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }

    $accessContext =
        sb_site_handler_get_access_context($siteId);

    if (!$accessContext['allowed']) {
        sb_json_error(
            'SITE_OR_PAGE_ACCESS_DENIED',
            403,
            [
                'siteId' => $siteId,
            ]
        );
    }

    /*
     * Эти поля нужны клиентской части для понимания
     * уровня текущего пользователя.
     */
    $site['currentUserRole'] =
        $accessContext['role'];

    $site['currentUserRoleRank'] =
        $accessContext['roleRank'];

    $site['currentUserHasGlobalView'] =
        $accessContext['hasGlobalView'];

    $site['currentUserHasGlobalEdit'] =
        $accessContext['hasGlobalEdit'];

    $site['currentUserHasGlobalDiskEdit'] =
        $accessContext['hasGlobalDiskEdit'];

    $site['currentUserHasPageAccess'] =
        $accessContext['hasPageAccess'];

    sb_json_ok([
        'site' => $site,
        'access' => [
            'role' => $accessContext['role'],
            'roleRank' => $accessContext['roleRank'],
            'globalView' =>
                $accessContext['hasGlobalView'],
            'globalEdit' =>
                $accessContext['hasGlobalEdit'],
            'hasPageAccess' =>
                $accessContext['hasPageAccess'],
        ],
        'handler' => 'site',
    ]);
}

if ($action === 'site.create') {
    if (!$USER->IsAdmin()) {
        sb_json_error('BITRIX_ADMIN_REQUIRED', 403);
    }

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        sb_json_error('NAME_REQUIRED', 422);
    }

    $sectionId = (int)($_POST['sectionId'] ?? 0);
    sb_site_handler_validate_section($sectionId);

    $sites = sb_read_sites();
    $id = RevisionService::nextEntityId(RevisionService::ENTITY_SITE);

    $slug = trim((string)($_POST['slug'] ?? ''));
    $slug = $slug === '' ? sb_slugify($name) : sb_slugify($slug);

    $existing = array_map(static function ($x) {
        return (string)($x['slug'] ?? '');
    }, $sites);

    $base = $slug !== '' ? $slug : 'site';
    $slug = $base;
    $i = 2;

    while (in_array($slug, $existing, true)) {
        $slug = $base . '-' . $i;
        $i++;
    }

    $now = date('c');
    $currentUserId = (int)$USER->GetID();

    $site = [
        'id' => $id,
        'name' => $name,
        'slug' => $slug,
        'sectionId' => $sectionId,
        'createdBy' => $currentUserId,
        'createdAt' => $now,
        'updatedAt' => $now,
        'updatedBy' => $currentUserId,
        'version' => 1,
        'homePageId' => 0,
        'diskFolderId' => 0,
        'topMenuId' => 0,
        'bitrixGroupId' => 0,
        'bitrixGroupCreatedBy' => 0,
        'bitrixGroupCreatedAt' => '',
        'settings' => [
            'containerWidth' => 1100,
            'accent' => '#2563eb',
            'logoFileId' => 0,
        ],
        'layout' => [
            'showHeader' => true,
            'showFooter' => true,
            'showLeft' => false,
            'showRight' => false,
            'leftWidth' => 260,
            'rightWidth' => 260,
            'leftMode' => 'blocks',
        ],
    ];

    /*
     * PostgreSQL-сущности сохраняются независимо от доступности внешних
     * сервисов. Создание группы и папки Диска выполняет transactional outbox.
     */
    sb_write_sites([$site]);
    $site = RevisionService::getSite($id, false) ?? $site;

    $defaultLayout = sb_layout_default_record($id);
    $defaultLayout['createdBy'] = $currentUserId;
    $defaultLayout['createdAt'] = $now;
    $defaultLayout['updatedBy'] = $currentUserId;
    $defaultLayout['updatedAt'] = $now;
    $defaultLayout['version'] = 1;
    sb_write_layouts([$defaultLayout]);

    sb_set_access_role(
        $id,
        'U' . $currentUserId,
        'OWNER',
        $currentUserId,
        [
            'allowOwnerAssignment' => true,
            'allowOwnerDowngrade' => true,
        ]
    );

    $provisioningJobs = OutboxService::enqueueSiteProvisioning($id, $currentUserId);

    sb_json_ok([
        'site' => $site,
        'provisioningQueued' => true,
        'provisioningJobs' => $provisioningJobs,
        'handler' => 'site',
    ]);
}

if ($action === 'site.update') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_site_handler_require_editor($siteId);
    $expectedVersion = RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null);

    $site = RevisionService::getSite($siteId, false);
    if (!$site) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $containerWidth = (int)($_POST['containerWidth'] ?? 0);
    $accent = trim((string)($_POST['accent'] ?? ''));
    $logoFileId = (int)($_POST['logoFileId'] ?? 0);

    if ($name === '') {
        $name = (string)($site['name'] ?? '');
    }

    if ($slug === '') {
        $slug = (string)($site['slug'] ?? '');
    }

    $slug = sb_slugify($slug);
    if ($slug === '') {
        $slug = 'site-' . $siteId;
    }

    $sites = sb_read_sites();

    $existing = array_map(
        static function ($x) {
            return (string)($x['slug'] ?? '');
        },
        array_filter($sites, static function ($s) use ($siteId) {
            return (int)($s['id'] ?? 0) !== $siteId;
        })
    );

    $base = $slug;
    $i = 2;

    while (in_array($slug, $existing, true)) {
        $slug = $base . '-' . $i;
        $i++;
    }

    if ($containerWidth <= 0) {
        $containerWidth = (int)($site['settings']['containerWidth'] ?? 1100);
    }

    $containerWidth = max(320, min(1920, $containerWidth));

    if ($accent === '') {
        $accent = (string)($site['settings']['accent'] ?? '#2563eb');
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $accent = '#2563eb';
    }

    $settings = isset($site['settings']) && is_array($site['settings']) ? $site['settings'] : [];
    $settings['containerWidth'] = $containerWidth;
    $settings['accent'] = $accent;
    $settings['logoFileId'] = $logoFileId;

    $site['name'] = $name;
    $site['slug'] = $slug;
    $site['settings'] = $settings;

    $updated = RevisionService::saveSite(
        $site,
        $expectedVersion,
        (int)$USER->GetID(),
        'settings_update'
    );

    sb_json_ok([
        'site' => $updated,
        'handler' => 'site',
    ]);
}

if ($action === 'site.delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    sb_site_handler_require_owner($id);

    try {
        $result = SiteDeletionService::delete($id, (int)$USER->GetID());
    } catch (SiteBuilderResourceBusyException $e) {
        sb_json_error('RESOURCE_BUSY', 423, $e->context());
    } catch (PDOException $e) {
        $sqlState = sb_db_exception_sqlstate($e);
        if ($sqlState === '55P03') {
            sb_json_error('RESOURCE_BUSY', 423);
        }
        if ($sqlState === '40P01' || $sqlState === '40001') {
            sb_json_error('RETRY_TRANSACTION', 409);
        }
        throw $e;
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'SITE_NOT_FOUND') {
            sb_json_error('NOT_FOUND', 404);
        }

        error_log('SiteBuilder site.delete failed: ' . $e->getMessage());

        sb_json_error('SITE_DELETE_FAILED', 500, [
            'handler' => 'site',
        ]);
    } catch (Throwable $e) {
        error_log('SiteBuilder site.delete failed: ' . $e->getMessage());

        sb_json_error('SITE_DELETE_FAILED', 500, [
            'handler' => 'site',
        ]);
    }

    sb_json_ok([
        'deleted' => true,
        'siteId' => $id,
        'counts' => $result['counts'] ?? [],
        'cleanupJobs' => $result['cleanupJobs'] ?? [],
        'warnings' => $result['warnings'] ?? [],
        'handler' => 'site',
    ]);
}

if ($action === 'site.setHome') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $pageId = (int)($_POST['pageId'] ?? 0);

    if ($siteId <= 0 || $pageId <= 0) {
        sb_json_error('SITE_PAGE_REQUIRED', 422);
    }

    sb_site_handler_require_editor($siteId);
    $expectedVersion = RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null);

    $page = sb_find_page($pageId);
    if (!$page || (int)($page['siteId'] ?? 0) !== $siteId) {
        sb_json_error('PAGE_NOT_IN_SITE', 422);
    }

    $site = RevisionService::getSite($siteId, false);
    if (!$site) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }
    $site['homePageId'] = $pageId;
    $savedSite = RevisionService::saveSite(
        $site,
        $expectedVersion,
        (int)$USER->GetID(),
        'home_page_change'
    );

    sb_json_ok([
        'site' => $savedSite,
        'handler' => 'site',
    ]);
}

if ($action === 'site.syncAccess') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    sb_site_handler_require_owner($siteId);

    $site = RevisionService::getSite($siteId, false);
    if (!$site) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }

    $currentUserId = (int)$USER->GetID();
    $jobs = [];
    if ((int)($site['bitrixGroupId'] ?? 0) <= 0) {
        $jobs['group'] = OutboxService::enqueueGroupEnsure($siteId, $currentUserId);
        $jobs['sync'] = OutboxService::enqueueAccessSync($siteId, $currentUserId, 5);
    } else {
        $jobs['sync'] = OutboxService::enqueueAccessSync($siteId, $currentUserId);
    }

    sb_json_ok([
        'queued' => true,
        'jobs' => $jobs,
        'handler' => 'site',
        'action' => 'site.syncAccess',
    ]);
}

if ($action === 'site.ensureGroup') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }
    sb_site_handler_require_owner($siteId);

    $expectedVersion = RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null);
    $site = RevisionService::getSite($siteId, false);
    if (!$site) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }
    RevisionService::assertExpected($site, $expectedVersion, RevisionService::ENTITY_SITE);

    $bitrixGroupId = (int)($site['bitrixGroupId'] ?? 0);
    if ($bitrixGroupId > 0) {
        sb_json_ok([
            'site' => $site,
            'bitrixGroupId' => $bitrixGroupId,
            'created' => false,
            'queued' => false,
            'handler' => 'site',
            'action' => 'site.ensureGroup',
        ]);
    }

    $job = OutboxService::enqueueGroupEnsure($siteId, (int)$USER->GetID());
    sb_json_ok([
        'site' => $site,
        'bitrixGroupId' => 0,
        'created' => false,
        'queued' => true,
        'job' => $job,
        'handler' => 'site',
        'action' => 'site.ensureGroup',
    ]);
}

if ($action === 'site.accessList') {
    $siteId = (int)($_POST['siteId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_site_handler_require_owner($siteId);

    if (!class_exists('SiteAccessManagementService')) {
        sb_json_error('SiteAccessManagementService.php не подключен', 500);
    }

    try {
        sb_json_ok([
            'items' => SiteAccessManagementService::list($siteId),
            'handler' => 'site',
            'action' => 'site.accessList',
        ]);
    } catch (Throwable $e) {
        sb_site_handler_handle_exception($e, 'site.accessList');
    }
}

if ($action === 'site.accessSet') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $userId = (int)($_POST['userId'] ?? 0);
    $role = strtoupper(trim((string)($_POST['role'] ?? '')));

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    if ($userId <= 0) {
        sb_json_error('USER_ID_REQUIRED', 422);
    }

    if ($role === '') {
        sb_json_error('ROLE_REQUIRED', 422);
    }

    sb_site_handler_require_owner($siteId);

    if (!class_exists('SiteAccessManagementService')) {
        sb_json_error('SiteAccessManagementService.php не подключен', 500);
    }

    try {
        $result = SiteAccessManagementService::setRole(
            $siteId,
            $userId,
            $role,
            (int)$USER->GetID()
        );

        sb_json_ok([
            'result' => $result,
            'items' => $result['items'] ?? [],
            'handler' => 'site',
            'action' => 'site.accessSet',
        ]);
    } catch (Throwable $e) {
        sb_site_handler_handle_exception($e, 'site.accessSet');
    }
}

if ($action === 'site.accessRemove') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $userId = (int)($_POST['userId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    if ($userId <= 0) {
        sb_json_error('USER_ID_REQUIRED', 422);
    }

    sb_site_handler_require_owner($siteId);

    if (!class_exists('SiteAccessManagementService')) {
        sb_json_error('SiteAccessManagementService.php не подключен', 500);
    }

    try {
        $result = SiteAccessManagementService::removeRole(
            $siteId,
            $userId,
            (int)$USER->GetID()
        );

        sb_json_ok([
            'result' => $result,
            'items' => $result['items'] ?? [],
            'handler' => 'site',
            'action' => 'site.accessRemove',
        ]);
    } catch (Throwable $e) {
        sb_site_handler_handle_exception($e, 'site.accessRemove');
    }
}

if ($action === 'site.appearanceGet') {
    $siteId = (int)($_POST['siteId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_content_manager($siteId);

    try {
        $appearance = SiteAppearanceService::get($siteId);

        sb_json_ok([
            'appearance' => $appearance,
            'handler' => 'site',
            'action' => $action,
        ]);
    } catch (Throwable $e) {
        sb_site_handler_handle_exception($e, $action);
    }
}

if ($action === 'site.appearanceUpdate') {
    global $USER;

    $siteId = (int)($_POST['siteId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_require_content_manager($siteId);

    try {
        $data = $_POST;

        unset($data['action'], $data['sessid'], $data['siteId'], $data['expectedVersion']);

        $appearance = SiteAppearanceService::update(
            $siteId,
            $data,
            (int)$USER->GetID(),
            RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null)
        );

        sb_json_ok([
            'appearance' => $appearance,
            'handler' => 'site',
            'action' => $action,
        ]);
    } catch (SiteBuilderVersionConflictException $e) {
        throw $e;
    } catch (InvalidArgumentException $e) {
        throw $e;
    } catch (Throwable $e) {
        error_log('SiteBuilder site.appearanceUpdate failed: ' . $e->getMessage());
        sb_json_error('SITE_APPEARANCEUPDATE_FAILED', 500, [
            'handler' => 'site',
            'action' => $action,
        ]);
    }
}

if ($action === 'site.appearanceUpload') {
    global $USER;

    $siteId = (int)($_POST['siteId'] ?? 0);
    $type = trim((string)($_POST['type'] ?? ''));

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    if ($type === '') {
        sb_json_error('TYPE_REQUIRED', 422);
    }

    sb_require_content_manager($siteId);

    $file = $_FILES['file'] ?? null;

    if (!is_array($file)) {
        sb_json_error('FILE_REQUIRED', 422);
    }

    try {
        $appearance = SiteAppearanceService::upload(
            $siteId,
            $type,
            $file,
            (int)$USER->GetID(),
            RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null)
        );

        sb_json_ok([
            'appearance' => $appearance,
            'handler' => 'site',
            'action' => $action,
        ]);
    } catch (SiteBuilderVersionConflictException $e) {
        throw $e;
    } catch (InvalidArgumentException $e) {
        throw $e;
    } catch (Throwable $e) {
        error_log('SiteBuilder site.appearanceUpload failed: ' . $e->getMessage());
        sb_json_error('SITE_APPEARANCEUPLOAD_FAILED', 500, [
            'handler' => 'site',
            'action' => $action,
        ]);
    }
}

if ($action === 'site.appearanceRemove') {
    global $USER;

    $siteId = (int)($_POST['siteId'] ?? 0);
    $type = trim((string)($_POST['type'] ?? ''));

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    if ($type === '') {
        sb_json_error('TYPE_REQUIRED', 422);
    }

    sb_require_content_manager($siteId);

    try {
        $appearance = SiteAppearanceService::remove(
            $siteId,
            $type,
            (int)$USER->GetID(),
            RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null)
        );

        sb_json_ok([
            'appearance' => $appearance,
            'handler' => 'site',
            'action' => $action,
        ]);
    } catch (SiteBuilderVersionConflictException $e) {
        throw $e;
    } catch (InvalidArgumentException $e) {
        throw $e;
    } catch (Throwable $e) {
        error_log('SiteBuilder site.appearanceRemove failed: ' . $e->getMessage());
        sb_json_error('SITE_APPEARANCEREMOVE_FAILED', 500, [
            'handler' => 'site',
            'action' => $action,
        ]);
    }
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'site',
    'action' => $action,
]);