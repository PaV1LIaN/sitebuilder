<?php

use Bitrix\Main\Loader;

class SiteAccessSyncService
{
    /**
     * Legacy-импорт ролей из рабочей группы. Сохранён для выполнения уже
     * поставленных заданий bitrix.access.sync. API site.syncAccess теперь
     * использует односторонний UnifiedAccessReconciliationService.
     */
    public static function sync(
        int $siteId,
        int $currentUserId,
        bool $strict = true
    ): array {
        if ($siteId <= 0) {
            throw new RuntimeException('EMPTY_SITE_ID');
        }

        if (!function_exists('sb_find_site')) {
            throw new RuntimeException('SITEBUILDER_SITE_REPOSITORY_NOT_FOUND');
        }

        $site = sb_find_site($siteId);

        if (!is_array($site)) {
            throw new RuntimeException('SITE_NOT_FOUND');
        }

        return self::syncSiteAccessFromBitrixGroup(
            $site,
            $currentUserId,
            $strict
        );
    }

    public static function syncSiteAccessFromBitrixGroup(array $site, int $currentUserId, bool $strict = true): array
    {
        if (!function_exists('sb_add_access_role_if_missing')) {
            throw new RuntimeException('SITEBUILDER_ACCESS_STORAGE_FUNCTIONS_NOT_FOUND');
        }

        $siteId = self::extractSiteId($site);
        $groupId = self::extractBitrixGroupId($site);

        if ($siteId <= 0) {
            throw new RuntimeException('EMPTY_SITE_ID');
        }

        if ($groupId <= 0) {
            throw new RuntimeException('EMPTY_BITRIX_GROUP_ID');
        }

        $members = self::getBitrixGroupMembers($groupId);

        if (empty($members)) {
            throw new RuntimeException('BITRIX_GROUP_MEMBERS_EMPTY');
        }

        $targetAccess = [];

        foreach ($members as $member) {
            $userId = (int)($member['userId'] ?? 0);
            $role = (string)($member['sitebuilderRole'] ?? '');

            if ($userId <= 0 || $role === '') {
                continue;
            }

            $accessCode = 'U' . $userId;

            if (!isset($targetAccess[$accessCode])) {
                $targetAccess[$accessCode] = $role;
                continue;
            }

            $targetAccess[$accessCode] = self::pickHigherRole($targetAccess[$accessCode], $role);
        }

        if ($currentUserId > 0) {
            $currentUserCode = 'U' . $currentUserId;

            if (!isset($targetAccess[$currentUserCode])) {
                $targetAccess[$currentUserCode] = 'OWNER';
            }
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
                $kept++;
            }
        }

        return [
            'siteId' => $siteId,
            'bitrixGroupId' => $groupId,
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

    protected static function extractSiteId(array $site): int
    {
        return (int)($site['id'] ?? $site['ID'] ?? 0);
    }

    protected static function extractBitrixGroupId(array $site): int
    {
        return (int)(
            $site['bitrixGroupId']
            ?? $site['bitrix_group_id']
            ?? $site['BITRIX_GROUP_ID']
            ?? 0
        );
    }

    protected static function getBitrixGroupMembers(int $groupId): array
    {
        if ($groupId <= 0) {
            throw new RuntimeException('EMPTY_BITRIX_GROUP_ID');
        }

        if (!Loader::includeModule('socialnetwork')) {
            throw new RuntimeException('SOCIALNETWORK_MODULE_NOT_INSTALLED');
        }

        if (!class_exists('CSocNetUserToGroup')) {
            throw new RuntimeException('CSocNetUserToGroup_NOT_FOUND');
        }

        $members = [];

        $rs = \CSocNetUserToGroup::GetList(
            ['ID' => 'ASC'],
            [
                'GROUP_ID' => $groupId,
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
            $sonetRole = (string)($row['ROLE'] ?? '');

            if ($userId <= 0) {
                continue;
            }

            $sitebuilderRole = self::mapSonetRoleToSitebuilderRole($sonetRole);

            if ($sitebuilderRole === '') {
                continue;
            }

            $members[] = [
                'userId' => $userId,
                'accessCode' => 'U' . $userId,
                'sonetRole' => $sonetRole,
                'sitebuilderRole' => $sitebuilderRole,
            ];
        }

        return $members;
    }

    protected static function mapSonetRoleToSitebuilderRole(string $sonetRole): string
    {
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

        return '';
    }

    protected static function pickHigherRole(string $roleA, string $roleB): string
    {
        $weight = [
            'VIEWER' => 10,
            'EDITOR' => 20,
            'OWNER' => 30,
        ];

        $a = $weight[$roleA] ?? 0;
        $b = $weight[$roleB] ?? 0;

        return $b > $a ? $roleB : $roleA;
    }
}
