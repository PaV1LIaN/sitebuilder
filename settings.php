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
    __DIR__ . '/lib/public_routes.php',
    __DIR__ . '/lib/access.php',
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
        <title>SiteBuilder / Settings</title>
        <?php $APPLICATION->ShowHead(); ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/settings.css?v=1">
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

if (!$USER->IsAdmin()) {
    sb_require_content_manager($siteId);
}
$publicSiteUrl = sb_public_site_url($basePath, $siteId);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>SiteBuilder / Settings</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/settings.css?v=1">
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-topbar">
        <div class="sb-topbar-left">
            <a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/editor.php?siteId=<?= (int)$siteId ?>">
                ← В редактор
            </a>
            <h1 class="sb-title">Настройки сайта</h1>
            <p class="sb-subtitle">siteId = <?= (int)$siteId ?> · версия <span id="siteVersionBadge">—</span></p>
        </div>

        <div class="sb-settings-top-actions">
            <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/versions.php?siteId=<?= (int)$siteId ?>&entityType=site&entityId=<?= (int)$siteId ?>">История настроек</a>
            <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/trash.php?siteId=<?= (int)$siteId ?>">Корзина</a>
            <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/audit.php?siteId=<?= (int)$siteId ?>">Журнал</a>
            <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/jobs.php?siteId=<?= (int)$siteId ?>">Задания</a>
            <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/queue_health.php?siteId=<?= (int)$siteId ?>">Состояние очереди</a>
            <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/alerts.php?siteId=<?= (int)$siteId ?>">Оповещения</a>
            <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/external_resources.php?siteId=<?= (int)$siteId ?>">Внешние ресурсы</a>
            <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/backups.php?siteId=<?= (int)$siteId ?>">Резервные копии</a>
            <a class="sb-btn sb-btn-light" id="openPublicSiteLink" href="<?= htmlspecialchars($publicSiteUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank">
                Открыть публичную
            </a>
            <button class="sb-btn sb-btn-light" type="button" id="reloadBtn">Обновить</button>
        </div>
    </div>

    <div class="sb-settings-layout">
        <div class="sb-settings-main">
            <section class="sb-panel">
                <div class="sb-settings-panel-head">
                    <div>
                        <h2 class="sb-panel-title">Основные настройки</h2>
                        <p class="sb-settings-note">
                            Название, адрес сайта, ширина контейнера и основной цвет.
                        </p>
                    </div>
                </div>

                <div class="sb-settings-grid">
                    <div class="sb-field">
                        <label for="siteNameInput">Название сайта</label>
                        <input class="sb-input" type="text" id="siteNameInput" placeholder="Название">
                    </div>

                    <div class="sb-field">
                        <label for="siteSlugInput">Slug</label>
                        <input class="sb-input" type="text" id="siteSlugInput" placeholder="site-slug">
                    </div>

                    <div class="sb-field">
                        <label for="containerWidthInput">Ширина контейнера</label>
                        <input class="sb-input" type="number" id="containerWidthInput" min="320" max="1920" step="10">
                    </div>

                    <div class="sb-field">
                        <label for="accentInput">Акцентный цвет</label>
                        <input class="sb-input sb-color-input" type="color" id="accentInput" value="#2563eb">
                    </div>
                </div>

                <div class="sb-settings-actions">
                    <button class="sb-btn sb-btn-primary" type="button" id="saveBasicBtn">Сохранить основные настройки</button>
                </div>
            </section>

            <section class="sb-panel">
                <div class="sb-settings-panel-head">
                    <div>
                        <h2 class="sb-panel-title">Дизайн-система</h2>
                        <p class="sb-settings-note">Общие цвета, типографика, скругления и тени для всех страниц сайта.</p>
                    </div>
                </div>
                <div class="sb-design-token-grid">
                    <div class="sb-field"><label for="secondaryColorInput">Тёмный акцент</label><input class="sb-input sb-color-input" type="color" id="secondaryColorInput" value="#0f172a"></div>
                    <div class="sb-field"><label for="textColorInput">Основной текст</label><input class="sb-input sb-color-input" type="color" id="textColorInput" value="#0f172a"></div>
                    <div class="sb-field"><label for="mutedColorInput">Вторичный текст</label><input class="sb-input sb-color-input" type="color" id="mutedColorInput" value="#64748b"></div>
                    <div class="sb-field"><label for="surfaceColorInput">Цвет карточек</label><input class="sb-input sb-color-input" type="color" id="surfaceColorInput" value="#ffffff"></div>
                    <div class="sb-field"><label for="borderColorInput">Цвет границ</label><input class="sb-input sb-color-input" type="color" id="borderColorInput" value="#e2e8f0"></div>
                </div>
                <div class="sb-settings-grid" style="margin-top:16px;">
                    <div class="sb-field"><label for="headingFontInput">Шрифт заголовков</label><select class="sb-select" id="headingFontInput"><option value="system">Системный</option><option value="arial">Arial</option><option value="georgia">Georgia</option><option value="times">Times New Roman</option><option value="mono">Моноширинный</option></select></div>
                    <div class="sb-field"><label for="bodyFontInput">Шрифт текста</label><select class="sb-select" id="bodyFontInput"><option value="system">Системный</option><option value="arial">Arial</option><option value="georgia">Georgia</option><option value="times">Times New Roman</option><option value="mono">Моноширинный</option></select></div>
                    <div class="sb-field"><label for="baseFontSizeInput">Базовый размер, px</label><input class="sb-input" type="number" id="baseFontSizeInput" min="14" max="22" value="16"></div>
                    <div class="sb-field"><label for="bodyLineHeightInput">Межстрочный интервал</label><input class="sb-input" type="number" id="bodyLineHeightInput" min="1.2" max="2.2" step="0.05" value="1.6"></div>
                    <div class="sb-field"><label for="headingWeightInput">Насыщенность заголовков</label><select class="sb-select" id="headingWeightInput"><option value="500">500</option><option value="600">600</option><option value="700">700</option><option value="800" selected>800</option><option value="900">900</option></select></div>
                    <div class="sb-field"><label for="radiusScaleInput">Скругление карточек, px</label><input class="sb-input" type="number" id="radiusScaleInput" min="0" max="32" value="16"></div>
                    <div class="sb-field"><label for="buttonRadiusInput">Скругление кнопок, px</label><input class="sb-input" type="number" id="buttonRadiusInput" min="0" max="40" value="12"></div>
                    <div class="sb-field"><label for="sectionGapInput">Расстояние между секциями, px</label><input class="sb-input" type="number" id="sectionGapInput" min="0" max="96" value="24"></div>
                    <div class="sb-field"><label for="shadowPresetInput">Глобальная тень</label><select class="sb-select" id="shadowPresetInput"><option value="none">Без тени</option><option value="soft" selected>Мягкая</option><option value="medium">Средняя</option><option value="strong">Выразительная</option></select></div>
                </div>
                <div class="sb-settings-actions"><button class="sb-btn sb-btn-primary" type="button" id="saveDesignSystemBtn">Сохранить дизайн-систему</button></div>
            </section>

            <section class="sb-panel">
                <div class="sb-settings-panel-head">
                    <div>
                        <h2 class="sb-panel-title">Логотип</h2>
                        <p class="sb-settings-note">
                            Логотип будет отображаться в шапке публичной части сайта.
                        </p>
                    </div>
                </div>

                <div class="sb-asset-row">
                    <div class="sb-asset-preview sb-asset-preview--logo" id="logoPreview">
                        <span>Нет логотипа</span>
                    </div>

                    <div class="sb-asset-controls">
                        <div class="sb-field">
                            <label for="logoFileInput">Файл логотипа</label>
                            <input class="sb-input" type="file" id="logoFileInput" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
                        </div>

                        <div class="sb-field">
                            <label for="headerLogoModeInput">Отображение в шапке</label>
                            <select class="sb-select" id="headerLogoModeInput">
                                <option value="image">Только логотип</option>
                                <option value="text">Только название сайта</option>
                                <option value="both">Логотип и название</option>
                            </select>
                        </div>

                        <div class="sb-field" style="margin-top:12px;">
                            <label for="logoSizeInput">Размер логотипа, px</label>
                            <input class="sb-input" type="number" id="logoSizeInput" min="24" max="160" step="2" value="42">
                        </div>

                        <div class="sb-settings-actions">
                            <button class="sb-btn sb-btn-primary" type="button" id="uploadLogoBtn">Загрузить логотип</button>
                            <button class="sb-btn sb-btn-light" type="button" id="removeLogoBtn">Удалить логотип</button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="sb-panel">
                <div class="sb-settings-panel-head">
                    <div>
                        <h2 class="sb-panel-title">Фон сайта</h2>
                        <p class="sb-settings-note">
                            Фон будет применяться к публичной части сайта.
                        </p>
                    </div>
                </div>

                <div class="sb-asset-row">
                    <div class="sb-asset-preview sb-asset-preview--background" id="backgroundPreview">
                        <span>Нет фона</span>
                    </div>

                    <div class="sb-asset-controls">
                        <div class="sb-field">
                            <label for="backgroundFileInput">Изображение фона</label>
                            <input class="sb-input" type="file" id="backgroundFileInput" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
                        </div>

                        <div class="sb-settings-grid">
                            <div class="sb-field">
                                <label for="backgroundColorInput">Цвет фона</label>
                                <input class="sb-input sb-color-input" type="color" id="backgroundColorInput" value="#f8fafc">
                            </div>

                            <div class="sb-field">
                                <label for="backgroundModeInput">Размер</label>
                                <select class="sb-select" id="backgroundModeInput">
                                    <option value="cover">Заполнить экран</option>
                                    <option value="contain">Уместить целиком</option>
                                    <option value="auto">Оригинальный размер</option>
                                    <option value="stretch">Растянуть</option>
                                </select>
                            </div>

                            <div class="sb-field">
                                <label for="backgroundPositionInput">Позиция</label>
                                <select class="sb-select" id="backgroundPositionInput">
                                    <option value="center center">По центру</option>
                                    <option value="top center">Сверху</option>
                                    <option value="bottom center">Снизу</option>
                                    <option value="left center">Слева</option>
                                    <option value="right center">Справа</option>
                                </select>
                            </div>

                            <div class="sb-field">
                                <label for="backgroundRepeatInput">Повтор</label>
                                <select class="sb-select" id="backgroundRepeatInput">
                                    <option value="no-repeat">Не повторять</option>
                                    <option value="repeat">Повторять</option>
                                    <option value="repeat-x">Повторять по X</option>
                                    <option value="repeat-y">Повторять по Y</option>
                                </select>
                            </div>
                        </div>

                        <div class="sb-settings-actions">
                            <button class="sb-btn sb-btn-primary" type="button" id="uploadBackgroundBtn">Загрузить фон</button>
                            <button class="sb-btn sb-btn-light" type="button" id="saveAppearanceBtn">Сохранить настройки фона</button>
                            <button class="sb-btn sb-btn-light" type="button" id="removeBackgroundBtn">Удалить фон</button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <aside class="sb-settings-side">
            <section class="sb-panel">
                <h2 class="sb-panel-title">Предпросмотр</h2>

                <div class="sb-appearance-preview" id="appearancePreview">
                    <div class="sb-appearance-preview__header">
                        <div class="sb-appearance-preview__logo" id="previewLogo">S</div>
                        <div class="sb-appearance-preview__title" id="previewTitle">Сайт</div>
                    </div>

                    <div class="sb-appearance-preview__content">
                        <div class="sb-appearance-preview__line"></div>
                        <div class="sb-appearance-preview__line is-short"></div>
                        <button class="sb-appearance-preview__button" type="button">Кнопка</button>
                    </div>
                </div>
            </section>

            <section class="sb-panel">
                <h2 class="sb-panel-title">Статус</h2>
                <div id="settingsMessage" class="sb-empty">Настройки загружаются...</div>
            </section>

            <section class="sb-panel">
                <h2 class="sb-panel-title">Ответ API</h2>
                <div id="output" class="sb-output">Здесь будут ответы API...</div>
            </section>
        </aside>
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
        appearance: null
    };

    var output = document.getElementById('output');
    var message = document.getElementById('settingsMessage');

    function print(data) {
        try {
            output.textContent = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
        } catch (e) {
            output.textContent = String(data);
        }
    }

    function setMessage(text, type) {
        message.classList.remove('is-success', 'is-error');

        if (type === 'success') {
            message.classList.add('is-success');
        }

        if (type === 'error') {
            message.classList.add('is-error');
        }

        message.textContent = text || '';
    }

    function getSessid() {
        if (window.BX && typeof BX.bitrix_sessid === 'function') {
            return BX.bitrix_sessid();
        }

        return '<?= CUtil::JSEscape(bitrix_sessid()) ?>';
    }

    function api(action, data) {
        return fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams(Object.assign({
                action: action,
                sessid: getSessid()
            }, data || {})),
            credentials: 'same-origin'
        }).then(async function (res) {
            var text = await res.text();
            var json = null;

            try {
                json = JSON.parse(text);
            } catch (e) {
                throw {
                    ok: false,
                    error: 'BAD_JSON_RESPONSE',
                    status: res.status,
                    text: text
                };
            }

            print(json);

            if (!json || json.ok !== true) {
                throw json || {ok: false, error: 'UNKNOWN_ERROR'};
            }

            return json;
        });
    }

    function apiUpload(type, file) {
        var fd = new FormData();

        fd.append('action', 'site.appearanceUpload');
        fd.append('sessid', getSessid());
        fd.append('siteId', String(siteId));
        fd.append('expectedVersion', String(Number((state.site && state.site.version) || (state.appearance && state.appearance.siteVersion) || 1)));
        fd.append('type', type);
        fd.append('file', file);

        return fetch(API_URL, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(async function (res) {
            var text = await res.text();
            var json = null;

            try {
                json = JSON.parse(text);
            } catch (e) {
                throw {
                    ok: false,
                    error: 'BAD_JSON_RESPONSE',
                    status: res.status,
                    text: text
                };
            }

            print(json);

            if (!json || json.ok !== true) {
                throw json || {ok: false, error: 'UNKNOWN_ERROR'};
            }

            return json;
        });
    }

    function getValue(id) {
        var el = document.getElementById(id);
        return el ? String(el.value || '') : '';
    }

    function setValue(id, value) {
        var el = document.getElementById(id);
        if (el) {
            el.value = value == null ? '' : String(value);
        }
    }

    function cssBackgroundSize(mode) {
        if (mode === 'stretch') {
            return '100% 100%';
        }

        if (mode === 'contain') {
            return 'contain';
        }

        if (mode === 'auto') {
            return 'auto';
        }

        return 'cover';
    }

    function renderBasic() {
        var site = state.site || {};
        var versionNode = document.getElementById('siteVersionBadge');
        var publicLink = document.getElementById('openPublicSiteLink');
        if (versionNode) {
            versionNode.textContent = String(Number(site.version || 1));
        }
        if (publicLink && site.slug) {
            publicLink.href = BASE_PATH + '/s/' + encodeURIComponent(String(site.slug)) + '/';
        }
        var settings = site.settings || {};

        setValue('siteNameInput', site.name || '');
        setValue('siteSlugInput', site.slug || '');
        setValue('containerWidthInput', settings.containerWidth || 1100);
        setValue('accentInput', settings.accent || '#2563eb');
    }

    function renderAppearance() {
        var appearance = state.appearance || {};

        setValue('headerLogoModeInput', appearance.headerLogoMode || 'image');
        setValue('logoSizeInput', appearance.logoSize || 42);
        setValue('backgroundColorInput', appearance.backgroundColor || '#f8fafc');
        setValue('backgroundModeInput', appearance.backgroundMode || 'cover');
        setValue('backgroundPositionInput', appearance.backgroundPosition || 'center center');
        setValue('backgroundRepeatInput', appearance.backgroundRepeat || 'no-repeat');
        setValue('secondaryColorInput', appearance.secondaryColor || '#0f172a');
        setValue('textColorInput', appearance.textColor || '#0f172a');
        setValue('mutedColorInput', appearance.mutedColor || '#64748b');
        setValue('surfaceColorInput', appearance.surfaceColor || '#ffffff');
        setValue('borderColorInput', appearance.borderColor || '#e2e8f0');
        setValue('headingFontInput', appearance.headingFont || 'system');
        setValue('bodyFontInput', appearance.bodyFont || 'system');
        setValue('baseFontSizeInput', appearance.baseFontSize || 16);
        setValue('bodyLineHeightInput', appearance.bodyLineHeight || 1.6);
        setValue('headingWeightInput', appearance.headingWeight || 800);
        setValue('radiusScaleInput', appearance.radiusScale == null ? 16 : appearance.radiusScale);
        setValue('buttonRadiusInput', appearance.buttonRadius == null ? 12 : appearance.buttonRadius);
        setValue('sectionGapInput', appearance.sectionGap == null ? 24 : appearance.sectionGap);
        setValue('shadowPresetInput', appearance.shadowPreset || 'soft');

        renderLogoPreview();
        renderBackgroundPreview();
        renderMainPreview();
    }

    function renderLogoPreview() {
        var appearance = state.appearance || {};
        var node = document.getElementById('logoPreview');

        if (!node) return;

        if (appearance.logoUrl) {
            node.innerHTML = '<img src="' + appearance.logoUrl + '" alt="">';
        } else {
            node.innerHTML = '<span>Нет логотипа</span>';
        }
    }

    function renderBackgroundPreview() {
        var appearance = state.appearance || {};
        var node = document.getElementById('backgroundPreview');

        if (!node) return;

        node.innerHTML = appearance.backgroundUrl ? '' : '<span>Нет фона</span>';
        node.style.backgroundColor = appearance.backgroundColor || '#f8fafc';
        node.style.backgroundImage = appearance.backgroundUrl ? 'url("' + appearance.backgroundUrl + '")' : '';
        node.style.backgroundSize = cssBackgroundSize(appearance.backgroundMode || 'cover');
        node.style.backgroundPosition = appearance.backgroundPosition || 'center center';
        node.style.backgroundRepeat = appearance.backgroundRepeat || 'no-repeat';
    }

    function previewFontStack(value) {
        var map = {
            system: 'Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
            arial: 'Arial,Helvetica,sans-serif',
            georgia: 'Georgia,"Times New Roman",serif',
            times: '"Times New Roman",Times,serif',
            mono: 'ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace'
        };
        return map[String(value || '')] || map.system;
    }

    function previewShadow(value) {
        var map = {
            none: 'none',
            soft: '0 12px 32px rgba(15,23,42,.08)',
            medium: '0 18px 48px rgba(15,23,42,.14)',
            strong: '0 24px 70px rgba(15,23,42,.22)'
        };
        return map[String(value || '')] || map.soft;
    }

    function renderMainPreview() {
        var site = state.site || {};
        var appearance = state.appearance || {};
        var settings = site.settings || {};

        var preview = document.getElementById('appearancePreview');
        var previewLogo = document.getElementById('previewLogo');
        var previewTitle = document.getElementById('previewTitle');

        if (!preview) return;

        var accent = getValue('accentInput') || settings.accent || '#2563eb';

        preview.style.backgroundColor = getValue('backgroundColorInput') || appearance.backgroundColor || '#f8fafc';
        preview.style.backgroundImage = appearance.backgroundUrl ? 'url("' + appearance.backgroundUrl + '")' : '';
        preview.style.backgroundSize = cssBackgroundSize(getValue('backgroundModeInput') || appearance.backgroundMode || 'cover');
        preview.style.backgroundPosition = getValue('backgroundPositionInput') || appearance.backgroundPosition || 'center center';
        preview.style.backgroundRepeat = getValue('backgroundRepeatInput') || appearance.backgroundRepeat || 'no-repeat';
        preview.style.setProperty('--preview-accent', accent);
        preview.style.setProperty('--preview-secondary', getValue('secondaryColorInput') || appearance.secondaryColor || '#0f172a');
        preview.style.setProperty('--preview-text', getValue('textColorInput') || appearance.textColor || '#0f172a');
        preview.style.setProperty('--preview-muted', getValue('mutedColorInput') || appearance.mutedColor || '#64748b');
        preview.style.setProperty('--preview-surface', getValue('surfaceColorInput') || appearance.surfaceColor || '#ffffff');
        preview.style.setProperty('--preview-border', getValue('borderColorInput') || appearance.borderColor || '#e2e8f0');
        preview.style.setProperty('--preview-radius', (getValue('radiusScaleInput') || appearance.radiusScale || 16) + 'px');
        preview.style.setProperty('--preview-button-radius', (getValue('buttonRadiusInput') || appearance.buttonRadius || 12) + 'px');
        preview.style.setProperty('--preview-logo-size', (getValue('logoSizeInput') || appearance.logoSize || 42) + 'px');
        preview.style.setProperty('--preview-heading-font', previewFontStack(getValue('headingFontInput') || appearance.headingFont || 'system'));
        preview.style.setProperty('--preview-body-font', previewFontStack(getValue('bodyFontInput') || appearance.bodyFont || 'system'));
        preview.style.setProperty('--preview-base-size', (getValue('baseFontSizeInput') || appearance.baseFontSize || 16) + 'px');
        preview.style.setProperty('--preview-line-height', getValue('bodyLineHeightInput') || appearance.bodyLineHeight || 1.6);
        preview.style.setProperty('--preview-heading-weight', getValue('headingWeightInput') || appearance.headingWeight || 800);
        preview.style.setProperty('--preview-shadow', previewShadow(getValue('shadowPresetInput') || appearance.shadowPreset || 'soft'));

        previewTitle.textContent = getValue('siteNameInput') || site.name || 'Сайт';

        if (appearance.logoUrl) {
            previewLogo.innerHTML = '<img src="' + appearance.logoUrl + '" alt="">';
        } else {
            var title = getValue('siteNameInput') || site.name || 'S';
            previewLogo.textContent = title.substring(0, 1).toUpperCase();
        }
    }

    async function loadAll() {
        setMessage('Загружаю настройки...', '');

        var siteRes = await api('site.get', {
            siteId: siteId
        });

        state.site = siteRes.site || null;

        var appearanceRes = await api('site.appearanceGet', {
            siteId: siteId
        });

        state.appearance = appearanceRes.appearance || {};

        renderBasic();
        renderAppearance();

        setMessage('Настройки загружены', 'success');
    }

    async function saveBasic() {
        var appearance = state.appearance || {};

        setMessage('Сохраняю основные настройки...', '');

        var res = await api('site.update', {
            siteId: siteId,
            expectedVersion: Number((state.site && state.site.version) || 1),
            name: getValue('siteNameInput').trim(),
            slug: getValue('siteSlugInput').trim(),
            containerWidth: getValue('containerWidthInput') || '1100',
            accent: getValue('accentInput') || '#2563eb',

            /*
             * Важно: site.update в старом обработчике принимает logoFileId.
             * Если его не передать, можно случайно сбросить логотип.
             */
            logoFileId: appearance.logoFileId || 0
        });

        state.site = res.site || state.site;

        renderBasic();
        renderMainPreview();

        setMessage('Основные настройки сохранены', 'success');
    }

    async function saveAppearance() {
        setMessage('Сохраняю оформление...', '');

        var res = await api('site.appearanceUpdate', {
            siteId: siteId,
            expectedVersion: Number((state.site && state.site.version) || 1),
            backgroundColor: getValue('backgroundColorInput') || '#f8fafc',
            backgroundMode: getValue('backgroundModeInput') || 'cover',
            backgroundPosition: getValue('backgroundPositionInput') || 'center center',
            backgroundRepeat: getValue('backgroundRepeatInput') || 'no-repeat',
            headerLogoMode: getValue('headerLogoModeInput') || 'image',
            logoSize: getValue('logoSizeInput') || '42'
        });

        state.appearance = res.appearance || state.appearance;
        if (state.site && state.appearance && state.appearance.siteVersion) {
            state.site.version = Number(state.appearance.siteVersion);
        }

        renderBasic();
        renderAppearance();

        setMessage('Оформление сохранено', 'success');
    }

    async function saveDesignSystem() {
        setMessage('Сохраняю дизайн-систему...', '');
        var res = await api('site.appearanceUpdate', {
            siteId: siteId,
            expectedVersion: Number((state.site && state.site.version) || 1),
            secondaryColor: getValue('secondaryColorInput') || '#0f172a',
            textColor: getValue('textColorInput') || '#0f172a',
            mutedColor: getValue('mutedColorInput') || '#64748b',
            surfaceColor: getValue('surfaceColorInput') || '#ffffff',
            borderColor: getValue('borderColorInput') || '#e2e8f0',
            headingFont: getValue('headingFontInput') || 'system',
            bodyFont: getValue('bodyFontInput') || 'system',
            baseFontSize: getValue('baseFontSizeInput') || '16',
            bodyLineHeight: getValue('bodyLineHeightInput') || '1.6',
            headingWeight: getValue('headingWeightInput') || '800',
            radiusScale: getValue('radiusScaleInput') || '16',
            buttonRadius: getValue('buttonRadiusInput') || '12',
            sectionGap: getValue('sectionGapInput') || '24',
            shadowPreset: getValue('shadowPresetInput') || 'soft'
        });
        state.appearance = res.appearance || state.appearance;
        if (state.site && state.appearance && state.appearance.siteVersion) state.site.version = Number(state.appearance.siteVersion);
        renderBasic();
        renderAppearance();
        setMessage('Дизайн-система сохранена', 'success');
    }

    async function uploadLogo() {
        var input = document.getElementById('logoFileInput');

        if (!input || !input.files || !input.files[0]) {
            alert('Выбери файл логотипа');
            return;
        }

        setMessage('Загружаю логотип...', '');

        var res = await apiUpload('logo', input.files[0]);

        state.appearance = res.appearance || state.appearance;
        input.value = '';
        if (state.site && state.appearance && state.appearance.siteVersion) {
            state.site.version = Number(state.appearance.siteVersion);
        }

        renderBasic();
        renderAppearance();

        setMessage('Логотип загружен', 'success');
    }

    async function uploadBackground() {
        var input = document.getElementById('backgroundFileInput');

        if (!input || !input.files || !input.files[0]) {
            alert('Выбери изображение фона');
            return;
        }

        setMessage('Загружаю фон...', '');

        var res = await apiUpload('background', input.files[0]);

        state.appearance = res.appearance || state.appearance;
        input.value = '';
        if (state.site && state.appearance && state.appearance.siteVersion) {
            state.site.version = Number(state.appearance.siteVersion);
        }

        renderBasic();
        renderAppearance();

        setMessage('Фон загружен', 'success');
    }

    async function removeLogo() {
        if (!confirm('Удалить логотип?')) {
            return;
        }

        setMessage('Удаляю логотип...', '');

        var res = await api('site.appearanceRemove', {
            siteId: siteId,
            expectedVersion: Number((state.site && state.site.version) || 1),
            type: 'logo'
        });

        state.appearance = res.appearance || state.appearance;
        if (state.site && state.appearance && state.appearance.siteVersion) {
            state.site.version = Number(state.appearance.siteVersion);
        }

        renderBasic();
        renderAppearance();

        setMessage('Логотип удалён', 'success');
    }

    async function removeBackground() {
        if (!confirm('Удалить фон?')) {
            return;
        }

        setMessage('Удаляю фон...', '');

        var res = await api('site.appearanceRemove', {
            siteId: siteId,
            expectedVersion: Number((state.site && state.site.version) || 1),
            type: 'background'
        });

        state.appearance = res.appearance || state.appearance;
        if (state.site && state.appearance && state.appearance.siteVersion) {
            state.site.version = Number(state.appearance.siteVersion);
        }

        renderBasic();
        renderAppearance();

        setMessage('Фон удалён', 'success');
    }

    document.getElementById('reloadBtn').addEventListener('click', function () {
        loadAll().catch(function (e) {
            print(e);
            setMessage('Ошибка загрузки настроек: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        });
    });

    document.getElementById('saveBasicBtn').addEventListener('click', function () {
        saveBasic().catch(function (e) {
            print(e);
            setMessage('Ошибка сохранения основных настроек: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        });
    });

    document.getElementById('saveAppearanceBtn').addEventListener('click', function () {
        saveAppearance().catch(function (e) {
            print(e);
            setMessage('Ошибка сохранения оформления: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        });
    });

    document.getElementById('saveDesignSystemBtn').addEventListener('click', function () {
        saveDesignSystem().catch(function (e) {
            print(e);
            setMessage('Ошибка сохранения дизайн-системы: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        });
    });

    document.getElementById('uploadLogoBtn').addEventListener('click', function () {
        uploadLogo().catch(function (e) {
            print(e);
            setMessage('Ошибка загрузки логотипа: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        });
    });

    document.getElementById('uploadBackgroundBtn').addEventListener('click', function () {
        uploadBackground().catch(function (e) {
            print(e);
            setMessage('Ошибка загрузки фона: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        });
    });

    document.getElementById('removeLogoBtn').addEventListener('click', function () {
        removeLogo().catch(function (e) {
            print(e);
            setMessage('Ошибка удаления логотипа: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        });
    });

    document.getElementById('removeBackgroundBtn').addEventListener('click', function () {
        removeBackground().catch(function (e) {
            print(e);
            setMessage('Ошибка удаления фона: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        });
    });

    [
        'siteNameInput',
        'accentInput',
        'backgroundColorInput',
        'backgroundModeInput',
        'backgroundPositionInput',
        'backgroundRepeatInput',
        'logoSizeInput',
        'headerLogoModeInput',
        'secondaryColorInput',
        'textColorInput',
        'mutedColorInput',
        'surfaceColorInput',
        'borderColorInput',
        'headingFontInput',
        'bodyFontInput',
        'baseFontSizeInput',
        'bodyLineHeightInput',
        'headingWeightInput',
        'radiusScaleInput',
        'buttonRadiusInput',
        'sectionGapInput',
        'shadowPresetInput'
    ].forEach(function (id) {
        var node = document.getElementById(id);
        if (node) {
            node.addEventListener('input', renderMainPreview);
            node.addEventListener('change', renderMainPreview);
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

    loadAll().catch(function (e) {
        print(e);
        setMessage('Ошибка загрузки настроек: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
    });
})();
</script>
</body>
</html>
