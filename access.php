<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/AccessUserIdentity.php';
sitebuilder_require_auth();
if (!AccessUserIdentity::canOpenAdmin()) {
    http_response_code(403);
    exit('Управление доступом доступно администраторам Sitebuilder и Битрикс.');
}
global $APPLICATION;
CJSCore::Init(['ajax']);
header('Cache-Control: no-store, private');
header('Content-Type: text/html; charset=UTF-8');
$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sitebuilder — Управление доступом</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= $esc($basePath) ?>/assets/admin/access.css?v=1">
</head>
<body class="sba-body">
<main class="sba-app" id="sba-app">
    <header class="sba-header">
        <div><a href="<?= $esc($basePath) ?>/index.php">← Все сайты</a><h1>Управление доступом</h1>
            <p>Выберите объект, добавьте сотрудников и назначьте права.</p></div>
        <button type="button" id="sba-reload">Обновить / отменить изменения</button>
    </header>
    <div id="sba-message" role="status" aria-live="polite" hidden></div>
    <div class="sba-layout">
        <aside class="sba-sidebar" aria-label="Объекты доступа"><h2>Сайты и диски</h2><div id="sba-tree">Загрузка…</div></aside>
        <section class="sba-content" id="sba-content" aria-labelledby="sba-title">
            <h2 id="sba-title">Выберите сайт, страницу или папку</h2>
            <p id="sba-note">Существующие разрешения не меняются при открытии этой страницы.</p>
            <div class="sba-links"><a id="sba-editor" hidden>Настройки сайта и блоков</a><a id="sba-history" hidden>История изменений</a></div>
            <div id="sba-controls" hidden>
                <details id="sba-add"><summary>Добавить пользователей</summary>
                    <div class="sba-add-grid">
                        <label>Поиск сотрудника<input id="sba-search" autocomplete="off" placeholder="Фамилия, ФИО, логин, почта или ID"><span id="sba-search-results"></span></label>
                        <label>Вставить список<textarea id="sba-paste" rows="5" placeholder="Иванов Иван Иванович&#10;employee@example.org&#10;123"></textarea>
                        <span class="sba-hint">До 5 000 строк. Один сотрудник на строку или через «;». Можно вставить столбец Excel.</span></label>
                    </div>
                    <div id="sba-resolve-status" role="status"></div><div id="sba-unresolved"></div>
                </details>
                <div class="sba-toolbar">
                    <label>Право для выбранных<select id="sba-bulk-role"></select></label>
                    <label id="sba-bulk-disk-wrap" hidden>Диск страницы<select id="sba-bulk-disk"></select></label>
                    <button type="button" id="sba-remove">Снять прямые назначения выбранных</button>
                </div>
            </div>
            <div id="sba-table-wrap" class="sba-table-wrap" hidden>
                <table><thead id="sba-head"></thead><tbody id="sba-rows"></tbody></table>
            </div>
            <div class="sba-footer" id="sba-footer" hidden>
                <span id="sba-count"></span><button type="button" id="sba-more" hidden>Ещё 100 пользователей</button>
                <button type="button" class="sba-primary" id="sba-save" disabled>Сохранить изменения</button>
            </div>
        </section>
    </div>
</main>
<script id="sba-config" type="application/json"><?= json_encode([
    'basePath' => $basePath, 'sessid' => bitrix_sessid(), 'siteId' => (int)($_GET['siteId'] ?? 0),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= $esc($basePath) ?>/assets/admin/access.js?v=1" defer></script>
</body>
</html>
