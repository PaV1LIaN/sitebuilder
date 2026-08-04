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
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor.css?v=21">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor-v2.css?v=17">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor-v3.css?v=17">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor-v4.css?v=18">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor-v5.css?v=19">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor-v6.css?v=20">
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
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor.css?v=21">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor-v2.css?v=17">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor-v3.css?v=17">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor-v4.css?v=18">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor-v5.css?v=19">
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <header class="sb-editor-appbar" id="editorAppbar">
        <div class="sb-editor-appbar__brand">
            <a class="sb-editor-appbar__back" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/index.php" aria-label="К списку сайтов">←</a>
            <button class="sb-editor-toptool" type="button" id="togglePagesPanelBtn" title="Свернуть дерево страниц" aria-label="Свернуть дерево страниц" aria-pressed="false">☰</button>
            <div class="sb-editor-appbar__brand-copy">
                <div class="sb-editor-appbar__eyebrow">SiteBuilder · сайт #<?= (int)$siteId ?></div>
                <h1 class="sb-editor-appbar__title">Визуальный редактор</h1>
            </div>
        </div>

        <div class="sb-editor-appbar__center">
            <div class="sb-editor-history-tools" role="group" aria-label="История действий">
                <button class="sb-editor-toptool" type="button" id="undoEditorBtn" title="Отменить (Ctrl+Z)" aria-label="Отменить" disabled>↶</button>
                <button class="sb-editor-toptool" type="button" id="redoEditorBtn" title="Повторить (Ctrl+Shift+Z)" aria-label="Повторить" disabled>↷</button>
            </div>

            <div class="sb-editor-status" id="editorSaveStatus" data-state="ready">
                <span class="sb-editor-status__dot"></span>
                <span class="sb-editor-status__text">Готово</span>
            </div>

            <div class="sb-editor-contextbar__devices" role="group" aria-label="Размер предпросмотра">
                <button class="sb-preview-device is-active" type="button" data-preview-device="desktop" title="Компьютер" aria-label="Компьютер">▣</button>
                <button class="sb-preview-device" type="button" data-preview-device="tablet" title="Планшет" aria-label="Планшет">▯</button>
                <button class="sb-preview-device" type="button" data-preview-device="mobile" title="Телефон" aria-label="Телефон">▯</button>
            </div>
        </div>

        <div class="sb-editor-appbar__actions">
            <button class="sb-editor-toptool" type="button" id="toggleInspectorPanelBtn" title="Свернуть инспектор" aria-label="Свернуть инспектор" aria-pressed="false">⚙</button>
            <button class="sb-editor-toptool" type="button" id="toggleFocusModeBtn" title="Фокус на холсте" aria-label="Фокус на холсте" aria-pressed="false">⛶</button>
            <button class="sb-editor-toptool" type="button" id="toggleEditorThemeBtn" title="Сменить тему" aria-label="Сменить тему" aria-pressed="false">◐</button>

            <a class="sb-btn sb-btn-light sb-btn-small" id="openPublicPageLink" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/public.php?siteId=<?= (int)$siteId ?>" target="_blank" rel="noopener">
                Предпросмотр ↗
            </a>

            <details class="sb-editor-more" id="editorMoreMenu">
                <summary class="sb-btn sb-btn-light sb-btn-small">Ещё</summary>
                <div class="sb-editor-more__menu">
                    <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/settings.php?siteId=<?= (int)$siteId ?>">Настройки сайта</a>
                    <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/layout.php?siteId=<?= (int)$siteId ?>">Layout сайта</a>
                    <?php if ($USER->IsAdmin() || (int)($globalRoleRank ?? 0) >= 3): ?>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/trash.php?siteId=<?= (int)$siteId ?>">Корзина</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/audit.php?siteId=<?= (int)$siteId ?>">Журнал действий</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/jobs.php?siteId=<?= (int)$siteId ?>">Фоновые задания</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/queue_health.php?siteId=<?= (int)$siteId ?>">Состояние очереди</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/alerts.php?siteId=<?= (int)$siteId ?>">Оповещения</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/external_resources.php?siteId=<?= (int)$siteId ?>">Внешние ресурсы</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/backups.php?siteId=<?= (int)$siteId ?>">Резервные копии</a>
                        <a href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/forms.php?siteId=<?= (int)$siteId ?>">Заявки форм</a>
                    <?php endif; ?>
                    <?php if ($USER->IsAdmin()): ?>
                        <button type="button" id="saveAsTemplateBtn">Сохранить в шаблоны</button>
                    <?php endif; ?>
                    <button class="sb-editor-more__danger sb-hidden" type="button" id="deleteSiteBtn">Удалить сайт</button>
                </div>
            </details>
        </div>
    </header>

    <div class="sb-editor-contextbar">
        <div class="sb-editor-contextbar__hint">
            <strong>Совет:</strong> перетаскивайте страницы, секции и блоки прямо на холсте. <kbd>Ctrl</kbd>+<kbd>S</kbd> — сохранить, <kbd>Ctrl</kbd>+<kbd>Z</kbd> — отменить локальное изменение, <kbd>Ctrl</kbd>+<kbd>K</kbd> — библиотека.
        </div>
        <div class="sb-editor-contextbar__selection" id="editorContextSelection">
            <span class="sb-editor-contextbar__selection-dot"></span>
            <span>Выберите страницу</span>
        </div>
    </div>

    <div class="sb-editor-shell" id="editorShell">
        <aside class="sb-editor-col sb-editor-col--pages" id="pagesPanel" aria-label="Страницы сайта">
            <div class="sb-editor-sticky">
                <div class="sb-panel">
                    <div class="sb-editor-section-head">
                        <h2 class="sb-panel-title">Страницы</h2>
                        <span class="sb-badge">siteId <?= (int)$siteId ?></span>
                    </div>

                    <div class="sb-editor-page-search">
                        <input type="search" id="pageSearchInput" placeholder="Найти страницу по названию или slug" autocomplete="off">
                    </div>

                    <details class="sb-editor-create-drawer" id="createPageDrawer">
                        <summary><span>＋ Новая страница</span><small>Добавить в дерево</small></summary>
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
                    </details>

                    <div id="pagesList" class="sb-editor-pages">
                        <div class="sb-empty">Загрузка страниц...</div>
                    </div>
                </div>
            </div>
        </aside>

        <div class="sb-editor-resizer sb-editor-resizer--left" data-panel-resizer="left" role="separator" aria-orientation="vertical" aria-label="Изменить ширину дерева страниц" tabindex="0"></div>

        <main class="sb-editor-col sb-editor-col--canvas" id="canvasPanel">
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
                    <div class="sb-draft-recovery" id="draftRecoveryBanner" hidden>
                        <div>
                            <strong>Найдены локальные изменения</strong>
                            <span id="draftRecoveryText">Можно восстановить несохранённую работу.</span>
                        </div>
                        <div class="sb-draft-recovery__actions">
                            <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="discardDraftBtn">Удалить</button>
                            <button class="sb-btn sb-btn-primary sb-btn-small" type="button" id="restoreDraftBtn">Восстановить</button>
                        </div>
                    </div>
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
        </main>

        <div class="sb-editor-resizer sb-editor-resizer--right" data-panel-resizer="right" role="separator" aria-orientation="vertical" aria-label="Изменить ширину инспектора" tabindex="0"></div>

        <aside class="sb-editor-col sb-editor-col--right" id="inspectorPanel" aria-label="Инспектор настроек">
            <div class="sb-editor-sticky">
                <div class="sb-inspector-tabs" role="tablist" aria-label="Панель настроек">
                    <button class="sb-inspector-tab is-active" type="button" data-inspector-tab="page"><span>▤</span><em>Страница</em></button>
                    <button class="sb-inspector-tab" type="button" data-inspector-tab="section"><span>▦</span><em>Секция</em></button>
                    <button class="sb-inspector-tab" type="button" data-inspector-tab="block"><span>◆</span><em>Блок</em></button>
                    <button class="sb-inspector-tab" type="button" data-inspector-tab="history"><span>↶</span><em>История</em></button>
                    <button class="sb-inspector-tab" type="button" data-inspector-tab="access" id="inspectorAccessTab" hidden><span>♙</span><em>Доступ</em></button>
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

                    <details class="sb-seo-panel" style="margin-top:14px;">
                        <summary>SEO и соцсети</summary>
                        <div class="sb-seo-panel__body">
                            <div class="sb-field"><label for="pageSeoTitleInput">Meta title</label><input class="sb-input" id="pageSeoTitleInput" maxlength="255" placeholder="По умолчанию — название страницы"><small id="pageSeoTitleCounter">0/60</small></div>
                            <div class="sb-field"><label for="pageSeoDescriptionInput">Meta description</label><textarea class="sb-textarea" id="pageSeoDescriptionInput" maxlength="500" rows="4"></textarea><small id="pageSeoDescriptionCounter">0/160</small></div>
                            <div class="sb-field"><label for="pageSeoKeywordsInput">Ключевые слова</label><input class="sb-input" id="pageSeoKeywordsInput" maxlength="500"></div>
                            <div class="sb-field"><label for="pageSeoCanonicalInput">Canonical URL</label><input class="sb-input" id="pageSeoCanonicalInput" placeholder="https://... или /path"></div>
                            <div class="sb-form-grid sb-form-grid--2">
                                <label class="sb-checkbox"><input type="checkbox" id="pageSeoIndexInput" checked><span>Разрешить индексацию</span></label>
                                <label class="sb-checkbox"><input type="checkbox" id="pageSeoFollowInput" checked><span>Переходить по ссылкам</span></label>
                            </div>
                            <div class="sb-field"><label for="pageSeoOgTitleInput">OG title</label><input class="sb-input" id="pageSeoOgTitleInput" maxlength="255"></div>
                            <div class="sb-field"><label for="pageSeoOgDescriptionInput">OG description</label><textarea class="sb-textarea" id="pageSeoOgDescriptionInput" maxlength="500" rows="3"></textarea></div>
                            <div class="sb-field"><label for="pageSeoOgImageInput">OG image</label><div class="sb-input-with-action"><input class="sb-input" id="pageSeoOgImageInput" placeholder="URL изображения"><button class="sb-btn sb-btn-light sb-btn-small" type="button" data-open-media data-media-target="pageSeoOgImageInput">Медиатека</button></div></div>
                            <a class="sb-btn sb-btn-light sb-btn-small" id="pageSitemapLink" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/sitemap.php?siteId=<?= (int)$siteId ?>" target="_blank" rel="noopener">Открыть sitemap.xml ↗</a>
                        </div>
                    </details>

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

                        <details class="sb-block-design-panel" id="blockDesignPanel" style="margin-top:12px;">
                            <summary>Адаптивность и анимация</summary>
                            <div class="sb-block-design-panel__body">
                                <div class="sb-form-row sb-block-visibility-row">
                                    <label><input type="checkbox" id="blockVisibleDesktopInput" checked> Компьютер</label>
                                    <label><input type="checkbox" id="blockVisibleTabletInput" checked> Планшет</label>
                                    <label><input type="checkbox" id="blockVisibleMobileInput" checked> Телефон</label>
                                </div>
                                <div class="sb-form-grid sb-form-grid--3" style="margin-top:12px;">
                                    <div class="sb-field"><label for="blockAnimationInput">Появление</label><select class="sb-select" id="blockAnimationInput"><option value="none">Без анимации</option><option value="fade">Проявление</option><option value="fade-up">Снизу вверх</option><option value="zoom">Масштаб</option><option value="slide-left">Слева</option><option value="slide-right">Справа</option></select></div>
                                    <div class="sb-field"><label for="blockAnimationDelayInput">Задержка, мс</label><input class="sb-input" type="number" id="blockAnimationDelayInput" min="0" max="3000" step="50" value="0"></div>
                                    <div class="sb-field"><label for="blockAnimationDurationInput">Длительность, мс</label><input class="sb-input" type="number" id="blockAnimationDurationInput" min="150" max="3000" step="50" value="600"></div>
                                </div>
                                <div class="sb-form-grid sb-form-grid--2" style="margin-top:12px;">
                                    <div class="sb-field"><label for="blockMarginTopInput">Отступ сверху, px</label><input class="sb-input" type="number" id="blockMarginTopInput" min="0" max="240" value="0"></div>
                                    <div class="sb-field"><label for="blockMarginBottomInput">Отступ снизу, px</label><input class="sb-input" type="number" id="blockMarginBottomInput" min="0" max="240" value="0"></div>
                                </div>
                                <div class="sb-editor-inspector-actions" style="margin-top:12px;">
                                    <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="copyBlockStyleBtn">Копировать стиль</button>
                                    <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="pasteBlockStyleBtn">Вставить стиль</button>
                                </div>
                                <div class="sb-editor-inspector-actions sb-global-block-actions" style="margin-top:8px;">
                                    <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="saveGlobalBlockBtn">Сохранить как глобальный</button>
                                    <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="openGlobalBlocksBtn">Глобальные блоки</button>
                                </div>
                            </div>
                        </details>

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
                                <div class="sb-rich-editor" id="textRichEditorWrap">
                                    <div class="sb-rich-editor__toolbar" id="textRichToolbar" role="toolbar" aria-label="Форматирование текста">
                                        <select class="sb-rich-editor__select" data-rich-command="formatBlock" aria-label="Стиль абзаца">
                                            <option value="p">Обычный текст</option>
                                            <option value="h2">Заголовок H2</option>
                                            <option value="h3">Заголовок H3</option>
                                            <option value="h4">Заголовок H4</option>
                                            <option value="blockquote">Цитата</option>
                                        </select>
                                        <button type="button" data-rich-command="bold" title="Полужирный"><strong>B</strong></button>
                                        <button type="button" data-rich-command="italic" title="Курсив"><em>I</em></button>
                                        <button type="button" data-rich-command="underline" title="Подчёркивание"><u>U</u></button>
                                        <span class="sb-rich-editor__separator"></span>
                                        <button type="button" data-rich-command="insertUnorderedList" title="Маркированный список">•≡</button>
                                        <button type="button" data-rich-command="insertOrderedList" title="Нумерованный список">1≡</button>
                                        <button type="button" data-rich-command="createLink" title="Добавить ссылку">↗</button>
                                        <button type="button" data-rich-command="unlink" title="Удалить ссылку">×↗</button>
                                        <button type="button" data-rich-command="removeFormat" title="Очистить форматирование">Tx</button>
                                    </div>
                                    <div class="sb-rich-editor__surface" id="textRichEditor" contenteditable="true" data-placeholder="Введите текст"></div>
                                </div>
                                <textarea class="sb-textarea sb-rich-editor__source" id="textTextInput" placeholder="Введите текст" aria-hidden="true" tabindex="-1"></textarea>
                                <p class="sb-block-form-note">Редактируй текст визуально. HTML очищается перед публикацией.</p>
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
                            <div class="sb-field"><label for="imageSrcInput">Изображение</label><div class="sb-media-field"><input class="sb-input" type="text" id="imageSrcInput" placeholder="https://... или выберите из медиатеки"><button class="sb-btn sb-btn-light sb-btn-small" type="button" data-open-media data-media-target="imageSrcInput">Медиатека</button></div></div>
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
                                <div class="sb-field"><label for="heroImageSrcInput">Изображение</label><div class="sb-media-field"><input class="sb-input" type="text" id="heroImageSrcInput"><button class="sb-btn sb-btn-light sb-btn-small" type="button" data-open-media data-media-target="heroImageSrcInput">Медиатека</button></div></div>
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
                                    <option value="custom">Индивидуальные права папок</option>
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

                <div class="sb-panel sb-inspector-panel" data-inspector-panel="access" id="pageAccessPanel" hidden>
                    <h2 class="sb-panel-title">Права выбранной страницы</h2>
                    <p class="sb-access-help">
                        <strong id="pageAccessPageTitle">Страница не выбрана</strong><br>
                        Выдайте пользователю просмотр или редактирование только этой страницы. Право можно распространить на дочерние страницы.
                    </p>

                    <div class="sb-access-form">
                        <div class="sb-field sb-access-search-wrap">
                            <label for="pageAccessUserSearchInput">Пользователь</label>
                            <input class="sb-input" type="text" id="pageAccessUserSearchInput" autocomplete="off" placeholder="ФИО, логин, email или ID">

                            <div id="pageAccessUserSearchResults" class="sb-access-search-results sb-hidden"></div>
                            <div id="pageAccessSelectedUser" class="sb-access-selected sb-hidden"></div>
                        </div>

                        <div class="sb-page-access-permissions" aria-label="Права страницы">
                            <label class="sb-switch"><input type="checkbox" id="pageAccessCanView" checked><span>Просмотр страницы</span></label>
                            <label class="sb-switch"><input type="checkbox" id="pageAccessCanEdit"><span>Редактирование страницы</span></label>
                            <label class="sb-switch"><input type="checkbox" id="pageAccessCanDiskView"><span>Просмотр файлов страницы</span></label>
                            <label class="sb-switch"><input type="checkbox" id="pageAccessCanDiskEdit"><span>Изменение файлов страницы</span></label>
                            <label class="sb-switch"><input type="checkbox" id="pageAccessIncludeChildren"><span>Также для дочерних страниц</span></label>
                        </div>
                    </div>

                    <div class="sb-editor-inspector-actions">
                        <button class="sb-btn sb-btn-primary" type="button" id="savePageAccessBtn">Сохранить права</button>
                        <button class="sb-btn sb-btn-light" type="button" id="reloadPageAccessBtn">Обновить</button>
                    </div>

                    <div id="pageAccessMessage" class="sb-empty sb-hidden" style="margin-top:12px;"></div>

                    <div id="pageAccessList" class="sb-access-list">
                        <div class="sb-empty">Выберите страницу</div>
                    </div>
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
                    <h2 class="sb-panel-title">Права на весь сайт</h2>
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
        </aside>
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
        <div class="sb-library-mode-tabs" role="tablist" aria-label="Режим библиотеки">
            <button class="is-active" type="button" data-library-view="blocks">Компоненты</button>
            <button type="button" data-library-view="presets">Готовые секции</button>
            <button type="button" data-open-global-blocks-library>Глобальные</button>
        </div>
        <div class="sb-block-library__tools" data-library-block-tools>
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
        <div class="sb-block-library__grid" id="blockLibraryGrid" data-library-pane="blocks">
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
                    <span class="sb-library-card__preview" aria-hidden="true">
                        <span class="sb-library-card__icon"><?= htmlspecialchars($icon, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <span class="sb-library-card__wire"><i></i><i></i><i></i></span>
                    </span>
                    <span class="sb-library-card__body"><strong><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><small><?= htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small></span>
                </button>
            <?php endforeach; ?>
        </div>
        <div class="sb-section-preset-grid" data-library-pane="presets" hidden>
            <button class="sb-section-preset-card" type="button" data-section-preset="hero_split"><span class="sb-section-preset-card__preview is-hero"><i></i><i></i></span><strong>Первый экран</strong><small>Заголовок, описание, кнопки и изображение</small></button>
            <button class="sb-section-preset-card" type="button" data-section-preset="benefits_cards"><span class="sb-section-preset-card__preview is-cards"><i></i><i></i><i></i></span><strong>Преимущества</strong><small>Заголовок, пояснение и три карточки</small></button>
            <button class="sb-section-preset-card" type="button" data-section-preset="image_text"><span class="sb-section-preset-card__preview is-split"><i></i><i></i></span><strong>Изображение + текст</strong><small>Адаптивный двухколоночный смысловой блок</small></button>
            <button class="sb-section-preset-card" type="button" data-section-preset="stats_band"><span class="sb-section-preset-card__preview is-stats"><i></i><i></i><i></i><i></i></span><strong>Показатели</strong><small>Контрастная полоса с ключевыми цифрами</small></button>
            <button class="sb-section-preset-card" type="button" data-section-preset="call_to_action"><span class="sb-section-preset-card__preview is-cta"><i></i><b></b></span><strong>Призыв к действию</strong><small>Акцентный заголовок, текст и кнопка</small></button>
            <button class="sb-section-preset-card" type="button" data-section-preset="quote_story"><span class="sb-section-preset-card__preview is-quote"><i>“</i><b></b></span><strong>Цитата</strong><small>Отзыв, обращение или история сотрудника</small></button>
        </div>
    </div>
</div>

<div class="sb-media-library" id="mediaLibraryModal" hidden>
    <div class="sb-media-library__backdrop" data-close-media-library></div>
    <div class="sb-media-library__dialog" role="dialog" aria-modal="true" aria-labelledby="mediaLibraryTitle">
        <div class="sb-media-library__head">
            <div><div class="sb-block-library__eyebrow">Битрикс.Диск сайта</div><h2 id="mediaLibraryTitle">Медиатека</h2></div>
            <button class="sb-template-modal__close" type="button" data-close-media-library>×</button>
        </div>
        <div class="sb-media-library__toolbar">
            <input class="sb-input" type="search" id="mediaLibrarySearch" placeholder="Поиск изображений">
            <label class="sb-btn sb-btn-primary sb-btn-small sb-media-upload-label"><input type="file" id="mediaLibraryUpload" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" hidden>Загрузить изображение</label>
            <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="mediaLibraryRefresh">Обновить</button>
        </div>
        <div class="sb-media-library__status" id="mediaLibraryStatus">Загрузка…</div>
        <div class="sb-media-library__grid" id="mediaLibraryGrid"></div>
    </div>
</div>


<div class="sb-global-block-library" id="globalBlocksModal" hidden>
    <div class="sb-global-block-library__backdrop" data-close-global-blocks></div>
    <div class="sb-global-block-library__dialog" role="dialog" aria-modal="true" aria-labelledby="globalBlocksTitle">
        <div class="sb-global-block-library__head">
            <div>
                <div class="sb-block-library__eyebrow">Повторно используемый контент</div>
                <h2 id="globalBlocksTitle">Глобальные блоки</h2>
                <p>Изменение глобального блока автоматически обновляет все связанные экземпляры на сайте.</p>
            </div>
            <button class="sb-template-modal__close" type="button" data-close-global-blocks>×</button>
        </div>
        <div class="sb-global-block-library__toolbar">
            <input class="sb-input" type="search" id="globalBlocksSearch" placeholder="Найти глобальный блок">
            <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="globalBlocksRefresh">Обновить</button>
        </div>
        <div class="sb-global-block-library__status" id="globalBlocksStatus">Загрузка…</div>
        <div class="sb-global-block-library__list" id="globalBlocksList"></div>
    </div>
</div>

<nav class="sb-editor-mobile-dock" id="editorMobileDock" aria-label="Инструменты редактора">
    <button type="button" data-mobile-editor-action="pages"><span>☰</span><em>Страницы</em></button>
    <button type="button" data-mobile-editor-action="add"><span>＋</span><em>Добавить</em></button>
    <button type="button" data-mobile-editor-action="preview"><span>▣</span><em>Вид</em></button>
    <button type="button" data-mobile-editor-action="inspector"><span>⚙</span><em>Настройки</em></button>
</nav>

<div class="sb-inline-toolbar" id="inlineTextToolbar" hidden role="toolbar" aria-label="Форматирование текста">
    <button type="button" data-inline-command="bold" title="Полужирный"><strong>B</strong></button>
    <button type="button" data-inline-command="italic" title="Курсив"><em>I</em></button>
    <button type="button" data-inline-command="underline" title="Подчёркнутый"><u>U</u></button>
    <span></span>
    <button type="button" data-inline-command="createLink" title="Ссылка">↗</button>
    <button type="button" data-inline-command="unlink" title="Удалить ссылку">×↗</button>
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
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/00-core.js?v=21"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/10-sections.js?v=17"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/20-pages.js?v=21"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/25-visual-builder.js?v=21"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/30-blocks.js?v=17"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/32-visual-blocks.js?v=20"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/34-editor-ux.js?v=17"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/36-content-tools.js?v=18"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/38-design-tools.js?v=19"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/39-global-blocks.js?v=19"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/41-business-blocks.js?v=20"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/35-history.js?v=17"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/40-access.js?v=21"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/50-template.js?v=17"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor/60-events.js?v=21"></script>

</body>
</html>
