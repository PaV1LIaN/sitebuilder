<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';

global $APPLICATION, $USER;

sitebuilder_require_auth();

CJSCore::Init(['ajax']);

header('Content-Type: text/html; charset=UTF-8');

$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$siteId = (int)($_GET['siteId'] ?? 0);

$libFiles = [
    __DIR__ . '/lib/db.php',
    __DIR__ . '/lib/json.php',
    __DIR__ . '/lib/storage_db.php',
    __DIR__ . '/lib/response.php',
    __DIR__ . '/lib/helpers.php',
    __DIR__ . '/lib/RevisionService.php',
    __DIR__ . '/lib/access.php',
    __DIR__ . '/lib/PageAccessRepository.php',
    __DIR__ . '/lib/PageAccessService.php',
];

foreach ($libFiles as $libFile) {
    if (file_exists($libFile)) {
        require_once $libFile;
    }
}

if ($siteId <= 0) {
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title>SiteBuilder / Editor</title>
        <?php $APPLICATION->ShowHead(); ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor.css?v=16">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor-v2.css?v=16">
    </head>
    <body class="sb-admin-body">
    <div class="sb-page">
        <h1 class="sb-title">Не передан siteId</h1>
        <p>
            <a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/index.php">
                Вернуться к списку сайтов
            </a>
        </p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$currentUserId = (int)$USER->GetID();

$canOpenEditor = false;

if ($USER->IsAdmin()) {
    $canOpenEditor = true;
}

/*
 * Глобальные роли:
 * ADMIN и OWNER.
 *
 * Используем access.php, потому что он также учитывает
 * резервные роли группы Битрикс24.
 */
if (!$canOpenEditor) {
    $globalRole = sb_get_role($siteId);
    $globalRoleRank = sb_role_rank($globalRole);

    if ($globalRoleRank >= 3) {
        $canOpenEditor = true;
    }
}

/*
 * Пользователь без глобальной роли может открыть редактор,
 * если у него есть canEdit хотя бы на одну страницу.
 */
if (!$canOpenEditor && $currentUserId > 0) {
    $accessCode = PageAccessRepository::userAccessCode(
        $currentUserId
    );

    $pageIds = PageAccessRepository::getPageIdsWithAccess(
        $siteId,
        $accessCode
    );

    foreach ($pageIds as $availablePageId) {
        if (
            PageAccessService::canEditPage(
                $siteId,
                (int)$availablePageId,
                $currentUserId
            )
        ) {
            $canOpenEditor = true;
            break;
        }
    }
}

if (!$canOpenEditor) {
    http_response_code(403);

    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title>Доступ запрещён</title>
        <?php $APPLICATION->ShowHead(); ?>

        <link
            rel="stylesheet"
            href="<?= htmlspecialchars(
                $basePath,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) ?>/assets/admin/admin.css"
        >
    </head>

    <body class="sb-admin-body">
    <div class="sb-page">
        <h1 class="sb-title">Доступ к редактору запрещён</h1>

        <p class="sb-subtitle">
            Для открытия редактора требуется глобальная роль
            ADMIN или OWNER либо право редактирования
            хотя бы одной страницы.
        </p>

        <p>
            <a
                class="sb-back-link"
                href="<?= htmlspecialchars(
                    $basePath,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                ) ?>/index.php"
            >
                Вернуться к списку сайтов
            </a>
        </p>
    </div>
    </body>
    </html>
    <?php

    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>SiteBuilder / Editor</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor.css?v=16">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor-v2.css?v=16">
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <header class="sb-editor-appbar">
        <div class="sb-editor-appbar__brand">
            <a class="sb-editor-appbar__back" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/index.php" aria-label="К списку сайтов">←</a>
            <div>
                <div class="sb-editor-appbar__eyebrow">SiteBuilder · сайт #<?= (int)$siteId ?></div>
                <h1 class="sb-editor-appbar__title">Визуальный редактор</h1>
            </div>
        </div>

        <div class="sb-editor-status" id="editorSaveStatus" data-state="ready">
            <span class="sb-editor-status__dot"></span>
            <span class="sb-editor-status__text">Готово</span>
        </div>

        <div class="sb-editor-appbar__actions">
            <a class="sb-btn sb-btn-light sb-btn-small" id="openPublicPageLink" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/public.php?siteId=<?= (int)$siteId ?>" target="_blank">
                Открыть страницу ↗
            </a>

            <a class="sb-btn sb-btn-light sb-btn-small" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/settings.php?siteId=<?= (int)$siteId ?>">
                Настройки
            </a>

            <details class="sb-editor-more">
                <summary class="sb-btn sb-btn-light sb-btn-small">Ещё</summary>
                <div class="sb-editor-more__menu">
                    <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/layout.php?siteId=<?= (int)$siteId ?>">Layout сайта</a>
                    <?php if ($USER->IsAdmin() || (int)($globalRoleRank ?? 0) >= 3): ?>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/trash.php?siteId=<?= (int)$siteId ?>">Корзина</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/audit.php?siteId=<?= (int)$siteId ?>">Журнал действий</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/jobs.php?siteId=<?= (int)$siteId ?>">Фоновые задания</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/queue_health.php?siteId=<?= (int)$siteId ?>">Состояние очереди</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/alerts.php?siteId=<?= (int)$siteId ?>">Оповещения</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/external_resources.php?siteId=<?= (int)$siteId ?>">Внешние ресурсы</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/backups.php?siteId=<?= (int)$siteId ?>">Резервные копии</a>
                    <?php endif; ?>
                </div>
            </details>

            <?php if ($USER->IsAdmin()): ?>
                <button class="sb-btn sb-btn-primary sb-btn-small" type="button" id="saveAsTemplateBtn">
                    В шаблоны
                </button>
            <?php endif; ?>

            <button class="sb-btn sb-btn-danger sb-btn-small sb-hidden" type="button" id="deleteSiteBtn">
                Удалить сайт
            </button>
        </div>
    </header>

    <div class="sb-editor-contextbar">
        <div class="sb-editor-contextbar__hint">
            <strong>Совет:</strong> выберите секцию и колонку, затем добавляйте блоки. <kbd>Ctrl</kbd>+<kbd>S</kbd> сохраняет текущий объект, <kbd>Ctrl</kbd>+<kbd>K</kbd> открывает библиотеку.
        </div>
        <div class="sb-editor-contextbar__devices" role="group" aria-label="Размер предпросмотра">
            <button class="sb-preview-device is-active" type="button" data-preview-device="desktop" title="Компьютер">▣</button>
            <button class="sb-preview-device" type="button" data-preview-device="tablet" title="Планшет">▯</button>
            <button class="sb-preview-device" type="button" data-preview-device="mobile" title="Телефон">▯</button>
        </div>
    </div>

    <div class="sb-editor-shell">
        <div class="sb-editor-col">
            <div class="sb-editor-sticky">
                <div class="sb-panel">
                    <div class="sb-editor-section-head">
                        <h2 class="sb-panel-title">Страницы</h2>
                        <span class="sb-badge">siteId <?= (int)$siteId ?></span>
                    </div>

                    <div class="sb-editor-page-search">
                        <input type="search" id="pageSearchInput" placeholder="Найти страницу по названию или slug" autocomplete="off">
                    </div>

                    <div class="sb-editor-create">
                        <div class="sb-form-row align-end">
                            <div class="sb-field">
                                <label for="newPageTitle">Название страницы</label>
                                <input class="sb-input" type="text" id="newPageTitle" placeholder="Например: Главная">
                            </div>
                        </div>

                        <div class="sb-form-row align-end" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="newPageSlug">Slug</label>
                                <input class="sb-input" type="text" id="newPageSlug" placeholder="Например: home">
                            </div>

                            <div class="sb-field">
                                <label for="newPageParentId">Родитель</label>
                                <select class="sb-select" id="newPageParentId">
                                    <option value="0">Без родителя</option>
                                </select>
                            </div>
                        </div>

                        <div class="sb-form-row" style="margin-top:12px;">
                            <button class="sb-btn sb-btn-primary" type="button" id="createPageBtn">Создать страницу</button>
                        </div>
                    </div>

                    <div id="pagesList" class="sb-editor-pages">
                        <div class="sb-empty">Загрузка страниц...</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sb-editor-col">
            <div class="sb-editor-canvas">
                <div class="sb-editor-canvas-head">
                    <div>
                        <h2 class="sb-editor-canvas-title" id="canvasPageTitle">Страница</h2>
                        <p class="sb-editor-canvas-sub" id="canvasPageMeta">Выберите страницу слева</p>
                    </div>

                    <div class="sb-toolbar">
                        <button class="sb-icon-btn" type="button" id="movePageUpBtn" title="Переместить страницу вверх">↑</button>
                        <button class="sb-icon-btn" type="button" id="movePageDownBtn" title="Переместить страницу вниз">↓</button>
                        <button class="sb-btn sb-btn-primary sb-btn-small" type="button" id="publishPageBtn">Опубликовать</button>
                    </div>
                </div>

                <div class="sb-editor-canvas-body" id="editorCanvasBody">
                    <div class="sb-editor-viewport is-desktop" id="editorViewport">
                    <div class="sb-editor-page">
                        <h2 class="sb-editor-page-heading" id="pagePreviewHeading">Выберите страницу</h2>

                        <div class="sb-editor-addbar">
                            <button class="sb-editor-add-card" type="button" data-add-block="heading">
                                <span class="sb-editor-add-card__icon">H</span>
                                <span><span class="sb-editor-add-card__title">Заголовок</span><span class="sb-editor-add-card__text">Тема или подзаголовок</span></span>
                            </button>
                            <button class="sb-editor-add-card" type="button" data-add-block="text">
                                <span class="sb-editor-add-card__icon">¶</span>
                                <span><span class="sb-editor-add-card__title">Текст</span><span class="sb-editor-add-card__text">Абзац или список</span></span>
                            </button>
                            <button class="sb-editor-add-card" type="button" data-add-block="image">
                                <span class="sb-editor-add-card__icon">▧</span>
                                <span><span class="sb-editor-add-card__title">Изображение</span><span class="sb-editor-add-card__text">Фото с подписью</span></span>
                            </button>
                            <button class="sb-editor-add-card" type="button" data-add-block="button">
                                <span class="sb-editor-add-card__icon">↗</span>
                                <span><span class="sb-editor-add-card__title">Кнопка</span><span class="sb-editor-add-card__text">Ссылка и призыв</span></span>
                            </button>
                            <button class="sb-editor-add-card" type="button" data-add-block="hero">
                                <span class="sb-editor-add-card__icon">★</span>
                                <span><span class="sb-editor-add-card__title">Первый экран</span><span class="sb-editor-add-card__text">Hero с кнопками</span></span>
                            </button>
                            <button class="sb-editor-add-card sb-editor-add-card--library" type="button" id="openBlockLibraryBtn">
                                <span class="sb-editor-add-card__icon">＋</span>
                                <span><span class="sb-editor-add-card__title">Все блоки</span><span class="sb-editor-add-card__text">Открыть библиотеку</span></span>
                            </button>
                        </div>

                        <div id="blocksList" class="sb-editor-blocks">
                            <div class="sb-editor-empty-big">
                                <strong>Страница не выбрана</strong>
                                Выбери страницу слева, чтобы редактировать ее блоки
                            </div>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sb-editor-col sb-editor-col--right">
            <div class="sb-editor-sticky">
                <div class="sb-inspector-tabs" role="tablist" aria-label="Панель настроек">
                    <button class="sb-inspector-tab is-active" type="button" data-inspector-tab="page">Страница</button>
                    <button class="sb-inspector-tab" type="button" data-inspector-tab="section">Секция</button>
                    <button class="sb-inspector-tab" type="button" data-inspector-tab="block">Блок</button>
                    <button class="sb-inspector-tab" type="button" data-inspector-tab="history">История</button>
                    <button class="sb-inspector-tab" type="button" data-inspector-tab="access" id="inspectorAccessTab" hidden>Доступ</button>
                </div>

                <div class="sb-panel sb-inspector-panel is-active" data-inspector-panel="page">
                    <h2 class="sb-panel-title">Свойства страницы</h2>
                    <p class="sb-editor-note">Здесь меняются заголовок, slug, статус и родитель текущей страницы.</p>

                    <div class="sb-field">
                        <label for="pageTitleInput">Название</label>
                        <input class="sb-input" type="text" id="pageTitleInput">
                    </div>

                    <div class="sb-field" style="margin-top:12px;">
                        <label for="pageSlugInput">Slug</label>
                        <input class="sb-input" type="text" id="pageSlugInput">
                    </div>

                    <div class="sb-field" style="margin-top:12px;">
                        <label for="pageStatusInput">Статус</label>
                        <select class="sb-select" id="pageStatusInput">
                            <option value="draft">draft</option>
                            <option value="published">published</option>
                        </select>
                    </div>

                    <div class="sb-field" style="margin-top:12px;">
                        <label for="pageParentInput">Родительская страница</label>
                        <select class="sb-select" id="pageParentInput">
                            <option value="0">Без родителя</option>
                        </select>
                    </div>

                    <div class="sb-editor-inspector-actions">
                        <button class="sb-btn sb-btn-primary" type="button" id="savePageBtn">Сохранить страницу</button>
                        <button class="sb-btn sb-btn-danger" type="button" id="deletePageBtn">Удалить страницу</button>
                    </div>
                </div>

                <div class="sb-panel sb-page-sections-editor sb-inspector-panel" data-inspector-panel="section">
                    <div class="sb-page-sections-editor__head">
                        <div>
                            <h2 class="sb-panel-title">Секции страницы</h2>
                            <p class="sb-editor-note">
                                Большие блоки страницы. Внутрь секций распределяются компоненты.
                            </p>
                        </div>

                        <button class="sb-btn sb-btn-primary sb-btn-small" type="button" id="addPageSectionBtn">
                            + Секция
                        </button>
                    </div>

                    <div id="pageSectionsMessage" class="sb-page-sections-message" hidden></div>

                    <div id="pageSectionsList" class="sb-page-sections-list">
                        <div class="sb-empty">Выберите страницу</div>
                    </div>
                </div>

                <div class="sb-panel sb-inspector-panel" data-inspector-panel="block">
                    <h2 class="sb-panel-title">Свойства блока</h2>

                    <div id="blockInspectorEmpty" class="sb-empty">
                        Выбери блок в центре страницы
                    </div>

                    <div id="blockInspector" class="sb-hidden">
                        <div class="sb-field">
                            <label for="blockTypeInput">Тип</label>
                            <input class="sb-input" type="text" id="blockTypeInput" disabled>
                        </div>

                        <div class="sb-form-row sb-block-placement-row" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="blockSectionInput">Секция</label>
                                <select class="sb-select" id="blockSectionInput">
                                    <option value="0">Основная секция</option>
                                </select>
                            </div>

                            <div class="sb-field">
                                <label for="blockColumnInput">Колонка</label>
                                <select class="sb-select" id="blockColumnInput">
                                    <option value="1">Колонка 1</option>
                                </select>
                            </div>
                        </div>


                        <div id="headingBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="headingTextInput">Текст заголовка</label>
                                <textarea class="sb-textarea sb-textarea--compact" id="headingTextInput" placeholder="Введите заголовок"></textarea>
                            </div>
                            <div class="sb-form-grid sb-form-grid--3" style="margin-top:12px;">
                                <div class="sb-field"><label for="headingLevelInput">Уровень</label><select class="sb-select" id="headingLevelInput"><option value="h1">H1</option><option value="h2">H2</option><option value="h3">H3</option><option value="h4">H4</option><option value="h5">H5</option><option value="h6">H6</option></select></div>
                                <div class="sb-field"><label for="headingAlignInput">Выравнивание</label><select class="sb-select" id="headingAlignInput"><option value="left">Слева</option><option value="center">По центру</option><option value="right">Справа</option></select></div>
                                <div class="sb-field"><label for="headingColorInput">Цвет</label><input class="sb-input sb-color-input" type="color" id="headingColorInput" value="#111827"></div>
                            </div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;">
                                <div class="sb-field"><label for="headingSizeInput">Размер, px (0 = авто)</label><input class="sb-input" type="number" min="0" max="120" id="headingSizeInput" value="0"></div>
                                <div class="sb-field"><label for="headingMaxWidthInput">Макс. ширина, px (0 = авто)</label><input class="sb-input" type="number" min="0" max="1800" id="headingMaxWidthInput" value="0"></div>
                            </div>
                        </div>

                        <div id="textBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="textTextInput">Текст блока</label>
                                <textarea class="sb-textarea" id="textTextInput" placeholder="Введите текст"></textarea>
                                <p class="sb-block-form-note">Поддерживаются переносы строк и базовые HTML-теги: p, strong, em, ul, ol, li, a.</p>
                            </div>
                            <div class="sb-form-grid sb-form-grid--3" style="margin-top:12px;">
                                <div class="sb-field"><label for="textAlignInput">Выравнивание</label><select class="sb-select" id="textAlignInput"><option value="left">Слева</option><option value="center">По центру</option><option value="right">Справа</option><option value="justify">По ширине</option></select></div>
                                <div class="sb-field"><label for="textSizeInput">Размер, px</label><input class="sb-input" type="number" min="12" max="72" id="textSizeInput" value="16"></div>
                                <div class="sb-field"><label for="textColorInput">Цвет</label><input class="sb-input sb-color-input" type="color" id="textColorInput" value="#374151"></div>
                            </div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;">
                                <div class="sb-field"><label for="textLineHeightInput">Межстрочный интервал</label><input class="sb-input" type="number" min="1" max="2.4" step="0.05" id="textLineHeightInput" value="1.65"></div>
                                <div class="sb-field"><label for="textMaxWidthInput">Макс. ширина, px (0 = авто)</label><input class="sb-input" type="number" min="0" max="1800" id="textMaxWidthInput" value="0"></div>
                            </div>
                        </div>

                        <div id="buttonBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field"><label for="buttonLabelInput">Текст кнопки</label><input class="sb-input" type="text" id="buttonLabelInput" placeholder="Например: Подробнее"></div>
                            <div class="sb-field" style="margin-top:12px;"><label for="buttonHrefInput">Ссылка</label><input class="sb-input" type="text" id="buttonHrefInput" placeholder="https://... или /path/"></div>
                            <div class="sb-form-grid sb-form-grid--3" style="margin-top:12px;">
                                <div class="sb-field"><label for="buttonTargetInput">Открывать</label><select class="sb-select" id="buttonTargetInput"><option value="_self">В этом окне</option><option value="_blank">В новой вкладке</option></select></div>
                                <div class="sb-field"><label for="buttonStyleInput">Стиль</label><select class="sb-select" id="buttonStyleInput"><option value="primary">Основная</option><option value="secondary">Тёмная</option><option value="outline">Контурная</option><option value="ghost">Лёгкая</option></select></div>
                                <div class="sb-field"><label for="buttonSizeInput">Размер</label><select class="sb-select" id="buttonSizeInput"><option value="small">Маленькая</option><option value="medium">Средняя</option><option value="large">Большая</option></select></div>
                            </div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;">
                                <div class="sb-field"><label for="buttonAlignInput">Выравнивание</label><select class="sb-select" id="buttonAlignInput"><option value="left">Слева</option><option value="center">По центру</option><option value="right">Справа</option></select></div>
                                <label class="sb-switch"><input type="checkbox" id="buttonFullWidthInput"><span>На всю ширину</span></label>
                            </div>
                        </div>

                        <div id="imageBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field"><label for="imageSrcInput">URL изображения</label><input class="sb-input" type="text" id="imageSrcInput" placeholder="https://... или /upload/..."></div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;">
                                <div class="sb-field"><label for="imageAltInput">Alt-текст</label><input class="sb-input" type="text" id="imageAltInput"></div>
                                <div class="sb-field"><label for="imageHrefInput">Ссылка при клике</label><input class="sb-input" type="text" id="imageHrefInput"></div>
                            </div>
                            <div class="sb-field" style="margin-top:12px;"><label for="imageCaptionInput">Подпись</label><input class="sb-input" type="text" id="imageCaptionInput"></div>
                            <div class="sb-form-grid sb-form-grid--3" style="margin-top:12px;">
                                <div class="sb-field"><label for="imageRatioInput">Пропорции</label><select class="sb-select" id="imageRatioInput"><option value="auto">Оригинал</option><option value="16:9">16:9</option><option value="4:3">4:3</option><option value="3:2">3:2</option><option value="1:1">Квадрат</option></select></div>
                                <div class="sb-field"><label for="imageFitInput">Заполнение</label><select class="sb-select" id="imageFitInput"><option value="cover">Обрезать</option><option value="contain">Вместить</option><option value="fill">Растянуть</option><option value="none">Без масштабирования</option></select></div>
                                <div class="sb-field"><label for="imageAlignInput">Выравнивание</label><select class="sb-select" id="imageAlignInput"><option value="left">Слева</option><option value="center">По центру</option><option value="right">Справа</option></select></div>
                            </div>
                            <div class="sb-form-grid sb-form-grid--3" style="margin-top:12px;">
                                <div class="sb-field"><label for="imageWidthInput">Ширина, %</label><input class="sb-input" type="number" min="10" max="100" id="imageWidthInput" value="100"></div>
                                <div class="sb-field"><label for="imageRadiusInput">Скругление, px</label><input class="sb-input" type="number" min="0" max="80" id="imageRadiusInput" value="18"></div>
                                <label class="sb-switch"><input type="checkbox" id="imageShadowInput"><span>Тень</span></label>
                            </div>
                        </div>

                        <div id="heroBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field"><label for="heroEyebrowInput">Надзаголовок</label><input class="sb-input" type="text" id="heroEyebrowInput"></div>
                            <div class="sb-field" style="margin-top:12px;"><label for="heroTitleInput">Заголовок</label><textarea class="sb-textarea sb-textarea--compact" id="heroTitleInput"></textarea></div>
                            <div class="sb-field" style="margin-top:12px;"><label for="heroTextInput">Описание</label><textarea class="sb-textarea" id="heroTextInput"></textarea></div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;">
                                <div class="sb-field"><label for="heroPrimaryLabelInput">Основная кнопка</label><input class="sb-input" type="text" id="heroPrimaryLabelInput"></div>
                                <div class="sb-field"><label for="heroPrimaryHrefInput">Ссылка</label><input class="sb-input" type="text" id="heroPrimaryHrefInput"></div>
                            </div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;">
                                <div class="sb-field"><label for="heroSecondaryLabelInput">Вторая кнопка</label><input class="sb-input" type="text" id="heroSecondaryLabelInput"></div>
                                <div class="sb-field"><label for="heroSecondaryHrefInput">Ссылка</label><input class="sb-input" type="text" id="heroSecondaryHrefInput"></div>
                            </div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;">
                                <div class="sb-field"><label for="heroImageSrcInput">URL изображения</label><input class="sb-input" type="text" id="heroImageSrcInput"></div>
                                <div class="sb-field"><label for="heroImageAltInput">Alt-текст</label><input class="sb-input" type="text" id="heroImageAltInput"></div>
                            </div>
                            <div class="sb-form-grid sb-form-grid--3" style="margin-top:12px;">
                                <div class="sb-field"><label for="heroThemeInput">Тема</label><select class="sb-select" id="heroThemeInput"><option value="light">Светлая</option><option value="soft">Мягкая</option><option value="accent">Акцентная</option><option value="dark">Тёмная</option></select></div>
                                <div class="sb-field"><label for="heroAlignInput">Текст</label><select class="sb-select" id="heroAlignInput"><option value="left">Слева</option><option value="center">По центру</option></select></div>
                                <div class="sb-field"><label for="heroImagePositionInput">Изображение</label><select class="sb-select" id="heroImagePositionInput"><option value="right">Справа</option><option value="left">Слева</option><option value="background">Фоном</option><option value="none">Скрыть</option></select></div>
                            </div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;">
                                <div class="sb-field"><label for="heroMinHeightInput">Мин. высота, px</label><input class="sb-input" type="number" min="220" max="900" id="heroMinHeightInput" value="380"></div>
                                <div class="sb-field"><label for="heroRadiusInput">Скругление, px</label><input class="sb-input" type="number" min="0" max="80" id="heroRadiusInput" value="28"></div>
                            </div>
                            <div class="sb-form-grid sb-form-grid--3" style="margin-top:12px;">
                                <div class="sb-field"><label for="heroBackgroundColorInput">Свой цвет фона</label><input class="sb-input sb-color-input" type="color" id="heroBackgroundColorInput" value="#eff6ff"></div>
                                <div class="sb-field"><label for="heroTextColorInput">Свой цвет текста</label><input class="sb-input sb-color-input" type="color" id="heroTextColorInput" value="#0f172a"></div>
                                <label class="sb-switch"><input type="checkbox" id="heroUseCustomColorsInput"><span>Использовать свои цвета</span></label>
                            </div>
                            <p class="sb-block-form-note">Если переключатель выключен, цвета определяются выбранной темой.</p>
                        </div>

                        <div id="cardsBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field"><label for="cardsTitleInput">Заголовок группы</label><input class="sb-input" type="text" id="cardsTitleInput"></div>
                            <div class="sb-repeater-head"><strong>Карточки</strong><button class="sb-btn sb-btn-light sb-btn-small" type="button" data-cards-action="add">+ Карточка</button></div>
                            <div id="cardsItemsEditor" class="sb-repeater"></div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;">
                                <div class="sb-field"><label for="cardsColumnsInput">Колонки</label><select class="sb-select" id="cardsColumnsInput"><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option></select></div>
                                <div class="sb-field"><label for="cardsStyleInput">Стиль</label><select class="sb-select" id="cardsStyleInput"><option value="elevated">С тенью</option><option value="outlined">Контур</option><option value="soft">Мягкий фон</option><option value="minimal">Минимализм</option></select></div>
                                <div class="sb-field"><label for="cardsImageRatioInput">Фото</label><select class="sb-select" id="cardsImageRatioInput"><option value="16:9">16:9</option><option value="4:3">4:3</option><option value="1:1">1:1</option><option value="auto">Оригинал</option></select></div>
                                <div class="sb-field"><label for="cardsAlignInput">Текст</label><select class="sb-select" id="cardsAlignInput"><option value="left">Слева</option><option value="center">По центру</option></select></div>
                            </div>
                        </div>

                        <div id="quoteBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field"><label for="quoteTextInput">Цитата</label><textarea class="sb-textarea" id="quoteTextInput"></textarea></div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;"><div class="sb-field"><label for="quoteAuthorInput">Автор</label><input class="sb-input" type="text" id="quoteAuthorInput"></div><div class="sb-field"><label for="quoteRoleInput">Должность</label><input class="sb-input" type="text" id="quoteRoleInput"></div></div>
                            <div class="sb-form-grid sb-form-grid--3" style="margin-top:12px;"><div class="sb-field"><label for="quoteStyleInput">Стиль</label><select class="sb-select" id="quoteStyleInput"><option value="accent">Акцент</option><option value="soft">Мягкий</option><option value="minimal">Минимальный</option><option value="dark">Тёмный</option></select></div><div class="sb-field"><label for="quoteAlignInput">Выравнивание</label><select class="sb-select" id="quoteAlignInput"><option value="left">Слева</option><option value="center">По центру</option></select></div><div class="sb-field"><label for="quoteAccentColorInput">Цвет акцента</label><input class="sb-input sb-color-input" type="color" id="quoteAccentColorInput" value="#2563eb"></div></div>
                        </div>

                        <div id="statsBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field"><label for="statsTitleInput">Заголовок</label><input class="sb-input" type="text" id="statsTitleInput"></div>
                            <div class="sb-repeater-head"><strong>Показатели</strong><button class="sb-btn sb-btn-light sb-btn-small" type="button" data-stats-action="add">+ Показатель</button></div>
                            <div id="statsItemsEditor" class="sb-repeater"></div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;"><div class="sb-field"><label for="statsColumnsInput">Колонки</label><select class="sb-select" id="statsColumnsInput"><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option></select></div><div class="sb-field"><label for="statsStyleInput">Стиль</label><select class="sb-select" id="statsStyleInput"><option value="cards">Карточки</option><option value="line">Линии</option><option value="plain">Без фона</option><option value="accent">Акцентный фон</option></select></div></div>
                        </div>

                        <div id="dividerBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field"><label for="dividerLabelInput">Подпись (необязательно)</label><input class="sb-input" type="text" id="dividerLabelInput"></div>
                            <div class="sb-form-grid sb-form-grid--3" style="margin-top:12px;"><div class="sb-field"><label for="dividerStyleInput">Стиль</label><select class="sb-select" id="dividerStyleInput"><option value="solid">Линия</option><option value="dashed">Пунктир</option><option value="gradient">Градиент</option><option value="dots">Точки</option></select></div><div class="sb-field"><label for="dividerColorInput">Цвет</label><input class="sb-input sb-color-input" type="color" id="dividerColorInput" value="#cbd5e1"></div><div class="sb-field"><label for="dividerThicknessInput">Толщина, px</label><input class="sb-input" type="number" min="1" max="8" id="dividerThicknessInput" value="1"></div></div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;"><div class="sb-field"><label for="dividerWidthInput">Ширина, %</label><input class="sb-input" type="number" min="10" max="100" id="dividerWidthInput" value="100"></div><div class="sb-field"><label for="dividerMarginInput">Отступ, px</label><input class="sb-input" type="number" min="0" max="160" id="dividerMarginInput" value="24"></div></div>
                        </div>

                        <div id="spacerBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field"><label for="spacerHeightInput">Высота на компьютере, px</label><input class="sb-input" type="range" min="0" max="400" step="4" id="spacerHeightInput" value="40"><output id="spacerHeightOutput">40 px</output></div>
                            <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;">
                                <div class="sb-field"><label for="spacerTabletHeightInput">На планшете, px</label><input class="sb-input" type="number" min="0" max="400" id="spacerTabletHeightInput" value="32"></div>
                                <div class="sb-field"><label for="spacerMobileHeightInput">На телефоне, px</label><input class="sb-input" type="number" min="0" max="400" id="spacerMobileHeightInput" value="24"></div>
                            </div>
                        </div>

                        <div id="htmlBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="htmlInput">HTML</label>
                                <textarea class="sb-textarea" id="htmlInput" placeholder="<div>HTML-код</div>"></textarea>
                                <p class="sb-block-form-note">
                                    Используй только проверенный HTML. Скрипты лучше не вставлять.
                                </p>
                            </div>
                        </div>

                        <div id="tableBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="tableTitleInput">Заголовок таблицы</label>
                                <input class="sb-input" type="text" id="tableTitleInput" placeholder="Например: Прайс-лист, контакты, расписание">
                            </div>

                            <div class="sb-table-editor" style="margin-top:12px;">
                                <div class="sb-table-editor__head">
                                    <div>
                                        <strong>Столбцы</strong>
                                        <p class="sb-editor-note">Задай любое количество столбцов и назови их как нужно</p>
                                    </div>

                                    <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-table-action="add-column">
                                        + Столбец
                                    </button>
                                </div>

                                <div id="tableColumnsEditor" class="sb-table-editor__columns"></div>

                                <div class="sb-table-editor__head" style="margin-top:16px;">
                                    <div>
                                        <strong>Строки</strong>
                                        <p class="sb-editor-note">Добавляй строки и заполняй значения по столбцам</p>
                                    </div>

                                    <button class="sb-btn sb-btn-primary sb-btn-small" type="button" data-table-action="add-row">
                                        + Строка
                                    </button>
                                </div>

                                <div id="tableRowsEditor" class="sb-table-editor__rows"></div>
                            </div>
                        </div>

                        <div id="diskBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="diskTitleInput">Заголовок блока</label>
                                <input class="sb-input" type="text" id="diskTitleInput">
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="diskRootModeInput">Режим корня</label>
                                <select class="sb-select" id="diskRootModeInput">
                                    <option value="site">Корень сайта</option>
                                    <option value="block">Папка блока</option>
                                </select>
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="diskViewModeInput">Вид</label>
                                <select class="sb-select" id="diskViewModeInput">
                                    <option value="table">Таблица</option>
                                    <option value="grid">Плитка</option>
                                </select>
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="diskPermissionModeInput">Режим прав</label>
                                <select class="sb-select" id="diskPermissionModeInput">
                                    <option value="inherit_site">Наследовать права сайта</option>
                                    <option value="custom">Собственные ограничения блока</option>
                                </select>
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="diskMaxFileSizeInput">Максимальный размер файла</label>
                                <input class="sb-input" type="number" id="diskMaxFileSizeInput" min="0">
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="diskAllowedExtensionsInput">Разрешенные расширения</label>
                                <input class="sb-input" type="text" id="diskAllowedExtensionsInput" placeholder="pdf docx xlsx png jpg">
                            </div>

                            <div class="sb-form-row" style="margin-top:12px;">
                                <label><input type="checkbox" id="diskAllowUploadInput"> Загрузка</label>
                                <label><input type="checkbox" id="diskAllowCreateFolderInput"> Создание папок</label>
                            </div>

                            <div class="sb-form-row" style="margin-top:12px;">
                                <label><input type="checkbox" id="diskAllowRenameInput"> Переименование</label>
                                <label><input type="checkbox" id="diskAllowDeleteInput"> Удаление</label>
                            </div>

                            <div class="sb-form-row" style="margin-top:12px;">
                                <label><input type="checkbox" id="diskAllowDownloadInput"> Скачивание</label>
                                <label><input type="checkbox" id="diskShowSearchInput"> Показывать поиск</label>
                            </div>

                            <div class="sb-form-row" style="margin-top:12px;">
                                <label><input type="checkbox" id="diskShowBreadcrumbsInput"> Показывать breadcrumbs</label>
                                <label><input type="checkbox" id="diskUseSiteRootFallbackInput"> Использовать корень сайта как fallback</label>
                            </div>
                        </div>

                        <div id="unknownBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-empty">
                                Для этого типа блока пока нет визуальной формы. Используй технический JSON ниже.
                            </div>
                        </div>

                        <button class="sb-advanced-toggle" type="button" id="toggleAdvancedJsonBtn">Технический JSON</button>

                        <div id="blockJsonFields" class="sb-editor-advanced-json">
                            <div class="sb-field" style="margin-top:12px;">
                                <label for="blockContentInput">Контент (JSON)</label>
                                <textarea class="sb-textarea" id="blockContentInput"></textarea>
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="blockPropsInput">Свойства (JSON)</label>
                                <textarea class="sb-textarea" id="blockPropsInput"></textarea>
                            </div>
                        </div>

                        <div class="sb-editor-json-actions">
                            <button class="sb-btn sb-btn-primary" type="button" id="saveBlockBtn">Сохранить блок</button>
                            <button class="sb-btn sb-btn-light" type="button" id="duplicateBlockBtn">Дублировать</button>
                            <button class="sb-btn sb-btn-light" type="button" id="moveBlockUpBtn">Блок ↑</button>
                            <button class="sb-btn sb-btn-light" type="button" id="moveBlockDownBtn">Блок ↓</button>
                            <button class="sb-btn sb-btn-danger" type="button" id="deleteBlockBtn">Удалить</button>
                        </div>
                    </div>
                </div>

                <div class="sb-panel sb-inspector-panel" data-inspector-panel="history" id="historyPanel">
                    <h2 class="sb-panel-title">История изменений</h2>
                    <p class="sb-editor-note">
                        Версии защищают от перезаписи чужой работы. Любую сохранённую версию существующей страницы или блока можно восстановить.
                    </p>

                    <div class="sb-editor-inspector-actions">
                        <button class="sb-btn sb-btn-light" type="button" id="pageHistoryBtn">История страницы</button>
                        <button class="sb-btn sb-btn-light" type="button" id="blockHistoryBtn">История блока</button>
                    </div>

                    <div id="historyMessage" class="sb-empty" style="margin-top:12px;">
                        Выберите страницу или блок и откройте историю.
                    </div>

                    <div id="historyList" class="sb-history-list"></div>
                </div>

                <div class="sb-panel sb-inspector-panel" data-inspector-panel="access" id="siteGroupPanel" hidden>
                    <h2 class="sb-panel-title">Группа Битрикс24 и права</h2>
                    <p class="sb-editor-note">
                        Группа используется для связки сайта с пользователями Битрикс24.
                    </p>

                    <div id="bitrixGroupInfo" class="sb-empty">
                        Информация о группе загружается...
                    </div>

                    <div class="sb-editor-inspector-actions">
                        <button class="sb-btn sb-btn-light" type="button" id="ensureBitrixGroupBtn">Создать группу</button>
                        <button class="sb-btn sb-btn-light" type="button" id="syncAccessBtn">Синхронизировать права</button>
                    </div>

                    <div id="syncAccessResult" class="sb-output" style="margin-top:12px;"></div>
                </div>

                <div class="sb-panel sb-inspector-panel" data-inspector-panel="access" id="siteAccessPanel" hidden>
                    <h2 class="sb-panel-title">Права пользователей</h2>
                    <p class="sb-access-help">
                        OWNER управляет сайтом и правами. ADMIN редактирует структуру сайта. EDITOR работает с файлами диска. VIEWER только смотрит.
                    </p>

                    <div class="sb-access-form">
                        <div class="sb-field sb-access-search-wrap">
                            <label for="accessUserSearchInput">Пользователь</label>
                            <input class="sb-input" type="text" id="accessUserSearchInput" autocomplete="off" placeholder="ФИО, логин, email или ID">

                            <div id="accessUserSearchResults" class="sb-access-search-results sb-hidden"></div>
                            <div id="accessSelectedUser" class="sb-access-selected sb-hidden"></div>
                        </div>

                        <div class="sb-field">
                            <label for="accessRoleInput">Роль</label>
                            <select class="sb-select" id="accessRoleInput">
                                <option value="VIEWER">VIEWER</option>
                                <option value="EDITOR">EDITOR</option>
                                <option value="ADMIN">ADMIN</option>
                                <option value="OWNER">OWNER</option>
                            </select>
                        </div>
                    </div>

                    <div class="sb-editor-inspector-actions">
                        <button class="sb-btn sb-btn-primary" type="button" id="grantAccessBtn">Выдать роль</button>
                        <button class="sb-btn sb-btn-light" type="button" id="reloadAccessBtn">Обновить</button>
                    </div>

                    <div id="accessMessage" class="sb-empty sb-hidden" style="margin-top:12px;"></div>

                    <div id="accessList" class="sb-access-list">
                        <div class="sb-empty">Права не загружены</div>
                    </div>
                </div>

                <div class="sb-panel" id="apiOutputPanel" hidden>
                    <h2 class="sb-panel-title">Ответ API</h2>
                    <div id="output" class="sb-output">Здесь будут ответы API...</div>
                </div>

                <div id="outputFallback" style="display:none;"></div>
            </div>
        </div>
    </div>
