<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';

global $APPLICATION, $USER;

sitebuilder_require_auth();

CJSCore::Init(['ajax']);

header('Content-Type: text/html; charset=UTF-8');

$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$canCreateSite = $USER->IsAdmin();
$isBitrixAdmin = $USER->IsAdmin();
$isSitebuilderGuest = sitebuilder_is_guest();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>SiteBuilder</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/dashboard.css">

    <style>
        .sb-role-badge {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 0 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .sb-role-badge--owner {
            background: #dcfce7;
            color: #166534;
        }

        .sb-role-badge--admin {
            background: #e0f2fe;
            color: #075985;
        }

        .sb-role-badge--editor {
            background: #eef2ff;
            color: #3730a3;
        }

        .sb-role-badge--viewer {
            background: #f3f4f6;
            color: #374151;
        }

        .sb-group-cell {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 150px;
        }

        .sb-group-cell__id {
            font-size: 12px;
            color: #6b7280;
        }

        .sb-actions-stack {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            min-width: 180px;
        }

        .sb-actions-stack .sb-btn {
            height: 30px;
            padding: 0 9px;
            font-size: 12px;
        }

        .sb-muted {
            color: #9ca3af;
            font-size: 12px;
        }

        .sb-user-site-list {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .sb-section-group {
            margin-bottom: 2px;
        }

        .sb-section-title {
            margin: 0 0 8px;
            font-size: 15px;
            font-weight: 800;
            color: #111827;
        }

        .sb-section-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .sb-user-site-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 14px;
            border: 1px solid #eef2f7;
            border-radius: 14px;
            background: #fff;
        }

        .sb-user-site-card:hover {
            border-color: #c7d2fe;
            background: #fcfcff;
        }

        .sb-user-site-link {
            color: #111827;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
        }

        .sb-user-site-link:hover {
            color: #2563eb;
            text-decoration: underline;
        }

        .sb-user-site-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .sb-user-sites-empty {
            padding: 18px;
            color: #6b7280;
        }

        .sb-site-section-select {
            min-width: 180px;
            height: 32px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            padding: 0 8px;
            background: #fff;
            font-size: 12px;
        }

        .sb-section-manager-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .sb-section-manager-item {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 90px auto;
            gap: 8px;
            align-items: center;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #f9fafb;
        }

        .sb-section-manager-actions {
            display: flex;
            gap: 6px;
        }

        .sb-modal[hidden] {
            display: none !important;
        }

        .sb-modal {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .sb-modal__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(4px);
        }

        .sb-modal__dialog {
            position: relative;
            width: min(520px, 100%);
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.28);
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        .sb-modal__head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 20px 22px;
            border-bottom: 1px solid #eef2f7;
            background: #f9fafb;
        }

        .sb-modal__title {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            color: #111827;
        }

        .sb-modal__subtitle {
            margin: 6px 0 0;
            font-size: 13px;
            color: #6b7280;
            line-height: 1.45;
        }

        .sb-modal__close {
            width: 34px;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
            cursor: pointer;
            font-size: 22px;
            line-height: 1;
            color: #6b7280;
        }

        .sb-modal__close:hover {
            background: #f3f4f6;
            color: #111827;
        }

        .sb-modal__body {
            padding: 22px;
        }

        .sb-modal__footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 22px;
            border-top: 1px solid #eef2f7;
            background: #f9fafb;
        }

        .sb-template-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 12px;
        }

        .sb-template-card {
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-height: 160px;
            padding: 14px;
            border: 1px solid #eef2f7;
            border-radius: 16px;
            background: #fff;
        }

        .sb-template-card:hover {
            border-color: #c7d2fe;
            background: #fcfcff;
        }

        .sb-template-card__title {
            margin: 0;
            color: #111827;
            font-size: 15px;
            font-weight: 800;
            line-height: 1.3;
        }

        .sb-template-card__description {
            margin: 0;
            color: #6b7280;
            font-size: 13px;
            line-height: 1.45;
        }

        .sb-template-card__meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: auto;
            color: #6b7280;
            font-size: 12px;
        }

        .sb-template-card__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .sb-template-empty {
            padding: 18px;
            border: 1px dashed #cbd5e1;
            border-radius: 16px;
            background: #fff;
            color: #6b7280;
            font-size: 13px;
        }

        @media (max-width: 700px) {
            .sb-section-manager-item {
                grid-template-columns: 1fr;
            }

            .sb-user-site-card {
                align-items: flex-start;
                flex-direction: column;
            }

            .sb-user-site-actions {
                width: 100%;
            }
        }
    </style>
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-dashboard">
        <header class="sb-dashboard-topbar">
            <div class="sb-dashboard-topbar__main">
                <h1 class="sb-dashboard-title">SiteBuilder</h1>
                <p class="sb-dashboard-subtitle">
                    Управление сайтами конструктора
                </p>
            </div>

            <div class="sb-dashboard-actions">
                <input class="sb-dashboard-search" type="text" id="dashboardSearch" placeholder="Поиск по сайтам">
                <button class="sb-btn sb-btn-light" type="button" id="reloadBtn">Обновить</button>

                <?php if ($isBitrixAdmin): ?>
                    <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/queue_health.php">Очередь</a>
                    <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/alerts.php">Оповещения</a>
                    <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/external_resources.php">Внешние ресурсы</a>
                    <a class="sb-btn sb-btn-light" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/deployment.php">Развёртывание</a>
                <?php endif; ?>

                <?php if ($canCreateSite): ?>
                    <button class="sb-btn sb-btn-light" type="button" id="createSectionBtn">Секции</button>
                    <button class="sb-btn sb-btn-light" type="button" id="reloadTemplatesBtn">Шаблоны</button>
                    <button class="sb-btn sb-btn-primary" type="button" id="createSiteQuickBtn">Создать сайт</button>
                <?php endif; ?>

                <?php if ($isSitebuilderGuest): ?>
                    <span class="sb-role-badge sb-role-badge--viewer">Гостевой режим</span>
                <?php endif; ?>

                <form method="post" action="<?= htmlspecialchars((string)sitebuilder_auth_config()['logout_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" style="display:inline-flex">
                    <?= bitrix_sessid_post() ?>
                    <button class="sb-btn sb-btn-light" type="submit">Выйти</button>
                </form>
            </div>
        </header>

        <section class="sb-dash-card">
            <div class="sb-dash-card__head">
                <h2>Список сайтов</h2>
                <div class="sb-dash-toolbar">
                    <span class="sb-badge" id="sitesCountBadge">0 сайтов</span>
                </div>
            </div>

            <?php if ($isBitrixAdmin): ?>
                <div class="sb-dash-table-wrap">
                    <table class="sb-dash-table">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Сайт</th>
                            <th>Секция</th>
                            <th>Роль</th>
                            <th>Slug</th>
                            <th>Домашняя</th>
                            <th>Группа Битрикс24</th>
                            <th>Диск</th>
                            <th>Изменен</th>
                            <th>Действия</th>
                        </tr>
                        </thead>

                        <tbody id="sitesTableBody">
                        <tr>
                            <td colspan="10">Загрузка...</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div id="sitesTableBody" class="sb-user-site-list">
                    <div class="sb-user-sites-empty">Загрузка...</div>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($isBitrixAdmin): ?>
            <section class="sb-dash-card" id="templatesCard">
                <div class="sb-dash-card__head">
                    <div>
                        <h2>Шаблоны сайтов</h2>
                        <div class="sb-muted">Создавать и удалять шаблоны может только администратор Битрикса</div>
                    </div>
                    <div class="sb-dash-toolbar">
                        <span class="sb-badge" id="templatesCountBadge">0 шаблонов</span>
                        <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="templatesReloadBtn">Обновить</button>
                    </div>
                </div>

                <div id="templatesList" class="sb-template-list">
                    <div class="sb-template-empty">Загрузка шаблонов...</div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($canCreateSite): ?>
            <div class="sb-modal" id="createSiteModal" hidden>
                <div class="sb-modal__backdrop" data-close-create-modal></div>

                <div class="sb-modal__dialog">
                    <div class="sb-modal__head">
                        <div>
                            <h2 class="sb-modal__title">Создать сайт</h2>
                            <p class="sb-modal__subtitle">
                                Новый сайт автоматически получит рабочую группу Битрикс24.
                            </p>
                        </div>

                        <button class="sb-modal__close" type="button" data-close-create-modal>×</button>
                    </div>

                    <div class="sb-modal__body">
                        <div class="sb-field">
                            <label for="siteName">Название сайта</label>
                            <input class="sb-input" type="text" id="siteName" placeholder="Например: Корпоративный портал">
                        </div>

                        <div class="sb-field" style="margin-top:12px;">
                            <label for="siteSlug">Slug</label>
                            <input class="sb-input" type="text" id="siteSlug" placeholder="Например: corp-portal">
                        </div>

                        <div class="sb-field" style="margin-top:12px;">
                            <label for="siteSectionId">Секция</label>
                            <select class="sb-select" id="siteSectionId">
                                <option value="0">Без секции</option>
                            </select>
                        </div>
                    </div>

                    <div class="sb-modal__footer">
                        <button class="sb-btn sb-btn-light" type="button" data-close-create-modal>Отмена</button>
                        <button class="sb-btn sb-btn-primary" type="button" id="createSiteBtn">Создать</button>
                    </div>
                </div>
            </div>

            <?php if ($isBitrixAdmin): ?>
                <div class="sb-modal" id="createFromTemplateModal" hidden>
                    <div class="sb-modal__backdrop" data-close-template-site-modal></div>

                    <div class="sb-modal__dialog">
                        <div class="sb-modal__head">
                            <div>
                                <h2 class="sb-modal__title">Создать сайт из шаблона</h2>
                                <p class="sb-modal__subtitle" id="templateSiteModalSubtitle">
                                    Выбери название, slug и секцию для нового сайта.
                                </p>
                            </div>

                            <button class="sb-modal__close" type="button" data-close-template-site-modal>×</button>
                        </div>

                        <div class="sb-modal__body">
                            <input type="hidden" id="templateSiteTemplateId" value="0">

                            <div class="sb-field">
                                <label for="templateSiteName">Название сайта</label>
                                <input class="sb-input" type="text" id="templateSiteName" placeholder="Например: Новый портал">
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="templateSiteSlug">Slug</label>
                                <input class="sb-input" type="text" id="templateSiteSlug" placeholder="Например: new-portal">
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="templateSiteSectionId">Секция</label>
                                <select class="sb-select" id="templateSiteSectionId">
                                    <option value="0">Без секции</option>
                                </select>
                            </div>
                        </div>

                        <div class="sb-modal__footer">
                            <button class="sb-btn sb-btn-light" type="button" data-close-template-site-modal>Отмена</button>
                            <button class="sb-btn sb-btn-primary" type="button" id="createFromTemplateBtn">Создать сайт</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="sb-modal" id="createSectionModal" hidden>
                <div class="sb-modal__backdrop" data-close-section-modal></div>

                <div class="sb-modal__dialog">
                    <div class="sb-modal__head">
                        <div>
                            <h2 class="sb-modal__title">Управление секциями</h2>
                            <p class="sb-modal__subtitle">
                                Создавай, переименовывай и удаляй секции сайтов.
                            </p>
                        </div>

                        <button class="sb-modal__close" type="button" data-close-section-modal>×</button>
                    </div>

                    <div class="sb-modal__body">
                        <div class="sb-field">
                            <label for="sectionName">Новая секция</label>
                            <input class="sb-input" type="text" id="sectionName" placeholder="Например: Информационные порталы">
                        </div>

                        <div class="sb-field" style="margin-top:12px;">
                            <label for="sectionSort">Сортировка</label>
                            <input class="sb-input" type="number" id="sectionSort" value="500">
                        </div>

                        <div class="sb-editor-inspector-actions" style="margin-top:12px;">
                            <button class="sb-btn sb-btn-primary" type="button" id="saveSectionBtn">Создать секцию</button>
                        </div>

                        <div style="border-top:1px solid #eef2f7; margin:18px 0;"></div>

                        <div>
                            <h3 style="margin:0 0 10px; font-size:15px;">Существующие секции</h3>
                            <div id="sectionsManagerList">
                                <div class="sb-muted">Загрузка секций...</div>
                            </div>
                        </div>
                    </div>

                    <div class="sb-modal__footer">
                        <button class="sb-btn sb-btn-light" type="button" data-close-section-modal>Закрыть</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isBitrixAdmin): ?>
            <section class="sb-dash-card">
                <div class="sb-dash-card__head">
                    <h2>Отладка</h2>
                </div>
                <div id="output" class="sb-output">Здесь будут ответы API...</div>
            </section>
        <?php else: ?>
            <div id="output" style="display:none;"></div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var BASE_PATH = '<?= CUtil::JSEscape($basePath) ?>';
    var API_URL = BASE_PATH + '/api.php';
    var CAN_CREATE_SITE = <?= $canCreateSite ? 'true' : 'false' ?>;
    var IS_BITRIX_ADMIN = <?= $isBitrixAdmin ? 'true' : 'false' ?>;

    var output = document.getElementById('output');
    var sitesTableBody = document.getElementById('sitesTableBody');
    var sitesCountBadge = document.getElementById('sitesCountBadge');
    var searchInput = document.getElementById('dashboardSearch');

    var allSites = [];
    var allSections = [];
    var allTemplates = [];

    function print(data) {
        if (!output) {
            return;
        }

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
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function publicSiteUrl(site) {
        var slug = String((site && site.slug) || '').trim();
        return slug
            ? BASE_PATH + '/s/' + encodeURIComponent(slug) + '/'
            : '#';
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

                if (res && res.ok === true) {
                    if (typeof onSuccess === 'function') {
                        onSuccess(res);
                    }
                    return;
                }

                if (typeof onFailure === 'function') {
                    onFailure(res || {error: 'UNKNOWN'});
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

    function siteStatus(site) {
        return Number(site.homePageId || 0) > 0 ? 'published' : 'draft';
    }

    function yesNoBadge(flag) {
        if (flag) {
            return '<span class="sb-status-badge success">Да</span>';
        }

        return '<span class="sb-status-badge warning">Нет</span>';
    }

    function roleRank(role) {
        role = String(role || '');

        if (role === 'OWNER') return 4;
        if (role === 'ADMIN') return 3;
        if (role === 'EDITOR') return 2;
        if (role === 'VIEWER') return 1;

        return 0;
    }

    function roleBadge(role) {
        role = String(role || 'VIEWER');

        var cls = 'sb-role-badge--viewer';

        if (role === 'OWNER') {
            cls = 'sb-role-badge--owner';
        } else if (role === 'ADMIN') {
            cls = 'sb-role-badge--admin';
        } else if (role === 'EDITOR') {
            cls = 'sb-role-badge--editor';
        }

        return '<span class="sb-role-badge ' + cls + '">' + escapeHtml(role) + '</span>';
    }

    function getBitrixGroupUrl(site) {
        var groupId = Number(site.bitrixGroupId || 0);

        if (site.bitrixGroupUrl) {
            return String(site.bitrixGroupUrl);
        }

        if (groupId > 0) {
            return '/workgroups/group/' + groupId + '/';
        }

        return '';
    }

    function renderGroupCell(site, userRoleRank) {
        var groupId = Number(site.bitrixGroupId || 0);
        var groupUrl = getBitrixGroupUrl(site);

        if (groupId > 0) {
            return ''
                + '<div class="sb-group-cell">'
                + '  <div class="sb-group-cell__id">ID группы: ' + groupId + '</div>'
                + '  <a class="sb-btn sb-btn-light sb-btn-small" href="' + escapeHtml(groupUrl) + '" target="_blank">Открыть группу</a>'
                + '</div>';
        }

        if (userRoleRank >= 4) {
            return ''
                + '<div class="sb-group-cell">'
                + '  <span class="sb-muted">Группа не создана</span>'
                + '  <button class="sb-btn sb-btn-primary sb-btn-small" type="button" data-action="ensure-group" data-site-id="' + Number(site.id || 0) + '">Создать группу</button>'
                + '</div>';
        }

        return '<span class="sb-muted">Нет группы</span>';
    }

    function renderActions(site, userRoleRank) {
        var siteId = Number(site.id || 0);
        var groupId = Number(site.bitrixGroupId || 0);

        var html = '<div class="sb-actions-stack">';

        html += '<a class="sb-btn sb-btn-light sb-btn-small" href="' + escapeHtml(publicSiteUrl(site)) + '" target="_blank">Публичная</a>';

        if (userRoleRank >= 3) {
            html += '<a class="sb-btn sb-btn-primary sb-btn-small" href="' + BASE_PATH + '/editor.php?siteId=' + siteId + '">Редактор</a>';
        }

        if (userRoleRank >= 4 && groupId > 0) {
            html += '<button class="sb-btn sb-btn-light sb-btn-small" type="button" data-action="sync-access" data-site-id="' + siteId + '">Синхр. права</button>';
        }

        if (userRoleRank >= 4) {
            html += '<button class="sb-btn sb-btn-danger sb-btn-small" type="button" data-action="delete-site" data-site-id="' + siteId + '" data-site-name="' + escapeHtml(site.name || '') + '">Удалить</button>';
        }

        html += '</div>';

        return html;
    }

    function getSectionName(sectionId) {
        sectionId = Number(sectionId || 0);

        if (sectionId <= 0) {
            return 'Без секции';
        }

        var section = allSections.find(function (s) {
            return Number(s.id || 0) === sectionId;
        });

        return section ? String(section.name || 'Без названия') : 'Без секции';
    }

    function renderSectionSelect(site) {
        var siteId = Number(site.id || 0);
        var currentSectionId = Number(site.sectionId || 0);

        var html = '<select class="sb-site-section-select" data-action="set-section" data-site-id="' + siteId + '">';
        html += '<option value="0">Без секции</option>';

        allSections.forEach(function (section) {
            var id = Number(section.id || 0);

            html += '<option value="' + id + '"' + (id === currentSectionId ? ' selected' : '') + '>'
                + escapeHtml(section.name || '')
                + '</option>';
        });

        html += '</select>';

        return html;
    }

    function loadSections(callback) {
        api('section.list', {}, function (res) {
            allSections = Array.isArray(res.sections) ? res.sections : [];

            if (typeof callback === 'function') {
                callback();
            }
        }, function () {
            allSections = [];

            if (typeof callback === 'function') {
                callback();
            }
        });
    }

    function fillCreateSiteSectionSelect() {
        var select = document.getElementById('siteSectionId');
        if (!select) return;

        var currentValue = String(select.value || '0');

        var html = '<option value="0">Без секции</option>';

        allSections.forEach(function (section) {
            html += '<option value="' + Number(section.id || 0) + '">'
                + escapeHtml(section.name || '')
                + '</option>';
        });

        select.innerHTML = html;

        if (select.querySelector('option[value="' + currentValue + '"]')) {
            select.value = currentValue;
        }
    }

    function renderSectionsManager() {
        var list = document.getElementById('sectionsManagerList');
        if (!list) return;

        if (!Array.isArray(allSections) || !allSections.length) {
            list.className = '';
            list.innerHTML = '<div class="sb-muted">Секций пока нет</div>';
            return;
        }

        list.className = 'sb-section-manager-list';

        list.innerHTML = allSections.map(function (section) {
            var id = Number(section.id || 0);

            return ''
                + '<div class="sb-section-manager-item" data-section-id="' + id + '">'
                + '  <input class="sb-input" type="text" data-section-name value="' + escapeHtml(section.name || '') + '">'
                + '  <input class="sb-input" type="number" data-section-sort value="' + Number(section.sort || 500) + '">'
                + '  <div class="sb-section-manager-actions">'
                + '      <button class="sb-btn sb-btn-primary sb-btn-small" type="button" data-action="update-section" data-section-id="' + id + '">Сохранить</button>'
                + '      <button class="sb-btn sb-btn-danger sb-btn-small" type="button" data-action="delete-section" data-section-id="' + id + '">Удалить</button>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    function reloadSectionsUi(callback) {
        loadSections(function () {
            fillCreateSiteSectionSelect();
            renderSectionsManager();

            if (typeof callback === 'function') {
                callback();
            }
        });
    }

    function groupSitesBySection(sites) {
        var groups = {};

        sites.forEach(function (site) {
            var sectionId = Number(site.sectionId || 0);
            var key = String(sectionId);

            if (!groups[key]) {
                groups[key] = {
                    sectionId: sectionId,
                    sectionName: getSectionName(sectionId),
                    sites: []
                };
            }

            groups[key].sites.push(site);
        });

        var result = Object.keys(groups).map(function (key) {
            return groups[key];
        });

        result.sort(function (a, b) {
            if (a.sectionId === 0 && b.sectionId !== 0) return 1;
            if (a.sectionId !== 0 && b.sectionId === 0) return -1;

            var sectionA = allSections.find(function (s) {
                return Number(s.id || 0) === a.sectionId;
            });

            var sectionB = allSections.find(function (s) {
                return Number(s.id || 0) === b.sectionId;
            });

            var sortA = sectionA ? Number(sectionA.sort || 500) : 999999;
            var sortB = sectionB ? Number(sectionB.sort || 500) : 999999;

            if (sortA !== sortB) {
                return sortA - sortB;
            }

            return String(a.sectionName).localeCompare(String(b.sectionName));
        });

        return result;
    }

    function renderSitesTable(sites) {
        var colspan = IS_BITRIX_ADMIN ? 10 : 2;

        if (!Array.isArray(sites) || !sites.length) {
            sitesTableBody.innerHTML = IS_BITRIX_ADMIN
                ? '<tr><td colspan="' + colspan + '">Сайтов пока нет</td></tr>'
                : '<div class="sb-user-sites-empty">Сайтов пока нет</div>';

            sitesCountBadge.textContent = '0 сайтов';
            return;
        }

        sitesCountBadge.textContent = sites.length + ' сайтов';

        var html = '';

        if (!IS_BITRIX_ADMIN) {
            var groups = groupSitesBySection(sites);

            groups.forEach(function (group) {
                html += ''
                    + '<div class="sb-section-group">'
                    + '  <h3 class="sb-section-title">' + escapeHtml(group.sectionName) + '</h3>'
                    + '  <div class="sb-section-list">';

                group.sites.forEach(function (s) {
                    var siteId = Number(s.id || 0);
                    var currentUserRole = String(s.currentUserRole || 'VIEWER');
                    var currentUserRoleRank = roleRank(currentUserRole);

                    html += ''
                        + '<div class="sb-user-site-card">'
                        + '  <a class="sb-user-site-link" href="' + escapeHtml(publicSiteUrl(s)) + '">'
                        +        escapeHtml(s.name || '')
                        + '  </a>'
                        + '  <div class="sb-user-site-actions">'
                        +        (currentUserRoleRank >= 3
                                    ? '<a class="sb-btn sb-btn-primary sb-btn-small" href="' + BASE_PATH + '/editor.php?siteId=' + siteId + '">Редактор</a>'
                                    : '')
                        + '  </div>'
                        + '</div>';
                });

                html += ''
                    + '  </div>'
                    + '</div>';
            });

            sitesTableBody.innerHTML = html;
            return;
        }

        for (var i = 0; i < sites.length; i++) {
            var s = sites[i];
            var siteId = Number(s.id || 0);
            var status = siteStatus(s);
            var statusClass = status === 'published' ? 'success' : 'warning';
            var currentUserRole = String(s.currentUserRole || 'VIEWER');
            var currentUserRoleRank = roleRank(currentUserRole);

            html += ''
                + '<tr>'
                + '  <td>' + siteId + '</td>'
                + '  <td>'
                + '    <div class="sb-site-cell">'
                + '      <div class="sb-site-cell__title">' + escapeHtml(s.name || '') + '</div>'
                + '      <div class="sb-site-cell__meta">created: ' + escapeHtml(s.createdAt || '—') + '</div>'
                + '    </div>'
                + '  </td>'
                + '  <td>' + renderSectionSelect(s) + '</td>'
                + '  <td>' + roleBadge(currentUserRole) + '</td>'
                + '  <td>' + escapeHtml(s.slug || '') + '</td>'
                + '  <td><span class="sb-status-badge ' + statusClass + '">' + escapeHtml(status) + '</span></td>'
                + '  <td>' + renderGroupCell(s, currentUserRoleRank) + '</td>'
                + '  <td>' + yesNoBadge(Number(s.diskFolderId || 0) > 0) + '</td>'
                + '  <td>' + escapeHtml(s.updatedAt || '—') + '</td>'
                + '  <td>' + renderActions(s, currentUserRoleRank) + '</td>'
                + '</tr>';
        }

        sitesTableBody.innerHTML = html;
    }

    function applySearch() {
        var query = String(searchInput.value || '').trim().toLowerCase();

        if (!query) {
            renderSitesTable(allSites);
            return;
        }

        var filtered = allSites.filter(function (site) {
            var sectionName = getSectionName(Number(site.sectionId || 0));

            var haystack = [
                site.name || '',
                site.slug || '',
                site.code || '',
                site.currentUserRole || '',
                sectionName || '',
                String(site.bitrixGroupId || '')
            ].join(' ').toLowerCase();

            return haystack.indexOf(query) !== -1;
        });

        renderSitesTable(filtered);
    }

    function loadTemplates() {
        if (!IS_BITRIX_ADMIN) {
            return;
        }

        var list = document.getElementById('templatesList');
        var badge = document.getElementById('templatesCountBadge');

        if (list) {
            list.innerHTML = '<div class="sb-template-empty">Загрузка шаблонов...</div>';
        }

        api('template.list', {}, function (res) {
            allTemplates = Array.isArray(res.templates) ? res.templates : [];
            renderTemplates();
        }, function (err) {
            if (list) {
                list.innerHTML = '<div class="sb-template-empty">Не удалось загрузить шаблоны</div>';
            }
            if (badge) {
                badge.textContent = 'Ошибка';
            }
        });
    }

    function renderTemplates() {
        var list = document.getElementById('templatesList');
        var badge = document.getElementById('templatesCountBadge');

        if (!list) {
            return;
        }

        if (badge) {
            badge.textContent = allTemplates.length + ' шаблонов';
        }

        if (!allTemplates.length) {
            list.innerHTML = '<div class="sb-template-empty">Шаблонов пока нет. Открой сайт в редакторе и нажми “Сохранить как шаблон”.</div>';
            return;
        }

        list.innerHTML = allTemplates.map(function (template) {
            var id = Number(template.id || 0);
            var name = String(template.name || 'Шаблон #' + id);
            var description = String(template.description || '');
            var pagesCount = Number(template.pagesCount || 0);
            var blocksCount = Number(template.blocksCount || 0);
            var sourceSiteName = String(template.sourceSiteName || '');
            var updatedAt = String(template.updatedAt || template.createdAt || '');

            return ''
                + '<div class="sb-template-card" data-template-id="' + id + '">'
                + '  <h3 class="sb-template-card__title">' + escapeHtml(name) + '</h3>'
                + '  <p class="sb-template-card__description">' + escapeHtml(description || 'Без описания') + '</p>'
                + '  <div class="sb-template-card__meta">'
                + '      <span class="sb-badge">' + pagesCount + ' стр.</span>'
                + '      <span class="sb-badge">' + blocksCount + ' блоков</span>'
                +        (sourceSiteName ? '<span class="sb-badge">Источник: ' + escapeHtml(sourceSiteName) + '</span>' : '')
                +        (updatedAt ? '<span class="sb-muted">' + escapeHtml(updatedAt) + '</span>' : '')
                + '  </div>'
                + '  <div class="sb-template-card__actions">'
                + '      <button class="sb-btn sb-btn-primary sb-btn-small" type="button" data-action="create-from-template" data-template-id="' + id + '">Создать сайт</button>'
                + '      <button class="sb-btn sb-btn-danger sb-btn-small" type="button" data-action="delete-template" data-template-id="' + id + '" data-template-name="' + escapeHtml(name) + '">Удалить</button>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    function findTemplate(templateId) {
        templateId = Number(templateId || 0);
        return allTemplates.find(function (template) {
            return Number(template.id || 0) === templateId;
        }) || null;
    }

    function fillTemplateSiteSectionSelect() {
        var select = document.getElementById('templateSiteSectionId');
        if (!select) return;

        var html = '<option value="0">Без секции</option>';
        allSections.forEach(function (section) {
            html += '<option value="' + Number(section.id || 0) + '">' + escapeHtml(section.name || '') + '</option>';
        });
        select.innerHTML = html;
    }

    function openCreateFromTemplateModal(templateId) {
        if (!IS_BITRIX_ADMIN) {
            return;
        }

        var template = findTemplate(templateId);
        if (!template) {
            alert('Шаблон не найден');
            return;
        }

        fillTemplateSiteSectionSelect();

        var modal = document.getElementById('createFromTemplateModal');
        var idInput = document.getElementById('templateSiteTemplateId');
        var nameInput = document.getElementById('templateSiteName');
        var slugInput = document.getElementById('templateSiteSlug');
        var sectionInput = document.getElementById('templateSiteSectionId');
        var subtitle = document.getElementById('templateSiteModalSubtitle');

        if (!modal || !idInput || !nameInput) {
            return;
        }

        idInput.value = String(templateId);
        nameInput.value = template.name || '';
        if (slugInput) slugInput.value = '';
        if (sectionInput) sectionInput.value = '0';
        if (subtitle) subtitle.textContent = 'Шаблон: ' + (template.name || ('#' + templateId));

        modal.hidden = false;

        setTimeout(function () {
            nameInput.focus();
            nameInput.select();
        }, 50);
    }

    function closeCreateFromTemplateModal() {
        var modal = document.getElementById('createFromTemplateModal');
        if (modal) {
            modal.hidden = true;
        }
    }

    function createSiteFromTemplate() {
        if (!IS_BITRIX_ADMIN) {
            return;
        }

        var idInput = document.getElementById('templateSiteTemplateId');
        var nameInput = document.getElementById('templateSiteName');
        var slugInput = document.getElementById('templateSiteSlug');
        var sectionInput = document.getElementById('templateSiteSectionId');

        var templateId = idInput ? Number(idInput.value || 0) : 0;
        var name = nameInput ? String(nameInput.value || '').trim() : '';
        var slug = slugInput ? String(slugInput.value || '').trim() : '';
        var sectionId = sectionInput ? Number(sectionInput.value || 0) : 0;

        if (templateId <= 0) {
            alert('Шаблон не выбран');
            return;
        }

        if (!name) {
            alert('Введите название сайта');
            if (nameInput) nameInput.focus();
            return;
        }

        api('template.createSite', {
            templateId: templateId,
            name: name,
            slug: slug,
            sectionId: sectionId
        }, function (res) {
            var site = res.site || {};
            closeCreateFromTemplateModal();
            alert(res.provisioningQueued
                ? 'Сайт создан из шаблона. Группа и папка Диска поставлены в очередь.'
                : 'Сайт создан из шаблона');
            loadSites();
            if (site.id) {
                window.location.href = BASE_PATH + '/editor.php?siteId=' + Number(site.id || 0);
            }
        }, function (err) {
            var message = err && (err.error || err.message) ? (err.error || err.message) : 'UNKNOWN_ERROR';
            alert('Не удалось создать сайт из шаблона: ' + message);
        });
    }

    function deleteTemplate(templateId, templateName) {
        templateId = Number(templateId || 0);
        if (templateId <= 0) return;

        var name = templateName || ('#' + templateId);
        if (!confirm('Удалить шаблон "' + name + '"?')) {
            return;
        }

        api('template.delete', {
            templateId: templateId
        }, function () {
            loadTemplates();
        }, function (err) {
            var message = err && (err.error || err.message) ? (err.error || err.message) : 'UNKNOWN_ERROR';
            alert('Не удалось удалить шаблон: ' + message);
        });
    }

    function loadSites() {
        loadSections(function () {
            api('site.list', {}, function (res) {
                if (!res || res.ok !== true) {
                    sitesTableBody.innerHTML = IS_BITRIX_ADMIN
                        ? '<tr><td colspan="10">Не удалось загрузить данные</td></tr>'
                        : '<div class="sb-user-sites-empty">Не удалось загрузить данные</div>';

                    sitesCountBadge.textContent = 'Ошибка загрузки';
                    return;
                }

                allSites = Array.isArray(res.sites) ? res.sites : [];
                applySearch();
            });
        });
    }

    function openCreateSiteModal() {
        if (!CAN_CREATE_SITE) {
            return;
        }

        var modal = document.getElementById('createSiteModal');
        if (!modal) {
            return;
        }

        fillCreateSiteSectionSelect();

        modal.hidden = false;

        setTimeout(function () {
            var nameInput = document.getElementById('siteName');
            if (nameInput) {
                nameInput.focus();
            }
        }, 50);
    }

    function closeCreateSiteModal() {
        var modal = document.getElementById('createSiteModal');
        if (!modal) {
            return;
        }

        modal.hidden = true;
    }

    function createSite() {
        if (!CAN_CREATE_SITE) {
            alert('Создавать сайты может только администратор Битрикс24');
            return;
        }

        var nameInput = document.getElementById('siteName');
        var slugInput = document.getElementById('siteSlug');
        var sectionInput = document.getElementById('siteSectionId');

        if (!nameInput || !slugInput) {
            alert('Форма создания сайта не найдена');
            return;
        }

        var name = (nameInput.value || '').trim();
        var slug = (slugInput.value || '').trim();
        var sectionId = sectionInput ? Number(sectionInput.value || 0) : 0;

        if (!name) {
            alert('Введите название сайта');
            nameInput.focus();
            return;
        }

        api('site.create', {
            name: name,
            slug: slug,
            sectionId: sectionId
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось создать сайт');
                return;
            }

            nameInput.value = '';
            slugInput.value = '';

            if (sectionInput) {
                sectionInput.value = '0';
            }

            closeCreateSiteModal();
            loadSites();
        }, function (err) {
            var message = err && (err.error || err.message) ? (err.error || err.message) : 'UNKNOWN_ERROR';
            alert('Не удалось создать сайт: ' + message);
        });
    }

    function openSectionModal() {
        if (!IS_BITRIX_ADMIN) {
            return;
        }

        var modal = document.getElementById('createSectionModal');
        if (!modal) {
            return;
        }

        reloadSectionsUi();

        modal.hidden = false;

        setTimeout(function () {
            var input = document.getElementById('sectionName');
            if (input) {
                input.focus();
            }
        }, 50);
    }

    function closeSectionModal() {
        var modal = document.getElementById('createSectionModal');
        if (!modal) {
            return;
        }

        modal.hidden = true;
    }

    function createSection() {
        var nameInput = document.getElementById('sectionName');
        var sortInput = document.getElementById('sectionSort');

        if (!nameInput) {
            return;
        }

        var name = String(nameInput.value || '').trim();
        var sort = Number(sortInput && sortInput.value ? sortInput.value : 500);

        if (!name) {
            alert('Введите название секции');
            nameInput.focus();
            return;
        }

        api('section.create', {
            name: name,
            sort: sort
        }, function () {
            nameInput.value = '';

            if (sortInput) {
                sortInput.value = '500';
            }

            reloadSectionsUi();
            loadSites();
        }, function (err) {
            var message = err && (err.error || err.message) ? (err.error || err.message) : 'UNKNOWN_ERROR';
            alert('Не удалось создать секцию: ' + message);
        });
    }

    function updateSection(sectionId) {
        sectionId = Number(sectionId || 0);
        if (sectionId <= 0) return;

        var row = document.querySelector('[data-section-id="' + sectionId + '"]');
        if (!row) return;

        var nameInput = row.querySelector('[data-section-name]');
        var sortInput = row.querySelector('[data-section-sort]');

        var name = nameInput ? String(nameInput.value || '').trim() : '';
        var sort = sortInput ? Number(sortInput.value || 500) : 500;

        if (!name) {
            alert('Введите название секции');
            if (nameInput) nameInput.focus();
            return;
        }

        api('section.update', {
            id: sectionId,
            name: name,
            sort: sort
        }, function () {
            reloadSectionsUi();
            loadSites();
        }, function (err) {
            var message = err && (err.error || err.message) ? (err.error || err.message) : 'UNKNOWN_ERROR';
            alert('Не удалось обновить секцию: ' + message);
        });
    }

    function deleteSection(sectionId) {
        sectionId = Number(sectionId || 0);
        if (sectionId <= 0) return;

        if (!confirm('Удалить секцию? Сайты из неё будут перенесены в «Без секции».')) {
            return;
        }

        var expectedVersions = {};
        allSites.forEach(function (site) {
            if (Number(site.sectionId || 0) === sectionId) {
                expectedVersions[Number(site.id || 0)] = Number(site.version || 1);
            }
        });

        api('section.delete', {
            id: sectionId,
            expectedVersions: JSON.stringify(expectedVersions)
        }, function () {
            reloadSectionsUi();
            loadSites();
        }, function (err) {
            var message = err && (err.error || err.message) ? (err.error || err.message) : 'UNKNOWN_ERROR';
            alert('Не удалось удалить секцию: ' + message);
        });
    }

    function currentSiteVersion(siteId) {
        siteId = Number(siteId || 0);
        var site = allSites.find(function (item) {
            return Number(item.id || 0) === siteId;
        });

        return Number((site && site.version) || 1);
    }

    function setSiteSection(siteId, sectionId) {
        api('site.setSection', {
            siteId: siteId,
            sectionId: sectionId,
            expectedVersion: currentSiteVersion(siteId)
        }, function () {
            loadSites();
        }, function (err) {
            var message = err && (err.error || err.message) ? (err.error || err.message) : 'UNKNOWN_ERROR';
            alert('Не удалось назначить секцию: ' + message);
            loadSites();
        });
    }

    function syncAccess(siteId) {
        api('site.syncAccess', {
            siteId: siteId
        }, function (res) {
            alert(
                res.queued
                    ? 'Синхронизация прав поставлена в очередь. Статус можно посмотреть в разделе «Задания».'
                    : 'Синхронизация завершена.'
            );

            loadSites();
        }, function (err) {
            var message = err && (err.error || err.message) ? (err.error || err.message) : 'UNKNOWN_ERROR';
            alert('Не удалось синхронизировать права: ' + message);
        });
    }

    function ensureGroup(siteId) {
        api('site.ensureGroup', {
            siteId: siteId,
            expectedVersion: currentSiteVersion(siteId)
        }, function (res) {
            alert(
                res.queued
                    ? 'Создание группы поставлено в очередь. Статус можно посмотреть в разделе «Задания».'
                    : 'Группа уже создана. ID: ' + Number(res.bitrixGroupId || 0)
            );

            loadSites();
        }, function (err) {
            var message = err && (err.error || err.message) ? (err.error || err.message) : 'UNKNOWN_ERROR';
            alert('Не удалось создать группу: ' + message);
        });
    }

    function deleteSite(siteId, siteName) {
        var name = siteName || ('siteId ' + siteId);

        var firstConfirm = confirm(
            'Удалить сайт "' + name + '"?\n\n' +
            'Будут удалены страницы, блоки, меню, доступы, шаблоны и layout внутри SiteBuilder.\n' +
            'Рабочая группа Битрикс24 и папка сайта в Диске будут поставлены в безопасную фоновую очистку.'
        );

        if (!firstConfirm) {
            return;
        }

        var secondConfirm = confirm(
            'Подтверди удаление ещё раз.\n\n' +
            'Это действие нельзя будет отменить через интерфейс SiteBuilder.'
        );

        if (!secondConfirm) {
            return;
        }

        api('site.delete', {
            id: siteId
        }, function (res) {
            var jobs = res && res.cleanupJobs ? Object.keys(res.cleanupJobs).length : 0;
            alert('Сайт удалён' + (jobs ? '. Заданий очистки: ' + jobs : ''));
            loadSites();
        }, function (err) {
            var message = err && (err.error || err.message) ? (err.error || err.message) : 'UNKNOWN_ERROR';
            alert('Не удалось удалить сайт: ' + message);
        });
    }

    var templatesList = document.getElementById('templatesList');
    if (templatesList) {
        templatesList.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;

            var action = btn.getAttribute('data-action');
            var templateId = Number(btn.getAttribute('data-template-id') || 0);

            if (action === 'create-from-template') {
                openCreateFromTemplateModal(templateId);
                return;
            }

            if (action === 'delete-template') {
                deleteTemplate(templateId, btn.getAttribute('data-template-name') || '');
            }
        });
    }

    sitesTableBody.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) {
            return;
        }

        var action = btn.getAttribute('data-action');
        var siteId = Number(btn.getAttribute('data-site-id') || 0);

        if (siteId > 0) {
            if (action === 'sync-access') {
                syncAccess(siteId);
                return;
            }

            if (action === 'ensure-group') {
                ensureGroup(siteId);
                return;
            }

            if (action === 'delete-site') {
                deleteSite(siteId, btn.getAttribute('data-site-name') || '');
                return;
            }
        }
    });

    sitesTableBody.addEventListener('change', function (e) {
        var select = e.target.closest('[data-action="set-section"]');
        if (!select) {
            return;
        }

        var siteId = Number(select.getAttribute('data-site-id') || 0);
        var sectionId = Number(select.value || 0);

        if (siteId <= 0) {
            return;
        }

        setSiteSection(siteId, sectionId);
    });

    var sectionsManagerList = document.getElementById('sectionsManagerList');
    if (sectionsManagerList) {
        sectionsManagerList.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;

            var action = btn.getAttribute('data-action');
            var sectionId = Number(btn.getAttribute('data-section-id') || 0);

            if (action === 'update-section') {
                updateSection(sectionId);
                return;
            }

            if (action === 'delete-section') {
                deleteSection(sectionId);
            }
        });
    }

    var createSiteBtn = document.getElementById('createSiteBtn');
    if (createSiteBtn) {
        createSiteBtn.addEventListener('click', createSite);
    }

    var createSiteQuickBtn = document.getElementById('createSiteQuickBtn');
    if (createSiteQuickBtn) {
        createSiteQuickBtn.addEventListener('click', openCreateSiteModal);
    }

    var createSectionBtn = document.getElementById('createSectionBtn');
    if (createSectionBtn) {
        createSectionBtn.addEventListener('click', openSectionModal);
    }

    var reloadTemplatesBtn = document.getElementById('reloadTemplatesBtn');
    if (reloadTemplatesBtn) {
        reloadTemplatesBtn.addEventListener('click', loadTemplates);
    }

    var templatesReloadBtn = document.getElementById('templatesReloadBtn');
    if (templatesReloadBtn) {
        templatesReloadBtn.addEventListener('click', loadTemplates);
    }

    var createFromTemplateBtn = document.getElementById('createFromTemplateBtn');
    if (createFromTemplateBtn) {
        createFromTemplateBtn.addEventListener('click', createSiteFromTemplate);
    }

    var saveSectionBtn = document.getElementById('saveSectionBtn');
    if (saveSectionBtn) {
        saveSectionBtn.addEventListener('click', createSection);
    }

    document.querySelectorAll('[data-close-create-modal]').forEach(function (btn) {
        btn.addEventListener('click', closeCreateSiteModal);
    });

    document.querySelectorAll('[data-close-section-modal]').forEach(function (btn) {
        btn.addEventListener('click', closeSectionModal);
    });

    document.querySelectorAll('[data-close-template-site-modal]').forEach(function (btn) {
        btn.addEventListener('click', closeCreateFromTemplateModal);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeCreateSiteModal();
            closeSectionModal();
            closeCreateFromTemplateModal();
        }
    });

    document.getElementById('reloadBtn').addEventListener('click', loadSites);
    searchInput.addEventListener('input', applySearch);

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

    loadSites();
    loadTemplates();
})();
</script>
</body>
</html>
