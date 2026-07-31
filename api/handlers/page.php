<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessRepository.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageAccessService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageSectionRepository.php';

/*
 * Локальные функции обработчика страниц.
 */

if (!function_exists('sb_page_handler_find_by_id')) {
    function sb_page_handler_find_by_id(array $pages, int $id): ?array
    {
        foreach ($pages as $page) {
            if ((int)($page['id'] ?? 0) === $id) {
                return $page;
            }
        }

        return null;
    }
}

if (!function_exists('sb_page_handler_find_index_by_id')) {
    function sb_page_handler_find_index_by_id(array $pages, int $id): int
    {
        foreach ($pages as $index => $page) {
            if ((int)($page['id'] ?? 0) === $id) {
                return (int)$index;
            }
        }

        return -1;
    }
}

if (!function_exists('sb_page_handler_is_descendant')) {
    function sb_page_handler_is_descendant(
        array $pages,
        int $pageId,
        int $possibleParentId
    ): bool {
        $current = sb_page_handler_find_by_id($pages, $possibleParentId);
        $safety = 0;

        while ($current && $safety < 1000) {
            $parentId = (int)($current['parentId'] ?? 0);

            if ($parentId <= 0) {
                return false;
            }

            if ($parentId === $pageId) {
                return true;
            }

            $current = sb_page_handler_find_by_id($pages, $parentId);
            $safety++;
        }

        return false;
    }
}

if (!function_exists('sb_page_handler_normalize_seo')) {
    function sb_page_handler_normalize_seo(array $seo): array
    {
        $safeUrl = static function ($value): string {
            $value = trim((string)$value);
            if ($value === '') return '';
            if (str_starts_with($value, '/') && !str_starts_with($value, '//')) return mb_substr($value, 0, 1000);
            if (preg_match('#^https?://#i', $value)) return mb_substr($value, 0, 1000);
            return '';
        };

        return [
            'title' => mb_substr(trim((string)($seo['title'] ?? '')), 0, 255),
            'description' => mb_substr(trim((string)($seo['description'] ?? '')), 0, 500),
            'keywords' => mb_substr(trim((string)($seo['keywords'] ?? '')), 0, 500),
            'canonical' => $safeUrl($seo['canonical'] ?? ''),
            'robotsIndex' => !array_key_exists('robotsIndex', $seo) || !empty($seo['robotsIndex']),
            'robotsFollow' => !array_key_exists('robotsFollow', $seo) || !empty($seo['robotsFollow']),
            'ogTitle' => mb_substr(trim((string)($seo['ogTitle'] ?? '')), 0, 255),
            'ogDescription' => mb_substr(trim((string)($seo['ogDescription'] ?? '')), 0, 500),
            'ogImage' => $safeUrl($seo['ogImage'] ?? ''),
        ];
    }
}

if (!function_exists('sb_page_handler_current_user_id')) {
    function sb_page_handler_current_user_id(): int
    {
        global $USER;

        if (
            !is_object($USER)
            || !method_exists($USER, 'IsAuthorized')
            || !$USER->IsAuthorized()
        ) {
            sb_json_error('AUTH_REQUIRED', 401);
        }

        return (int)$USER->GetID();
    }
}

if (!function_exists('sb_page_handler_is_bitrix_admin')) {
    function sb_page_handler_is_bitrix_admin(): bool
    {
        global $USER;

        return is_object($USER)
            && method_exists($USER, 'IsAdmin')
            && $USER->IsAdmin();
    }
}

if (!function_exists('sb_page_handler_has_global_view')) {
    function sb_page_handler_has_global_view(
        int $siteId,
        int $userId
    ): bool {
        if (sb_page_handler_is_bitrix_admin()) {
            return true;
        }

        return PageAccessService::hasGlobalSiteAccess(
            $siteId,
            $userId,
            'view'
        );
    }
}

if (!function_exists('sb_page_handler_has_global_edit')) {
    function sb_page_handler_has_global_edit(
        int $siteId,
        int $userId
    ): bool {
        if (sb_page_handler_is_bitrix_admin()) {
            return true;
        }

        return PageAccessService::hasGlobalSiteAccess(
            $siteId,
            $userId,
            'edit'
        );
    }
}

if (!function_exists('sb_page_handler_require_page_view')) {
    function sb_page_handler_require_page_view(
        int $siteId,
        int $pageId,
        int $userId
    ): void {
        if (
            !PageAccessService::canViewPage(
                $siteId,
                $pageId,
                $userId
            )
        ) {
            sb_json_error('PAGE_VIEW_ACCESS_DENIED', 403, [
                'siteId' => $siteId,
                'pageId' => $pageId,
            ]);
        }
    }
}

if (!function_exists('sb_page_handler_require_page_edit')) {
    function sb_page_handler_require_page_edit(
        int $siteId,
        int $pageId,
        int $userId
    ): void {
        if (
            !PageAccessService::canEditPage(
                $siteId,
                $pageId,
                $userId
            )
        ) {
            sb_json_error('PAGE_EDIT_ACCESS_DENIED', 403, [
                'siteId' => $siteId,
                'pageId' => $pageId,
            ]);
        }
    }
}

if (!function_exists('sb_page_handler_add_access_info')) {
    function sb_page_handler_add_access_info(
        array $page,
        int $siteId,
        int $userId
    ): array {
        $pageId = (int)($page['id'] ?? 0);

        $access = PageAccessService::getPageAccessInfo(
            $siteId,
            $pageId,
            $userId
        );

        $page['access'] = $access;

        /*
         * navigationOnly = true:
         * пользователь не имеет доступа к самой странице,
         * но она нужна в дереве как родитель доступной подстраницы.
         */
        $page['navigationOnly'] = !$access['canView'];

        return $page;
    }
}

