/* =========================================================
   SITEBUILDER / DISK SETTINGS 2.0 / v5 VERSION HOTFIX
   Full clean-shell UI. The legacy form stays hidden and is used only
   as the transport/save mechanism for backward compatibility.
   ========================================================= */
(function () {
    'use strict';

    var MARKER = 'sbDiskSettingsV4';
    var scheduled = false;

    var FIELD_DEFS = [
        ['title', ['заголовок блока'], ['title', 'blocktitle']],
        ['rootSource', ['источник корня'], ['rootsource', 'root_source', 'rootmode']],
        ['viewMode', ['вид по умолчанию'], ['viewmode', 'view_mode']],
        ['sortBy', ['сортировка по умолчанию'], ['sortby', 'sort_by']],
        ['sortDirection', ['направление сортировки'], ['sortdirection', 'sort_direction', 'sortdir']],
        ['maxFileSize', ['максимальный размер файла', 'максимальный размер'], ['maxfilesize', 'max_file_size']],
        ['extensions', ['допустимые расширения'], ['extensions', 'allowedextensions', 'allowed_extensions']],
        ['permissionMode', ['режим прав'], ['permissionmode', 'permission_mode']],
        ['useSiteRoot', ['использовать корень сайта'], ['usesiteroot', 'use_site_root']],
        ['allowUpload', ['разрешить загрузку'], ['allowupload', 'allow_upload']],
        ['allowCreateFolder', ['разрешить создание папок'], ['allowcreatefolder', 'allow_create_folder']],
        ['allowRename', ['разрешить переименование'], ['allowrename', 'allow_rename']],
        ['allowDelete', ['разрешить удаление'], ['allowdelete', 'allow_delete']],
        ['allowDownload', ['разрешить скачивание'], ['allowdownload', 'allow_download']],
        ['showSearch', ['показывать поиск'], ['showsearch', 'show_search']],
        ['showBreadcrumbs', ['показывать breadcrumbs', 'показывать хлебные крошки'], ['showbreadcrumbs', 'show_breadcrumbs']]
    ];

    function normalize(value) {
        return String(value || '')
            .replace(/\u00a0/g, ' ')
            .replace(/[«»“”"]/g, '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function attrText(control) {
        if (!control) return '';
        return normalize([
            control.id,
            control.name,
            control.getAttribute('data-name'),
            control.getAttribute('data-key'),
            control.getAttribute('data-field'),
            control.getAttribute('placeholder'),
            control.getAttribute('aria-label')
        ].filter(Boolean).join(' ')).replace(/\s+/g, '');
    }

    function isDiskHeading(node) {
        var text = normalize(node && node.textContent);
        return text.indexOf('настройки блока') !== -1 && text.indexOf('диск') !== -1;
    }

    function findHeading() {
        return Array.prototype.slice.call(
            document.querySelectorAll('h1,h2,h3,h4,[role="heading"],.modal-title,.popup-window-titlebar-text')
        ).find(isDiskHeading) || null;
    }

    function findScope(heading) {
        if (!heading) return null;

        var node = heading;
        var best = heading.parentElement;

        for (var i = 0; i < 11 && node && node !== document.body; i++) {
            if (node.querySelectorAll) {
                var controls = node.querySelectorAll('input:not([type="hidden"]),select,textarea');
                var buttons = node.querySelectorAll('button,input[type="submit"],input[type="button"]');
                if (controls.length >= 8 && buttons.length >= 1) {
                    best = node;
                }
            }
            node = node.parentElement;
        }

        return best;
    }

    function labelText(control, scope) {
        var label = control.closest ? control.closest('label') : null;

        if (!label && control.id && scope) {
            try {
                label = scope.querySelector('label[for="' + CSS.escape(control.id) + '"]');
            } catch (error) {
                /* Old browsers: use structural fallback below. */
            }
        }

        if (label) {
            return normalize(label.textContent);
        }

        var node = control.parentElement;
        for (var i = 0; i < 3 && node && node !== scope; i++) {
            var own = normalize(node.textContent);
            if (own && own.length < 220) {
                return own;
            }
            node = node.parentElement;
        }

        return '';
    }

    function matchField(control, scope) {
        var text = labelText(control, scope);
        var attrs = attrText(control);

        for (var i = 0; i < FIELD_DEFS.length; i++) {
            var def = FIELD_DEFS[i];
            var labels = def[1];
            var hints = def[2];

            for (var j = 0; j < labels.length; j++) {
                if (text.indexOf(labels[j]) !== -1) return def[0];
            }

            for (var k = 0; k < hints.length; k++) {
                if (attrs.indexOf(hints[k].replace(/[_\s]/g, '')) !== -1) return def[0];
            }
        }

        return '';
    }

    function collectNative(scope) {
        var result = {};
        var controls = Array.prototype.slice.call(
            scope.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]),select,textarea')
        );

        controls.forEach(function (control) {
            var name = matchField(control, scope);
            if (name && !result[name]) {
                result[name] = control;
            }
        });

        return result;
    }

    function buttonText(button) {
        return normalize(
            button && (
                button.textContent
                || button.value
                || button.getAttribute('aria-label')
                || button.getAttribute('title')
            )
        );
    }

    function findLegacyActions(scope) {
        var buttons = Array.prototype.slice.call(
            scope.querySelectorAll('button,input[type="submit"],input[type="button"]')
        );

        var save = buttons.find(function (button) {
            return buttonText(button).indexOf('сохран') !== -1;
        }) || null;

        var close = buttons.find(function (button) {
            var raw = String(button.textContent || '').trim();
            var text = buttonText(button);
            return raw === '×' || raw === '✕' || text === 'x' || text.indexOf('закрыть') !== -1;
        }) || null;

        var cancel = buttons.find(function (button) {
            if (button === save || button === close) return false;
            return buttonText(button).indexOf('отмен') !== -1;
        }) || null;

        return {save: save, close: close, cancel: cancel};
    }

    /*
     * IMPORTANT:
     *
     * The v4 mirror UI dispatched input/change into the legacy Disk form
     * every time a user edited a mirror field. Legacy listeners can react
     * to those events and update the block before the final Save click.
     *
     * Result:
     *   settings dialog opened at block version 75
     *   mirror edits advanced the block to version 78
     *   final saveSettings still sent expectedVersion=75
     *   => VERSION_CONFLICT
     *
     * The mirror is now draft-only. We update the native form values
     * silently and invoke the existing legacy Save button exactly once.
     */
    function setNativeValue(control, value) {
        if (!control) return;

        if (control.type === 'checkbox') {
            control.checked = !!value;
            return;
        }

        control.value = String(
            value == null
                ? ''
                : value
        );
    }

    function optionData(native) {
        if (!native || native.tagName !== 'SELECT') return [];
        return Array.prototype.slice.call(native.options).map(function (option) {
            return {value: option.value, label: String(option.textContent || option.value).trim()};
        });
    }

    function createField(label, control, help) {
        var field = document.createElement('label');
        field.className = 'sb-disk4-field';

        var title = document.createElement('span');
        title.className = 'sb-disk4-label';
        title.textContent = label;
        field.appendChild(title);
        field.appendChild(control);

        if (help) {
            var small = document.createElement('small');
            small.className = 'sb-disk4-help';
            small.textContent = help;
            field.appendChild(small);
        }

        return field;
    }

    function createInput(native, type, placeholder) {
        var input = document.createElement('input');
        input.type = type || 'text';
        input.className = 'sb-disk4-input';
        input.value = native ? native.value : '';
        if (placeholder) input.placeholder = placeholder;
        input.addEventListener('input', function () {
            setNativeValue(native, input.value);
        });
        return input;
    }

    function createSelect(native) {
        var select = document.createElement('select');
        select.className = 'sb-disk4-select';

        optionData(native).forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.value;
            option.textContent = item.label;
            select.appendChild(option);
        });

        if (native) select.value = native.value;
        select.addEventListener('change', function () {
            setNativeValue(native, select.value);
            refreshSummary();
        });
        return select;
    }

    function createSwitch(native, title, description) {
        var label = document.createElement('label');
        label.className = 'sb-disk4-switch';

        var copy = document.createElement('span');
        copy.className = 'sb-disk4-switch__copy';

        var strong = document.createElement('strong');
        strong.textContent = title;
        var small = document.createElement('small');
        small.textContent = description;
        copy.appendChild(strong);
        copy.appendChild(small);

        var input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = native ? !!native.checked : false;

        var ui = document.createElement('span');
        ui.className = 'sb-disk4-switch__ui';

        input.addEventListener('change', function () {
            setNativeValue(native, input.checked);
            refreshSummary();
        });

        label.appendChild(copy);
        label.appendChild(input);
        label.appendChild(ui);
        return {root: label, input: input};
    }

    function parseExtensions(value) {
        var seen = {};
        return String(value || '')
            .toLowerCase()
            .split(/[\s,;]+/)
            .map(function (item) {
                return item.trim().replace(/^\.+/, '').replace(/[^a-z0-9_-]/g, '');
            })
            .filter(function (item) {
                if (!item || seen[item]) return false;
                seen[item] = true;
                return true;
            });
    }

    var state = {
        scope: null,
        native: {},
        actions: {},
        mirrors: {},
        root: null,
        summary: null,
        activeTab: 'general'
    };

    function mirrorText(name) {
        var mirror = state.mirrors[name];
        if (!mirror) return '—';
        if (mirror.tagName === 'SELECT') {
            return mirror.options[mirror.selectedIndex]
                ? String(mirror.options[mirror.selectedIndex].textContent).trim()
                : '—';
        }
        if (mirror.type === 'checkbox') return mirror.checked ? 'Да' : 'Нет';
        return String(mirror.value || '—').trim();
    }

    function refreshSummary() {
        if (!state.summary) return;
        var mb = state.mirrors.maxFileSizeMb ? state.mirrors.maxFileSizeMb.value + ' МБ' : '—';
        state.summary.innerHTML = '';
        [
            ['Корень', mirrorText('rootSource')],
            ['Вид', mirrorText('viewMode')],
            ['Права', mirrorText('permissionMode')],
            ['Файл', mb]
        ].forEach(function (item) {
            var node = document.createElement('div');
            node.className = 'sb-disk4-summary-item';
            node.innerHTML = '<span></span><strong></strong>';
            node.querySelector('span').textContent = item[0];
            node.querySelector('strong').textContent = item[1];
            state.summary.appendChild(node);
        });
    }

    function tabButton(id, label, icon) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'sb-disk4-nav__item';
        button.dataset.tab = id;
        button.innerHTML = '<span class="sb-disk4-nav__icon"></span><span class="sb-disk4-nav__text"></span>';
        button.querySelector('.sb-disk4-nav__icon').textContent = icon;
        button.querySelector('.sb-disk4-nav__text').textContent = label;
        button.addEventListener('click', function () { activateTab(id); });
        return button;
    }

    function activateTab(id) {
        state.activeTab = id;
        state.root.querySelectorAll('[data-tab]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.tab === id);
        });
        state.root.querySelectorAll('[data-pane]').forEach(function (pane) {
            pane.hidden = pane.dataset.pane !== id;
        });
    }

    function pane(id, title, description) {
        var section = document.createElement('section');
        section.className = 'sb-disk4-pane';
        section.dataset.pane = id;
        var head = document.createElement('header');
        head.className = 'sb-disk4-pane__head';
        var h = document.createElement('h3');
        h.textContent = title;
        var p = document.createElement('p');
        p.textContent = description;
        head.appendChild(h);
        head.appendChild(p);
        section.appendChild(head);
        return section;
    }

    function card(title, description) {
        var box = document.createElement('div');
        box.className = 'sb-disk4-card';
        var head = document.createElement('div');
        head.className = 'sb-disk4-card__head';
        var strong = document.createElement('strong');
        strong.textContent = title;
        var small = document.createElement('small');
        small.textContent = description;
        head.appendChild(strong);
        head.appendChild(small);
        box.appendChild(head);
        return box;
    }

    function buildGeneral() {
        var section = pane('general', 'Основные настройки', 'Как блок называется, какую папку открывает и как отображает файлы.');
        var base = card('Отображение', 'Основные параметры файлового блока.');
        var grid = document.createElement('div');
        grid.className = 'sb-disk4-grid';

        var title = createInput(state.native.title, 'text', 'Файлы');
        state.mirrors.title = title;
        var root = createSelect(state.native.rootSource);
        state.mirrors.rootSource = root;
        var view = createSelect(state.native.viewMode);
        state.mirrors.viewMode = view;
        var sort = createSelect(state.native.sortBy);
        state.mirrors.sortBy = sort;
        var direction = createSelect(state.native.sortDirection);
        state.mirrors.sortDirection = direction;

        var titleField = createField('Заголовок блока', title, 'Отображается над списком файлов.');
        titleField.classList.add('is-span-2');
        grid.appendChild(titleField);
        grid.appendChild(createField('Корневая папка', root, 'Папка Bitrix.Диска, открываемая пользователю.'));
        grid.appendChild(createField('Вид по умолчанию', view, 'Таблица или другой доступный режим отображения.'));
        grid.appendChild(createField('Сортировать по', sort));
        grid.appendChild(createField('Направление', direction));
        base.appendChild(grid);
        section.appendChild(base);
        return section;
    }

    function buildUpload() {
        var section = pane('upload', 'Загрузка файлов', 'Ограничения на размер и расширения файлов, загружаемых через этот блок.');
        var limits = card('Ограничения', 'Настройки действуют только для загрузки через SiteBuilder.');
        var grid = document.createElement('div');
        grid.className = 'sb-disk4-grid';

        var nativeBytes = state.native.maxFileSize;
        var mb = document.createElement('input');
        mb.type = 'number';
        mb.min = '1';
        mb.max = '2048';
        mb.step = '1';
        mb.className = 'sb-disk4-input';
        var bytes = Number(nativeBytes ? nativeBytes.value : 0);
        mb.value = String(bytes > 0 ? Math.max(1, Math.round(bytes / 1048576)) : 50);
        mb.addEventListener('input', function () {
            var value = Math.max(1, Math.min(2048, Number(mb.value || 50)));
            setNativeValue(nativeBytes, Math.round(value * 1048576));
            refreshSummary();
        });
        state.mirrors.maxFileSizeMb = mb;

        var sizeWrap = document.createElement('div');
        sizeWrap.className = 'sb-disk4-unit';
        sizeWrap.appendChild(mb);
        var unit = document.createElement('span');
        unit.textContent = 'МБ';
        sizeWrap.appendChild(unit);

        var ext = createInput(state.native.extensions, 'text', 'pdf doc docx xls xlsx png jpg');
        state.mirrors.extensions = ext;
        ext.addEventListener('blur', function () {
            var items = parseExtensions(ext.value);
            ext.value = items.join(' ');
            setNativeValue(state.native.extensions, ext.value);
            renderExtChips(chips, items);
        });

        grid.appendChild(createField('Максимальный размер файла', sizeWrap, 'Допустимо от 1 до 2048 МБ.'));
        var extField = createField('Допустимые расширения', ext, 'Можно вводить через пробел, запятую или точку с запятой.');
        extField.classList.add('is-span-2');
        grid.appendChild(extField);
        limits.appendChild(grid);

        var presetRow = document.createElement('div');
        presetRow.className = 'sb-disk4-presets';
        [['Документы',['pdf','doc','docx','xls','xlsx','ppt','pptx']],['Изображения',['jpg','jpeg','png','gif','webp']],['Документы + изображения',['pdf','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','gif','webp']]].forEach(function (preset) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = preset[0];
            button.addEventListener('click', function () {
                ext.value = preset[1].join(' ');
                setNativeValue(state.native.extensions, ext.value);
                renderExtChips(chips, preset[1]);
            });
            presetRow.appendChild(button);
        });
        limits.appendChild(presetRow);

        var chips = document.createElement('div');
        chips.className = 'sb-disk4-chips';
        limits.appendChild(chips);
        renderExtChips(chips, parseExtensions(ext.value));
        section.appendChild(limits);
        return section;
    }

    function renderExtChips(target, items) {
        target.innerHTML = '';
        if (!items.length) {
            var empty = document.createElement('span');
            empty.className = 'is-empty';
            empty.textContent = 'Расширения не ограничены';
            target.appendChild(empty);
            return;
        }
        items.forEach(function (item) {
            var chip = document.createElement('span');
            chip.textContent = '.' + item;
            target.appendChild(chip);
        });
    }

    function buildAccess() {
        var section = pane('access', 'Доступ и возможности', 'Какие действия доступны пользователю внутри файлового блока.');
        var modeCard = card('Режим прав', 'Права блока только ограничивают интерфейс и не расширяют серверные права пользователя.');
        var mode = createSelect(state.native.permissionMode);
        state.mirrors.permissionMode = mode;
        modeCard.appendChild(createField('Режим прав', mode));

        var presets = document.createElement('div');
        presets.className = 'sb-disk4-presets';
        presets.innerHTML = '<span>Быстрый режим</span>';
        [
            ['Только просмотр',{allowUpload:false,allowCreateFolder:false,allowRename:false,allowDelete:false,allowDownload:true,showSearch:true,showBreadcrumbs:true}],
            ['Работа без удаления',{allowUpload:true,allowCreateFolder:true,allowRename:true,allowDelete:false,allowDownload:true,showSearch:true,showBreadcrumbs:true}],
            ['Все действия',{allowUpload:true,allowCreateFolder:true,allowRename:true,allowDelete:true,allowDownload:true,showSearch:true,showBreadcrumbs:true}]
        ].forEach(function (preset) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = preset[0];
            button.addEventListener('click', function () {
                Object.keys(preset[1]).forEach(function (name) {
                    var mirror = state.mirrors[name];
                    if (!mirror) return;
                    mirror.checked = !!preset[1][name];
                    setNativeValue(state.native[name], mirror.checked);
                });
                refreshSummary();
            });
            presets.appendChild(button);
        });
        modeCard.appendChild(presets);
        section.appendChild(modeCard);

        var actionCard = card('Разрешённые действия', 'Отключённые действия не показываются пользователю блока.');
        var switchGrid = document.createElement('div');
        switchGrid.className = 'sb-disk4-switch-grid';

        [
            ['useSiteRoot','Использовать корень сайта','Если у блока нет своей папки — использовать корень сайта.'],
            ['allowUpload','Загрузка файлов','Разрешить пользователю загружать новые файлы.'],
            ['allowCreateFolder','Создание папок','Разрешить создавать новые папки.'],
            ['allowRename','Переименование','Разрешить менять имена файлов и папок.'],
            ['allowDelete','Удаление','Разрешить удаление файлов и папок.'],
            ['allowDownload','Скачивание','Разрешить скачивание файлов.'],
            ['showSearch','Поиск','Показывать строку поиска по файлам.'],
            ['showBreadcrumbs','Навигационная цепочка','Показывать текущий путь по папкам.']
        ].forEach(function (def) {
            var sw = createSwitch(state.native[def[0]], def[1], def[2]);
            state.mirrors[def[0]] = sw.input;
            switchGrid.appendChild(sw.root);
        });

        actionCard.appendChild(switchGrid);
        section.appendChild(actionCard);
        return section;
    }

    function createRoot(scope, actions) {
        var root = document.createElement('div');
        root.className = 'sb-disk4-root';

        var header = document.createElement('header');
        header.className = 'sb-disk4-header';
        var heading = document.createElement('div');
        heading.innerHTML = '<div class="sb-disk4-kicker">Файловый блок</div><h2>Настройки «Диска»</h2><p>Отображение, загрузка и доступ к файлам в одном месте.</p>';
        header.appendChild(heading);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'sb-disk4-close';
        close.setAttribute('aria-label', 'Закрыть');
        close.textContent = '×';
        close.addEventListener('click', function () {
            if (actions.close) actions.close.click();
            else if (actions.cancel) actions.cancel.click();
        });
        header.appendChild(close);
        root.appendChild(header);

        var body = document.createElement('div');
        body.className = 'sb-disk4-body';
        root.appendChild(body);

        var sidebar = document.createElement('aside');
        sidebar.className = 'sb-disk4-sidebar';
        body.appendChild(sidebar);

        var summary = document.createElement('div');
        summary.className = 'sb-disk4-summary';
        state.summary = summary;
        sidebar.appendChild(summary);

        var nav = document.createElement('nav');
        nav.className = 'sb-disk4-nav';
        nav.appendChild(tabButton('general','Основное','◫'));
        nav.appendChild(tabButton('upload','Загрузка','⇧'));
        nav.appendChild(tabButton('access','Доступ','◉'));
        sidebar.appendChild(nav);

        var tip = document.createElement('div');
        tip.className = 'sb-disk4-tip';
        tip.innerHTML = '<strong>Важно</strong><span>Настройки блока не могут дать пользователю больше прав, чем разрешено в Bitrix24.</span>';
        sidebar.appendChild(tip);

        var content = document.createElement('main');
        content.className = 'sb-disk4-content';
        content.appendChild(buildGeneral());
        content.appendChild(buildUpload());
        content.appendChild(buildAccess());
        body.appendChild(content);

        var footer = document.createElement('footer');
        footer.className = 'sb-disk4-footer';
        var stateText = document.createElement('span');
        stateText.textContent = 'Изменения применятся после сохранения.';
        footer.appendChild(stateText);

        var buttons = document.createElement('div');
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'sb-disk4-btn sb-disk4-btn--secondary';
        cancel.textContent = 'Отмена';
        cancel.addEventListener('click', function () {
            if (actions.cancel) actions.cancel.click();
            else if (actions.close) actions.close.click();
        });

        var save = document.createElement('button');
        save.type = 'button';
        save.className = 'sb-disk4-btn sb-disk4-btn--primary';
        save.textContent = 'Сохранить';
        save.addEventListener('click', function () {
            /*
             * Make the hidden legacy form an exact snapshot of the mirror
             * immediately before its original save handler runs.
             *
             * No input/change events are dispatched here. The original
             * saveSettings handler reads these form controls and performs
             * one optimistic-locking update.
             */
            [
                'title',
                'rootSource',
                'viewMode',
                'sortBy',
                'sortDirection',
                'permissionMode'
            ].forEach(function (name) {
                if (
                    state.native[name]
                    && state.mirrors[name]
                ) {
                    setNativeValue(
                        state.native[name],
                        state.mirrors[name].value
                    );
                }
            });

            [
                'useSiteRoot',
                'allowUpload',
                'allowCreateFolder',
                'allowRename',
                'allowDelete',
                'allowDownload',
                'showSearch',
                'showBreadcrumbs'
            ].forEach(function (name) {
                if (
                    state.native[name]
                    && state.mirrors[name]
                ) {
                    setNativeValue(
                        state.native[name],
                        state.mirrors[name].checked
                    );
                }
            });

            var mb =
                state.mirrors.maxFileSizeMb;

            if (mb) {
                var value =
                    Math.max(
                        1,
                        Math.min(
                            2048,
                            Number(
                                mb.value || 50
                            )
                        )
                    );

                mb.value =
                    String(value);

                setNativeValue(
                    state.native.maxFileSize,
                    Math.round(
                        value * 1048576
                    )
                );
            }

            if (state.mirrors.extensions) {
                state.mirrors.extensions.value =
                    parseExtensions(
                        state.mirrors.extensions.value
                    ).join(' ');

                setNativeValue(
                    state.native.extensions,
                    state.mirrors.extensions.value
                );
            }

            if (actions.save) {
                actions.save.click();
            }
        });

        buttons.appendChild(cancel);
        buttons.appendChild(save);
        footer.appendChild(buttons);
        root.appendChild(footer);

        return root;
    }

    function hideLegacy(scope, root) {
        Array.prototype.slice.call(scope.children).forEach(function (child) {
            if (child !== root) {
                child.classList.add('sb-disk4-legacy-hidden');
            }
        });
    }

    function decorate() {
        var heading = findHeading();
        if (!heading) return;

        var scope = findScope(heading);
        if (!scope || scope.dataset[MARKER] === '1') return;

        var native = collectNative(scope);
        var actions = findLegacyActions(scope);
        if (Object.keys(native).length < 7 || !actions.save) return;

        scope.dataset[MARKER] = '1';
        scope.classList.add('sb-disk4-dialog');
        state.scope = scope;
        state.native = native;
        state.actions = actions;

        var root = createRoot(scope, actions);
        state.root = root;
        scope.appendChild(root);
        hideLegacy(scope, root);
        refreshSummary();
        activateTab('general');

        root.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault();
                root.querySelector('.sb-disk4-btn--primary').click();
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                root.querySelector('.sb-disk4-close').click();
            }
        });
    }

    function schedule() {
        if (scheduled) return;
        scheduled = true;
        window.requestAnimationFrame(function () {
            scheduled = false;
            decorate();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', schedule);
    else schedule();

    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver(schedule).observe(document.body, {childList:true, subtree:true});
    }
})();
