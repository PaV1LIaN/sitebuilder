<?php

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);

require_once $_SERVER['DOCUMENT_ROOT']
    . '/bitrix/modules/main/include/prolog_before.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/storage_db.php';
require_once __DIR__ . '/lib/storage_db_extra.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/PageAccessRepository.php';
require_once __DIR__ . '/lib/PageAccessService.php';
require_once __DIR__ . '/lib/public_render.php';

global $USER;

sitebuilder_require_auth();

header(
    'Content-Type: text/html; charset=UTF-8'
);

$basePath =
    rtrim(
        str_replace(
            $_SERVER['DOCUMENT_ROOT'],
            '',
            __DIR__
        ),
        '/'
    );

$siteId =
    (int)(
        $_GET['siteId']
        ?? 0
    );

$pageId =
    (int)(
        $_GET['pageId']
        ?? 0
    );

if ($siteId <= 0) {
    http_response_code(400);
    exit('siteId required');
}

/*
 * layout.php is available to content managers only. Keep the embedded
 * preview at the same permission level: it can intentionally preview a
 * draft page and must therefore not behave like a public endpoint.
 */
if (!$USER->IsAdmin()) {
    sb_require_content_manager(
        $siteId
    );
}

if (!function_exists('sb_layout_preview_bool')) {
    function sb_layout_preview_bool(
        string $key,
        bool $fallback
    ): bool {
        if (
            !array_key_exists(
                $key,
                $_GET
            )
        ) {
            return $fallback;
        }

        $value =
            strtolower(
                trim(
                    (string)$_GET[$key]
                )
            );

        if (
            in_array(
                $value,
                [
                    '1',
                    'true',
                    'yes',
                    'on',
                ],
                true
            )
        ) {
            return true;
        }

        if (
            in_array(
                $value,
                [
                    '0',
                    'false',
                    'no',
                    'off',
                ],
                true
            )
        ) {
            return false;
        }

        return $fallback;
    }
}

if (!function_exists('sb_layout_preview_all_pages')) {
    function sb_layout_preview_all_pages(
        int $siteId
    ): array {
        $pages =
            array_values(
                array_filter(
                    sb_read_pages(),
                    static function ($page) use ($siteId): bool {
                        return
                            is_array($page)
                            && (int)(
                                $page['siteId']
                                ?? 0
                            ) === $siteId;
                    }
                )
            );

        $pages =
            array_map(
                'sb_normalize_page_record',
                $pages
            );

        usort(
            $pages,
            static function (
                array $a,
                array $b
            ): int {
                $sort =
                    (int)(
                        $a['sort']
                        ?? 500
                    )
                    <=>
                    (int)(
                        $b['sort']
                        ?? 500
                    );

                return $sort !== 0
                    ? $sort
                    : (
                        (int)(
                            $a['id']
                            ?? 0
                        )
                        <=>
                        (int)(
                            $b['id']
                            ?? 0
                        )
                    );
            }
        );

        return $pages;
    }
}

if (!function_exists('sb_layout_preview_find_page')) {
    function sb_layout_preview_find_page(
        array $pages,
        int $pageId
    ): ?array {
        foreach ($pages as $page) {
            if (
                (int)(
                    $page['id']
                    ?? 0
                ) === $pageId
            ) {
                return $page;
            }
        }

        return null;
    }
}

if (!function_exists('sb_layout_preview_navigation_pages')) {
    function sb_layout_preview_navigation_pages(
        int $siteId,
        array $allPages,
        ?array $currentPage
    ): array {
        /*
         * Start with exactly the pages which the real public view would
         * expose. Then add the selected draft and its ancestors solely so
         * breadcrumbs/section navigation can describe that draft page.
         */
        $result = [];
        $allMap = [];

        foreach ($allPages as $page) {
            $id =
                (int)(
                    $page['id']
                    ?? 0
                );

            if ($id > 0) {
                $allMap[$id] =
                    $page;
            }
        }

        foreach (
            sb_public_pages_for_site(
                $siteId
            )
            as $page
        ) {
            $id =
                (int)(
                    $page['id']
                    ?? 0
                );

            if ($id > 0) {
                $result[$id] =
                    $page;
            }
        }

        $cursor =
            $currentPage;

        $seen = [];

        while ($cursor) {
            $id =
                (int)(
                    $cursor['id']
                    ?? 0
                );

            if (
                $id <= 0
                || isset(
                    $seen[$id]
                )
            ) {
                break;
            }

            $seen[$id] = true;
            $result[$id] =
                $cursor;

            $parentId =
                (int)(
                    $cursor['parentId']
                    ?? 0
                );

            if (
                $parentId <= 0
                || !isset(
                    $allMap[$parentId]
                )
            ) {
                break;
            }

            $cursor =
                $allMap[$parentId];
        }

        $pages =
            array_values(
                $result
            );

        usort(
            $pages,
            static function (
                array $a,
                array $b
            ): int {
                $sort =
                    (int)(
                        $a['sort']
                        ?? 500
                    )
                    <=>
                    (int)(
                        $b['sort']
                        ?? 500
                    );

                return $sort !== 0
                    ? $sort
                    : (
                        (int)(
                            $a['id']
                            ?? 0
                        )
                        <=>
                        (int)(
                            $b['id']
                            ?? 0
                        )
                    );
            }
        );

        return $pages;
    }
}