if (!function_exists('sb_page_handler_filter_visible_pages')) {
    function sb_page_handler_filter_visible_pages(
        array $pages,
        int $siteId,
        int $userId
    ): array {
        $hasGlobalView = sb_page_handler_has_global_view(
            $siteId,
            $userId
        );

        /*
         * Любая глобальная роль с правом просмотра
         * сохраняет доступ ко всем страницам.
         *
         * Индивидуальные правила при этом могут дополнительно
         * дать canEdit для отдельных страниц.
         */
        if ($hasGlobalView) {
            return array_values(array_map(
                static function ($page) use ($siteId, $userId) {
                    $page = sb_normalize_page_record($page);

                    return sb_page_handler_add_access_info(
                        $page,
                        $siteId,
                        $userId
                    );
                },
                $pages
            ));
        }

        /*
         * Пользователь без глобальной роли получает только
         * страницы, разрешённые через page_access.
         */
        $accessCode = PageAccessRepository::userAccessCode(
            $userId
        );

        $pagesById = [];

        foreach ($pages as $page) {
            $pageId = (int)($page['id'] ?? 0);

            if ($pageId > 0) {
                $pagesById[$pageId] = $page;
            }
        }

        $includedIds = [];
        $permissionsByPageId = [];

        foreach ($pages as $page) {
            $pageId = (int)($page['id'] ?? 0);

            if ($pageId <= 0) {
                continue;
            }

            $canView = PageAccessRepository::hasPagePermission(
                $siteId,
                $pageId,
                $accessCode,
                'view'
            );

            $canEdit = PageAccessRepository::hasPagePermission(
                $siteId,
                $pageId,
                $accessCode,
                'edit'
            );

            if (!$canView && !$canEdit) {
                continue;
            }

            $includedIds[$pageId] = true;

            $permissionsByPageId[$pageId] = [
                'canView' => $canView || $canEdit,
                'canEdit' => $canEdit,
            ];
        }

        /*
         * Добавляем родителей разрешённых страниц,
         * чтобы сохранить дерево навигации.
         */
        foreach (array_keys($includedIds) as $pageId) {
            $currentId = (int)$pageId;
            $visited = [];

            while ($currentId > 0) {
                if (isset($visited[$currentId])) {
                    break;
                }

                $visited[$currentId] = true;

                $currentPage = $pagesById[$currentId] ?? null;

                if (!$currentPage) {
                    break;
                }

                $parentId = (int)(
                    $currentPage['parentId'] ?? 0
                );

                if ($parentId <= 0) {
                    break;
                }

                $includedIds[$parentId] = true;

                if (!isset($permissionsByPageId[$parentId])) {
                    $permissionsByPageId[$parentId] = [
                        'canView' => false,
                        'canEdit' => false,
                    ];
                }

                $currentId = $parentId;
            }
        }

        $result = [];

        foreach ($pages as $page) {
            $pageId = (int)($page['id'] ?? 0);

            if (!isset($includedIds[$pageId])) {
                continue;
            }

            $page = sb_normalize_page_record($page);

            $permission = $permissionsByPageId[$pageId] ?? [
                'canView' => false,
                'canEdit' => false,
            ];

            $page['access'] = [
                'canView' => (bool)$permission['canView'],
                'canEdit' => (bool)$permission['canEdit'],
            ];

            $page['navigationOnly'] =
                !$permission['canView'];

            $result[] = $page;
        }

        return array_values($result);
    }
}

if (!function_exists('sb_page_handler_delete_access_rows')) {
    function sb_page_handler_delete_access_rows(
        int $siteId,
        array $pageIds
    ): void {
        $pageIds = array_values(array_unique(array_filter(
            array_map('intval', $pageIds),
            static fn(int $id): bool => $id > 0
        )));

        if ($siteId <= 0 || empty($pageIds)) {
            return;
        }

        $pdo = sb_db();

        $placeholders = [];
        $params = [
            ':site_id' => $siteId,
        ];

        foreach ($pageIds as $index => $pageId) {
            $placeholder = ':page_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $pageId;
        }

        $stmt = $pdo->prepare("
            DELETE FROM sitebuilder.page_access
            WHERE site_id = :site_id
              AND page_id IN (" . implode(',', $placeholders) . ")
        ");

        $stmt->execute($params);
    }
}

if (!function_exists('sb_page_handler_grant_creator_access')) {
    function sb_page_handler_grant_creator_access(
        int $siteId,
        int $pageId,
        int $userId
    ): void {
        PageAccessRepository::save(
            $siteId,
            $pageId,
            PageAccessRepository::userAccessCode($userId),
            true,
            true,
            false,
            $userId
        );
    }
}

/*
 * Получение списка страниц.
 */
if ($action === 'page.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    $currentUserId = sb_page_handler_current_user_id();

    $hasGlobalView = sb_page_handler_has_global_view(
        $siteId,
        $currentUserId
    );

    $hasPageAccess = PageAccessService::hasAnyPageAccess(
        $siteId,
        $currentUserId
    );

    if (!$hasGlobalView && !$hasPageAccess) {
        sb_json_error('SITE_OR_PAGE_ACCESS_DENIED', 403, [
            'siteId' => $siteId,
        ]);
    }

    $pages = array_values(array_filter(
        sb_read_pages(),
        static function ($page) use ($siteId) {
            return (int)($page['siteId'] ?? 0) === $siteId;
        }
    ));

    usort($pages, static function ($a, $b) {
        $sortCompare =
            (int)($a['sort'] ?? 500)
            <=>
            (int)($b['sort'] ?? 500);

        if ($sortCompare !== 0) {
            return $sortCompare;
        }

        return
            (int)($a['id'] ?? 0)
            <=>
            (int)($b['id'] ?? 0);
    });

    $pages = sb_page_handler_filter_visible_pages(
        $pages,
        $siteId,
        $currentUserId
    );

    sb_json_ok([
        'pages' => $pages,
        'access' => [
            'globalView' => $hasGlobalView,
            'globalEdit' => sb_page_handler_has_global_edit(
                $siteId,
                $currentUserId
            ),
            'hasPageAccess' => $hasPageAccess,
        ],
    ]);
}

/*
 * Создание страницы.
 */
if ($action === 'page.create') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $parentId = (int)($_POST['parentId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_REQUIRED', 422);
    }

    if ($title === '') {
        sb_json_error('TITLE_REQUIRED', 422);
    }

    $currentUserId = sb_page_handler_current_user_id();

    $hasGlobalEdit = sb_page_handler_has_global_edit(
        $siteId,
        $currentUserId
    );

    $pages = sb_read_pages();

    if ($parentId > 0) {
        $parent = sb_page_handler_find_by_id($pages, $parentId);

        if (
            !$parent
            || (int)($parent['siteId'] ?? 0) !== $siteId
        ) {
            sb_json_error('PARENT_PAGE_NOT_FOUND', 404);
        }

        if (
            !$hasGlobalEdit
            && !PageAccessService::canEditPage(
                $siteId,
                $parentId,
                $currentUserId
            )
        ) {
            sb_json_error('PARENT_PAGE_EDIT_ACCESS_DENIED', 403, [
                'parentId' => $parentId,
            ]);
        }
    } elseif (!$hasGlobalEdit) {
        /*
         * Корневые страницы может создавать только пользователь
         * с глобальным правом редактирования сайта.
         */
        sb_json_error('ROOT_PAGE_CREATE_ACCESS_DENIED', 403);
    }

    if ($slug === '') {
        $slug = sb_slugify($title);
    }

    $id = RevisionService::nextEntityId(RevisionService::ENTITY_PAGE);
    $maxSort = 0;

    foreach ($pages as $page) {
        if (
            (int)($page['siteId'] ?? 0) === $siteId
            && (int)($page['parentId'] ?? 0) === $parentId
        ) {
            $maxSort = max(
                $maxSort,
                (int)($page['sort'] ?? 0)
            );
        }
    }

    $page = sb_normalize_page_record([
        'id' => $id,
        'siteId' => $siteId,
        'title' => $title,
        'slug' => $slug,
        'parentId' => $parentId,
        'sort' => $maxSort > 0 ? $maxSort + 10 : 10,
        'status' => 'draft',
        'publishedAt' => null,
        'seo' => [],
        'createdBy' => $currentUserId,
        'createdAt' => date('c'),
        'updatedBy' => $currentUserId,
        'updatedAt' => date('c'),
    ]);

    $pages[] = $page;

    sb_write_pages([$page]);

    /*
     * Если страницу создал пользователь с доступом только
     * к отдельной ветке, выдаём ему прямое право на новую страницу.
     */
    if (!$hasGlobalEdit) {
        sb_page_handler_grant_creator_access(
            $siteId,
            $id,
            $currentUserId
        );
    }

    $page = sb_page_handler_add_access_info(
        $page,
        $siteId,
        $currentUserId
    );

    sb_json_ok([
        'page' => $page,
    ]);
}


