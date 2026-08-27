<?php

declare(strict_types=1);

/**
 * Собирает именно пользователей, которые могут открыть выбранную страницу.
 *
 * Источники доступа:
 * - прямые глобальные роли sitebuilder.access;
 * - прямые и унаследованные правила sitebuilder.page_access;
 * - участники рабочей группы Битрикс24, связанной с сайтом;
 * - администраторы Битрикс24.
 *
 * Групповые access_code разворачиваются в пользователей, потому что ACL
 * папки Диска настраивается в интерфейсе построчно для каждого человека.
 */
class DiskPageUserRepository
{
    private const MAX_USERS = 1000;

    public static function listUsersWithPageAccess(
        int $siteId,
        int $pageId
    ): array {
        if ($siteId <= 0) {
            throw new RuntimeException('INVALID_SITE_ID');
        }

        if ($pageId <= 0) {
            throw new RuntimeException('INVALID_PAGE_ID');
        }

        PageAccessRepository::requirePageInSite($siteId, $pageId);

        $userIds = [];
        $groupIds = [];

        self::collectGlobalAccessCodes(
            $siteId,
            $userIds,
            $groupIds
        );

        self::collectPageAccessCodes(
            $siteId,
            $pageId,
            $userIds,
            $groupIds
        );

        foreach (array_keys($groupIds) as $groupId) {
            self::collectMainGroupUsers((int)$groupId, $userIds);
        }

        self::collectSiteWorkgroupUsers($siteId, $userIds);

        /* Администраторы имеют неявный доступ ко всем страницам. */
        $adminUserIds = [];
        self::collectMainGroupUsers(1, $adminUserIds);
        foreach (array_keys($adminUserIds) as $adminUserId) {
            $userIds[(int)$adminUserId] = true;
        }

        $currentUserId = DiskCurrentUser::getId();
        if ($currentUserId > 0) {
            $userIds[$currentUserId] = true;
        }

        $ids = array_map('intval', array_keys($userIds));
        $ids = array_values(array_filter($ids, static function (int $id): bool {
            return $id > 0;
        }));
        sort($ids, SORT_NUMERIC);

        /*
         * PageAccessService проверяет администратора через текущего $USER.
         * При построении матрицы администратором это сделало бы администраторами
         * все строки. Поэтому права каждого кандидата вычисляются явно.
         */
        $visibleIds = [];
        $pageAccessByUser = [];

        foreach ($ids as $userId) {
            $pageAccess = self::buildPageAccessInfo(
                $siteId,
                $pageId,
                $userId,
                isset($adminUserIds[$userId])
            );

            if (!$pageAccess['canView']) {
                continue;
            }

            $visibleIds[] = $userId;
            $pageAccessByUser[$userId] = $pageAccess;
        }

        if (count($visibleIds) > self::MAX_USERS) {
            throw new RuntimeException('TOO_MANY_PAGE_USERS');
        }

        $profiles = self::loadUserProfiles($visibleIds);
        $result = [];

        foreach ($visibleIds as $userId) {
            $profile = $profiles[$userId] ?? null;
            if ($profile === null || empty($profile['active'])) {
                continue;
            }

            $profile['globalRole'] = self::resolveGlobalRole(
                $siteId,
                $userId
            );
            $profile['pageAccess'] = $pageAccessByUser[$userId];
            $profile['isBitrixAdmin'] = isset($adminUserIds[$userId]);

            $result[] = $profile;
        }

        usort($result, static function (array $left, array $right): int {
            $nameCompare = strnatcasecmp(
                (string)($left['name'] ?? ''),
                (string)($right['name'] ?? '')
            );

            if ($nameCompare !== 0) {
                return $nameCompare;
            }

            return (int)($left['userId'] ?? 0)
                <=> (int)($right['userId'] ?? 0);
        });

        return $result;
    }

