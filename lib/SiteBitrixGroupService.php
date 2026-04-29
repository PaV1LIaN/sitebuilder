<?php

use Bitrix\Main\Loader;

class SiteBitrixGroupService
{
    public static function createForSite(array $site, int $ownerUserId): int
    {
        if ($ownerUserId <= 0) {
            throw new RuntimeException('EMPTY_OWNER_USER_ID');
        }

        if (!Loader::includeModule('socialnetwork')) {
            throw new RuntimeException('SOCIALNETWORK_MODULE_NOT_INSTALLED');
        }

        if (!class_exists('CSocNetGroup')) {
            throw new RuntimeException('CSocNetGroup_NOT_FOUND');
        }

        $siteId = (int)($site['id'] ?? 0);
        $siteName = trim((string)($site['name'] ?? ''));

        if ($siteId <= 0) {
            throw new RuntimeException('EMPTY_SITE_ID');
        }

        if ($siteName === '') {
            $siteName = 'Сайт #' . $siteId;
        }

        $groupName = self::buildGroupName($siteName, $siteId);
        $subjectId = self::resolveSubjectId();

        $fields = [
            'SITE_ID' => self::getSiteId(),
            'NAME' => $groupName,
            'DESCRIPTION' => 'Рабочая группа сайта SiteBuilder: ' . $siteName,
            'VISIBLE' => 'N',
            'OPENED' => 'N',
            'PROJECT' => 'N',
            'SUBJECT_ID' => $subjectId,
            'INITIATE_PERMS' => self::ownerRole(),
            'SPAM_PERMS' => self::ownerRole(),
        ];

        $groupId = (int)\CSocNetGroup::CreateGroup(
            $ownerUserId,
            $fields,
            false
        );

        if ($groupId <= 0) {
            $message = self::getLastBitrixError();

            throw new RuntimeException(
                'BITRIX_GROUP_CREATE_ERROR' . ($message !== '' ? ': ' . $message : '')
            );
        }

        return $groupId;
    }

    protected static function buildGroupName(string $siteName, int $siteId): string
    {
        $siteName = trim($siteName);

        if ($siteName === '') {
            $siteName = 'Сайт #' . $siteId;
        }

        return 'SiteBuilder: ' . $siteName;
    }

    protected static function resolveSubjectId(): int
    {
        if (!class_exists('CSocNetGroupSubject')) {
            return 1;
        }

        $siteId = self::getSiteId();

        $rs = \CSocNetGroupSubject::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            ['SITE_ID' => $siteId],
            false,
            ['nTopCount' => 1],
            ['ID', 'SITE_ID', 'NAME']
        );

        if ($row = $rs->Fetch()) {
            $id = (int)($row['ID'] ?? 0);

            if ($id > 0) {
                return $id;
            }
        }

        $rs = \CSocNetGroupSubject::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            [],
            false,
            ['nTopCount' => 1],
            ['ID', 'SITE_ID', 'NAME']
        );

        if ($row = $rs->Fetch()) {
            $id = (int)($row['ID'] ?? 0);

            if ($id > 0) {
                return $id;
            }
        }

        return 1;
    }

    protected static function getSiteId(): string
    {
        if (defined('SITE_ID') && SITE_ID) {
            return (string)SITE_ID;
        }

        return 's1';
    }

    protected static function ownerRole(): string
    {
        if (defined('SONET_ROLES_OWNER')) {
            return SONET_ROLES_OWNER;
        }

        return 'A';
    }

    protected static function getLastBitrixError(): string
    {
        global $APPLICATION;

        if (is_object($APPLICATION) && method_exists($APPLICATION, 'GetException')) {
            $exception = $APPLICATION->GetException();

            if ($exception && method_exists($exception, 'GetString')) {
                return trim((string)$exception->GetString());
            }
        }

        return '';
    }
}