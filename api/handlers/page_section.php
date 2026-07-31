<?php

global $USER;

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/PageSectionRepository.php';

if (!function_exists('sb_page_section_parse_array')) {
    function sb_page_section_parse_array($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('sb_page_section_find_page')) {
    function sb_page_section_find_page(int $siteId, int $pageId): ?array
    {
        foreach (sb_read_pages() as $page) {
            if (
                (int)($page['id'] ?? 0) === $pageId &&
                (int)($page['siteId'] ?? 0) === $siteId
            ) {
                return $page;
            }
        }

        return null;
    }
}

if (!function_exists('sb_page_section_require_page')) {
    function sb_page_section_require_page(int $siteId, int $pageId): array
    {
        if ($siteId <= 0) {
            sb_json_error('SITE_ID_REQUIRED', 422);
        }

        if ($pageId <= 0) {
            sb_json_error('PAGE_ID_REQUIRED', 422);
        }

        $page = sb_page_section_find_page($siteId, $pageId);

        if (!$page) {
            sb_json_error('PAGE_NOT_FOUND', 404);
        }

        return $page;
    }
}


if (!function_exists('sb_page_section_preset_definition')) {
    function sb_page_section_preset_definition(string $presetKey): ?array
    {
        $presets = [
            'hero_split' => [
                'title' => 'Первый экран',
                'layout' => [
                    'container' => 'wide',
                    'columns' => 1,
                    'tabletColumns' => 1,
                    'mobileColumns' => 1,
                    'gap' => 24,
                    'verticalAlign' => 'center',
                ],
                'props' => [
                    'backgroundColor' => '#eff6ff',
                    'textColor' => '#0f172a',
                    'paddingTop' => 56,
                    'paddingBottom' => 56,
                    'paddingX' => 32,
                    'borderRadius' => 28,
                    'shadow' => false,
                ],
                'blocks' => [
                    [
                        'type' => 'hero',
                        'column' => 1,
                        'content' => [
                            'eyebrow' => 'Новый раздел',
                            'title' => 'Расскажите о главной ценности страницы',
                            'text' => 'Короткое описание помогает посетителю понять предложение и выбрать следующий шаг.',
                            'primaryLabel' => 'Подробнее',
                            'primaryHref' => '#',
                            'secondaryLabel' => '',
                            'secondaryHref' => '',
                            'imageSrc' => '',
                            'imageAlt' => '',
                        ],
                        'props' => [
                            'theme' => 'soft',
                            'align' => 'left',
                            'imagePosition' => 'right',
                            'minHeight' => 420,
                            'radius' => 24,
                        ],
                    ],
                ],
            ],
            'benefits_cards' => [
                'title' => 'Преимущества',
                'layout' => [
                    'container' => 'default',
                    'columns' => 1,
                    'tabletColumns' => 1,
                    'mobileColumns' => 1,
                    'gap' => 24,
                    'verticalAlign' => 'start',
                ],
                'props' => [
                    'backgroundColor' => '#ffffff',
                    'textColor' => '#0f172a',
                    'paddingTop' => 56,
                    'paddingBottom' => 56,
                    'paddingX' => 24,
                    'borderRadius' => 0,
                    'shadow' => false,
                ],
                'blocks' => [
                    [
                        'type' => 'heading',
                        'column' => 1,
                        'content' => ['text' => 'Почему выбирают нас'],
                        'props' => ['level' => 'h2', 'align' => 'center', 'size' => 40, 'maxWidth' => 760],
                    ],
                    [
                        'type' => 'text',
                        'column' => 1,
                        'content' => ['text' => '<p>Коротко опишите преимущества продукта, подразделения или сервиса.</p>'],
                        'props' => ['align' => 'center', 'size' => 18, 'lineHeight' => 1.65, 'maxWidth' => 720],
                    ],
                    [
                        'type' => 'cards',
                        'column' => 1,
                        'content' => [
                            'title' => '',
                            'items' => [
                                ['title' => 'Преимущество 1', 'text' => 'Раскройте пользу для пользователя.', 'imageSrc' => '', 'href' => '', 'buttonText' => ''],
                                ['title' => 'Преимущество 2', 'text' => 'Добавьте конкретный аргумент.', 'imageSrc' => '', 'href' => '', 'buttonText' => ''],
                                ['title' => 'Преимущество 3', 'text' => 'Завершите блок сильным фактом.', 'imageSrc' => '', 'href' => '', 'buttonText' => ''],
                            ],
                        ],
                        'props' => ['columns' => 3, 'style' => 'elevated', 'imageRatio' => '16:9', 'align' => 'left'],
                    ],
                ],
            ],
            'image_text' => [
                'title' => 'Изображение и текст',
                'layout' => [
                    'container' => 'default',
                    'columns' => 2,
                    'tabletColumns' => 2,
                    'mobileColumns' => 1,
                    'gap' => 40,
                    'verticalAlign' => 'center',
                ],
                'props' => [
                    'backgroundColor' => '#f8fafc',
                    'textColor' => '#0f172a',
                    'paddingTop' => 48,
                    'paddingBottom' => 48,
                    'paddingX' => 28,
                    'borderRadius' => 24,
                    'shadow' => false,
                ],
                'blocks' => [
                    [
                        'type' => 'image',
                        'column' => 1,
                        'content' => ['src' => '', 'alt' => 'Изображение раздела', 'caption' => '', 'href' => ''],
                        'props' => ['ratio' => '4:3', 'fit' => 'cover', 'align' => 'center', 'width' => 100, 'radius' => 20, 'shadow' => true],
                    ],
                    [
                        'type' => 'heading',
                        'column' => 2,
                        'content' => ['text' => 'Заголовок смыслового раздела'],
                        'props' => ['level' => 'h2', 'align' => 'left', 'size' => 38, 'maxWidth' => 620],
                    ],
                    [
                        'type' => 'text',
                        'column' => 2,
                        'content' => ['text' => '<p>Раскройте тему подробнее. Используйте несколько абзацев, списки и ссылки.</p>'],
                        'props' => ['align' => 'left', 'size' => 17, 'lineHeight' => 1.7, 'maxWidth' => 620],
                    ],
                    [
                        'type' => 'button',
                        'column' => 2,
                        'content' => ['label' => 'Узнать больше', 'href' => '#', 'target' => '_self'],
                        'props' => ['style' => 'primary', 'size' => 'medium', 'align' => 'left', 'fullWidth' => false],
                    ],
                ],
            ],
            'stats_band' => [
                'title' => 'Показатели',
                'layout' => [
                    'container' => 'wide',
                    'columns' => 1,
                    'tabletColumns' => 1,
                    'mobileColumns' => 1,
                    'gap' => 20,
                    'verticalAlign' => 'center',
                ],
                'props' => [
                    'backgroundColor' => '#0f172a',
                    'textColor' => '#ffffff',
                    'paddingTop' => 48,
                    'paddingBottom' => 48,
                    'paddingX' => 32,
                    'borderRadius' => 24,
                    'shadow' => true,
                ],
                'blocks' => [
                    [
                        'type' => 'stats',
                        'column' => 1,
                        'content' => [
                            'title' => 'Результаты в цифрах',
                            'items' => [
                                ['value' => '24/7', 'label' => 'Доступность'],
                                ['value' => '99%', 'label' => 'Точность'],
                                ['value' => '10+', 'label' => 'Готовых сценариев'],
                                ['value' => '1', 'label' => 'Единое пространство'],
                            ],
                        ],
                        'props' => ['columns' => 4, 'style' => 'plain'],
                    ],
                ],
            ],
            'call_to_action' => [
                'title' => 'Призыв к действию',
                'layout' => [
                    'container' => 'default',
                    'columns' => 1,
                    'tabletColumns' => 1,
                    'mobileColumns' => 1,
                    'gap' => 18,
                    'verticalAlign' => 'center',
                ],
                'props' => [
                    'backgroundColor' => '#2563eb',
                    'textColor' => '#ffffff',
                    'paddingTop' => 52,
                    'paddingBottom' => 52,
                    'paddingX' => 32,
                    'borderRadius' => 26,
                    'shadow' => true,
                ],
                'blocks' => [
                    [
                        'type' => 'heading',
                        'column' => 1,
                        'content' => ['text' => 'Готовы сделать следующий шаг?'],
                        'props' => ['level' => 'h2', 'align' => 'center', 'color' => '#ffffff', 'size' => 38, 'maxWidth' => 760],
                    ],
                    [
                        'type' => 'text',
                        'column' => 1,
                        'content' => ['text' => '<p>Добавьте короткое пояснение и понятный призыв к действию.</p>'],
                        'props' => ['align' => 'center', 'color' => '#dbeafe', 'size' => 18, 'lineHeight' => 1.6, 'maxWidth' => 680],
                    ],
                    [
                        'type' => 'button',
                        'column' => 1,
                        'content' => ['label' => 'Перейти', 'href' => '#', 'target' => '_self'],
                        'props' => ['style' => 'secondary', 'size' => 'large', 'align' => 'center', 'fullWidth' => false],
                    ],
                ],
            ],
            'quote_story' => [
                'title' => 'Цитата',
                'layout' => [
                    'container' => 'default',
                    'columns' => 1,
                    'tabletColumns' => 1,
                    'mobileColumns' => 1,
                    'gap' => 20,
                    'verticalAlign' => 'center',
                ],
                'props' => [
                    'backgroundColor' => '#fff7ed',
                    'textColor' => '#431407',
                    'paddingTop' => 48,
                    'paddingBottom' => 48,
                    'paddingX' => 32,
                    'borderRadius' => 24,
                    'shadow' => false,
                ],
                'blocks' => [
                    [
                        'type' => 'quote',
                        'column' => 1,
                        'content' => [
                            'text' => 'Здесь можно разместить отзыв, обращение руководителя или важную мысль.',
                            'author' => 'Имя автора',
                            'role' => 'Должность',
                        ],
                        'props' => ['style' => 'accent', 'align' => 'left', 'accentColor' => '#ea580c'],
                    ],
                ],
            ],
        ];

        return $presets[$presetKey] ?? null;
    }
}

if (!function_exists('sb_page_section_create_preset_blocks')) {
    function sb_page_section_create_preset_blocks(
        int $pageId,
        int $sectionId,
        array $blockDefinitions,
        int $userId
    ): array {
        $allBlocks = sb_read_blocks();
        $created = [];
        $nextSort = sb_next_block_sort($pageId, $allBlocks);

        foreach ($blockDefinitions as $definition) {
            $type = trim((string)($definition['type'] ?? 'text'));
            if ($type === '') {
                continue;
            }

            $column = max(1, min(4, (int)($definition['column'] ?? 1)));
            $props = is_array($definition['props'] ?? null) ? $definition['props'] : [];
            $props['sectionId'] = $sectionId;
            $props['column'] = $column;
            $props['_placement'] = [
                'sectionId' => $sectionId,
                'column' => $column,
            ];

            $block = [
                'id' => sb_next_block_id($allBlocks),
                'pageId' => $pageId,
                'type' => $type,
                'sort' => $nextSort,
                'content' => is_array($definition['content'] ?? null)
                    ? $definition['content']
                    : sb_default_block_content($type),
                'props' => $props,
                'createdBy' => $userId,
                'createdAt' => date('c'),
                'updatedAt' => date('c'),
                'updatedBy' => $userId,
            ];

            $created[] = $block;
            $allBlocks[] = $block;
            $nextSort += 10;
        }

        if ($created !== []) {
            sb_write_blocks($created);
        }

        return array_map('sb_normalize_block_record', $created);
    }
}

if ($action === 'pageSection.list') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $pageId = (int)($_POST['pageId'] ?? 0);

    sb_page_section_require_page($siteId, $pageId);
    sb_require_content_manager($siteId);

    $defaultSection = PageSectionRepository::ensureDefaultForPage(
        $siteId,
        $pageId,
        (int)$USER->GetID()
    );

    sb_json_ok([
        'defaultSection' => $defaultSection,
        'sections' => PageSectionRepository::listForPage($siteId, $pageId),
    ]);
}


