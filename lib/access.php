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
        $access = sb_read_access();

        foreach ($access as $row) {
            if (
                (int)($row['siteId'] ?? 0) === $siteId
                && (string)($row['accessCode'] ?? '') === $accessCode
            ) {
                return (string)($row['role'] ?? '');
            }
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
        $role = sb_get_role($siteId, sb_user_access_code());

        if (sb_role_rank($role) < $minRank) {
            sb_json_error('ACCESS_DENIED', 403);
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

if (!function_exists('sb_require_editor')) {
    function sb_require_editor(int $siteId): void
    {
        sb_require_site_role($siteId, 2);
    }
}

if (!function_exists('sb_require_viewer')) {
    function sb_require_viewer(int $siteId): void
    {
        sb_require_site_role($siteId, 1);
    }
}