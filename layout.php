<?php

declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__ . '/lib/auth.php';

global $APPLICATION, $USER;

sitebuilder_require_auth();

header('Content-Type: text/html; charset=UTF-8');

$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$siteId = (int)($_GET['siteId'] ?? 0);

foreach ([
    __DIR__ . '/lib/db.php',
    __DIR__ . '/lib/json.php',
    __DIR__ . '/lib/response.php',
    __DIR__ . '/lib/helpers.php',
    __DIR__ . '/lib/access.php',
] as $libFile) {
    require_once $libFile;
}

if ($siteId <= 0) {
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Каркас сайта</title>
        <?php $APPLICATION->ShowHead(); ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    </head>
    <body class="sb-admin-body">
        <div class="sb-page">
            <h1 class="sb-title">Не передан siteId</h1>
            <p><a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/index.php">← К списку сайтов</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (!$USER->IsAdmin()) {
    sb_require_content_manager($siteId);
}

$site = sb_find_site($siteId);
$siteName = trim((string)($site['name'] ?? ''));
if ($siteName === '') {
    $siteName = 'Сайт #' . $siteId;
}

$h = static function ($value): string {
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $h($siteName) ?> · Каркас сайта</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= $h($basePath) ?>/assets/admin/admin.css">
    <link rel="stylesheet" href="<?= $h($basePath) ?>/assets/admin/layout2.css?v=3">
    <link rel="stylesheet" href="<?= $h($basePath) ?>/assets/admin/layout2-preview.css?v=1">
</head>
<body class="sb-admin-body">
<div class="sb-page sb-layout2-page">
    <header class="sb-layout2-topbar">
        <div class="sb-layout2-topbar__main">
            <a class="sb-back-link" href="<?= $h($basePath) ?>/index.php">← К списку сайтов</a>
            <div class="sb-layout2-title-row">
                <div>
                    <h1 class="sb-title">Каркас сайта</h1>
                    <p class="sb-subtitle"><?= $h($siteName) ?> · siteId <?= $siteId ?></p>
                </div>
                <span class="sb-layout2-version" id="layoutVersionBadge">версия —</span>
            </div>
        </div>

        <div class="sb-layout2-topbar__actions">
            <a class="sb-btn sb-btn-light" href="<?= $h($basePath) ?>/editor.php?siteId=<?= $siteId ?>">Редактор страниц</a>
            <a class="sb-btn sb-btn-light" href="<?= $h($basePath) ?>/public.php?siteId=<?= $siteId ?>" target="_blank" rel="noopener">Открыть сайт ↗</a>
            <a class="sb-btn sb-btn-light" href="<?= $h($basePath) ?>/versions.php?siteId=<?= $siteId ?>&entityType=layout&entityId=<?= $siteId ?>">История</a>
            <a class="sb-btn sb-btn-light" href="<?= $h($basePath) ?>/audit.php?siteId=<?= $siteId ?>">Журнал</a>
        </div>
    </header>

    <div class="sb-layout2-notice" id="layoutNotice" hidden role="status"></div>

    <section class="sb-panel sb-layout2-settings">
        <div class="sb-layout2-section-head">
            <div>
                <div class="sb-layout2-eyebrow">Общий каркас</div>
                <h2 class="sb-panel-title">Области сайта</h2>
                <p class="sb-layout2-help">
                    Все изменения layout сначала живут только в черновике и preview. Оригинальный сайт изменится только после нажатия «Сохранить каркас».
                </p>
            </div>

            <div class="sb-layout2-save-row">
                <span class="sb-layout2-save-state" id="layoutSaveState" data-state="ready">Загрузка…</span>
                <button type="button" class="sb-btn sb-btn-primary" id="saveSettingsBtn" disabled>Сохранить каркас</button>
                <button type="button" class="sb-btn sb-btn-light" id="reloadBtn">Обновить</button>
            </div>
        </div>

        <div class="sb-layout2-zone-switches">
            <label class="sb-layout2-switch-card">
                <input type="checkbox" id="showHeader">
                <span class="sb-layout2-switch-ui"></span>
                <span class="sb-layout2-switch-copy">
                    <strong>Шапка</strong>
                    <small>Логотип, меню и дополнительные блоки</small>
                </span>
            </label>

            <label class="sb-layout2-switch-card">
                <input type="checkbox" id="showLeft">
                <span class="sb-layout2-switch-ui"></span>
                <span class="sb-layout2-switch-copy">
                    <strong>Левая панель</strong>
                    <small>Меню страниц или собственные блоки</small>
                </span>
            </label>

            <label class="sb-layout2-switch-card">
                <input type="checkbox" id="showRight">
                <span class="sb-layout2-switch-ui"></span>
                <span class="sb-layout2-switch-copy">
                    <strong>Правая панель</strong>
                    <small>Дополнительные блоки рядом с контентом</small>
                </span>
            </label>

            <label class="sb-layout2-switch-card">
                <input type="checkbox" id="showFooter">
                <span class="sb-layout2-switch-ui"></span>
                <span class="sb-layout2-switch-copy">
                    <strong>Подвал</strong>
                    <small>Показывается, когда в нём есть блоки</small>
                </span>
            </label>
        </div>

        <div class="sb-layout2-settings-grid">
            <div class="sb-layout2-setting-card" id="leftModeCard">
                <div class="sb-layout2-setting-card__head">
                    <div>
                        <strong>Содержимое левой панели</strong>
                        <small>Что посетитель увидит слева от страницы</small>
                    </div>
                </div>
                <input type="hidden" id="leftMode" value="blocks">
                <div class="sb-layout2-segmented" role="group" aria-label="Содержимое левой панели">
                    <button type="button" data-left-mode="menu">☰ Меню страниц</button>
                    <button type="button" data-left-mode="blocks">▦ Блоки</button>
                </div>
                <p class="sb-layout2-setting-note" id="leftModeHint"></p>
            </div>

            <div class="sb-layout2-setting-card" id="leftWidthCard">
                <div class="sb-layout2-setting-card__head">
                    <div>
                        <strong>Ширина левой панели</strong>
                        <small>Сохраняемое значение Desktop</small>
                    </div>
                    <label class="sb-layout2-number-box">
                        <input type="number" id="leftWidth" min="120" max="800" step="10">
                        <span>px</span>
                    </label>
                </div>
                <input class="sb-layout2-range" type="range" id="leftWidthRange" min="120" max="800" step="10">
                <div class="sb-layout2-range-scale"><span>120</span><span>800</span></div>
            </div>

            <div class="sb-layout2-setting-card" id="rightWidthCard">
                <div class="sb-layout2-setting-card__head">
                    <div>
                        <strong>Ширина правой панели</strong>
                        <small>Сохраняемое значение Desktop</small>
                    </div>
                    <label class="sb-layout2-number-box">
                        <input type="number" id="rightWidth" min="120" max="800" step="10">
                        <span>px</span>
                    </label>
                </div>
                <input class="sb-layout2-range" type="range" id="rightWidthRange" min="120" max="800" step="10">
                <div class="sb-layout2-range-scale"><span>120</span><span>800</span></div>
            </div>
        </div>
    </section>


    <section
        class="sb-panel sb-layout2-real-preview-panel"
        id="layoutRealPreviewPanel"
    >
        <div class="sb-layout2-real-preview-head">
            <div>
                <div class="sb-layout2-eyebrow">
                    Реальный предпросмотр
                </div>

                <h2 class="sb-panel-title">
                    Как выглядит сайт
                </h2>

                <p>
                    Используется тот же публичный шаблон, компоненты,
                    логотип, меню, секции и адаптивные стили, что и в
                    <code>public.php</code>.
                </p>
            </div>
        </div>

        <div class="sb-layout2-real-preview-toolbar">
            <label class="sb-field">
                <span>Страница для предпросмотра</span>

                <div class="sb-layout2-real-preview-page-row">
                    <select
                        class="sb-select"
                        id="layoutPreviewPage"
                        disabled
                    >
                        <option value="0">
                            Загружаю страницы…
                        </option>
                    </select>

                    <span
                        class="sb-layout2-preview-status"
                        id="layoutPreviewPageStatus"
                        data-status="none"
                    >
                        —
                    </span>
                </div>
            </label>

            <div
                class="sb-layout2-preview-devices"
                role="group"
                aria-label="Размер предпросмотра"
            >
                <button
                    type="button"
                    class="is-active"
                    data-layout-preview-device="desktop"
                >
                    Desktop
                </button>

                <button
                    type="button"
                    data-layout-preview-device="tablet"
                >
                    Tablet
                </button>

                <button
                    type="button"
                    data-layout-preview-device="mobile"
                >
                    Mobile
                </button>
            </div>

            <div class="sb-layout2-preview-actions">
                <button
                    type="button"
                    class="sb-btn sb-btn-light"
                    id="layoutPreviewReload"
                >
                    Обновить preview
                </button>

                <a
                    class="sb-btn sb-btn-light"
                    id="layoutPreviewOpen"
                    href="#"
                    target="_blank"
                    rel="noopener"
                >
                    Открыть отдельно ↗
                </a>
            </div>
        </div>

        <div class="sb-layout2-real-preview-meta">
            <div class="sb-layout2-real-preview-meta__left">
                <strong>Preview</strong>
                <span id="layoutPreviewNote">
                    Загружаю…
                </span>
            </div>

            <div class="sb-layout2-real-preview-meta__right">
                <span
                    class="sb-layout2-preview-size"
                    id="layoutPreviewSize"
                >
                    1280 px
                </span>

                <span id="layoutPreviewLoaded">
                    —
                </span>
            </div>
        </div>

        <div
            class="sb-layout2-real-preview-viewport"
            id="layoutPreviewViewport"
            data-device="desktop"
        >
            <div
                class="sb-layout2-preview-empty"
                id="layoutPreviewEmpty"
                hidden
            >
                Нет страницы для предпросмотра.
            </div>

            <div class="sb-layout2-preview-frame-stage">
                <div
                    class="sb-layout2-preview-frame-shell"
                    id="layoutPreviewFrameShell"
                    style="width:1280px"
                >
                    <div class="sb-layout2-preview-device-cap"></div>

                    <div
                        class="sb-layout2-preview-loading"
                        id="layoutPreviewLoading"
                    >
                        Загружаю публичный вид…
                    </div>

                    <iframe
                        class="sb-layout2-preview-frame is-loading"
                        id="layoutPreviewFrame"
                        title="Предпросмотр сайта"
                        sandbox="allow-scripts"
                        referrerpolicy="same-origin"
                    ></iframe>
                </div>
            </div>
        </div>

        <div class="sb-layout2-preview-hint">
            Черновую страницу можно просматривать здесь до публикации.
            Ссылки, кнопки и отправка форм внутри iframe отключены.
        </div>
    </section>

    <section class="sb-layout2-workspace">
        <div class="sb-panel sb-layout2-canvas-panel">
            <div class="sb-layout2-canvas-head">
                <div>
                    <div class="sb-layout2-eyebrow">Визуальная схема</div>
                    <h2 class="sb-panel-title">Каркас страницы</h2>
                    <p>Центральный контент редактируется в редакторе страниц. Здесь настраиваются только общие области.</p>
                </div>
                <div class="sb-layout2-canvas-legend">
                    <span><i class="is-layout"></i>Layout</span>
                    <span><i class="is-page"></i>Контент страницы</span>
                </div>
            </div>

            <div class="sb-layout2-canvas" id="layoutCanvas">
                <div class="sb-layout2-loading">Загружаю каркас…</div>
            </div>
        </div>

        <aside class="sb-panel sb-layout2-inspector" id="layoutInspector">
            <div class="sb-layout2-inspector-empty" id="layoutInspectorEmpty">
                <div class="sb-layout2-inspector-empty__icon">◇</div>
                <strong>Выберите layout-блок</strong>
                <span>Нажмите на блок в шапке, боковой панели или подвале, чтобы изменить его содержимое.</span>
            </div>

            <div id="layoutInspectorForm" hidden>
                <div class="sb-layout2-inspector-head">
                    <div>
                        <div class="sb-layout2-eyebrow" id="inspectorZone">Layout</div>
                        <h2 class="sb-panel-title" id="inspectorTitle">Блок</h2>
                        <p id="inspectorMeta"></p>
                    </div>
                    <button type="button" class="sb-layout2-icon-button" id="closeInspectorBtn" title="Закрыть" aria-label="Закрыть редактор блока">×</button>
                </div>

                <div class="sb-layout2-inspector-fields" id="layoutBlockFields"></div>

                <details class="sb-layout2-advanced">
                    <summary>Расширенный режим · JSON</summary>

                    <div class="sb-field">
                        <label for="blockAdvancedContent">Content JSON</label>
                        <textarea class="sb-textarea sb-layout2-json" id="blockAdvancedContent" spellcheck="false"></textarea>
                    </div>

                    <div class="sb-field">
                        <label for="blockAdvancedProps">Props JSON</label>
                        <textarea class="sb-textarea sb-layout2-json" id="blockAdvancedProps" spellcheck="false"></textarea>
                    </div>
                </details>

                <div class="sb-layout2-inspector-actions">
                    <span class="sb-layout2-block-state" id="layoutBlockState">Без изменений</span>
                    <div>
                        <button type="button" class="sb-btn sb-btn-primary" id="saveLayoutBlockBtn" disabled>Применить блок</button>
                        <button type="button" class="sb-btn sb-btn-danger" id="deleteLayoutBlockBtn">Удалить</button>
                    </div>
                </div>
            </div>
        </aside>
    </section>

    <details class="sb-layout2-debug">
        <summary>Техническая информация</summary>
        <pre id="layoutDebug">Ожидание API…</pre>
    </details>
</div>

<script>
window.SB_LAYOUT_CONFIG = {
    basePath: '<?= CUtil::JSEscape($basePath) ?>',
    apiUrl: '<?= CUtil::JSEscape($basePath) ?>/api.php',
    siteId: <?= $siteId ?>,
    homePageId: <?= (int)($site['homePageId'] ?? 0) ?>,
    previewUrl: '<?= CUtil::JSEscape($basePath) ?>/layout_preview.php',
    sessid: '<?= CUtil::JSEscape(bitrix_sessid()) ?>'
};
</script>
<script src="<?= $h($basePath) ?>/assets/admin/layout2.js?v=4"></script>
<script src="<?= $h($basePath) ?>/assets/admin/layout2-preview.js?v=2"></script>
</body>
</html>
