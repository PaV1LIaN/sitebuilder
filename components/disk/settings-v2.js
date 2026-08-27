/* =========================================================
   SITEBUILDER / DISK SETTINGS 2.0 / v3
   Clean mirror UI. The legacy form stays intact but hidden.
   Existing backend/save handlers remain authoritative.
   ========================================================= */
(function () {
    'use strict';

    var MARKER = 'sbDiskSettingsV3';
    var scheduled = false;

    var FIELD_DEFS = [
        ['title', ['заголовок блока']],
        ['rootSource', ['источник корня']],
        ['viewMode', ['вид по умолчанию']],
        ['sortBy', ['сортировка по умолчанию']],
        ['sortDirection', ['направление сортировки']],
        ['maxFileSize', ['максимальный размер файла', 'максимальный размер']],
        ['extensions', ['допустимые расширения']],
        ['permissionMode', ['режим прав']],
        ['useSiteRoot', ['использовать корень сайта']],
        ['allowUpload', ['разрешить загрузку']],
        ['allowCreateFolder', ['разрешить создание папок']],
        ['allowRename', ['разрешить переименование']],
        ['allowDelete', ['разрешить удаление']],
        ['allowDownload', ['разрешить скачивание']],
        ['showSearch', ['показывать поиск']],
        ['showBreadcrumbs', ['показывать breadcrumbs', 'показывать хлебные крошки']]
    ];

    var LABELS = {
        title: 'Заголовок блока',
        rootSource: 'Корневая папка',
        viewMode: 'Вид по умолчанию',
        sortBy: 'Сортировать по',
        sortDirection: 'Направление',
        maxFileSize: 'Максимальный размер файла',
        extensions: 'Допустимые расширения',
        permissionMode: 'Режим прав',
        useSiteRoot: 'Использовать корень сайта, если у блока нет своей папки',
        allowUpload: 'Разрешить загрузку',
        allowCreateFolder: 'Разрешить создание папок',
        allowRename: 'Разрешить переименование',
        allowDelete: 'Разрешить удаление',
        allowDownload: 'Разрешить скачивание',
        showSearch: 'Показывать поиск',
        showBreadcrumbs: 'Показывать путь к папке'
    };

    var HELP = {
        rootSource: 'Определяет папку Bitrix.Диска, которая открывается как корневая для этого блока.',
        viewMode: 'Таблица удобна для документов, плитка — для файлов с визуальным превью.',
        permissionMode: 'Настройки блока могут только ограничить действия. Серверные права пользователя они не расширяют.',
        maxFileSize: 'Лимит одного загружаемого файла.',
        extensions: 'Через пробел, запятую или точку с запятой. Например: pdf docx xlsx png jpg.'
    };

    function normalize(value) {
        return String(value || '')
            .replace(/\u00a0/g, ' ')
            .replace(/[«»“”"]/g, '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function textOfButton(button) {
        if (!button) {
            return '';
        }
        return normalize(
            button.textContent
            || button.value
            || button.getAttribute('aria-label')
            || button.getAttribute('title')
            || ''
        );
    }

    function findHeading() {
        var nodes = Array.prototype.slice.call(
            document.querySelectorAll('h1,h2,h3,h4,[role="heading"],.modal-title,.popup-window-titlebar-text')
        );
        return nodes.find(function (node) {
            var text = normalize(node.textContent);
            return text.indexOf('настройки блока') !== -1 && text.indexOf('диск') !== -1;
        }) || null;
    }

    function findScope(heading) {
        var current = heading;
        for (var i = 0; i < 10 && current && current !== document.body; i++) {
            var controls = current.querySelectorAll
                ? current.querySelectorAll('input:not([type="hidden"]),select,textarea')
                : [];
            if (controls.length >= 6) {
                return current;
            }
            current = current.parentElement;
        }
        return heading ? heading.parentElement : null;
    }

    function findForm(scope, heading) {
        return (
            (heading && heading.closest && heading.closest('form'))
            || (scope && scope.querySelector && scope.querySelector('form'))
            || (scope && scope.matches && scope.matches('form') ? scope : null)
        );
    }

    function findLabelText(control, form) {
        var label = control.closest ? control.closest('label') : null;

        if (!label && control.id && form) {
            try {
                label = form.querySelector('label[for="' + CSS.escape(control.id) + '"]');
            } catch (error) {
                label = null;
            }
        }

        if (label) {
            return normalize(label.textContent);
        }

        var current = control.parentElement;
        for (var i = 0; i < 4 && current && current !== form; i++) {
            var text = normalize(current.textContent);
            if (text) {
                return text;
            }
            current = current.parentElement;
        }
        return '';
    }

    function fieldNameByText(text) {
        for (var i = 0; i < FIELD_DEFS.length; i++) {
            for (var j = 0; j < FIELD_DEFS[i][1].length; j++) {
                if (text.indexOf(FIELD_DEFS[i][1][j]) !== -1) {
                    return FIELD_DEFS[i][0];
                }
            }
        }
        return '';
    }

    function collectOriginals(form) {
        var result = {};
        var controls = Array.prototype.slice.call(
            form.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]),select,textarea')
        );

        controls.forEach(function (control) {
            var name = fieldNameByText(findLabelText(control, form));
            if (name && !result[name]) {
                result[name] = control;
            }
        });

        return result;
    }

    function findActions(scope, form) {
        var buttons = Array.prototype.slice.call(
            scope.querySelectorAll('button,input[type="submit"],input[type="button"]')
        );

        var save = buttons.find(function (button) {
            return textOfButton(button).indexOf('сохран') !== -1;
        }) || null;

        var cancel = buttons.find(function (button) {
            if (button === save) {
                return false;
            }
            var text = textOfButton(button);
            return text.indexOf('отмен') !== -1;
        }) || null;

        var close = buttons.find(function (button) {
            var raw = String(button.textContent || '').trim();
            var text = textOfButton(button);
            return raw === '×' || raw === '✕' || text === 'x' || text.indexOf('закрыть') !== -1;
        }) || null;

        return { save: save, cancel: cancel, close: close };
    }

    function setOriginal(original, value, checked) {
        if (!original) {
            return;
        }

        if (original.type === 'checkbox') {
            original.checked = !!checked;
        } else {
            original.value = String(value == null ? '' : value);
        }

        original.dispatchEvent(new Event('input', {bubbles: true}));
        original.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function cloneSelect(original) {
        var select = document.createElement('select');
        select.className = 'sb-disk3-control';
        Array.prototype.slice.call(original.options || []).forEach(function (option) {
            var copy = document.createElement('option');
            copy.value = option.value;
            copy.textContent = option.textContent;
            copy.disabled = option.disabled;
            select.appendChild(copy);
        });
        select.value = original.value;
        select.addEventListener('change', function () {
            setOriginal(original, select.value, false);
        });
        return select;
    }

    function createTextControl(original, type) {
        var input = document.createElement('input');
        input.type = type || 'text';
        input.className = 'sb-disk3-control';
        input.value = original.value || '';
        input.addEventListener('input', function () {
            setOriginal(original, input.value, false);
        });
        return input;
    }

    function createMirror(name, original) {
        if (!original) {
            return null;
        }

        if (original.type === 'checkbox') {
            return createToggle(name, original);
        }

        var field = document.createElement('label');
        field.className = 'sb-disk3-field';

        var label = document.createElement('span');
        label.className = 'sb-disk3-label';
        label.textContent = LABELS[name] || name;
        field.appendChild(label);

        var control;

        if (name === 'maxFileSize') {
            var size = document.createElement('div');
            size.className = 'sb-disk3-size';
            control = document.createElement('input');
            control.type = 'number';
            control.min = '1';
            control.max = '2048';
            control.step = '1';
            control.className = 'sb-disk3-control';
            var bytes = Number(original.value || 0);
            control.value = String(bytes > 0 ? Math.max(1, Math.round(bytes / 1048576 * 100) / 100) : 50);
            control.addEventListener('input', function () {
                var mb = Math.max(1, Math.min(2048, Number(control.value || 50)));
                setOriginal(original, Math.round(mb * 1048576), false);
                updateHeaderSummary();
            });
            var unit = document.createElement('span');
            unit.textContent = 'МБ';
            size.appendChild(control);
            size.appendChild(unit);
            field.appendChild(size);
        } else if (original.tagName === 'SELECT') {
            control = cloneSelect(original);
            field.appendChild(control);
        } else {
            control = createTextControl(original, 'text');
            if (name === 'extensions') {
                control.placeholder = 'pdf docx xlsx png jpg';
            }
            field.appendChild(control);
        }

        if (HELP[name]) {
            var help = document.createElement('small');
            help.className = 'sb-disk3-help';
            help.textContent = HELP[name];
            field.appendChild(help);
        }

        if (name === 'extensions') {
            enhanceExtensions(field, control, original);
        }

        control.addEventListener('change', updateHeaderSummary);
        control.addEventListener('input', updateHeaderSummary);

        return field;
    }

    function createToggle(name, original) {
        var label = document.createElement('label');
        label.className = 'sb-disk3-toggle';

        var copy = document.createElement('input');
        copy.type = 'checkbox';
        copy.checked = !!original.checked;

        var track = document.createElement('span');
        track.className = 'sb-disk3-toggle__track';
        var text = document.createElement('span');
        text.className = 'sb-disk3-toggle__text';
        text.textContent = LABELS[name] || name;

        copy.addEventListener('change', function () {
            setOriginal(original, '', copy.checked);
            updateHeaderSummary();
        });

        label.appendChild(copy);
        label.appendChild(track);
        label.appendChild(text);
        return label;
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
                if (!item || seen[item]) {
                    return false;
                }
                seen[item] = true;
                return true;
            });
    }

    function enhanceExtensions(field, input, original) {
        var buttons = document.createElement('div');
        buttons.className = 'sb-disk3-inline-actions';
        var chips = document.createElement('div');
        chips.className = 'sb-disk3-chips';

        var presets = [
            ['Документы', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']],
            ['Изображения', ['jpg', 'jpeg', 'png', 'gif', 'webp']],
            ['Документы + изображения', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp']]
        ];

        presets.forEach(function (preset) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'sb-disk3-mini';
            button.textContent = preset[0];
            button.addEventListener('click', function () {
                input.value = preset[1].join(' ');
                setOriginal(original, input.value, false);
                render();
            });
            buttons.appendChild(button);
        });

        function render() {
            chips.innerHTML = '';
            var items = parseExtensions(input.value);
            if (!items.length) {
                var empty = document.createElement('span');
                empty.className = 'is-empty';
                empty.textContent = 'Без ограничения по расширениям';
                chips.appendChild(empty);
                return;
            }
            items.forEach(function (item) {
                var chip = document.createElement('span');
                chip.textContent = '.' + item;
                chips.appendChild(chip);
            });
        }

        input.addEventListener('input', render);
        input.addEventListener('blur', function () {
            input.value = parseExtensions(input.value).join(' ');
            setOriginal(original, input.value, false);
            render();
        });

        field.appendChild(buttons);
        field.appendChild(chips);
        render();
    }

    function section(title, description) {
        var node = document.createElement('section');
        node.className = 'sb-disk3-section';

        var head = document.createElement('div');
        head.className = 'sb-disk3-section__head';
        var h = document.createElement('strong');
        h.textContent = title;
        var p = document.createElement('span');
        p.textContent = description;
        head.appendChild(h);
        head.appendChild(p);
        node.appendChild(head);

        var content = document.createElement('div');
        content.className = 'sb-disk3-section__content';
        node.appendChild(content);

        return { section: node, content: content };
    }

    function tabButton(name, title) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'sb-disk3-tab';
        button.dataset.diskTab = name;
        button.textContent = title;
        return button;
    }

    function setTab(name) {
        document.querySelectorAll('.sb-disk3-tab').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.diskTab === name);
        });
        document.querySelectorAll('.sb-disk3-panel').forEach(function (panel) {
            panel.hidden = panel.dataset.diskPanel !== name;
        });
    }

    var runtime = null;

    function updateHeaderSummary() {
        if (!runtime) {
            return;
        }
        var original = runtime.originals;
        var summary = runtime.summary;
        var root = original.rootSource && original.rootSource.options
            ? original.rootSource.options[original.rootSource.selectedIndex].textContent
            : '—';
        var view = original.viewMode && original.viewMode.options
            ? original.viewMode.options[original.viewMode.selectedIndex].textContent
            : '—';
        var rights = original.permissionMode && original.permissionMode.options
            ? original.permissionMode.options[original.permissionMode.selectedIndex].textContent
            : '—';
        var bytes = Number(original.maxFileSize ? original.maxFileSize.value : 0);
        var mb = bytes > 0 ? Math.round(bytes / 1048576) : 0;

        summary.innerHTML = '';
        [
            ['Корень', root],
            ['Вид', view],
            ['Права', rights],
            ['Файл', mb ? mb + ' МБ' : '—']
        ].forEach(function (item) {
            var pill = document.createElement('span');
            pill.innerHTML = '<small></small><strong></strong>';
            pill.querySelector('small').textContent = item[0];
            pill.querySelector('strong').textContent = item[1];
            summary.appendChild(pill);
        });
    }

    function makePresetButton(title, values) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'sb-disk3-preset';
        button.textContent = title;
        button.addEventListener('click', function () {
            Object.keys(values).forEach(function (name) {
                var original = runtime.originals[name];
                if (!original) {
                    return;
                }
                setOriginal(original, '', !!values[name]);
                var mirror = runtime.ui.querySelector('[data-mirror="' + name + '"] input[type="checkbox"]');
                if (mirror) {
                    mirror.checked = !!values[name];
                }
            });
            updateHeaderSummary();
        });
        return button;
    }

    function buildUi(scope, form, heading, originals, actions) {
        var legacy = document.createElement('div');
        legacy.className = 'sb-disk3-legacy';
        legacy.hidden = true;

        Array.prototype.slice.call(form.childNodes).forEach(function (child) {
            legacy.appendChild(child);
        });
        form.appendChild(legacy);

        if (heading && !legacy.contains(heading)) {
            heading.classList.add('sb-disk3-external-hidden');
        }
        if (actions.close && !legacy.contains(actions.close)) {
            actions.close.classList.add('sb-disk3-external-hidden');
        }

        var ui = document.createElement('div');
        ui.className = 'sb-disk3-ui';

        var header = document.createElement('header');
        header.className = 'sb-disk3-header';
        header.innerHTML = '<div><h2>Настройки блока «Диск»</h2><p>Отображение, загрузка файлов и доступ пользователей.</p></div>';

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'sb-disk3-close';
        close.textContent = '×';
        close.setAttribute('aria-label', 'Закрыть');
        close.addEventListener('click', function () {
            if (actions.close) {
                actions.close.click();
            } else if (actions.cancel) {
                actions.cancel.click();
            }
        });
        header.appendChild(close);
        ui.appendChild(header);

        var summary = document.createElement('div');
        summary.className = 'sb-disk3-summary';
        ui.appendChild(summary);

        var tabs = document.createElement('nav');
        tabs.className = 'sb-disk3-tabs';
        [
            ['main', 'Основное'],
            ['upload', 'Загрузка'],
            ['access', 'Доступ']
        ].forEach(function (item) {
            var button = tabButton(item[0], item[1]);
            button.addEventListener('click', function () {
                setTab(item[0]);
            });
            tabs.appendChild(button);
        });
        ui.appendChild(tabs);

        var body = document.createElement('div');
        body.className = 'sb-disk3-body';
        ui.appendChild(body);

        var mainPanel = document.createElement('div');
        mainPanel.className = 'sb-disk3-panel';
        mainPanel.dataset.diskPanel = 'main';
        var main = section('Основные параметры', 'Что увидит пользователь при открытии блока.');
        main.content.classList.add('sb-disk3-grid');
        ['title', 'rootSource', 'viewMode', 'sortBy', 'sortDirection'].forEach(function (name) {
            var mirror = createMirror(name, originals[name]);
            if (mirror) {
                mirror.dataset.mirror = name;
                main.content.appendChild(mirror);
            }
        });
        mainPanel.appendChild(main.section);
        body.appendChild(mainPanel);

        var uploadPanel = document.createElement('div');
        uploadPanel.className = 'sb-disk3-panel';
        uploadPanel.dataset.diskPanel = 'upload';
        var upload = section('Загрузка файлов', 'Лимиты и типы файлов для загрузки через этот блок.');
        upload.content.classList.add('sb-disk3-grid');
        ['maxFileSize', 'extensions'].forEach(function (name) {
            var mirror = createMirror(name, originals[name]);
            if (mirror) {
                mirror.dataset.mirror = name;
                upload.content.appendChild(mirror);
            }
        });
        uploadPanel.appendChild(upload.section);
        body.appendChild(uploadPanel);

        var accessPanel = document.createElement('div');
        accessPanel.className = 'sb-disk3-panel';
        accessPanel.dataset.diskPanel = 'access';
        var access = section('Доступ и возможности', 'Режим прав и действия, доступные внутри этого блока.');

        var permission = createMirror('permissionMode', originals.permissionMode);
        if (permission) {
            permission.dataset.mirror = 'permissionMode';
            access.content.appendChild(permission);
        }

        var presets = document.createElement('div');
        presets.className = 'sb-disk3-presets';
        presets.appendChild(makePresetButton('Только просмотр', {
            allowUpload:false, allowCreateFolder:false, allowRename:false, allowDelete:false,
            allowDownload:true, showSearch:true, showBreadcrumbs:true
        }));
        presets.appendChild(makePresetButton('Работа без удаления', {
            allowUpload:true, allowCreateFolder:true, allowRename:true, allowDelete:false,
            allowDownload:true, showSearch:true, showBreadcrumbs:true
        }));
        presets.appendChild(makePresetButton('Все действия', {
            allowUpload:true, allowCreateFolder:true, allowRename:true, allowDelete:true,
            allowDownload:true, showSearch:true, showBreadcrumbs:true
        }));
        access.content.appendChild(presets);

        var toggles = document.createElement('div');
        toggles.className = 'sb-disk3-toggle-grid';
        ['useSiteRoot', 'allowUpload', 'allowCreateFolder', 'allowRename', 'allowDelete', 'allowDownload', 'showSearch', 'showBreadcrumbs'].forEach(function (name) {
            var mirror = createMirror(name, originals[name]);
            if (mirror) {
                mirror.dataset.mirror = name;
                toggles.appendChild(mirror);
            }
        });
        access.content.appendChild(toggles);
        accessPanel.appendChild(access.section);
        body.appendChild(accessPanel);

        var footer = document.createElement('footer');
        footer.className = 'sb-disk3-footer';
        var state = document.createElement('span');
        state.textContent = 'Изменения применятся после сохранения.';
        footer.appendChild(state);

        var buttons = document.createElement('div');
        if (actions.cancel || actions.close) {
            var cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'sb-disk3-button is-secondary';
            cancel.textContent = 'Отмена';
            cancel.addEventListener('click', function () {
                if (actions.cancel) {
                    actions.cancel.click();
                } else if (actions.close) {
                    actions.close.click();
                }
            });
            buttons.appendChild(cancel);
        }

        var save = document.createElement('button');
        save.type = 'button';
        save.className = 'sb-disk3-button is-primary';
        save.textContent = 'Сохранить';
        save.addEventListener('click', function () {
            if (actions.save) {
                actions.save.click();
            } else if (form.requestSubmit) {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
        buttons.appendChild(save);
        footer.appendChild(buttons);
        ui.appendChild(footer);

        form.appendChild(ui);

        runtime = {
            originals: originals,
            actions: actions,
            form: form,
            ui: ui,
            summary: summary
        };

        updateHeaderSummary();
        setTab('main');

        scope.classList.add('sb-disk3-dialog');
        form.classList.add('sb-disk3-form');

        scope.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault();
                save.click();
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                close.click();
            }
        });
    }

    function decorate() {
        var heading = findHeading();
        if (!heading) {
            return;
        }
        var scope = findScope(heading);
        var form = findForm(scope, heading);
        if (!scope || !form || form.dataset[MARKER] === '1') {
            return;
        }

        var originals = collectOriginals(form);
        if (Object.keys(originals).length < 6) {
            return;
        }

        var actions = findActions(scope, form);
        if (!actions.save) {
            return;
        }

        form.dataset[MARKER] = '1';
        buildUi(scope, form, heading, originals, actions);
    }

    function scheduleDecorate() {
        if (scheduled) {
            return;
        }
        scheduled = true;
        window.requestAnimationFrame(function () {
            scheduled = false;
            decorate();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleDecorate);
    } else {
        scheduleDecorate();
    }

    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver(scheduleDecorate).observe(document.body, {
            childList: true,
            subtree: true
        });
    }
})();
