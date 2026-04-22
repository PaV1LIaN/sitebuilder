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
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>SiteBuilder Dashboard</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/dashboard.css">
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-dashboard">
        <header class="sb-dashboard-topbar">
            <div class="sb-dashboard-topbar__main">
                <h1 class="sb-dashboard-title">Dashboard сайтов</h1>
                <p class="sb-dashboard-subtitle">
                    Сводка по всем сайтам конструктора и активности пользователей
                </p>
            </div>

            <div class="sb-dashboard-actions">
                <input class="sb-dashboard-search" type="text" id="dashboardSearch" placeholder="Поиск по сайтам">
                <select class="sb-dashboard-filter" id="dashboardPeriod">
                    <option value="7">7 дней</option>
                    <option value="30" selected>30 дней</option>
                    <option value="90">90 дней</option>
                </select>
                <button class="sb-btn sb-btn-light" type="button" id="exportBtn">Экспорт</button>
                <button class="sb-btn sb-btn-primary" type="button" id="createSiteQuickBtn">Создать сайт</button>
            </div>
        </header>

        <section class="sb-dashboard-kpis">
            <article class="sb-kpi-card">
                <div class="sb-kpi-card__label">Всего сайтов</div>
                <div class="sb-kpi-card__value" id="kpiSitesTotal">0</div>
                <div class="sb-kpi-card__meta">Все сайты в системе</div>
            </article>

            <article class="sb-kpi-card">
                <div class="sb-kpi-card__label">Опубликованные</div>
                <div class="sb-kpi-card__value" id="kpiPublished">0</div>
                <div class="sb-kpi-card__meta success">Активные сайты</div>
            </article>

            <article class="sb-kpi-card">
                <div class="sb-kpi-card__label">Черновики</div>
                <div class="sb-kpi-card__value" id="kpiDrafts">0</div>
                <div class="sb-kpi-card__meta warning">Требуют публикации</div>
            </article>

            <article class="sb-kpi-card">
                <div class="sb-kpi-card__label">Всего посещений</div>
                <div class="sb-kpi-card__value" id="kpiVisits">0</div>
                <div class="sb-kpi-card__meta">Суммарно по системе</div>
            </article>

            <article class="sb-kpi-card">
                <div class="sb-kpi-card__label">Уникальные посетители</div>
                <div class="sb-kpi-card__value" id="kpiUnique">0</div>
                <div class="sb-kpi-card__meta">За выбранный период</div>
            </article>

            <article class="sb-kpi-card">
                <div class="sb-kpi-card__label">Средняя сессия</div>
                <div class="sb-kpi-card__value" id="kpiAvgSession">0м</div>
                <div class="sb-kpi-card__meta">Среднее по сайтам</div>
            </article>
        </section>

        <section class="sb-dashboard-grid sb-dashboard-grid--analytics">
            <article class="sb-dash-card">
                <div class="sb-dash-card__head">
                    <h2>Динамика посещаемости</h2>
                    <div class="sb-dash-toolbar">
                        <button class="sb-chip active" type="button">Посещения</button>
                        <button class="sb-chip" type="button">Уникальные</button>
                    </div>
                </div>
                <div class="sb-chart-placeholder">
                    Здесь будет график посещаемости
                </div>
            </article>

            <article class="sb-dash-card">
                <div class="sb-dash-card__head">
                    <h2>Популярные сайты</h2>
                </div>
                <div class="sb-rank-list" id="popularSitesBlock">
                    <div class="sb-rank-item">
                        <div>
                            <div class="sb-rank-item__title">Нет данных</div>
                            <div class="sb-rank-item__meta">Список появится после загрузки</div>
                        </div>
                        <div class="sb-rank-item__value">—</div>
                    </div>
                </div>
            </article>
        </section>

        <section class="sb-dash-card">
            <div class="sb-dash-card__head">
                <h2>Сайты со статистикой</h2>
                <div class="sb-dash-toolbar">
                    <button class="sb-chip active" type="button">Все</button>
                    <button class="sb-chip" type="button">Published</button>
                    <button class="sb-chip" type="button">Draft</button>
                    <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="reloadBtn">Обновить список</button>
                </div>
            </div>

            <div class="sb-dash-table-wrap">
                <table class="sb-dash-table">
                    <thead>
                    <tr>
                        <th>Сайт</th>
                        <th>Статус</th>
                        <th>Пользователи</th>
                        <th>Посещения</th>
                        <th>Уникальные</th>
                        <th>Средняя длительность</th>
                        <th>Активность</th>
                        <th>Последнее изменение</th>
                        <th>Действия</th>
                    </tr>
                    </thead>
                    <tbody id="sitesStatsTableBody">
                    <tr>
                        <td colspan="9">Загрузка...</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sb-dashboard-grid sb-dashboard-grid--bottom">
            <article class="sb-dash-card">
                <div class="sb-dash-card__head">
                    <h2>Низкая активность</h2>
                </div>
                <div class="sb-rank-list" id="lowActivityBlock">
                    <div class="sb-rank-item">
                        <div>
                            <div class="sb-rank-item__title">Нет данных</div>
                            <div class="sb-rank-item__meta">Список появится после загрузки</div>
                        </div>
                        <span class="sb-status-badge warning">—</span>
                    </div>
                </div>
            </article>

            <article class="sb-dash-card">
                <div class="sb-dash-card__head">
                    <h2>Последние действия пользователей</h2>
                </div>
                <div class="sb-activity-list" id="lastActionsBlock">
                    <div class="sb-activity-item">
                        <div>
                            <div class="sb-activity-item__title">Нет данных</div>
                            <div class="sb-activity-item__meta">Журнал действий пока пуст</div>
                        </div>
                    </div>
                </div>
            </article>

            <article class="sb-dash-card">
                <div class="sb-dash-card__head">
                    <h2>Сводная активность по системе</h2>
                </div>
                <div class="sb-system-stats">
                    <div class="sb-system-stat">
                        <span>Редактирования страниц</span>
                        <strong id="sysEdits">0</strong>
                    </div>
                    <div class="sb-system-stat">
                        <span>Публикации</span>
                        <strong id="sysPublishes">0</strong>
                    </div>
                    <div class="sb-system-stat">
                        <span>Загрузки файлов</span>
                        <strong id="sysUploads">0</strong>
                    </div>
                    <div class="sb-system-stat">
                        <span>Входы в админку</span>
                        <strong id="sysLogins">0</strong>
                    </div>
                </div>
            </article>
        </section>

        <section class="sb-dash-card">
            <div class="sb-dash-card__head">
                <h2>Создать сайт</h2>
            </div>
            <div class="sb-form-row align-end">
                <div class="sb-field">
                    <label for="siteName">Название сайта</label>
                    <input class="sb-input" type="text" id="siteName" placeholder="Например: Корпоративный портал">
                </div>
                <div class="sb-field">
                    <label for="siteSlug">Slug</label>
                    <input class="sb-input" type="text" id="siteSlug" placeholder="Например: corp-portal">
                </div>
                <button type="button" class="sb-btn sb-btn-primary" id="createSiteBtn">Создать</button>
            </div>
        </section>

        <section class="sb-dash-card">
            <div class="sb-dash-card__head">
                <h2>Отладка</h2>
            </div>
            <div id="output" class="sb-output">Здесь будут ответы API...</div>
        </section>
    </div>
