<?php

require_once __DIR__ . '/bootstrap.php';

class SitebuilderDiskComponent
{
    protected array $params = [];
    protected array $result = [];

    public function __construct(array $params = [])
    {
        $this->params = $params;
    }

    public function execute(): void
    {
        try {
            $context = DiskContextFactory::fromArray([
                'siteId' => (int)($this->params['SITE_ID'] ?? 0),
                'pageId' => (int)($this->params['PAGE_ID'] ?? 0),
                'blockId' => (int)($this->params['BLOCK_ID'] ?? 0),
                'currentUserId' => (int)($this->params['CURRENT_USER_ID'] ?? 0),
            ]);

            DiskValidator::assertContext($context);

            $settings = DiskSettingsRepository::ensureExistsForBlock(
                $context->blockId,
                $context->siteId,
                $context->pageId,
                $context->currentUserId
            );

            $root = DiskRootResolver::resolveWithSource($context, $settings);
            $permissions = DiskPermissionService::resolve($context, $settings, $root['rootFolderId']);

            $this->result = [
                'SITE_ID' => $context->siteId,
                'PAGE_ID' => $context->pageId,
                'BLOCK_ID' => $context->blockId,
                'CURRENT_USER_ID' => $context->currentUserId,
                'SETTINGS' => $settings,
                'ROOT_FOLDER_ID' => $root['rootFolderId'],
                'ROOT_SOURCE' => $root['source'],
                'PERMISSIONS' => $permissions,
                'TITLE' => $settings['title'] ?? 'Файлы',
                'INITIAL_STATE' => [
                    'siteId' => $context->siteId,
                    'pageId' => $context->pageId,
                    'blockId' => $context->blockId,
                    'rootFolderId' => $root['rootFolderId'],
                    'rootSource' => $root['source'],
                    'currentFolderId' => $root['rootFolderId'],
                    'settings' => $settings,
                    'permissions' => $permissions,
                ],
                'ERROR' => null,
            ];
        } catch (Throwable $e) {
            $this->result = [
                'SITE_ID' => (int)($this->params['SITE_ID'] ?? 0),
                'PAGE_ID' => (int)($this->params['PAGE_ID'] ?? 0),
                'BLOCK_ID' => (int)($this->params['BLOCK_ID'] ?? 0),
                'CURRENT_USER_ID' => (int)($this->params['CURRENT_USER_ID'] ?? 0),
                'SETTINGS' => [],
                'ROOT_FOLDER_ID' => null,
                'ROOT_SOURCE' => 'none',
                'PERMISSIONS' => [
                    'canView' => false,
                    'canUpload' => false,
                    'canCreateFolder' => false,
                    'canRename' => false,
                    'canDelete' => false,
                    'canDownload' => false,
                    'canManageAccess' => false,
                    'canEditSettings' => false,
                ],
                'TITLE' => 'Файлы',
                'INITIAL_STATE' => [
                    'siteId' => (int)($this->params['SITE_ID'] ?? 0),
                    'pageId' => (int)($this->params['PAGE_ID'] ?? 0),
                    'blockId' => (int)($this->params['BLOCK_ID'] ?? 0),
                    'rootFolderId' => null,
                    'rootSource' => 'none',
                    'currentFolderId' => null,
                    'settings' => [],
                    'permissions' => [
                        'canView' => false,
                        'canUpload' => false,
                        'canCreateFolder' => false,
                        'canRename' => false,
                        'canDelete' => false,
                        'canDownload' => false,
                        'canManageAccess' => false,
                        'canEditSettings' => false,
                    ],
                ],
                'ERROR' => $e->getMessage(),
            ];
        }

        $arResult = $this->result;
        include __DIR__ . '/template.php';
    }
}