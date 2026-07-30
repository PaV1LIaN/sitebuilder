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

    /**
     * Компенсирующее удаление группы, если создание сайта откатилось.
     */
    public static function deleteCreatedGroup(int $groupId): void
    {
        if ($groupId <= 0) {
            return;
        }

        if (!Loader::includeModule('socialnetwork') || !class_exists('CSocNetGroup')) {
            throw new RuntimeException('SOCIALNETWORK_MODULE_NOT_INSTALLED');
        }

        $deleted = \CSocNetGroup::Delete($groupId);

        if (!$deleted) {
            $message = self::getLastBitrixError();
            throw new RuntimeException(
                'BITRIX_GROUP_DELETE_ERROR' . ($message !== '' ? ': ' . $message : '')
            );
        }
    }

    /**
     * Идемпотентно удаляет только группу, созданную SiteBuilder.
     * Чужая группа с тем же ID не удаляется, если имя не имеет служебного префикса.
     */
    public static function deleteManagedGroup(int $groupId, string $expectedSiteName = ''): array
    {
        if ($groupId <= 0) {
            throw new InvalidArgumentException('INVALID_BITRIX_GROUP_ID');
        }
        if (!Loader::includeModule('socialnetwork') || !class_exists('CSocNetGroup')) {
            throw new RuntimeException('SOCIALNETWORK_MODULE_NOT_INSTALLED');
        }

        $group = self::getGroup($groupId);
        if (!$group) {
            return ['deleted' => false, 'alreadyMissing' => true, 'groupId' => $groupId];
        }

        $name = trim((string)($group['NAME'] ?? ''));
        if (!str_starts_with($name, 'SiteBuilder:')) {
            throw new RuntimeException('BITRIX_GROUP_NOT_MANAGED');
        }

        /*
         * Название сайта могло измениться после создания группы, поэтому
         * точное совпадение имени не является условием удаления. Защита
         * основана на сохранённом ID и служебном префиксе группы.
         */
        self::deleteCreatedGroup($groupId);
        return ['deleted' => true, 'alreadyMissing' => false, 'groupId' => $groupId, 'name' => $name];
    }

    /** Возвращает нормализованные сведения о группе или null, если она отсутствует. */
    public static function inspectGroup(int $groupId): ?array
    {
        if ($groupId <= 0) {
            return null;
        }
        if (!Loader::includeModule('socialnetwork') || !class_exists('CSocNetGroup')) {
            throw new RuntimeException('SOCIALNETWORK_MODULE_NOT_INSTALLED');
        }
        $row = self::getGroup($groupId);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)($row['ID'] ?? $groupId),
            'name' => trim((string)($row['NAME'] ?? '')),
            'description' => trim((string)($row['DESCRIPTION'] ?? '')),
            'ownerId' => (int)($row['OWNER_ID'] ?? 0),
            'active' => (string)($row['ACTIVE'] ?? 'Y') !== 'N',
            'managed' => str_starts_with(trim((string)($row['NAME'] ?? '')), 'SiteBuilder:'),
        ];
    }

    /** Перечисляет все группы со служебным префиксом SiteBuilder. */
    public static function listManagedGroups(): array
    {
        if (!Loader::includeModule('socialnetwork') || !class_exists('CSocNetGroup')) {
            throw new RuntimeException('SOCIALNETWORK_MODULE_NOT_INSTALLED');
        }
        $rows = [];

        /*
         * ORM не зависит от видимости текущего cron-пользователя и поэтому
         * предпочтительнее legacy GetList для фоновой инвентаризации.
         */
        if (class_exists('\Bitrix\Socialnetwork\WorkgroupTable')) {
            $result = \Bitrix\Socialnetwork\WorkgroupTable::getList([
                'select' => ['ID', 'NAME', 'DESCRIPTION', 'OWNER_ID', 'ACTIVE'],
                'filter' => ['%NAME' => 'SiteBuilder:'],
                'order' => ['ID' => 'ASC'],
            ]);
            while ($row = $result->fetch()) {
                self::appendManagedGroupRow($rows, $row);
            }
            return $rows;
        }

        $result = \CSocNetGroup::GetList(
            ['ID' => 'ASC'],
            ['%NAME' => 'SiteBuilder:'],
            false,
            false,
            ['ID', 'NAME', 'DESCRIPTION', 'OWNER_ID', 'ACTIVE']
        );
        while (is_object($result) && ($row = $result->Fetch())) {
            self::appendManagedGroupRow($rows, $row);
        }
        return $rows;
    }

    private static function appendManagedGroupRow(array &$rows, array $row): void
    {
        $name = trim((string)($row['NAME'] ?? ''));
        if (!str_starts_with($name, 'SiteBuilder:')) {
            return;
        }
        $rows[] = [
            'id' => (int)($row['ID'] ?? 0),
            'name' => $name,
            'description' => trim((string)($row['DESCRIPTION'] ?? '')),
            'ownerId' => (int)($row['OWNER_ID'] ?? 0),
            'active' => (string)($row['ACTIVE'] ?? 'Y') !== 'N',
            'managed' => true,
        ];
    }

    public static function expectedGroupName(array $site): string
    {
        return self::buildGroupName(
            trim((string)($site['name'] ?? '')),
            (int)($site['id'] ?? 0)
        );
    }

    private static function getGroup(int $groupId): ?array
    {
        try {
            $result = \CSocNetGroup::GetByID($groupId);
        } catch (Throwable $e) {
            throw new RuntimeException('BITRIX_GROUP_LOOKUP_FAILED', 0, $e);
        }

        if (is_array($result)) {
            return $result ?: null;
        }
        if (is_object($result) && method_exists($result, 'Fetch')) {
            $row = $result->Fetch();
            return is_array($row) ? $row : null;
        }
        return null;
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