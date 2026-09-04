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

        $rules = self::allRules();
        $installedCount = 0;

        foreach ($expectedRules as $expected) {
            foreach ($rules as $item) {
                if (is_array($item) && self::sameRule($item, $expected)) {
                    $installedCount++;
                    break;
                }
            }
        }

        $basePath = '/' . trim($basePath, '/');
        $probes = [
            [
                'url' => $basePath . '/s/site-slug/sitemap.xml',
                'id' => self::SITEMAP_RULE_ID,
                'path' => $basePath . '/sitemap.php',
            ],
            [
                'url' => $basePath . '/s/site-slug/page-slug/',
                'id' => self::PUBLIC_RULE_ID,
                'path' => $basePath . '/public.php',
            ],
        ];

        $interceptedBy = null;

        foreach ($probes as $probe) {
            $firstMatch = self::firstMatchingRule($rules, (string)$probe['url']);

            if (
                !is_array($firstMatch)
                || (string)($firstMatch['ID'] ?? '') !== (string)$probe['id']
                || (string)($firstMatch['PATH'] ?? '') !== (string)$probe['path']
            ) {
                $interceptedBy = is_array($firstMatch) ? $firstMatch : [];
                break;
            }
        }

        if ($installedCount === count($expectedRules) && $interceptedBy === null) {
            return [
                'available' => true,
                'installed' => true,
                'rules' => $expectedRules,
                'message' => 'Публичные ЧПУ-маршруты установлены.',
            ];
        }

        $interceptedPath = (string)($interceptedBy['PATH'] ?? '');

        return [
            'available' => true,
            'installed' => false,
            'rules' => $expectedRules,
            'interceptedBy' => $interceptedBy,
            'message' => $interceptedPath !== ''
                ? 'Публичный URL перехватывает правило портала: ' . $interceptedPath . '.'
                : 'Публичные ЧПУ-маршруты ещё не установлены.',
        ];
    }

    public static function install(string $basePath): array
    {
        if (!class_exists('CUrlRewriter')) {
            throw new RuntimeException('CUrlRewriter недоступен');
        }

        $expectedRules = self::expectedRules($basePath);
        $basePath = '/' . trim($basePath, '/');

        /*
         * Удаляем собственные правила и старый переход через router.php.
         * Остальные маршруты портала не затрагиваются.
         */
        \CUrlRewriter::Delete([
            'SITE_ID' => self::siteId(),
            'PATH' => $basePath . '/router.php',
        ]);

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
                (string)($status['message'] ?? 'Битрикс24 не подтвердил запись правила в urlrewrite.php')
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
                'SORT' => 1,
            ],
            [
                'SITE_ID' => self::siteId(),
                'CONDITION' => '#^' . $escapedBasePath . '/s/([^/]+)(?:/(.*))?/?$#',
                'RULE' => 'siteSlug=$1&pagePath=$2',
                'ID' => self::PUBLIC_RULE_ID,
                'PATH' => $basePath . '/public.php',
                'SORT' => 2,
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

        return (int)($actual['SORT'] ?? 100) === (int)($expected['SORT'] ?? 100);
    }

    private static function allRules(): array
    {
        $rules = \CUrlRewriter::GetList(
            ['SITE_ID' => self::siteId()],
            ['SORT' => 'ASC']
        );

        return array_values(array_filter((array)$rules, 'is_array'));
    }

    private static function firstMatchingRule(array $rules, string $url): ?array
    {
        foreach ($rules as $rule) {
            $condition = (string)($rule['CONDITION'] ?? '');

            if ($condition !== '' && @preg_match($condition, $url) === 1) {
                return $rule;
            }
        }

        return null;
    }

    private static function siteId(): string
    {
        return defined('SITE_ID') && (string)SITE_ID !== ''
            ? (string)SITE_ID
            : 's1';
    }
}
