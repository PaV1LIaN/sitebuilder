<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/SiteAppearanceService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessRepository.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessService.php';

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
        sb_site_handler_require_role($siteId, 2);
    }
}

if (!function_exists('sb_site_handler_require_viewer')) {
    function sb_site_handler_require_viewer(int $siteId): void
    {
        sb_site_handler_require_role($siteId, 1);
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
            'hasGlobalEdit' => $roleRank >= 2,
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
    function sb_site_handler_update_bitrix_group_fields(int $siteId, int $groupId, int $currentUserId): void
    {
        if ($siteId <= 0 || $groupId <= 0) {
            return;
        }

        sb_db_execute("
            UPDATE sitebuilder.site
            SET
                bitrix_group_id = :bitrix_group_id,
                bitrix_group_created_by = :created_by,
                bitrix_group_created_at = now()
            WHERE id = :site_id
        ", [
            ':bitrix_group_id' => $groupId,
            ':created_by' => $currentUserId,
            ':site_id' => $siteId,
        ]);
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

        foreach ($targetAccess as $accessCode => $role) {
            $members[] = [
                'userId' => (int)preg_replace('/\D+/', '', $accessCode),
                'accessCode' => $accessCode,
                'sonetRole' => '',
                'sitebuilderRole' => $role,
            ];
        }

        $access = sb_read_access();
        $next = [];
        $existingByCode = [];

        foreach ($access as $row) {
            if ((int)($row['siteId'] ?? 0) === $siteId) {
                $existingByCode[(string)($row['accessCode'] ?? '')] = $row;
                continue;
            }

            $next[] = $row;
        }

        $created = 0;
        $updated = 0;
        $removed = 0;
        $kept = 0;

        $now = date('c');

        foreach ($targetAccess as $accessCode => $role) {
            if (isset($existingByCode[$accessCode])) {
                $row = $existingByCode[$accessCode];
                $oldRole = (string)($row['role'] ?? '');

                if ($oldRole !== $role) {
                    $row['role'] = $role;
                    $row['updatedAt'] = $now;
                    $row['updatedBy'] = $currentUserId;
                    $updated++;
                } else {
                    $kept++;
                }

                $next[] = $row;
                unset($existingByCode[$accessCode]);
                continue;
            }

            $next[] = [
                'siteId' => $siteId,
                'accessCode' => $accessCode,
                'role' => $role,
                'createdBy' => $currentUserId,
                'createdAt' => $now,
                'updatedAt' => $now,
                'updatedBy' => $currentUserId,
            ];

            $created++;
        }

        if (!$strict) {
            foreach ($existingByCode as $row) {
                $next[] = $row;
                $kept++;
            }
        } else {
            $removed = count($existingByCode);
        }

        sb_write_access($next);

        return [
            'siteId' => $siteId,
            'bitrixGroupId' => $bitrixGroupId,
            'strict' => $strict,
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
        'file' => __FILE__,
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
        'file' => __FILE__,
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

    $maxId = 0;
    foreach ($sites as $s) {
        $maxId = max($maxId, (int)($s['id'] ?? 0));
    }

    $id = $maxId + 1;

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

    $bitrixGroupId = 0;
    $bitrixGroupError = '';

    if (class_exists('SiteBitrixGroupService')) {
        try {
            $bitrixGroupId = (int)SiteBitrixGroupService::createForSite($site, $currentUserId);

            if ($bitrixGroupId > 0) {
                $site['bitrixGroupId'] = $bitrixGroupId;
                $site['bitrixGroupCreatedBy'] = $currentUserId;
                $site['bitrixGroupCreatedAt'] = $now;
            }
        } catch (Throwable $e) {
            $bitrixGroupError = $e->getMessage();
        }
    } else {
        $bitrixGroupError = 'SiteBitrixGroupService.php не подключен';
    }

    $sites[] = $site;
    sb_write_sites($sites);

    if ($bitrixGroupId > 0) {
        sb_site_handler_update_bitrix_group_fields($id, $bitrixGroupId, $currentUserId);
        $site = sb_find_site($id) ?: $site;
    }

    $access = sb_read_access();
    $access[] = [
        'siteId' => $id,
        'accessCode' => 'U' . $currentUserId,
        'role' => 'OWNER',
        'createdBy' => $currentUserId,
        'createdAt' => $now,
        'updatedAt' => $now,
        'updatedBy' => $currentUserId,
    ];
    sb_write_access($access);

    /*
    * Удаляем точечные права страниц удалённого сайта.
    */
    sb_db_execute("
    DELETE FROM sitebuilder.page_access
    WHERE site_id = :site_id
    ", [
    ':site_id' => $id,
    ]);

    sb_json_ok([
        'site' => $site,
        'bitrixGroupId' => $bitrixGroupId,
        'bitrixGroupError' => $bitrixGroupError,
        'handler' => 'site',
        'file' => __FILE__,
    ]);
}

if ($action === 'site.update') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_site_handler_require_editor($siteId);

    $site = sb_find_site($siteId);
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

    $updated = null;

    foreach ($sites as &$s) {
        if ((int)($s['id'] ?? 0) !== $siteId) {
            continue;
        }

        $settings = isset($s['settings']) && is_array($s['settings']) ? $s['settings'] : [];
        $settings['containerWidth'] = $containerWidth;
        $settings['accent'] = $accent;
        $settings['logoFileId'] = $logoFileId;

        $s['name'] = $name;
        $s['slug'] = $slug;
        $s['settings'] = $settings;
        $s['updatedAt'] = date('c');
        $s['updatedBy'] = (int)$USER->GetID();

        $updated = $s;
        break;
    }
    unset($s);

    if (!$updated) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }

    sb_write_sites($sites);

    sb_json_ok([
        'site' => $updated,
        'handler' => 'site',
        'file' => __FILE__,
    ]);
}

