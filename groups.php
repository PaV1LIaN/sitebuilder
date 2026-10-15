<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/AccessUserIdentity.php';
sitebuilder_require_auth();
if (!AccessUserIdentity::canOpenAdmin()) { http_response_code(403); exit('Нет прав на управление группами.'); }
global $APPLICATION;
CJSCore::Init(['ajax']);
header('Cache-Control: no-store, private');
header('Content-Type: text/html; charset=UTF-8');
$base = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Группы доступа — Sitebuilder</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= $h($base) ?>/assets/admin/groups.css?v=1">
</head>
<body class="sbg-body">
<div class="sbg-app" id="sbg-app">
    <aside class="sbg-sidebar" aria-label="Группы и навигация">
        <a class="sbg-brand" href="<?= $h($base) ?>/index.php"><span class="sbg-logo">S</span> Sitebuilder <span class="sbg-brand-note">ACCESS</span></a>
        <nav class="sbg-nav" aria-label="Разделы доступа">
            <a class="sbg-current" href="<?= $h($base) ?>/groups.php" aria-current="page">Группы доступа <span>→</span></a>
            <a href="<?= $h($base) ?>/access.php">Персональные права</a>
            <a href="<?= $h($base) ?>/index.php">Все сайты</a>
        </nav>
        <div class="sbg-sidebar-label">ВАШИ ГРУППЫ</div>
        <label class="sbg-search-label"><span class="sbg-sr">Найти группу</span><input id="sbg-search" placeholder="Найти группу…" autocomplete="off"></label>
        <div id="sbg-list" class="sbg-group-list" aria-live="polite">Загрузка…</div>
        <button id="sbg-more-groups" class="sbg-sidebar-button" hidden>Ещё группы</button>
        <form id="sbg-create" class="sbg-new-group">
            <label for="sbg-new-name">Новая группа</label>
            <input id="sbg-new-name" placeholder="Например, Отдел снабжения" maxlength="160" required autocomplete="off">
            <button type="submit" class="sbg-primary">+ Создать группу</button>
        </form>
        <p class="sbg-sidebar-foot">Локальные группы Sitebuilder.<br>Без синхронизации с corp.access.</p>
    </aside>
    <main class="sbg-main">
        <header class="sbg-top"><div><span class="sbg-eyebrow">УПРАВЛЕНИЕ ДОСТУПОМ</span><h1>Группы</h1></div><button id="sbg-reload" class="sbg-button">Обновить</button></header>
        <div id="sbg-message" class="sbg-message" role="status" aria-live="polite" hidden></div>
        <div id="sbg-retry-panel" class="sbg-card sbg-retry" hidden><span>Результат запроса не подтверждён. Повтор использует тот же номер операции.</span><button id="sbg-retry" class="sbg-primary">Повторить запрос</button></div>
        <section id="sbg-empty" class="sbg-card sbg-empty"><span class="sbg-empty-symbol">◎</span><h2>Одна группа — нужные права</h2><p>Создайте группу слева, добавьте сотрудников и выберите страницы или папки.<br>Новые участники получат её права без повторной выдачи.</p></section>
        <section id="sbg-work" hidden>
            <div class="sbg-card sbg-group-header">
                <div class="sbg-avatar" aria-hidden="true">◎</div>
                <div class="sbg-group-heading"><span class="sbg-eyebrow">ГРУППА ДОСТУПА <span id="sbg-id"></span></span>
                    <form id="sbg-rename" class="sbg-rename"><label class="sbg-sr" for="sbg-name">Название группы</label><input id="sbg-name" maxlength="160" required><button type="submit" class="sbg-button">Переименовать</button></form>
                    <p id="sbg-summary" class="sbg-muted"></p>
                </div>
                <button id="sbg-delete" class="sbg-button sbg-danger">Удалить группу</button>
            </div>
            <div class="sbg-note"><strong>Права суммируются.</strong> Удаление участника снимает только права этой группы. Личные назначения и остальные группы сохраняются. Явный персональный запрет имеет приоритет.</div>
            <div class="sbg-tabs" role="tablist" aria-label="Настройки группы">
                <button id="sbg-tab-members" role="tab" data-tab="members" aria-selected="true" aria-controls="sbg-panel-members">Участники <span id="sbg-member-badge">0</span></button>
                <button id="sbg-tab-rights" role="tab" data-tab="rights" aria-selected="false" aria-controls="sbg-panel-rights">Права <span id="sbg-rule-badge">0</span></button>
                <button id="sbg-tab-history" role="tab" data-tab="history" aria-selected="false" aria-controls="sbg-panel-history">История</button>
            </div>
            <section id="sbg-panel-members" class="sbg-card sbg-panel" role="tabpanel" aria-labelledby="sbg-tab-members">
                <details id="sbg-add-panel" class="sbg-add-panel"><summary>+ Добавить сотрудников списком</summary>
                    <label for="sbg-paste">ФИО, логин, почта или ID — один сотрудник на строку</label>
                    <textarea id="sbg-paste" rows="5" placeholder="Иванов Иван Иванович&#10;petrov@example.org&#10;557"></textarea>
                    <div class="sbg-toolbar"><span class="sbg-muted">До 5 000 строк. Можно вставить столбец из Excel.</span><button id="sbg-resolve" class="sbg-button" type="button">Распознать список</button></div>
                    <p id="sbg-preview-count"></p><div id="sbg-unresolved"></div>
                    <button id="sbg-add" class="sbg-primary" disabled>Добавить найденных</button>
                </details>
                <div class="sbg-section-header"><h2>Состав группы</h2><button id="sbg-remove-members" class="sbg-button sbg-danger" disabled>Удалить выбранных</button></div>
                <div class="sbg-table-wrap"><table class="sbg-table"><thead><tr><th><input id="sbg-check-all" type="checkbox" aria-label="Выбрать загруженных участников"></th><th>Сотрудник</th><th>Логин</th><th>Статус</th></tr></thead><tbody id="sbg-members"></tbody></table></div>
                <button id="sbg-more-members" class="sbg-button sbg-more" hidden>Ещё 100 участников</button>
            </section>
            <section id="sbg-panel-rights" class="sbg-card sbg-panel" role="tabpanel" aria-labelledby="sbg-tab-rights" hidden>
                <details id="sbg-rule-panel" class="sbg-rule-panel"><summary>+ Назначить доступ к объекту</summary>
                    <div class="sbg-rule-layout"><div class="sbg-target-pane"><label for="sbg-site">Сайт</label><select id="sbg-site"><option value="">Выберите сайт</option></select><div id="sbg-tree" class="sbg-tree"></div></div>
                    <div class="sbg-rule-settings"><h3 id="sbg-target-title">Выберите сайт, страницу или папку</h3><p id="sbg-target-note" class="sbg-muted"></p>
                        <fieldset id="sbg-flags" disabled><legend class="sbg-sr">Разрешения для выбранного объекта</legend>
                            <label class="sbg-cap"><input id="sbg-view" type="checkbox" checked disabled><span><strong>Просмотр</strong><small id="sbg-view-help">Чтение выбранной страницы</small></span></label>
                            <label class="sbg-cap"><input id="sbg-edit" type="checkbox"><span><strong>Редактирование</strong><small id="sbg-edit-help">Изменение содержимого страницы</small></span></label>
                            <label id="sbg-delete-cap" class="sbg-cap" hidden><input id="sbg-can-delete" type="checkbox"><span><strong>Удаление</strong><small>Удаление файлов и папок. Не включается вместе с редактированием.</small></span></label>
                            <label class="sbg-check"><input id="sbg-children" type="checkbox" checked> Применять к вложенным страницам / папкам</label>
                            <label id="sbg-open-page-wrap" class="sbg-check" hidden><input id="sbg-open-page" type="checkbox" checked> Открывать страницу с этим диском, без доступа к остальным страницам</label>
                            <button id="sbg-save-rule" class="sbg-primary" type="button">Сохранить право группы</button>
                        </fieldset>
                    </div></div>
                </details>
                <div class="sbg-section-header"><h2>Назначенные права</h2><span class="sbg-muted">Действуют для всех участников</span></div>
                <div class="sbg-table-wrap"><table class="sbg-table"><thead><tr><th>Объект</th><th>Разрешения</th><th>Наследование</th><th>Действия</th></tr></thead><tbody id="sbg-rules"></tbody></table></div>
                <p class="sbg-muted sbg-footnote">Для папок показываются только разрешённые ветки. Родительские папки могут открываться для перехода, но их файлы при этом недоступны. Перемещение требует удаления в исходной папке и изменения в целевой.</p>
            </section>
            <section id="sbg-panel-history" class="sbg-card sbg-panel" role="tabpanel" aria-labelledby="sbg-tab-history" hidden><h2>История изменений</h2><div id="sbg-history"></div><button id="sbg-more-history" class="sbg-button sbg-more" hidden>Ещё записи</button></section>
        </section>
    </main>
</div>
<script id="sbg-config" type="application/json"><?= json_encode(['basePath'=>$base,'sessid'=>bitrix_sessid()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= $h($base) ?>/assets/admin/groups.js?v=1" defer></script>
</body>
</html>
