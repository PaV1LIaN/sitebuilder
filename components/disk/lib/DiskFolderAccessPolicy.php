<?php

final class DiskFolderAccessPolicy
{
    public static function settings(array $settings): array
    {
        $mode = (string)($settings['permissionMode'] ?? 'inherit_site');
        if (!in_array($mode, ['inherit_site', 'custom', 'bitrix_disk'], true)) {
            $mode = 'inherit_site';
        }
        // Existing individual/native ACLs must never be silently disabled on upgrade.
        $required = array_key_exists('requireFolderAccess', $settings)
            ? (filter_var($settings['requireFolderAccess'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true)
            : $mode !== 'inherit_site';
        if (!$required) {
            $mode = 'inherit_site';
        } elseif ($mode === 'inherit_site') {
            $mode = 'custom';
        }

        return ['requireFolderAccess' => $required, 'permissionMode' => $mode];
    }
}
