<?php

require_once dirname(__DIR__, 3) . '/lib/AccessUserIdentity.php';
require_once dirname(__DIR__, 3) . '/lib/AccessGroupPolicy.php';

class DiskPermissionService
{
    public static function resolve(
        DiskContext $context,
        array $settings,
        ?int $folderId = null,
        ?int $rootFolderId = null,
        bool $withExplanation = false
    ): array
    {
        $rolePermissions = self::resolveRolePermissions($context);
        $legacyPermissions = $rolePermissions;
        $native = null;
        $rootFolderId = $rootFolderId ?: $folderId;
        $folderRule = null;
        $permissionMode = (string)($settings['permissionMode'] ?? 'inherit_site');

        $protected = in_array((string)$rolePermissions['role'], ['bitrix_admin','site_admin'], true);
        $legacyEligible = !empty($rolePermissions['canView']);
        $groups = $protected ? ['view'=>false,'edit'=>false,'delete'=>false,'traverse'=>false,'groups'=>[]]
            : AccessGroupPolicy::disk($context->siteId, $context->pageId, $context->blockId,
                (int)$folderId, (int)$rootFolderId, $context->currentUserId, $permissionMode);
        $hasGroups = $groups['view'] || $groups['traverse'];
        $pageDenied = method_exists('PageAccessService', 'isPageDenied')
            && PageAccessService::isPageDenied($context->siteId, $context->pageId, $context->currentUserId);
        if ($hasGroups && (!$protected && ($pageDenied || !PageAccessService::canViewPage(
            $context->siteId, $context->pageId, $context->currentUserId)))) {
            $groups = ['view'=>false,'edit'=>false,'delete'=>false,'traverse'=>false,'groups'=>[]];
            $hasGroups = false;
        }

        if ($permissionMode === 'custom' && !$protected && ($legacyEligible || $hasGroups)
            && $folderId > 0 && $rootFolderId > 0) {
            $folderRule = FolderAccessRepository::resolveEffectiveRole(
                $context->blockId, (int)$folderId, (int)$rootFolderId, $context->currentUserId
            );
            if ($folderRule !== null) {
                $rolePermissions = self::permissionsForFolderRole((string)$folderRule['role'], $rolePermissions);
                if ((string)$folderRule['role'] === 'DENY') {
                    $groups = ['view'=>false,'edit'=>false,'delete'=>false,'traverse'=>false,'groups'=>[]];
                    $hasGroups = false;
                }
            }
        }

        $localPermissions = $rolePermissions;
        $groupPermissions = [
            'canView' => $groups['view'], 'canDownload' => $groups['view'],
            'canUpload' => $groups['edit'], 'canCreateFolder' => $groups['edit'],
            'canRename' => $groups['edit'], 'canEditFile' => $groups['edit'],
            'canDelete' => $groups['delete'],
        ];
        $canTraverse = !$protected && $groups['traverse'];
        if ($permissionMode === 'bitrix_disk' && !$protected && $folderId > 0) {
            $native = BitrixDiskRightsService::resolvePermissions($context, (int)$folderId, $rolePermissions);
            // Existing personal/page access keeps its historical native ACL semantics.
            // Local groups ALONE never gain native permissions broader than their explicit flags.
            $rolePermissions = $native;
            if (!$legacyEligible && $hasGroups) {
                foreach ($groupPermissions as $key => $allow) {
                    $rolePermissions[$key] = $allow && !empty($native[$key]);
                }
                $rolePermissions['role'] = 'group_access';
            } elseif (!$legacyEligible && !$hasGroups) {
                $rolePermissions = self::permissionsForRole('');
            }
            $canTraverse = $canTraverse && !empty($native['canView']);
        } elseif (!$protected && $hasGroups) {
            if (!array_key_exists('canEditFile', $rolePermissions)) {
                $rolePermissions['canEditFile'] = !empty($rolePermissions['canRename']);
            }
            foreach ($groupPermissions as $key => $allow) {
                $rolePermissions[$key] = !empty($rolePermissions[$key]) || $allow;
            }
            if ($rolePermissions['role'] === '') $rolePermissions['role'] = 'group_access';
        }
        if (!$protected && $pageDenied) {
            $rolePermissions = self::permissionsForRole('');
            $canTraverse = false;
        }
        if ($hasGroups) $rolePermissions['accessSource'] = 'sitebuilder_groups';

        $beforeRestrictions = $rolePermissions;
        $blockRestrictions = self::resolveBlockRestrictions($settings);

        if (!array_key_exists('canEditFile', $rolePermissions)) {
            $rolePermissions['canEditFile'] = !empty($rolePermissions['canRename']);
        }

        $result = [
            'canView' => $rolePermissions['canView'] && $blockRestrictions['canView'],
            'canTraverse' => $canTraverse && empty($rolePermissions['canView']),
            'groupSources' => $groups['groups'],
            'canUpload' => $rolePermissions['canUpload'] && $blockRestrictions['canUpload'],
            'canCreateFolder' => $rolePermissions['canCreateFolder'] && $blockRestrictions['canCreateFolder'],
            'canEditFile' => !empty($rolePermissions['canEditFile']) && $blockRestrictions['canEditFile'],
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
            'effectiveTaskName' => $rolePermissions['effectiveTaskName'] ?? null,
        ];
        if ($withExplanation) $result['explanation'] = [
            'protected'=>$protected, 'legacy'=>$legacyPermissions, 'local'=>$localPermissions,
            'native'=>$native, 'beforeRestrictions'=>$beforeRestrictions, 'blockRestrictions'=>$blockRestrictions,
            'groupFlags'=>$groups, 'legacyEligible'=>$legacyEligible, 'folderRule'=>$folderRule,
            'pageDenied'=>$pageDenied,
            'pageVisible'=>PageAccessService::canViewPage($context->siteId,$context->pageId,$context->currentUserId),
        ];
        return $result;
    }

    protected static function resolveRolePermissions(DiskContext $context): array
    {
        if (AccessUserIdentity::isPortalAdmin($context->currentUserId)) {
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
            $context->currentUserId,
            false // Legacy capabilities only; group edit must not imply deletion.
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
            $context->currentUserId,
            false // Legacy capabilities only; group edit must not imply deletion.
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
         * EDITOR не редактирует страницы сайта и не управляет
         * настройками блока или правами других пользователей.
         *
         * Право изменения Диска означает полноценную работу
         * с содержимым: загрузку, создание папок, переименование,
         * перемещение, копирование, удаление и скачивание.
         */
        if ($role === 'site_editor') {
            return [
                'role' => $role,
                'canView' => true,
                'canUpload' => true,
                'canCreateFolder' => true,
                'canRename' => true,
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
            'canEditFile' => !empty($settings['allowUpload']),
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