if (!function_exists('sb_layout_preview_view_model')) {
    function sb_layout_preview_view_model(
        int $siteId,
        int $requestedPageId,
        string $basePath
    ): ?array {
        $site =
            sb_public_find_site(
                $siteId
            );

        if (!$site) {
            return null;
        }

        $allPages =
            sb_layout_preview_all_pages(
                $siteId
            );

        $currentPage =
            $requestedPageId > 0
                ? sb_layout_preview_find_page(
                    $allPages,
                    $requestedPageId
                )
                : null;

        if (!$currentPage) {
            $homePageId =
                (int)(
                    $site['homePageId']
                    ?? 0
                );

            if ($homePageId > 0) {
                $currentPage =
                    sb_layout_preview_find_page(
                        $allPages,
                        $homePageId
                    );
            }
        }

        if (
            !$currentPage
            && $allPages
        ) {
            $currentPage =
                $allPages[0];
        }

        if (!$currentPage) {
            return null;
        }

        $pages =
            sb_layout_preview_navigation_pages(
                $siteId,
                $allPages,
                $currentPage
            );

        $layout =
            sb_public_layout_for_site(
                $siteId
            );

        $layoutSettings =
            is_array(
                $layout['settings']
                ?? null
            )
                ? $layout['settings']
                : [];

        /*
         * Draft layout settings are passed from layout.php only for this
         * authenticated iframe. They are validated independently and never
         * written to the database by the preview endpoint.
         */
        $layoutSettings['showHeader'] =
            sb_layout_preview_bool(
                'showHeader',
                !empty(
                    $layoutSettings[
                        'showHeader'
                    ]
                )
            );

        $layoutSettings['showFooter'] =
            sb_layout_preview_bool(
                'showFooter',
                !empty(
                    $layoutSettings[
                        'showFooter'
                    ]
                )
            );

        $layoutSettings['showLeft'] =
            sb_layout_preview_bool(
                'showLeft',
                !empty(
                    $layoutSettings[
                        'showLeft'
                    ]
                )
            );

        $layoutSettings['showRight'] =
            sb_layout_preview_bool(
                'showRight',
                !empty(
                    $layoutSettings[
                        'showRight'
                    ]
                )
            );

        if (
            array_key_exists(
                'leftWidth',
                $_GET
            )
        ) {
            $layoutSettings[
                'leftWidth'
            ] =
                max(
                    120,
                    min(
                        800,
                        (int)$_GET[
                            'leftWidth'
                        ]
                    )
                );
        }

        if (
            array_key_exists(
                'rightWidth',
                $_GET
            )
        ) {
            $layoutSettings[
                'rightWidth'
            ] =
                max(
                    120,
                    min(
                        800,
                        (int)$_GET[
                            'rightWidth'
                        ]
                    )
                );
        }

        if (
            array_key_exists(
                'leftMode',
                $_GET
            )
        ) {
            $leftMode =
                trim(
                    (string)$_GET[
                        'leftMode'
                    ]
                );

            $layoutSettings[
                'leftMode'
            ] =
                in_array(
                    $leftMode,
                    [
                        'blocks',
                        'menu',
                    ],
                    true
                )
                    ? $leftMode
                    : 'blocks';
        }

        $layout['settings'] =
            $layoutSettings;

        $menu =
            sb_public_filter_menu_pages(
                sb_public_menu_for_site(
                    $site
                ),
                $pages
            );

        $pageBlocks =
            sb_public_page_blocks(
                (int)$currentPage[
                    'id'
                ]
            );

        $pageSections =
            sb_public_page_sections(
                $siteId,
                (int)$currentPage[
                    'id'
                ]
            );

        $settings =
            isset($site['settings'])
            && is_array(
                $site['settings']
            )
                ? $site['settings']
                : [];

        $containerWidth =
            max(
                320,
                min(
                    1920,
                    (int)(
                        $settings[
                            'containerWidth'
                        ]
                        ?? 1360
                    )
                )
            );

        $accent =
            (string)(
                $settings['accent']
                ?? '#2563eb'
            );

        if (
            !preg_match(
                '/^#[0-9a-fA-F]{6}$/',
                $accent
            )
        ) {
            $accent =
                '#2563eb';
        }

        $breadcrumbs =
            sb_public_breadcrumbs(
                $pages,
                $currentPage
            );

        $sectionNavHtml =
            sb_public_render_section_nav(
                $pages,
                $currentPage,
                $basePath,
                $siteId
            );

        $childPagesHtml =
            sb_public_render_child_pages(
                $pages,
                $currentPage,
                $basePath,
                $siteId
            );

        return [
            'site' => $site,
            'pages' => $pages,
            'currentPage' =>
                $currentPage,
            'pageBlocks' =>
                $pageBlocks,
            'pageSections' =>
                $pageSections,
            'layout' => $layout,
            'menu' => $menu,
            'basePath' =>
                $basePath,
            'siteId' => $siteId,
            'containerWidth' =>
                $containerWidth,
            'accent' => $accent,
            'showHeader' =>
                !empty(
                    $layoutSettings[
                        'showHeader'
                    ]
                ),
            'showFooter' =>
                !empty(
                    $layoutSettings[
                        'showFooter'
                    ]
                ),
            'showLeft' =>
                !empty(
                    $layoutSettings[
                        'showLeft'
                    ]
                ),
            'showRight' =>
                !empty(
                    $layoutSettings[
                        'showRight'
                    ]
                ),
            'leftWidth' =>
                max(
                    120,
                    min(
                        800,
                        (int)(
                            $layoutSettings[
                                'leftWidth'
                            ]
                            ?? 260
                        )
                    )
                ),
            'rightWidth' =>
                max(
                    120,
                    min(
                        800,
                        (int)(
                            $layoutSettings[
                                'rightWidth'
                            ]
                            ?? 260
                        )
                    )
                ),
            'leftMode' =>
                (string)(
                    $layoutSettings[
                        'leftMode'
                    ]
                    ?? 'blocks'
                ),
            'breadcrumbs' =>
                $breadcrumbs,
            'breadcrumbsHtml' =>
                sb_public_render_breadcrumbs(
                    $breadcrumbs,
                    $basePath,
                    $siteId
                ),
            'sectionNavHtml' =>
                $sectionNavHtml,
            'childPagesHtml' =>
                $childPagesHtml,
            'layoutPreview' => true,
            'previewPageStatus' =>
                (string)(
                    $currentPage[
                        'status'
                    ]
                    ?? 'draft'
                ),
        ];
    }
}

