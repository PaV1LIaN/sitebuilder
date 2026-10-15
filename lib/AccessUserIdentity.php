<?php

declare(strict_types=1);

/** Identity of the subject being checked, never implicitly the viewing admin. */
final class AccessUserIdentity
{
    private static array $groupCache = [];
    public static function groups(int $userId): array
    {
        global $USER;
        if ($userId <= 0) {
            return [];
        }
        if (is_object($USER) && method_exists($USER, 'GetID')
            && (int)$USER->GetID() === $userId && method_exists($USER, 'GetUserGroupArray')) {
            return array_map('intval', (array)$USER->GetUserGroupArray());
        }
        if (!array_key_exists($userId, self::$groupCache)) {
            self::$groupCache[$userId] = is_callable(['CUser', 'GetUserGroup'])
                ? array_map('intval', (array)CUser::GetUserGroup($userId)) : [];
        }
        return self::$groupCache[$userId];
    }

    public static function isPortalAdmin(int $userId): bool
    {
        global $USER;
        if ($userId <= 0) {
            return false;
        }
        if (is_object($USER) && method_exists($USER, 'GetID')
            && (int)$USER->GetID() === $userId && method_exists($USER, 'IsAdmin')) {
            return (bool)$USER->IsAdmin();
        }
        return in_array(1, self::groups($userId), true);
    }

    public static function canOpenAdmin(): bool
    {
        global $USER;
        if (!is_object($USER) || !method_exists($USER, 'IsAuthorized') || !$USER->IsAuthorized()) {
            return false;
        }
        $id = (int)$USER->GetID();
        $config = sitebuilder_auth_config();
        return $id > 0 && $id !== (int)($config['guest_user_id'] ?? 0)
            && (self::isPortalAdmin($id) || sitebuilder_is_site_admin_user_id($id));
    }
}
