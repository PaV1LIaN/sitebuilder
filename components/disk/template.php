<?php if (!empty($arResult['ERROR'])): ?>
    <pre style="padding:12px;background:#fff3f3;border:1px solid #f1b5b5;color:#8a1f1f;margin-bottom:16px;">
<?= disk_h($arResult['ERROR']) ?>
    </pre>
<?php endif; ?>
<?php


$initialStateJson = disk_h(json_encode($arResult['INITIAL_STATE'], JSON_UNESCAPED_UNICODE));
?>
<div class="sb-disk"
     id="sb-disk-<?= (int)$arResult['BLOCK_ID'] ?>"
     data-site-id="<?= (int)$arResult['SITE_ID'] ?>"
     data-page-id="<?= (int)$arResult['PAGE_ID'] ?>"
     data-block-id="<?= (int)$arResult['BLOCK_ID'] ?>"
     data-sessid="<?= htmlspecialchars(bitrix_sessid(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
     data-initial-state="<?= $initialStateJson ?>">

    <div class="sb-disk__header">
        <div class="sb-disk__header-main">
            <div class="sb-disk__title-wrap">
                <h3 class="sb-disk__title"><?= disk_h($arResult['TITLE']) ?></h3>
                <div class="sb-disk__subtitle" data-role="subtitle"></div>
            </div>
        </div>

        <div class="sb-disk__header-actions">
            <button type="button" class="sb-disk__btn sb-disk__btn--ghost" data-action="refresh">Обновить</button>

            <?php if (!empty($arResult['PERMISSIONS']['canManageAccess'])): ?>
                <button type="button" class="sb-disk__btn sb-disk__btn--ghost" data-action="folder-access" data-permission="canManageAccess">Права папки</button>
            <?php endif; ?>

            <?php if (!empty($arResult['PERMISSIONS']['canEditSettings'])): ?>
                <button type="button" class="sb-disk__btn sb-disk__btn--ghost" data-action="settings" data-permission="canEditSettings">Настройки</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($arResult['SETTINGS']['showBreadcrumbs'])): ?>
        <div class="sb-disk__breadcrumbs" data-role="breadcrumbs"></div>
    <?php endif; ?>

    <div class="sb-disk__toolbar">
        <div class="sb-disk__toolbar-left">
            <?php if (!empty($arResult['SETTINGS']['showSearch'])): ?>
                <div class="sb-disk__search">
                    <input type="text"
                           class="sb-disk__search-input"
                           data-role="search-input"
                           placeholder="Поиск файлов и папок">
                </div>
            <?php endif; ?>

            <select class="sb-disk__select" data-role="sort-select">
                <option value="updatedAt:desc">Сначала новые</option>
                <option value="updatedAt:asc">Сначала старые</option>
                <option value="name:asc">По имени А–Я</option>
                <option value="name:desc">По имени Я–А</option>
                <option value="size:desc">По размеру</option>
            </select>
        </div>

        <div class="sb-disk__toolbar-right">
            <button type="button" class="sb-disk__btn" data-action="upload" data-permission="canUpload" <?= empty($arResult['PERMISSIONS']['canUpload']) ? 'hidden' : '' ?>>Загрузить</button>

            <button type="button" class="sb-disk__btn" data-action="create-folder" data-permission="canCreateFolder" <?= empty($arResult['PERMISSIONS']['canCreateFolder']) ? 'hidden' : '' ?>>Новая папка</button>

            <div class="sb-disk__view-switch">
                <button type="button" class="sb-disk__view-btn is-active" data-view="table">Таблица</button>
                <button type="button" class="sb-disk__view-btn" data-view="grid">Плитка</button>
            </div>
        </div>
    </div>

    <div class="sb-disk__capacity" data-role="disk-capacity" hidden>
        <div class="sb-disk__capacity-summary" role="status" aria-live="polite" aria-atomic="true">
            <strong data-role="capacity-used"></strong>
            <span data-role="capacity-available"></span>
        </div>
        <progress class="sb-disk__capacity-progress" data-role="capacity-progress" max="100" value="0" aria-label="Заполнение диска" hidden></progress>
    </div>
    <div class="sb-disk__upload-notice" data-role="upload-notice" role="status" aria-live="polite" aria-atomic="true" hidden></div>

    <div class="sb-disk__bulkbar" data-role="bulkbar" hidden>
        <span class="sb-disk__bulkbar-text" data-role="bulkbar-text">Выбрано: 0</span>
        <div class="sb-disk__bulkbar-actions">
            <button type="button" class="sb-disk__btn" data-action="download-selected" data-permission="canDownload">Скачать</button>
            <button type="button" class="sb-disk__btn sb-disk__btn--danger" data-action="delete-selected" data-permission="canDelete">Удалить</button>
        </div>
    </div>

    <div class="sb-disk__content">
        <div class="sb-disk__state" data-state="loading" hidden>Загрузка...</div>
        <div class="sb-disk__state" data-state="empty" hidden>Здесь пока нет файлов и папок.</div>
        <div class="sb-disk__state" data-state="error" hidden>Не удалось загрузить содержимое.</div>
        <div class="sb-disk__state" data-state="no-access" hidden>У вас нет доступа к этому разделу.</div>
        <div class="sb-disk__state" data-state="no-root" hidden>
            Для блока не настроена корневая папка.
            <?php if (!empty($arResult['PERMISSIONS']['canEditSettings'])): ?>
                <div class="sb-disk__state-actions">
                    <button type="button" class="sb-disk__btn" data-action="init-site-root">Создать корень сайта</button>
                    <button type="button" class="sb-disk__btn" data-action="init-block-root">Создать папку блока</button>
                </div>
            <?php endif; ?>
        </div>

        <div class="sb-disk__view sb-disk__view--table" data-view-container="table">
            <table class="sb-disk__table">
                <thead>
                    <tr>
                        <th class="sb-disk__col sb-disk__col--checkbox">
                            <input type="checkbox" data-role="select-all">
                        </th>
                        <th class="sb-disk__col sb-disk__col--name">Название</th>
                        <th class="sb-disk__col">Тип</th>
                        <th class="sb-disk__col">Размер</th>
                        <th class="sb-disk__col">Изменен</th>
                        <th class="sb-disk__col sb-disk__col--actions"></th>
                    </tr>
                </thead>
                <tbody data-role="items-table"></tbody>
            </table>
        </div>

        <div class="sb-disk__view sb-disk__view--grid" data-view-container="grid" hidden></div>
    </div>

    <input type="file" class="sb-disk__file-input" data-role="upload-input" multiple hidden>

    <?php if (!empty($arResult['PERMISSIONS']['canEditSettings'])): ?>
        <div class="sb-disk-modal sb-disk-settings-native" data-role="settings-modal" hidden>
            <div class="sb-disk-modal__backdrop" data-action="close-settings"></div>

            <div class="sb-disk-modal__dialog sb-disk-settings-native__dialog" role="dialog" aria-modal="true" aria-labelledby="sb-disk-settings-title">
                <div class="sb-disk-settings-native__header">
                    <div>
                        <div class="sb-disk-settings-native__eyebrow">Файловый блок</div>
                        <h3 class="sb-disk-settings-native__title" id="sb-disk-settings-title">Настройки «Диска»</h3>
                        <div class="sb-disk-settings-native__subtitle">
                            Отображение, загрузка файлов и доступ пользователей.
                        </div>
                    </div>

                    <button type="button" class="sb-disk-settings-native__close" data-action="close-settings" aria-label="Закрыть">×</button>
                </div>

                <div class="sb-disk-settings-native__layout">
                    <aside class="sb-disk-settings-native__sidebar">
                        <nav class="sb-disk-settings-native__nav" aria-label="Разделы настроек">
                            <button type="button" class="sb-disk-settings-native__tab is-active" data-settings-tab="main">
                                <span class="sb-disk-settings-native__tab-icon">▣</span>
                                <span>Основное</span>
                            </button>

                            <button type="button" class="sb-disk-settings-native__tab" data-settings-tab="upload">
                                <span class="sb-disk-settings-native__tab-icon">⇧</span>
                                <span>Загрузка</span>
                            </button>

                            <button type="button" class="sb-disk-settings-native__tab" data-settings-tab="access">
                                <span class="sb-disk-settings-native__tab-icon">●</span>
                                <span>Доступ</span>
                            </button>
                        </nav>

                        <div class="sb-disk-settings-native__note">
                            <strong>Важно</strong>
                            <span>Настройки блока не могут дать пользователю больше прав, чем разрешено в Bitrix24.</span>
                        </div>
                    </aside>

                    <div class="sb-disk-settings-native__content">
                        <form class="sb-disk-form sb-disk-settings-native__form" data-role="settings-form">
                            <section class="sb-disk-settings-native__panel" data-settings-panel="main">
                                <div class="sb-disk-settings-native__panel-head">
                                    <h4>Основные настройки</h4>
                                    <p>Как блок называется, какую папку открывает и как отображает файлы.</p>
                                </div>

                                <div class="sb-disk-settings-native__card">
                                    <div class="sb-disk-settings-native__section-title">Отображение</div>

                                    <div class="sb-disk-settings-native__grid">
                                        <div class="sb-disk-form__field sb-disk-settings-native__field--full">
                                            <label class="sb-disk-form__label">Название диска</label>
                                            <input type="text" class="sb-disk-form__input" name="title" maxlength="100">
                                            <div class="sb-disk-form__hint">Название связано с папкой на портале. Диски с общей папкой используют одно название.</div>
                                        </div>

                                        <div class="sb-disk-form__field">
                                            <label class="sb-disk-form__label">Корневая папка</label>
                                            <select class="sb-disk-form__select" name="rootFolderId" data-role="root-select">
                                                <option value="">Использовать папку сайта</option>
                                                <option value="__create_block_root__">Создать отдельную папку блока</option>
                                            </select>
                                            <div class="sb-disk-form__hint">Папка Bitrix.Диска, открываемая пользователю.</div>
                                        </div>

                                        <div class="sb-disk-form__field">
                                            <label class="sb-disk-form__label">Вид по умолчанию</label>
                                            <select class="sb-disk-form__select" name="viewMode">
                                                <option value="table">Таблица</option>
                                                <option value="grid">Плитка</option>
                                            </select>
                                            <div class="sb-disk-form__hint">Таблица или визуальная плитка.</div>
                                        </div>

                                        <div class="sb-disk-form__field">
                                            <label class="sb-disk-form__label">Сортировать по</label>
                                            <select class="sb-disk-form__select" name="defaultSort">
                                                <option value="updatedAt">Дата изменения</option>
                                                <option value="createdAt">Дата создания</option>
                                                <option value="name">Имя</option>
                                                <option value="size">Размер</option>
                                            </select>
                                        </div>

                                        <div class="sb-disk-form__field">
                                            <label class="sb-disk-form__label">Направление</label>
                                            <select class="sb-disk-form__select" name="defaultSortDirection">
                                                <option value="desc">По убыванию</option>
                                                <option value="asc">По возрастанию</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="sb-disk-settings-native__panel" data-settings-panel="upload" hidden>
                                <div class="sb-disk-settings-native__panel-head">
                                    <h4>Загрузка файлов</h4>
                                    <p>Ограничения для файлов, загружаемых через этот блок.</p>
                                </div>

                                <div class="sb-disk-settings-native__card">
                                    <div class="sb-disk-settings-native__grid">
                                        <div class="sb-disk-form__field">
                                            <label class="sb-disk-form__label">Максимальный размер файла</label>
                                            <div class="sb-disk-settings-native__input-unit">
                                                <input type="number" class="sb-disk-form__input" name="maxFileSizeMb" min="1" max="2048" step="1">
                                                <span>МБ</span>
                                            </div>
                                            <div class="sb-disk-form__hint">Лимит одного файла.</div>
                                        </div>

                                        <div class="sb-disk-form__field">
                                            <label class="sb-disk-form__label">Максимальный размер диска</label>
                                            <div class="sb-disk-settings-native__input-unit">
                                                <input type="number" class="sb-disk-form__input" name="maxDiskSizeMb" min="0" step="any">
                                                <span>МБ</span>
                                            </div>
                                            <div class="sb-disk-form__hint">Общий объём файлов в корневой папке и подпапках. 0 — без ограничения.</div>
                                        </div>

                                        <div class="sb-disk-form__field sb-disk-settings-native__field--full">
                                            <label class="sb-disk-form__label">Допустимые расширения</label>
                                            <input type="text" class="sb-disk-form__input" name="allowedExtensions" placeholder="pdf doc docx xls xlsx png jpg">
                                            <div class="sb-disk-settings-native__preset-row">
                                                <button type="button" data-extension-preset="documents">Документы</button>
                                                <button type="button" data-extension-preset="images">Изображения</button>
                                                <button type="button" data-extension-preset="all">Документы + изображения</button>
                                            </div>
                                            <div class="sb-disk-form__hint">Можно вводить через пробел, запятую или точку с запятой.</div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="sb-disk-settings-native__panel" data-settings-panel="access" hidden>
                                <div class="sb-disk-settings-native__panel-head">
                                    <h4>Доступ и возможности</h4>
                                    <p>Какие действия доступны пользователю внутри файлового блока.</p>
                                </div>

                                <div class="sb-disk-settings-native__card">
                                    <label class="sb-disk-settings-native__switch">
                                        <span><strong>Проверять права на папки</strong><small>Включено: видны только разрешённые папки. Выключено: вся структура доступна пользователям с допуском к Диску на этой странице.</small></span>
                                        <input type="checkbox" name="requireFolderAccess" value="1">
                                        <i></i>
                                    </label>
                                    <div class="sb-disk-form__field">
                                        <label class="sb-disk-form__label">Режим прав</label>
                                        <select class="sb-disk-form__select" name="permissionMode">
                                            <option value="inherit_site">Наследовать права сайта</option>
                                            <option value="custom">Индивидуальные права папок SiteBuilder</option>
                                            <option value="bitrix_disk">Точные права Битрикс24.Диск</option>
                                        </select>
                                        <div class="sb-disk-form__hint">Права папки наследуются подпапками. Для выборочного доступа назначайте права нужным папкам; разрешение на корень откроет его подпапки. Точный режим использует штатные права Битрикс24.Диск.</div>
                                    </div>

                                    <div class="sb-disk-settings-native__quick-presets">
                                        <span>Быстрый выбор:</span>
                                        <button type="button" data-access-preset="view">Только просмотр</button>
                                        <button type="button" data-access-preset="edit">Работа без удаления</button>
                                        <button type="button" data-access-preset="all">Все действия</button>
                                    </div>

                                    <div class="sb-disk-settings-native__switch-grid">
                                        <label class="sb-disk-settings-native__switch">
                                            <span><strong>Папка сайта как резервная</strong><small>Использовать папку сайта, если отдельная папка блока недоступна.</small></span>
                                            <input type="checkbox" name="useSiteRootFallback" value="1">
                                            <i></i>
                                        </label>

                                        <label class="sb-disk-settings-native__switch">
                                            <span><strong>Загрузка файлов</strong><small>Разрешить добавление новых файлов.</small></span>
                                            <input type="checkbox" name="allowUpload" value="1">
                                            <i></i>
                                        </label>

                                        <label class="sb-disk-settings-native__switch">
                                            <span><strong>Создание папок</strong><small>Разрешить создавать новые папки.</small></span>
                                            <input type="checkbox" name="allowCreateFolder" value="1">
                                            <i></i>
                                        </label>

                                        <label class="sb-disk-settings-native__switch">
                                            <span><strong>Переименование</strong><small>Разрешить менять имена файлов и папок.</small></span>
                                            <input type="checkbox" name="allowRename" value="1">
                                            <i></i>
                                        </label>

                                        <label class="sb-disk-settings-native__switch">
                                            <span><strong>Удаление</strong><small>Разрешить удалять файлы и папки.</small></span>
                                            <input type="checkbox" name="allowDelete" value="1">
                                            <i></i>
                                        </label>

                                        <label class="sb-disk-settings-native__switch">
                                            <span><strong>Скачивание</strong><small>Разрешить скачивать файлы.</small></span>
                                            <input type="checkbox" name="allowDownload" value="1">
                                            <i></i>
                                        </label>

                                        <label class="sb-disk-settings-native__switch">
                                            <span><strong>Поиск</strong><small>Показывать строку поиска по файлам.</small></span>
                                            <input type="checkbox" name="showSearch" value="1">
                                            <i></i>
                                        </label>

                                        <label class="sb-disk-settings-native__switch">
                                            <span><strong>Навигационная цепочка</strong><small>Показывать путь к текущей папке.</small></span>
                                            <input type="checkbox" name="showBreadcrumbs" value="1">
                                            <i></i>
                                        </label>
                                    </div>
                                </div>
                            </section>
                        </form>

                        <div class="sb-disk-modal__message sb-disk-settings-native__message" data-role="settings-message"></div>
                    </div>
                </div>

                <div class="sb-disk-settings-native__footer">
                    <span>Изменения применятся после сохранения.</span>

                    <div>
                        <button type="button" class="sb-disk__btn sb-disk__btn--ghost" data-action="close-settings">Отмена</button>
                        <button type="button" class="sb-disk__btn" data-action="save-settings">Сохранить</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($arResult['PERMISSIONS']['canManageAccess'])): ?>
        <div class="sb-disk-modal" data-role="folder-access-modal" hidden>
            <div class="sb-disk-modal__backdrop" data-action="close-folder-access"></div>
            <div class="sb-disk-modal__dialog sb-disk-folder-access" role="dialog" aria-modal="true" aria-label="Права текущей папки">
                <div class="sb-disk-modal__header">
                    <div>
                        <h3 class="sb-disk-modal__title">Права текущей папки</h3>
                        <div class="sb-disk-form__hint" data-role="folder-access-folder"></div>
                    </div>
                    <button type="button" class="sb-disk-modal__close" data-action="close-folder-access" aria-label="Закрыть права папки">×</button>
                </div>

                <div class="sb-disk-modal__body">
                    <div class="sb-disk-folder-access__warning" data-role="folder-access-warning" hidden>
                        Права этого окна сейчас не применяются. В настройках блока выберите «Индивидуальные права папок SiteBuilder» и сохраните настройки.
                        <button type="button" class="sb-disk__btn sb-disk__btn--ghost" data-action="folder-access-settings">Открыть настройки доступа</button>
                    </div>

                    <section class="sb-disk-bulk">
                        <h4 class="sb-disk-bulk__title">Добавить пользователей списком</h4>
                        <div data-role="bulk-access-source">
                        <label class="sb-disk-bulk__label">
                            <span>1. Вставьте ФИО, логины или ID — по одному на строку</span>
                            <textarea class="sb-disk-form__input sb-disk-bulk__input" data-role="bulk-access-input" rows="5" maxlength="60000" placeholder="Иванов Иван Иванович&#10;petrova.aa&#10;ID 125"></textarea>
                        </label>
                        <div class="sb-disk-bulk__toolbar">
                            <span class="sb-disk-form__hint">До 200 строк. Можно вставить столбец из Excel.</span>
                            <button type="button" class="sb-disk__btn" data-action="bulk-access-resolve">Найти пользователей</button>
                        </div>
                        </div>
                        <div data-role="bulk-access-review" hidden>
                            <div class="sb-disk-bulk__toolbar">
                                <strong>2. Проверьте найденных пользователей</strong>
                                <button type="button" class="sb-disk__btn sb-disk__btn--ghost" data-action="bulk-access-toggle">Снять выбор</button>
                            </div>
                            <button type="button" class="sb-disk__btn sb-disk__btn--ghost" data-action="bulk-access-edit">Изменить исходный список</button>
                            <p class="sb-disk-form__hint">У тёзок выберите нужный логин. Ненайденные и невыбранные строки не применяются.</p>
                            <div class="sb-disk-bulk__rows" data-role="bulk-access-rows"></div>
                            <div class="sb-disk-bulk__toolbar">
                                <label class="sb-disk-bulk__label">
                                    <span>3. Право для выбранных пользователей</span>
                                    <select class="sb-disk-form__select" data-role="bulk-access-role">
                                        <option value="VIEWER">Просмотр и скачивание</option>
                                        <option value="EDITOR">Редактирование содержимого</option>
                                        <option value="DENY">Нет доступа</option>
                                        <option value="INHERIT">Наследовать — удалить личное правило</option>
                                    </select>
                                </label>
                                <button type="button" class="sb-disk__btn" data-action="bulk-access-apply" disabled>Применить выбранным (0)</button>
                            </div>
                            <p class="sb-disk-form__hint" data-role="bulk-access-summary"></p>
                        </div>
                        <p class="sb-disk-bulk__status" data-role="bulk-access-status" role="status" aria-live="polite"></p>
                        <p class="sb-disk-form__hint">Меняются права текущей папки. Вложенные папки наследуют их, если у них нет собственных правил. Пользователю также нужен доступ к странице и Диску. Администраторы и владельцы сохраняют полный доступ.</p>
                    </section>

                    <details class="sb-disk-bulk__single">
                        <summary>Добавить одного пользователя</summary>
                    <div class="sb-disk-folder-access__search">
                        <input type="search" class="sb-disk-form__input" data-role="folder-access-query" placeholder="ФИО, логин, email или ID">
                        <button type="button" class="sb-disk__btn" data-action="search-folder-access-user">Найти</button>
                    </div>
                    <div class="sb-disk-folder-access__results" data-role="folder-access-results"></div>

                    <div class="sb-disk-folder-access__editor" data-role="folder-access-editor" hidden>
                        <strong data-role="folder-access-selected-user"></strong>
                        <select class="sb-disk-form__select" data-role="folder-access-role">
                            <option value="VIEWER">Просмотр и скачивание</option>
                            <option value="EDITOR">Редактирование содержимого</option>
                            <option value="DENY">Нет доступа</option>
                        </select>
                        <button type="button" class="sb-disk__btn" data-action="save-folder-access">Сохранить</button>
                    </div>
                    </details>

                    <div class="sb-disk-bulk__toolbar">
                        <strong>Текущие правила папки</strong>
                        <button type="button" class="sb-disk__btn sb-disk__btn--ghost" data-action="folder-access-refresh">Перечитать права</button>
                    </div>
                    <div class="sb-disk-folder-access__list" data-role="folder-access-list"></div>
                    <div class="sb-disk-modal__message" data-role="folder-access-message"></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
