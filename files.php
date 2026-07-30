<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';

global $APPLICATION, $USER;

sitebuilder_require_auth();

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
        <title>SiteBuilder / Files</title>
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
    <title>SiteBuilder / Files</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    <style>
        .sb-files-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .sb-file-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px;
            background: #fafafa;
        }

        .sb-file-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .sb-file-title {
            margin: 0 0 6px;
            font-size: 15px;
            font-weight: 700;
            word-break: break-word;
        }

        .sb-file-actions {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .sb-file-upload-input {
            max-width: 360px;
        }
    </style>
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-topbar">
        <div>
            <a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/index.php">← К списку сайтов</a>
            <h1 class="sb-title">Файлы сайта</h1>
            <p class="sb-subtitle">siteId = <?= (int)$siteId ?></p>
        </div>
    </div>

    <div class="sb-panel">
        <h2 class="sb-panel-title">Загрузка файла</h2>
        <div class="sb-toolbar">
            <input class="sb-input sb-file-upload-input" type="file" id="uploadFileInput">
            <button type="button" class="sb-btn sb-btn-primary" id="uploadBtn">Загрузить</button>
            <button type="button" class="sb-btn sb-btn-light" id="reloadBtn">Обновить список</button>
        </div>
        <div class="sb-meta" id="folderInfo" style="margin-top:12px;">Папка ещё не загружена</div>
    </div>

    <div class="sb-panel">
        <h2 class="sb-panel-title">Файлы</h2>
        <div id="filesContainer" class="sb-files-list">
            <div class="sb-empty">Загрузка...</div>
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
    var filesContainer = document.getElementById('filesContainer');
    var folderInfo = document.getElementById('folderInfo');
    var uploadFileInput = document.getElementById('uploadFileInput');

    var state = {
        files: [],
        folderId: 0
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

    function loadFiles() {
        filesContainer.innerHTML = '<div class="sb-empty">Загрузка...</div>';

        api('file.list', { siteId: SITE_ID }, function (res) {
            if (!res || res.ok !== true) {
                filesContainer.innerHTML = '<div class="sb-empty">Не удалось загрузить файлы</div>';
                folderInfo.textContent = 'Ошибка загрузки папки';
                return;
            }

            state.files = Array.isArray(res.files) ? res.files : [];
            state.folderId = Number(res.folderId || 0);

            folderInfo.textContent = 'Disk folder ID: ' + state.folderId;
            renderFiles();
        });
    }

    function renderFiles() {
        if (!state.files.length) {
            filesContainer.innerHTML = '<div class="sb-empty">Файлов пока нет</div>';
            return;
        }

        var html = '';
        for (var i = 0; i < state.files.length; i++) {
            html += renderFileCard(state.files[i]);
        }
        filesContainer.innerHTML = html;
    }

    function renderFileCard(file) {
        var id = Number(file.id || 0);
        var name = escapeHtml(file.name || '');
        var size = Number(file.size || 0);
        var createTime = escapeHtml(file.createTime || '');
        var updateTime = escapeHtml(file.updateTime || '');
        var downloadUrl = escapeHtml(file.downloadUrl || '#');

        return ''
            + '<div class="sb-file-card">'
            + '  <div class="sb-file-head">'
            + '    <div>'
            + '      <div class="sb-file-title">' + name + '</div>'
            + '      <div class="sb-meta">'
            + '        <div><strong>ID:</strong> ' + id + '</div>'
            + '        <div><strong>Размер:</strong> ' + size + ' байт</div>'
            + '        <div><strong>Создан:</strong> ' + createTime + '</div>'
            + '        <div><strong>Обновлён:</strong> ' + updateTime + '</div>'
            + '      </div>'
            + '    </div>'
            + '    <span class="sb-badge">file</span>'
            + '  </div>'
            + '  <div class="sb-file-actions">'
            + '    <a class="sb-btn sb-btn-light sb-btn-small" href="' + downloadUrl + '" target="_blank">Скачать</a>'
            + '    <button type="button" class="sb-btn sb-btn-danger sb-btn-small js-delete-file" data-id="' + id + '">Удалить</button>'
            + '  </div>'
            + '</div>';
    }

    function uploadFile() {
        if (!uploadFileInput.files || !uploadFileInput.files.length) {
            alert('Выберите файл');
            return;
        }

        var file = uploadFileInput.files[0];

        var formData = new FormData();
        formData.append('action', 'file.upload');
        formData.append('siteId', String(SITE_ID));
        formData.append('sessid', getSessid());
        formData.append('file', file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API_URL, true);

        xhr.onload = function () {
            var text = xhr.responseText || '';
            var data = null;

            try {
                data = JSON.parse(text);
            } catch (e) {
                print({
                    ok: false,
                    error: 'BAD_JSON',
                    raw: text
                });
                alert('Ответ сервера не является JSON');
                return;
            }

            print(data);

            if (!data || data.ok !== true) {
                alert('Не удалось загрузить файл');
                return;
            }

            uploadFileInput.value = '';
            loadFiles();
        };

        xhr.onerror = function () {
            print({
                ok: false,
                error: 'XHR_UPLOAD_ERROR'
            });
            alert('Ошибка загрузки файла');
        };

        xhr.send(formData);
    }

    function deleteFile(fileId) {
        if (!confirm('Удалить файл #' + fileId + '?')) {
            return;
        }

        api('file.delete', {
            siteId: SITE_ID,
            fileId: fileId
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось удалить файл');
                return;
            }

            loadFiles();
        });
    }

    document.getElementById('uploadBtn').addEventListener('click', uploadFile);
    document.getElementById('reloadBtn').addEventListener('click', loadFiles);

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-delete-file');
        if (!btn) {
            return;
        }

        deleteFile(parseInt(btn.getAttribute('data-id'), 10) || 0);
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

    loadFiles();
})();
</script>
</body>
</html>