<?php

require_once $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/PageAccessRepository.php';

require_once $_SERVER['DOCUMENT_ROOT']
    . '/local/sitebuilder/lib/PageAccessService.php';

global $USER;

/*
 * Локальные функции обработчика прав страниц.
 */

if (!function_exists('sb_page_access_json_success')) {
    function sb_page_access_json_success(
        array $data = []
    ): void {
        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            [
                'ok' => true,
                'data' => $data,
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}

if (!function_exists('sb_page_access_json_error')) {
    function sb_page_access_json_error(
        string $message,
        int $status = 400,
        array $details = []
    ): void {
        if (function_exists('sb_json_error')) {
            sb_json_error(
                $message,
                $status,
                $details
            );

            exit;
        }

        http_response_code($status);

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            [
                'ok' => false,
                'error' => $message,
                'message' => $message,
                'details' => $details,
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}

if (!function_exists('sb_page_access_error_status')) {
    function sb_page_access_error_status(
        string $error
    ): int {
        switch ($error) {
            case 'AUTH_REQUIRED':
                return 401;

            case 'BAD_SESSID':
            case 'PAGE_ACCESS_DENIED':
                return 403;

            case 'PAGE_NOT_IN_SITE':
            case 'PAGE_ACCESS_NOT_FOUND':
                return 404;

            case 'INVALID_SITE_ID':
            case 'INVALID_PAGE_ID':
            case 'INVALID_PAGE_ACCESS_ID':
            case 'INVALID_USER_ID':
            case 'EMPTY_ACCESS_CODE':
            case 'INVALID_ACCESS_CODE':
            case 'EMPTY_PAGE_PERMISSION':
                return 422;

            case 'UNKNOWN_PAGE_ACCESS_ACTION':
                return 400;

            default:
                return 400;
        }
    }
}

if (!function_exists('sb_page_access_bool')) {
    function sb_page_access_bool($value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'true'
            || $value === 'TRUE'
            || $value === 'Y'
            || $value === 'y'
            || $value === 'on';
    }
}

if (!function_exists(
    'sb_page_access_current_user_id'
)) {
    function sb_page_access_current_user_id(): int
    {
        global $USER;

        if (
            !is_object($USER)
            || !method_exists(
                $USER,
                'IsAuthorized'
            )
            || !$USER->IsAuthorized()
        ) {
            throw new RuntimeException(
                'AUTH_REQUIRED'
            );
        }

        $userId = (int)$USER->GetID();

        if ($userId <= 0) {
            throw new RuntimeException(
                'AUTH_REQUIRED'
            );
        }

        return $userId;
    }
}

/**
 * Управлять правами конкретной страницы могут:
 *
 * 1. Администратор Битрикс24.
 * 2. Глобальный ADMIN или OWNER сайта.
 * 3. Пользователь с правом редактирования страницы.
 *
 * Наличие только can_disk_edit не позволяет
 * самостоятельно выдавать права другим пользователям.
 */
/**
 * Управлять правами конкретной страницы могут:
 *
 * 1. Администратор Битрикс24.
 * 2. Глобальный ADMIN сайта.
 * 3. Глобальный OWNER сайта.
 *
 * EDITOR, page.edit и can_disk_edit
 * не позволяют выдавать права другим пользователям.
 */
if (!function_exists('sb_page_access_can_manage')) {
    function sb_page_access_can_manage(
        int $siteId,
        int $pageId,
        int $userId
    ): bool {
        global $USER;

        if (
            $siteId <= 0
            || $pageId <= 0
            || $userId <= 0
        ) {
            return false;
        }

        /*
         * Страница обязательно должна относиться
         * к указанному сайту.
         */
        if (
            !PageAccessRepository::pageBelongsToSite(
                $siteId,
                $pageId
            )
        ) {
            return false;
        }

        /*
         * Администратор самого Битрикс24.
         */
        if (
            is_object($USER)
            && method_exists($USER, 'IsAdmin')
            && $USER->IsAdmin()
        ) {
            return true;
        }

        /*
         * Глобальные роли ADMIN и OWNER.
         */
        return PageAccessService::hasGlobalSiteAccess(
            $siteId,
            $userId,
            'admin'
        );
    }
}


try {
    $action = trim(
        (string)($_POST['action'] ?? '')
    );

    $currentUserId =
        sb_page_access_current_user_id();

    /*
     * Получение списка прав страницы.
     */
    if ($action === 'pageAccess.list') {
        $siteId = (int)(
            $_POST['siteId'] ?? 0
        );

        $pageId = (int)(
            $_POST['pageId'] ?? 0
        );

        if ($siteId <= 0) {
            throw new RuntimeException(
                'INVALID_SITE_ID'
            );
        }

        if ($pageId <= 0) {
            throw new RuntimeException(
                'INVALID_PAGE_ID'
            );
        }

        PageAccessRepository::requirePageInSite(
            $siteId,
            $pageId
        );

        if (
            !sb_page_access_can_manage(
                $siteId,
                $pageId,
                $currentUserId
            )
        ) {
            throw new RuntimeException(
                'PAGE_ACCESS_DENIED'
            );
        }

        $items =
            PageAccessRepository::listByPage(
                $siteId,
                $pageId
            );

        sb_page_access_json_success([
            'items' => $items,
        ]);
    }

    /*
     * Создание или обновление прав.
     */
    if ($action === 'pageAccess.save') {
        /*
         * В bootstrap уже должна выполняться
         * проверка sessid. Здесь оставлена
         * дополнительная защита.
         */
        if (!check_bitrix_sessid()) {
            throw new RuntimeException(
                'BAD_SESSID'
            );
        }

        $siteId = (int)(
            $_POST['siteId'] ?? 0
        );

        $pageId = (int)(
            $_POST['pageId'] ?? 0
        );

        $accessCode = trim(
            (string)(
                $_POST['accessCode'] ?? ''
            )
        );

        $canView = sb_page_access_bool(
            $_POST['canView'] ?? false
        );

        $canEdit = sb_page_access_bool(
            $_POST['canEdit'] ?? false
        );

        $canDiskView = sb_page_access_bool(
            $_POST['canDiskView'] ?? false
        );

        $canDiskEdit = sb_page_access_bool(
            $_POST['canDiskEdit'] ?? false
        );

        $includeChildren =
            sb_page_access_bool(
                $_POST['includeChildren']
                ?? false
            );

        if ($siteId <= 0) {
            throw new RuntimeException(
                'INVALID_SITE_ID'
            );
        }

        if ($pageId <= 0) {
            throw new RuntimeException(
                'INVALID_PAGE_ID'
            );
        }

        if ($accessCode === '') {
            throw new RuntimeException(
                'EMPTY_ACCESS_CODE'
            );
        }

        PageAccessRepository::requirePageInSite(
            $siteId,
            $pageId
        );

        /*
         * Проверяем формат до сохранения.
         */
        $accessCode =
            PageAccessRepository::normalizeAccessCode(
                $accessCode
            );

        /*
         * Редактирование страницы включает просмотр.
         */
        if ($canEdit) {
            $canView = true;
        }

        /*
         * Изменение Диска включает просмотр Диска.
         */
        if ($canDiskEdit) {
            $canDiskView = true;
        }

        /*
         * Допускается запись только с правами Диска,
         * даже если сама страница недоступна для просмотра.
         */
        if (
            !$canView
            && !$canEdit
            && !$canDiskView
            && !$canDiskEdit
        ) {
            throw new RuntimeException(
                'EMPTY_PAGE_PERMISSION'
            );
        }

        if (
            !sb_page_access_can_manage(
                $siteId,
                $pageId,
                $currentUserId
            )
        ) {
            throw new RuntimeException(
                'PAGE_ACCESS_DENIED'
            );
        }

        $item = PageAccessRepository::save(
            $siteId,
            $pageId,
            $accessCode,
            $canView,
            $canEdit,
            $includeChildren,
            $currentUserId,
            $canDiskView,
            $canDiskEdit
        );

        sb_page_access_json_success([
            'item' => $item,
        ]);
    }

    /*
     * Удаление записи прав.
     */
    if ($action === 'pageAccess.delete') {
        if (!check_bitrix_sessid()) {
            throw new RuntimeException(
                'BAD_SESSID'
            );
        }

        $id = (int)(
            $_POST['id'] ?? 0
        );

        $siteId = (int)(
            $_POST['siteId'] ?? 0
        );

        $pageId = (int)(
            $_POST['pageId'] ?? 0
        );

        if ($id <= 0) {
            throw new RuntimeException(
                'INVALID_PAGE_ACCESS_ID'
            );
        }

        if ($siteId <= 0) {
            throw new RuntimeException(
                'INVALID_SITE_ID'
            );
        }

        if ($pageId <= 0) {
            throw new RuntimeException(
                'INVALID_PAGE_ID'
            );
        }

        PageAccessRepository::requirePageInSite(
            $siteId,
            $pageId
        );

        if (
            !sb_page_access_can_manage(
                $siteId,
                $pageId,
                $currentUserId
            )
        ) {
            throw new RuntimeException(
                'PAGE_ACCESS_DENIED'
            );
        }

        $deleted =
            PageAccessRepository::delete(
                $id,
                $siteId,
                $pageId
            );

        if (!$deleted) {
            throw new RuntimeException(
                'PAGE_ACCESS_NOT_FOUND'
            );
        }

        sb_page_access_json_success([
            'deleted' => true,
            'id' => $id,
        ]);
    }

    throw new RuntimeException(
        'UNKNOWN_PAGE_ACCESS_ACTION'
    );
} catch (Throwable $e) {
    $error = trim($e->getMessage());

    if ($error === '') {
        $error = 'PAGE_ACCESS_ERROR';
    }

    /*
     * Не возвращаем пользователю путь к PHP-файлу
     * и номер строки.
     */
    sb_page_access_json_error(
        $error,
        sb_page_access_error_status($error),
        [
            'action' => (string)(
                $_POST['action'] ?? ''
            ),
        ]
    );
}