</div>

<script>
(function () {
    var BASE_PATH = '<?= CUtil::JSEscape($basePath) ?>';
    var API_URL = BASE_PATH + '/api.php';

    var output = document.getElementById('output');
    var sitesStatsTableBody = document.getElementById('sitesStatsTableBody');
    var popularSitesBlock = document.getElementById('popularSitesBlock');
    var lowActivityBlock = document.getElementById('lowActivityBlock');
    var lastActionsBlock = document.getElementById('lastActionsBlock');

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
                if (typeof onSuccess === 'function') onSuccess(res);
            },
            onfailure: function (err) {
                print({
                    ok: false,
                    error: 'AJAX_ERROR',
                    detail: err
                });
                if (typeof onFailure === 'function') onFailure(err);
            }
        });
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = String(value);
        }
    }

    function formatDuration(minutes) {
        minutes = Number(minutes || 0);
        if (minutes <= 0) return '0м';
        var h = Math.floor(minutes / 60);
        var m = minutes % 60;
        if (h > 0) {
            return h + 'ч ' + m + 'м';
        }
        return m + 'м';
    }

    function buildFakeSiteStats(site, index) {
        var published = !!site.homePageId;
        var users = 40 + index * 17;
        var visits = 300 + index * 190;
        var unique = Math.max(20, Math.round(visits * 0.38));
        var avgMinutes = 3 + index * 2;
        var trend = index % 2 === 0 ? '+' + (5 + index * 3) + '%' : '-' + (2 + index) + '%';

        return {
            id: Number(site.id || 0),
            name: site.name || '',
            slug: site.slug || '',
            status: published ? 'published' : 'draft',
            users: users,
            visits: visits,
            unique: unique,
            avgMinutes: avgMinutes,
            trend: trend,
            updatedAt: site.updatedAt || site.createdAt || '—'
        };
    }

    function renderKpis(stats) {
        setText('kpiSitesTotal', stats.totalSites);
        setText('kpiPublished', stats.publishedSites);
        setText('kpiDrafts', stats.draftSites);
        setText('kpiVisits', stats.totalVisits);
        setText('kpiUnique', stats.totalUnique);
        setText('kpiAvgSession', formatDuration(stats.avgSession));
    }

    function renderSitesTable(siteStats) {
        if (!siteStats.length) {
            sitesStatsTableBody.innerHTML = '<tr><td colspan="9">Сайтов пока нет</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < siteStats.length; i++) {
            var s = siteStats[i];
            var statusClass = s.status === 'published' ? 'success' : 'warning';
            var trendClass = String(s.trend).indexOf('-') === 0 ? 'down' : 'up';

            html += ''
                + '<tr>'
                + '  <td>'
                + '    <div class="sb-site-cell">'
                + '      <div class="sb-site-cell__title">' + escapeHtml(s.name) + '</div>'
                + '      <div class="sb-site-cell__meta">' + escapeHtml(s.slug) + '</div>'
                + '    </div>'
                + '  </td>'
                + '  <td><span class="sb-status-badge ' + statusClass + '">' + escapeHtml(s.status) + '</span></td>'
                + '  <td>' + s.users + '</td>'
                + '  <td>' + s.visits + '</td>'
                + '  <td>' + s.unique + '</td>'
                + '  <td>' + formatDuration(s.avgMinutes) + '</td>'
                + '  <td><span class="sb-trend ' + trendClass + '">' + escapeHtml(s.trend) + '</span></td>'
                + '  <td>' + escapeHtml(s.updatedAt) + '</td>'
                + '  <td>'
                + '    <a class="sb-btn sb-btn-light sb-btn-small" href="' + BASE_PATH + '/editor.php?siteId=' + s.id + '">Открыть</a>'
                + '  </td>'
                + '</tr>';
        }

        sitesStatsTableBody.innerHTML = html;
    }

    function renderPopular(siteStats) {
        if (!siteStats.length) {
            popularSitesBlock.innerHTML = '<div class="sb-rank-item"><div><div class="sb-rank-item__title">Нет данных</div></div><div class="sb-rank-item__value">—</div></div>';
            return;
        }

        var sorted = siteStats.slice().sort(function (a, b) {
            return b.visits - a.visits;
        }).slice(0, 5);

        var html = '';
        for (var i = 0; i < sorted.length; i++) {
            html += ''
                + '<div class="sb-rank-item">'
                + '  <div>'
                + '    <div class="sb-rank-item__title">' + escapeHtml(sorted[i].name) + '</div>'
                + '    <div class="sb-rank-item__meta">' + sorted[i].visits + ' посещений</div>'
                + '  </div>'
                + '  <div class="sb-rank-item__value">' + escapeHtml(sorted[i].trend) + '</div>'
                + '</div>';
        }
        popularSitesBlock.innerHTML = html;
    }

    function renderLowActivity(siteStats) {
        if (!siteStats.length) {
            lowActivityBlock.innerHTML = '<div class="sb-rank-item"><div><div class="sb-rank-item__title">Нет данных</div></div><span class="sb-status-badge warning">—</span></div>';
            return;
        }

        var sorted = siteStats.slice().sort(function (a, b) {
            return a.visits - b.visits;
        }).slice(0, 5);

        var html = '';
        for (var i = 0; i < sorted.length; i++) {
            html += ''
                + '<div class="sb-rank-item">'
                + '  <div>'
                + '    <div class="sb-rank-item__title">' + escapeHtml(sorted[i].name) + '</div>'
                + '    <div class="sb-rank-item__meta">' + sorted[i].visits + ' посещений</div>'
                + '  </div>'
                + '  <span class="sb-status-badge warning">Низкая активность</span>'
                + '</div>';
        }
        lowActivityBlock.innerHTML = html;
    }

    function renderLastActions(siteStats) {
        if (!siteStats.length) {
            lastActionsBlock.innerHTML = '<div class="sb-activity-item"><div><div class="sb-activity-item__title">Нет данных</div></div></div>';
            return;
        }

        var html = '';
        for (var i = 0; i < Math.min(siteStats.length, 5); i++) {
            html += ''
                + '<div class="sb-activity-item">'
                + '  <div>'
                + '    <div class="sb-activity-item__title">admin обновил сайт "' + escapeHtml(siteStats[i].name) + '"</div>'
                + '    <div class="sb-activity-item__meta">' + escapeHtml(siteStats[i].updatedAt) + '</div>'
                + '  </div>'
                + '</div>';
        }
        lastActionsBlock.innerHTML = html;
    }

    function renderSystemStats(siteStats) {
        var total = siteStats.length;
        setText('sysEdits', total * 7);
        setText('sysPublishes', total * 2);
        setText('sysUploads', total * 5);
        setText('sysLogins', total * 13);
    }

    function buildDashboard(sites) {
        var siteStats = [];
        for (var i = 0; i < sites.length; i++) {
            siteStats.push(buildFakeSiteStats(sites[i], i + 1));
        }

        var totalSites = siteStats.length;
        var publishedSites = 0;
        var draftSites = 0;
        var totalVisits = 0;
        var totalUnique = 0;
        var totalAvg = 0;

        for (var j = 0; j < siteStats.length; j++) {
            if (siteStats[j].status === 'published') {
                publishedSites++;
            } else {
                draftSites++;
            }
            totalVisits += siteStats[j].visits;
            totalUnique += siteStats[j].unique;
            totalAvg += siteStats[j].avgMinutes;
        }

        var avgSession = totalSites ? Math.round(totalAvg / totalSites) : 0;

        renderKpis({
            totalSites: totalSites,
            publishedSites: publishedSites,
            draftSites: draftSites,
            totalVisits: totalVisits,
            totalUnique: totalUnique,
            avgSession: avgSession
        });

        renderSitesTable(siteStats);
        renderPopular(siteStats);
        renderLowActivity(siteStats);
        renderLastActions(siteStats);
        renderSystemStats(siteStats);
    }

    function loadDashboard() {
        api('site.list', {}, function (res) {
            if (!res || res.ok !== true) {
                sitesStatsTableBody.innerHTML = '<tr><td colspan="9">Не удалось загрузить данные</td></tr>';
                return;
            }

            buildDashboard(Array.isArray(res.sites) ? res.sites : []);
        });
    }

    function createSite() {
        var nameInput = document.getElementById('siteName');
        var slugInput = document.getElementById('siteSlug');

        var name = (nameInput.value || '').trim();
        var slug = (slugInput.value || '').trim();

        if (!name) {
            alert('Введите название сайта');
            nameInput.focus();
            return;
        }

        api('site.create', {
            name: name,
            slug: slug
        }, function (res) {
            if (!res || res.ok !== true) {
                alert('Не удалось создать сайт');
                return;
            }

            nameInput.value = '';
            slugInput.value = '';
            loadDashboard();
        });
    }

    document.getElementById('createSiteBtn').addEventListener('click', createSite);
    document.getElementById('createSiteQuickBtn').addEventListener('click', function () {
        document.getElementById('siteName').focus();
        window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    });
    document.getElementById('reloadBtn').addEventListener('click', loadDashboard);
    document.getElementById('exportBtn').addEventListener('click', function () {
        alert('Экспорт можно подключить позже');
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

    loadDashboard();
})();
</script>
</body>
</html>