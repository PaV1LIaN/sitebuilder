<?php

class DiskRootResolver
{
    public static function resolve(DiskContext $context, array $settings, bool $autoCreate = false): ?int
    {
        $result = self::resolveWithSource($context, $settings, $autoCreate);
        return $result['rootFolderId'];
    }

    public static function resolveWithSource(DiskContext $context, array $settings, bool $autoCreate = false): array
    {
        $rootMode = (string)($settings['rootMode'] ?? 'site');

        if ($rootMode === 'block') {
            if (!empty($settings['rootFolderId'])) {
                return [
                    'rootFolderId' => (int)$settings['rootFolderId'],
                    'source' => 'block',
                ];
            }

            if ($autoCreate) {
                $folderId = BlockDiskInitializer::ensureBlockRootFolder(
                    $context->siteId,
                    $context->pageId,
                    $context->blockId,
                    $context->currentUserId,
                    (string)($settings['title'] ?? '')
                );

                return [
                    'rootFolderId' => $folderId,
                    'source' => 'block',
                ];
            }
        }

        $siteRootFolderId = SiteRepository::getRootDiskFolderId($context->siteId);
        if ($siteRootFolderId !== null && $siteRootFolderId > 0) {
            return [
                'rootFolderId' => $siteRootFolderId,
                'source' => 'site',
            ];
        }

        if ($autoCreate && !empty($settings['useSiteRootFallback'])) {
            $site = SiteRepository::getById($context->siteId);
            if (!$site) {
                throw new RuntimeException('SITE_NOT_FOUND');
            }

            $folderId = SiteDiskInitializer::ensureSiteRootFolder(
                $context->siteId,
                $context->currentUserId,
                (string)$site['name']
            );

            return [
                'rootFolderId' => $folderId,
                'source' => 'site',
            ];
        }

        return [
            'rootFolderId' => null,
            'source' => 'none',
        ];
    }
}