<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/PageAccessRepository.php';

class PageAccessService
{
    /**
     * Проверка просмотра страницы.
     *
     * Доступ дают:
     * - администратор Битрикс;
     * - глобальная роль VIEWER и выше;
     * - точечное право can_view/can_edit.
     */
    public static function canViewPage(
        int $siteId,
        int $pageId,
        int $userId
    ): bool {
        if (
            $siteId <= 0
            || $pageId <= 0
            || $userId <= 0
        ) {
            return false;
        }

        if (self::isBitrixAdmin()) {
            return true;
        }

        if (
            self::hasGlobalSiteAccess(
                $siteId,
                $userId,
                'view'
            )
        ) {
            return true;
        }

        $accessCode =
            PageAccessRepository::userAccessCode($userId);

        return PageAccessRepository::hasPagePermission(
            $siteId,
            $pageId,
            $accessCode,
            'view'
        );
    }

    /**
     * Проверка редактирования страницы и её обычных блоков.
     *
     * Доступ дают:
     * - администратор Битрикс;
     * - глобальная роль ADMIN и выше;
     * - точечное право can_edit.
     */
    public static function canEditPage(
        int $siteId,
        int $pageId,
        int $userId
    ): bool {
        if (
            $siteId <= 0
            || $pageId <= 0
            || $userId <= 0
        ) {
            return false;
        }

        if (self::isBitrixAdmin()) {
            return true;
        }

        if (
            self::hasGlobalSiteAccess(
                $siteId,
                $userId,
                'edit'
            )
        ) {
            return true;
        }

        $accessCode =
            PageAccessRepository::userAccessCode($userId);

        return PageAccessRepository::hasPagePermission(
            $siteId,
            $pageId,
            $accessCode,
            'edit'
        );
    }

    /**
     * Проверка просмотра Диска на конкретной странице.
     *
     * Доступ дают:
     * - администратор Битрикс;
     * - глобальная роль VIEWER и выше;
     * - точечное право can_disk_view/can_disk_edit.
     */
    public static function canViewDisk(
        int $siteId,
        int $pageId,
        int $userId
    ): bool {
        if (
            $siteId <= 0
            || $pageId <= 0
            || $userId <= 0
        ) {
            return false;
        }

        if (self::isBitrixAdmin()) {
            return true;
        }

        if (
            self::hasGlobalSiteAccess(
                $siteId,
                $userId,
                'disk_view'
            )
        ) {
            return true;
        }

        $accessCode =
            PageAccessRepository::userAccessCode($userId);

        return PageAccessRepository::hasPagePermission(
            $siteId,
            $pageId,
            $accessCode,
            'disk_view'
        );
    }

    /**
     * Проверка изменения Диска на конкретной странице.
     *
     * Доступ дают:
     * - администратор Битрикс;
     * - глобальная роль EDITOR и выше;
     * - точечное право can_disk_edit.
     */
    public static function canEditDisk(
        int $siteId,
        int $pageId,
        int $userId
    ): bool {
        if (
            $siteId <= 0
            || $pageId <= 0
            || $userId <= 0
        ) {
            return false;
        }

        if (self::isBitrixAdmin()) {
            return true;
        }

        if (
            self::hasGlobalSiteAccess(
                $siteId,
                $userId,
                'disk_edit'
            )
        ) {
            return true;
        }

        $accessCode =
            PageAccessRepository::userAccessCode($userId);

        return PageAccessRepository::hasPagePermission(
            $siteId,
            $pageId,
            $accessCode,
            'disk_edit'
        );
    }

    /**
     * Проверяет наличие хотя бы одного точечного разрешения:
     *
     * - страница: просмотр;
     * - страница: изменение;
     * - Диск: просмотр;
     * - Диск: изменение.
     */
    public static function hasAnyPageAccess(
        int $siteId,
        int $userId
    ): bool {
        if ($siteId <= 0 || $userId <= 0) {
            return false;
        }

        if (self::isBitrixAdmin()) {
            return true;
        }

        $accessCode =
            PageAccessRepository::userAccessCode($userId);

        return PageAccessRepository::hasAnyPageAccess(
            $siteId,
            $accessCode
        );
    }

    /**
     * Полная информация о правах пользователя
     * на конкретной странице.
     */
    public static function getPageAccessInfo(
        int $siteId,
        int $pageId,
        int $userId
    ): array {
        return [
            'canView' => self::canViewPage(
                $siteId,
                $pageId,
                $userId
            ),
            'canEdit' => self::canEditPage(
                $siteId,
                $pageId,
                $userId
            ),
            'canDiskView' => self::canViewDisk(
                $siteId,
                $pageId,
                $userId
            ),
            'canDiskEdit' => self::canEditDisk(
                $siteId,
                $pageId,
                $userId
            ),
        ];
    }

