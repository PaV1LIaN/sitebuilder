<?php

class DiskPermissionService
{
    public static function resolve(DiskContext $context, array $settings, ?int $rootFolderId = null): array
    {
        $rolePermissions = self::resolveRolePermissions($context);
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
        ];
    }

    protected static function resolveRolePermissions(DiskContext $context): array
    {
        if (DiskCurrentUser::isAdmin()) {
            return [
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

        $role = SiteAccessRepository::getUserRole($context->siteId, $context->currentUserId);

        switch ($role) {
            case 'site_admin':
                return [
                    'canView' => true,
                    'canUpload' => true,
                    'canCreateFolder' => true,
                    'canRename' => true,
                    'canDelete' => true,
                    'canDownload' => true,
                    'canManageAccess' => true,
                    'canEditSettings' => true,
                ];

            case 'site_editor':
                return [
                    'canView' => true,
                    'canUpload' => true,
                    'canCreateFolder' => true,
                    'canRename' => true,
                    'canDelete' => true,
                    'canDownload' => true,
                    'canManageAccess' => false,
                    'canEditSettings' => true,
                ];

            case 'site_user':
                return [
                    'canView' => true,
                    'canUpload' => true,
                    'canCreateFolder' => false,
                    'canRename' => false,
                    'canDelete' => false,
                    'canDownload' => true,
                    'canManageAccess' => false,
                    'canEditSettings' => false,
                ];

            case 'site_viewer':
                return [
                    'canView' => true,
                    'canUpload' => false,
                    'canCreateFolder' => false,
                    'canRename' => false,
                    'canDelete' => false,
                    'canDownload' => true,
                    'canManageAccess' => false,
                    'canEditSettings' => false,
                ];

            default:
                return [
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
}