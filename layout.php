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
        <title>SiteBuilder / Layout</title>
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
    <title>SiteBuilder / Layout</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    <style>
        .sb-layout-zones-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .sb-layout-zone-card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
            overflow: hidden;
        }

        .sb-layout-zone-head {
            padding: 14px 16px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .sb-layout-zone-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .sb-layout-zone-body {
            padding: 16px;
        }

        .sb-layout-blocks {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .sb-layout-block {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fafafa;
            padding: 12px;
        }

        .sb-layout-block-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }

        .sb-layout-block-title {
            margin: 0 0 6px;
            font-size: 15px;
            font-weight: 700;
        }

        .sb-layout-editor-box {
            margin-top: 20px;
        }

        @media (max-width: 1200px) {
            .sb-layout-zones-grid {
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
            <h1 class="sb-title">Layout сайта</h1>
            <p class="sb-subtitle">siteId = <?= (int)$siteId ?></p>
        </div>
    </div>

    <div class="sb-panel">
        <h2 class="sb-panel-title">Настройки layout</h2>

        <div class="sb-grid-4">
            <div class="sb-field">
                <label for="showHeader">Show header</label>
                <select class="sb-select" id="showHeader">
                    <option value="1">Да</option>
                    <option value="0">Нет</option>
                </select>
            </div>

            <div class="sb-field">
                <label for="showFooter">Show footer</label>
                <select class="sb-select" id="showFooter">
                    <option value="1">Да</option>
                    <option value="0">Нет</option>
                </select>
            </div>

            <div class="sb-field">
                <label for="showLeft">Show left</label>
                <select class="sb-select" id="showLeft">
                    <option value="1">Да</option>
                    <option value="0">Нет</option>
                </select>
            </div>

            <div class="sb-field">
                <label for="showRight">Show right</label>
                <select class="sb-select" id="showRight">
                    <option value="1">Да</option>
                    <option value="0">Нет</option>
                </select>
            </div>

            <div class="sb-field">
                <label for="leftWidth">Left width</label>
                <input class="sb-input" type="number" id="leftWidth" min="120" max="800">
            </div>

            <div class="sb-field">
                <label for="rightWidth">Right width</label>
                <input class="sb-input" type="number" id="rightWidth" min="120" max="800">
            </div>

            <div class="sb-field">
                <label for="leftMode">Left mode</label>
                <select class="sb-select" id="leftMode">
                    <option value="blocks">blocks</option>
                    <option value="menu">menu</option>
                </select>
            </div>
        </div>

        <div class="sb-toolbar" style="margin-top:16px;">
            <button type="button" class="sb-btn sb-btn-primary" id="saveSettingsBtn">Сохранить layout settings</button>
            <button type="button" class="sb-btn sb-btn-light" id="reloadBtn">Обновить</button>
        </div>
    </div>

    <div class="sb-layout-zones-grid" id="zonesContainer"></div>

    <div class="sb-panel sb-layout-editor-box">
        <h2 class="sb-panel-title">Редактор layout-блока</h2>

        <div id="layoutBlockEditorEmpty" class="sb-empty">Выберите блок для редактирования</div>

        <div id="layoutBlockEditorForm" class="sb-hidden">
            <div class="sb-field" style="margin-bottom:12px;">
                <label for="editLayoutBlockZone">Zone</label>
                <input class="sb-input" type="text" id="editLayoutBlockZone" readonly>
            </div>

            <div class="sb-field" style="margin-bottom:12px;">
                <label for="editLayoutBlockType">Тип</label>
                <input class="sb-input" type="text" id="editLayoutBlockType" readonly>
            </div>

            <div class="sb-field" style="margin-bottom:12px;">
                <label for="editLayoutBlockContent">Content / JSON</label>
                <textarea class="sb-textarea" id="editLayoutBlockContent"></textarea>
            </div>

            <div class="sb-field" style="margin-bottom:12px;">
                <label for="editLayoutBlockProps">Props / JSON</label>
                <textarea class="sb-textarea" id="editLayoutBlockProps">{}</textarea>
            </div>

            <div class="sb-toolbar">
                <button type="button" class="sb-btn sb-btn-primary" id="saveLayoutBlockBtn">Сохранить</button>
                <button type="button" class="sb-btn sb-btn-danger" id="deleteLayoutBlockBtn">Удалить</button>
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
    var zonesContainer = document.getElementById('zonesContainer');

    var state = {
        layout: null,
        currentBlockId: 0,
        currentZone: ''
    };

    var ZONES = ['header', 'footer', 'left', 'right'];

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

    function loadLayout() {
        api('layout.get', { siteId: SITE_ID }, function (res) {
            if (!res || res.ok !== true) {
                zonesContainer.innerHTML = '<div class="sb-empty">Не удалось загрузить layout</div>';
                return;
            }

            state.layout = res.layout || null;
            renderSettings();
            renderZones();
        });
    }

    function renderSettings() {
        if (!state.layout || !state.layout.settings) {
            return;
        }

        var s = state.layout.settings;
        document.getElementById('showHeader').value = s.showHeader ? '1' : '0';
        document.getElementById('showFooter').value = s.showFooter ? '1' : '0';
        document.getElementById('showLeft').value = s.showLeft ? '1' : '0';
        document.getElementById('showRight').value = s.showRight ? '1' : '0';
        document.getElementById('leftWidth').value = Number(s.leftWidth || 260);
        document.getElementById('rightWidth').value = Number(s.rightWidth || 260);
        document.getElementById('leftMode').value = s.leftMode || 'blocks';
    }

    function renderZones() {
        if (!state.layout || !state.layout.zones) {
            zonesContainer.innerHTML = '<div class="sb-empty">Нет layout-зон</div>';
            return;
        }

        var html = '';
        for (var i = 0; i < ZONES.length; i++) {
            html += renderZoneCard(ZONES[i], state.layout.zones[ZONES[i]] || []);
        }
        zonesContainer.innerHTML = html;

        if (state.currentBlockId) {
            var found = findLayoutBlock(state.currentBlockId);
            if (!found) {
                clearLayoutBlockEditor();
            }
        }
    }

    function renderZoneCard(zone, blocks) {
        var html = ''
            + '<div class="sb-layout-zone-card">'
            + '  <div class="sb-layout-zone-head">'
            + '    <h3 class="sb-layout-zone-title">' + escapeHtml(zone) + '</h3>'
            + '    <span class="sb-badge">' + blocks.length + ' блок(ов)</span>'
            + '  </div>'
            + '  <div class="sb-layout-zone-body">'
            + '    <div class="sb-toolbar" style="margin-bottom:12px;">'
            + '      <button type="button" class="sb-btn sb-btn-light sb-btn-small js-add-layout-block" data-zone="' + zone + '" data-type="text">+ Text</button>'
            + '      <button type="button" class="sb-btn sb-btn-light sb-btn-small js-add-layout-block" data-zone="' + zone + '" data-type="heading">+ Heading</button>'
            + '      <button type="button" class="sb-btn sb-btn-light sb-btn-small js-add-layout-block" data-zone="' + zone + '" data-type="button">+ Button</button>'
            + '      <button type="button" class="sb-btn sb-btn-light sb-btn-small js-add-layout-block" data-zone="' + zone + '" data-type="html">+ HTML</button>'
            + '    </div>';

        if (!blocks.length) {
            html += '<div class="sb-empty">Блоков нет</div>';
        } else {
            html += '<div class="sb-layout-blocks">';
            for (var i = 0; i < blocks.length; i++) {
                html += renderLayoutBlock(zone, blocks[i], i, blocks.length);
            }
            html += '</div>';
        }

        html += '</div></div>';
        return html;
    }

    function renderLayoutBlock(zone, block, index, total) {
        var id = Number(block.id || 0);
        var type = escapeHtml(block.type || '');
        var preview = escapeHtml(buildPreviewText(block));

        return ''
            + '<div class="sb-layout-block">'
            + '  <div class="sb-layout-block-head">'
            + '    <div>'
            + '      <div class="sb-layout-block-title">' + type + ' #' + id + '</div>'
            + '      <div class="sb-meta">'
            + '        <div><strong>Zone:</strong> ' + escapeHtml(zone) + '</div>'
            + '        <div><strong>Sort:</strong> ' + Number(block.sort || 0) + '</div>'
            + '      </div>'
            + '    </div>'
            + '    <span class="sb-badge">layout block</span>'
            + '  </div>'
            + '  <div class="sb-meta" style="margin-top:8px;">' + preview + '</div>'
            + '  <div class="sb-actions" style="margin-top:10px;">'
            + '    <button type="button" class="sb-btn sb-btn-light sb-btn-small js-edit-layout-block" data-zone="' + zone + '" data-id="' + id + '">Редактировать</button>'
            + '    <button type="button" class="sb-btn sb-btn-gray sb-btn-small js-move-layout-block-up" data-id="' + id + '"' + (index === 0 ? ' disabled' : '') + '>↑</button>'
            + '    <button type="button" class="sb-btn sb-btn-gray sb-btn-small js-move-layout-block-down" data-id="' + id + '"' + (index === total - 1 ? ' disabled' : '') + '>↓</button>'
            + '    <button type="button" class="sb-btn sb-btn-danger sb-btn-small js-delete-layout-block" data-id="' + id + '">Удалить</button>'
            + '  </div>'
            + '</div>';
    }

    function buildPreviewText(block) {
        var type = String(block.type || '');
        var content = block.content || {};

        if (type === 'text') {
            return String(content.html || '').replace(/<[^>]*>/g, ' ').trim() || '[text]';
        }
        if (type === 'heading') {
            return String(content.text || '') || '[heading]';
        }
        if (type === 'button') {
            return (content.text || '[button]') + ' → ' + (content.href || '');
        }
        if (type === 'html') {
            return String(content.html || '').replace(/<[^>]*>/g, ' ').trim() || '[html]';
        }

        return JSON.stringify(content);
    }

    function saveLayoutSettings() {
        var settings = {
            showHeader: document.getElementById('showHeader').value === '1',
            showFooter: document.getElementById('showFooter').value === '1',
            showLeft: document.getElementById('showLeft').value === '1',
            showRight: document.getElementById('showRight').value === '1',
            leftWidth: parseInt(document.getElementById('leftWidth').value, 10) || 260,
            rightWidth: parseInt(document.getElementById('rightWidth').value, 10) || 260,
            leftMode: document.getElementById('leftMode').value || 'blocks'
        };

        api('layout.updateSettings', {
            siteId: SITE_ID,
            settings: JSON.stringify(settings)
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось сохранить layout settings');
                return;
            }

            state.layout = res.layout || state.layout;
            renderSettings();
            renderZones();
        });
    }

    function addLayoutBlock(zone, type) {
        api('layout.block.create', {
            siteId: SITE_ID,
            zone: zone,
            type: type
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось создать layout block');
                return;
            }

            state.layout = res.layout || state.layout;
            renderZones();
        });
    }

    function findLayoutBlock(blockId) {
        if (!state.layout || !state.layout.zones) {
            return null;
        }

        for (var i = 0; i < ZONES.length; i++) {
            var zone = ZONES[i];
            var blocks = state.layout.zones[zone] || [];
            for (var j = 0; j < blocks.length; j++) {
                if (Number(blocks[j].id || 0) === Number(blockId || 0)) {
                    return {
                        zone: zone,
                        block: blocks[j]
                    };
                }
            }
        }

        return null;
    }

    function editLayoutBlock(zone, blockId) {
        var found = findLayoutBlock(blockId);
        if (!found) {
            alert('Блок не найден');
            return;
        }

        state.currentBlockId = Number(blockId || 0);
        state.currentZone = found.zone || zone;

        document.getElementById('layoutBlockEditorEmpty').classList.add('sb-hidden');
        document.getElementById('layoutBlockEditorForm').classList.remove('sb-hidden');
        document.getElementById('editLayoutBlockZone').value = state.currentZone;
        document.getElementById('editLayoutBlockType').value = found.block.type || '';
        document.getElementById('editLayoutBlockContent').value = JSON.stringify(found.block.content || {}, null, 2);
        document.getElementById('editLayoutBlockProps').value = JSON.stringify(found.block.props || {}, null, 2);
    }

    function clearLayoutBlockEditor() {
        state.currentBlockId = 0;
        state.currentZone = '';
        document.getElementById('layoutBlockEditorEmpty').classList.remove('sb-hidden');
        document.getElementById('layoutBlockEditorForm').classList.add('sb-hidden');
        document.getElementById('editLayoutBlockZone').value = '';
        document.getElementById('editLayoutBlockType').value = '';
        document.getElementById('editLayoutBlockContent').value = '';
        document.getElementById('editLayoutBlockProps').value = '{}';
    }

    function saveLayoutBlock() {
        if (!state.currentBlockId) {
            return;
        }

        var contentText = document.getElementById('editLayoutBlockContent').value || '{}';
        var propsText = document.getElementById('editLayoutBlockProps').value || '{}';

        try {
            JSON.parse(contentText);
        } catch (e) {
            alert('Content должен быть валидным JSON');
            return;
        }

        try {
            JSON.parse(propsText);
        } catch (e) {
            alert('Props должен быть валидным JSON');
            return;
        }

        var currentId = Number(state.currentBlockId || 0);
        var currentZone = String(state.currentZone || '');

        api('layout.block.update', {
            siteId: SITE_ID,
            id: currentId,
            content: contentText,
            props: propsText
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось сохранить layout block');
                return;
            }

            state.layout = res.layout || state.layout;
            renderZones();

            var found = findLayoutBlock(currentId);
            if (found) {
                state.currentBlockId = currentId;
                state.currentZone = found.zone;
                document.getElementById('editLayoutBlockZone').value = found.zone;
                document.getElementById('editLayoutBlockType').value = found.block.type || '';
                document.getElementById('editLayoutBlockContent').value = JSON.stringify(found.block.content || {}, null, 2);
                document.getElementById('editLayoutBlockProps').value = JSON.stringify(found.block.props || {}, null, 2);
                document.getElementById('layoutBlockEditorEmpty').classList.add('sb-hidden');
                document.getElementById('layoutBlockEditorForm').classList.remove('sb-hidden');
            } else {
                state.currentBlockId = 0;
                state.currentZone = currentZone;
                clearLayoutBlockEditor();
            }
        });
    }

    function deleteLayoutBlock(blockId) {
        if (!confirm('Удалить layout block #' + blockId + '?')) {
            return;
        }

        api('layout.block.delete', {
            siteId: SITE_ID,
            id: blockId
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось удалить layout block');
                return;
            }

            state.layout = res.layout || state.layout;

            if (Number(state.currentBlockId || 0) === Number(blockId || 0)) {
                clearLayoutBlockEditor();
            }

            renderZones();
        });
    }

    function moveLayoutBlock(blockId, dir) {
        api('layout.block.move', {
            siteId: SITE_ID,
            id: blockId,
            dir: dir
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось переместить layout block');
                return;
            }

            state.layout = res.layout || state.layout;
            renderZones();
        });
    }

    document.getElementById('saveSettingsBtn').addEventListener('click', saveLayoutSettings);
    document.getElementById('reloadBtn').addEventListener('click', loadLayout);
    document.getElementById('saveLayoutBlockBtn').addEventListener('click', saveLayoutBlock);
    document.getElementById('deleteLayoutBlockBtn').addEventListener('click', function () {
        if (state.currentBlockId) {
            deleteLayoutBlock(state.currentBlockId);
        }
    });

    document.addEventListener('click', function (e) {
        var addBtn = e.target.closest('.js-add-layout-block');
        if (addBtn) {
            addLayoutBlock(
                addBtn.getAttribute('data-zone') || '',
                addBtn.getAttribute('data-type') || 'text'
            );
            return;
        }

        var editBtn = e.target.closest('.js-edit-layout-block');
        if (editBtn) {
            editLayoutBlock(
                editBtn.getAttribute('data-zone') || '',
                parseInt(editBtn.getAttribute('data-id'), 10) || 0
            );
            return;
        }

        var delBtn = e.target.closest('.js-delete-layout-block');
        if (delBtn) {
            deleteLayoutBlock(parseInt(delBtn.getAttribute('data-id'), 10) || 0);
            return;
        }

        var upBtn = e.target.closest('.js-move-layout-block-up');
        if (upBtn) {
            moveLayoutBlock(parseInt(upBtn.getAttribute('data-id'), 10) || 0, 'up');
            return;
        }

        var downBtn = e.target.closest('.js-move-layout-block-down');
        if (downBtn) {
            moveLayoutBlock(parseInt(downBtn.getAttribute('data-id'), 10) || 0, 'down');
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

    loadLayout();
})();
</script>
</body>
</html>