    /**
     * Оставляет только страницы, которые пользователь
     * имеет право просматривать.
     *
     * Одно лишь право Диска не делает страницу видимой.
     * Для отображения страницы нужно can_view/can_edit
     * либо глобальная роль VIEWER и выше.
     */
    public static function filterVisiblePages(
        array $pages,
        int $siteId,
        int $userId
    ): array {
        $filtered = [];

        foreach ($pages as $page) {
            $pageId = (int)(
                $page['id']
                ?? $page['ID']
                ?? 0
            );

            if ($pageId <= 0) {
                continue;
            }

            if (
                !self::canViewPage(
                    $siteId,
                    $pageId,
                    $userId
                )
            ) {
                continue;
            }

            $page['access'] =
                self::getPageAccessInfo(
                    $siteId,
                    $pageId,
                    $userId
                );

            $filtered[] = $page;
        }

        return $filtered;
    }

    /**
     * Проверка глобального права сайта.
     *
     * Матрица:
     *
     * VIEWER = просмотр страницы и Диска.
     * EDITOR = просмотр страницы и изменение Диска.
     * ADMIN  = изменение страниц, Диска и управление.
     * OWNER  = полный контроль.
     */
    public static function hasGlobalSiteAccess(
        int $siteId,
        int $userId,
        string $permission
    ): bool {
        if ($siteId <= 0 || $userId <= 0) {
            return false;
        }

        if (self::isBitrixAdmin()) {
            return true;
        }

        $role = self::getGlobalSiteRole(
            $siteId,
            $userId
        );

        if (
            $permission === 'view'
            || $permission === 'disk_view'
        ) {
            return $role >= 1;
        }

        if ($permission === 'disk_edit') {
            return $role >= 2;
        }

        if (
            $permission === 'edit'
            || $permission === 'admin'
            || $permission === 'manage_access'
        ) {
            return $role >= 3;
        }

        if ($permission === 'owner') {
            return $role >= 4;
        }

        return false;
    }

    /**
     * Получает глобальную роль пользователя.
     *
     * Сначала используется общий механизм access.php,
     * если он уже подключён. Он учитывает таблицу прав
     * и резервную роль группы Битрикс24.
     *
     * Если access.php недоступен, выполняется прямой
     * запрос в sitebuilder.access.
     */
    private static function getGlobalSiteRole(
        int $siteId,
        int $userId
    ): int {
        static $cache = [];

        if ($siteId <= 0 || $userId <= 0) {
            return 0;
        }

        $key = $siteId . ':' . $userId;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $accessCode =
            PageAccessRepository::userAccessCode($userId);

        /*
         * Основной механизм проекта.
         */
        if (function_exists('sb_get_role')) {
            try {
                $role = sb_get_role(
                    $siteId,
                    $accessCode
                );

                if (function_exists('sb_role_rank')) {
                    $cache[$key] = (int)sb_role_rank(
                        $role
                    );

                    return $cache[$key];
                }

                $cache[$key] =
                    self::normalizeRoleRank($role);

                return $cache[$key];
            } catch (Throwable $e) {
                /*
                 * При ошибке используем прямой запрос ниже.
                 */
            }
        }

        try {
            $pdo = sb_db();

            $stmt = $pdo->prepare("
                SELECT role
                FROM sitebuilder.access
                WHERE site_id = :site_id
                  AND access_code = :access_code
                LIMIT 1
            ");

            $stmt->execute([
                ':site_id' => $siteId,
                ':access_code' => $accessCode,
            ]);

            $row = $stmt->fetch(
                PDO::FETCH_ASSOC
            );
        } catch (Throwable $e) {
            $cache[$key] = 0;

            return 0;
        }

        if (!$row) {
            $cache[$key] = 0;

            return 0;
        }

        $cache[$key] =
            self::normalizeRoleRank(
                $row['role'] ?? 0
            );

        return $cache[$key];
    }

    private static function normalizeRoleRank(
        $role
    ): int {
        if (is_numeric($role)) {
            $rank = (int)$role;

            return max(0, min(4, $rank));
        }

        $roleString = mb_strtoupper(
            trim((string)$role)
        );

        $map = [
            'VIEWER' => 1,
            'EDITOR' => 2,
            'ADMIN' => 3,
            'OWNER' => 4,
        ];

        return $map[$roleString] ?? 0;
    }

    private static function isBitrixAdmin(): bool
    {
        global $USER;

        return is_object($USER)
            && method_exists($USER, 'IsAdmin')
            && $USER->IsAdmin();
    }
}