if ($action === 'site.delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        sb_json_error('ID_REQUIRED', 422);
    }

    sb_site_handler_require_owner($id);

    $sites = sb_read_sites();
    $before = count($sites);

    $sites = array_values(array_filter($sites, static function ($s) use ($id) {
        return (int)($s['id'] ?? 0) !== $id;
    }));

    if (count($sites) === $before) {
        sb_json_error('NOT_FOUND', 404);
    }

    sb_write_sites($sites);

    $pages = sb_read_pages();
    $deletedPageIds = [];

    foreach ($pages as $p) {
        if ((int)($p['siteId'] ?? 0) === $id) {
            $deletedPageIds[(int)($p['id'] ?? 0)] = true;
        }
    }

    $pages = array_values(array_filter($pages, static function ($p) use ($id) {
        return (int)($p['siteId'] ?? 0) !== $id;
    }));
    sb_write_pages($pages);

    $blocks = sb_read_blocks();
    $blocks = array_values(array_filter($blocks, static function ($b) use ($deletedPageIds) {
        return !isset($deletedPageIds[(int)($b['pageId'] ?? 0)]);
    }));
    sb_write_blocks($blocks);

    $access = sb_read_access();
    $access = array_values(array_filter($access, static function ($r) use ($id) {
        return (int)($r['siteId'] ?? 0) !== $id;
    }));
    sb_write_access($access);

    $menus = sb_read_menus();
    $menus = array_values(array_filter($menus, static function ($m) use ($id) {
        return (int)($m['siteId'] ?? 0) !== $id;
    }));
    sb_write_menus($menus);

    if (function_exists('sb_read_templates') && function_exists('sb_write_templates')) {
        $templates = sb_read_templates();
        $templates = array_values(array_filter($templates, static function ($tpl) use ($id) {
            return (int)($tpl['siteId'] ?? 0) !== $id;
        }));
        sb_write_templates($templates);
    }

    if (function_exists('sb_read_layouts') && function_exists('sb_write_layouts')) {
        $layouts = sb_read_layouts();
        $layouts = array_values(array_filter($layouts, static function ($layout) use ($id) {
            return (int)($layout['siteId'] ?? 0) !== $id;
        }));
        sb_write_layouts($layouts);
    }

    sb_json_ok([
        'handler' => 'site',
        'file' => __FILE__,
    ]);
}

if ($action === 'site.setHome') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $pageId = (int)($_POST['pageId'] ?? 0);

    if ($siteId <= 0 || $pageId <= 0) {
        sb_json_error('SITE_PAGE_REQUIRED', 422);
    }

    sb_site_handler_require_editor($siteId);

    $page = sb_find_page($pageId);
    if (!$page || (int)($page['siteId'] ?? 0) !== $siteId) {
        sb_json_error('PAGE_NOT_IN_SITE', 422);
    }

    $sites = sb_read_sites();
    $found = false;

    foreach ($sites as $i => $s) {
        if ((int)($s['id'] ?? 0) === $siteId) {
            $sites[$i]['homePageId'] = $pageId;
            $sites[$i]['updatedAt'] = date('c');
            $sites[$i]['updatedBy'] = (int)$USER->GetID();
            $found = true;
            break;
        }
    }

    if (!$found) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }

    sb_write_sites($sites);

    sb_json_ok([
        'handler' => 'site',
        'file' => __FILE__,
    ]);
}

