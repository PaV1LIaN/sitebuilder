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

$libFiles = [
    __DIR__ . '/lib/db.php',
    __DIR__ . '/lib/json.php',
    __DIR__ . '/lib/storage_db.php',
    __DIR__ . '/lib/response.php',
    __DIR__ . '/lib/helpers.php',
    __DIR__ . '/lib/access.php',
];

foreach ($libFiles as $libFile) {
    if (file_exists($libFile)) {
        require_once $libFile;
    }
}

if ($siteId <= 0) {
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title>SiteBuilder / Editor</title>
        <?php $APPLICATION->ShowHead(); ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor.css">
    </head>
    <body class="sb-admin-body">
    <div class="sb-page">
        <h1 class="sb-title">Не передан siteId</h1>
        <p>
            <a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/index.php">
                Вернуться к списку сайтов
            </a>
        </p>
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
    <title>SiteBuilder / Editor</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor.css">
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-topbar">
        <div class="sb-topbar-left">
            <a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/index.php">← К списку сайтов</a>
            <h1 class="sb-title">Редактор сайта</h1>
            <p class="sb-subtitle">siteId = <?= (int)$siteId ?></p>
        </div>
    </div>

    <div class="sb-editor-topline">
        <p class="sb-editor-topline-note">
            Слева — структура страниц. По центру — полотно текущей страницы. Справа — свойства выбранной страницы или блока.
        </p>

        <div class="sb-editor-topline-actions">
            <a class="sb-btn sb-btn-light sb-btn-small" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/public.php?siteId=<?= (int)$siteId ?>" target="_blank">
                Открыть публичную
            </a>

            <a class="sb-btn sb-btn-light sb-btn-small" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/layout.php?siteId=<?= (int)$siteId ?>">
                Layout
            </a>

            <a class="sb-btn sb-btn-light sb-btn-small" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/settings.php?siteId=<?= (int)$siteId ?>">
                Настройки
            </a>

            <button class="sb-btn sb-btn-danger sb-btn-small sb-hidden" type="button" id="deleteSiteBtn">
                Удалить сайт
            </button>
        </div>
    </div>

    <div class="sb-editor-shell">
        <div class="sb-editor-col">
            <div class="sb-editor-sticky">
                <div class="sb-panel">
                    <div class="sb-editor-section-head">
                        <h2 class="sb-panel-title">Страницы</h2>
                        <span class="sb-badge">siteId <?= (int)$siteId ?></span>
                    </div>

                    <div class="sb-editor-create">
                        <div class="sb-form-row align-end">
                            <div class="sb-field">
                                <label for="newPageTitle">Название страницы</label>
                                <input class="sb-input" type="text" id="newPageTitle" placeholder="Например: Главная">
                            </div>
                        </div>

                        <div class="sb-form-row align-end" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="newPageSlug">Slug</label>
                                <input class="sb-input" type="text" id="newPageSlug" placeholder="Например: home">
                            </div>

                            <div class="sb-field">
                                <label for="newPageParentId">Родитель</label>
                                <select class="sb-select" id="newPageParentId">
                                    <option value="0">Без родителя</option>
                                </select>
                            </div>
                        </div>

                        <div class="sb-form-row" style="margin-top:12px;">
                            <button class="sb-btn sb-btn-primary" type="button" id="createPageBtn">Создать страницу</button>
                        </div>
                    </div>

                    <div id="pagesList" class="sb-editor-pages">
                        <div class="sb-empty">Загрузка страниц...</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sb-editor-col">
            <div class="sb-editor-canvas">
                <div class="sb-editor-canvas-head">
                    <div>
                        <h2 class="sb-editor-canvas-title" id="canvasPageTitle">Страница</h2>
                        <p class="sb-editor-canvas-sub" id="canvasPageMeta">Выберите страницу слева</p>
                    </div>

                    <div class="sb-toolbar">
                        <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="movePageUpBtn">Страницу ↑</button>
                        <button class="sb-btn sb-btn-light sb-btn-small" type="button" id="movePageDownBtn">Страницу ↓</button>
                        <button class="sb-btn sb-btn-primary sb-btn-small" type="button" id="publishPageBtn">Опубликовать</button>
                    </div>
                </div>

                <div class="sb-editor-canvas-body">
                    <div class="sb-editor-page">
                        <h2 class="sb-editor-page-heading" id="pagePreviewHeading">Выберите страницу</h2>

                        <div class="sb-editor-addbar">
                            <button class="sb-editor-add-card" type="button" data-add-block="heading">
                                <span class="sb-editor-add-card__title">Заголовок</span>
                                <span class="sb-editor-add-card__text">Большой заголовок или подзаголовок секции</span>
                            </button>

                            <button class="sb-editor-add-card" type="button" data-add-block="text">
                                <span class="sb-editor-add-card__title">Текст</span>
                                <span class="sb-editor-add-card__text">Абзацы, списки и обычный контент</span>
                            </button>

                            <button class="sb-editor-add-card" type="button" data-add-block="button">
                                <span class="sb-editor-add-card__title">Кнопка</span>
                                <span class="sb-editor-add-card__text">CTA-кнопка со ссылкой</span>
                            </button>

                            <button class="sb-editor-add-card" type="button" data-add-block="html">
                                <span class="sb-editor-add-card__title">HTML</span>
                                <span class="sb-editor-add-card__text">Произвольный HTML-блок</span>
                            </button>

                            <button class="sb-editor-add-card" type="button" data-add-block="disk">
                                <span class="sb-editor-add-card__title">Диск</span>
                                <span class="sb-editor-add-card__text">Файлы, папки, загрузка и доступы</span>
                            </button>
                        </div>

                        <div id="blocksList" class="sb-editor-blocks">
                            <div class="sb-editor-empty-big">
                                <strong>Страница не выбрана</strong>
                                Выбери страницу слева, чтобы редактировать ее блоки
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sb-editor-col sb-editor-col--right">
            <div class="sb-editor-sticky">
                <div class="sb-panel">
                    <h2 class="sb-panel-title">Свойства страницы</h2>
                    <p class="sb-editor-note">Здесь меняются заголовок, slug, статус и родитель текущей страницы.</p>

                    <div class="sb-field">
                        <label for="pageTitleInput">Название</label>
                        <input class="sb-input" type="text" id="pageTitleInput">
                    </div>

                    <div class="sb-field" style="margin-top:12px;">
                        <label for="pageSlugInput">Slug</label>
                        <input class="sb-input" type="text" id="pageSlugInput">
                    </div>

                    <div class="sb-field" style="margin-top:12px;">
                        <label for="pageStatusInput">Статус</label>
                        <select class="sb-select" id="pageStatusInput">
                            <option value="draft">draft</option>
                            <option value="published">published</option>
                        </select>
                    </div>

                    <div class="sb-field" style="margin-top:12px;">
                        <label for="pageParentInput">Родительская страница</label>
                        <select class="sb-select" id="pageParentInput">
                            <option value="0">Без родителя</option>
                        </select>
                    </div>

                    <div class="sb-editor-inspector-actions">
                        <button class="sb-btn sb-btn-primary" type="button" id="savePageBtn">Сохранить страницу</button>
                        <button class="sb-btn sb-btn-danger" type="button" id="deletePageBtn">Удалить страницу</button>
                    </div>
                </div>

                <div class="sb-panel">
                    <h2 class="sb-panel-title">Свойства блока</h2>

                    <div id="blockInspectorEmpty" class="sb-empty">
                        Выбери блок в центре страницы
                    </div>

                    <div id="blockInspector" class="sb-hidden">
                        <div class="sb-field">
                            <label for="blockTypeInput">Тип</label>
                            <input class="sb-input" type="text" id="blockTypeInput" disabled>
                        </div>

                        <div id="headingBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="headingTextInput">Текст заголовка</label>
                                <input class="sb-input" type="text" id="headingTextInput" placeholder="Введите заголовок">
                            </div>
                        </div>

                        <div id="textBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="textTextInput">Текст блока</label>
                                <textarea class="sb-textarea" id="textTextInput" placeholder="Введите текст"></textarea>
                            </div>
                        </div>

                        <div id="buttonBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="buttonLabelInput">Текст кнопки</label>
                                <input class="sb-input" type="text" id="buttonLabelInput" placeholder="Например: Подробнее">
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="buttonHrefInput">Ссылка</label>
                                <input class="sb-input" type="text" id="buttonHrefInput" placeholder="https://... или /path/">
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="buttonTargetInput">Открывать</label>
                                <select class="sb-select" id="buttonTargetInput">
                                    <option value="_self">В этом окне</option>
                                    <option value="_blank">В новой вкладке</option>
                                </select>
                            </div>
                        </div>

                        <div id="htmlBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="htmlInput">HTML</label>
                                <textarea class="sb-textarea" id="htmlInput" placeholder="<div>HTML-код</div>"></textarea>
                                <p class="sb-block-form-note">
                                    Используй только проверенный HTML. Скрипты лучше не вставлять.
                                </p>
                            </div>
                        </div>

                        <div id="diskBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="diskTitleInput">Заголовок блока</label>
                                <input class="sb-input" type="text" id="diskTitleInput">
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="diskRootModeInput">Режим корня</label>
                                <select class="sb-select" id="diskRootModeInput">
                                    <option value="site">Корень сайта</option>
                                    <option value="block">Папка блока</option>
                                </select>
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="diskViewModeInput">Вид</label>
                                <select class="sb-select" id="diskViewModeInput">
                                    <option value="table">Таблица</option>
                                    <option value="grid">Плитка</option>
                                </select>
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="diskPermissionModeInput">Режим прав</label>
                                <select class="sb-select" id="diskPermissionModeInput">
                                    <option value="inherit_site">Наследовать права сайта</option>
                                    <option value="custom">Собственные ограничения блока</option>
                                </select>
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="diskMaxFileSizeInput">Максимальный размер файла</label>
                                <input class="sb-input" type="number" id="diskMaxFileSizeInput" min="0">
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="diskAllowedExtensionsInput">Разрешенные расширения</label>
                                <input class="sb-input" type="text" id="diskAllowedExtensionsInput" placeholder="pdf docx xlsx png jpg">
                            </div>

                            <div class="sb-form-row" style="margin-top:12px;">
                                <label><input type="checkbox" id="diskAllowUploadInput"> Загрузка</label>
                                <label><input type="checkbox" id="diskAllowCreateFolderInput"> Создание папок</label>
                            </div>

                            <div class="sb-form-row" style="margin-top:12px;">
                                <label><input type="checkbox" id="diskAllowRenameInput"> Переименование</label>
                                <label><input type="checkbox" id="diskAllowDeleteInput"> Удаление</label>
                            </div>

                            <div class="sb-form-row" style="margin-top:12px;">
                                <label><input type="checkbox" id="diskAllowDownloadInput"> Скачивание</label>
                                <label><input type="checkbox" id="diskShowSearchInput"> Показывать поиск</label>
                            </div>

                            <div class="sb-form-row" style="margin-top:12px;">
                                <label><input type="checkbox" id="diskShowBreadcrumbsInput"> Показывать breadcrumbs</label>
                                <label><input type="checkbox" id="diskUseSiteRootFallbackInput"> Использовать корень сайта как fallback</label>
                            </div>
                        </div>

                        <div id="unknownBlockForm" class="sb-block-type-form" style="margin-top:12px;">
                            <div class="sb-empty">
                                Для этого типа блока пока нет визуальной формы. Используй технический JSON ниже.
                            </div>
                        </div>

                        <div id="blockJsonFields" class="sb-editor-advanced-json">
                            <div class="sb-field" style="margin-top:12px;">
                                <label for="blockContentInput">Контент (JSON)</label>
                                <textarea class="sb-textarea" id="blockContentInput"></textarea>
                            </div>

                            <div class="sb-field" style="margin-top:12px;">
                                <label for="blockPropsInput">Свойства (JSON)</label>
                                <textarea class="sb-textarea" id="blockPropsInput"></textarea>
                            </div>
                        </div>

                        <div class="sb-editor-json-actions">
                            <button class="sb-btn sb-btn-primary" type="button" id="saveBlockBtn">Сохранить блок</button>
                            <button class="sb-btn sb-btn-light" type="button" id="duplicateBlockBtn">Дублировать</button>
                            <button class="sb-btn sb-btn-light" type="button" id="moveBlockUpBtn">Блок ↑</button>
                            <button class="sb-btn sb-btn-light" type="button" id="moveBlockDownBtn">Блок ↓</button>
                            <button class="sb-btn sb-btn-danger" type="button" id="deleteBlockBtn">Удалить</button>
                        </div>
                    </div>
                </div>

                <div class="sb-panel" id="siteGroupPanel" hidden>
                    <h2 class="sb-panel-title">Группа Битрикс24 и права</h2>
                    <p class="sb-editor-note">
                        Группа используется для связки сайта с пользователями Битрикс24.
                    </p>

                    <div id="bitrixGroupInfo" class="sb-empty">
                        Информация о группе загружается...
                    </div>

                    <div class="sb-editor-inspector-actions">
                        <button class="sb-btn sb-btn-light" type="button" id="ensureBitrixGroupBtn">Создать группу</button>
                        <button class="sb-btn sb-btn-light" type="button" id="syncAccessBtn">Синхронизировать права</button>
                    </div>

                    <div id="syncAccessResult" class="sb-output" style="margin-top:12px;"></div>
                </div>

                <div class="sb-panel" id="siteAccessPanel" hidden>
                    <h2 class="sb-panel-title">Права пользователей</h2>
                    <p class="sb-access-help">
                        OWNER управляет сайтом и правами. ADMIN редактирует структуру сайта. EDITOR работает с файлами диска. VIEWER только смотрит.
                    </p>

                    <div class="sb-access-form">
                        <div class="sb-field sb-access-search-wrap">
                            <label for="accessUserSearchInput">Пользователь</label>
                            <input class="sb-input" type="text" id="accessUserSearchInput" autocomplete="off" placeholder="ФИО, логин, email или ID">

                            <div id="accessUserSearchResults" class="sb-access-search-results sb-hidden"></div>
                            <div id="accessSelectedUser" class="sb-access-selected sb-hidden"></div>
                        </div>

                        <div class="sb-field">
                            <label for="accessRoleInput">Роль</label>
                            <select class="sb-select" id="accessRoleInput">
                                <option value="VIEWER">VIEWER</option>
                                <option value="EDITOR">EDITOR</option>
                                <option value="ADMIN">ADMIN</option>
                                <option value="OWNER">OWNER</option>
                            </select>
                        </div>
                    </div>

                    <div class="sb-editor-inspector-actions">
                        <button class="sb-btn sb-btn-primary" type="button" id="grantAccessBtn">Выдать роль</button>
                        <button class="sb-btn sb-btn-light" type="button" id="reloadAccessBtn">Обновить</button>
                    </div>

                    <div id="accessMessage" class="sb-empty sb-hidden" style="margin-top:12px;"></div>

                    <div id="accessList" class="sb-access-list">
                        <div class="sb-empty">Права не загружены</div>
                    </div>
                </div>

                <div class="sb-panel" id="apiOutputPanel" hidden>
                    <h2 class="sb-panel-title">Ответ API</h2>
                    <div id="output" class="sb-output">Здесь будут ответы API...</div>
                </div>

                <div id="outputFallback" style="display:none;"></div>
            </div>
        </div>
    </div>
</div>

<script src="/bitrix/js/main/core/core.js"></script>
<script>
(function () {
    var BASE_PATH = '<?= CUtil::JSEscape($basePath) ?>';
    var API_URL = BASE_PATH + '/api.php';
    var siteId = <?= (int)$siteId ?>;
    var IS_BITRIX_ADMIN = <?= $USER->IsAdmin() ? 'true' : 'false' ?>;

    var state = {
        site: null,
        pages: [],
        currentPageId: 0,
        blocks: [],
        currentBlockId: 0,
        accessItems: [],
        userSearchResults: [],
        selectedAccessUser: null,
        userSearchTimer: null
    };

    var output = document.getElementById('output') || document.getElementById('outputFallback');
    var pagesList = document.getElementById('pagesList');
    var blocksList = document.getElementById('blocksList');
    var newPageParentId = document.getElementById('newPageParentId');

    function print(data) {
        if (!output) return;

        try {
            output.textContent = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
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

    function userAvatarHtml(user, className) {
        user = user || {};
        className = className || '';

        var avatar = user.avatarUrl || user.avatar || user.photoUrl || user.userAvatarUrl || '';
        var title = user.title || user.name || user.userName || '';
        var initials = 'U';

        if (title) {
            var parts = String(title).trim().split(/\s+/).filter(Boolean);

            if (parts.length === 1) {
                initials = parts[0].substring(0, 1).toUpperCase();
            } else if (parts.length >= 2) {
                initials = (parts[0].substring(0, 1) + parts[1].substring(0, 1)).toUpperCase();
            }
        }

        var size = '32px';

        if (className.indexOf('selected') !== -1) {
            size = '42px';
        }

        var wrapStyle = [
            'width:' + size,
            'height:' + size,
            'min-width:' + size,
            'max-width:' + size,
            'min-height:' + size,
            'max-height:' + size,
            'border-radius:50%',
            'overflow:hidden',
            'display:flex',
            'align-items:center',
            'justify-content:center',
            'background:#eef2ff',
            'color:#3730a3',
            'font-size:11px',
            'font-weight:700',
            'line-height:1'
        ].join(';');

        if (avatar) {
            return ''
                + '<div class="' + className + '" style="' + wrapStyle + '">'
                + '  <img src="' + escapeHtml(avatar) + '" alt="" style="width:' + size + ';height:' + size + ';min-width:' + size + ';max-width:' + size + ';min-height:' + size + ';max-height:' + size + ';object-fit:cover;display:block;">'
                + '</div>';
        }

        return ''
            + '<div class="' + className + '" style="' + wrapStyle + '">'
            + escapeHtml(initials)
            + '</div>';
    }

    function getSessid() {
        if (window.BX && typeof BX.bitrix_sessid === 'function') {
            return BX.bitrix_sessid();
        }

        return '<?= CUtil::JSEscape(bitrix_sessid()) ?>';
    }

    function api(action, data) {
        return new Promise(function (resolve, reject) {
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

                    if (res && res.ok) {
                        resolve(res);
                    } else {
                        reject(res || {error: 'UNKNOWN'});
                    }
                },
                onfailure: function (err) {
                    print({
                        ok: false,
                        error: 'AJAX_ERROR',
                        detail: err
                    });

                    reject(err);
                }
            });
        });
    }

    function getInputValue(id) {
        var el = document.getElementById(id);
        return el ? String(el.value || '') : '';
    }

    function getChecked(id) {
        var el = document.getElementById(id);
        return !!(el && el.checked);
    }

    function getCurrentPage() {
        return state.pages.find(function (page) {
            return Number(page.id || 0) === state.currentPageId;
        }) || null;
    }

    function getCurrentBlock() {
        return state.blocks.find(function (block) {
            return Number(block.id || 0) === state.currentBlockId;
        }) || null;
    }

    function pageHasChildren(pageId) {
        return state.pages.some(function (page) {
            return Number(page.parentId || 0) === Number(pageId || 0);
        });
    }

    function buildPageTree(pages, parentId, depth, result) {
        result = result || [];
        depth = depth || 0;

        var branch = pages
            .filter(function (page) {
                return Number(page.parentId || 0) === Number(parentId || 0);
            })
            .sort(function (a, b) {
                var sortCmp = Number(a.sort || 0) - Number(b.sort || 0);
                if (sortCmp !== 0) return sortCmp;
                return Number(a.id || 0) - Number(b.id || 0);
            });

        branch.forEach(function (page) {
            result.push({
                page: page,
                depth: depth
            });

            buildPageTree(pages, Number(page.id || 0), depth + 1, result);
        });

        return result;
    }

    async function loadSite() {
        var res = await api('site.get', {
            siteId: siteId
        });

        state.site = res.site || null;
    }

    async function loadPages() {
        var res = await api('page.list', {
            siteId: siteId
        });

        state.pages = Array.isArray(res.pages) ? res.pages : [];

        if (!state.currentPageId && state.pages.length) {
            state.currentPageId = Number(state.pages[0].id || 0);
        }

        fillParentOptions();
        renderPages();
        fillPageForm();
        updateCanvasHeader();
    }

    async function loadBlocks() {
        if (!state.currentPageId) {
            state.blocks = [];
            state.currentBlockId = 0;
            renderBlocks();
            fillBlockForm();
            return;
        }

        var res = await api('block.list', {
            pageId: state.currentPageId
        });

        state.blocks = Array.isArray(res.blocks) ? res.blocks : [];

        if (state.currentBlockId) {
            var exists = state.blocks.some(function (b) {
                return Number(b.id || 0) === state.currentBlockId;
            });

            if (!exists) {
                state.currentBlockId = 0;
            }
        }

        renderBlocks();
        fillBlockForm();
        updateCanvasHeader();
    }

    function fillParentOptions() {
        var currentValue = String(newPageParentId.value || '0');
        var html = '<option value="0">Без родителя</option>';

        state.pages.forEach(function (page) {
            html += '<option value="' + Number(page.id || 0) + '">' + escapeHtml(page.title || ('Страница #' + page.id)) + '</option>';
        });

        newPageParentId.innerHTML = html;
        newPageParentId.value = currentValue;
    }

    function fillPageParentEditorOptions() {
        var select = document.getElementById('pageParentInput');
        if (!select) return;

        var currentPageId = Number(state.currentPageId || 0);
        var currentValue = String(select.value || '0');

        var html = '<option value="0">Без родителя</option>';

        state.pages.forEach(function (page) {
            var id = Number(page.id || 0);

            if (id === currentPageId) {
                return;
            }

            html += '<option value="' + id + '">' + escapeHtml(page.title || ('Страница #' + id)) + '</option>';
        });

        select.innerHTML = html;

        if (currentValue && select.querySelector('option[value="' + currentValue + '"]')) {
            select.value = currentValue;
        }
    }

    function renderPages() {
        if (!state.pages.length) {
            pagesList.innerHTML = '<div class="sb-empty">Страниц пока нет</div>';
            return;
        }

        var tree = buildPageTree(state.pages, 0, 0, []);

        pagesList.innerHTML = tree.map(function (item) {
            var page = item.page;
            var depth = item.depth;
            var active = Number(page.id || 0) === state.currentPageId ? ' is-active' : '';
            var hasChildren = pageHasChildren(page.id);
            var status = String(page.status || 'draft');

            return ''
                + '<div class="sb-editor-page-item' + active + '" data-page-id="' + Number(page.id || 0) + '" style="margin-left:' + (depth * 18) + 'px;">'
                + '  <div class="sb-editor-page-top">'
                + '      <div>'
                + '          <h3 class="sb-editor-page-title">' + escapeHtml(page.title || '') + '</h3>'
                + '          <div class="sb-editor-page-meta">'
                +               '<span class="sb-editor-chip">' + escapeHtml(page.slug || '') + '</span>'
                +               '<span class="sb-editor-chip ' + (status === 'published' ? 'sb-editor-chip--green' : 'sb-editor-chip--yellow') + '">' + escapeHtml(status) + '</span>'
                +               (hasChildren ? '<span class="sb-editor-chip sb-editor-chip--blue">section</span>' : '')
                + '          </div>'
                + '      </div>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    function updateCanvasHeader() {
        var page = getCurrentPage();
        var pageTitle = document.getElementById('canvasPageTitle');
        var pageMeta = document.getElementById('canvasPageMeta');
        var previewHeading = document.getElementById('pagePreviewHeading');

        if (!page) {
            pageTitle.textContent = 'Страница';
            pageMeta.textContent = 'Выберите страницу слева';
            previewHeading.textContent = 'Выберите страницу';
            return;
        }

        pageTitle.textContent = page.title || 'Страница';
        pageMeta.textContent = 'slug: ' + (page.slug || '') + ' · статус: ' + (page.status || 'draft') + ' · блоков: ' + state.blocks.length;
        previewHeading.textContent = page.title || 'Страница';
    }

    function blockPreviewText(block) {
        var type = String(block.type || '');
        var content = block.content || {};
        var props = block.props || {};

        if (type === 'heading') {
            return content.text || '[пустой заголовок]';
        }

        if (type === 'text') {
            return content.text || '[пустой текст]';
        }

        if (type === 'button') {
            return (content.label || 'Кнопка') + (content.href ? ' → ' + content.href : '');
        }

        if (type === 'html') {
            return (content.html || '').slice(0, 220) || '[пустой HTML]';
        }

        if (type === 'disk') {
            return 'Компонент "Диск": ' + (props.title || 'Файлы') + ' · rootMode=' + (props.rootMode || 'site') + ' · view=' + (props.viewMode || 'table');
        }

        try {
            return JSON.stringify(content);
        } catch (e) {
            return '[контент блока]';
        }
    }

    function renderBlocks() {
        if (!state.currentPageId) {
            blocksList.innerHTML = ''
                + '<div class="sb-editor-empty-big">'
                + '   <strong>Страница не выбрана</strong>'
                + '   Выбери страницу слева, чтобы редактировать блоки'
                + '</div>';
            return;
        }

        if (!state.blocks.length) {
            blocksList.innerHTML = ''
                + '<div class="sb-editor-empty-big">'
                + '   <strong>На странице пока нет блоков</strong>'
                + '   Добавь первый блок через панель сверху'
                + '</div>';
            return;
        }

        blocksList.innerHTML = state.blocks.map(function (block) {
            var active = Number(block.id || 0) === state.currentBlockId ? ' is-active' : '';

            return ''
                + '<div class="sb-editor-block' + active + '" data-block-id="' + Number(block.id || 0) + '">'
                + '  <div class="sb-editor-block-head">'
                + '      <div>'
                + '          <h3 class="sb-editor-block-title">' + escapeHtml(block.type || 'block') + '</h3>'
                + '          <div class="sb-editor-chip">block #' + Number(block.id || 0) + '</div>'
                + '      </div>'
                + '  </div>'
                + '  <div class="sb-editor-block-preview">' + escapeHtml(blockPreviewText(block)) + '</div>'
                + '</div>';
        }).join('');
    }

    function hideAllBlockTypeForms() {
        [
            'headingBlockForm',
            'textBlockForm',
            'buttonBlockForm',
            'htmlBlockForm',
            'diskBlockForm',
            'unknownBlockForm'
        ].forEach(function (id) {
            var node = document.getElementById(id);
            if (node) {
                node.classList.remove('is-active');
                node.classList.add('sb-hidden');
            }
        });
    }

    function showBlockTypeForm(id) {
        var node = document.getElementById(id);
        if (!node) return;

        node.classList.add('is-active');
        node.classList.remove('sb-hidden');
    }

    function fillVisualBlockForm(block) {
        hideAllBlockTypeForms();

        var type = String(block.type || '');
        var content = block.content || {};

        if (type === 'heading') {
            showBlockTypeForm('headingBlockForm');
            document.getElementById('headingTextInput').value = content.text || '';
            return;
        }

        if (type === 'text') {
            showBlockTypeForm('textBlockForm');
            document.getElementById('textTextInput').value = content.text || '';
            return;
        }

        if (type === 'button') {
            showBlockTypeForm('buttonBlockForm');
            document.getElementById('buttonLabelInput').value = content.label || '';
            document.getElementById('buttonHrefInput').value = content.href || '';
            document.getElementById('buttonTargetInput').value = content.target || '_self';
            return;
        }

        if (type === 'html') {
            showBlockTypeForm('htmlBlockForm');
            document.getElementById('htmlInput').value = content.html || '';
            return;
        }

        if (type === 'disk') {
            showBlockTypeForm('diskBlockForm');
            return;
        }

        showBlockTypeForm('unknownBlockForm');

        var jsonFields = document.getElementById('blockJsonFields');
        if (jsonFields) {
            jsonFields.classList.add('is-open');
        }
    }

    function fillDiskForm(props) {
        props = props || {};

        document.getElementById('diskTitleInput').value = props.title || 'Файлы';
        document.getElementById('diskRootModeInput').value = props.rootMode || 'site';
        document.getElementById('diskViewModeInput').value = props.viewMode || 'table';
        document.getElementById('diskPermissionModeInput').value = props.permissionMode || 'inherit_site';
        document.getElementById('diskMaxFileSizeInput').value = props.maxFileSize || 52428800;
        document.getElementById('diskAllowedExtensionsInput').value = Array.isArray(props.allowedExtensions) ? props.allowedExtensions.join(' ') : '';

        document.getElementById('diskAllowUploadInput').checked = !!props.allowUpload;
        document.getElementById('diskAllowCreateFolderInput').checked = !!props.allowCreateFolder;
        document.getElementById('diskAllowRenameInput').checked = !!props.allowRename;
        document.getElementById('diskAllowDeleteInput').checked = !!props.allowDelete;
        document.getElementById('diskAllowDownloadInput').checked = !!props.allowDownload;
        document.getElementById('diskShowSearchInput').checked = !!props.showSearch;
        document.getElementById('diskShowBreadcrumbsInput').checked = !!props.showBreadcrumbs;
        document.getElementById('diskUseSiteRootFallbackInput').checked = !!props.useSiteRootFallback;
    }

    function fillBlockForm() {
        var block = getCurrentBlock();
        var emptyNode = document.getElementById('blockInspectorEmpty');
        var formNode = document.getElementById('blockInspector');

        if (!block) {
            emptyNode.classList.remove('sb-hidden');
            formNode.classList.add('sb-hidden');

            hideAllBlockTypeForms();

            document.getElementById('blockTypeInput').value = '';
            document.getElementById('blockContentInput').value = '';
            document.getElementById('blockPropsInput').value = '';

            var jsonFieldsEmpty = document.getElementById('blockJsonFields');
            if (jsonFieldsEmpty) {
                jsonFieldsEmpty.classList.remove('is-open');
            }

            return;
        }

        emptyNode.classList.add('sb-hidden');
        formNode.classList.remove('sb-hidden');

        var content = block.content || {};
        var props = block.props || {};

        document.getElementById('blockTypeInput').value = block.type || '';
        document.getElementById('blockContentInput').value = JSON.stringify(content, null, 2);
        document.getElementById('blockPropsInput').value = JSON.stringify(props, null, 2);

        var jsonFields = document.getElementById('blockJsonFields');
        if (jsonFields) {
            jsonFields.classList.remove('is-open');
        }

        if (block.type === 'disk') {
            fillDiskForm(props);
        }

        fillVisualBlockForm(block);
    }

    function fillPageForm() {
        var page = getCurrentPage();

        fillPageParentEditorOptions();

        document.getElementById('pageTitleInput').value = page ? (page.title || '') : '';
        document.getElementById('pageSlugInput').value = page ? (page.slug || '') : '';
        document.getElementById('pageStatusInput').value = page ? (page.status || 'draft') : 'draft';

        var parentSelect = document.getElementById('pageParentInput');
        if (parentSelect) {
            parentSelect.value = page ? String(page.parentId || 0) : '0';
        }
    }

    function collectDiskBlockProps(block) {
        var oldProps = block.props || {};

        return {
            title: getInputValue('diskTitleInput').trim() || 'Файлы',
            rootMode: getInputValue('diskRootModeInput') || 'site',
            rootFolderId: oldProps.rootFolderId || null,
            viewMode: getInputValue('diskViewModeInput') || 'table',
            permissionMode: getInputValue('diskPermissionModeInput') || 'inherit_site',
            maxFileSize: Number(getInputValue('diskMaxFileSizeInput') || 0),
            allowedExtensions: String(getInputValue('diskAllowedExtensionsInput') || '')
                .trim()
                .split(/\s+/)
                .filter(Boolean),
            allowUpload: getChecked('diskAllowUploadInput'),
            allowCreateFolder: getChecked('diskAllowCreateFolderInput'),
            allowRename: getChecked('diskAllowRenameInput'),
            allowDelete: getChecked('diskAllowDeleteInput'),
            allowDownload: getChecked('diskAllowDownloadInput'),
            showSearch: getChecked('diskShowSearchInput'),
            showBreadcrumbs: getChecked('diskShowBreadcrumbsInput'),
            useSiteRootFallback: getChecked('diskUseSiteRootFallbackInput'),
            defaultSort: oldProps.defaultSort || 'updatedAt',
            defaultSortDirection: oldProps.defaultSortDirection || 'desc'
        };
    }

    function collectVisualBlockData(block) {
        var type = String(block.type || '');
        var content = {};
        var props = block.props || {};

        if (type === 'heading') {
            return {
                content: {
                    text: getInputValue('headingTextInput').trim()
                },
                props: props
            };
        }

        if (type === 'text') {
            return {
                content: {
                    text: getInputValue('textTextInput')
                },
                props: props
            };
        }

        if (type === 'button') {
            return {
                content: {
                    label: getInputValue('buttonLabelInput').trim() || 'Кнопка',
                    href: getInputValue('buttonHrefInput').trim() || '#',
                    target: getInputValue('buttonTargetInput') || '_self'
                },
                props: props
            };
        }

        if (type === 'html') {
            return {
                content: {
                    html: getInputValue('htmlInput')
                },
                props: props
            };
        }

        if (type === 'disk') {
            return {
                content: block.content || {},
                props: collectDiskBlockProps(block)
            };
        }

        try {
            content = JSON.parse(document.getElementById('blockContentInput').value || '{}');
        } catch (e) {
            alert('Контент блока должен быть валидным JSON');
            return null;
        }

        try {
            props = JSON.parse(document.getElementById('blockPropsInput').value || '{}');
        } catch (e) {
            alert('Свойства блока должны быть валидным JSON');
            return null;
        }

        return {
            content: content,
            props: props
        };
    }

    async function createPage() {
        var title = getInputValue('newPageTitle').trim();
        var slug = getInputValue('newPageSlug').trim();
        var parentId = Number(getInputValue('newPageParentId') || 0);

        if (!title) {
            alert('Введите название страницы');
            document.getElementById('newPageTitle').focus();
            return;
        }

        await api('page.create', {
            siteId: siteId,
            title: title,
            slug: slug,
            parentId: parentId
        });

        document.getElementById('newPageTitle').value = '';
        document.getElementById('newPageSlug').value = '';
        document.getElementById('newPageParentId').value = '0';

        await loadPages();
        await loadBlocks();
    }

    async function savePage() {
        if (!state.currentPageId) return;

        var parentId = Number(getInputValue('pageParentInput') || 0);

        await api('page.updateMeta', {
            id: state.currentPageId,
            title: getInputValue('pageTitleInput').trim(),
            slug: getInputValue('pageSlugInput').trim(),
            parentId: parentId
        });

        await api('page.setStatus', {
            id: state.currentPageId,
            status: getInputValue('pageStatusInput')
        });

        await loadPages();
        await loadBlocks();
    }

    async function deletePage() {
        if (!state.currentPageId) return;
        if (!confirm('Удалить страницу? Дочерние страницы и блоки этой страницы тоже будут удалены.')) return;

        var idToDelete = state.currentPageId;

        await api('page.delete', {
            id: idToDelete
        });

        if (state.currentPageId === idToDelete) {
            state.currentPageId = 0;
        }

        await loadPages();
        await loadBlocks();
    }

    async function movePage(dir) {
        if (!state.currentPageId) return;

        await api('page.move', {
            id: state.currentPageId,
            dir: dir
        });

        await loadPages();
    }

    async function createBlock(type) {
        if (!state.currentPageId) {
            alert('Сначала выберите страницу');
            return;
        }

        var content = {};
        var props = {};

        if (type === 'heading') {
            content = {text: 'Новый заголовок'};
        } else if (type === 'text') {
            content = {text: 'Новый текстовый блок'};
        } else if (type === 'button') {
            content = {
                label: 'Кнопка',
                href: '#',
                target: '_self'
            };
        } else if (type === 'html') {
            content = {html: '<div>Новый HTML блок</div>'};
        } else if (type === 'disk') {
            content = {};
            props = {
                title: 'Файлы',
                rootMode: 'site',
                rootFolderId: null,
                viewMode: 'table',
                allowUpload: true,
                allowCreateFolder: true,
                allowRename: true,
                allowDelete: true,
                allowDownload: true,
                showSearch: true,
                showBreadcrumbs: true,
                defaultSort: 'updatedAt',
                defaultSortDirection: 'desc',
                allowedExtensions: [],
                maxFileSize: 52428800,
                permissionMode: 'inherit_site',
                useSiteRootFallback: true
            };
        }

        await api('block.create', {
            pageId: state.currentPageId,
            type: type,
            content: JSON.stringify(content),
            props: JSON.stringify(props)
        });

        await loadBlocks();
    }

    async function saveBlock() {
        var block = getCurrentBlock();
        if (!block) return;

        var collected = collectVisualBlockData(block);

        if (!collected) {
            return;
        }

        await api('block.update', {
            id: block.id,
            content: JSON.stringify(collected.content),
            props: JSON.stringify(collected.props)
        });

        await loadBlocks();
    }

    async function duplicateBlock() {
        var block = getCurrentBlock();
        if (!block) return;

        await api('block.duplicate', {
            id: block.id
        });

        await loadBlocks();
    }

    async function deleteBlock() {
        var block = getCurrentBlock();
        if (!block) return;
        if (!confirm('Удалить блок?')) return;

        await api('block.delete', {
            id: block.id
        });

        state.currentBlockId = 0;
        await loadBlocks();
    }

    async function moveBlock(dir) {
        var block = getCurrentBlock();
        if (!block) return;

        await api('block.move', {
            id: block.id,
            dir: dir
        });

        await loadBlocks();
    }

    function setManagementPanelsVisible(canManage) {
        var groupPanel = document.getElementById('siteGroupPanel');
        var accessPanel = document.getElementById('siteAccessPanel');
        var apiPanel = document.getElementById('apiOutputPanel');
        var deleteSiteBtn = document.getElementById('deleteSiteBtn');

        if (groupPanel) {
            groupPanel.hidden = !canManage;
        }

        if (accessPanel) {
            accessPanel.hidden = !canManage;
        }

        if (apiPanel) {
            apiPanel.hidden = !canManage;
        }

        if (deleteSiteBtn) {
            var role = state.site && state.site.currentUserRole
                ? String(state.site.currentUserRole)
                : '';

            var canDeleteSite = IS_BITRIX_ADMIN || role === 'OWNER' || canManage;

            deleteSiteBtn.classList.toggle('sb-hidden', !canDeleteSite);
        }
    }

    function renderBitrixGroupPanel() {
        var site = state.site || {};
        var groupId = Number(site.bitrixGroupId || 0);
        var node = document.getElementById('bitrixGroupInfo');

        if (!node) return;

        if (groupId > 0) {
            node.innerHTML = ''
                + '<div><strong>Группа создана</strong></div>'
                + '<div class="sb-muted">ID группы: ' + groupId + '</div>'
                + '<div style="margin-top:8px;">'
                + '  <a class="sb-btn sb-btn-light sb-btn-small" target="_blank" href="/workgroups/group/' + groupId + '/">Открыть группу</a>'
                + '</div>';

            return;
        }

        node.innerHTML = ''
            + '<div><strong>Группа Битрикс24 не создана</strong></div>'
            + '<div class="sb-muted">Можно создать группу и затем синхронизировать права.</div>';
    }

    async function ensureBitrixGroup() {
        var resultNode = document.getElementById('syncAccessResult');

        try {
            var res = await api('site.ensureGroup', {
                siteId: siteId
            });

            state.site = res.site || state.site;

            renderBitrixGroupPanel();

            if (resultNode) {
                resultNode.textContent = JSON.stringify(res, null, 2);
            }
        } catch (e) {
            if (resultNode) {
                resultNode.textContent = JSON.stringify(e, null, 2);
            }
        }
    }

    async function syncAccess() {
        var resultNode = document.getElementById('syncAccessResult');

        try {
            var res = await api('site.syncAccess', {
                siteId: siteId
            });

            if (resultNode) {
                resultNode.textContent = JSON.stringify(res, null, 2);
            }

            await loadAccessList();
        } catch (e) {
            if (resultNode) {
                resultNode.textContent = JSON.stringify(e, null, 2);
            }
        }
    }

    function setAccessMessage(message, type) {
        var node = document.getElementById('accessMessage');
        if (!node) return;

        node.classList.remove('sb-hidden', 'is-success', 'is-error');

        if (type === 'success') {
            node.classList.add('is-success');
        }

        if (type === 'error') {
            node.classList.add('is-error');
        }

        node.textContent = message || '';
    }

    function hideAccessMessage() {
        var node = document.getElementById('accessMessage');
        if (!node) return;

        node.classList.add('sb-hidden');
        node.textContent = '';
    }

    function renderAccessUserSearchResults(users) {
        var results = document.getElementById('accessUserSearchResults');
        if (!results) return;

        state.userSearchResults = Array.isArray(users) ? users : [];

        if (!state.userSearchResults.length) {
            results.innerHTML = '';
            results.classList.add('sb-hidden');
            return;
        }

        results.innerHTML = state.userSearchResults.map(function (user) {
            var id = Number(user.id || 0);
            var title = user.title || user.name || ('Пользователь #' + id);
            var meta = [];

            if (user.login) meta.push(user.login);
            if (user.email) meta.push(user.email);

            return ''
                + '<button class="sb-access-result-item" type="button" data-select-access-user="' + id + '" style="display:grid;grid-template-columns:32px minmax(0,1fr);gap:10px;align-items:center;width:100%;min-height:44px;padding:7px 10px;box-sizing:border-box;">'
                +      userAvatarHtml(user, 'sb-access-result-avatar')
                + '  <div class="sb-access-result-body" style="min-width:0;overflow:hidden;">'
                + '      <div class="sb-access-result-title" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(title) + '</div>'
                + '      <div class="sb-access-result-meta" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">ID: ' + id + (meta.length ? ' · ' + escapeHtml(meta.join(' · ')) : '') + '</div>'
                + '  </div>'
                + '</button>';
        }).join('');

        results.classList.remove('sb-hidden');
    }

    function renderSelectedAccessUser() {
        var selectedNode = document.getElementById('accessSelectedUser');
        if (!selectedNode) return;

        var user = state.selectedAccessUser;

        if (!user) {
            selectedNode.innerHTML = '';
            selectedNode.classList.add('sb-hidden');
            return;
        }

        var userId = Number(user.id || 0);
        var meta = [];

        if (user.login) meta.push(user.login);
        if (user.email) meta.push(user.email);

        selectedNode.innerHTML = ''
            + '<div class="sb-access-selected-user">'
            +      userAvatarHtml(user, 'sb-access-selected-avatar')
            + '  <div class="sb-access-selected-body">'
            + '      <div class="sb-access-selected-title">' + escapeHtml(user.title || user.name || ('Пользователь #' + userId)) + '</div>'
            + '      <div class="sb-access-selected-meta">ID: ' + userId + (meta.length ? ' · ' + escapeHtml(meta.join(' · ')) : '') + '</div>'
            + '  </div>'
            + '  <div class="sb-access-selected-actions">'
            + '      <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-clear-access-user>Сбросить</button>'
            + '  </div>'
            + '</div>';

        selectedNode.classList.remove('sb-hidden');
    }

    async function searchAccessUsers() {
        var input = document.getElementById('accessUserSearchInput');
        if (!input) return;

        var query = String(input.value || '').trim();

        state.selectedAccessUser = null;
        renderSelectedAccessUser();

        if (query === '') {
            renderAccessUserSearchResults([]);
            return;
        }

        if (!/^\d+$/.test(query) && query.length < 2) {
            renderAccessUserSearchResults([]);
            return;
        }

        try {
            var res = await api('user.search', {
                siteId: siteId,
                query: query,
                limit: 10
            });

            renderAccessUserSearchResults(Array.isArray(res.users) ? res.users : []);
        } catch (e) {
            renderAccessUserSearchResults([]);
        }
    }

    function selectAccessUser(user) {
        state.selectedAccessUser = user || null;

        var input = document.getElementById('accessUserSearchInput');
        if (input && user) {
            input.value = user.title || user.name || '';
        }

        renderAccessUserSearchResults([]);
        renderSelectedAccessUser();
    }

    function clearSelectedAccessUser() {
        state.selectedAccessUser = null;

        var input = document.getElementById('accessUserSearchInput');
        if (input) {
            input.value = '';
            input.focus();
        }

        renderSelectedAccessUser();
        renderAccessUserSearchResults([]);
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

    function renderAccessList() {
        var list = document.getElementById('accessList');
        if (!list) return;

        if (!Array.isArray(state.accessItems) || !state.accessItems.length) {
            list.innerHTML = '<div class="sb-empty">Права ещё не выданы</div>';
            return;
        }

        list.innerHTML = state.accessItems.map(function (item) {
            var userId = Number(item.userId || 0);
            var name = item.userName || item.title || ('Пользователь #' + userId);
            var role = item.role || '';

            return ''
                + '<div class="sb-access-item">'
                + '  <div class="sb-access-item__main">'
                + '      <div class="sb-access-item__name">' + escapeHtml(name) + '</div>'
                + '      <div class="sb-access-item__meta">ID: ' + userId + ' · ' + escapeHtml(item.accessCode || '') + '</div>'
                + '  </div>'
                + '  <div class="sb-access-item__side">'
                +        roleBadge(role)
                + '      <button class="sb-btn sb-btn-danger sb-btn-small" type="button" data-access-remove-user="' + userId + '">Удалить</button>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    async function loadAccessList() {
        var panel = document.getElementById('siteAccessPanel');
        if (!panel) return;

        try {
            var res = await api('site.accessList', {
                siteId: siteId
            });

            state.accessItems = Array.isArray(res.items) ? res.items : [];

            setManagementPanelsVisible(true);
            renderBitrixGroupPanel();
            renderAccessList();
        } catch (e) {
            state.accessItems = [];
            setManagementPanelsVisible(false);
        }
    }

    async function grantAccessRole() {
        var roleInput = document.getElementById('accessRoleInput');
        if (!roleInput) return;

        var user = state.selectedAccessUser;
        var userId = user ? Number(user.id || 0) : 0;
        var role = String(roleInput.value || '').trim();

        if (userId <= 0) {
            setAccessMessage('Сначала найди и выбери пользователя из списка', 'error');

            var searchInput = document.getElementById('accessUserSearchInput');
            if (searchInput) {
                searchInput.focus();
            }

            return;
        }

        if (!role) {
            setAccessMessage('Выбери роль', 'error');
            return;
        }

        try {
            setAccessMessage('Сохраняю права...', '');

            var res = await api('site.accessSet', {
                siteId: siteId,
                userId: userId,
                role: role
            });

            state.accessItems = Array.isArray(res.items) ? res.items : [];

            clearSelectedAccessUser();
            renderAccessList();

            var groupSync = res.result && res.result.groupSync ? res.result.groupSync : null;
            var syncText = '';

            if (groupSync) {
                if (groupSync.ok) {
                    syncText = '\nПользователь также синхронизирован с группой Битрикс24.';
                } else if (groupSync.error) {
                    syncText = '\nНо с группой Битрикс24 не синхронизировался: ' + groupSync.error;
                } else if (groupSync.message) {
                    syncText = '\nГруппа Битрикс24: ' + groupSync.message;
                }
            }

            setAccessMessage('Роль выдана: U' + userId + ' → ' + role + syncText, 'success');
        } catch (e) {
            setAccessMessage('Ошибка выдачи роли: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        }
    }

    async function removeAccessRole(userId) {
        userId = Number(userId || 0);

        if (userId <= 0) return;

        if (!confirm('Удалить доступ пользователя #' + userId + '?')) {
            return;
        }

        try {
            hideAccessMessage();

            var res = await api('site.accessRemove', {
                siteId: siteId,
                userId: userId
            });

            state.accessItems = Array.isArray(res.items) ? res.items : [];
            renderAccessList();

            setAccessMessage('Доступ удалён', 'success');
        } catch (e) {
            setAccessMessage('Ошибка удаления доступа: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        }
    }

    async function deleteSite() {
        var siteName = state.site && state.site.name ? state.site.name : ('siteId ' + siteId);

        if (!confirm('Удалить сайт "' + siteName + '"?')) {
            return;
        }

        if (!confirm('Подтверди удаление ещё раз. Это действие нельзя отменить через интерфейс.')) {
            return;
        }

        await api('site.delete', {
            id: siteId
        });

        alert('Сайт удалён');
        window.location.href = BASE_PATH + '/index.php';
    }

    pagesList.addEventListener('click', async function (e) {
        var item = e.target.closest('[data-page-id]');
        if (!item) return;

        state.currentPageId = Number(item.getAttribute('data-page-id') || 0);
        state.currentBlockId = 0;

        renderPages();
        fillPageForm();

        await loadBlocks();
    });

    blocksList.addEventListener('click', function (e) {
        var item = e.target.closest('[data-block-id]');
        if (!item) return;

        state.currentBlockId = Number(item.getAttribute('data-block-id') || 0);

        renderBlocks();
        fillBlockForm();
    });

    document.getElementById('createPageBtn').addEventListener('click', createPage);
    document.getElementById('savePageBtn').addEventListener('click', savePage);
    document.getElementById('deletePageBtn').addEventListener('click', deletePage);

    document.getElementById('movePageUpBtn').addEventListener('click', function () {
        movePage('up');
    });

    document.getElementById('movePageDownBtn').addEventListener('click', function () {
        movePage('down');
    });

    document.getElementById('publishPageBtn').addEventListener('click', async function () {
        if (!state.currentPageId) return;

        await api('page.setStatus', {
            id: state.currentPageId,
            status: 'published'
        });

        await loadPages();
    });

    document.querySelectorAll('[data-add-block]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            createBlock(btn.getAttribute('data-add-block'));
        });
    });

    document.getElementById('saveBlockBtn').addEventListener('click', saveBlock);
    document.getElementById('duplicateBlockBtn').addEventListener('click', duplicateBlock);
    document.getElementById('deleteBlockBtn').addEventListener('click', deleteBlock);

    document.getElementById('moveBlockUpBtn').addEventListener('click', function () {
        moveBlock('up');
    });

    document.getElementById('moveBlockDownBtn').addEventListener('click', function () {
        moveBlock('down');
    });

    var deleteSiteBtn = document.getElementById('deleteSiteBtn');
    if (deleteSiteBtn) {
        deleteSiteBtn.addEventListener('click', deleteSite);
    }

    var syncAccessBtn = document.getElementById('syncAccessBtn');
    if (syncAccessBtn) {
        syncAccessBtn.addEventListener('click', syncAccess);
    }

    var ensureBitrixGroupBtn = document.getElementById('ensureBitrixGroupBtn');
    if (ensureBitrixGroupBtn) {
        ensureBitrixGroupBtn.addEventListener('click', ensureBitrixGroup);
    }

    var grantAccessBtn = document.getElementById('grantAccessBtn');
    if (grantAccessBtn) {
        grantAccessBtn.addEventListener('click', grantAccessRole);
    }

    var reloadAccessBtn = document.getElementById('reloadAccessBtn');
    if (reloadAccessBtn) {
        reloadAccessBtn.addEventListener('click', loadAccessList);
    }

    var accessUserSearchInput = document.getElementById('accessUserSearchInput');
    if (accessUserSearchInput) {
        accessUserSearchInput.addEventListener('input', function () {
            clearTimeout(state.userSearchTimer);

            state.userSearchTimer = setTimeout(function () {
                searchAccessUsers();
            }, 300);
        });

        accessUserSearchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();

                if (state.userSearchResults.length) {
                    selectAccessUser(state.userSearchResults[0]);
                }
            }
        });
    }

    document.addEventListener('click', function (e) {
        var selectBtn = e.target.closest('[data-select-access-user]');
        if (selectBtn) {
            var userId = Number(selectBtn.getAttribute('data-select-access-user') || 0);
            var user = (state.userSearchResults || []).find(function (item) {
                return Number(item.id || 0) === userId;
            });

            if (user) {
                selectAccessUser(user);
            }

            return;
        }

        var clearBtn = e.target.closest('[data-clear-access-user]');
        if (clearBtn) {
            clearSelectedAccessUser();
            return;
        }

        var removeBtn = e.target.closest('[data-access-remove-user]');
        if (removeBtn) {
            removeAccessRole(Number(removeBtn.getAttribute('data-access-remove-user') || 0));
            return;
        }
    });

    document.addEventListener('mousedown', function (e) {
        var wrap = e.target.closest('.sb-access-search-wrap');
        if (!wrap) {
            renderAccessUserSearchResults([]);
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

    (async function init() {
        try {
            setManagementPanelsVisible(false);

            await loadSite();
            await loadPages();
            await loadBlocks();
            await loadAccessList();
        } catch (e) {
            print(e);
            alert('Не удалось загрузить редактор');
        }
    })();
})();
</script>
</body>
</html>