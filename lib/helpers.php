<?php
    
require_once __DIR__ . '/json.php';

if (!function_exists('sb_normalize_page_record')) {
    function sb_normalize_page_record(array $page): array
    {
        return [
            'id' => (int)($page['id'] ?? 0),
            'siteId' => (int)($page['siteId'] ?? 0),
            'title' => trim((string)($page['title'] ?? '')),
            'slug' => trim((string)($page['slug'] ?? '')),
            'parentId' => (int)($page['parentId'] ?? 0),
            'sort' => (int)($page['sort'] ?? 500),
            'status' => in_array((string)($page['status'] ?? 'draft'), ['draft', 'published'], true)
                ? (string)($page['status'] ?? 'draft')
                : 'draft',
            'publishedAt' => !empty($page['publishedAt']) ? (string)$page['publishedAt'] : null,
            'seo' => is_array($page['seo'] ?? null) ? $page['seo'] : [],
            'createdBy' => isset($page['createdBy']) ? (int)$page['createdBy'] : 0,
            'createdAt' => !empty($page['createdAt']) ? (string)$page['createdAt'] : date('c'),
            'updatedBy' => isset($page['updatedBy']) ? (int)$page['updatedBy'] : 0,
            'updatedAt' => !empty($page['updatedAt']) ? (string)$page['updatedAt'] : date('c'),
            'version' => max(1, (int)($page['version'] ?? 1)),
        ];
    }
}

if (!function_exists('sb_normalize_block_record')) {
    function sb_normalize_block_record(array $block): array
    {
        return [
            'id' => (int)($block['id'] ?? 0),
            'pageId' => (int)($block['pageId'] ?? 0),
            'type' => trim((string)($block['type'] ?? 'text')),
            'sort' => (int)($block['sort'] ?? 500),
            'content' => is_array($block['content'] ?? null) ? $block['content'] : [],
            'props' => is_array($block['props'] ?? null) ? $block['props'] : [],
            'createdBy' => isset($block['createdBy']) ? (int)$block['createdBy'] : 0,
            'createdAt' => !empty($block['createdAt']) ? (string)$block['createdAt'] : date('c'),
            'updatedBy' => isset($block['updatedBy']) ? (int)$block['updatedBy'] : 0,
            'updatedAt' => !empty($block['updatedAt']) ? (string)$block['updatedAt'] : date('c'),
            'version' => max(1, (int)($block['version'] ?? 1)),
        ];
    }
}