if ($action === 'pageSection.createPreset') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $pageId = (int)($_POST['pageId'] ?? 0);
    $presetKey = trim((string)($_POST['presetKey'] ?? ''));

    sb_page_section_require_page($siteId, $pageId);
    sb_require_content_manager($siteId);

    $preset = sb_page_section_preset_definition($presetKey);
    if ($preset === null) {
        sb_json_error('SECTION_PRESET_NOT_FOUND', 404);
    }

    $userId = (int)$USER->GetID();
    $section = PageSectionRepository::create(
        $siteId,
        $pageId,
        (string)($preset['title'] ?? 'Готовая секция'),
        is_array($preset['layout'] ?? null) ? $preset['layout'] : [],
        is_array($preset['props'] ?? null) ? $preset['props'] : [],
        $userId
    );

    $blocks = sb_page_section_create_preset_blocks(
        $pageId,
        (int)$section['id'],
        is_array($preset['blocks'] ?? null) ? $preset['blocks'] : [],
        $userId
    );

    sb_json_ok([
        'presetKey' => $presetKey,
        'section' => $section,
        'sections' => PageSectionRepository::listForPage($siteId, $pageId),
        'blocks' => $blocks,
    ]);
}

if ($action === 'pageSection.create') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $pageId = (int)($_POST['pageId'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $layout = sb_page_section_parse_array($_POST['layout'] ?? []);
    $props = sb_page_section_parse_array($_POST['props'] ?? []);

    sb_page_section_require_page($siteId, $pageId);
    sb_require_content_manager($siteId);

    $section = PageSectionRepository::create(
        $siteId,
        $pageId,
        $title,
        $layout,
        $props,
        (int)$USER->GetID()
    );

    sb_json_ok([
        'section' => $section,
        'sections' => PageSectionRepository::listForPage($siteId, $pageId),
    ]);
}

if ($action === 'pageSection.update') {
    $sectionId = (int)($_POST['sectionId'] ?? $_POST['id'] ?? 0);

    if ($sectionId <= 0) {
        sb_json_error('PAGE_SECTION_ID_REQUIRED', 422);
    }

    $section = PageSectionRepository::getById($sectionId);

    if (!$section) {
        sb_json_error('PAGE_SECTION_NOT_FOUND', 404);
    }

    $siteId = (int)$section['siteId'];
    sb_require_content_manager($siteId);

    $fields = [];

    if (array_key_exists('title', $_POST)) {
        $fields['title'] = (string)$_POST['title'];
    }

    if (array_key_exists('layout', $_POST)) {
        $fields['layout'] = sb_page_section_parse_array($_POST['layout']);
    }

    if (array_key_exists('props', $_POST)) {
        $fields['props'] = sb_page_section_parse_array($_POST['props']);
    }

    try {
        $updated = PageSectionRepository::update(
            $sectionId,
            $fields,
            (int)$USER->GetID(),
            RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null)
        );

        sb_json_ok([
            'section' => $updated,
            'sections' => PageSectionRepository::listForPage(
                (int)$updated['siteId'],
                (int)$updated['pageId']
            ),
        ]);
    } catch (SiteBuilderVersionConflictException|InvalidArgumentException $e) {
        throw $e;
    } catch (RuntimeException $e) {
        $known = ['PAGE_SECTION_NOT_FOUND'];
        if (in_array($e->getMessage(), $known, true)) {
            sb_json_error($e->getMessage(), 422);
        }
        throw $e;
    }
}