</div>

<?php if ($USER->IsAdmin()): ?>
    <div class="sb-template-modal" id="saveTemplateModal" hidden>
        <div class="sb-template-modal__backdrop" data-close-template-modal></div>

        <div class="sb-template-modal__dialog">
            <div class="sb-template-modal__head">
                <div>
                    <h2 class="sb-template-modal__title">Сохранить сайт как шаблон</h2>
                    <p class="sb-template-modal__subtitle">
                        Шаблон сохранит страницы, вложенность, блоки, layout, меню и оформление. Файлы диска не копируются.
                    </p>
                </div>

                <button class="sb-template-modal__close" type="button" data-close-template-modal>×</button>
            </div>

            <div class="sb-template-modal__body">
                <div class="sb-field">
                    <label for="templateNameInput">Название шаблона</label>
                    <input class="sb-input" type="text" id="templateNameInput" placeholder="Например: Корпоративный портал">
                </div>

                <div class="sb-field" style="margin-top:12px;">
                    <label for="templateDescriptionInput">Описание</label>
                    <textarea class="sb-input" id="templateDescriptionInput" rows="4" placeholder="Кратко опиши, для каких сайтов подходит этот шаблон"></textarea>
                </div>

                <div class="sb-template-note">
                    Создание, изменение и удаление шаблонов доступно только администратору Битрикса.
                </div>

                <div id="templateMessage" class="sb-template-message" hidden></div>
            </div>

            <div class="sb-template-modal__footer">
                <button class="sb-btn sb-btn-light" type="button" data-close-template-modal>Отмена</button>
                <button class="sb-btn sb-btn-primary" type="button" id="createTemplateBtn">Создать шаблон</button>
            </div>
        </div>
    </div>