if ($action === 'site.syncAccess') {
    $siteId = (int)($_POST['siteId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_site_handler_require_owner($siteId);

    try {
        $result = null;

        if (class_exists('SiteAccessSyncService')) {
            if (method_exists('SiteAccessSyncService', 'sync')) {
                $result = SiteAccessSyncService::sync($siteId, (int)$USER->GetID(), true);
            } elseif (method_exists('SiteAccessSyncService', 'syncSiteAccess')) {
                $result = SiteAccessSyncService::syncSiteAccess($siteId, (int)$USER->GetID(), true);
            } elseif (method_exists('SiteAccessSyncService', 'syncSite')) {
                $result = SiteAccessSyncService::syncSite($siteId, (int)$USER->GetID(), true);
            }
        }

        if (!is_array($result)) {
            $result = sb_site_handler_sync_access_fallback($siteId, (int)$USER->GetID(), true);
        }

        sb_json_ok([
            'result' => $result,
            'handler' => 'site',
            'action' => 'site.syncAccess',
            'file' => __FILE__,
        ]);
    } catch (Throwable $e) {
        sb_json_error($e->getMessage(), 500, [
            'handler' => 'site',
            'action' => 'site.syncAccess',
            'file' => __FILE__,
        ]);
    }
}

if ($action === 'site.ensureGroup') {
    $siteId = (int)($_POST['siteId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    sb_site_handler_require_owner($siteId);

    $site = sb_find_site($siteId);

    if (!$site) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }

    $currentUserId = (int)$USER->GetID();

    $bitrixGroupId = sb_site_handler_get_bitrix_group_id($site);

    if ($bitrixGroupId > 0) {
        sb_json_ok([
            'site' => $site,
            'bitrixGroupId' => $bitrixGroupId,
            'created' => false,
            'handler' => 'site',
            'action' => 'site.ensureGroup',
            'file' => __FILE__,
        ]);
    }

    if (!class_exists('SiteBitrixGroupService')) {
        sb_json_error('SiteBitrixGroupService.php не подключен', 500, [
            'handler' => 'site',
            'action' => 'site.ensureGroup',
            'file' => __FILE__,
        ]);
    }

    try {
        $bitrixGroupId = (int)SiteBitrixGroupService::createForSite([
            'id' => $siteId,
            'name' => (string)($site['name'] ?? ('Сайт #' . $siteId)),
        ], $currentUserId);

        if ($bitrixGroupId <= 0) {
            throw new RuntimeException('BITRIX_GROUP_CREATE_EMPTY_ID');
        }

        sb_site_handler_update_bitrix_group_fields($siteId, $bitrixGroupId, $currentUserId);

        $site = sb_find_site($siteId);

        sb_json_ok([
            'site' => $site,
            'bitrixGroupId' => $bitrixGroupId,
            'created' => true,
            'handler' => 'site',
            'action' => 'site.ensureGroup',
            'file' => __FILE__,
        ]);
    } catch (Throwable $e) {
        sb_json_error($e->getMessage(), 500, [
            'handler' => 'site',
            'action' => 'site.ensureGroup',
            'file' => __FILE__,
        ]);
    }
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
            'file' => __FILE__,
        ]);
    } catch (Throwable $e) {
        sb_json_error($e->getMessage(), 500, [
            'handler' => 'site',
            'action' => 'site.accessList',
            'file' => __FILE__,
        ]);
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
            'file' => __FILE__,
        ]);
    } catch (Throwable $e) {
        sb_json_error($e->getMessage(), 500, [
            'handler' => 'site',
            'action' => 'site.accessSet',
            'file' => __FILE__,
        ]);
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
            'file' => __FILE__,
        ]);
    } catch (Throwable $e) {
        sb_json_error($e->getMessage(), 500, [
            'handler' => 'site',
            'action' => 'site.accessRemove',
            'file' => __FILE__,
        ]);
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
            'file' => __FILE__,
        ]);
    } catch (Throwable $e) {
        sb_json_error($e->getMessage(), 500, [
            'handler' => 'site',
            'action' => $action,
            'file' => __FILE__,
        ]);
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

        unset($data['action'], $data['sessid'], $data['siteId']);

        $appearance = SiteAppearanceService::update(
            $siteId,
            $data,
            (int)$USER->GetID()
        );

        sb_json_ok([
            'appearance' => $appearance,
            'handler' => 'site',
            'action' => $action,
            'file' => __FILE__,
        ]);
    } catch (Throwable $e) {
        sb_json_error($e->getMessage(), 500, [
            'handler' => 'site',
            'action' => $action,
            'file' => __FILE__,
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
            (int)$USER->GetID()
        );

        sb_json_ok([
            'appearance' => $appearance,
            'handler' => 'site',
            'action' => $action,
            'file' => __FILE__,
        ]);
    } catch (Throwable $e) {
        sb_json_error($e->getMessage(), 500, [
            'handler' => 'site',
            'action' => $action,
            'file' => __FILE__,
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
            (int)$USER->GetID()
        );

        sb_json_ok([
            'appearance' => $appearance,
            'handler' => 'site',
            'action' => $action,
            'file' => __FILE__,
        ]);
    } catch (Throwable $e) {
        sb_json_error($e->getMessage(), 500, [
            'handler' => 'site',
            'action' => $action,
            'file' => __FILE__,
        ]);
    }
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'site',
    'action' => $action,
    'file' => __FILE__,
]);