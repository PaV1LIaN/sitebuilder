/* =========================================================
   SITEBUILDER / DISK SETTINGS 2.0 / v1
   Progressive enhancement for the existing Disk settings form.
   Backend fields and existing save handlers are preserved.
   ========================================================= */
(function () {
    'use strict';

    var MARKER = 'sbDiskSettings2Decorated';
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

    var ACTION_FIELDS = [
        'allowUpload',
        'allowCreateFolder',
        'allowRename',
        'allowDelete',
        'allowDownload',
        'showSearch',
        'showBreadcrumbs'
    ];

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

    function isDiskSettingsHeading(node) {
        var text = normalize(node && node.textContent);

        return text.indexOf('настройки блока') !== -1
            && text.indexOf('диск') !== -1;
    }

    function findHeading() {
        var selectors = [
            'h1',
            'h2',
            'h3',
            'h4',
            '[role="heading"]',
            '.modal-title',
            '.popup-window-titlebar-text'
        ];

        var nodes = Array.prototype.slice.call(
            document.querySelectorAll(selectors.join(','))
        );

        return nodes.find(isDiskSettingsHeading) || null;
    }

    function findScope(heading) {
        if (!heading) {
            return null;
        }

        var current = heading;
        var fallback = heading.parentElement;

        for (var i = 0; i < 9 && current && current !== document.body; i++) {
            var controls = current.querySelectorAll
                ? current.querySelectorAll(
                    'input:not([type="hidden"]),select,textarea'
                )
                : [];

            if (controls.length >= 6) {
                return current;
            }

            current = current.parentElement;
        }

        return fallback;
    }

    function findForm(scope, heading) {
        if (!scope) {
            return null;
        }

        return (
            (heading && heading.closest && heading.closest('form'))
            || scope.querySelector('form')
            || (
                scope.matches
                && scope.matches('form')
                ? scope
                : null
            )
        );
    }

    function findLabelText(control, form) {
        if (!control) {
            return '';
        }

        var label = control.closest
            ? control.closest('label')
            : null;

        if (!label && control.id && form) {
            try {
                label = form.querySelector(
                    'label[for="' + CSS.escape(control.id) + '"]'
                );
            } catch (error) {
                label = form.querySelector(
                    'label[for="' + control.id.replace(/"/g, '\\"') + '"]'
                );
            }
        }

        if (label) {
            return normalize(label.textContent);
        }

        var parent = control.parentElement;

        for (var i = 0; i < 3 && parent && parent !== form; i++) {
            var text = normalize(parent.textContent);

            if (text) {
                return text;
            }

            parent = parent.parentElement;
        }

        return '';
    }

    function fieldNameByText(text) {
        for (var i = 0; i < FIELD_DEFS.length; i++) {
            var def = FIELD_DEFS[i];

            for (var j = 0; j < def[1].length; j++) {
                if (text.indexOf(def[1][j]) !== -1) {
                    return def[0];
                }
            }
        }

        return '';
    }

    function fieldContainer(control, form) {
        if (!control) {
            return null;
        }

        var label = control.closest
            ? control.closest('label')
            : null;

        if (label && form.contains(label)) {
            return label;
        }

        var selectors = [
            '.sb-field',
            '.field',
            '.form-row',
            '.settings-row',
            '.setting-row',
            '.form-group'
        ];

        for (var i = 0; i < selectors.length; i++) {
            var found = control.closest
                ? control.closest(selectors[i])
                : null;

            if (found && found !== form && form.contains(found)) {
                return found;
            }
        }

        return control.parentElement && control.parentElement !== form
            ? control.parentElement
            : control;
    }

    function collectFields(form) {
        var result = {};
        var controls = Array.prototype.slice.call(
            form.querySelectorAll(
                'input:not([type="hidden"]):not([type="submit"]):not([type="button"]),select,textarea'
            )
        );

        controls.forEach(function (control) {
            if (control.dataset.sbDiskSettings2Claimed === '1') {
                return;
            }

            var text = findLabelText(control, form);
            var name = fieldNameByText(text);

            if (!name || result[name]) {
                return;
            }

            var container = fieldContainer(control, form);

            if (!container) {
                return;
            }

            control.dataset.sbDiskSettings2Claimed = '1';

            container.classList.add('sb-disk-settings2-field');
            container.dataset.diskSettings2Field = name;

            if (control.type === 'checkbox') {
                container.classList.add('is-toggle');
            }

            if (
                name === 'title'
                || name === 'extensions'
                || name === 'permissionMode'
            ) {
                container.classList.add('is-wide');
            }

            result[name] = {
                control: control,
                container: container
            };
        });

        return result;
    }

    function section(title, description, className) {
        var node = document.createElement('section');
        node.className = 'sb-disk-settings2-section'
            + (className ? ' ' + className : '');

        var head = document.createElement('div');
        head.className = 'sb-disk-settings2-section__head';

        var titleNode = document.createElement('strong');
        titleNode.textContent = title;

        var descriptionNode = document.createElement('span');
        descriptionNode.textContent = description;

        head.appendChild(titleNode);
        head.appendChild(descriptionNode);

        var grid = document.createElement('div');
        grid.className = 'sb-disk-settings2-grid';

        node.appendChild(head);
        node.appendChild(grid);

        return {
            section: node,
            grid: grid,
            head: head
        };
    }

    function appendField(target, fields, name) {
        if (!target || !fields[name]) {
            return;
        }

        target.appendChild(fields[name].container);
    }

    function helper(text) {
        var node = document.createElement('small');
        node.className = 'sb-disk-settings2-help';
        node.textContent = text;
        return node;
    }

    function parseExtensions(value) {
        var seen = {};

        return String(value || '')
            .toLowerCase()
            .split(/[\s,;]+/)
            .map(function (item) {
                return item
                    .trim()
                    .replace(/^\.+/, '')
                    .replace(/[^a-z0-9_-]/g, '');
            })
            .filter(function (item) {
                if (!item || seen[item]) {
                    return false;
                }

                seen[item] = true;
                return true;
            })
            .slice(0, 50);
    }

    function normalizedExtensionsValue(items) {
        return items.join(' ');
    }

    function setupExtensions(field) {
        if (!field) {
            return null;
        }

        var control = field.control;
        var container = field.container;

        control.setAttribute(
            'placeholder',
            'pdf doc docx xls xlsx png jpg'
        );

        var tools = document.createElement('div');
        tools.className = 'sb-disk-settings2-ext-tools';

        var presets = [
            [
                'Документы',
                ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']
            ],
            [
                'Изображения',
                ['jpg', 'jpeg', 'png', 'gif', 'webp']
            ],
            [
                'Документы + изображения',
                [
                    'pdf', 'doc', 'docx', 'xls', 'xlsx',
                    'ppt', 'pptx', 'jpg', 'jpeg', 'png',
                    'gif', 'webp'
                ]
            ]
        ];

        presets.forEach(function (preset) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'sb-disk-settings2-mini-btn';
            button.textContent = preset[0];

            button.addEventListener('click', function () {
                control.value = normalizedExtensionsValue(preset[1]);
                control.dispatchEvent(
                    new Event('input', {bubbles: true})
                );
                control.dispatchEvent(
                    new Event('change', {bubbles: true})
                );
                render();
            });

            tools.appendChild(button);
        });

        var chips = document.createElement('div');
        chips.className = 'sb-disk-settings2-ext-chips';

        function render() {
            var items = parseExtensions(control.value);

            chips.innerHTML = '';

            if (!items.length) {
                var empty = document.createElement('span');
                empty.className = 'is-empty';
                empty.textContent = 'Ограничение по расширениям не задано';
                chips.appendChild(empty);
                return;
            }

            items.forEach(function (item) {
                var chip = document.createElement('span');
                chip.textContent = '.' + item;
                chips.appendChild(chip);
            });
        }

        function normalizeValue() {
            control.value = normalizedExtensionsValue(
                parseExtensions(control.value)
            );
            render();
        }

        control.addEventListener('input', render);
        control.addEventListener('blur', normalizeValue);
        control.addEventListener('change', render);

        container.appendChild(tools);
        container.appendChild(chips);
        container.appendChild(
            helper(
                'Можно вводить через пробел, запятую или точку с запятой. Точки перед расширениями не нужны.'
            )
        );

        render();

        return {
            normalize: normalizeValue,
            render: render
        };
    }

    function setupMaxFileSize(field) {
        if (!field) {
            return null;
        }

        var original = field.control;
        var container = field.container;

        original.classList.add(
            'sb-disk-settings2-native-bytes'
        );

        var row = document.createElement('div');
        row.className = 'sb-disk-settings2-size-row';

        var input = document.createElement('input');
        input.type = 'number';
        input.min = '1';
        input.max = '2048';
        input.step = '1';
        input.inputMode = 'decimal';
        input.className = 'sb-disk-settings2-size-input';
        input.setAttribute(
            'aria-label',
            'Максимальный размер файла в мегабайтах'
        );

        var unit = document.createElement('span');
        unit.textContent = 'МБ';

        row.appendChild(input);
        row.appendChild(unit);

        original.insertAdjacentElement('afterend', row);

        function bytesToMb(value) {
            var bytes = Number(value || 0);

            if (!isFinite(bytes) || bytes <= 0) {
                return 50;
            }

            return Math.max(
                1,
                Math.round(bytes / 1048576 * 100) / 100
            );
        }

        function syncFromOriginal() {
            input.value = String(
                bytesToMb(original.value)
            );
        }

        function syncToOriginal(dispatchChange) {
            var mb = Number(input.value || 0);

            if (!isFinite(mb)) {
                mb = 50;
            }

            mb = Math.max(1, Math.min(2048, mb));
            input.value = String(mb);

            original.value = String(
                Math.round(mb * 1048576)
            );

            original.dispatchEvent(
                new Event('input', {bubbles: true})
            );

            if (dispatchChange) {
                original.dispatchEvent(
                    new Event('change', {bubbles: true})
                );
            }

            return mb;
        }

        input.addEventListener('input', function () {
            syncToOriginal(false);
        });

        input.addEventListener('change', function () {
            syncToOriginal(true);
        });

        container.appendChild(
            helper(
                'Лимит одного файла. Значение сохраняется в байтах, но здесь показывается в мегабайтах.'
            )
        );

        syncFromOriginal();

        return {
            input: input,
            sync: function () {
                return syncToOriginal(true);
            }
        };
    }

    function setupRootHelper(field) {
        if (!field) {
            return;
        }

        var control = field.control;
        var note = helper('');

        field.container.appendChild(note);

        function update() {
            var option = control.options
                ? control.options[control.selectedIndex]
                : null;

            var text = normalize(
                option ? option.textContent : control.value
            );

            if (text.indexOf('собствен') !== -1) {
                note.textContent =
                    'Для блока используется отдельная папка. Это самый безопасный вариант для независимого раздела документов.';
            } else if (
                text.indexOf('сайт') !== -1
                || text.indexOf('корень') !== -1
            ) {
                note.textContent =
                    'Блок работает с общей корневой папкой сайта. Изменения в ней могут быть видны другим блокам Диска.';
            } else {
                note.textContent =
                    'Определяет папку Bitrix.Диска, которая будет открываться как корневая для этого блока.';
            }
        }

        control.addEventListener('change', update);
        update();
    }

    function setupPermissionHelper(field) {
        if (!field) {
            return;
        }

        var control = field.control;
        var note = helper('');

        field.container.appendChild(note);

        function update() {
            var option = control.options
                ? control.options[control.selectedIndex]
                : null;

            var text = normalize(
                option ? option.textContent : control.value
            );

            if (
                text.indexOf('индивиду') !== -1
                || text.indexOf('custom') !== -1
            ) {
                note.textContent =
                    'Индивидуальный режим учитывает настройки блока, но не может дать пользователю больше прав, чем разрешено его ролью и правами страницы.';
            } else {
                note.textContent =
                    'Права пользователя наследуются от сайта/страницы. Переключатели ниже дополнительно ограничивают разрешённые действия блока.';
            }
        }

        control.addEventListener('change', update);
        update();
    }

    function setCheckbox(field, checked) {
        if (!field || !field.control) {
            return;
        }

        var control = field.control;

        if (control.checked === checked) {
            return;
        }

        control.checked = checked;
        control.dispatchEvent(
            new Event('change', {bubbles: true})
        );
    }

    function setupPermissionPresets(sectionNode, fields) {
        var bar = document.createElement('div');
        bar.className = 'sb-disk-settings2-presets';

        var label = document.createElement('span');
        label.textContent = 'Быстрые наборы действий:';
        bar.appendChild(label);

        var presets = [
            {
                title: 'Только просмотр',
                values: {
                    allowUpload: false,
                    allowCreateFolder: false,
                    allowRename: false,
                    allowDelete: false,
                    allowDownload: true,
                    showSearch: true,
                    showBreadcrumbs: true
                }
            },
            {
                title: 'Работа без удаления',
                values: {
                    allowUpload: true,
                    allowCreateFolder: true,
                    allowRename: true,
                    allowDelete: false,
                    allowDownload: true,
                    showSearch: true,
                    showBreadcrumbs: true
                }
            },
            {
                title: 'Все действия',
                values: {
                    allowUpload: true,
                    allowCreateFolder: true,
                    allowRename: true,
                    allowDelete: true,
                    allowDownload: true,
                    showSearch: true,
                    showBreadcrumbs: true
                }
            }
        ];

        presets.forEach(function (preset) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'sb-disk-settings2-mini-btn';
            button.textContent = preset.title;

            button.addEventListener('click', function () {
                Object.keys(preset.values).forEach(function (name) {
                    setCheckbox(
                        fields[name],
                        !!preset.values[name]
                    );
                });

                updateSummary(fields);
            });

            bar.appendChild(button);
        });

        sectionNode.insertBefore(
            bar,
            sectionNode.querySelector('.sb-disk-settings2-grid')
        );
    }

    function selectedText(field) {
        if (!field || !field.control) {
            return '—';
        }

        var control = field.control;

        if (
            control.tagName === 'SELECT'
            && control.options
            && control.selectedIndex >= 0
        ) {
            return String(
                control.options[control.selectedIndex].textContent
                || control.value
                || '—'
            ).trim();
        }

        return String(control.value || '—').trim();
    }

    function ensureSummary(form) {
        var summary = form.querySelector(
            '[data-disk-settings2-summary]'
        );

        if (summary) {
            return summary;
        }

        summary = document.createElement('div');
        summary.className = 'sb-disk-settings2-summary';
        summary.dataset.diskSettings2Summary = '1';

        form.insertBefore(
            summary,
            form.firstChild
        );

        return summary;
    }

    function updateSummary(fields) {
        var form = fields.__form;

        if (!form) {
            return;
        }

        var summary = ensureSummary(form);
        var mb = fields.__sizeEnhancer
            ? fields.__sizeEnhancer.input.value
            : '';

        var items = [
            [
                'Корень',
                selectedText(fields.rootSource)
            ],
            [
                'Вид',
                selectedText(fields.viewMode)
            ],
            [
                'Права',
                selectedText(fields.permissionMode)
            ]
        ];

        if (mb) {
            items.push([
                'Файл',
                mb + ' МБ'
            ]);
        }

        summary.innerHTML = '';

        items.forEach(function (item) {
            var pill = document.createElement('span');
            var label = document.createElement('small');
            var value = document.createElement('strong');

            label.textContent = item[0];
            value.textContent = item[1];

            pill.appendChild(label);
            pill.appendChild(value);
            summary.appendChild(pill);
        });
    }

    function hideLegacyHeadings(form) {
        var nodes = Array.prototype.slice.call(
            form.querySelectorAll(
                'h2,h3,h4,h5,legend,strong,.section-title'
            )
        );

        nodes.forEach(function (node) {
            var text = normalize(node.textContent);

            if (
                text === 'основные настройки'
                || text === 'возможности'
            ) {
                node.classList.add(
                    'sb-disk-settings2-old-heading'
                );
            }
        });
    }

    function findActions(scope, form) {
        var buttons = Array.prototype.slice.call(
            scope.querySelectorAll(
                'button,input[type="submit"],input[type="button"]'
            )
        );

        var save = buttons.find(function (button) {
            var text = textOfButton(button);

            return text.indexOf('сохран') !== -1;
        }) || null;

        var cancel = buttons.find(function (button) {
            if (button === save) {
                return false;
            }

            var text = textOfButton(button);

            return (
                text.indexOf('отмен') !== -1
                || text.indexOf('закрыть') !== -1
            );
        }) || null;

        var close = buttons.find(function (button) {
            var text = textOfButton(button);
            var raw = String(button.textContent || '').trim();

            return (
                raw === '×'
                || raw === '✕'
                || text === 'x'
                || text.indexOf('закрыть') !== -1
            );
        }) || null;

        if (close) {
            close.classList.add(
                'sb-disk-settings2-close'
            );
        }

        var actionNode = save
            ? save.parentElement
            : null;

        if (
            actionNode
            && actionNode !== form
        ) {
            actionNode.classList.add(
                'sb-disk-settings2-actions'
            );
        }

        if (save) {
            save.classList.add(
                'sb-disk-settings2-save'
            );
        }

        if (cancel) {
            cancel.classList.add(
                'sb-disk-settings2-cancel'
            );
        }

        return {
            save: save,
            cancel: cancel,
            close: close
        };
    }

    function setupActionFooter(scope, form, actions) {
        if (!actions.save) {
            return;
        }

        if (
            actions.save.parentElement
            && actions.save.parentElement !== form
        ) {
            var parent = actions.save.parentElement;

            if (!parent.querySelector('.sb-disk-settings2-action-note')) {
                var note = document.createElement('span');
                note.className = 'sb-disk-settings2-action-note';
                note.textContent =
                    'Изменения применятся после сохранения настроек блока.';
                parent.insertBefore(
                    note,
                    parent.firstChild
                );
            }

            return;
        }

        var footer = document.createElement('div');
        footer.className = 'sb-disk-settings2-actions';

        var note = document.createElement('span');
        note.className = 'sb-disk-settings2-action-note';
        note.textContent =
            'Изменения применятся после сохранения настроек блока.';

        var buttons = document.createElement('div');
        buttons.className = 'sb-disk-settings2-actions__buttons';

        if (actions.cancel && form.contains(actions.cancel)) {
            buttons.appendChild(actions.cancel);
        }

        buttons.appendChild(actions.save);

        footer.appendChild(note);
        footer.appendChild(buttons);
        form.appendChild(footer);
    }

    function setupSaveValidation(scope, fields, actions) {
        if (!actions.save) {
            return;
        }

        function prepare() {
            if (fields.__sizeEnhancer) {
                var mb = fields.__sizeEnhancer.sync();

                if (!isFinite(mb) || mb < 1 || mb > 2048) {
                    return false;
                }
            }

            if (fields.__extensionsEnhancer) {
                fields.__extensionsEnhancer.normalize();
            }

            return true;
        }

        actions.save.addEventListener(
            'click',
            function (event) {
                if (!prepare()) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    window.alert(
                        'Проверьте максимальный размер файла. Допустимо от 1 до 2048 МБ.'
                    );
                }
            },
            true
        );

        scope.addEventListener('keydown', function (event) {
            if (
                (event.ctrlKey || event.metaKey)
                && event.key === 'Enter'
            ) {
                event.preventDefault();

                if (prepare()) {
                    actions.save.click();
                }

                return;
            }

            if (event.key === 'Escape' && actions.close) {
                event.preventDefault();
                actions.close.click();
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

        if (
            !scope
            || !form
            || form.dataset[MARKER] === '1'
        ) {
            return;
        }

        var fields = collectFields(form);

        if (Object.keys(fields).length < 6) {
            return;
        }

        form.dataset[MARKER] = '1';
        form.classList.add(
            'sb-disk-settings2-form'
        );
        scope.classList.add(
            'sb-disk-settings2-dialog'
        );
        heading.classList.add(
            'sb-disk-settings2-title'
        );

        var subtitle = document.createElement('p');
        subtitle.className = 'sb-disk-settings2-subtitle';
        subtitle.textContent =
            'Настройте отображение, загрузку и доступ к файлам. Права блока не расширяют права пользователя в Bitrix24.';
        heading.insertAdjacentElement(
            'afterend',
            subtitle
        );

        hideLegacyHeadings(form);

        var mount = document.createElement('div');
        mount.className = 'sb-disk-settings2-workspace';

        var main = section(
            'Основное',
            'Название, корневая папка и то, как список файлов открывается пользователю.'
        );

        [
            'title',
            'rootSource',
            'viewMode',
            'sortBy',
            'sortDirection'
        ].forEach(function (name) {
            appendField(main.grid, fields, name);
        });

        var upload = section(
            'Загрузка файлов',
            'Ограничения применяются к загрузке через этот блок.'
        );

        [
            'maxFileSize',
            'extensions'
        ].forEach(function (name) {
            appendField(upload.grid, fields, name);
        });

        var access = section(
            'Доступ и возможности',
            'Здесь задаются разрешённые действия и элементы интерфейса.'
        );

        appendField(
            access.grid,
            fields,
            'permissionMode'
        );

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
            appendField(access.grid, fields, name);
        });

        setupPermissionPresets(
            access.section,
            fields
        );

        mount.appendChild(main.section);
        mount.appendChild(upload.section);
        mount.appendChild(access.section);

        form.appendChild(mount);

        fields.__form = form;
        fields.__sizeEnhancer = setupMaxFileSize(
            fields.maxFileSize
        );
        fields.__extensionsEnhancer = setupExtensions(
            fields.extensions
        );

        setupRootHelper(fields.rootSource);
        setupPermissionHelper(fields.permissionMode);

        var actions = findActions(scope, form);

        setupActionFooter(
            scope,
            form,
            actions
        );

        setupSaveValidation(
            scope,
            fields,
            actions
        );

        form.addEventListener('change', function () {
            updateSummary(fields);
        });

        form.addEventListener('input', function () {
            updateSummary(fields);
        });

        updateSummary(fields);
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
        document.addEventListener(
            'DOMContentLoaded',
            scheduleDecorate
        );
    } else {
        scheduleDecorate();
    }

    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver(function () {
            scheduleDecorate();
        }).observe(
            document.body,
            {
                childList: true,
                subtree: true
            }
        );
    }
})();
