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

            <?php if (!empty($arResult['PERMISSIONS']['canEditSettings'])): ?>
                <button type="button" class="sb-disk__btn sb-disk__btn--ghost" data-action="settings">Настройки</button>
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
            <?php if (!empty($arResult['PERMISSIONS']['canUpload'])): ?>
                <button type="button" class="sb-disk__btn" data-action="upload">Загрузить</button>
            <?php endif; ?>

            <?php if (!empty($arResult['PERMISSIONS']['canCreateFolder'])): ?>
                <button type="button" class="sb-disk__btn" data-action="create-folder">Новая папка</button>
            <?php endif; ?>

            <div class="sb-disk__view-switch">
                <button type="button" class="sb-disk__view-btn is-active" data-view="table">Таблица</button>
                <button type="button" class="sb-disk__view-btn" data-view="grid">Плитка</button>
            </div>
        </div>
    </div>

    <div class="sb-disk__bulkbar" data-role="bulkbar" hidden>
        <span class="sb-disk__bulkbar-text" data-role="bulkbar-text">Выбрано: 0</span>
        <div class="sb-disk__bulkbar-actions">
            <button type="button" class="sb-disk__btn" data-action="download-selected">Скачать</button>
            <button type="button" class="sb-disk__btn sb-disk__btn--danger" data-action="delete-selected">Удалить</button>
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
        <div class="sb-disk-modal" data-role="settings-modal" hidden>
            <div class="sb-disk-modal__backdrop" data-action="close-settings"></div>
            <div class="sb-disk-modal__dialog">
                <div class="sb-disk-modal__header">
                    <h3 class="sb-disk-modal__title">Настройки блока “Диск”</h3>
                    <button type="button" class="sb-disk-modal__close" data-action="close-settings">×</button>
                </div>

                <div class="sb-disk-modal__body">
                    <form class="sb-disk-form" data-role="settings-form">
                        <div class="sb-disk-form__grid">
                            <div class="sb-disk-form__field">
                                <label class="sb-disk-form__label">Заголовок блока</label>
                                <input type="text" class="sb-disk-form__input" name="title">
                            </div>

                            <div class="sb-disk-form__field">
                                <label class="sb-disk-form__label">Источник корня</label>
                                <select class="sb-disk-form__select" name="rootFolderId" data-role="root-select">
                                    <option value="">Использовать корень сайта</option>
                                </select>
                            </div>

                            <div class="sb-disk-form__field">
                                <label class="sb-disk-form__label">Вид по умолчанию</label>
                                <select class="sb-disk-form__select" name="viewMode">
                                    <option value="table">Таблица</option>
                                    <option value="grid">Плитка</option>
                                </select>
                            </div>

                            <div class="sb-disk-form__field">
                                <label class="sb-disk-form__label">Сортировка по умолчанию</label>
                                <select class="sb-disk-form__select" name="defaultSort">
                                    <option value="updatedAt">Дата изменения</option>
                                    <option value="createdAt">Дата создания</option>
                                    <option value="name">Имя</option>
                                    <option value="size">Размер</option>
                                </select>
                            </div>

                            <div class="sb-disk-form__field">
                                <label class="sb-disk-form__label">Направление сортировки</label>
                                <select class="sb-disk-form__select" name="defaultSortDirection">
                                    <option value="desc">По убыванию</option>
                                    <option value="asc">По возрастанию</option>
                                </select>
                            </div>

                            <div class="sb-disk-form__field">
                                <label class="sb-disk-form__label">Максимальный размер файла (байт)</label>
                                <input type="number" class="sb-disk-form__input" name="maxFileSize" min="0">
                            </div>

                            <div class="sb-disk-form__field sb-disk-form__field--full">
                                <label class="sb-disk-form__label">Допустимые расширения</label>
                                <input type="text" class="sb-disk-form__input" name="allowedExtensions" placeholder="pdf doc docx xlsx png jpg">
                                <div class="sb-disk-form__hint">Через пробел, запятую или точку с запятой</div>
                            </div>

                            <div class="sb-disk-form__field">
                                <label class="sb-disk-form__label">Режим прав</label>
                                <select class="sb-disk-form__select" name="permissionMode">
                                    <option value="inherit_site">Наследовать права сайта</option>
                                    <option value="custom">Собственные ограничения блока</option>
                                </select>
                            </div>

                            <div class="sb-disk-form__field">
                                <label class="sb-disk-form__check">
                                    <input type="checkbox" name="useSiteRootFallback" value="1">
                                    <span>Использовать корень сайта, если у блока нет своей папки</span>
                                </label>
                            </div>
                        </div>

                        <div class="sb-disk-form__checks">
                            <label class="sb-disk-form__check"><input type="checkbox" name="allowUpload" value="1"><span>Разрешить загрузку</span></label>
                            <label class="sb-disk-form__check"><input type="checkbox" name="allowCreateFolder" value="1"><span>Разрешить создание папок</span></label>
                            <label class="sb-disk-form__check"><input type="checkbox" name="allowRename" value="1"><span>Разрешить переименование</span></label>
                            <label class="sb-disk-form__check"><input type="checkbox" name="allowDelete" value="1"><span>Разрешить удаление</span></label>
                            <label class="sb-disk-form__check"><input type="checkbox" name="allowDownload" value="1"><span>Разрешить скачивание</span></label>
                            <label class="sb-disk-form__check"><input type="checkbox" name="showSearch" value="1"><span>Показывать поиск</span></label>
                            <label class="sb-disk-form__check"><input type="checkbox" name="showBreadcrumbs" value="1"><span>Показывать breadcrumbs</span></label>
                        </div>
                    </form>

                    <div class="sb-disk-modal__message" data-role="settings-message"></div>
                </div>

                <div class="sb-disk-modal__footer">
                    <button type="button" class="sb-disk__btn sb-disk__btn--ghost" data-action="close-settings">Отмена</button>
                    <button type="button" class="sb-disk__btn" data-action="save-settings">Сохранить</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>