/*
 * Единое сохранение свойств страницы с optimistic locking.
 */
if ($action === 'page.save') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $parentId = (int)($_POST['parentId'] ?? 0);
    $status = trim((string)($_POST['status'] ?? 'draft'));
    $seoRaw = $_POST['seo'] ?? null;
    $expectedVersion = RevisionService::requireExpectedVersion(
        $_POST['expectedVersion'] ?? null
    );

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    if ($title === '') {
        sb_json_error('TITLE_REQUIRED', 422);
    }

    if (!in_array($status, ['draft', 'published'], true)) {
        sb_json_error('INVALID_STATUS', 422);
    }

    $currentUserId = sb_page_handler_current_user_id();
    $pages = sb_read_pages();
    $page = sb_page_handler_find_by_id($pages, $id);

    if (!$page) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $siteId = (int)($page['siteId'] ?? 0);
    sb_page_handler_require_page_edit($siteId, $id, $currentUserId);

    $hasGlobalEdit = sb_page_handler_has_global_edit($siteId, $currentUserId);

    if ($slug === '') {
        $slug = sb_slugify($title);
    }

    if ($parentId === $id) {
        sb_json_error('PAGE_CANNOT_BE_OWN_PARENT', 422);
    }

    if ($parentId > 0) {
        $parent = sb_page_handler_find_by_id($pages, $parentId);

        if (!$parent || (int)($parent['siteId'] ?? 0) !== $siteId) {
            sb_json_error('PARENT_PAGE_NOT_FOUND', 404);
        }

        if (sb_page_handler_is_descendant($pages, $id, $parentId)) {
            sb_json_error('CYCLIC_PARENT_RELATION', 422);
        }

        if (
            !$hasGlobalEdit
            && !PageAccessService::canEditPage($siteId, $parentId, $currentUserId)
        ) {
            sb_json_error('PARENT_PAGE_EDIT_ACCESS_DENIED', 403);
        }
    } elseif (!$hasGlobalEdit && (int)($page['parentId'] ?? 0) !== 0) {
        sb_json_error('MOVE_PAGE_TO_ROOT_ACCESS_DENIED', 403);
    }

    $page['title'] = $title;
    $page['slug'] = $slug;
    $page['parentId'] = $parentId;

    if ((string)($page['status'] ?? 'draft') !== $status) {
        $page['publishedAt'] = $status === 'published' ? date('c') : null;
    }
    $page['status'] = $status;

    if ($seoRaw !== null) {
        $seo = is_array($seoRaw) ? $seoRaw : json_decode((string)$seoRaw, true);
        if (!is_array($seo)) {
            sb_json_error('BAD_SEO_JSON', 422);
        }
        $page['seo'] = sb_page_handler_normalize_seo($seo);
    }

    $saved = RevisionService::savePage(
        $page,
        $expectedVersion,
        $currentUserId,
        'save'
    );

    sb_json_ok([
        'page' => sb_page_handler_add_access_info(
            $saved,
            $siteId,
            $currentUserId
        ),
    ]);
}

/*
 * Изменение названия, URL и родителя.
 */