if (!function_exists('sb_normalize_menu_record')) {
    function sb_normalize_menu_record(array $menu): array
    {
        $items = is_array($menu['items'] ?? null)
            ? array_values(array_filter($menu['items'], 'is_array'))
            : [];

        $items = array_map('sb_normalize_menu_item', $items);
        usort($items, static function (array $a, array $b): int {
            $sortCompare = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
            return $sortCompare !== 0
                ? $sortCompare
                : (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return [
            'id' => (int)($menu['id'] ?? 0),
            'siteId' => (int)($menu['siteId'] ?? 0),
            'name' => trim((string)($menu['name'] ?? '')),
            'items' => $items,
            'createdBy' => isset($menu['createdBy']) ? (int)$menu['createdBy'] : 0,
            'createdAt' => !empty($menu['createdAt']) ? (string)$menu['createdAt'] : date('c'),
            'updatedBy' => isset($menu['updatedBy']) ? (int)$menu['updatedBy'] : 0,
            'updatedAt' => !empty($menu['updatedAt']) ? (string)$menu['updatedAt'] : date('c'),
            'version' => max(1, (int)($menu['version'] ?? 1)),
        ];
    }
}


if (!function_exists('sb_slugify')) {
    function sb_slugify(string $name): string
    {
        $slug = \CUtil::translit($name, 'ru', [
            'replace_space' => '-',
            'replace_other' => '-',
            'change_case' => 'L',
            'delete_repeat_replace' => true,
            'use_google' => false,
        ]);

        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'item';
    }
}

if (!function_exists('sb_site_exists')) {
    function sb_site_exists(int $siteId): bool
    {
        foreach (sb_read_sites() as $s) {
            if ((int)($s['id'] ?? 0) === $siteId) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('sb_find_site')) {
    function sb_find_site(int $siteId): ?array
    {
        foreach (sb_read_sites() as $s) {
            if ((int)($s['id'] ?? 0) === $siteId) {
                return $s;
            }
        }
        return null;
    }
}

if (!function_exists('sb_find_page')) {
    function sb_find_page(int $pageId): ?array
    {
        foreach (sb_read_pages() as $p) {
            if ((int)($p['id'] ?? 0) === $pageId) {
                return $p;
            }
        }
        return null;
    }
}

if (!function_exists('sb_find_block')) {
    function sb_find_block(int $blockId): ?array
    {
        foreach (sb_read_blocks() as $b) {
            if ((int)($b['id'] ?? 0) === $blockId) {
                return $b;
            }
        }
        return null;
    }
}

if (!function_exists('sb_page_exists_in_site')) {
    function sb_page_exists_in_site(int $pageId, int $siteId): bool
    {
        $page = sb_find_page($pageId);
        return $page && (int)($page['siteId'] ?? 0) === $siteId;
    }
}

if (!function_exists('sb_page_children_ids')) {
    function sb_page_children_ids(int $siteId, int $parentId): array
    {
        $ids = [];
        foreach (sb_read_pages() as $p) {
            if (
                (int)($p['siteId'] ?? 0) === $siteId
                && (int)($p['parentId'] ?? 0) === $parentId
            ) {
                $ids[] = (int)($p['id'] ?? 0);
            }
        }
        return $ids;
    }
}

if (!function_exists('sb_page_is_descendant')) {
    function sb_page_is_descendant(int $siteId, int $candidateId, int $pageId): bool
    {
        if ($candidateId <= 0 || $pageId <= 0) {
            return false;
        }

        $pages = sb_read_pages();

        $childrenMap = [];
        foreach ($pages as $p) {
            if ((int)($p['siteId'] ?? 0) !== $siteId) {
                continue;
            }

            $pid = (int)($p['parentId'] ?? 0);
            $id  = (int)($p['id'] ?? 0);

            if (!isset($childrenMap[$pid])) {
                $childrenMap[$pid] = [];
            }
            $childrenMap[$pid][] = $id;
        }

        $stack = [$pageId];
        $seen = [];

        while ($stack) {
            $current = array_pop($stack);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            foreach (($childrenMap[$current] ?? []) as $childId) {
                if ($childId === $candidateId) {
                    return true;
                }
                $stack[] = $childId;
            }
        }

        return false;
    }
}

if (!function_exists('sb_blocks_for_page')) {
    function sb_blocks_for_page(int $pageId): array
    {
        $blocks = array_values(array_filter(sb_read_blocks(), static function ($b) use ($pageId) {
            return (int)($b['pageId'] ?? 0) === $pageId;
        }));

        usort($blocks, static function ($a, $b) {
            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
            if ($sortCmp !== 0) {
                return $sortCmp;
            }
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return $blocks;
    }
}

if (!function_exists('sb_next_block_id')) {
    function sb_next_block_id(array $blocks = null): int
    {
        if (!class_exists('IdSequenceService')) {
            require_once __DIR__ . '/IdSequenceService.php';
        }

        return IdSequenceService::next(IdSequenceService::ENTITY_BLOCK);
    }
}

if (!function_exists('sb_next_block_sort')) {
    function sb_next_block_sort(int $pageId, array $blocks = null): int
    {
        if ($blocks === null) {
            $blocks = sb_read_blocks();
        }

        $maxSort = 0;
        foreach ($blocks as $b) {
            if ((int)($b['pageId'] ?? 0) === $pageId) {
                $maxSort = max($maxSort, (int)($b['sort'] ?? 0));
            }
        }

        return $maxSort + 10;
    }
}

if (!function_exists('sb_default_block_content')) {
    function sb_default_block_content(string $type): array
    {
        switch ($type) {
            case 'text':
                return [
                    'text' => 'Новый текстовый блок',
                ];

            case 'heading':
                return [
                    'text' => 'Новый заголовок',
                    'level' => 'h2',
                    'align' => 'left',
                    'color' => '',
                    'size' => 0,
                    'maxWidth' => '',
                ];

            case 'image':
                return [
                    'fileId' => 0,
                    'src' => '',
                    'alt' => '',
                    'caption' => '',
                    'title' => '',
                    'href' => '',
                ];

            case 'button':
                return [
                    'text' => 'Кнопка',
                    'label' => 'Кнопка',
                    'href' => '#',
                    'target' => '_self',
                ];

            case 'hero':
                return [
                    'eyebrow' => 'Новый раздел',
                    'title' => 'Сильный заголовок для первого экрана',
                    'text' => 'Коротко объясните ценность страницы и подскажите посетителю следующий шаг.',
                    'primaryLabel' => 'Подробнее',
                    'primaryHref' => '#',
                    'secondaryLabel' => '',
                    'secondaryHref' => '',
                    'imageSrc' => '',
                    'imageAlt' => '',
                ];

            case 'cards':
                return [
                    'title' => 'Преимущества',
                    'items' => [
                        [
                            'title' => 'Карточка 1',
                            'text' => 'Краткое описание преимущества или услуги.',
                            'imageSrc' => '',
                            'href' => '',
                            'buttonText' => '',
                        ],
                        [
                            'title' => 'Карточка 2',
                            'text' => 'Краткое описание преимущества или услуги.',
                            'imageSrc' => '',
                            'href' => '',
                            'buttonText' => '',
                        ],
                        [
                            'title' => 'Карточка 3',
                            'text' => 'Краткое описание преимущества или услуги.',
                            'imageSrc' => '',
                            'href' => '',
                            'buttonText' => '',
                        ],
                    ],
                ];

            case 'quote':
                return [
                    'text' => 'Здесь можно разместить важную цитату, отзыв или ключевую мысль.',
                    'author' => 'Имя автора',
                    'role' => 'Должность или подразделение',
                ];

            case 'stats':
                return [
                    'title' => 'Ключевые показатели',
                    'items' => [
                        ['value' => '24/7', 'label' => 'Доступность сервиса'],
                        ['value' => '99%', 'label' => 'Точность данных'],
                        ['value' => '10+', 'label' => 'Готовых сценариев'],
                    ],
                ];

            case 'divider':
                return [
                    'label' => '',
                ];

            case 'spacer':
                return [
                    'height' => 40,
                    'tabletHeight' => 32,
                    'mobileHeight' => 24,
                ];

            case 'columns2':
                return [
                    'leftHtml' => '<p>Левая колонка</p>',
                    'rightHtml' => '<p>Правая колонка</p>',
                    'ratio' => '1:1',
                    'gap' => 24,
                ];

            case 'gallery':
                return [
                    'items' => [],
                    'columns' => 3,
                    'gap' => 16,
                ];

            case 'card':
                return [
                    'title' => 'Карточка',
                    'text' => 'Описание карточки',
                    'imageFileId' => 0,
                    'imageSrc' => '',
                    'buttonText' => '',
                    'buttonHref' => '',
                ];

            case 'faq':
                return [
                    'title' => 'Частые вопросы',
                    'items' => [
                        ['question' => 'Как начать работу?', 'answer' => 'Добавьте свой ответ на частый вопрос.'],
                        ['question' => 'Где получить помощь?', 'answer' => 'Укажите контакты или порядок обращения.'],
                    ],
                ];

            case 'video':
                return [
                    'url' => '',
                    'title' => 'Видео',
                    'caption' => '',
                    'poster' => '',
                ];

            case 'pricing':
                return [
                    'title' => 'Тарифы и варианты',
                    'plans' => [
                        ['name' => 'Базовый', 'price' => 'Бесплатно', 'description' => 'Для знакомства', 'features' => ['Основная возможность', 'Поддержка'], 'buttonText' => 'Выбрать', 'buttonHref' => '#', 'featured' => false],
                        ['name' => 'Расширенный', 'price' => 'По запросу', 'description' => 'Для команды', 'features' => ['Все возможности', 'Приоритетная поддержка'], 'buttonText' => 'Оставить заявку', 'buttonHref' => '#', 'featured' => true],
                    ],
                ];

            case 'form':
                return [
                    'title' => 'Оставить заявку',
                    'description' => 'Заполните форму — мы свяжемся с вами.',
                    'submitLabel' => 'Отправить',
                    'successText' => 'Спасибо! Заявка отправлена.',
                    'privacyText' => 'Нажимая кнопку, вы соглашаетесь на обработку данных.',
                    'fields' => [
                        ['key' => 'name', 'label' => 'Имя', 'type' => 'text', 'required' => true, 'placeholder' => 'Ваше имя', 'options' => [], 'width' => 'half'],
                        ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'placeholder' => 'name@example.com', 'options' => [], 'width' => 'half'],
                        ['key' => 'message', 'label' => 'Сообщение', 'type' => 'textarea', 'required' => false, 'placeholder' => 'Расскажите о задаче', 'options' => [], 'width' => 'full'],
                    ],
                ];

            case 'navigation':
                return [
                    'brand' => 'Название сайта',
                    'links' => [
                        ['label' => 'Главная', 'href' => '#'],
                        ['label' => 'О нас', 'href' => '#'],
                        ['label' => 'Контакты', 'href' => '#'],
                    ],
                    'ctaLabel' => 'Связаться',
                    'ctaHref' => '#',
                ];

            case 'footer':
                return [
                    'brand' => 'Название сайта',
                    'text' => 'Краткое описание организации или проекта.',
                    'columns' => [
                        ['title' => 'Разделы', 'links' => [['label' => 'Главная', 'href' => '#'], ['label' => 'Контакты', 'href' => '#']]],
                        ['title' => 'Документы', 'links' => [['label' => 'Политика конфиденциальности', 'href' => '#']]],
                    ],
                    'copyright' => '© ' . date('Y') . ' Все права защищены',
                ];

            case 'html':
                return [
                    'html' => '<div>HTML блок</div>',
                ];

            default:
                return [];
        }
    }
}

if (!function_exists('sb_normalize_block_record')) {
    function sb_normalize_block_record(array $block): array
    {
        if (!isset($block['content']) || !is_array($block['content'])) {
            $block['content'] = [];
        }

        if (!isset($block['props']) || !is_array($block['props'])) {
            $block['props'] = [];
        }

        if (!isset($block['type'])) {
            $block['type'] = 'text';
        }

        if (!isset($block['sort'])) {
            $block['sort'] = 500;
        }

        return $block;
    }
}

if (!function_exists('sb_find_menu')) {
    function sb_find_menu(int $menuId): ?array
    {
        foreach (sb_read_menus() as $m) {
            if ((int)($m['id'] ?? 0) === $menuId) {
                return $m;
            }
        }
        return null;
    }
}

if (!function_exists('sb_next_menu_id')) {
    function sb_next_menu_id(array $menus = null): int
    {
        if (!class_exists('IdSequenceService')) {
            require_once __DIR__ . '/IdSequenceService.php';
        }

        return IdSequenceService::next(IdSequenceService::ENTITY_MENU);
    }
}

if (!function_exists('sb_next_menu_item_id')) {
    function sb_next_menu_item_id(array $items): int
    {
        $maxId = 0;
        foreach ($items as $item) {
            $maxId = max($maxId, (int)($item['id'] ?? 0));
        }
        return $maxId + 1;
    }
}

if (!function_exists('sb_normalize_menu_item')) {
    function sb_normalize_menu_item(array $item): array
    {
        if (!isset($item['id'])) {
            $item['id'] = 0;
        }
        if (!isset($item['title'])) {
            $item['title'] = '';
        }
        if (!isset($item['type'])) {
            $item['type'] = 'page';
        }
        if (!isset($item['pageId'])) {
            $item['pageId'] = 0;
        }
        if (!isset($item['url'])) {
            $item['url'] = '';
        }
        if (!isset($item['target'])) {
            $item['target'] = '_self';
        }
        if (!isset($item['sort'])) {
            $item['sort'] = 500;
        }

        return $item;
    }
}

if (!function_exists('sb_normalize_menu_record')) {
    function sb_normalize_menu_record(array $menu): array
    {
        if (!isset($menu['items']) || !is_array($menu['items'])) {
            $menu['items'] = [];
        }

        $menu['items'] = array_map('sb_normalize_menu_item', $menu['items']);

        usort($menu['items'], static function ($a, $b) {
            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
            if ($sortCmp !== 0) {
                return $sortCmp;
            }
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        if (!isset($menu['name'])) {
            $menu['name'] = '';
        }
        if (!isset($menu['siteId'])) {
            $menu['siteId'] = 0;
        }

        return $menu;
    }
}

if (!function_exists('sb_menu_next_item_sort')) {
    function sb_menu_next_item_sort(array $items): int
    {
        $maxSort = 0;
        foreach ($items as $item) {
            $maxSort = max($maxSort, (int)($item['sort'] ?? 0));
        }
        return $maxSort + 10;
    }
}

if (!function_exists('sb_find_access_row')) {
    function sb_find_access_row(int $siteId, string $accessCode): ?array
    {
        foreach (sb_read_access() as $row) {
            if (
                (int)($row['siteId'] ?? 0) === $siteId
                && (string)($row['accessCode'] ?? '') === $accessCode
            ) {
                return $row;
            }
        }

        return null;
    }
}

if (!function_exists('sb_access_rows_for_site')) {
    function sb_access_rows_for_site(int $siteId): array
    {
        return array_values(array_filter(sb_read_access(), static function ($row) use ($siteId) {
            return (int)($row['siteId'] ?? 0) === $siteId;
        }));
    }
}

if (!function_exists('sb_normalize_access_role')) {
    function sb_normalize_access_role(string $role): string
    {
        $role = strtoupper(trim($role));

        if (!in_array($role, ['VIEWER', 'EDITOR', 'ADMIN', 'OWNER'], true)) {
            return '';
        }

        return $role;
    }
}

if (!function_exists('sb_count_site_owners')) {
    function sb_count_site_owners(int $siteId): int
    {
        $count = 0;

        foreach (sb_read_access() as $row) {
            if (
                (int)($row['siteId'] ?? 0) === $siteId
                && (string)($row['role'] ?? '') === 'OWNER'
            ) {
                $count++;
            }
        }

        return $count;
    }
}



if (!function_exists('sb_is_bitrix_admin')) {
    function sb_is_bitrix_admin(): bool
    {
        global $USER;

        return is_object($USER)
            && method_exists($USER, 'IsAdmin')
            && $USER->IsAdmin();
    }
}

if (!function_exists('sb_require_bitrix_admin')) {
    function sb_require_bitrix_admin(): void
    {
        if (!sb_is_bitrix_admin()) {
            sb_json_error('BITRIX_ADMIN_REQUIRED', 403, [
                'message' => 'Создавать и изменять шаблоны может только администратор Битрикса.',
            ]);
        }
    }
}

if (!function_exists('sb_find_template')) {
    function sb_find_template(int $templateId): ?array
    {
        foreach (sb_read_templates() as $tpl) {
            if ((int)($tpl['id'] ?? 0) === $templateId) {
                return $tpl;
            }
        }
        return null;
    }
}

if (!function_exists('sb_next_template_id')) {
    function sb_next_template_id(array $templates = null): int
    {
        if ($templates === null) {
            $templates = sb_read_templates();
        }

        $maxId = 0;
        foreach ($templates as $tpl) {
            $maxId = max($maxId, (int)($tpl['id'] ?? 0));
        }

        return $maxId + 1;
    }
}

if (!function_exists('sb_templates_for_site')) {
    function sb_templates_for_site(int $siteId): array
    {
        $templates = array_values(array_filter(sb_read_templates(), static function ($tpl) use ($siteId) {
            return (int)($tpl['siteId'] ?? 0) === $siteId;
        }));

        usort($templates, static function ($a, $b) {
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return $templates;
    }
}

if (!function_exists('sb_normalize_template_record')) {
    function sb_normalize_template_record(array $tpl): array
    {
        if (!isset($tpl['name'])) {
            $tpl['name'] = '';
        }
        if (!isset($tpl['siteId'])) {
            $tpl['siteId'] = 0;
        }
        if (!isset($tpl['blocks']) || !is_array($tpl['blocks'])) {
            $tpl['blocks'] = [];
        }

        $tpl['blocks'] = array_map('sb_normalize_block_record', $tpl['blocks']);

        usort($tpl['blocks'], static function ($a, $b) {
            $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
            if ($sortCmp !== 0) {
                return $sortCmp;
            }
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });

        return $tpl;
    }
}

if (!function_exists('sb_layout_default_record')) {
    function sb_layout_default_record(int $siteId): array
    {
        return [
            'siteId' => $siteId,
            'settings' => [
                'showHeader' => true,
                'showFooter' => true,
                'showLeft' => false,
                'showRight' => false,
                'leftWidth' => 260,
                'rightWidth' => 260,
                'leftMode' => 'blocks',
            ],
            'zones' => [
                'header' => [],
                'footer' => [],
                'left' => [],
                'right' => [],
            ],
        ];
    }
}

if (!function_exists('sb_layout_valid_zone')) {
    function sb_layout_valid_zone(string $zone): bool
    {
        return in_array($zone, ['header', 'footer', 'left', 'right'], true);
    }
}

if (!function_exists('sb_find_layout')) {
    function sb_find_layout(int $siteId): ?array
    {
        foreach (sb_read_layouts() as $layout) {
            if ((int)($layout['siteId'] ?? 0) === $siteId) {
                return $layout;
            }
        }
        return null;
    }
}

if (!function_exists('sb_layout_ensure_record')) {
    function sb_layout_ensure_record(int $siteId): array
    {
        $layout = sb_find_layout($siteId);
        if ($layout) {
            return sb_normalize_layout_record($layout);
        }

        $layout = sb_layout_default_record($siteId);
        sb_write_layouts([$layout]);

        return sb_normalize_layout_record($layout);
    }
}

if (!function_exists('sb_normalize_layout_record')) {
    function sb_normalize_layout_record(array $layout): array
    {
        if (!isset($layout['settings']) || !is_array($layout['settings'])) {
            $layout['settings'] = [];
        }

        $layout['settings'] = array_merge([
            'showHeader' => true,
            'showFooter' => true,
            'showLeft' => false,
            'showRight' => false,
            'leftWidth' => 260,
            'rightWidth' => 260,
            'leftMode' => 'blocks',
        ], $layout['settings']);

        if (!isset($layout['zones']) || !is_array($layout['zones'])) {
            $layout['zones'] = [];
        }

        foreach (['header', 'footer', 'left', 'right'] as $zone) {
            if (!isset($layout['zones'][$zone]) || !is_array($layout['zones'][$zone])) {
                $layout['zones'][$zone] = [];
            }

            $layout['zones'][$zone] = array_map('sb_normalize_block_record', $layout['zones'][$zone]);

            usort($layout['zones'][$zone], static function ($a, $b) {
                $sortCmp = (int)($a['sort'] ?? 500) <=> (int)($b['sort'] ?? 500);
                if ($sortCmp !== 0) {
                    return $sortCmp;
                }
                return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
            });
        }

        return $layout;
    }
}

if (!function_exists('sb_layout_next_block_id')) {
    function sb_layout_next_block_id(array $layout): int
    {
        $maxId = 0;

        $zones = (array)($layout['zones'] ?? []);
        foreach ($zones as $blocks) {
            if (!is_array($blocks)) {
                continue;
            }

            foreach ($blocks as $block) {
                $maxId = max($maxId, (int)($block['id'] ?? 0));
            }
        }

        return $maxId + 1;
    }
}

if (!function_exists('sb_layout_next_block_sort')) {
    function sb_layout_next_block_sort(array $layout, string $zone): int
    {
        $maxSort = 0;

        $blocks = (array)($layout['zones'][$zone] ?? []);
        foreach ($blocks as $block) {
            $maxSort = max($maxSort, (int)($block['sort'] ?? 0));
        }

        return $maxSort + 10;
    }
}

if (!function_exists('sb_layout_find_block')) {
    function sb_layout_find_block(array $layout, int $blockId): ?array
    {
        $zones = (array)($layout['zones'] ?? []);
        foreach ($zones as $zoneName => $blocks) {
            if (!is_array($blocks)) {
                continue;
            }

            foreach ($blocks as $block) {
                if ((int)($block['id'] ?? 0) === $blockId) {
                    $block['_zone'] = (string)$zoneName;
                    return $block;
                }
            }
        }

        return null;
    }
}