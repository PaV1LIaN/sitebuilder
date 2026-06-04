<?php

require_once __DIR__ . '/json.php';
require_once __DIR__ . '/response.php';

if (!function_exists('sb_user_access_code')) {
    function sb_user_access_code(): string
    {
        global $USER;

        return 'U' . (int)$USER->GetID();
    }
}

if (!function_exists('sb_get_role')) {
    function sb_get_role(int $siteId, string $accessCode): ?string
    {
        $siteId = (int)$siteId;
        $accessCode = trim((string)$accessCode);

        if ($siteId <= 0 || $accessCode === '') {
            return null;
        }

        $directRole = sb_get_role_from_access_table($siteId, $accessCode);

        if ($directRole !== null && $directRole !== '') {
            return $directRole;
        }

        return sb_get_role_from_bitrix_group($siteId, $accessCode);
    }
}

if (!function_exists('sb_get_role_from_access_table')) {
    function sb_get_role_from_access_table(int $siteId, string $accessCode): ?string
    {
        $access = sb_read_access();

        foreach ($access as $row) {
            if (
                (int)($row['siteId'] ?? 0) === $siteId
                && (string)($row['accessCode'] ?? '') === $accessCode
            ) {
                $role = trim((string)($row['role'] ?? ''));

                return $role !== '' ? $role : null;
            }
        }

        return null;
    }
}