if ($action === 'pageSection.move') {
    $sectionId = (int)($_POST['sectionId'] ?? $_POST['id'] ?? 0);
    $dir = trim((string)($_POST['dir'] ?? ''));

    if ($sectionId <= 0) {
        sb_json_error('PAGE_SECTION_ID_REQUIRED', 422);
    }

    $section = PageSectionRepository::getById($sectionId);

    if (!$section) {
        sb_json_error('PAGE_SECTION_NOT_FOUND', 404);
    }

    sb_require_content_manager((int)$section['siteId']);

    try {
        $moved = PageSectionRepository::move(
            $sectionId,
            $dir,
            (int)$USER->GetID(),
            RevisionService::decodeVersionMap($_POST['expectedVersions'] ?? null)
        );

        sb_json_ok([
            'moved' => $moved,
            'sections' => PageSectionRepository::listForPage(
                (int)$section['siteId'],
                (int)$section['pageId']
            ),
        ]);
    } catch (SiteBuilderVersionConflictException|InvalidArgumentException $e) {
        throw $e;
    } catch (RuntimeException $e) {
        $known = ['INVALID_DIR', 'PAGE_SECTION_NOT_FOUND', 'PAGE_SECTION_NOT_FOUND_IN_SIBLINGS'];
        if (in_array($e->getMessage(), $known, true)) {
            sb_json_error($e->getMessage(), 422);
        }
        throw $e;
    }
}

