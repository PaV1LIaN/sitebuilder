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
        <title>SiteBuilder / Settings</title>
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
    <title>SiteBuilder / Settings</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-topbar">
        <div>
            <a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/index.php">← К списку сайтов</a>
            <h1 class="sb-title">Настройки сайта</h1>
            <p class="sb-subtitle">siteId = <?= (int)$siteId ?></p>
        </div>
    </div>

    <div class="sb-panel">
        <h2 class="sb-panel-title">Основные настройки</h2>

        <div id="settingsEmpty" class="sb-empty">Загрузка...</div>

        <div id="settingsForm" class="sb-hidden">
            <div class="sb-grid-2">
                <div class="sb-field">
                    <label for="siteName">Название сайта</label>
                    <input class="sb-input" type="text" id="siteName">
                </div>

                <div class="sb-field">
                    <label for="siteSlug">Slug</label>
                    <input class="sb-input" type="text" id="siteSlug">
                </div>

                <div class="sb-field">
                    <label for="containerWidth">Ширина контейнера</label>
                    <input class="sb-input" type="number" id="containerWidth" min="320" max="1920">
                </div>

                <div class="sb-field">
                    <label for="accent">Accent color</label>
                    <input class="sb-input" type="text" id="accent" placeholder="#2563eb">
                </div>

                <div class="sb-field">
                    <label for="logoFileId">Logo file ID</label>
                    <input class="sb-input" type="number" id="logoFileId" min="0">
                </div>

                <div class="sb-field">
                    <label for="homePageId">Домашняя страница</label>
                    <select class="sb-select" id="homePageId"></select>
                </div>

                <div class="sb-field full">
                    <label>Служебная информация</label>
                    <div class="sb-meta" id="siteMeta"></div>
                </div>
            </div>

            <div class="sb-toolbar" style="margin-top:16px;">
                <button type="button" class="sb-btn sb-btn-primary" id="saveSettingsBtn">Сохранить</button>
                <button type="button" class="sb-btn sb-btn-light" id="reloadBtn">Обновить</button>
            </div>
        </div>
    </div>

    <div class="sb-panel">
        <h2 class="sb-panel-title">Отладка</h2>
        <div id="output" class="sb-output">Здесь будут ответы API...</div>
    </div>
</div>

