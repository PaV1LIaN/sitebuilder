/* =========================================================
   SITEBUILDER / DISK SETTINGS 2.0 / v2
   Cleaner dialog shell for the existing Disk settings form.
   Backend fields and save handlers are preserved.
   ========================================================= */
(function () {
    'use strict';

    var MARKER = 'sbDiskSettings2V2Decorated';
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

        for (var i = 0; i < 10 && current && current !== document.body; i++) {
            var controls = current.querySelectorAll
                ? current.querySelectorAll('input:not([type="hidden"]),select,textarea')
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
            || (scope.matches && scope.matches('form') ? scope : null)
        );
    }

    function findLabelText(control, form) {
        if (!control) {
            return '';
        }

        var label = control.closest ? control.closest('label') : null;

        if (!label && control.id && form) {
            try {
                label = form.querySelector('label[for="' + CSS.escape(control.id) + '"]');
            } catch (error) {
                label = form.querySelector('label[for="' + control.id.replace(/"/g, '\\"') + '"]');
            }
        }

        if (label) {
            return normalize(label.textContent);
        }

        var parent = control.parentElement;
        for (var i = 0; i < 4 && parent && parent !== form; i++) {
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

        var best = control;
        var current = control;

        for (var i = 0; i < 6 && current && current !== form; i++) {
            var parent = current.parentElement;
            if (!parent || parent === form) {
                break;
            }

            var controlsCount = parent.querySelectorAll('input,select,textarea').length;
            var textLength = normalize(parent.textContent).length;

            if (controlsCount <= 3 && textLength <= 260) {
                best = parent;
                current = parent;
                continue;
            }

            break;
        }

        return best;
    }

    function collectFields(form) {
        var result = {};
        var controls = Array.prototype.slice.call(
            form.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]),select,textarea')
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
            container.dataset.diskSettings2Field = name;
            container.classList.add('sb-disk2-field');

            if (control.type === 'checkbox') {
                container.classList.add('is-toggle');
            }
            if (name === 'title' || name === 'extensions' || name === 'permissionMode') {
                container.classList.add('is-wide');
            }

            result[name] = { control: control, container: container };
        });

        return result;
    }

    function createSection(title, description, className) {
        var node = document.createElement('section');
        node.className = 'sb-disk2-section' + (className ? ' ' + className : '');

        var header = document.createElement('div');
        header.className = 'sb-disk2-section__head';

        var strong = document.createElement('strong');
        strong.textContent = title;

        var small = document.createElement('span');
        small.textContent = description;

        header.appendChild(strong);
        header.appendChild(small);
        node.appendChild(header);

        var grid = document.createElement('div');
        grid.className = 'sb-disk2-grid';
        node.appendChild(grid);

        return { section: node, grid: grid };
    }

    function appendField(target, fields, name) {
        if (target && fields[name]) {
            target.appendChild(fields[name].container);
        }
    }

    function helper(text) {
        var small = document.createElement('small');
        small.className = 'sb-disk2-help';
        small.textContent = text;
        return small;
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

    function normalizeExtensionsValue(items) {
        return items.join(' ');
    }

    function setupExtensions(field) {
        if (!field) {
            return null;
        }

        var control = field.control;
        var container = field.container;
        control.setAttribute('placeholder', 'pdf doc docx xls xlsx png jpg');

        var presets = document.createElement('div');
        presets.className = 'sb-disk2-inline-buttons';

        [
            ['Документы', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']],
            ['Изображения', ['jpg', 'jpeg', 'png', 'gif', 'webp']],
            ['Документы + изображения', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp']]
        ].forEach(function (preset) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'sb-disk2-mini-btn';
            button.textContent = preset[0];
            button.addEventListener('click', function () {
                control.value = normalizeExtensionsValue(preset[1]);
                control.dispatchEvent(new Event('input', {bubbles: true}));
                control.dispatchEvent(new Event('change', {bubbles: true}));
                render();
            });
            presets.appendChild(button);
        });

        var chips = document.createElement('div');
        chips.className = 'sb-disk2-ext-chips';

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
            control.value = normalizeExtensionsValue(parseExtensions(control.value));
            render();
        }

        control.addEventListener('input', render);
        control.addEventListener('change', render);
        control.addEventListener('blur', normalizeValue);

        container.appendChild(presets);
        container.appendChild(chips);
        container.appendChild(helper('Можно вводить через пробел, запятую или точку с запятой. Точки перед расширениями не нужны.'));
        render();

        return { normalize: normalizeValue };
    }

    function setupMaxFileSize(field) {
        if (!field) {
            return null;
        }

        var original = field.control;
        var container = field.container;
        original.classList.add('sb-disk2-native-bytes');

        var row = document.createElement('div');
        row.className = 'sb-disk2-size-row';

        var input = document.createElement('input');
        input.type = 'number';
        input.min = '1';
        input.max = '2048';
        input.step = '1';
        input.className = 'sb-disk2-size-input';

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
            return Math.max(1, Math.round(bytes / 1048576 * 100) / 100);
        }

        function syncFromOriginal() {
            input.value = String(bytesToMb(original.value));
        }

        function syncToOriginal(dispatch) {
            var mb = Number(input.value || 0);
            if (!isFinite(mb)) {
                mb = 50;
            }
            mb = Math.max(1, Math.min(2048, mb));
            input.value = String(mb);
            original.value = String(Math.round(mb * 1048576));
            original.dispatchEvent(new Event('input', {bubbles: true}));
            if (dispatch) {
                original.dispatchEvent(new Event('change', {bubbles: true}));
            }
            return mb;
        }

        input.addEventListener('input', function () { syncToOriginal(false); });
        input.addEventListener('change', function () { syncToOriginal(true); });
        container.appendChild(helper('Лимит одного файла. Значение хранится в байтах, но здесь показывается в мегабайтах.'));
        syncFromOriginal();

        return {
            input: input,
            sync: function () { return syncToOriginal(true); }
        };
    }

    function setupNote(field, text) {
        if (!field) {
            return;
        }
        field.container.appendChild(helper(text));
    }

    function ensureSummary(form) {
        var summary = form.querySelector('[data-sb-disk2-summary]');
        if (summary) {
            return summary;
        }
        summary = document.createElement('div');
        summary.className = 'sb-disk2-summary';
        summary.dataset.sbDisk2Summary = '1';
        form.insertBefore(summary, form.firstChild);
        return summary;
    }

    function selectedText(field) {
        if (!field || !field.control) {
            return '—';
        }
        var control = field.control;
        if (control.tagName === 'SELECT' && control.options && control.selectedIndex >= 0) {
            return String(control.options[control.selectedIndex].textContent || control.value || '—').trim();
        }
        if (control.type === 'checkbox') {
            return control.checked ? 'Да' : 'Нет';
        }
        return String(control.value || '—').trim();
    }

    function updateSummary(fields) {
        var form = fields.__form;
        if (!form) {
            return;
        }
        var summary = ensureSummary(form);
        summary.innerHTML = '';

        var items = [
            ['Корень', selectedText(fields.rootSource)],
            ['Вид', selectedText(fields.viewMode)],
            ['Права', selectedText(fields.permissionMode)],
            ['Файл', fields.__sizeEnhancer ? fields.__sizeEnhancer.input.value + ' МБ' : '—']
        ];

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

    function findActions(scope) {
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
            return text.indexOf('отмен') !== -1 || text.indexOf('закрыть') !== -1;
        }) || null;

        var close = buttons.find(function (button) {
            var raw = String(button.textContent || '').trim();
            var text = textOfButton(button);
            return raw === '×' || raw === '✕' || text === 'x' || text.indexOf('закрыть') !== -1;
        }) || null;

        return { save: save, cancel: cancel, close: close };
    }

    function setupFooter(form, actions) {
        if (!actions.save) {
            return null;
        }

        var footer = document.createElement('div');
        footer.className = 'sb-disk2-actions';

        var note = document.createElement('span');
        note.className = 'sb-disk2-actions__note';
        note.textContent = 'Изменения применятся после сохранения настроек блока.';

        var buttons = document.createElement('div');
        buttons.className = 'sb-disk2-actions__buttons';

        if (actions.cancel) {
            actions.cancel.classList.add('sb-disk2-cancel');
            buttons.appendChild(actions.cancel);
        }

        actions.save.classList.add('sb-disk2-save');
        buttons.appendChild(actions.save);

        footer.appendChild(note);
        footer.appendChild(buttons);
        form.appendChild(footer);

        return footer;
    }

    function setCheckbox(field, checked) {
        if (!field || !field.control) {
            return;
        }
        if (field.control.checked === checked) {
            return;
        }
        field.control.checked = checked;
        field.control.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function setupPermissionPresets(target, fields) {
        var box = document.createElement('div');
        box.className = 'sb-disk2-presets';

        var head = document.createElement('span');
        head.textContent = 'Быстрые наборы действий';
        box.appendChild(head);

        [
            ['Только просмотр', {allowUpload:false, allowCreateFolder:false, allowRename:false, allowDelete:false, allowDownload:true, showSearch:true, showBreadcrumbs:true}],
            ['Работа без удаления', {allowUpload:true, allowCreateFolder:true, allowRename:true, allowDelete:false, allowDownload:true, showSearch:true, showBreadcrumbs:true}],
            ['Все действия', {allowUpload:true, allowCreateFolder:true, allowRename:true, allowDelete:true, allowDownload:true, showSearch:true, showBreadcrumbs:true}]
        ].forEach(function (preset) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'sb-disk2-mini-btn';
            button.textContent = preset[0];
            button.addEventListener('click', function () {
                Object.keys(preset[1]).forEach(function (name) {
                    setCheckbox(fields[name], preset[1][name]);
                });
                updateSummary(fields);
            });
            box.appendChild(button);
        });

        target.insertBefore(box, target.firstChild.nextSibling);
    }

    function hideLegacyContent(form, keepNodes) {
        var keep = new Set(keepNodes.filter(Boolean));
        Array.prototype.slice.call(form.children).forEach(function (child) {
            if (keep.has(child)) {
                return;
            }
            child.classList.add('sb-disk2-legacy-hidden');
        });
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

        actions.save.addEventListener('click', function (event) {
            if (!prepare()) {
                event.preventDefault();
                event.stopImmediatePropagation();
                window.alert('Проверьте максимальный размер файла. Допустимо от 1 до 2048 МБ.');
            }
        }, true);

        scope.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault();
                if (prepare()) {
                    actions.save.click();
                }
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
        if (!scope || !form || form.dataset[MARKER] === '1') {
            return;
        }

        var fields = collectFields(form);
        if (Object.keys(fields).length < 6) {
            return;
        }

        form.dataset[MARKER] = '1';
        scope.classList.add('sb-disk2-dialog');
        form.classList.add('sb-disk2-form');
        heading.classList.add('sb-disk2-title');

        var subtitle = document.createElement('p');
        subtitle.className = 'sb-disk2-subtitle';
        subtitle.textContent = 'Настройте отображение, загрузку и доступ к файлам. Права блока не расширяют права пользователя в Bitrix24.';
        heading.insertAdjacentElement('afterend', subtitle);

        var shell = document.createElement('div');
        shell.className = 'sb-disk2-shell';

        var hero = document.createElement('div');
        hero.className = 'sb-disk2-hero';
        hero.innerHTML = '<div><strong>Параметры блока</strong><span>Быстрые настройки для списка файлов, загрузки и действий пользователя.</span></div>';
        shell.appendChild(hero);

        var layout = document.createElement('div');
        layout.className = 'sb-disk2-layout';
        shell.appendChild(layout);

        var mainCol = document.createElement('div');
        mainCol.className = 'sb-disk2-main';
        var sideCol = document.createElement('aside');
        sideCol.className = 'sb-disk2-side';
        layout.appendChild(mainCol);
        layout.appendChild(sideCol);

        var main = createSection('Основное', 'Название, корневая папка и то, как список файлов открывается пользователю.');
        ['title', 'rootSource', 'viewMode', 'sortBy', 'sortDirection'].forEach(function (name) {
            appendField(main.grid, fields, name);
        });
        mainCol.appendChild(main.section);

        var upload = createSection('Загрузка файлов', 'Ограничения применяются к загрузке через этот блок.');
        ['maxFileSize', 'extensions'].forEach(function (name) {
            appendField(upload.grid, fields, name);
        });
        mainCol.appendChild(upload.section);

        var access = createSection('Доступ и возможности', 'Здесь задаются разрешённые действия и элементы интерфейса.');
        appendField(access.grid, fields, 'permissionMode');
        ['useSiteRoot', 'allowUpload', 'allowCreateFolder', 'allowRename', 'allowDelete', 'allowDownload', 'showSearch', 'showBreadcrumbs'].forEach(function (name) {
            appendField(access.grid, fields, name);
        });
        setupPermissionPresets(access.section, fields);
        sideCol.appendChild(access.section);

        form.appendChild(shell);

        fields.__form = form;
        fields.__sizeEnhancer = setupMaxFileSize(fields.maxFileSize);
        fields.__extensionsEnhancer = setupExtensions(fields.extensions);
        setupNote(fields.rootSource, 'Определяет папку Bitrix.Диска, которая будет открываться как корневая для этого блока.');
        setupNote(fields.permissionMode, 'Переключатели ниже ограничивают доступные действия блока и не могут расширить серверные права пользователя.');

        var actions = findActions(scope);
        var footer = setupFooter(form, actions);
        setupSaveValidation(scope, fields, actions);
        updateSummary(fields);

        form.addEventListener('change', function () { updateSummary(fields); });
        form.addEventListener('input', function () { updateSummary(fields); });

        hideLegacyContent(form, [
            ensureSummary(form),
            subtitle,
            shell,
            footer
        ]);
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
        new MutationObserver(function () {
            scheduleDecorate();
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
