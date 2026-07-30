<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';

global $APPLICATION, $USER;

sitebuilder_require_bitrix_admin();

if (method_exists($APPLICATION, 'ShowHead')) {
    // ok
}

CJSCore::Init(['ajax']);

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>SiteBuilder API Test</title>
    <?php $APPLICATION->ShowHead(); ?>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        button { margin: 0 10px 10px 0; padding: 10px 14px; cursor: pointer; }
        pre { white-space: pre-wrap; background: #f5f5f5; padding: 16px; border: 1px solid #ccc; min-height: 180px; }
        .row { margin-bottom: 12px; }
    </style>
</head>
<body>
    <h1>Проверка API SiteBuilder</h1>

    <div class="row">
        <strong>Пользователь:</strong>
        <?= htmlspecialchars((string)$USER->GetLogin(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        (ID <?= (int)$USER->GetID() ?>)
    </div>

    <div class="row">
        <strong>sessid from PHP:</strong>
        <?= htmlspecialchars(bitrix_sessid(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>

    <div class="row">
        <button type="button" id="checkBxBtn">Проверить BX</button>
        <button type="button" id="pingBtn">Ping</button>
        <button type="button" id="siteListBtn">Site list</button>
    </div>

    <pre id="out">Здесь будет результат...</pre>

    <script>
        (function () {
            var out = document.getElementById('out');

            function print(data) {
                if (typeof data === 'string') {
                    out.textContent = data;
                    return;
                }
                try {
                    out.textContent = JSON.stringify(data, null, 2);
                } catch (e) {
                    out.textContent = String(data);
                }
            }

            function printError(prefix, err) {
                var text = prefix + '\n';
                try {
                    text += JSON.stringify(err, null, 2);
                } catch (e) {
                    text += String(err);
                }
                out.textContent = text;
            }

            function callApi(data) {
                if (typeof window.BX === 'undefined') {
                    print('BX не загружен');
                    return;
                }

                if (typeof BX.ajax !== 'function') {
                    print('BX.ajax не найден');
                    return;
                }

                var sessid = (typeof BX.bitrix_sessid === 'function')
                    ? BX.bitrix_sessid()
                    : '<?= CUtil::JSEscape(bitrix_sessid()) ?>';

                print({
                    status: 'sending',
                    url: '/local/sitebuilder/api.php',
                    data: Object.assign({ sessid: sessid }, data)
                });

                BX.ajax({
                    url: '/local/sitebuilder/api.php',
                    method: 'POST',
                    data: Object.assign({
                        sessid: sessid
                    }, data),
                    dataType: 'json',
                    timeout: 30,
                    onsuccess: function (res) {
                        print(res);
                    },
                    onfailure: function (err) {
                        printError('AJAX ERROR', err);
                    }
                });
            }

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

            document.getElementById('checkBxBtn').addEventListener('click', function () {
                print({
                    BX_exists: typeof window.BX !== 'undefined',
                    BX_ajax_type: typeof window.BX !== 'undefined' ? typeof BX.ajax : 'BX missing',
                    BX_bitrix_sessid_type: typeof window.BX !== 'undefined' ? typeof BX.bitrix_sessid : 'BX missing',
                    sessid_js: (typeof window.BX !== 'undefined' && typeof BX.bitrix_sessid === 'function') ? BX.bitrix_sessid() : null,
                    sessid_php: '<?= CUtil::JSEscape(bitrix_sessid()) ?>'
                });
            });

            document.getElementById('pingBtn').addEventListener('click', function () {
                callApi({ action: 'ping' });
            });

            document.getElementById('siteListBtn').addEventListener('click', function () {
                callApi({ action: 'site.list' });
            });

            print({
                loaded: true,
                BX_exists: typeof window.BX !== 'undefined',
                BX_ajax_type: typeof window.BX !== 'undefined' ? typeof BX.ajax : 'BX missing',
                BX_bitrix_sessid_type: typeof window.BX !== 'undefined' ? typeof BX.bitrix_sessid : 'BX missing',
                sessid_php: '<?= CUtil::JSEscape(bitrix_sessid()) ?>'
            });
        })();
    </script>
</body>
</html>