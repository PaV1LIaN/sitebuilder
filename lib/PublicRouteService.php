<?php

declare(strict_types=1);

final class PublicRouteService
{
    private const PUBLIC_RULE_ID = 'sitebuilder:public';
    private const SITEMAP_RULE_ID = 'sitebuilder:sitemap';

    public static function status(string $basePath): array
    {
        $expectedRules = self::expectedRules($basePath);

        if (!class_exists('CUrlRewriter')) {
            return [
                'available' => false,
                'installed' => false,
                'rules' => $expectedRules,
                'message' => 'Класс CUrlRewriter недоступен.',
            ];
        }

        $installedCount = 0;

        foreach ($expectedRules as $expected) {
            $items = \CUrlRewriter::GetList([
                'SITE_ID' => self::siteId(),
                'ID' => (string)$expected['ID'],
            ]);

            foreach ((array)$items as $item) {
                if (is_array($item) && self::sameRule($item, $expected)) {
                    $installedCount++;
                    break;
                }
            }
        }

        if ($installedCount === count($expectedRules)) {
            return [
                'available' => true,
                'installed' => true,
                'rules' => $expectedRules,
                'message' => 'Публичные ЧПУ-маршруты установлены.',
            ];
        }

        return [
            'available' => true,
            'installed' => false,
            'rules' => $expectedRules,
            'message' => 'Публичные ЧПУ-маршруты ещё не установлены.',
        ];
    }

    public static function install(string $basePath): array
    {
        if (!class_exists('CUrlRewriter')) {
            throw new RuntimeException('CUrlRewriter недоступен');
        }

        $expectedRules = self::expectedRules($basePath);

        /*
         * Удаляем только правило с собственным ID. Остальные маршруты портала
         * не затрагиваются, даже если они ведут в тот же каталог.
         */
        foreach ($expectedRules as $expected) {
            \CUrlRewriter::Delete([
                'SITE_ID' => self::siteId(),
                'ID' => (string)$expected['ID'],
            ]);

            \CUrlRewriter::Add($expected);
        }

        $status = self::status($basePath);

        if (empty($status['installed'])) {
            throw new RuntimeException(
                'Битрикс24 не подтвердил запись правила в urlrewrite.php'
            );
        }

        return $status;
    }

    private static function expectedRules(string $basePath): array
    {
        $basePath = '/' . trim($basePath, '/');
        $escapedBasePath = preg_quote($basePath, '#');

        return [
            [
                'SITE_ID' => self::siteId(),
                'CONDITION' => '#^' . $escapedBasePath . '/s/([^/]+)/sitemap[.]xml$#',
                'RULE' => 'siteSlug=$1',
                'ID' => self::SITEMAP_RULE_ID,
                'PATH' => $basePath . '/sitemap.php',
                'SORT' => 90,
            ],
            [
                'SITE_ID' => self::siteId(),
                'CONDITION' => '#^' . $escapedBasePath . '/s/([^/]+)(?:/(.*))?/?$#',
                'RULE' => 'siteSlug=$1&pagePath=$2',
                'ID' => self::PUBLIC_RULE_ID,
                'PATH' => $basePath . '/public.php',
                'SORT' => 100,
            ],
        ];
    }

    private static function sameRule(array $actual, array $expected): bool
    {
        foreach (['CONDITION', 'RULE', 'ID', 'PATH'] as $field) {
            if ((string)($actual[$field] ?? '') !== (string)$expected[$field]) {
                return false;
            }
        }

        return true;
    }

    private static function siteId(): string
    {
        return defined('SITE_ID') && (string)SITE_ID !== ''
            ? (string)SITE_ID
            : 's1';
    }
}