if ($action === 'pageSection.reorder') {
    $siteId = (int)($_POST['siteId'] ?? 0);
    $pageId = (int)($_POST['pageId'] ?? 0);
    $order = sb_page_section_parse_array($_POST['order'] ?? []);

    sb_page_section_require_page($siteId, $pageId);
    sb_require_content_manager($siteId);

    try {
        $sections = PageSectionRepository::reorder(
            $siteId,
            $pageId,
            $order,
            (int)$USER->GetID(),
            RevisionService::decodeVersionMap($_POST['expectedVersions'] ?? null)
        );

        sb_json_ok([
            'reordered' => true,
            'sections' => $sections,
        ]);
    } catch (SiteBuilderVersionConflictException|InvalidArgumentException $e) {
        throw $e;
    } catch (RuntimeException $e) {
        $known = [
            'PAGE_SECTION_CONTEXT_REQUIRED',
            'PAGE_SECTION_NOT_IN_PAGE',
        ];
        if (in_array($e->getMessage(), $known, true)) {
            sb_json_error($e->getMessage(), 422);
        }
        throw $e;
    }
}

if ($action === 'pageSection.delete') {
    $sectionId = (int)($_POST['sectionId'] ?? $_POST['id'] ?? 0);

    if ($sectionId <= 0) {
        sb_json_error('PAGE_SECTION_ID_REQUIRED', 422);
    }

    $section = PageSectionRepository::getById($sectionId);

    if (!$section) {
        sb_json_error('PAGE_SECTION_NOT_FOUND', 404);
    }

    sb_require_content_manager((int)$section['siteId']);

    try {
        PageSectionRepository::delete(
            $sectionId,
            (int)$USER->GetID(),
            RevisionService::requireExpectedVersion($_POST['expectedVersion'] ?? null)
        );

        sb_json_ok([
            'deleted' => true,
            'id' => $sectionId,
            'siteId' => (int)$section['siteId'],
            'pageId' => (int)$section['pageId'],
            'sections' => PageSectionRepository::listForPage(
                (int)$section['siteId'],
                (int)$section['pageId']
            ),
        ]);
    } catch (SiteBuilderVersionConflictException|InvalidArgumentException $e) {
        throw $e;
    } catch (RuntimeException $e) {
        $known = ['PAGE_SECTION_NOT_FOUND', 'CANNOT_DELETE_LAST_SECTION', 'TARGET_SECTION_NOT_FOUND'];
        if (in_array($e->getMessage(), $known, true)) {
            sb_json_error($e->getMessage(), 422);
        }
        throw $e;
    }
}

if ($action === 'pageSection.assignBlock') {
    $blockId = (int)($_POST['blockId'] ?? 0);
    $sectionId = (int)($_POST['sectionId'] ?? 0);
    $column = (int)($_POST['column'] ?? 1);

    if ($blockId <= 0) {
        sb_json_error('BLOCK_ID_REQUIRED', 422);
    }

    if ($sectionId <= 0) {
        sb_json_error('PAGE_SECTION_ID_REQUIRED', 422);
    }

    $section = PageSectionRepository::getById($sectionId);

    if (!$section) {
        sb_json_error('PAGE_SECTION_NOT_FOUND', 404);
    }

    sb_require_content_manager((int)$section['siteId']);

    $expectedVersion = RevisionService::requireExpectedVersion(
        $_POST['expectedVersion'] ?? null
    );

    $block = PageSectionRepository::assignBlock(
        $blockId,
        $sectionId,
        $column,
        (int)$USER->GetID(),
        $expectedVersion
    );

    sb_json_ok([
        'block' => $block,
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'page_section',
    'action' => $action,
]);
