<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

global $APPLICATION, $USER;

if (!$USER->IsAuthorized()) {
    require $_SERVER['DOCUMENT_ROOT'] . '/auth.php';
    exit;
}

CJSCore::Init(['ajax']);

header('Content-Type: text/html; charset=UTF-8');

$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$siteId = (int)($_GET['siteId'] ?? 0);

if ($siteId <= 0) {
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title>SiteBuilder / Editor</title>
        <?php $APPLICATION->ShowHead(); ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    </head>
    <body class="sb-admin-body">
    <div class="sb-page">
        <h1 class="sb-title">Не передан siteId</h1>
        <p><a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/index.php">Вернуться к списку сайтов</a></p>
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
    <style>
        .sb-editor-shell {
            display: grid;
            grid-template-columns: 320px minmax(0, 1fr) 360px;
            gap: 20px;
            align-items: start;
        }

        .sb-editor-col {
            min-width: 0;
        }

        .sb-editor-sticky {
            position: sticky;
            top: 16px;
        }

        .sb-editor-topline {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 18px;
        }

        .sb-editor-topline-note {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
            max-width: 860px;
            line-height: 1.5;
        }

        .sb-editor-topline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .sb-editor-section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .sb-editor-section-head .sb-panel-title {
            margin: 0;
        }

        .sb-editor-create {
            padding-bottom: 14px;
            margin-bottom: 14px;
            border-bottom: 1px solid #eef2f7;
        }

        .sb-editor-pages {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .sb-editor-page-item {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fafafa;
            padding: 12px;
            cursor: pointer;
            transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
        }

        .sb-editor-page-item:hover {
            border-color: #c7d2fe;
            background: #fcfcff;
        }

        .sb-editor-page-item.is-active {
            border-color: #2563eb;
            background: #eff6ff;
            box-shadow: 0 6px 18px rgba(37, 99, 235, 0.12);
        }

        .sb-editor-page-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .sb-editor-page-title {
            margin: 0 0 6px;
            font-size: 15px;
            font-weight: 700;
            color: #111827;
            line-height: 1.2;
        }

        .sb-editor-page-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }

        .sb-editor-chip {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 0 8px;
            border-radius: 999px;
            background: #f3f4f6;
            color: #4b5563;
            font-size: 12px;
            font-weight: 600;
        }

        .sb-editor-chip--blue {
            background: #eef2ff;
            color: #3730a3;
        }

        .sb-editor-chip--green {
            background: #dcfce7;
            color: #166534;
        }

        .sb-editor-chip--yellow {
            background: #fef3c7;
            color: #92400e;
        }

        .sb-editor-page-actions,
        .sb-editor-block-actions,
        .sb-editor-inspector-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .sb-editor-page-actions .sb-btn,
        .sb-editor-block-actions .sb-btn,
        .sb-editor-inspector-actions .sb-btn {
            height: 32px;
            padding: 0 10px;
            font-size: 12px;
        }

        .sb-editor-canvas {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }

        .sb-editor-canvas-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .sb-editor-canvas-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #111827;
        }

        .sb-editor-canvas-sub {
            margin: 4px 0 0;
            font-size: 13px;
            color: #6b7280;
        }

        .sb-editor-canvas-body {
            background: #f8fafc;
            padding: 24px;
            min-height: 720px;
        }

        .sb-editor-page {
            max-width: 980px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            min-height: 620px;
            padding: 24px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
        }

        .sb-editor-page-heading {
            margin: 0 0 18px;
            font-size: 30px;
            line-height: 1.15;
            font-weight: 700;
            color: #111827;
        }

        .sb-editor-addbar {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 18px;
        }

        .sb-editor-add-card {
            border: 1px solid #dbe3f0;
            border-radius: 14px;
            background: #fff;
            padding: 12px;
            text-align: left;
            cursor: pointer;
            transition: border-color .15s ease, transform .15s ease, box-shadow .15s ease;
        }

        .sb-editor-add-card:hover {
            border-color: #93c5fd;
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.08);
        }

        .sb-editor-add-card__title {
            display: block;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }

        .sb-editor-add-card__text {
            display: block;
            font-size: 12px;
            color: #6b7280;
            line-height: 1.4;
        }

        .sb-editor-blocks {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .sb-editor-block {
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            background: #fff;
            padding: 14px;
            transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
            cursor: pointer;
        }

        .sb-editor-block:hover {
            border-color: #c7d2fe;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.08);
        }

        .sb-editor-block.is-active {
            border-color: #2563eb;
            background: #f8fbff;
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.12);
        }

        .sb-editor-block-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 10px;
        }

        .sb-editor-block-title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }

        .sb-editor-block-preview {
            border: 1px solid #eef2f7;
            background: #fff;
            border-radius: 12px;
            padding: 12px;
            font-size: 14px;
            line-height: 1.6;
            color: #374151;
            min-height: 52px;
        }

        .sb-editor-empty-big {
            padding: 30px 20px;
            text-align: center;
            color: #6b7280;
            border: 1px dashed #d1d5db;
            border-radius: 16px;
            background: #fff;
        }

        .sb-editor-empty-big strong {
            display: block;
            color: #111827;
            margin-bottom: 6px;
            font-size: 16px;
        }

        .sb-editor-note {
            font-size: 13px;
            color: #6b7280;
            line-height: 1.5;
            margin-top: -2px;
            margin-bottom: 12px;
        }

        .sb-editor-json-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .sb-editor-divider {
            border-top: 1px solid #eef2f7;
            margin-top: 14px;
        }

        .sb-editor-group-box {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #f9fafb;
            padding: 12px;
        }

        .sb-editor-group-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .sb-editor-group-row:last-child {
            margin-bottom: 0;
        }

        .sb-editor-group-label {
            color: #6b7280;
        }

        .sb-editor-group-value {
            color: #111827;
            font-weight: 700;
            text-align: right;
        }

        .sb-editor-sync-result {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            font-size: 13px;
            color: #374151;
            line-height: 1.5;
            white-space: pre-line;
        }

        .sb-editor-sync-result.is-success {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }

        .sb-editor-sync-result.is-error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        .sb-access-form {
            display: grid;
            grid-template-columns: 1fr 130px;
            gap: 10px;
            align-items: end;
        }

        .sb-access-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 14px;
        }

        .sb-access-item {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #f9fafb;
        }

        .sb-access-user {
            min-width: 0;
        }

        .sb-access-user__name {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .sb-access-user__meta {
            margin-top: 3px;
            font-size: 12px;
            color: #6b7280;
        }

        .sb-access-role {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 0 8px;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            font-size: 12px;
            font-weight: 700;
        }

        .sb-access-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .sb-access-message {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            background: #f8fafc;
            font-size: 13px;
            color: #374151;
            line-height: 1.45;
        }

        .sb-access-message.is-success {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }

        .sb-access-message.is-error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        .sb-access-search-wrap {
            position: relative;
        }
        
        .sb-access-results {
            display: none;
            position: absolute;
            z-index: 1000;
            left: 0;
            right: 0;
            top: calc(100% + 6px);
            max-height: 260px;
            overflow: auto;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.16);
        }
        
        .sb-access-results.is-open {
            display: block;
        }
        
        .sb-access-result-item {
            width: 100%;
            display: block;
            text-align: left;
            padding: 10px 12px;
            border: 0;
            background: #fff;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .sb-access-result-item:hover {
            background: #f8fafc;
        }
        
        .sb-access-result-title {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
        }
        
        .sb-access-result-meta {
            margin-top: 3px;
            font-size: 12px;
            color: #6b7280;
        }
        
        .sb-access-selected {
            margin-top: 8px;
            padding: 9px 10px;
            border-radius: 12px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #166534;
            font-size: 13px;
            line-height: 1.4;
        }
        
        .sb-access-selected button {
            margin-left: 8px;
        }

        @media (max-width: 1440px) {
            .sb-editor-shell {
                grid-template-columns: 300px minmax(0, 1fr) 330px;
            }

            .sb-editor-addbar {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 1180px) {
            .sb-editor-shell {
                grid-template-columns: 320px minmax(0, 1fr);
            }

            .sb-editor-col--right {
                grid-column: 1 / -1;
            }

            .sb-editor-sticky {
                position: static;
            }
        }

        @media (max-width: 900px) {
            .sb-editor-topline {
                flex-direction: column;
                align-items: stretch;
            }

            .sb-editor-topline-actions {
                justify-content: flex-start;
            }

            .sb-editor-shell {
                grid-template-columns: 1fr;
            }

            .sb-editor-addbar {
                grid-template-columns: 1fr;
            }

            .sb-access-form {
                grid-template-columns: 1fr;
            }

            .sb-access-item {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-topbar">
        <div class="sb-topbar-left">
            <a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/index.php">← К списку сайтов</a>
            <h1 class="sb-title">Редактор сайта</h1>
            <p class="sb-subtitle">siteId = <?= (int)$siteId ?></p>
        </div>
    </div>

    <div class="sb-editor-topline">
        <p class="sb-editor-topline-note">
            Слева — структура страниц. По центру — полотно текущей страницы. Справа — свойства выбранной страницы или блока.
        </p>
        <div class="sb-editor-topline-actions">
            <a class="sb-btn sb-btn-light sb-btn-small" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/public.php?siteId=<?= (int)$siteId ?>" target="_blank">Открыть публичную</a>
            <a class="sb-btn sb-btn-light sb-btn-small" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/layout.php?siteId=<?= (int)$siteId ?>">Layout</a>
            <a class="sb-btn sb-btn-light sb-btn-small" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/menu.php?siteId=<?= (int)$siteId ?>">Меню</a>
            <button class="sb-btn sb-btn-danger sb-btn-small sb-hidden" type="button" id="deleteSiteBtn">Удалить сайт</button>
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
                        <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="movePageUpBtn">Страницу ↑</button>
                        <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="movePageDownBtn">Страницу ↓</button>
                        <button class="sb-btn sb-btn-primary sb-btn-small" type="button" id="publishPageBtn">Опубликовать</button>
                    </div>
                </div>

                <div class="sb-editor-canvas-body">
                    <div class="sb-editor-page">
                        <h2 class="sb-editor-page-heading" id="pagePreviewHeading">Выберите страницу</h2>

                        <div class="sb-editor-addbar">
                            <button class="sb-editor-add-card" type="button" data-add-block="heading">
                                <span class="sb-editor-add-card__title">Заголовок</span>
                                <span class="sb-editor-add-card__text">Большой заголовок или подзаголовок секции</span>
                            </button>
                            <button class="sb-editor-add-card" type="button" data-add-block="text">
                                <span class="sb-editor-add-card__title">Текст</span>
                                <span class="sb-editor-add-card__text">Абзацы, списки и обычный контент</span>
                            </button>
                            <button class="sb-editor-add-card" type="button" data-add-block="button">
                                <span class="sb-editor-add-card__title">Кнопка</span>
                                <span class="sb-editor-add-card__text">CTA-кнопка со ссылкой</span>
                            </button>
                            <button class="sb-editor-add-card" type="button" data-add-block="html">
                                <span class="sb-editor-add-card__title">HTML</span>
                                <span class="sb-editor-add-card__text">Произвольный HTML-блок</span>
                            </button>
                            <button class="sb-editor-add-card" type="button" data-add-block="disk">
                                <span class="sb-editor-add-card__title">Диск</span>
                                <span class="sb-editor-add-card__text">Файлы, папки, загрузка и доступы в контексте сайта</span>
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

        <div class="sb-editor-col sb-editor-col--right">
            <div class="sb-editor-sticky">
                <div class="sb-panel">
                    <h2 class="sb-panel-title">Свойства страницы</h2>
                    <p class="sb-editor-note">Здесь меняются заголовок, slug и статус текущей страницы.</p>

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

                <div class="sb-panel">
                    <h2 class="sb-panel-title">Свойства блока</h2>
                    <div id="blockInspectorEmpty" class="sb-empty">
                        Выбери блок в центре страницы
                    </div>

                    <div id="blockInspector" class="sb-hidden">
                        <div class="sb-field">
                            <label for="blockTypeInput">Тип</label>
                            <input class="sb-input" type="text" id="blockTypeInput" disabled>
                        </div>

                        <div id="diskBlockForm" class="sb-hidden" style="margin-top:12px;">
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

                            <div class="sb-editor-divider"></div>
                        </div>

                        <div class="sb-field" style="margin-top:12px;">
                            <label for="blockContentInput">Контент (JSON)</label>
                            <textarea class="sb-textarea" id="blockContentInput"></textarea>
                        </div>

                        <div class="sb-field" style="margin-top:12px;">
                            <label for="blockPropsInput">Свойства (JSON)</label>
                            <textarea class="sb-textarea" id="blockPropsInput"></textarea>
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

                <div class="sb-panel" id="siteGroupPanel" hidden>
                    <h2 class="sb-panel-title">Группа Битрикс24 и права</h2>
                    <p class="sb-editor-note">
                        Участники группы Битрикс24 могут синхронизироваться с правами сайта.
                    </p>

                    <div class="sb-editor-group-box">
                        <div class="sb-editor-group-row">
                            <span class="sb-editor-group-label">ID группы</span>
                            <span class="sb-editor-group-value" id="bitrixGroupIdText">—</span>
                        </div>

                        <div class="sb-editor-group-row">
                            <span class="sb-editor-group-label">Создана</span>
                            <span class="sb-editor-group-value" id="bitrixGroupCreatedAtText">—</span>
                        </div>

                        <div class="sb-editor-group-row">
                            <span class="sb-editor-group-label">Связь</span>
                            <span class="sb-editor-group-value" id="bitrixGroupStatusText">—</span>
                        </div>
                    </div>

                    <div class="sb-editor-inspector-actions">
                        <button class="sb-btn sb-btn-primary" type="button" id="ensureBitrixGroupBtn">Создать группу Битрикс24</button>
                        <a class="sb-btn sb-btn-light" href="#" target="_blank" id="openBitrixGroupBtn">Открыть группу</a>
                        <button class="sb-btn sb-btn-primary" type="button" id="syncAccessBtn">Синхронизировать права</button>
                    </div>

                    <div id="syncAccessResult" class="sb-editor-sync-result sb-hidden"></div>
                </div>

                <div class="sb-panel" id="siteAccessPanel" hidden>
                    <h2 class="sb-panel-title">Права пользователей</h2>
                    <p class="sb-editor-note">
                        Выдай пользователю роль на сайте. Если у сайта есть группа Битрикс24, пользователь будет добавлен туда автоматически.
                    </p>

                    <div class="sb-access-form">
                        <div class="sb-field sb-access-search-wrap">
                            <label for="accessUserSearchInput">Пользователь</label>
                            <input class="sb-input" type="text" id="accessUserSearchInput" autocomplete="off" placeholder="ФИО, логин, email или ID">
                            <input type="hidden" id="accessUserIdInput" value="">
                    
                            <div id="accessUserSearchResults" class="sb-access-results"></div>
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

                    <div id="accessMessage" class="sb-access-message sb-hidden"></div>

                    <div id="accessList" class="sb-access-list">
                        <div class="sb-empty">Загрузка прав...</div>
                    </div>
                </div>

                <div class="sb-panel" id="apiOutputPanel" hidden>
                    <h2 class="sb-panel-title">Ответ API</h2>
                    <div id="output" class="sb-output">Здесь будут ответы API...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/bitrix/js/main/core/core.js"></script>
<script>
(function () {
    var BASE_PATH = '<?= CUtil::JSEscape($basePath) ?>';
    var API_URL = BASE_PATH + '/api.php';
    var siteId = <?= (int)$siteId ?>;

    var state = {
        site: null,
        pages: [],
        currentPageId: 0,
        blocks: [],
        currentBlockId: 0,
        accessItems: [],
        userSearchResults: [],
        selectedAccessUser: null,
        userSearchTimer: null
    };

    var output = document.getElementById('output');
    var pagesList = document.getElementById('pagesList');
    var blocksList = document.getElementById('blocksList');
    var newPageParentId = document.getElementById('newPageParentId');

    function print(data) {
        try {
            output.textContent = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
        } catch (e) {
            output.textContent = String(data);
        }
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getSessid() {
        if (window.BX && typeof BX.bitrix_sessid === 'function') {
            return BX.bitrix_sessid();
        }
        return '';
    }

    function api(action, data) {
        return new Promise(function (resolve, reject) {
            BX.ajax({
                url: API_URL,
                method: 'POST',
                dataType: 'json',
                timeout: 60,
                data: Object.assign({
                    action: action,
                    sessid: getSessid()
                }, data || {}),
                onsuccess: function (res) {
                    print(res);
                    if (res && res.ok) {
                        resolve(res);
                    } else {
                        reject(res || {error: 'UNKNOWN'});
                    }
                },
                onfailure: function (err) {
                    reject(err);
                }
            });
        });
    }

    function getCurrentPage() {
        return state.pages.find(function (page) {
            return Number(page.id || 0) === state.currentPageId;
        }) || null;
    }

    function getCurrentBlock() {
        return state.blocks.find(function (block) {
            return Number(block.id || 0) === state.currentBlockId;
        }) || null;
    }

    function pageHasChildren(pageId) {
        return state.pages.some(function (page) {
            return Number(page.parentId || 0) === Number(pageId || 0);
        });
    }

    function buildPageTree(pages, parentId, depth, result) {
        result = result || [];
        depth = depth || 0;

        var branch = pages
            .filter(function (page) {
                return Number(page.parentId || 0) === Number(parentId || 0);
            })
            .sort(function (a, b) {
                var sortCmp = Number(a.sort || 0) - Number(b.sort || 0);
                if (sortCmp !== 0) return sortCmp;
                return Number(a.id || 0) - Number(b.id || 0);
            });

        branch.forEach(function (page) {
            result.push({
                page: page,
                depth: depth
            });
            buildPageTree(pages, Number(page.id || 0), depth + 1, result);
        });

        return result;
    }

    async function loadSite() {
        var res = await api('site.get', {siteId: siteId});
        state.site = res.site || null;
        renderBitrixGroupPanel();
    }

    function renderBitrixGroupPanel() {
        var site = state.site || {};
        var groupId = Number(site.bitrixGroupId || 0);
        var groupUrl = site.bitrixGroupUrl || (groupId > 0 ? '/workgroups/group/' + groupId + '/' : '');
        var createdAt = site.bitrixGroupCreatedAt || '';

        var groupIdText = document.getElementById('bitrixGroupIdText');
        var createdAtText = document.getElementById('bitrixGroupCreatedAtText');
        var statusText = document.getElementById('bitrixGroupStatusText');
        var openBtn = document.getElementById('openBitrixGroupBtn');
        var syncBtn = document.getElementById('syncAccessBtn');
        var ensureBtn = document.getElementById('ensureBitrixGroupBtn');

        if (groupIdText) {
            groupIdText.textContent = groupId > 0 ? String(groupId) : 'Не создана';
        }

        if (createdAtText) {
            createdAtText.textContent = createdAt || '—';
        }

        if (statusText) {
            statusText.textContent = groupId > 0 ? 'Группа привязана' : 'Группа не привязана';
        }

        if (openBtn) {
            if (groupId > 0 && groupUrl) {
                openBtn.href = groupUrl;
                openBtn.classList.remove('sb-hidden');
            } else {
                openBtn.href = '#';
                openBtn.classList.add('sb-hidden');
            }
        }

        if (syncBtn) {
            syncBtn.disabled = groupId <= 0;
            syncBtn.title = groupId > 0 ? '' : 'Сначала нужно создать группу Битрикс24';

            if (groupId > 0) {
                syncBtn.classList.remove('sb-hidden');
            } else {
                syncBtn.classList.add('sb-hidden');
            }
        }

        if (ensureBtn) {
            if (groupId > 0) {
                ensureBtn.classList.add('sb-hidden');
            } else {
                ensureBtn.classList.remove('sb-hidden');
            }
        }
    }

    function setManagementPanelsVisible(canManage) {
        var groupPanel = document.getElementById('siteGroupPanel');
        var accessPanel = document.getElementById('siteAccessPanel');
        var apiPanel = document.getElementById('apiOutputPanel');
        var deleteSiteBtn = document.getElementById('deleteSiteBtn');

        if (groupPanel) {
            groupPanel.hidden = !canManage;
        }

        if (accessPanel) {
            accessPanel.hidden = !canManage;
        }

        if (apiPanel) {
            apiPanel.hidden = !canManage;
        }

        if (deleteSiteBtn) {
            deleteSiteBtn.classList.toggle('sb-hidden', !canManage);
        }
    }

    async function ensureBitrixGroup() {
        var resultNode = document.getElementById('syncAccessResult');
        var btn = document.getElementById('ensureBitrixGroupBtn');

        if (resultNode) {
            resultNode.classList.remove('sb-hidden', 'is-success', 'is-error');
            resultNode.textContent = 'Создание группы Битрикс24...';
        }

        if (btn) {
            btn.disabled = true;
        }

        try {
            var res = await api('site.ensureGroup', {
                siteId: siteId
            });

            state.site = res.site || state.site;

            if (resultNode) {
                resultNode.classList.add('is-success');
                resultNode.textContent =
                    res.created
                        ? 'Группа Битрикс24 создана. ID группы: ' + Number(res.bitrixGroupId || 0)
                        : 'Группа уже была создана. ID группы: ' + Number(res.bitrixGroupId || 0);
            }

            await loadSite();
        } catch (e) {
            if (resultNode) {
                resultNode.classList.add('is-error');
                resultNode.textContent = 'Ошибка создания группы: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR');
            }
        } finally {
            if (btn) {
                btn.disabled = false;
            }

            renderBitrixGroupPanel();
        }
    }

    async function syncAccessFromBitrixGroup() {
        var resultNode = document.getElementById('syncAccessResult');
        var btn = document.getElementById('syncAccessBtn');

        if (resultNode) {
            resultNode.classList.remove('sb-hidden', 'is-success', 'is-error');
            resultNode.textContent = 'Синхронизация...';
        }

        if (btn) {
            btn.disabled = true;
        }

        try {
            var res = await api('site.syncAccess', {
                siteId: siteId
            });

            var result = res.result || {};

            if (resultNode) {
                resultNode.classList.add('is-success');
                resultNode.textContent =
                    'Права синхронизированы.\n' +
                    'Создано: ' + Number(result.created || 0) + '\n' +
                    'Обновлено: ' + Number(result.updated || 0) + '\n' +
                    'Удалено: ' + Number(result.removed || 0) + '\n' +
                    'Без изменений: ' + Number(result.kept || 0);
            }

            await loadSite();
            await loadAccessList();
        } catch (e) {
            if (resultNode) {
                resultNode.classList.add('is-error');
                resultNode.textContent = 'Ошибка синхронизации: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR');
            }
        } finally {
            if (btn) {
                btn.disabled = false;
            }

            renderBitrixGroupPanel();
        }
    }

    function setAccessMessage(message, type) {
        var node = document.getElementById('accessMessage');
        if (!node) return;

        node.classList.remove('sb-hidden', 'is-success', 'is-error');

        if (type === 'success') {
            node.classList.add('is-success');
        }

        if (type === 'error') {
            node.classList.add('is-error');
        }

        node.textContent = message || '';
    }

    function hideAccessMessage() {
        var node = document.getElementById('accessMessage');
        if (!node) return;

        node.classList.add('sb-hidden');
        node.textContent = '';
    }

    function clearAccessUserSearchResults() {
        var results = document.getElementById('accessUserSearchResults');
        if (!results) return;

        results.classList.remove('is-open');
        results.innerHTML = '';
    }

    function renderAccessUserSearchResults(users) {
        var results = document.getElementById('accessUserSearchResults');
        if (!results) return;

        state.userSearchResults = Array.isArray(users) ? users : [];

        if (!state.userSearchResults.length) {
            results.innerHTML = ''
                + '<div class="sb-access-result-item">'
                + '  <div class="sb-access-result-title">Ничего не найдено</div>'
                + '  <div class="sb-access-result-meta">Попробуй другой запрос</div>'
                + '</div>';
            results.classList.add('is-open');
            return;
        }

        results.innerHTML = state.userSearchResults.map(function (user) {
            var id = Number(user.id || 0);
            var title = user.title || ('Пользователь #' + id);
            var meta = [];

            if (user.login) meta.push(user.login);
            if (user.email) meta.push(user.email);

            return ''
                + '<button class="sb-access-result-item" type="button" data-select-access-user="' + id + '">'
                + '  <div class="sb-access-result-title">' + escapeHtml(title) + '</div>'
                + '  <div class="sb-access-result-meta">ID: ' + id + (meta.length ? ' · ' + escapeHtml(meta.join(' · ')) : '') + '</div>'
                + '</button>';
        }).join('');

        results.classList.add('is-open');
    }

    async function searchAccessUsers() {
        var input = document.getElementById('accessUserSearchInput');
        if (!input) return;

        var query = String(input.value || '').trim();

        state.selectedAccessUser = null;

        var hidden = document.getElementById('accessUserIdInput');
        if (hidden) {
            hidden.value = '';
        }

        var selectedNode = document.getElementById('accessSelectedUser');
        if (selectedNode) {
            selectedNode.classList.add('sb-hidden');
            selectedNode.innerHTML = '';
        }

        if (query === '') {
            clearAccessUserSearchResults();
            return;
        }

        if (!/^\d+$/.test(query) && query.length < 2) {
            clearAccessUserSearchResults();
            return;
        }

        try {
            var res = await api('user.search', {
                siteId: siteId,
                query: query,
                limit: 10
            });

            renderAccessUserSearchResults(Array.isArray(res.users) ? res.users : []);
        } catch (e) {
            renderAccessUserSearchResults([]);
        }
    }

    function selectAccessUser(userId) {
        userId = Number(userId || 0);

        if (userId <= 0) return;

        var user = state.userSearchResults.find(function (u) {
            return Number(u.id || 0) === userId;
        });

        if (!user) {
            return;
        }

        state.selectedAccessUser = user;

        var hidden = document.getElementById('accessUserIdInput');
        if (hidden) {
            hidden.value = String(userId);
        }

        var input = document.getElementById('accessUserSearchInput');
        if (input) {
            input.value = user.title || ('Пользователь #' + userId);
        }

        var selectedNode = document.getElementById('accessSelectedUser');
        if (selectedNode) {
            var meta = [];

            if (user.login) meta.push(user.login);
            if (user.email) meta.push(user.email);

            selectedNode.innerHTML = ''
                + '<strong>' + escapeHtml(user.title || ('Пользователь #' + userId)) + '</strong>'
                + '<br>ID: ' + userId
                + (meta.length ? ' · ' + escapeHtml(meta.join(' · ')) : '')
                + ' <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-clear-access-user>Сбросить</button>';

            selectedNode.classList.remove('sb-hidden');
        }

        clearAccessUserSearchResults();
    }

    function clearSelectedAccessUser() {
        state.selectedAccessUser = null;

        var hidden = document.getElementById('accessUserIdInput');
        if (hidden) {
            hidden.value = '';
        }

        var input = document.getElementById('accessUserSearchInput');
        if (input) {
            input.value = '';
            input.focus();
        }

        var selectedNode = document.getElementById('accessSelectedUser');
        if (selectedNode) {
            selectedNode.classList.add('sb-hidden');
            selectedNode.innerHTML = '';
        }

        clearAccessUserSearchResults();
    }

    function renderAccessList() {
        var panel = document.getElementById('siteAccessPanel');
        var list = document.getElementById('accessList');

        if (!panel || !list) return;

        panel.hidden = false;

        var items = Array.isArray(state.accessItems) ? state.accessItems : [];

        if (!items.length) {
            list.innerHTML = '<div class="sb-empty">Права пока не выданы</div>';
            return;
        }

        list.innerHTML = items.map(function (item) {
            var userId = Number(item.userId || 0);
            var userName = item.userName || ('Пользователь #' + userId);
            var role = item.role || '';

            return ''
                + '<div class="sb-access-item" data-access-user-id="' + userId + '">'
                + '  <div class="sb-access-user">'
                + '      <div class="sb-access-user__name">' + escapeHtml(userName) + '</div>'
                + '      <div class="sb-access-user__meta">U' + userId + ' · ' + escapeHtml(item.accessCode || '') + '</div>'
                + '  </div>'
                + '  <div class="sb-access-actions">'
                + '      <span class="sb-access-role">' + escapeHtml(role) + '</span>'
                + '      <button class="sb-btn sb-btn-danger sb-btn-small" type="button" data-access-remove-user="' + userId + '">Удалить</button>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    async function loadAccessList() {
        var panel = document.getElementById('siteAccessPanel');
        if (!panel) return;

        try {
            var res = await api('site.accessList', {
                siteId: siteId
            });

            state.accessItems = Array.isArray(res.items) ? res.items : [];

            setManagementPanelsVisible(true);
            renderBitrixGroupPanel();
            renderAccessList();
        } catch (e) {
            state.accessItems = [];
            setManagementPanelsVisible(false);
        }
    }

    async function grantAccessRole() {
        var userIdInput = document.getElementById('accessUserIdInput');
        var roleInput = document.getElementById('accessRoleInput');

        if (!userIdInput || !roleInput) return;

        var userId = Number(userIdInput.value || 0);
        var role = String(roleInput.value || '').trim();

        if (userId <= 0) {
            setAccessMessage('Сначала найди и выбери пользователя из списка', 'error');

            var searchInput = document.getElementById('accessUserSearchInput');
            if (searchInput) {
                searchInput.focus();
            }

            return;
        }

        if (!role) {
            setAccessMessage('Выбери роль', 'error');
            return;
        }

        try {
            setAccessMessage('Сохраняю права...', '');

            var res = await api('site.accessSet', {
                siteId: siteId,
                userId: userId,
                role: role
            });

            state.accessItems = Array.isArray(res.items) ? res.items : [];

            clearSelectedAccessUser();
            renderAccessList();

            var groupSync = res.result && res.result.groupSync ? res.result.groupSync : null;
            var syncText = '';

            if (groupSync) {
                if (groupSync.ok) {
                    syncText = '\nПользователь также синхронизирован с группой Битрикс24.';
                } else if (groupSync.error) {
                    syncText = '\nНо с группой Битрикс24 не синхронизировался: ' + groupSync.error;
                } else if (groupSync.message) {
                    syncText = '\nГруппа Битрикс24: ' + groupSync.message;
                }
            }

            setAccessMessage('Роль выдана: U' + userId + ' → ' + role + syncText, 'success');
        } catch (e) {
            setAccessMessage('Ошибка выдачи роли: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        }
    }

    async function removeAccessRole(userId) {
        userId = Number(userId || 0);

        if (userId <= 0) return;

        if (!confirm('Удалить права пользователя U' + userId + '?')) {
            return;
        }

        try {
            setAccessMessage('Удаляю права...', '');

            var res = await api('site.accessRemove', {
                siteId: siteId,
                userId: userId
            });

            state.accessItems = Array.isArray(res.items) ? res.items : [];
            renderAccessList();

            setAccessMessage('Права пользователя U' + userId + ' удалены', 'success');
        } catch (e) {
            setAccessMessage('Ошибка удаления прав: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        }
    }

    async function loadPages() {
        var res = await api('page.list', {siteId: siteId});
        state.pages = Array.isArray(res.pages) ? res.pages : [];

        if (!state.currentPageId && state.pages.length) {
            state.currentPageId = Number(state.pages[0].id || 0);
        }

        fillParentOptions();
        renderPages();
        fillPageForm();
        updateCanvasHeader();
    }

    async function loadBlocks() {
        if (!state.currentPageId) {
            state.blocks = [];
            state.currentBlockId = 0;
            renderBlocks();
            fillBlockForm();
            return;
        }

        var res = await api('block.list', {pageId: state.currentPageId});
        state.blocks = Array.isArray(res.blocks) ? res.blocks : [];

        if (state.currentBlockId) {
            var exists = state.blocks.some(function (b) {
                return Number(b.id || 0) === state.currentBlockId;
            });
            if (!exists) {
                state.currentBlockId = 0;
            }
        }

        renderBlocks();
        fillBlockForm();
        updateCanvasHeader();
    }

    function fillParentOptions() {
        var currentValue = String(newPageParentId.value || '0');
        var html = '<option value="0">Без родителя</option>';

        state.pages.forEach(function (page) {
            html += '<option value="' + Number(page.id || 0) + '">' + escapeHtml(page.title || ('Страница #' + page.id)) + '</option>';
        });

        newPageParentId.innerHTML = html;
        newPageParentId.value = currentValue;
    }

    function renderPages() {
        if (!state.pages.length) {
            pagesList.innerHTML = '<div class="sb-empty">Страниц пока нет</div>';
            return;
        }

        var tree = buildPageTree(state.pages, 0, 0, []);

        pagesList.innerHTML = tree.map(function (item) {
            var page = item.page;
            var depth = item.depth;
            var active = Number(page.id || 0) === state.currentPageId ? ' is-active' : '';
            var hasChildren = pageHasChildren(page.id);
            var status = String(page.status || 'draft');

            return ''
                + '<div class="sb-editor-page-item' + active + '" data-page-id="' + Number(page.id || 0) + '" style="margin-left:' + (depth * 18) + 'px;">'
                + '  <div class="sb-editor-page-top">'
                + '      <div>'
                + '          <h3 class="sb-editor-page-title">' + escapeHtml(page.title || '') + '</h3>'
                + '          <div class="sb-editor-page-meta">'
                +               '<span class="sb-editor-chip">' + escapeHtml(page.slug || '') + '</span>'
                +               '<span class="sb-editor-chip ' + (status === 'published' ? 'sb-editor-chip--green' : 'sb-editor-chip--yellow') + '">' + escapeHtml(status) + '</span>'
                +               (hasChildren ? '<span class="sb-editor-chip sb-editor-chip--blue">section</span>' : '')
                + '          </div>'
                + '      </div>'
                + '  </div>'
                + '  <div class="sb-editor-page-actions">'
                + '      <button class="sb-btn sb-btn-light" type="button" data-select-page="' + Number(page.id || 0) + '">Открыть</button>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    function updateCanvasHeader() {
        var page = getCurrentPage();
        var pageTitle = document.getElementById('canvasPageTitle');
        var pageMeta = document.getElementById('canvasPageMeta');
        var previewHeading = document.getElementById('pagePreviewHeading');

        if (!page) {
            pageTitle.textContent = 'Страница';
            pageMeta.textContent = 'Выберите страницу слева';
            previewHeading.textContent = 'Выберите страницу';
            return;
        }

        pageTitle.textContent = page.title || 'Страница';
        pageMeta.textContent = 'slug: ' + (page.slug || '') + ' · статус: ' + (page.status || 'draft') + ' · блоков: ' + state.blocks.length;
        previewHeading.textContent = page.title || 'Страница';
    }

    function blockPreviewText(block) {
        var type = String(block.type || '');
        var content = block.content || {};
        var props = block.props || {};

        if (type === 'heading') {
            return content.text || '[пустой заголовок]';
        }

        if (type === 'text') {
            return content.text || '[пустой текст]';
        }

        if (type === 'button') {
            return (content.label || 'Кнопка') + (content.href ? ' → ' + content.href : '');
        }

        if (type === 'html') {
            return (content.html || '').slice(0, 220) || '[пустой HTML]';
        }

        if (type === 'disk') {
            var title = props.title || 'Файлы';
            var mode = props.rootMode || 'site';
            var view = props.viewMode || 'table';

            return 'Компонент "Диск": ' + title + ' · rootMode=' + mode + ' · view=' + view;
        }

        try {
            return JSON.stringify(content);
        } catch (e) {
            return '[контент блока]';
        }
    }

    function renderBlocks() {
        if (!state.currentPageId) {
            blocksList.innerHTML = ''
                + '<div class="sb-editor-empty-big">'
                + '   <strong>Страница не выбрана</strong>'
                + '   Выбери страницу слева, чтобы редактировать блоки'
                + '</div>';
            return;
        }

        if (!state.blocks.length) {
            blocksList.innerHTML = ''
                + '<div class="sb-editor-empty-big">'
                + '   <strong>На странице пока нет блоков</strong>'
                + '   Добавь первый блок через панель сверху'
                + '</div>';
            return;
        }

        blocksList.innerHTML = state.blocks.map(function (block) {
            var active = Number(block.id || 0) === state.currentBlockId ? ' is-active' : '';

            return ''
                + '<div class="sb-editor-block' + active + '" data-block-id="' + Number(block.id || 0) + '">'
                + '  <div class="sb-editor-block-head">'
                + '      <div>'
                + '          <h3 class="sb-editor-block-title">' + escapeHtml(block.type || 'block') + '</h3>'
                + '          <div class="sb-editor-chip">block #' + Number(block.id || 0) + '</div>'
                + '      </div>'
                + '  </div>'
                + '  <div class="sb-editor-block-preview">' + escapeHtml(blockPreviewText(block)) + '</div>'
                + '  <div class="sb-editor-block-actions">'
                + '      <button class="sb-btn sb-btn-light" type="button" data-select-block="' + Number(block.id || 0) + '">Выбрать</button>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    function fillPageForm() {
        var page = getCurrentPage();

        fillPageParentEditorOptions();

        document.getElementById('pageTitleInput').value = page ? (page.title || '') : '';
        document.getElementById('pageSlugInput').value = page ? (page.slug || '') : '';
        document.getElementById('pageStatusInput').value = page ? (page.status || 'draft') : 'draft';

        var parentSelect = document.getElementById('pageParentInput');
        if (parentSelect) {
            parentSelect.value = page ? String(page.parentId || 0) : '0';
        }
    }

    function fillBlockForm() {
        var block = getCurrentBlock();
        var emptyNode = document.getElementById('blockInspectorEmpty');
        var formNode = document.getElementById('blockInspector');
        var diskForm = document.getElementById('diskBlockForm');

        if (!block) {
            emptyNode.classList.remove('sb-hidden');
            formNode.classList.add('sb-hidden');
            if (diskForm) diskForm.classList.add('sb-hidden');

            document.getElementById('blockTypeInput').value = '';
            document.getElementById('blockContentInput').value = '';
            document.getElementById('blockPropsInput').value = '';
            return;
        }

        emptyNode.classList.add('sb-hidden');
        formNode.classList.remove('sb-hidden');

        document.getElementById('blockTypeInput').value = block.type || '';

        var content = block.content || {};
        var props = block.props || {};

        document.getElementById('blockContentInput').value = JSON.stringify(content, null, 2);
        document.getElementById('blockPropsInput').value = JSON.stringify(props, null, 2);

        if (block.type === 'disk') {
            if (diskForm) diskForm.classList.remove('sb-hidden');

            document.getElementById('diskTitleInput').value = props.title || 'Файлы';
            document.getElementById('diskRootModeInput').value = props.rootMode || 'site';
            document.getElementById('diskViewModeInput').value = props.viewMode || 'table';
            document.getElementById('diskPermissionModeInput').value = props.permissionMode || 'inherit_site';
            document.getElementById('diskMaxFileSizeInput').value = props.maxFileSize || 52428800;
            document.getElementById('diskAllowedExtensionsInput').value = Array.isArray(props.allowedExtensions) ? props.allowedExtensions.join(' ') : '';

            document.getElementById('diskAllowUploadInput').checked = !!props.allowUpload;
            document.getElementById('diskAllowCreateFolderInput').checked = !!props.allowCreateFolder;
            document.getElementById('diskAllowRenameInput').checked = !!props.allowRename;
            document.getElementById('diskAllowDeleteInput').checked = !!props.allowDelete;
            document.getElementById('diskAllowDownloadInput').checked = !!props.allowDownload;
            document.getElementById('diskShowSearchInput').checked = !!props.showSearch;
            document.getElementById('diskShowBreadcrumbsInput').checked = !!props.showBreadcrumbs;
            document.getElementById('diskUseSiteRootFallbackInput').checked = !!props.useSiteRootFallback;
        } else {
            if (diskForm) diskForm.classList.add('sb-hidden');
        }
    }

    async function createPage() {
        var title = (document.getElementById('newPageTitle').value || '').trim();
        var slug = (document.getElementById('newPageSlug').value || '').trim();
        var parentId = Number(document.getElementById('newPageParentId').value || 0);

        if (!title) {
            alert('Введите название страницы');
            return;
        }

        await api('page.create', {
            siteId: siteId,
            title: title,
            slug: slug,
            parentId: parentId
        });

        document.getElementById('newPageTitle').value = '';
        document.getElementById('newPageSlug').value = '';
        document.getElementById('newPageParentId').value = '0';

        await loadPages();
        await loadBlocks();
    }

    async function savePage() {
        if (!state.currentPageId) return;

        var parentId = Number(document.getElementById('pageParentInput').value || 0);

        await api('page.updateMeta', {
            id: state.currentPageId,
            title: document.getElementById('pageTitleInput').value.trim(),
            slug: document.getElementById('pageSlugInput').value.trim(),
            parentId: parentId
        });

        await api('page.setStatus', {
            id: state.currentPageId,
            status: document.getElementById('pageStatusInput').value
        });

        await loadPages();
        await loadBlocks();
    }

    async function deletePage() {
        if (!state.currentPageId) return;
        if (!confirm('Удалить страницу?')) return;

        var idToDelete = state.currentPageId;
        await api('page.delete', {id: idToDelete});

        if (state.currentPageId === idToDelete) {
            state.currentPageId = 0;
        }

        await loadPages();
        await loadBlocks();
    }

    async function movePage(dir) {
        if (!state.currentPageId) return;
        await api('page.move', {id: state.currentPageId, dir: dir});
        await loadPages();
    }

    async function createBlock(type) {
        if (!state.currentPageId) {
            alert('Сначала выберите страницу');
            return;
        }

        var content = {};
        var props = {};

        if (type === 'heading') {
            content = { text: 'Новый заголовок' };
        } else if (type === 'text') {
            content = { text: 'Новый текстовый блок' };
        } else if (type === 'button') {
            content = { label: 'Кнопка', href: '#' };
        } else if (type === 'html') {
            content = { html: '<div>Новый HTML блок</div>' };
        } else if (type === 'disk') {
            content = {};
            props = {
                title: 'Файлы',
                rootMode: 'site',
                rootFolderId: null,
                viewMode: 'table',
                allowUpload: true,
                allowCreateFolder: true,
                allowRename: true,
                allowDelete: false,
                allowDownload: true,
                showSearch: true,
                showBreadcrumbs: true,
                defaultSort: 'updatedAt',
                defaultSortDirection: 'desc',
                allowedExtensions: [],
                maxFileSize: 52428800,
                permissionMode: 'inherit_site',
                useSiteRootFallback: true
            };
        }

        await api('block.create', {
            pageId: state.currentPageId,
            type: type,
            content: JSON.stringify(content),
            props: JSON.stringify(props)
        });

        await loadBlocks();
    }

    async function saveBlock() {
        var block = getCurrentBlock();
        if (!block) return;

        var content;
        var props;

        try {
            content = JSON.parse(document.getElementById('blockContentInput').value || '{}');
        } catch (e) {
            alert('Контент блока должен быть валидным JSON');
            return;
        }

        try {
            props = JSON.parse(document.getElementById('blockPropsInput').value || '{}');
        } catch (e) {
            alert('Свойства блока должны быть валидным JSON');
            return;
        }

        if (block.type === 'disk') {
            props = {
                title: document.getElementById('diskTitleInput').value.trim() || 'Файлы',
                rootMode: document.getElementById('diskRootModeInput').value,
                rootFolderId: props.rootFolderId || null,
                viewMode: document.getElementById('diskViewModeInput').value,
                permissionMode: document.getElementById('diskPermissionModeInput').value,
                maxFileSize: Number(document.getElementById('diskMaxFileSizeInput').value || 0),
                allowedExtensions: String(document.getElementById('diskAllowedExtensionsInput').value || '')
                    .trim()
                    .split(/\s+/)
                    .filter(Boolean),

                allowUpload: document.getElementById('diskAllowUploadInput').checked,
                allowCreateFolder: document.getElementById('diskAllowCreateFolderInput').checked,
                allowRename: document.getElementById('diskAllowRenameInput').checked,
                allowDelete: document.getElementById('diskAllowDeleteInput').checked,
                allowDownload: document.getElementById('diskAllowDownloadInput').checked,
                showSearch: document.getElementById('diskShowSearchInput').checked,
                showBreadcrumbs: document.getElementById('diskShowBreadcrumbsInput').checked,
                useSiteRootFallback: document.getElementById('diskUseSiteRootFallbackInput').checked,
                defaultSort: props.defaultSort || 'updatedAt',
                defaultSortDirection: props.defaultSortDirection || 'desc'
            };

            document.getElementById('blockPropsInput').value = JSON.stringify(props, null, 2);
        }

        await api('block.update', {
            id: block.id,
            content: JSON.stringify(content),
            props: JSON.stringify(props)
        });

        await loadBlocks();
    }

    async function duplicateBlock() {
        var block = getCurrentBlock();
        if (!block) return;

        await api('block.duplicate', {id: block.id});
        await loadBlocks();
    }

    async function deleteBlock() {
        var block = getCurrentBlock();
        if (!block) return;
        if (!confirm('Удалить блок?')) return;

        await api('block.delete', {id: block.id});
        state.currentBlockId = 0;
        await loadBlocks();
    }

    async function moveBlock(dir) {
        var block = getCurrentBlock();
        if (!block) return;

        await api('block.move', {
            id: block.id,
            dir: dir
        });

        await loadBlocks();
    }

    async function deleteCurrentSite() {
        var siteName = state.site && state.site.name ? state.site.name : ('siteId ' + siteId);

        var firstConfirm = confirm(
            'Удалить сайт "' + siteName + '"?\n\n' +
            'Будут удалены страницы, блоки, меню, доступы, шаблоны и layout внутри SiteBuilder.\n' +
            'Группа Битрикс24 и файлы диска сейчас не удаляются автоматически.'
        );

        if (!firstConfirm) {
            return;
        }

        var secondConfirm = confirm(
            'Подтверди удаление ещё раз.\n\n' +
            'Это действие нельзя будет отменить через интерфейс SiteBuilder.'
        );

        if (!secondConfirm) {
            return;
        }

        try {
            await api('site.delete', {
                id: siteId
            });

            alert('Сайт удалён');
            window.location.href = BASE_PATH + '/index.php';
        } catch (e) {
            var message = (e && (e.error || e.message)) ? (e.error || e.message) : 'UNKNOWN_ERROR';
            alert('Не удалось удалить сайт: ' + message);
        }
    }

    pagesList.addEventListener('click', async function (e) {
        var item = e.target.closest('[data-page-id]');
        if (!item) return;

        state.currentPageId = Number(item.getAttribute('data-page-id') || 0);
        state.currentBlockId = 0;
        renderPages();
        fillPageForm();
        await loadBlocks();
    });

    blocksList.addEventListener('click', function (e) {
        var item = e.target.closest('[data-block-id]');
        if (!item) return;

        state.currentBlockId = Number(item.getAttribute('data-block-id') || 0);
        renderBlocks();
        fillBlockForm();
    });

    document.getElementById('createPageBtn').addEventListener('click', createPage);
    document.getElementById('savePageBtn').addEventListener('click', savePage);
    document.getElementById('deletePageBtn').addEventListener('click', deletePage);
    document.getElementById('movePageUpBtn').addEventListener('click', function () { movePage('up'); });
    document.getElementById('movePageDownBtn').addEventListener('click', function () { movePage('down'); });
    document.getElementById('publishPageBtn').addEventListener('click', async function () {
        if (!state.currentPageId) return;
        await api('page.setStatus', {id: state.currentPageId, status: 'published'});
        await loadPages();
    });

    document.querySelectorAll('[data-add-block]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            createBlock(btn.getAttribute('data-add-block'));
        });
    });

    document.getElementById('saveBlockBtn').addEventListener('click', saveBlock);
    document.getElementById('duplicateBlockBtn').addEventListener('click', duplicateBlock);
    document.getElementById('deleteBlockBtn').addEventListener('click', deleteBlock);
    document.getElementById('moveBlockUpBtn').addEventListener('click', function () { moveBlock('up'); });
    document.getElementById('moveBlockDownBtn').addEventListener('click', function () { moveBlock('down'); });

    var deleteSiteBtn = document.getElementById('deleteSiteBtn');
    if (deleteSiteBtn) {
        deleteSiteBtn.addEventListener('click', deleteCurrentSite);
    }

    var syncAccessBtn = document.getElementById('syncAccessBtn');
    if (syncAccessBtn) {
        syncAccessBtn.addEventListener('click', syncAccessFromBitrixGroup);
    }

    var ensureBitrixGroupBtn = document.getElementById('ensureBitrixGroupBtn');
    if (ensureBitrixGroupBtn) {
        ensureBitrixGroupBtn.addEventListener('click', ensureBitrixGroup);
    }

    var grantAccessBtn = document.getElementById('grantAccessBtn');
    if (grantAccessBtn) {
        grantAccessBtn.addEventListener('click', grantAccessRole);
    }

    var reloadAccessBtn = document.getElementById('reloadAccessBtn');
    if (reloadAccessBtn) {
        reloadAccessBtn.addEventListener('click', function () {
            hideAccessMessage();
            loadAccessList();
        });
    }

    var accessList = document.getElementById('accessList');
    if (accessList) {
        accessList.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-access-remove-user]');
            if (!btn) return;

            removeAccessRole(Number(btn.getAttribute('data-access-remove-user') || 0));
        });
    }

    var accessUserSearchInput = document.getElementById('accessUserSearchInput');
    if (accessUserSearchInput) {
        accessUserSearchInput.addEventListener('input', function () {
            clearTimeout(state.userSearchTimer);

            state.userSearchTimer = setTimeout(function () {
                searchAccessUsers();
            }, 300);
        });

        accessUserSearchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();

                if (state.userSearchResults.length) {
                    selectAccessUser(Number(state.userSearchResults[0].id || 0));
                }
            }
        });
    }

    var accessUserSearchResults = document.getElementById('accessUserSearchResults');
    if (accessUserSearchResults) {
        accessUserSearchResults.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-select-access-user]');
            if (!btn) return;

            selectAccessUser(Number(btn.getAttribute('data-select-access-user') || 0));
        });
    }

    var accessSelectedUser = document.getElementById('accessSelectedUser');
    if (accessSelectedUser) {
        accessSelectedUser.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-clear-access-user]');
            if (!btn) return;

            clearSelectedAccessUser();
        });
    }

    document.addEventListener('mousedown', function (e) {
        var wrap = e.target.closest('.sb-access-search-wrap');
        if (!wrap) {
            clearAccessUserSearchResults();
        }
    });

    window.onerror = function (message, source, lineno, colno, error) {
        print({
            jsError: true,
            message: message,
            source: source,
            line: lineno,
            column: colno,
            stack: error && error.stack ? error.stack : null
        });
    };

    (async function init() {
        try {
            await loadSite();
            await loadAccessList();
            await loadPages();
            await loadBlocks();
        } catch (e) {
            print(e);
            alert('Не удалось загрузить редактор');
        }
    })();

    function fillPageParentEditorOptions() {
        var select = document.getElementById('pageParentInput');
        if (!select) return;

        var currentPageId = Number(state.currentPageId || 0);
        var currentValue = String(select.value || '0');

        var html = '<option value="0">Без родителя</option>';

        state.pages.forEach(function (page) {
            var id = Number(page.id || 0);

            if (id === currentPageId) {
                return;
            }

            html += '<option value="' + id + '">' + escapeHtml(page.title || ('Страница #' + id)) + '</option>';
        });

        select.innerHTML = html;

        if (currentValue && select.querySelector('option[value="' + currentValue + '"]')) {
            select.value = currentValue;
        }
    }
})();
</script>
</body>
</html>