if (!function_exists('sb_get_role_from_bitrix_group')) {
    function sb_get_role_from_bitrix_group(int $siteId, string $accessCode): ?string
    {
        static $cache = [];

        $siteId = (int)$siteId;
        $accessCode = trim((string)$accessCode);

        if ($siteId <= 0 || $accessCode === '') {
            return null;
        }

        if (!preg_match('/^U(\d+)$/', $accessCode, $m)) {
            return null;
        }

        $userId = (int)$m[1];

        if ($userId <= 0) {
            return null;
        }

        $cacheKey = $siteId . ':' . $userId;

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $bitrixGroupId = sb_get_site_bitrix_group_id_for_access($siteId);

        if ($bitrixGroupId <= 0) {
            $cache[$cacheKey] = null;
            return null;
        }

        if (!class_exists('\Bitrix\Main\Loader')) {
            $cache[$cacheKey] = null;
            return null;
        }

        if (!\Bitrix\Main\Loader::includeModule('socialnetwork')) {
            $cache[$cacheKey] = null;
            return null;
        }

        if (!class_exists('CSocNetUserToGroup')) {
            $cache[$cacheKey] = null;
            return null;
        }

        $rs = \CSocNetUserToGroup::GetList(
            ['ID' => 'ASC'],
            [
                'GROUP_ID' => $bitrixGroupId,
                'USER_ID' => $userId,
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

        $bestRole = null;

        while ($row = $rs->Fetch()) {
            $sonetRole = (string)($row['ROLE'] ?? '');
            $sitebuilderRole = sb_map_sonet_role_to_sitebuilder_role($sonetRole);

            if ($sitebuilderRole === null) {
                continue;
            }

            if (
                $bestRole === null
                || sb_role_rank($sitebuilderRole) > sb_role_rank($bestRole)
            ) {
                $bestRole = $sitebuilderRole;
            }
        }

        $cache[$cacheKey] = $bestRole;

        return $bestRole;
    }
}

if (!function_exists('sb_get_site_bitrix_group_id_for_access')) {
    function sb_get_site_bitrix_group_id_for_access(int $siteId): int
    {
        static $cache = [];

        $siteId = (int)$siteId;

        if ($siteId <= 0) {
            return 0;
        }

        if (array_key_exists($siteId, $cache)) {
            return $cache[$siteId];
        }

        $groupId = 0;

        if (function_exists('sb_find_site')) {
            try {
                $site = sb_find_site($siteId);

                if (is_array($site)) {
                    $groupId = (int)(
                        $site['bitrixGroupId']
                        ?? $site['bitrix_group_id']
                        ?? 0
                    );
                }
            } catch (Throwable $e) {
                $groupId = 0;
            }
        }

        if ($groupId <= 0 && function_exists('sb_db')) {
            try {
                $pdo = sb_db();

                $st = $pdo->prepare("
                    SELECT bitrix_group_id
                    FROM sitebuilder.site
                    WHERE id = :site_id
                    LIMIT 1
                ");

                $st->execute([
                    ':site_id' => $siteId,
                ]);

                $row = $st->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $groupId = (int)($row['bitrix_group_id'] ?? 0);
                }
            } catch (Throwable $e) {
                $groupId = 0;
            }
        }

        $cache[$siteId] = $groupId;

        return $groupId;
    }
}

if (!function_exists('sb_map_sonet_role_to_sitebuilder_role')) {
    function sb_map_sonet_role_to_sitebuilder_role(string $sonetRole): ?string
    {
        $sonetRole = trim((string)$sonetRole);

        $ownerRole = defined('SONET_ROLES_OWNER') ? SONET_ROLES_OWNER : 'A';
        $moderatorRole = defined('SONET_ROLES_MODERATOR') ? SONET_ROLES_MODERATOR : 'E';
        $userRole = defined('SONET_ROLES_USER') ? SONET_ROLES_USER : 'K';

        if ($sonetRole === $ownerRole || $sonetRole === 'A') {
            return 'OWNER';
        }

        if ($sonetRole === $moderatorRole || $sonetRole === 'E') {
            /*
             * В новой матрице прав EDITOR — это не редактор конструктора,
             * а пользователь, который может работать с файлами диска.
             */
            return 'EDITOR';
        }

        if ($sonetRole === $userRole || $sonetRole === 'K') {
            return 'VIEWER';
        }

        return null;
    }
}

if (!function_exists('sb_role_rank')) {
    function sb_role_rank(?string $role): int
    {
        switch ((string)$role) {
            case 'VIEWER':
                return 1;

            case 'EDITOR':
                return 2;

            case 'ADMIN':
                return 3;

            case 'OWNER':
                return 4;

            default:
                return 0;
        }
    }
}

if (!function_exists('sb_require_site_role')) {
    function sb_require_site_role(int $siteId, int $minRank): void
    {
        global $USER;

        /*
         * Администратор Битрикс24 имеет полный доступ ко всем сайтам конструктора.
         */
        if ($USER && $USER->IsAdmin()) {
            return;
        }

        $role = sb_get_role($siteId, sb_user_access_code());

        if (sb_role_rank($role) < $minRank) {
            sb_json_error('ACCESS_DENIED', 403, [
                'siteId' => $siteId,
                'requiredRank' => $minRank,
                'actualRole' => $role,
            ]);
        }
    }
}

if (!function_exists('sb_require_owner')) {
    function sb_require_owner(int $siteId): void
    {
        sb_require_site_role($siteId, 4);
    }
}

if (!function_exists('sb_require_admin')) {
    function sb_require_admin(int $siteId): void
    {
        sb_require_site_role($siteId, 3);
    }
}

if (!function_exists('sb_require_content_manager')) {
    function sb_require_content_manager(int $siteId): void
    {
        /*
         * Контент сайта: страницы, блоки, меню, layout, шаблоны.
         *
         * Доступ только:
         * - ADMIN сайта
         * - OWNER сайта
         * - администратор Битрикс24
         *
         * EDITOR сюда НЕ проходит.
         * EDITOR теперь нужен только для работы с файлами диска.
         */
        sb_require_site_role($siteId, 3);
    }
}

if (!function_exists('sb_require_editor')) {
    function sb_require_editor(int $siteId): void
    {
        /*
         * Оставляем старую функцию для совместимости.
         * EDITOR = файловый редактор диска.
         */
        sb_require_site_role($siteId, 2);
    }
}

if (!function_exists('sb_require_viewer')) {
    function sb_require_viewer(int $siteId): void
    {
        sb_require_site_role($siteId, 1);
    }
}