    private static function collectGlobalAccessCodes(
        int $siteId,
        array &$userIds,
        array &$groupIds
    ): void {
        $rows = DiskDb::fetchAll("
            SELECT access_code
            FROM sitebuilder.access
            WHERE site_id = :site_id
        ", [
            ':site_id' => $siteId,
        ]);

        foreach ($rows as $row) {
            self::collectAccessCode(
                (string)($row['access_code'] ?? ''),
                $userIds,
                $groupIds
            );
        }
    }

    private static function collectPageAccessCodes(
        int $siteId,
        int $pageId,
        array &$userIds,
        array &$groupIds
    ): void {
        $pageIds = PageAccessRepository::getPageAndParentIds(
            $siteId,
            $pageId
        );

        if (empty($pageIds)) {
            return;
        }

        $placeholders = [];
        $params = [
            ':site_id' => $siteId,
        ];

        foreach ($pageIds as $index => $candidatePageId) {
            $placeholder = ':page_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (int)$candidatePageId;
        }

        $rows = DiskDb::fetchAll("
            SELECT
                page_id,
                access_code,
                can_view,
                can_edit,
                include_children
            FROM sitebuilder.page_access
            WHERE site_id = :site_id
              AND page_id IN (" . implode(', ', $placeholders) . ")
              AND (can_view = TRUE OR can_edit = TRUE)
        ", $params);

        foreach ($rows as $row) {
            $rulePageId = (int)($row['page_id'] ?? 0);
            $isDirect = $rulePageId === $pageId;
            $includeChildren = self::boolValue(
                $row['include_children'] ?? false
            );

            if (!$isDirect && !$includeChildren) {
                continue;
            }

            self::collectAccessCode(
                (string)($row['access_code'] ?? ''),
                $userIds,
                $groupIds
            );
        }
    }

    private static function collectAccessCode(
        string $accessCode,
        array &$userIds,
        array &$groupIds
    ): void {
        $accessCode = mb_strtoupper(trim($accessCode));

        if (preg_match('/^U([1-9]\d*)$/', $accessCode, $matches)) {
            $userIds[(int)$matches[1]] = true;
            return;
        }

        if (preg_match('/^G([1-9]\d*)$/', $accessCode, $matches)) {
            $groupIds[(int)$matches[1]] = true;
        }
    }

    private static function collectMainGroupUsers(
        int $groupId,
        array &$userIds
    ): void {
        if ($groupId <= 0 || !class_exists('CUser')) {
            return;
        }

        $by = 'id';
        $order = 'asc';
        $result = \CUser::GetList(
            $by,
            $order,
            [
                'ACTIVE' => 'Y',
                'GROUPS_ID' => $groupId,
            ],
            [
                'FIELDS' => ['ID'],
            ]
        );

        while ($row = $result->Fetch()) {
            $userId = (int)($row['ID'] ?? 0);
            if ($userId > 0) {
                $userIds[$userId] = true;
            }
        }
    }

    private static function collectSiteWorkgroupUsers(
        int $siteId,
        array &$userIds
    ): void {
        if (!function_exists('sb_get_site_bitrix_group_id_for_access')) {
            return;
        }

        $workgroupId = (int)sb_get_site_bitrix_group_id_for_access($siteId);

        if (
            $workgroupId <= 0
            || !\Bitrix\Main\Loader::includeModule('socialnetwork')
            || !class_exists('CSocNetUserToGroup')
        ) {
            return;
        }

        $result = \CSocNetUserToGroup::GetList(
            ['ID' => 'ASC'],
            [
                'GROUP_ID' => $workgroupId,
            ],
            false,
            false,
            [
                'USER_ID',
                'ROLE',
            ]
        );

        $allowedRoles = [
            defined('SONET_ROLES_OWNER') ? SONET_ROLES_OWNER : 'A',
            defined('SONET_ROLES_MODERATOR') ? SONET_ROLES_MODERATOR : 'E',
            defined('SONET_ROLES_USER') ? SONET_ROLES_USER : 'K',
        ];

        while ($row = $result->Fetch()) {
            if (!in_array((string)($row['ROLE'] ?? ''), $allowedRoles, true)) {
                continue;
            }

            $userId = (int)($row['USER_ID'] ?? 0);
            if ($userId > 0) {
                $userIds[$userId] = true;
            }
        }
    }

    private static function loadUserProfiles(array $userIds): array
    {
        if (empty($userIds) || !class_exists('\Bitrix\Main\UserTable')) {
            return [];
        }

        $result = \Bitrix\Main\UserTable::getList([
            'select' => [
                'ID',
                'ACTIVE',
                'LOGIN',
                'NAME',
                'LAST_NAME',
                'SECOND_NAME',
                'EMAIL',
                'PERSONAL_PHOTO',
            ],
            'filter' => [
                '@ID' => $userIds,
            ],
        ]);

        $profiles = [];

        while ($row = $result->fetch()) {
            $userId = (int)($row['ID'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $name = trim(implode(' ', array_filter([
                trim((string)($row['LAST_NAME'] ?? '')),
                trim((string)($row['NAME'] ?? '')),
                trim((string)($row['SECOND_NAME'] ?? '')),
            ], static function (string $part): bool {
                return $part !== '';
            })));

            if ($name === '') {
                $name = trim((string)($row['LOGIN'] ?? ''));
            }

            if ($name === '') {
                $name = 'Пользователь #' . $userId;
            }

            $avatarUrl = '';
            $photoId = (int)($row['PERSONAL_PHOTO'] ?? 0);

            if ($photoId > 0 && class_exists('CFile')) {
                $avatarUrl = (string)\CFile::GetPath($photoId);
            }

            $profiles[$userId] = [
                'userId' => $userId,
                'name' => $name,
                'login' => (string)($row['LOGIN'] ?? ''),
                'email' => (string)($row['EMAIL'] ?? ''),
                'avatarUrl' => $avatarUrl,
                'active' => (string)($row['ACTIVE'] ?? 'N') === 'Y',
            ];
        }

        return $profiles;
    }

    private static function resolveGlobalRole(
        int $siteId,
        int $userId
    ): string {
        if (!function_exists('sb_get_role')) {
            return '';
        }

        try {
            return (string)(sb_get_role($siteId, 'U' . $userId) ?? '');
        } catch (Throwable $exception) {
            return '';
        }
    }

    private static function buildPageAccessInfo(
        int $siteId,
        int $pageId,
        int $userId,
        bool $isBitrixAdmin
    ): array {
        if ($isBitrixAdmin) {
            return [
                'canView' => true,
                'canEdit' => true,
                'canDiskView' => true,
                'canDiskEdit' => true,
            ];
        }

        $role = mb_strtoupper(self::resolveGlobalRole($siteId, $userId));
        $rankMap = [
            'VIEWER' => 1,
            'READER' => 1,
            'USER' => 1,
            'MEMBER' => 1,
            'EDITOR' => 2,
            'ADMIN' => 3,
            'OWNER' => 4,
        ];
        $rank = $rankMap[$role] ?? 0;
        $accessCode = PageAccessRepository::userAccessCode($userId);

        return [
            'canView' => $rank >= 1 || PageAccessRepository::hasPagePermission(
                $siteId,
                $pageId,
                $accessCode,
                'view'
            ),
            'canEdit' => $rank >= 3 || PageAccessRepository::hasPagePermission(
                $siteId,
                $pageId,
                $accessCode,
                'edit'
            ),
            'canDiskView' => $rank >= 1 || PageAccessRepository::hasPagePermission(
                $siteId,
                $pageId,
                $accessCode,
                'disk_view'
            ),
            'canDiskEdit' => $rank >= 2 || PageAccessRepository::hasPagePermission(
                $siteId,
                $pageId,
                $accessCode,
                'disk_edit'
            ),
        ];
    }

    private static function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            mb_strtolower(trim((string)$value)),
            ['1', 'true', 't', 'yes', 'y', 'on'],
            true
        );
    }
}
