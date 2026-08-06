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
            'accessSource' => $rolePermissions['accessSource'] ?? '',
            'folderRole' => $folderRule['role'] ?? null,
            'folderRuleId' => isset($folderRule['folderId']) ? (int)$folderRule['folderId'] : null,
            'folderRuleInherited' => !empty($folderRule['inherited']),
        ];
    }

    protected static function resolveRolePermissions(DiskContext $context): array
    {
        if (DiskCurrentUser::isAdmin()) {
            return self::withAccessSource(
                self::permissionsForRole('bitrix_admin'),
                'bitrix_admin'
            );
        }

        /*
         * Сначала определяем глобальную роль сайта.
         * Она нужна, чтобы сохранить полный доступ OWNER/ADMIN.
         */
        $globalRole = SiteAccessRepository::getUserRole(
            $context->siteId,
            $context->currentUserId
        );

        if ($globalRole === 'site_admin') {
            return self::withAccessSource(
                self::permissionsForRole('site_admin'),
                'global_site_role'
            );
        }

        /*
         * PageAccessService объединяет:
         * - глобальные роли сайта;
         * - точечные права выбранной страницы;
         * - наследуемые права родительской страницы.
         *
         * Благодаря этому пользователь, которому выдали can_disk_view
         * или can_disk_edit только на одной странице, получает доступ
         * к компоненту Диска именно на этой странице.
         */
        if (PageAccessService::canEditDisk(
            $context->siteId,
            $context->pageId,
            $context->currentUserId
        )) {
            return self::withAccessSource(
                self::permissionsForRole('site_editor'),
                $globalRole !== null
                    ? 'global_or_page_access'
                    : 'page_access'
            );
        }

        if (PageAccessService::canViewDisk(
            $context->siteId,
            $context->pageId,
            $context->currentUserId
        )) {
            $viewRole = in_array(
                $globalRole,
                ['site_user', 'site_viewer'],
                true
            )
                ? $globalRole
                : 'site_viewer';

            return self::withAccessSource(
                self::permissionsForRole($viewRole),
                $globalRole !== null
                    ? 'global_or_page_access'
                    : 'page_access'
            );
        }

        return self::withAccessSource(
            self::permissionsForRole(''),
            'none'
        );
    }

    protected static function withAccessSource(
        array $permissions,
        string $source
    ): array {
        $permissions['accessSource'] = $source;
        return $permissions;
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
         * EDITOR не редактирует страницы сайта.
         * Он работает с файлами Диска:
         * загрузка, скачивание и удаление.
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
            'accessSource' => $base['accessSource'] ?? '',
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