if ($action === 'page.updateMeta') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $parentId = isset($_POST['parentId'])
        ? (int)$_POST['parentId']
        : null;

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    if ($title === '') {
        sb_json_error('TITLE_REQUIRED', 422);
    }

    $currentUserId = sb_page_handler_current_user_id();
    $pages = sb_read_pages();

    $index = sb_page_handler_find_index_by_id($pages, $id);

    if ($index < 0) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $page = $pages[$index];
    $siteId = (int)($page['siteId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_NOT_FOUND', 422);
    }

    sb_page_handler_require_page_edit(
        $siteId,
        $id,
        $currentUserId
    );

    $hasGlobalEdit = sb_page_handler_has_global_edit(
        $siteId,
        $currentUserId
    );

    if ($slug === '') {
        $slug = sb_slugify($title);
    }

    if ($parentId !== null) {
        if ($parentId === $id) {
            sb_json_error('PAGE_CANNOT_BE_OWN_PARENT', 422);
        }

        if ($parentId > 0) {
            $parent = sb_page_handler_find_by_id(
                $pages,
                $parentId
            );

            if (
                !$parent
                || (int)($parent['siteId'] ?? 0) !== $siteId
            ) {
                sb_json_error('PARENT_PAGE_NOT_FOUND', 404);
            }

            if (
                sb_page_handler_is_descendant(
                    $pages,
                    $id,
                    $parentId
                )
            ) {
                sb_json_error('CYCLIC_PARENT_RELATION', 422);
            }

            if (
                !$hasGlobalEdit
                && !PageAccessService::canEditPage(
                    $siteId,
                    $parentId,
                    $currentUserId
                )
            ) {
                sb_json_error(
                    'PARENT_PAGE_EDIT_ACCESS_DENIED',
                    403
                );
            }
        } elseif (!$hasGlobalEdit) {
            /*
             * Перенос страницы в корень — изменение структуры сайта.
             */
            sb_json_error(
                'MOVE_PAGE_TO_ROOT_ACCESS_DENIED',
                403
            );
        }

        $page['parentId'] = $parentId;
    }

    $page['title'] = $title;
    $page['slug'] = $slug;

    $expectedVersion = RevisionService::requireExpectedVersion(
        $_POST['expectedVersion'] ?? null
    );
    $savedPage = RevisionService::savePage(
        $page,
        $expectedVersion,
        $currentUserId,
        'meta_update'
    );

    $resultPage = sb_page_handler_add_access_info(
        $savedPage,
        $siteId,
        $currentUserId
    );

    sb_json_ok([
        'page' => $resultPage,
    ]);
}

/*
 * Изменение родителя.
 */
if ($action === 'page.setParent') {
    $id = (int)($_POST['id'] ?? 0);
    $parentId = (int)($_POST['parentId'] ?? 0);

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    $currentUserId = sb_page_handler_current_user_id();
    $pages = sb_read_pages();

    $index = sb_page_handler_find_index_by_id($pages, $id);

    if ($index < 0) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $page = $pages[$index];
    $siteId = (int)($page['siteId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_NOT_FOUND', 422);
    }

    sb_page_handler_require_page_edit(
        $siteId,
        $id,
        $currentUserId
    );

    $hasGlobalEdit = sb_page_handler_has_global_edit(
        $siteId,
        $currentUserId
    );

    if ($parentId === $id) {
        sb_json_error('PAGE_CANNOT_BE_OWN_PARENT', 422);
    }

    if ($parentId > 0) {
        $parent = sb_page_handler_find_by_id(
            $pages,
            $parentId
        );

        if (
            !$parent
            || (int)($parent['siteId'] ?? 0) !== $siteId
        ) {
            sb_json_error('PARENT_PAGE_NOT_FOUND', 404);
        }

        if (
            sb_page_handler_is_descendant(
                $pages,
                $id,
                $parentId
            )
        ) {
            sb_json_error('CYCLIC_PARENT_RELATION', 422);
        }

        if (
            !$hasGlobalEdit
            && !PageAccessService::canEditPage(
                $siteId,
                $parentId,
                $currentUserId
            )
        ) {
            sb_json_error(
                'PARENT_PAGE_EDIT_ACCESS_DENIED',
                403
            );
        }
    } elseif (!$hasGlobalEdit) {
        sb_json_error(
            'MOVE_PAGE_TO_ROOT_ACCESS_DENIED',
            403
        );
    }

    $page['parentId'] = $parentId;

    $expectedVersion = RevisionService::requireExpectedVersion(
        $_POST['expectedVersion'] ?? null
    );
    $savedPage = RevisionService::savePage(
        $page,
        $expectedVersion,
        $currentUserId,
        'parent_change'
    );

    $resultPage = sb_page_handler_add_access_info(
        $savedPage,
        $siteId,
        $currentUserId
    );

    sb_json_ok([
        'page' => $resultPage,
    ]);
}

/*
 * Публикация или снятие с публикации.
 */
