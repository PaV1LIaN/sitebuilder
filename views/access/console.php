<?php if (!defined('SB_ACCESS_CONSOLE_VIEW')) { http_response_code(404); exit; } ?>
<div class="sbg-app" id="uc-app">
<aside class="sbg-sidebar">
    <?php require __DIR__ . '/navigation.php'; ?>
    <?php if ($view === 'employees'): ?>
    <div class="sbg-sidebar-label">НАЙТИ СОТРУДНИКА</div>
    <label class="sbg-search-label"><span class="sbg-sr">ФИО, логин, почта или ID</span><input id="uc-person-search" placeholder="ФИО, логин, почта или ID" autocomplete="off"></label>
    <div id="uc-person-results" class="sbg-group-list" aria-live="polite"></div>
    <?php else: ?>
    <div class="sbg-sidebar-label">САЙТЫ, СТРАНИЦЫ И ДИСКИ</div>
    <label class="sbg-search-label"><span class="sbg-sr">Фильтр сайтов</span><input id="uc-site-filter" placeholder="Найти сайт…" autocomplete="off"></label>
    <div id="uc-tree" class="uc-tree uc-tree-dark" aria-label="Выбрать объект">Загрузка…</div>
    <?php endif; ?>
    <p class="sbg-sidebar-foot">Выдача через группы.<br>Старые назначения пока сохраняются.</p>
</aside>
<main class="sbg-main">
    <header class="sbg-top"><div><span class="sbg-eyebrow">ЕДИНАЯ СИСТЕМА ДОСТУПА</span><h1><?= $view==='employees'?'Сотрудники':'Объекты' ?></h1></div><button id="uc-reload" class="sbg-button">Обновить</button></header>
    <div id="uc-message" class="sbg-message" role="status" aria-live="polite" hidden></div>
    <div id="uc-retry-panel" class="sbg-card sbg-retry" hidden><span>Результат записи не подтверждён. Повтор использует тот же запрос.</span><button id="uc-retry" class="sbg-primary">Повторить запрос</button></div>
    <div class="sbg-note"><strong>Новые назначения — через группы.</strong> Существующие личные права и запреты действуют до отдельного переноса. Удаление одного источника не отменяет разрешения остальных.</div>
    <section id="uc-empty" class="sbg-card sbg-empty"><h2><?= $view==='employees'?'Выберите сотрудника':'Выберите объект слева' ?></h2><p><?= $view==='employees'?'Участие в группах и объяснение прав на выбранной странице или папке.':'Кому выдан доступ, откуда он унаследован и какие старые назначения остаются.' ?></p></section>
    <section id="uc-work" hidden>
        <div class="sbg-card uc-heading"><h2 id="uc-title"></h2><p id="uc-subtitle" class="sbg-muted"></p><a id="uc-context-link" class="uc-link" hidden></a></div>
        <?php if ($view === 'objects'): ?>
        <section class="sbg-card sbg-panel uc-spaced">
            <div class="sbg-section-header"><h2>Группы с назначениями</h2><button id="uc-open-rule" class="sbg-primary">Назначить группе</button></div>
            <p class="sbg-muted">Прямые и унаследованные разрешения. Итог для конкретного человека проверяется ниже — с учётом его личных ограничений.</p>
            <div id="uc-object-rules" class="uc-cards"></div><button id="uc-more-rules" class="sbg-button sbg-more" hidden>Ещё 100 правил</button>
            <details id="uc-rule-editor" class="uc-details"><summary>Назначение группы</summary>
                <p id="uc-edit-target" class="sbg-person"></p>
                <label class="uc-field">Группа<input id="uc-group-search" placeholder="Найти группу…" autocomplete="off"></label><div id="uc-group-results" class="uc-results"></div>
                <p id="uc-chosen-group" class="sbg-person"></p>
                <fieldset id="uc-flags" disabled>
                    <label class="sbg-cap"><input type="checkbox" checked disabled><span><strong>Просмотр</strong></span></label>
                    <label class="sbg-cap"><input id="uc-edit" type="checkbox"><span><strong>Редактирование</strong><small>Для Диска — загрузка и изменение файлов, без удаления.</small></span></label>
                    <label id="uc-delete-wrap" class="sbg-cap" hidden><input id="uc-delete" type="checkbox"><span><strong>Удаление</strong></span></label>
                    <label class="sbg-check"><input id="uc-children" type="checkbox" checked> Распространять на вложенные объекты</label>
                    <label id="uc-open-page-wrap" class="sbg-check" hidden><input id="uc-open-page" type="checkbox" checked> Открывать страницу с этим Диском</label>
                </fieldset>
                <div class="sbg-actions"><button id="uc-save-rule" class="sbg-primary" disabled>Сохранить назначение</button><button id="uc-cancel-rule" class="sbg-button">Отменить изменения</button></div>
            </details>
        </section>
        <?php else: ?>
        <section class="sbg-card sbg-panel uc-spaced"><h2>Участие в группах</h2>
            <p class="sbg-muted">Исключение из группы снимает её права на всех объектах, а не только на странице, которую вы проверяете.</p>
            <div id="uc-memberships" class="uc-cards"></div><button id="uc-more-memberships" class="sbg-button sbg-more" hidden>Ещё 100 групп</button>
            <details class="uc-details"><summary>Добавить сотрудника в группу</summary><label class="uc-field">Название группы<input id="uc-member-group-search" placeholder="Найти группу…" autocomplete="off"></label><div id="uc-member-group-results" class="uc-results"></div></details>
        </section>
        <?php endif; ?>
        <section class="sbg-card sbg-panel uc-spaced"><h2>Проверить итоговый доступ</h2>
            <?php if ($view==='objects'): ?>
            <label class="uc-field">Сотрудник<input id="uc-explain-search" placeholder="ФИО, логин, почта или ID" autocomplete="off"></label><div id="uc-explain-results" class="uc-results"></div>
            <?php else: ?>
            <details class="uc-details" id="uc-object-picker"><summary>Выбрать страницу или папку</summary><label class="uc-field">Сайт<select id="uc-inspect-site"><option value="">Выберите сайт</option></select></label><div id="uc-tree" class="uc-tree"></div></details>
            <?php endif; ?>
            <p id="uc-explain-title" class="sbg-person">Выберите <?= $view==='objects'?'сотрудника':'объект' ?> для проверки.</p>
            <div id="uc-explanation" aria-live="polite"></div>
        </section>
        <details class="sbg-card sbg-panel uc-spaced uc-details"><summary id="uc-legacy-title">Существующие личные назначения — переходный слой</summary>
            <p class="sbg-muted">Это сохранённые записи, а не полный итоговый доступ. Они не удаляются при изменении групп. Штатные права Диска проверяются на конкретном объекте.</p>
            <div id="uc-legacy" class="uc-cards"></div><button id="uc-more-legacy" class="sbg-button sbg-more" hidden>Ещё 100 назначений</button>
            <p class="sbg-muted">Для обслуживания уже существующих исключений: <a class="uc-link" href="<?= $h($base) ?>/access_legacy.php">переходный редактор</a>. Новые назначения создавайте через группы.</p>
        </details>
    </section>
</main>
</div>
