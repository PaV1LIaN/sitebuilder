<?php

class DiskPermissionService
{
    public static function resolve(
        DiskContext $context,
        array $settings,
        ?int $folderId = null,
        ?int $rootFolderId = null
    ): array
    {
        $rolePermissions = self::resolveRolePermissions($context);
        $rootFolderId = $rootFolderId ?: $folderId;
        $folderRule = null;

        if (
            ($settings['permissionMode'] ?? 'inherit_site') === 'custom'
            && $rolePermissions['role'] !== ''
            && $rolePermissions['role'] !== 'bitrix_admin'
            && $rolePermissions['role'] !== 'site_admin'
            && $folderId !== null
            && $folderId > 0
            && $rootFolderId !== null
            && $rootFolderId > 0
        ) {
            $folderRule = FolderAccessRepository::resolveEffectiveRole(
                $context->blockId,
                $folderId,
                $rootFolderId,
                $context->currentUserId
            );

            if ($folderRule !== null) {
                $rolePermissions = self::permissionsForFolderRole(
                    (string)$folderRule['role'],
                    $rolePermissions
                );
            }
        }

        $blockRestrictions = self::resolveBlockRestrictions($settings);

        return [
            'canView' => $rolePermissions['canView'] && $blockRestrictions['canView'],
            'canUpload' => $rolePermissions['canUpload'] && $blockRestrictions['canUpload'],
            'canCreateFolder' => $rolePermissions['canCreateFolder'] && $blockRestrictions['canCreateFolder'],
            'canRename' => $rolePermissions['canRename'] && $blockRestrictions['canRename'],
            'canDelete' => $rolePermissions['canDelete'] && $blockRestrictions['canDelete'],
            'canDownload' => $rolePermissions['canDownload'] && $blockRestrictions['canDownload'],

            'canManageAccess' => $rolePermissions['canManageAccess'],
            'canEditSettings' => $rolePermissions['canEditSettings'],

            'role' => $rolePermissions['role'],
            'folderRole' => $folderRule['role'] ?? null,
            'folderRuleId' => isset($folderRule['folderId']) ? (int)$folderRule['folderId'] : null,
            'folderRuleInherited' => !empty($folderRule['inherited']),
        ];
    }

    protected static function resolveRolePermissions(DiskContext $context): array
    {
        if (DiskCurrentUser::isAdmin()) {
            return self::permissionsForRole('bitrix_admin');
        }

        $role = SiteAccessRepository::getUserRole($context->siteId, $context->currentUserId);

        return self::permissionsForRole((string)$role);
    }

    protected static function permissionsForRole(string $role): array
    {
        $role = trim($role);

        if ($role === 'site_admin' || $role === 'bitrix_admin') {
            return [
                'role' => $role,
                'canView' => true,
                'canUpload' => true,
                'canCreateFolder' => true,
                'canRename' => true,
                'canDelete' => true,
                'canDownload' => true,
                'canManageAccess' => true,
                'canEditSettings' => true,
            ];
        }

        /*
         * EDITOR теперь НЕ редактирует сайт.
         * Он работает только с файлами диска:
         * загрузка, скачивание, удаление.
         */
        if ($role === 'site_editor') {
            return [
                'role' => $role,
                'canView' => true,
                'canUpload' => true,
                'canCreateFolder' => false,
                'canRename' => false,
                'canDelete' => true,
                'canDownload' => true,
                'canManageAccess' => false,
                'canEditSettings' => false,
            ];
        }

        if ($role === 'site_user') {
            return [
                'role' => $role,
                'canView' => true,
                'canUpload' => false,
                'canCreateFolder' => false,
                'canRename' => false,
                'canDelete' => false,
                'canDownload' => true,
                'canManageAccess' => false,
                'canEditSettings' => false,
            ];
        }

        if ($role === 'site_viewer') {
            return [
                'role' => $role,
                'canView' => true,
                'canUpload' => false,
                'canCreateFolder' => false,
                'canRename' => false,
                'canDelete' => false,
                'canDownload' => true,
                'canManageAccess' => false,
                'canEditSettings' => false,
            ];
        }

        return [
            'role' => '',
            'canView' => false,
            'canUpload' => false,
            'canCreateFolder' => false,
            'canRename' => false,
            'canDelete' => false,
            'canDownload' => false,
            'canManageAccess' => false,
            'canEditSettings' => false,
        ];
    }

    protected static function resolveBlockRestrictions(array $settings): array
    {
        return [
            'canView' => true,
            'canUpload' => !empty($settings['allowUpload']),
            'canCreateFolder' => !empty($settings['allowCreateFolder']),
            'canRename' => !empty($settings['allowRename']),
            'canDelete' => !empty($settings['allowDelete']),
            'canDownload' => !empty($settings['allowDownload']),
        ];
    }

    protected static function permissionsForFolderRole(string $role, array $base): array
    {
        $management = [
            'canManageAccess' => !empty($base['canManageAccess']),
            'canEditSettings' => !empty($base['canEditSettings']),
        ];

        if ($role === FolderAccessRepository::ROLE_EDITOR) {
            return array_merge([
                'role' => 'folder_editor',
                'canView' => true,
                'canUpload' => true,
                'canCreateFolder' => true,
                'canRename' => true,
                'canDelete' => true,
                'canDownload' => true,
            ], $management);
        }

        if ($role === FolderAccessRepository::ROLE_VIEWER) {
            return array_merge([
                'role' => 'folder_viewer',
                'canView' => true,
                'canUpload' => false,
                'canCreateFolder' => false,
                'canRename' => false,
                'canDelete' => false,
                'canDownload' => true,
            ], $management);
        }

        return array_merge([
            'role' => 'folder_denied',
            'canView' => false,
            'canUpload' => false,
            'canCreateFolder' => false,
            'canRename' => false,
            'canDelete' => false,
            'canDownload' => false,
        ], $management);
    }
}