$vm =
    sb_layout_preview_view_model(
        $siteId,
        $pageId,
        $basePath
    );

if (!$vm) {
    http_response_code(404);

    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <style>
            body {
                margin: 0;
                padding: 28px;
                font-family: Arial, sans-serif;
                color: #475569;
                background: #f8fafc;
            }
        </style>
    </head>
    <body>
        <strong>Нет страницы для предпросмотра.</strong>
    </body>
    </html>
    <?php

    exit;
}

/*
 * Render the exact same public template and CSS/JS, then add only a tiny
 * preview guard. This keeps the visual result tied to the real public view.
 */
ob_start();

include __DIR__
    . '/views/layout/public_page.php';

$html =
    (string)ob_get_clean();

$previewCss = <<<'HTML'
<style id="sb-layout-preview-guard">
html {
    scroll-behavior: auto !important;
}
body {
    margin: 0 !important;
}
.sb-motion,
[data-sb-animate] {
    opacity: 1 !important;
    transform: none !important;
    animation: none !important;
}
a,
button,
input,
select,
textarea,
label {
    cursor: default !important;
}
</style>
HTML;

$previewJs = <<<'HTML'
<script id="sb-layout-preview-guard-script">
(function () {
    'use strict';

    document.documentElement.classList.add(
        'sb-layout-preview-mode'
    );

    document.addEventListener(
        'click',
        function (event) {
            if (
                event.target.closest(
                    'a,button,[role="button"]'
                )
            ) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        },
        true
    );

    document.addEventListener(
        'submit',
        function (event) {
            event.preventDefault();
            event.stopImmediatePropagation();
        },
        true
    );
})();
</script>
HTML;

$html =
    preg_replace(
        '/<\/head>/i',
        $previewCss
        . "\n</head>",
        $html,
        1
    ) ?? $html;

$html =
    preg_replace(
        '/<\/body>/i',
        $previewJs
        . "\n</body>",
        $html,
        1
    ) ?? $html;

echo $html;