if ($action === 'page.setStatus') {
    $id = (int)($_POST['id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    if (!in_array($status, ['draft', 'published'], true)) {
        sb_json_error('INVALID_STATUS', 422);
    }

    $currentUserId = sb_page_handler_current_user_id();
    $pages = sb_read_pages();

    $index = sb_page_handler_find_index_by_id($pages, $id);

    if ($index < 0) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $page = $pages[$index];
    $siteId = (int)($page['siteId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_NOT_FOUND', 422);
    }

    sb_page_handler_require_page_edit(
        $siteId,
        $id,
        $currentUserId
    );

    $page['status'] = $status;
    $page['publishedAt'] =
        $status === 'published'
            ? date('c')
            : null;

    $expectedVersion = RevisionService::requireExpectedVersion(
        $_POST['expectedVersion'] ?? null
    );
    $savedPage = RevisionService::savePage(
        $page,
        $expectedVersion,
        $currentUserId,
        'status_change'
    );

    $resultPage = sb_page_handler_add_access_info(
        $savedPage,
        $siteId,
        $currentUserId
    );

    sb_json_ok([
        'page' => $resultPage,
    ]);
}

/*
 * Перемещение страницы вверх или вниз.
 */
if ($action === 'page.move') {
    $id = (int)($_POST['id'] ?? 0);
    $dir = trim((string)($_POST['dir'] ?? ''));

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    if (!in_array($dir, ['up', 'down'], true)) {
        sb_json_error('INVALID_DIR', 422);
    }

    $currentUserId = sb_page_handler_current_user_id();
    $pages = sb_read_pages();

    $page = sb_page_handler_find_by_id($pages, $id);

    if (!$page) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $siteId = (int)($page['siteId'] ?? 0);
    $parentId = (int)($page['parentId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_NOT_FOUND', 422);
    }

    sb_page_handler_require_page_edit(
        $siteId,
        $id,
        $currentUserId
    );

    $hasGlobalEdit = sb_page_handler_has_global_edit(
        $siteId,
        $currentUserId
    );

    $siblings = [];

    foreach ($pages as $index => $sibling) {
        if (
            (int)($sibling['siteId'] ?? 0) === $siteId
            && (int)($sibling['parentId'] ?? 0) === $parentId
        ) {
            $siblings[] = [
                'index' => $index,
                'row' => $sibling,
            ];
        }
    }

    usort($siblings, static function ($a, $b) {
        $sortCompare =
            (int)($a['row']['sort'] ?? 500)
            <=>
            (int)($b['row']['sort'] ?? 500);

        if ($sortCompare !== 0) {
            return $sortCompare;
        }

        return
            (int)($a['row']['id'] ?? 0)
            <=>
            (int)($b['row']['id'] ?? 0);
    });

    $position = null;

    for ($i = 0; $i < count($siblings); $i++) {
        if (
            (int)($siblings[$i]['row']['id'] ?? 0) === $id
        ) {
            $position = $i;
            break;
        }
    }

    if ($position === null) {
        sb_json_error(
            'PAGE_NOT_FOUND_IN_SIBLINGS',
            404
        );
    }

    $swapPosition =
        $dir === 'up'
            ? $position - 1
            : $position + 1;

    if (!isset($siblings[$swapPosition])) {
        sb_json_ok([
            'moved' => false,
        ]);
    }

    $targetPageId = (int)(
        $siblings[$swapPosition]['row']['id'] ?? 0
    );

    /*
     * Меняется сортировка сразу двух страниц.
     * Поэтому нужны права и на соседнюю страницу.
     */
    if (
        !$hasGlobalEdit
        && !PageAccessService::canEditPage(
            $siteId,
            $targetPageId,
            $currentUserId
        )
    ) {
        sb_json_error(
            'TARGET_PAGE_EDIT_ACCESS_DENIED',
            403,
            [
                'pageId' => $targetPageId,
            ]
        );
    }

    $firstIndex = $siblings[$position]['index'];
    $secondIndex = $siblings[$swapPosition]['index'];

    $firstSort = (int)($pages[$firstIndex]['sort'] ?? 500);
    $secondSort = (int)($pages[$secondIndex]['sort'] ?? 500);

    $pages[$firstIndex]['sort'] = $secondSort;
    $pages[$secondIndex]['sort'] = $firstSort;

    $versionMap = RevisionService::decodeVersionMap(
        $_POST['expectedVersions'] ?? null
    );

    $firstId = (int)$pages[$firstIndex]['id'];
    $secondId = (int)$pages[$secondIndex]['id'];
    $firstExpected = RevisionService::requireVersionFromMap($versionMap, $firstId);
    $secondExpected = RevisionService::requireVersionFromMap($versionMap, $secondId);

    $firstSaved = RevisionService::savePage(
        $pages[$firstIndex],
        $firstExpected,
        $currentUserId,
        'reorder'
    );
    $secondSaved = RevisionService::savePage(
        $pages[$secondIndex],
        $secondExpected,
        $currentUserId,
        'reorder'
    );

    sb_json_ok([
        'moved' => true,
        'pages' => [$firstSaved, $secondSaved],
    ]);
}

/*
 * Drag-and-drop перемещение страницы в дереве.
 *
 * position:
 * - before — перед targetId;
 * - after  — после targetId;
 * - inside — последним дочерним элементом targetId.
 *
 * Операция атомарно меняет parentId и нормализует sort у затронутых
 * веток. Для каждой реально изменяемой страницы требуется актуальная
 * версия из expectedVersions.
 */
if ($action === 'page.reorderTree') {
    $id = (int)($_POST['id'] ?? 0);
    $targetId = (int)($_POST['targetId'] ?? 0);
    $position = trim((string)($_POST['position'] ?? ''));

    if ($id <= 0 || $targetId <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    if ($id === $targetId) {
        sb_json_error('PAGE_CANNOT_TARGET_ITSELF', 422);
    }

    if (!in_array($position, ['before', 'after', 'inside'], true)) {
        sb_json_error('INVALID_POSITION', 422);
    }

    $currentUserId = sb_page_handler_current_user_id();
    $pages = sb_read_pages();
    $source = sb_page_handler_find_by_id($pages, $id);
    $target = sb_page_handler_find_by_id($pages, $targetId);

    if (!$source || !$target) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $siteId = (int)($source['siteId'] ?? 0);
    if (
        $siteId <= 0
        || (int)($target['siteId'] ?? 0) !== $siteId
    ) {
        sb_json_error('PAGE_SITE_MISMATCH', 422);
    }

    sb_page_handler_require_page_edit($siteId, $id, $currentUserId);
    $hasGlobalEdit = sb_page_handler_has_global_edit($siteId, $currentUserId);

    $oldParentId = (int)($source['parentId'] ?? 0);
    $newParentId = $position === 'inside'
        ? $targetId
        : (int)($target['parentId'] ?? 0);

    if (
        $newParentId === $id
        || sb_page_handler_is_descendant($pages, $id, $newParentId)
    ) {
        sb_json_error('CYCLIC_PARENT_RELATION', 422);
    }

    if (!$hasGlobalEdit) {
        if ($newParentId <= 0) {
            sb_json_error('MOVE_PAGE_TO_ROOT_ACCESS_DENIED', 403);
        }

        if (!PageAccessService::canEditPage(
            $siteId,
            $newParentId,
            $currentUserId
        )) {
            sb_json_error('PARENT_PAGE_EDIT_ACCESS_DENIED', 403, [
                'parentId' => $newParentId,
            ]);
        }
    }

    $sortPages = static function (array $items): array {
        usort($items, static function (array $first, array $second): int {
            $sortCompare = (int)($first['sort'] ?? 500) <=> (int)($second['sort'] ?? 500);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }

            return (int)($first['id'] ?? 0) <=> (int)($second['id'] ?? 0);
        });

        return $items;
    };

    $siblingsFor = static function (array $allPages, int $currentSiteId, int $parentId, int $excludeId = 0) use ($sortPages): array {
        $items = array_values(array_filter(
            $allPages,
            static function (array $page) use ($currentSiteId, $parentId, $excludeId): bool {
                $pageId = (int)($page['id'] ?? 0);

                return (int)($page['siteId'] ?? 0) === $currentSiteId
                    && (int)($page['parentId'] ?? 0) === $parentId
                    && ($excludeId <= 0 || $pageId !== $excludeId);
            }
        ));

        return $sortPages($items);
    };

    if ($oldParentId === $newParentId) {
        $destination = $siblingsFor($pages, $siteId, $newParentId, $id);
    } else {
        $destination = $siblingsFor($pages, $siteId, $newParentId, $id);
    }

    if ($position === 'inside') {
        $insertAt = count($destination);
    } else {
        $insertAt = null;

        foreach ($destination as $index => $sibling) {
            if ((int)($sibling['id'] ?? 0) === $targetId) {
                $insertAt = $position === 'before' ? $index : $index + 1;
                break;
            }
        }

        if ($insertAt === null) {
            sb_json_error('TARGET_PAGE_NOT_FOUND_IN_SIBLINGS', 422);
        }
    }

    $movedSource = $source;
    $movedSource['parentId'] = $newParentId;
    array_splice($destination, $insertAt, 0, [$movedSource]);

    $desiredById = [];
    $applySiblingOrder = static function (array $siblings, int $parentId) use (&$desiredById): void {
        $sort = 10;
        foreach ($siblings as $sibling) {
            $sibling['parentId'] = $parentId;
            $sibling['sort'] = $sort;
            $desiredById[(int)$sibling['id']] = $sibling;
            $sort += 10;
        }
    };

    if ($oldParentId !== $newParentId) {
        $oldSiblings = $siblingsFor($pages, $siteId, $oldParentId, $id);
        $applySiblingOrder($oldSiblings, $oldParentId);
    }

    $applySiblingOrder($destination, $newParentId);

    $originalById = [];
    foreach ($pages as $page) {
        $originalById[(int)($page['id'] ?? 0)] = $page;
    }

    $changed = [];
    foreach ($desiredById as $pageId => $desired) {
        $original = $originalById[$pageId] ?? null;
        if (!$original) {
            continue;
        }

        if (
            (int)($original['parentId'] ?? 0) !== (int)($desired['parentId'] ?? 0)
            || (int)($original['sort'] ?? 0) !== (int)($desired['sort'] ?? 0)
        ) {
            $changed[$pageId] = $desired;
        }
    }

    if (empty($changed)) {
        sb_json_ok(['moved' => false]);
    }

    if (!$hasGlobalEdit) {
        foreach (array_keys($changed) as $changedPageId) {
            if (!PageAccessService::canEditPage(
                $siteId,
                (int)$changedPageId,
                $currentUserId
            )) {
                sb_json_error('TARGET_PAGE_EDIT_ACCESS_DENIED', 403, [
                    'pageId' => (int)$changedPageId,
                ]);
            }
        }
    }

    $versionMap = RevisionService::decodeVersionMap(
        $_POST['expectedVersions'] ?? null
    );
    $savedPages = [];

    foreach ($changed as $changedPageId => $desired) {
        $saved = RevisionService::savePage(
            $desired,
            RevisionService::requireVersionFromMap($versionMap, (int)$changedPageId),
            $currentUserId,
            'tree_reorder'
        );

        $savedPages[] = sb_page_handler_add_access_info(
            $saved,
            $siteId,
            $currentUserId
        );
    }

    sb_json_ok([
        'moved' => true,
        'page' => sb_page_handler_add_access_info(
            RevisionService::getPage($id, false) ?? $movedSource,
            $siteId,
            $currentUserId
        ),
        'pages' => $savedPages,
    ]);
}

/*
 * Удаление страницы и всех подстраниц.
 */
if ($action === 'page.delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    $currentUserId = sb_page_handler_current_user_id();
    $pages = sb_read_pages();

    $page = sb_page_handler_find_by_id($pages, $id);

    if (!$page) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $siteId = (int)($page['siteId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_NOT_FOUND', 422);
    }

    $idsToDelete = [
        $id => true,
    ];

    $changed = true;
    $safety = 0;

    while ($changed && $safety < 1000) {
        $changed = false;

        foreach ($pages as $childPage) {
            $childId = (int)($childPage['id'] ?? 0);
            $parentId = (int)($childPage['parentId'] ?? 0);

            if (
                $childId > 0
                && !isset($idsToDelete[$childId])
                && isset($idsToDelete[$parentId])
            ) {
                $idsToDelete[$childId] = true;
                $changed = true;
            }
        }

        $safety++;
    }

    $hasGlobalEdit = sb_page_handler_has_global_edit(
        $siteId,
        $currentUserId
    );

    /*
     * При удалении ветки проверяем права на каждую страницу,
     * потому что удалятся также все дочерние страницы.
     */
    if (!$hasGlobalEdit) {
        foreach (array_keys($idsToDelete) as $deletePageId) {
            if (
                !PageAccessService::canEditPage(
                    $siteId,
                    (int)$deletePageId,
                    $currentUserId
                )
            ) {
                sb_json_error(
                    'CHILD_PAGE_EDIT_ACCESS_DENIED',
                    403,
                    [
                        'pageId' => (int)$deletePageId,
                    ]
                );
            }
        }
    }

    $deletePageIds = array_values(array_map(
        'intval',
        array_keys($idsToDelete)
    ));

    $versionMap = RevisionService::decodeVersionMap(
        $_POST['expectedVersions'] ?? null
    );

    foreach ($deletePageIds as $deletePageId) {
        $deletePage = sb_page_handler_find_by_id($pages, $deletePageId);
        if (!$deletePage) {
            sb_json_error('PAGE_NOT_FOUND', 404, ['pageId' => $deletePageId]);
        }

        $expectedVersion = RevisionService::requireVersionFromMap(
            $versionMap,
            $deletePageId
        );
        $lockedPage = RevisionService::getPage($deletePageId, true);

        if (!$lockedPage) {
            sb_json_error('PAGE_NOT_FOUND', 404, ['pageId' => $deletePageId]);
        }

        RevisionService::assertExpected(
            $lockedPage,
            $expectedVersion,
            RevisionService::ENTITY_PAGE
        );
    }

    $placeholders = implode(',', array_fill(0, count($deletePageIds), '?'));
    $pdo = sb_db();

    $pageSnapshots = array_values(array_filter(
        $pages,
        static fn(array $candidate): bool => isset($idsToDelete[(int)($candidate['id'] ?? 0)])
    ));

    $blocksToDelete = array_values(array_filter(
        sb_read_blocks(),
        static fn(array $block): bool => isset($idsToDelete[(int)($block['pageId'] ?? 0)])
    ));

    $sectionsToDelete = PageSectionRepository::listForPageIds($deletePageIds);

    $accessStmt = $pdo->prepare("
        SELECT site_id,page_id,access_code,can_view,can_edit,can_disk_view,can_disk_edit,include_children,created_by,created_at,updated_at
        FROM sitebuilder.page_access
        WHERE site_id = ? AND page_id IN ($placeholders)
    ");
    $accessStmt->execute(array_merge([$siteId], $deletePageIds));
    $pageAccessToDelete = array_map(static function (array $row): array {
        $toBool = static function (mixed $value): bool {
            if (is_bool($value)) {
                return $value;
            }

            return in_array(
                strtolower(trim((string)$value)),
                ['1', 't', 'true', 'y', 'yes', 'on'],
                true
            );
        };

        return [
            'siteId' => (int)$row['site_id'],
            'pageId' => (int)$row['page_id'],
            'accessCode' => (string)$row['access_code'],
            'canView' => $toBool($row['can_view'] ?? false),
            'canEdit' => $toBool($row['can_edit'] ?? false),
            'canDiskView' => $toBool($row['can_disk_view'] ?? false),
            'canDiskEdit' => $toBool($row['can_disk_edit'] ?? false),
            'includeChildren' => $toBool($row['include_children'] ?? false),
            'createdBy' => !empty($row['created_by']) ? (int)$row['created_by'] : 0,
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }, $accessStmt->fetchAll(PDO::FETCH_ASSOC));

    $siteBeforeDelete = RevisionService::getSite($siteId, true);
    if (!$siteBeforeDelete) {
        sb_json_error('SITE_NOT_FOUND', 404);
    }
    $wasHomePage = isset($idsToDelete[(int)($siteBeforeDelete['homePageId'] ?? 0)]);

    /*
     * Убираем ссылки на удаляемые страницы из меню того же сайта.
     */
    $menus = sb_read_menus();
    $changedMenus = [];
    $removedMenuItems = [];

    foreach ($menus as &$menu) {
        if ((int)($menu['siteId'] ?? 0) !== $siteId) {
            continue;
        }

        $items = is_array($menu['items'] ?? null)
            ? $menu['items']
            : [];

        $removedItems = array_values(array_filter(
            $items,
            static function (array $item) use ($idsToDelete): bool {
                return (string)($item['type'] ?? '') === 'page'
                    && isset($idsToDelete[(int)($item['pageId'] ?? 0)]);
            }
        ));

        $filteredItems = array_values(array_filter(
            $items,
            static function (array $item) use ($idsToDelete): bool {
                if ((string)($item['type'] ?? '') !== 'page') {
                    return true;
                }

                return !isset(
                    $idsToDelete[(int)($item['pageId'] ?? 0)]
                );
            }
        ));

        if (count($filteredItems) === count($items)) {
            continue;
        }

        $removedMenuItems[] = [
            'menuId' => (int)$menu['id'],
            'menuName' => (string)($menu['name'] ?? ''),
            'items' => $removedItems,
        ];

        $menu['items'] = $filteredItems;
        $menu['updatedBy'] = $currentUserId;
        $menu['updatedAt'] = date('c');
        $changedMenus[] = $menu;
    }
    unset($menu);

    $recycleBinId = RecycleBinService::storePageTree(
        $siteId,
        $id,
        (string)($page['title'] ?? ('Страница #' . $id)),
        [
            'rootPageId' => $id,
            'pages' => $pageSnapshots,
            'blocks' => $blocksToDelete,
            'sections' => $sectionsToDelete,
            'pageAccess' => $pageAccessToDelete,
            'removedMenuItems' => $removedMenuItems,
            'wasHomePage' => $wasHomePage,
        ],
        $currentUserId
    );

    foreach ($changedMenus as $changedMenu) {
        RevisionService::saveMenu(
            $changedMenu,
            (int)($changedMenu['version'] ?? 1),
            $currentUserId,
            'page_reference_remove'
        );
    }

    if ($wasHomePage) {
        $siteBeforeDelete['homePageId'] = 0;
        $siteBeforeDelete = RevisionService::saveSite(
            $siteBeforeDelete,
            (int)$siteBeforeDelete['version'],
            $currentUserId,
            'home_page_removed'
        );
    }

    $stmt = $pdo->prepare("
        DELETE FROM sitebuilder.page_access
        WHERE site_id = ?
          AND page_id IN ($placeholders)
    ");
    $stmt->execute(array_merge(
        [$siteId],
        $deletePageIds
    ));

    foreach ($blocksToDelete as $blockToDelete) {
        RevisionService::recordDeletedBlock($blockToDelete, $currentUserId);
    }
    foreach ($deletePageIds as $deletePageId) {
        $deletePage = sb_page_handler_find_by_id($pages, $deletePageId);
        if ($deletePage) {
            RevisionService::recordDeletedPage($deletePage, $currentUserId);
        }
    }

    /* Секции теперь находятся в PostgreSQL и удаляются в той же транзакции. */
    $deletedSectionCount = PageSectionRepository::deleteByPageIds($deletePageIds);

    $stmt = $pdo->prepare("
        DELETE FROM sitebuilder.block
        WHERE page_id IN ($placeholders)
    ");
    $stmt->execute($deletePageIds);

    $stmt = $pdo->prepare("
        DELETE FROM sitebuilder.page
        WHERE site_id = ?
          AND id IN ($placeholders)
    ");
    $stmt->execute(array_merge(
        [$siteId],
        $deletePageIds
    ));

    if ($stmt->rowCount() !== count($deletePageIds)) {
        throw new RuntimeException('PAGE_TREE_DELETE_COUNT_MISMATCH');
    }

    sb_json_ok([
        'deleted' => true,
        'id' => $id,
        'siteId' => $siteId,
        'pageId' => $id,
        'deletedPageIds' => array_map(
            'intval',
            array_keys($idsToDelete)
        ),
        'recycleBinId' => $recycleBinId,
        'deletedSectionCount' => $deletedSectionCount,
        'siteVersion' => (int)($siteBeforeDelete['version'] ?? 1),
    ]);
}

/*
 * Копирование страницы и её блоков.
 */
if ($action === 'page.duplicate') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        sb_json_error('PAGE_ID_REQUIRED', 422);
    }

    $currentUserId = sb_page_handler_current_user_id();
    $pages = sb_read_pages();

    $source = sb_page_handler_find_by_id($pages, $id);

    if (!$source) {
        sb_json_error('PAGE_NOT_FOUND', 404);
    }

    $siteId = (int)($source['siteId'] ?? 0);

    if ($siteId <= 0) {
        sb_json_error('SITE_ID_NOT_FOUND', 422);
    }

    sb_page_handler_require_page_edit(
        $siteId,
        $id,
        $currentUserId
    );

    $hasGlobalEdit = sb_page_handler_has_global_edit(
        $siteId,
        $currentUserId
    );

    $sourceParentId = (int)($source['parentId'] ?? 0);

    /*
    * Дублирование создаёт новую страницу рядом с исходной.
    * Поэтому применяем те же ограничения, что и при page.create.
    */
    if (!$hasGlobalEdit) {
        if ($sourceParentId <= 0) {
            sb_json_error(
                'ROOT_PAGE_CREATE_ACCESS_DENIED',
                403
            );
        }

        if (
            !PageAccessService::canEditPage(
                $siteId,
                $sourceParentId,
                $currentUserId
            )
        ) {
            sb_json_error(
                'PARENT_PAGE_EDIT_ACCESS_DENIED',
                403,
                [
                    'parentId' => $sourceParentId,
                ]
            );
        }
    }

    $newId = RevisionService::nextEntityId(RevisionService::ENTITY_PAGE);
    $maxSort = 0;

    foreach ($pages as $page) {
        if (
            (int)($page['siteId'] ?? 0) === $siteId
            && (int)($page['parentId'] ?? 0)
                === (int)($source['parentId'] ?? 0)
        ) {
            $maxSort = max(
                $maxSort,
                (int)($page['sort'] ?? 0)
            );
        }
    }

    $copy = sb_normalize_page_record([
        'id' => $newId,
        'siteId' => $siteId,
        'title' => (string)($source['title'] ?? '')
            . ' (копия)',
        'slug' => sb_slugify(
            (string)($source['slug'] ?? 'page')
            . '-'
            . $newId
        ),
        'parentId' => $sourceParentId,
        'sort' => $maxSort > 0
            ? $maxSort + 10
            : (int)($source['sort'] ?? 10) + 10,
        'status' => 'draft',
        'publishedAt' => null,
        'seo' => [],
        'createdBy' => $currentUserId,
        'createdAt' => date('c'),
        'updatedBy' => $currentUserId,
        'updatedAt' => date('c'),
    ]);

    $pages[] = $copy;

    sb_write_pages([$copy]);

    $blocks = sb_read_blocks();

    $sourceBlocks = array_values(array_filter(
        $blocks,
        static function ($block) use ($id) {
            return (int)($block['pageId'] ?? 0) === $id;
        }
    ));
    $newBlocks = [];
    $reservedBlockIds = !empty($sourceBlocks)
        ? RevisionService::reserveEntityIds(RevisionService::ENTITY_BLOCK, count($sourceBlocks))
        : [];

    foreach ($sourceBlocks as $sourceBlockIndex => $sourceBlock) {
        $newBlockId = (int)$reservedBlockIds[$sourceBlockIndex];

        $newBlock = sb_normalize_block_record([
            'id' => $newBlockId,
            'pageId' => $newId,
            'type' => (string)($sourceBlock['type'] ?? 'text'),
            'sort' => (int)($sourceBlock['sort'] ?? 500),
            'content' => is_array(
                $sourceBlock['content'] ?? null
            )
                ? $sourceBlock['content']
                : [],
            'props' => is_array(
                $sourceBlock['props'] ?? null
            )
                ? $sourceBlock['props']
                : [],
            'createdBy' => $currentUserId,
            'createdAt' => date('c'),
            'updatedBy' => $currentUserId,
            'updatedAt' => date('c'),
            'version' => 1,
        ]);

        $blocks[] = $newBlock;
        $newBlocks[] = $newBlock;
    }

    sb_write_blocks($newBlocks);

    /*
     * Пользователю с доступом только к отдельной странице
     * выдаём прямое право на созданную копию.
     */
    if (!$hasGlobalEdit) {
        sb_page_handler_grant_creator_access(
            $siteId,
            $newId,
            $currentUserId
        );
    }

    $copy = sb_page_handler_add_access_info(
        $copy,
        $siteId,
        $currentUserId
    );

    sb_json_ok([
        'page' => $copy,
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'page',
    'action' => $action,
]);