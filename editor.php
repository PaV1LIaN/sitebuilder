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

            <?php if ($USER->IsAdmin()): ?>
                <button class="sb-btn sb-btn-primary sb-btn-small" type="button" id="saveAsTemplateBtn">
                    Сохранить как шаблон
                </button>
            <?php endif; ?>

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

                <div class="sb-panel sb-page-sections-editor">
                    <div class="sb-page-sections-editor__head">
                        <div>
                            <h2 class="sb-panel-title">Секции страницы</h2>
                            <p class="sb-editor-note">
                                Большие блоки страницы. Внутрь секций распределяются компоненты.
                            </p>
                        </div>

                        <button class="sb-btn sb-btn-primary sb-btn-small" type="button" id="addPageSectionBtn">
                            + Секция
                        </button>
                    </div>

                    <div id="pageSectionsMessage" class="sb-page-sections-message" hidden></div>

                    <div id="pageSectionsList" class="sb-page-sections-list">
                        <div class="sb-empty">Выберите страницу</div>
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

                        <div class="sb-form-row sb-block-placement-row" style="margin-top:12px;">
                            <div class="sb-field">
                                <label for="blockSectionInput">Секция</label>
                                <select class="sb-select" id="blockSectionInput">
                                    <option value="0">Основная секция</option>
                                </select>
                            </div>

                            <div class="sb-field">
                                <label for="blockColumnInput">Колонка</label>
                                <select class="sb-select" id="blockColumnInput">
                                    <option value="1">Колонка 1</option>
                                </select>
                            </div>
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

<?php if ($USER->IsAdmin()): ?>
    <div class="sb-template-modal" id="saveTemplateModal" hidden>
        <div class="sb-template-modal__backdrop" data-close-template-modal></div>

        <div class="sb-template-modal__dialog">
            <div class="sb-template-modal__head">
                <div>
                    <h2 class="sb-template-modal__title">Сохранить сайт как шаблон</h2>
                    <p class="sb-template-modal__subtitle">
                        Шаблон сохранит страницы, вложенность, блоки, layout, меню и оформление. Файлы диска не копируются.
                    </p>
                </div>

                <button class="sb-template-modal__close" type="button" data-close-template-modal>×</button>
            </div>

            <div class="sb-template-modal__body">
                <div class="sb-field">
                    <label for="templateNameInput">Название шаблона</label>
                    <input class="sb-input" type="text" id="templateNameInput" placeholder="Например: Корпоративный портал">
                </div>

                <div class="sb-field" style="margin-top:12px;">
                    <label for="templateDescriptionInput">Описание</label>
                    <textarea class="sb-input" id="templateDescriptionInput" rows="4" placeholder="Кратко опиши, для каких сайтов подходит этот шаблон"></textarea>
                </div>

                <div class="sb-template-note">
                    Создание, изменение и удаление шаблонов доступно только администратору Битрикса.
                </div>

                <div id="templateMessage" class="sb-template-message" hidden></div>
            </div>

            <div class="sb-template-modal__footer">
                <button class="sb-btn sb-btn-light" type="button" data-close-template-modal>Отмена</button>
                <button class="sb-btn sb-btn-primary" type="button" id="createTemplateBtn">Создать шаблон</button>
            </div>
        </div>
    </div>
<?php endif; ?>


<script>
window.SB_EDITOR_CONFIG = {
    basePath: '<?= CUtil::JSEscape($basePath) ?>',
    apiUrl: '<?= CUtil::JSEscape($basePath) ?>/api.php',
    siteId: <?= (int)$siteId ?>,
    isBitrixAdmin: <?= $USER->IsAdmin() ? 'true' : 'false' ?>,
    sessid: '<?= CUtil::JSEscape(bitrix_sessid()) ?>'
};
</script>

<script src="/bitrix/js/main/core/core.js"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/editor.js?v=1"></script>

</body>
</html>