<script>
(function () {
    var BASE_PATH = '<?= CUtil::JSEscape($basePath) ?>';
    var API_URL = BASE_PATH + '/api.php';
    var SITE_ID = <?= (int)$siteId ?>;

    var output = document.getElementById('output');
    var settingsEmpty = document.getElementById('settingsEmpty');
    var settingsForm = document.getElementById('settingsForm');

    var state = {
        site: null,
        pages: []
    };

    function print(data) {
        if (typeof data === 'string') {
            output.textContent = data;
            return;
        }
        try {
            output.textContent = JSON.stringify(data, null, 2);
        } catch (e) {
            output.textContent = String(data);
        }
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getSessid() {
        if (typeof window.BX !== 'undefined' && typeof BX.bitrix_sessid === 'function') {
            return BX.bitrix_sessid();
        }
        return '<?= CUtil::JSEscape(bitrix_sessid()) ?>';
    }

    function api(action, data, onSuccess, onFailure) {
        if (typeof window.BX === 'undefined' || typeof BX.ajax !== 'function') {
            print('BX.ajax не загружен');
            return;
        }

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
                if (typeof onSuccess === 'function') {
                    onSuccess(res);
                }
            },
            onfailure: function (err) {
                print({
                    ok: false,
                    error: 'AJAX_ERROR',
                    detail: err
                });
                if (typeof onFailure === 'function') {
                    onFailure(err);
                }
            }
        });
    }

    function loadSite(next) {
        api('site.get', { siteId: SITE_ID }, function (res) {
            if (!res || res.ok !== true) {
                settingsEmpty.textContent = 'Не удалось загрузить сайт';
                return;
            }

            state.site = res.site || null;

            if (typeof next === 'function') {
                next();
            }
        });
    }

    function loadPages(next) {
        api('page.list', { siteId: SITE_ID }, function (res) {
            if (res && res.ok === true) {
                state.pages = Array.isArray(res.pages) ? res.pages : [];
            } else {
                state.pages = [];
            }

            if (typeof next === 'function') {
                next();
            }
        });
    }

    function renderHomePageOptions() {
        var select = document.getElementById('homePageId');
        var html = '<option value="0">Не выбрана</option>';

        for (var i = 0; i < state.pages.length; i++) {
            var page = state.pages[i];
            var id = Number(page.id || 0);
            var selected = id === Number((state.site && state.site.homePageId) || 0) ? ' selected' : '';
            html += '<option value="' + id + '"' + selected + '>'
                + escapeHtml(page.title || ('Страница #' + id))
                + ' (#' + id + ')</option>';
        }

        select.innerHTML = html;
    }

    function renderSite() {
        if (!state.site) {
            settingsEmpty.textContent = 'Сайт не найден';
            return;
        }

        document.getElementById('siteName').value = state.site.name || '';
        document.getElementById('siteSlug').value = state.site.slug || '';
        document.getElementById('containerWidth').value = Number((state.site.settings && state.site.settings.containerWidth) || 1100);
        document.getElementById('accent').value = (state.site.settings && state.site.settings.accent) || '#2563eb';
        document.getElementById('logoFileId').value = Number((state.site.settings && state.site.settings.logoFileId) || 0);

        renderHomePageOptions();

        document.getElementById('siteMeta').innerHTML =
            '<div><strong>ID:</strong> ' + Number(state.site.id || 0) + '</div>'
            + '<div><strong>Disk folder ID:</strong> ' + Number(state.site.diskFolderId || 0) + '</div>'
            + '<div><strong>Top menu ID:</strong> ' + Number(state.site.topMenuId || 0) + '</div>'
            + '<div><strong>Created at:</strong> ' + escapeHtml(state.site.createdAt || '') + '</div>'
            + '<div><strong>Updated at:</strong> ' + escapeHtml(state.site.updatedAt || '') + '</div>';

        settingsEmpty.classList.add('sb-hidden');
        settingsForm.classList.remove('sb-hidden');
    }

    function saveSettings() {
        if (!state.site) {
            return;
        }

        var siteName = (document.getElementById('siteName').value || '').trim();
        var siteSlug = (document.getElementById('siteSlug').value || '').trim();
        var containerWidth = parseInt(document.getElementById('containerWidth').value, 10) || 1100;
        var accent = (document.getElementById('accent').value || '').trim();
        var logoFileId = parseInt(document.getElementById('logoFileId').value, 10) || 0;
        var homePageId = parseInt(document.getElementById('homePageId').value, 10) || 0;

        if (!siteName) {
            alert('Название сайта не может быть пустым');
            return;
        }

        api('site.update', {
            siteId: SITE_ID,
            name: siteName,
            slug: siteSlug,
            containerWidth: containerWidth,
            accent: accent,
            logoFileId: logoFileId
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось сохранить настройки сайта');
                return;
            }

            state.site = res.site || state.site;

            if (homePageId > 0) {
                api('site.setHome', {
                    siteId: SITE_ID,
                    pageId: homePageId
                }, function (res2) {
                    if (!res2 || res2.ok !== true) {
                        alert('Основные настройки сохранены, но не удалось установить домашнюю страницу');
                        loadSite(function () {
                            loadPages(renderSite);
                        });
                        return;
                    }

                    loadSite(function () {
                        loadPages(renderSite);
                    });
                });
            } else {
                var oldSite = state.site || {};
                oldSite.homePageId = 0;
                state.site = oldSite;
                renderSite();
                alert('Настройки сохранены. Если нужно сбросить home page в API полностью, это можно добавить отдельным action.');
            }
        });
    }

    document.getElementById('saveSettingsBtn').addEventListener('click', saveSettings);
    document.getElementById('reloadBtn').addEventListener('click', function () {
        loadSite(function () {
            loadPages(renderSite);
        });
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

    loadSite(function () {
        loadPages(renderSite);
    });
})();
</script>
</body>
</html>