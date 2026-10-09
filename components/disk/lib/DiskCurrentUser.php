<?php

require_once dirname(__DIR__, 3) . '/lib/auth.php';

class DiskCurrentUser
{
    public static function getId(): int
    {
        global $USER;

        if ($USER instanceof CUser) {
            return (int)$USER->GetID();
        }

        return 0;
    }

    public static function requireId(): int
    {
        $userId = self::getId();
        if ($userId <= 0) {
            throw new RuntimeException('NOT_AUTHORIZED');
        }

        return $userId;
    }

    public static function isAdmin(): bool
    {
        return sitebuilder_is_admin();
    }

    public static function isBitrixAdmin(): bool
    {
        global $USER;

        if (!($USER instanceof CUser)) {
            return false;
        }

        return $USER->IsAdmin();
    }

    public static function getGroupIds(): array
    {
        global $USER;

        if (!($USER instanceof CUser)) {
            return [];
        }

        $groups = $USER->GetUserGroupArray();
        if (!is_array($groups)) {
            return [];
        }

        return array_values(array_map('intval', $groups));
    }
}
