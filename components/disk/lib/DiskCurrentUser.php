<?php

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