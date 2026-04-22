<?php

class DiskRootResolver
{
    public static function resolve(DiskContext $context, array $settings): ?int
    {
        if (!empty($settings['rootFolderId'])) {
            return (int)$settings['rootFolderId'];
        }

        if (!empty($settings['useSiteRootFallback'])) {
            $siteRootFolderId = SiteRepository::getRootDiskFolderId($context->siteId);
            if ($siteRootFolderId !== null && $siteRootFolderId > 0) {
                return $siteRootFolderId;
            }
        }

        return null;
    }

    public static function resolveWithSource(DiskContext $context, array $settings): array
    {
        if (!empty($settings['rootFolderId'])) {
            return [
                'rootFolderId' => (int)$settings['rootFolderId'],
                'source' => 'block',
            ];
        }

        if (!empty($settings['useSiteRootFallback'])) {
            $siteRootFolderId = SiteRepository::getRootDiskFolderId($context->siteId);
            if ($siteRootFolderId !== null && $siteRootFolderId > 0) {
                return [
                    'rootFolderId' => $siteRootFolderId,
                    'source' => 'site',
                ];
            }
        }

        return [
            'rootFolderId' => null,
            'source' => 'none',
        ];
    }
}