<?php endif; ?>


<div class="sb-block-library" id="blockLibraryModal" hidden>
    <div class="sb-block-library__backdrop" data-close-block-library></div>
    <div class="sb-block-library__dialog" role="dialog" aria-modal="true" aria-labelledby="blockLibraryTitle">
        <div class="sb-block-library__head">
            <div>
                <div class="sb-block-library__eyebrow">Компоненты страницы</div>
                <h2 id="blockLibraryTitle">Добавить блок</h2>
            </div>
            <button class="sb-template-modal__close" type="button" data-close-block-library>×</button>
        </div>
        <div class="sb-block-library__tools">
            <input class="sb-input" type="search" id="blockLibrarySearch" placeholder="Найти блок...">
            <div class="sb-block-library__categories">
                <button class="is-active" type="button" data-library-category="all">Все</button>
                <button type="button" data-library-category="basic">Базовые</button>
                <button type="button" data-library-category="content">Контент</button>
                <button type="button" data-library-category="marketing">Маркетинг</button>
                <button type="button" data-library-category="data">Данные</button>
                <button type="button" data-library-category="advanced">Служебные</button>
            </div>
        </div>
        <div class="sb-block-library__grid" id="blockLibraryGrid">
            <?php
            $libraryBlocks = [
                ['heading', 'H', 'Заголовок', 'Заголовки H1–H6 с настройкой размера и цвета', 'basic'],
                ['text', '¶', 'Текст', 'Абзацы, списки и форматированный контент', 'basic'],
                ['button', '↗', 'Кнопка', 'Ссылка с несколькими визуальными стилями', 'basic'],
                ['image', '▧', 'Изображение', 'Фото, подпись, ссылка, пропорции и тень', 'content'],
                ['hero', '★', 'Первый экран', 'Крупный заголовок, описание, кнопки и изображение', 'marketing'],
                ['cards', '▦', 'Карточки', 'Сетка услуг, преимуществ или подразделений', 'marketing'],
                ['quote', '“', 'Цитата', 'Отзыв, важная мысль или обращение руководителя', 'content'],
                ['stats', '№', 'Показатели', 'Ключевые цифры и факты в адаптивной сетке', 'data'],
                ['table', '▤', 'Таблица', 'Структурированные данные и редактируемые строки', 'data'],
                ['divider', '—', 'Разделитель', 'Линия, градиент или точки между блоками', 'basic'],
                ['spacer', '↕', 'Отступ', 'Управляемое пустое пространство', 'basic'],
                ['disk', '◫', 'Битрикс.Диск', 'Файлы, папки, поиск и права доступа', 'advanced'],
                ['html', '</>', 'HTML', 'Произвольная проверенная HTML-разметка', 'advanced'],
            ];
            foreach ($libraryBlocks as [$type, $icon, $title, $description, $category]):
            ?>
                <button class="sb-library-card" type="button" data-library-block="<?= htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-category="<?= htmlspecialchars($category, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-search="<?= htmlspecialchars(mb_strtolower($title . ' ' . $description), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <span class="sb-library-card__icon"><?= htmlspecialchars($icon, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <span class="sb-library-card__body"><strong><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><small><?= htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="sb-toast-stack" id="editorToastStack" aria-live="polite"></div>

<script>
window.SB_EDITOR_CONFIG = {
    basePath: '<?= CUtil::JSEscape($basePath) ?>',
    apiUrl: '<?= CUtil::JSEscape($basePath) ?>/api/index.php',
    siteId: <?= (int)$siteId ?>,
    isBitrixAdmin: <?= $USER->IsAdmin() ? 'true' : 'false' ?>,
    sessid: '<?= CUtil::JSEscape(bitrix_sessid()) ?>'
};
</script>

<script src="/bitrix/js/main/core/core.js"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/00-core.js?v=16"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/10-sections.js?v=16"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/20-pages.js?v=16"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/25-visual-builder.js?v=16"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/30-blocks.js?v=16"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/32-visual-blocks.js?v=16"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/35-history.js?v=16"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/40-access.js?v=16"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/50-template.js?v=16"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/60-events.js?v=16"></script>

</body>
</html>