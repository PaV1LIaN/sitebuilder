<?php

declare(strict_types=1);

/**
 * Собирает именно пользователей, которые могут открыть выбранную страницу.
 *
 * Источники доступа:
 * - прямые глобальные роли sitebuilder.access;
 * - прямые и унаследованные правила sitebuilder.page_access;
 * - в legacy-режиме участники рабочей группы Битрикс24, связанной с сайтом;
 * - администраторы Битрикс24.
 *
 * Групповые access_code разворачиваются в пользователей, потому что ACL
 * папки Диска настраивается в интерфейсе построчно для каждого человека.
 * Контроллер синхронизации передаёт sitebuilderOnly=true и тем самым не
 * использует портал как обратный источник глобального доступа.
 */
class DiskPageUserRepository
{
    private const MAX_USERS = 1000;

    public static function listUsersWithPageAccess(
        int $siteId,
        int $pageId,
        bool $sitebuilderOnly = false
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

        if (!$sitebuilderOnly) {
            self::collectSiteWorkgroupUsers($siteId, $userIds);
        }

        /* Администраторы имеют неявный доступ ко всем страницам. */
        $adminUserIds = [];
        self::collectMainGroupUsers(1, $adminUserIds);
        foreach (array_keys($adminUserIds) as $adminUserId) {
            $userIds[(int)$adminUserId] = true;
        }

        if (!$sitebuilderOnly) {
            $currentUserId = DiskCurrentUser::getId();
            if ($currentUserId > 0) {
                $userIds[$currentUserId] = true;
            }
        }

        $ids = array_map('intval', array_keys($userIds));
        $ids = array_values(array_filter($ids, static function (int $id): bool {
            return $id > 0;
        }));
        sort($ids, SORT_NUMERIC);
        if (count($ids) > self::MAX_USERS) {
            throw new RuntimeException('TOO_MANY_PAGE_USERS');
        }

        /*
         * PageAccessService проверяет администратора через текущего $USER.
         * При построении матрицы администратором это сделало бы администраторами
         * все строки. Поэтому права каждого кандидата вычисляются явно.
        */
        $visibleIds = [];
        $pageAccessByUser = [];
        $globalRoleByUser = [];
        $accessCodesByUser = self::accessCodesByUsers($ids);
        $allAccessCodes = [];
        foreach ($accessCodesByUser as $accessCodes) {
            foreach ($accessCodes as $accessCode) {
                $allAccessCodes[$accessCode] = true;
            }
        }
        $siteRolesByCode = self::loadSiteRolesByAccessCode(
            $siteId,
            array_keys($allAccessCodes)
        );
        $pagePermissionsByCode = self::loadPagePermissionsByAccessCode(
            $siteId,
            $pageId,
            array_keys($allAccessCodes)
        );

        foreach ($ids as $userId) {
            $accessCodes = $accessCodesByUser[$userId] ?? ['U' . $userId];
            $globalRole = self::resolveGlobalRole(
                $siteId,
                $userId,
                $sitebuilderOnly,
                $accessCodes,
                $siteRolesByCode
            );
            $pageAccess = self::buildPageAccessInfo(
                isset($adminUserIds[$userId]),
                $globalRole,
                $accessCodes,
                $pagePermissionsByCode
            );

            if (!$pageAccess['canView']) {
                continue;
            }

            $visibleIds[] = $userId;
            $pageAccessByUser[$userId] = $pageAccess;
            $globalRoleByUser[$userId] = $globalRole;
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

            $profile['globalRole'] = $globalRoleByUser[$userId] ?? '';
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
        int $userId,
        bool $sitebuilderOnly,
        array $accessCodes,
        array $siteRolesByCode
    ): string {
        $bestRole = self::bestRoleForAccessCodes(
            $accessCodes,
            $siteRolesByCode
        );

        if ($sitebuilderOnly) {
            return $bestRole;
        }

        if (!function_exists('sb_get_role')) {
            return $bestRole;
        }

        try {
            $fallbackRole = (string)(sb_get_role($siteId, 'U' . $userId) ?? '');
            return self::roleRank($fallbackRole) > self::roleRank($bestRole)
                ? $fallbackRole
                : $bestRole;
        } catch (Throwable $exception) {
            return $bestRole;
        }
    }

    private static function buildPageAccessInfo(
        bool $isBitrixAdmin,
        string $globalRole,
        array $accessCodes,
        array $pagePermissionsByCode
    ): array {
        if ($isBitrixAdmin) {
            return [
                'canView' => true,
                'canEdit' => true,
                'canDiskView' => true,
                'canDiskEdit' => true,
            ];
        }

        $rank = self::roleRank($globalRole);
        $pagePermissions = self::mergePagePermissionsForAccessCodes(
            $accessCodes,
            $pagePermissionsByCode
        );

        return [
            'canView' => $rank >= 1 || $pagePermissions['canView'],
            'canEdit' => $rank >= 3 || $pagePermissions['canEdit'],
            'canDiskView' => $rank >= 1 || $pagePermissions['canDiskView'],
            'canDiskEdit' => $rank >= 2 || $pagePermissions['canDiskEdit'],
        ];
    }

    /** @return array<int,string[]> */
    private static function accessCodesByUsers(array $userIds): array
    {
        $result = [];
        foreach ($userIds as $userId) {
            $userId = (int)$userId;
            if ($userId > 0) {
                $result[$userId] = ['U' . $userId];
            }
        }
        if (empty($result)) {
            return [];
        }

        if (class_exists('\Bitrix\Main\UserGroupTable')) {
            $rows = \Bitrix\Main\UserGroupTable::getList([
                'select' => ['USER_ID', 'GROUP_ID'],
                'filter' => ['@USER_ID' => array_keys($result)],
            ]);
            while ($row = $rows->fetch()) {
                $userId = (int)($row['USER_ID'] ?? 0);
                $groupId = (int)($row['GROUP_ID'] ?? 0);
                if ($userId > 0 && $groupId > 0 && isset($result[$userId])) {
                    $result[$userId][] = 'G' . $groupId;
                }
            }
        } elseif (class_exists('CUser') && method_exists('CUser', 'GetUserGroup')) {
            foreach (array_keys($result) as $userId) {
                foreach ((array)\CUser::GetUserGroup((int)$userId) as $groupId) {
                    $groupId = (int)$groupId;
                    if ($groupId > 0) {
                        $result[$userId][] = 'G' . $groupId;
                    }
                }
            }
        }

        foreach ($result as &$codes) {
            $codes = array_values(array_unique($codes));
            sort($codes, SORT_NATURAL);
        }
        unset($codes);
        return $result;
    }

    /** @return array<string,string> */
    private static function loadSiteRolesByAccessCode(
        int $siteId,
        array $accessCodes
    ): array {
        if ($siteId <= 0 || empty($accessCodes)) {
            return [];
        }

        $params = [':site_id' => $siteId];
        $placeholders = [];
        foreach (array_values($accessCodes) as $index => $accessCode) {
            $placeholder = ':access_code_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (string)$accessCode;
        }

        $rows = DiskDb::fetchAll("
            SELECT access_code,role
            FROM sitebuilder.access
            WHERE site_id=:site_id
              AND access_code IN (" . implode(',', $placeholders) . ")
        ", $params);

        $result = [];
        foreach ($rows as $row) {
            $accessCode = (string)($row['access_code'] ?? '');
            if ($accessCode !== '') {
                $result[$accessCode] = (string)($row['role'] ?? '');
            }
        }
        return $result;
    }

    /** @return array<string,array> */
    private static function loadPagePermissionsByAccessCode(
        int $siteId,
        int $pageId,
        array $accessCodes
    ): array {
        $pageIds = PageAccessRepository::getPageAndParentIds($siteId, $pageId);
        if (empty($accessCodes) || empty($pageIds)) {
            return [];
        }

        $params = [':site_id' => $siteId];
        $accessPlaceholders = [];
        foreach (array_values($accessCodes) as $index => $accessCode) {
            $placeholder = ':permission_access_' . $index;
            $accessPlaceholders[] = $placeholder;
            $params[$placeholder] = (string)$accessCode;
        }
        $pagePlaceholders = [];
        foreach (array_values($pageIds) as $index => $candidatePageId) {
            $placeholder = ':permission_page_' . $index;
            $pagePlaceholders[] = $placeholder;
            $params[$placeholder] = (int)$candidatePageId;
        }

        $rows = DiskDb::fetchAll("
            SELECT access_code,page_id,can_view,can_edit,can_disk_view,can_disk_edit,
                   include_children
            FROM sitebuilder.page_access
            WHERE site_id=:site_id
              AND access_code IN (" . implode(',', $accessPlaceholders) . ")
              AND page_id IN (" . implode(',', $pagePlaceholders) . ")
        ", $params);

        $result = [];
        foreach ($rows as $row) {
            $direct = (int)($row['page_id'] ?? 0) === $pageId;
            if (!$direct && !self::boolValue($row['include_children'] ?? false)) {
                continue;
            }
            $accessCode = (string)($row['access_code'] ?? '');
            if ($accessCode === '') {
                continue;
            }
            $permissions = $result[$accessCode] ?? self::emptyPagePermissions();
            $permissions['canView'] = $permissions['canView']
                || self::boolValue($row['can_view'] ?? false)
                || self::boolValue($row['can_edit'] ?? false);
            $permissions['canEdit'] = $permissions['canEdit']
                || self::boolValue($row['can_edit'] ?? false);
            $permissions['canDiskView'] = $permissions['canDiskView']
                || self::boolValue($row['can_disk_view'] ?? false)
                || self::boolValue($row['can_disk_edit'] ?? false);
            $permissions['canDiskEdit'] = $permissions['canDiskEdit']
                || self::boolValue($row['can_disk_edit'] ?? false);
            $result[$accessCode] = $permissions;
        }
        return $result;
    }

    private static function bestRoleForAccessCodes(
        array $accessCodes,
        array $rolesByCode
    ): string {
        $bestRole = '';
        foreach ($accessCodes as $accessCode) {
            $role = (string)($rolesByCode[$accessCode] ?? '');
            if (self::roleRank($role) > self::roleRank($bestRole)) {
                $bestRole = $role;
            }
        }
        return $bestRole;
    }

    private static function mergePagePermissionsForAccessCodes(
        array $accessCodes,
        array $permissionsByCode
    ): array {
        $result = self::emptyPagePermissions();
        foreach ($accessCodes as $accessCode) {
            $permissions = $permissionsByCode[$accessCode] ?? null;
            if (!is_array($permissions)) {
                continue;
            }
            foreach (array_keys($result) as $key) {
                $result[$key] = $result[$key] || !empty($permissions[$key]);
            }
        }
        return $result;
    }

    private static function emptyPagePermissions(): array
    {
        return [
            'canView' => false,
            'canEdit' => false,
            'canDiskView' => false,
            'canDiskEdit' => false,
        ];
    }

    private static function roleRank(string $role): int
    {
        return match (mb_strtoupper(trim($role))) {
            'VIEWER', 'READER', 'USER', 'MEMBER' => 1,
            'EDITOR' => 2,
            'ADMIN' => 3,
            'OWNER' => 4,
            default => 0,
        };
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
