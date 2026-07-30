<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';

global $APPLICATION, $USER;

sitebuilder_require_auth();

CJSCore::Init(['ajax']);

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
        <title>SiteBuilder / Menu</title>
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

if (!$USER->IsAdmin()) {
    sb_require_content_manager($siteId);
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>SiteBuilder / Menu</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    <style>
        .sb-menus-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            gap: 16px;
        }

        .sb-menu-card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
            overflow: hidden;
        }

        .sb-menu-card-top {
            padding: 16px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .sb-menu-name {
            margin: 0 0 8px;
            font-size: 18px;
            font-weight: 700;
            word-break: break-word;
        }

        .sb-menu-actions {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .sb-menu-items {
            padding: 16px;
        }

        .sb-menu-items-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .sb-items-title {
            font-size: 15px;
            font-weight: 700;
            margin: 0;
        }

        .sb-item-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .sb-menu-item-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px;
            background: #fafafa;
        }

        .sb-menu-item-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }

        .sb-menu-item-title {
            font-size: 15px;
            font-weight: 700;
            margin: 0 0 6px;
        }

        .sb-dialog-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 1000;
        }

        .sb-dialog {
            width: 100%;
            max-width: 640px;
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.2);
            overflow: hidden;
        }

        .sb-dialog-head {
            padding: 16px 18px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 18px;
            font-weight: 700;
        }

        .sb-dialog-body {
            padding: 18px;
        }

        .sb-dialog-actions {
            padding: 16px 18px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        @media (max-width: 900px) {
            .sb-menus-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-topbar">
        <div>
            <a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/index.php">← К списку сайтов</a>
            <h1 class="sb-title">Меню сайта</h1>
            <p class="sb-subtitle">siteId = <?= (int)$siteId ?></p>
        </div>
        <div class="sb-actions">
            <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/trash.php?siteId=<?= (int)$siteId ?>">Корзина</a>
            <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/audit.php?siteId=<?= (int)$siteId ?>">Журнал</a>
        </div>
    </div>

    <div class="sb-panel">
        <h2 class="sb-panel-title">Создать меню</h2>
        <div class="sb-form-row align-end">
            <div class="sb-field">
                <label for="newMenuName">Название меню</label>
                <input class="sb-input" type="text" id="newMenuName" placeholder="Например: Верхнее меню">
            </div>
            <button type="button" class="sb-btn sb-btn-primary" id="createMenuBtn">Создать меню</button>
            <button type="button" class="sb-btn sb-btn-light" id="reloadBtn">Обновить</button>
        </div>
    </div>

    <div class="sb-panel">
        <h2 class="sb-panel-title">Меню сайта</h2>
        <div id="menusContainer">
            <div class="sb-empty">Загрузка...</div>
        </div>
    </div>

    <div class="sb-panel">
        <h2 class="sb-panel-title">Отладка</h2>
        <div id="output" class="sb-output">Здесь будут ответы API...</div>
    </div>
</div>

<div class="sb-dialog-backdrop" id="itemDialogBackdrop">
    <div class="sb-dialog">
        <div class="sb-dialog-head" id="itemDialogTitle">Пункт меню</div>
        <div class="sb-dialog-body">
            <div class="sb-form-row align-end">
                <div class="sb-field">
                    <label for="itemTitle">Название</label>
                    <input class="sb-input" type="text" id="itemTitle" placeholder="Например: Главная">
                </div>
                <div class="sb-field">
                    <label for="itemType">Тип</label>
                    <select class="sb-select" id="itemType">
                        <option value="page">Страница</option>
                        <option value="url">Ссылка</option>
                    </select>
                </div>
            </div>

            <div class="sb-form-row align-end" style="margin-top:12px;">
                <div class="sb-field" id="itemPageField">
                    <label for="itemPageId">Страница</label>
                    <select class="sb-select" id="itemPageId"></select>
                </div>
                <div class="sb-field" id="itemUrlField" style="display:none;">
                    <label for="itemUrl">URL</label>
                    <input class="sb-input" type="text" id="itemUrl" placeholder="https://example.com">
                </div>
                <div class="sb-field">
                    <label for="itemTarget">Target</label>
                    <select class="sb-select" id="itemTarget">
                        <option value="_self">_self</option>
                        <option value="_blank">_blank</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="sb-dialog-actions">
            <button type="button" class="sb-btn sb-btn-gray" id="itemDialogCancel">Отмена</button>
            <button type="button" class="sb-btn sb-btn-primary" id="itemDialogSave">Сохранить</button>
        </div>
    </div>
</div>

<script>
(function () {
    var BASE_PATH = '<?= CUtil::JSEscape($basePath) ?>';
    var API_URL = BASE_PATH + '/api.php';
    var SITE_ID = <?= (int)$siteId ?>;
    var output = document.getElementById('output');
    var menusContainer = document.getElementById('menusContainer');

    var state = {
        menus: [],
        pages: [],
        topMenuId: 0,
        siteVersion: 1,
        itemDialog: {
            open: false,
            mode: 'create',
            menuId: 0,
            itemId: 0
        }
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

    function loadMenus() {
        menusContainer.innerHTML = '<div class="sb-empty">Загрузка...</div>';

        api('menu.list', { siteId: SITE_ID }, function (res) {
            if (!res || res.ok !== true) {
                menusContainer.innerHTML = '<div class="sb-empty">Не удалось загрузить меню</div>';
                return;
            }

            state.menus = Array.isArray(res.menus) ? res.menus : [];
            state.topMenuId = Number(res.topMenuId || 0);
            state.siteVersion = Number(res.siteVersion || 1);
            renderMenus();
        });
    }

    function renderMenus() {
        if (!state.menus.length) {
            menusContainer.innerHTML = '<div class="sb-empty">Меню пока нет</div>';
            return;
        }

        var html = '<div class="sb-menus-grid">';
        for (var i = 0; i < state.menus.length; i++) {
            html += renderMenuCard(state.menus[i]);
        }
        html += '</div>';

        menusContainer.innerHTML = html;
    }

    function renderMenuCard(menu) {
        var id = Number(menu.id || 0);
        var isTop = id === Number(state.topMenuId || 0);
        var items = Array.isArray(menu.items) ? menu.items : [];
        var version = Number(menu.version || 1);

        var html = ''
            + '<div class="sb-menu-card">'
            + '  <div class="sb-menu-card-top">'
            + '    <div>'
            + '      <h3 class="sb-menu-name">' + escapeHtml(menu.name || '') + '</h3>'
            + '      <div class="sb-meta">'
            + '        <div><strong>ID:</strong> ' + id + '</div>'
            + '        <div><strong>Пунктов:</strong> ' + items.length + '</div>'
            + '        <div><strong>Версия:</strong> ' + version + '</div>'
            + '      </div>'
            + '    </div>'
            + '    <div>'
            +      (isTop ? '<span class="sb-badge sb-badge-green">TOP MENU</span>' : '<span class="sb-badge">menu #' + id + '</span>')
            + '    </div>'
            + '  </div>';

        html += ''
            + '<div class="sb-menu-actions">'
            + '  <button type="button" class="sb-btn sb-btn-light sb-btn-small js-rename-menu" data-id="' + id + '">Переименовать</button>'
            + '  <button type="button" class="sb-btn sb-btn-light sb-btn-small js-set-top-menu" data-id="' + id + '">Сделать верхним</button>'
            + '  <button type="button" class="sb-btn sb-btn-light sb-btn-small js-add-item" data-id="' + id + '">Добавить пункт</button>'
            + '  <a class="sb-btn sb-btn-light sb-btn-small" href="' + BASE_PATH + '/versions.php?siteId=' + SITE_ID + '&entityType=menu&entityId=' + id + '">История</a>'
            + '  <button type="button" class="sb-btn sb-btn-danger sb-btn-small js-delete-menu" data-id="' + id + '">Удалить</button>'
            + '</div>';

        html += '<div class="sb-menu-items">';
        html += '<div class="sb-menu-items-header"><h4 class="sb-items-title">Пункты меню</h4></div>';

        if (!items.length) {
            html += '<div class="sb-empty">Пунктов пока нет</div>';
        } else {
            html += '<div class="sb-item-list">';
            for (var j = 0; j < items.length; j++) {
                html += renderItemCard(id, items[j], j, items.length);
            }
            html += '</div>';
        }

        html += '</div></div>';

        return html;
    }

    function renderItemCard(menuId, item, index, total) {
        var itemId = Number(item.id || 0);
        var type = String(item.type || '');
        var title = escapeHtml(item.title || '');
        var target = escapeHtml(item.target || '_self');
        var pageId = Number(item.pageId || 0);
        var url = escapeHtml(item.url || '');

        var details = '';
        if (type === 'page') {
            details += '<div><strong>Тип:</strong> page</div>';
            details += '<div><strong>Page ID:</strong> ' + pageId + '</div>';
        } else {
            details += '<div><strong>Тип:</strong> url</div>';
            details += '<div><strong>URL:</strong> ' + url + '</div>';
        }
        details += '<div><strong>Target:</strong> ' + target + '</div>';
        details += '<div><strong>Sort:</strong> ' + Number(item.sort || 0) + '</div>';

        return ''
            + '<div class="sb-menu-item-card">'
            + '  <div class="sb-menu-item-head">'
            + '    <div>'
            + '      <div class="sb-menu-item-title">' + title + '</div>'
            + '      <div class="sb-meta">' + details + '</div>'
            + '    </div>'
            + '    <span class="sb-badge">item #' + itemId + '</span>'
            + '  </div>'
            + '  <div class="sb-actions" style="margin-top:10px;">'
            + '    <button type="button" class="sb-btn sb-btn-light sb-btn-small js-edit-item" data-menu-id="' + menuId + '" data-item-id="' + itemId + '">Редактировать</button>'
            + '    <button type="button" class="sb-btn sb-btn-gray sb-btn-small js-move-item-up" data-menu-id="' + menuId + '" data-item-id="' + itemId + '"' + (index === 0 ? ' disabled' : '') + '>↑</button>'
            + '    <button type="button" class="sb-btn sb-btn-gray sb-btn-small js-move-item-down" data-menu-id="' + menuId + '" data-item-id="' + itemId + '"' + (index === total - 1 ? ' disabled' : '') + '>↓</button>'
            + '    <button type="button" class="sb-btn sb-btn-danger sb-btn-small js-delete-item" data-menu-id="' + menuId + '" data-item-id="' + itemId + '">Удалить</button>'
            + '  </div>'
            + '</div>';
    }

    function createMenu() {
        var input = document.getElementById('newMenuName');
        var name = (input.value || '').trim();

        if (!name) {
            alert('Введите название меню');
            input.focus();
            return;
        }

        api('menu.create', {
            siteId: SITE_ID,
            name: name
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось создать меню');
                return;
            }

            input.value = '';
            loadMenus();
        });
    }

    function renameMenu(menuId) {
        var menu = findMenu(menuId);
        if (!menu) {
            alert('Меню не найдено');
            return;
        }

        var name = prompt('Новое название меню:', menu.name || '');
        if (name === null) {
            return;
        }

        name = name.trim();
        if (!name) {
            alert('Название не может быть пустым');
            return;
        }

        api('menu.update', {
            id: menuId,
            name: name,
            expectedVersion: Number(menu.version || 1)
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось переименовать меню');
                return;
            }

            loadMenus();
        });
    }

    function deleteMenu(menuId) {
        var menu = findMenu(menuId);
        if (!menu) { alert('Меню не найдено'); return; }
        if (!confirm('Удалить меню #' + menuId + '?')) {
            return;
        }

        api('menu.delete', {
            id: menuId,
            expectedVersion: Number(menu.version || 1),
            expectedSiteVersion: Number(state.siteVersion || 1)
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось удалить меню');
                return;
            }

            loadMenus();
        });
    }

    function setTopMenu(menuId) {
        api('menu.setTop', {
            siteId: SITE_ID,
            menuId: menuId,
            expectedSiteVersion: Number(state.siteVersion || 1)
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось назначить верхнее меню');
                return;
            }

            loadMenus();
        });
    }

    function findMenu(menuId) {
        for (var i = 0; i < state.menus.length; i++) {
            if (Number(state.menus[i].id || 0) === Number(menuId || 0)) {
                return state.menus[i];
            }
        }
        return null;
    }

    function findItem(menuId, itemId) {
        var menu = findMenu(menuId);
        if (!menu || !Array.isArray(menu.items)) {
            return null;
        }

        for (var i = 0; i < menu.items.length; i++) {
            if (Number(menu.items[i].id || 0) === Number(itemId || 0)) {
                return menu.items[i];
            }
        }
        return null;
    }

    function fillPageOptions(selectedPageId) {
        var select = document.getElementById('itemPageId');
        var html = '<option value="">Выберите страницу</option>';

        for (var i = 0; i < state.pages.length; i++) {
            var page = state.pages[i];
            var pid = Number(page.id || 0);
            var title = escapeHtml(page.title || ('Страница #' + pid));
            var selected = pid === Number(selectedPageId || 0) ? ' selected' : '';
            html += '<option value="' + pid + '"' + selected + '>' + title + ' (#' + pid + ')</option>';
        }

        select.innerHTML = html;
    }

    function toggleItemFields() {
        var type = document.getElementById('itemType').value;
        document.getElementById('itemPageField').style.display = type === 'page' ? '' : 'none';
        document.getElementById('itemUrlField').style.display = type === 'url' ? '' : 'none';
    }

    function openItemDialog(mode, menuId, itemId) {
        state.itemDialog.open = true;
        state.itemDialog.mode = mode;
        state.itemDialog.menuId = Number(menuId || 0);
        state.itemDialog.itemId = Number(itemId || 0);

        var backdrop = document.getElementById('itemDialogBackdrop');
        var title = document.getElementById('itemDialogTitle');
        var itemTitle = document.getElementById('itemTitle');
        var itemType = document.getElementById('itemType');
        var itemPageId = document.getElementById('itemPageId');
        var itemUrl = document.getElementById('itemUrl');
        var itemTarget = document.getElementById('itemTarget');

        if (mode === 'create') {
            title.textContent = 'Добавить пункт меню';
            itemTitle.value = '';
            itemType.value = 'page';
            fillPageOptions(0);
            itemPageId.value = '';
            itemUrl.value = '';
            itemTarget.value = '_self';
        } else {
            var item = findItem(menuId, itemId);
            if (!item) {
                alert('Пункт меню не найден');
                return;
            }

            title.textContent = 'Редактировать пункт меню';
            itemTitle.value = item.title || '';
            itemType.value = item.type || 'page';
            fillPageOptions(Number(item.pageId || 0));
            itemPageId.value = String(item.pageId || '');
            itemUrl.value = item.url || '';
            itemTarget.value = item.target || '_self';
        }

        toggleItemFields();
        backdrop.style.display = 'flex';
    }

    function closeItemDialog() {
        state.itemDialog.open = false;
        document.getElementById('itemDialogBackdrop').style.display = 'none';
    }

    function saveItemDialog() {
        var menuId = state.itemDialog.menuId;
        var mode = state.itemDialog.mode;
        var itemId = state.itemDialog.itemId;

        var title = (document.getElementById('itemTitle').value || '').trim();
        var type = document.getElementById('itemType').value;
        var pageId = parseInt(document.getElementById('itemPageId').value, 10) || 0;
        var url = (document.getElementById('itemUrl').value || '').trim();
        var target = document.getElementById('itemTarget').value;

        if (!title) {
            alert('Введите название пункта');
            return;
        }

        if (type === 'page' && !pageId) {
            alert('Выберите страницу');
            return;
        }

        if (type === 'url' && !url) {
            alert('Введите URL');
            return;
        }

        var menu = findMenu(menuId);
        if (!menu) { alert('Меню не найдено'); return; }

        var data = {
            menuId: menuId,
            expectedVersion: Number(menu.version || 1),
            title: title,
            type: type,
            pageId: pageId,
            url: url,
            target: target
        };

        var action = 'menu.item.add';
        if (mode === 'edit') {
            action = 'menu.item.update';
            data.itemId = itemId;
        }

        api(action, data, function (res) {
            if (!res || res.ok !== true) {
                alert(mode === 'edit' ? 'Не удалось обновить пункт' : 'Не удалось добавить пункт');
                return;
            }

            closeItemDialog();
            loadMenus();
        });
    }

    function deleteItem(menuId, itemId) {
        var menu = findMenu(menuId);
        if (!menu) { alert('Меню не найдено'); return; }
        if (!confirm('Удалить пункт меню #' + itemId + '?')) {
            return;
        }

        api('menu.item.delete', {
            menuId: menuId,
            itemId: itemId,
            expectedVersion: Number(menu.version || 1)
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось удалить пункт');
                return;
            }

            loadMenus();
        });
    }

    function moveItem(menuId, itemId, dir) {
        var menu = findMenu(menuId);
        if (!menu) { alert('Меню не найдено'); return; }
        api('menu.item.move', {
            menuId: menuId,
            itemId: itemId,
            dir: dir,
            expectedVersion: Number(menu.version || 1)
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось переместить пункт');
                return;
            }

            loadMenus();
        });
    }

    document.getElementById('createMenuBtn').addEventListener('click', createMenu);
    document.getElementById('reloadBtn').addEventListener('click', function () {
        loadPages(loadMenus);
    });

    document.getElementById('itemType').addEventListener('change', toggleItemFields);
    document.getElementById('itemDialogCancel').addEventListener('click', closeItemDialog);
    document.getElementById('itemDialogSave').addEventListener('click', saveItemDialog);

    document.getElementById('itemDialogBackdrop').addEventListener('click', function (e) {
        if (e.target === this) {
            closeItemDialog();
        }
    });

    document.addEventListener('click', function (e) {
        var renameBtn = e.target.closest('.js-rename-menu');
        if (renameBtn) {
            renameMenu(parseInt(renameBtn.getAttribute('data-id'), 10) || 0);
            return;
        }

        var deleteMenuBtn = e.target.closest('.js-delete-menu');
        if (deleteMenuBtn) {
            deleteMenu(parseInt(deleteMenuBtn.getAttribute('data-id'), 10) || 0);
            return;
        }

        var setTopBtn = e.target.closest('.js-set-top-menu');
        if (setTopBtn) {
            setTopMenu(parseInt(setTopBtn.getAttribute('data-id'), 10) || 0);
            return;
        }

        var addItemBtn = e.target.closest('.js-add-item');
        if (addItemBtn) {
            openItemDialog('create', parseInt(addItemBtn.getAttribute('data-id'), 10) || 0, 0);
            return;
        }

        var editItemBtn = e.target.closest('.js-edit-item');
        if (editItemBtn) {
            openItemDialog(
                'edit',
                parseInt(editItemBtn.getAttribute('data-menu-id'), 10) || 0,
                parseInt(editItemBtn.getAttribute('data-item-id'), 10) || 0
            );
            return;
        }

        var deleteItemBtn = e.target.closest('.js-delete-item');
        if (deleteItemBtn) {
            deleteItem(
                parseInt(deleteItemBtn.getAttribute('data-menu-id'), 10) || 0,
                parseInt(deleteItemBtn.getAttribute('data-item-id'), 10) || 0
            );
            return;
        }

        var moveUpBtn = e.target.closest('.js-move-item-up');
        if (moveUpBtn) {
            moveItem(
                parseInt(moveUpBtn.getAttribute('data-menu-id'), 10) || 0,
                parseInt(moveUpBtn.getAttribute('data-item-id'), 10) || 0,
                'up'
            );
            return;
        }

        var moveDownBtn = e.target.closest('.js-move-item-down');
        if (moveDownBtn) {
            moveItem(
                parseInt(moveDownBtn.getAttribute('data-menu-id'), 10) || 0,
                parseInt(moveDownBtn.getAttribute('data-item-id'), 10) || 0,
                'down'
            );
            return;
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

    loadPages(loadMenus);
})();
</script>
</body>
</html>