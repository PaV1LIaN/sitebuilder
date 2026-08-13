/* =========================================================
   SITEBUILDER / LAYOUT 2.0 / STAGE 2
   Visual site shell editor + DnD/relocate/duplicate.
   No autosave.
   ========================================================= */
(function () {
    'use strict';

    var config = window.SB_LAYOUT_CONFIG || {};
    if (!config.apiUrl || !config.siteId) return;

    var SITE_ID = Number(config.siteId || 0);
    var ZONES = ['header', 'left', 'right', 'footer'];

    var ZONE_META = {
        header: {title: 'Шапка', icon: '▰', description: 'Дополнительные блоки рядом с логотипом и меню'},
        left: {title: 'Левая панель', icon: '◧', description: 'Меню страниц или собственные layout-блоки'},
        right: {title: 'Правая панель', icon: '◨', description: 'Дополнительный контент справа от страницы'},
        footer: {title: 'Подвал', icon: '▱', description: 'Общие блоки в нижней части сайта'}
    };

    var BLOCK_META = {
        text: {title: 'Текст', icon: '¶'},
        heading: {title: 'Заголовок', icon: 'H'},
        button: {title: 'Кнопка', icon: '↗'},
        html: {title: 'HTML', icon: '</>'}
    };

    var state = {
        layout: null,
        draftSettings: null,
        settingsDirty: false,
        currentBlockId: 0,
        blockDirty: false,
        syncing: false,
        busy: false
    };

    var drag = {
        pending: null,
        active: false,
        sourceId: 0,
        sourceZone: '',
        sourceIndex: -1,
        targetZone: '',
        targetSlotIndex: -1,
        targetIndex: -1,
        noOp: false,
        ghost: null
    };

    function node(id) {
        return document.getElementById(id);
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char];
        });
    }

    function clamp(value, min, max) {
        value = Number(value);
        if (!isFinite(value)) value = min;
        return Math.max(min, Math.min(max, value));
    }

    function debug(value) {
        var output = node('layoutDebug');
        if (!output) return;
        try {
            output.textContent = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
        } catch (error) {
            output.textContent = String(value);
        }
    }

    function notice(text, type, timeout) {
        var target = node('layoutNotice');
        if (!target) return;

        target.textContent = String(text || '');
        target.setAttribute('data-type', type || 'info');
        target.hidden = !text;

        if (text && timeout) {
            window.setTimeout(function () {
                if (target.textContent === String(text)) target.hidden = true;
            }, Number(timeout));
        }
    }

    function setSaveState(text, status) {
        var target = node('layoutSaveState');
        if (!target) return;
        target.textContent = text || 'Готово';
        target.setAttribute('data-state', status || 'ready');
    }

    function setBlockState(text, dirty) {
        var target = node('layoutBlockState');
        if (target) {
            target.textContent = text;
            target.setAttribute('data-state', dirty ? 'dirty' : 'ready');
        }

        var save = node('saveLayoutBlockBtn');
        if (save) save.disabled = !dirty || state.busy;
    }

    function requestBody(action, data) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('sessid', String(config.sessid || ''));

        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (value === undefined || value === null) return;
            body.set(key, String(value));
        });

        return body;
    }

    async function api(action, data) {
        var response = await fetch(config.apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: requestBody(action, data).toString()
        });

        var text = await response.text();
        var payload = null;

        try {
            payload = JSON.parse(text);
        } catch (error) {
            debug({action: action, httpStatus: response.status, response: text});
            throw new Error('BAD_API_RESPONSE');
        }

        debug(payload);

        if (!response.ok || !payload || payload.ok !== true) {
            var apiError = new Error(String(payload && payload.error || 'API_ERROR'));
            apiError.payload = payload;
            apiError.httpStatus = response.status;
            throw apiError;
        }

        return payload;
    }

    function handleError(error, fallback) {
        console.error(error);
        var code = String(error && error.message || '');

        if (code === 'VERSION_CONFLICT' || code === 'LAYOUT_VERSION_CONFLICT') {
            notice(
                'Каркас изменился в другой сессии. Нажмите «Обновить», затем повторите изменение.',
                'error'
            );
        } else {
            notice(fallback || 'Не удалось выполнить действие.', 'error');
        }

        setSaveState('Ошибка', 'error');
    }

    function normalizeSettings(settings) {
        settings = settings && typeof settings === 'object' ? settings : {};
        var leftMode = String(settings.leftMode || 'blocks');

        if (['blocks', 'menu'].indexOf(leftMode) === -1) leftMode = 'blocks';

        return {
            showHeader: settings.showHeader !== false,
            showFooter: settings.showFooter !== false,
            showLeft: settings.showLeft !== false,
            showRight: !!settings.showRight,
            leftWidth: clamp(settings.leftWidth == null ? 260 : settings.leftWidth, 120, 800),
            rightWidth: clamp(settings.rightWidth == null ? 260 : settings.rightWidth, 120, 800),
            leftMode: leftMode
        };
    }

    function version() {
        return Math.max(1, Number(state.layout && state.layout.version || 1));
    }

    function renderVersion() {
        var badge = node('layoutVersionBadge');
        if (badge) badge.textContent = 'версия ' + version();
    }

    function totalBlocks() {
        if (!state.layout || !state.layout.zones) return 0;

        return ZONES.reduce(function (sum, zone) {
            return sum + (Array.isArray(state.layout.zones[zone]) ? state.layout.zones[zone].length : 0);
        }, 0);
    }

    function zoneBlocks(zone) {
        if (!state.layout || !state.layout.zones) return [];

        var blocks = Array.isArray(state.layout.zones[zone])
            ? state.layout.zones[zone]
            : [];

        return blocks.slice().sort(function (a, b) {
            var bySort = Number(a.sort || 0) - Number(b.sort || 0);
            return bySort !== 0 ? bySort : Number(a.id || 0) - Number(b.id || 0);
        });
    }

    function findBlock(blockId) {
        blockId = Number(blockId || 0);
        if (blockId <= 0) return null;

        for (var i = 0; i < ZONES.length; i++) {
            var zone = ZONES[i];
            var blocks = zoneBlocks(zone);

            for (var j = 0; j < blocks.length; j++) {
                if (Number(blocks[j].id || 0) === blockId) {
                    return {zone: zone, block: blocks[j], index: j, total: blocks.length};
                }
            }
        }

        return null;
    }

    function zoneEnabled(zone) {
        var settings = state.draftSettings || normalizeSettings(state.layout && state.layout.settings);
        if (zone === 'header') return !!settings.showHeader;
        if (zone === 'footer') return !!settings.showFooter;
        if (zone === 'left') return !!settings.showLeft;
        if (zone === 'right') return !!settings.showRight;
        return true;
    }

    function blockMeta(type) {
        type = String(type || '');
        return BLOCK_META[type] || {title: type || 'Блок', icon: '◆'};
    }

    function zoneMeta(zone) {
        return ZONE_META[zone] || {title: zone, icon: '◇', description: ''};
    }

    function stripTags(value) {
        var box = document.createElement('div');
        box.innerHTML = String(value || '');
        return String(box.textContent || box.innerText || '').replace(/\s+/g, ' ').trim();
    }

    function truncate(value, length) {
        value = String(value || '');
        length = Number(length || 80);
        return value.length > length ? value.slice(0, length - 1) + '…' : value;
    }

    function blockPreview(block) {
        block = block || {};
        var type = String(block.type || '');
        var content = block.content && typeof block.content === 'object' ? block.content : {};

        if (type === 'text') {
            return truncate(stripTags(content.text || content.html || content.content || '') || 'Новый текстовый блок', 95);
        }

        if (type === 'heading') {
            return truncate(content.text || 'Новый заголовок', 95);
        }

        if (type === 'button') {
            return truncate(
                (content.text || content.label || 'Кнопка') + (content.href ? ' → ' + content.href : ''),
                95
            );
        }

        if (type === 'html') {
            return truncate(stripTags(content.html || '') || 'HTML-блок', 95);
        }

        try {
            return truncate(JSON.stringify(content), 95);
        } catch (error) {
            return 'Layout-блок';
        }
    }

    function updateSettingsDisabled() {
        var settings = state.draftSettings;
        if (!settings) return;

        var leftDisabled = !settings.showLeft;
        var rightDisabled = !settings.showRight;

        [node('leftModeCard'), node('leftWidthCard')].forEach(function (card) {
            if (card) card.classList.toggle('is-disabled', leftDisabled);
        });

        var rightCard = node('rightWidthCard');
        if (rightCard) rightCard.classList.toggle('is-disabled', rightDisabled);

        [node('leftWidth'), node('leftWidthRange')].forEach(function (control) {
            if (control) control.disabled = leftDisabled;
        });

        [node('rightWidth'), node('rightWidthRange')].forEach(function (control) {
            if (control) control.disabled = rightDisabled;
        });

        document.querySelectorAll('[data-left-mode]').forEach(function (button) {
            button.disabled = leftDisabled;
        });
    }

    function updateLeftModeUi() {
        var settings = state.draftSettings;
        if (!settings) return;

        node('leftMode').value = settings.leftMode;

        document.querySelectorAll('[data-left-mode]').forEach(function (button) {
            button.classList.toggle(
                'is-active',
                button.getAttribute('data-left-mode') === settings.leftMode
            );
        });

        var hint = node('leftModeHint');
        if (hint) {
            hint.textContent = settings.leftMode === 'menu'
                ? 'Меню строится из страниц сайта. Сохранённые layout-блоки левой зоны остаются в каркасе и могут использоваться как резервное содержимое.'
                : 'В левой панели выводятся layout-блоки, добавленные в зону «Левая панель».';
        }
    }

    function renderSettings() {
        if (!state.layout) return;

        state.syncing = true;

        try {
            state.draftSettings = normalizeSettings(state.layout.settings);
            var settings = state.draftSettings;

            node('showHeader').checked = settings.showHeader;
            node('showFooter').checked = settings.showFooter;
            node('showLeft').checked = settings.showLeft;
            node('showRight').checked = settings.showRight;
            node('leftWidth').value = settings.leftWidth;
            node('leftWidthRange').value = settings.leftWidth;
            node('rightWidth').value = settings.rightWidth;
            node('rightWidthRange').value = settings.rightWidth;

            updateLeftModeUi();
            updateSettingsDisabled();
        } finally {
            state.syncing = false;
        }

        state.settingsDirty = false;
        node('saveSettingsBtn').disabled = true;
        setSaveState('Сохранено', 'ready');
    }

    function settingsChanged() {
        if (state.syncing || !state.draftSettings) return;

        state.settingsDirty = true;
        node('saveSettingsBtn').disabled = !!state.busy;
        setSaveState('Есть несохранённые изменения', 'dirty');
        updateSettingsDisabled();
        updateLeftModeUi();
        renderCanvas();
    }

    function readToggle(id, key) {
        var control = node(id);
        if (control && state.draftSettings) state.draftSettings[key] = !!control.checked;
        settingsChanged();
    }

    function setWidth(side, raw) {
        if (!state.draftSettings) return;

        var value = clamp(raw, 120, 800);
        var key = side === 'left' ? 'leftWidth' : 'rightWidth';
        var number = node(side + 'Width');
        var range = node(side + 'WidthRange');

        state.draftSettings[key] = value;
        if (number && Number(number.value) !== value) number.value = value;
        if (range && Number(range.value) !== value) range.value = value;

        settingsChanged();
    }

    function setLeftMode(mode) {
        if (!state.draftSettings) return;
        state.draftSettings.leftMode = mode === 'menu' ? 'menu' : 'blocks';
        settingsChanged();
    }

    function renderBlockCard(zone, block, index, total) {
        var id = Number(block.id || 0);
        var meta = blockMeta(block.type);
        var selected = id === Number(state.currentBlockId || 0);

        return ''
            + '<article class="sb-layout2-block' + (selected ? ' is-selected' : '') + '"'
            + ' data-layout-block-id="' + id + '"'
            + ' data-layout-block-zone="' + escapeHtml(zone) + '">'
            + '  <button'
            + ' class="sb-layout2-block__drag"'
            + ' type="button"'
            + ' data-layout-drag-handle="' + id + '"'
            + ' title="Перетащить блок"'
            + ' aria-label="Перетащить блок">⋮⋮</button>'
            + '  <button class="sb-layout2-block__main" type="button" data-layout-edit="' + id + '">'
            + '    <span class="sb-layout2-block__icon">' + escapeHtml(meta.icon) + '</span>'
            + '    <span class="sb-layout2-block__copy"><strong>' + escapeHtml(meta.title) + '</strong><small>'
            + escapeHtml(blockPreview(block)) + '</small></span>'
            + '  </button>'
            + '  <span class="sb-layout2-block__actions">'
            + '    <button type="button" data-layout-duplicate="' + id + '" title="Дублировать" aria-label="Дублировать блок">⧉</button>'
            + '    <button type="button" data-layout-move="up" data-layout-id="' + id + '"' + (index === 0 ? ' disabled' : '') + ' title="Выше" aria-label="Переместить выше">↑</button>'
            + '    <button type="button" data-layout-move="down" data-layout-id="' + id + '"' + (index === total - 1 ? ' disabled' : '') + ' title="Ниже" aria-label="Переместить ниже">↓</button>'
            + '    <button type="button" class="is-danger" data-layout-delete="' + id + '" title="Удалить" aria-label="Удалить блок">×</button>'
            + '  </span>'
            + '</article>';
    }

    function addMenuKey(zone, targetIndex, prefix) {
        return String(prefix || 'slot')
            + ':'
            + String(zone)
            + ':'
            + String(Number(targetIndex || 0));
    }

    function renderAddMenu(zone, targetIndex, key) {
        targetIndex = Math.max(0, Number(targetIndex || 0));
        key = key || addMenuKey(zone, targetIndex, 'slot');

        return ''
            + '<div class="sb-layout2-add-menu" data-layout-add-menu="' + escapeHtml(key) + '" hidden>'
            + '<button type="button" data-layout-add-type="text" data-layout-zone="' + escapeHtml(zone) + '" data-layout-target-index="' + targetIndex + '"><span>¶</span><strong>Текст</strong></button>'
            + '<button type="button" data-layout-add-type="heading" data-layout-zone="' + escapeHtml(zone) + '" data-layout-target-index="' + targetIndex + '"><span>H</span><strong>Заголовок</strong></button>'
            + '<button type="button" data-layout-add-type="button" data-layout-zone="' + escapeHtml(zone) + '" data-layout-target-index="' + targetIndex + '"><span>↗</span><strong>Кнопка</strong></button>'
            + '<button type="button" data-layout-add-type="html" data-layout-zone="' + escapeHtml(zone) + '" data-layout-target-index="' + targetIndex + '"><span>&lt;/&gt;</span><strong>HTML</strong></button>'
            + '</div>';
    }

    function renderInsertSlot(zone, index, empty) {
        index = Math.max(0, Number(index || 0));
        var key = addMenuKey(zone, index, 'slot');

        return ''
            + '<div class="sb-layout2-insert-slot' + (empty ? ' is-empty' : '') + '"'
            + ' data-layout-drop-slot'
            + ' data-layout-zone="' + escapeHtml(zone) + '"'
            + ' data-layout-index="' + index + '">'
            + '  <span class="sb-layout2-insert-line"></span>'
            + '  <div class="sb-layout2-add-wrap sb-layout2-inline-add">'
            + '    <button'
            + ' type="button"'
            + ' class="sb-layout2-inline-add__button"'
            + ' data-layout-add-toggle="' + escapeHtml(key) + '"'
            + ' data-layout-zone="' + escapeHtml(zone) + '"'
            + ' data-layout-target-index="' + index + '"'
            + ' title="' + (empty ? 'Добавить первый блок' : 'Добавить блок сюда') + '">'
            + (empty ? '＋ Добавить блок' : '＋')
            + '    </button>'
            + renderAddMenu(zone, index, key)
            + '  </div>'
            + '</div>';
    }

    function renderZone(zone) {
        var meta = zoneMeta(zone);
        var blocks = zoneBlocks(zone);
        var enabled = zoneEnabled(zone);
        var isLeftMenu = zone === 'left' && state.draftSettings.leftMode === 'menu';
        var body = '';

        if (isLeftMenu) {
            body += ''
                + '<div class="sb-layout2-menu-preview"><span>☰</span><div><strong>Меню страниц</strong><small>Основное содержимое левой панели</small></div></div>';

            if (blocks.length) {
                body += '<div class="sb-layout2-zone-note">' + blocks.length + ' layout-блок(ов) сохранено в этой зоне</div>';
            }
        }

        body += '<div class="sb-layout2-block-list" data-layout-block-list="' + escapeHtml(zone) + '">';

        for (var i = 0; i <= blocks.length; i++) {
            body += renderInsertSlot(zone, i, blocks.length === 0);

            if (i < blocks.length) {
                body += renderBlockCard(zone, blocks[i], i, blocks.length);
            }
        }

        body += '</div>';

        var specialNote = '';

        if (zone === 'header') {
            specialNote = 'Логотип и основное меню добавляются шапкой автоматически.';
        } else if (zone === 'footer' && blocks.length === 0) {
            specialNote = 'Пустой подвал не выводится на публичной странице.';
        } else if (zone === 'right' && blocks.length === 0) {
            specialNote = 'Пустая правая панель не занимает место на странице.';
        }

        var headerKey = addMenuKey(zone, blocks.length, 'head');

        return ''
            + '<section class="sb-layout2-zone' + (enabled ? '' : ' is-disabled') + ' sb-layout2-zone--' + escapeHtml(zone) + '" data-layout-zone-card="' + escapeHtml(zone) + '">'
            + '<header class="sb-layout2-zone__head">'
            + '<div class="sb-layout2-zone__identity"><span class="sb-layout2-zone__icon">' + escapeHtml(meta.icon) + '</span><div>'
            + '<strong>' + escapeHtml(meta.title) + '</strong><small>' + (enabled ? escapeHtml(meta.description) : 'Скрыта на сайте') + '</small>'
            + '</div></div>'
            + '<div class="sb-layout2-zone__tools"><span class="sb-layout2-zone__count">' + blocks.length + '</span>'
            + '<div class="sb-layout2-add-wrap"><button'
            + ' type="button"'
            + ' class="sb-layout2-zone__add"'
            + ' data-layout-add-toggle="' + escapeHtml(headerKey) + '"'
            + ' data-layout-zone="' + escapeHtml(zone) + '"'
            + ' data-layout-target-index="' + blocks.length + '">＋ Добавить</button>'
            + renderAddMenu(zone, blocks.length, headerKey)
            + '</div></div>'
            + '</header>'
            + (specialNote ? '<div class="sb-layout2-zone__note">' + escapeHtml(specialNote) + '</div>' : '')
            + '<div class="sb-layout2-zone__body">' + body + '</div>'
            + '</section>';
    }

    function previewSideWidth(side) {
        var settings = state.draftSettings;
        var value = side === 'left' ? settings.leftWidth : settings.rightWidth;
        return Math.round(clamp(value * 0.55, 118, 310));
    }

    function renderPagePlaceholder() {
        return ''
            + '<main class="sb-layout2-page-content">'
            + '<div class="sb-layout2-page-content__head"><span>Контент страницы</span><small>Редактируется отдельно</small></div>'
            + '<div class="sb-layout2-page-ghost"><div class="is-title"></div><div></div><div></div><div class="is-short"></div></div>'
            + '<div class="sb-layout2-page-section-ghost"><i></i><i></i><i></i></div>'
            + '</main>';
    }

    function renderCanvas() {
        var canvas = node('layoutCanvas');
        if (!canvas || !state.layout || !state.draftSettings) return;

        var settings = state.draftSettings;
        var middleStyle = '--sb-layout2-left-preview:' + previewSideWidth('left') + 'px;'
            + '--sb-layout2-right-preview:' + previewSideWidth('right') + 'px;';

        canvas.innerHTML = ''
            + '<div class="sb-layout2-frame">'
            + renderZone('header')
            + '<div class="sb-layout2-middle' + (settings.showLeft ? ' has-left' : ' no-left') + (settings.showRight ? ' has-right' : ' no-right') + '" style="' + middleStyle + '">'
            + renderZone('left')
            + renderPagePlaceholder()
            + renderZone('right')
            + '</div>'
            + renderZone('footer')
            + '</div>'
            + '<div class="sb-layout2-preview-meta">'
            + '<span>' + totalBlocks() + ' layout-блок(ов)</span>'
            + '<span>Левая: ' + Number(settings.leftWidth) + ' px</span>'
            + '<span>Правая: ' + Number(settings.rightWidth) + ' px</span>'
            + '</div>';
    }

    function closeAddMenus(exceptKey) {
        document.querySelectorAll('[data-layout-add-menu]').forEach(function (menu) {
            var keep = exceptKey && menu.getAttribute('data-layout-add-menu') === exceptKey;
            menu.hidden = !keep;
        });
    }

    function renderSelectOptions(values, current) {
        return values.map(function (item) {
            return '<option value="' + escapeHtml(item[0]) + '"'
                + (String(item[0]) === String(current) ? ' selected' : '') + '>'
                + escapeHtml(item[1]) + '</option>';
        }).join('');
    }

    function blockFieldsHtml(block) {
        var type = String(block.type || '');
        var content = block.content && typeof block.content === 'object' ? block.content : {};
        var props = block.props && typeof block.props === 'object' ? block.props : {};

        if (type === 'text') {
            var text = content.text || content.html || content.content || '';
            var align = props.align || content.align || 'left';
            var size = clamp(props.size || content.size || 16, 12, 72);

            return ''
                + '<div class="sb-field"><label for="blockText">Текст</label><textarea class="sb-textarea" id="blockText">' + escapeHtml(text) + '</textarea></div>'
                + '<div class="sb-layout2-form-grid">'
                + '<div class="sb-field"><label for="blockTextAlign">Выравнивание</label><select class="sb-select" id="blockTextAlign">'
                + renderSelectOptions([['left','Слева'],['center','По центру'],['right','Справа'],['justify','По ширине']], align)
                + '</select></div>'
                + '<div class="sb-field"><label for="blockTextSize">Размер текста</label><div class="sb-layout2-input-unit">'
                + '<input class="sb-input" type="number" min="12" max="72" id="blockTextSize" value="' + Number(size) + '"><span>px</span>'
                + '</div></div></div>';
        }

        if (type === 'heading') {
            var level = props.level || content.level || 'h2';
            var headingAlign = props.align || content.align || 'left';

            return ''
                + '<div class="sb-field"><label for="blockHeadingText">Текст заголовка</label><input class="sb-input" id="blockHeadingText" value="' + escapeHtml(content.text || '') + '"></div>'
                + '<div class="sb-layout2-form-grid">'
                + '<div class="sb-field"><label for="blockHeadingLevel">Уровень</label><select class="sb-select" id="blockHeadingLevel">'
                + renderSelectOptions([['h1','H1'],['h2','H2'],['h3','H3'],['h4','H4'],['h5','H5'],['h6','H6']], level)
                + '</select></div>'
                + '<div class="sb-field"><label for="blockHeadingAlign">Выравнивание</label><select class="sb-select" id="blockHeadingAlign">'
                + renderSelectOptions([['left','Слева'],['center','По центру'],['right','Справа']], headingAlign)
                + '</select></div></div>';
        }

        if (type === 'button') {
            var buttonText = content.text || content.label || 'Кнопка';
            var target = content.target || '_self';
            var style = props.style || content.style || 'primary';
            var buttonAlign = props.align || content.align || 'left';

            return ''
                + '<div class="sb-field"><label for="blockButtonText">Текст кнопки</label><input class="sb-input" id="blockButtonText" value="' + escapeHtml(buttonText) + '"></div>'
                + '<div class="sb-field"><label for="blockButtonHref">Ссылка</label><input class="sb-input" id="blockButtonHref" value="' + escapeHtml(content.href || '#') + '" placeholder="/page/ или https://..."></div>'
                + '<div class="sb-layout2-form-grid">'
                + '<div class="sb-field"><label for="blockButtonStyle">Стиль</label><select class="sb-select" id="blockButtonStyle">'
                + renderSelectOptions([['primary','Основная'],['secondary','Вторичная'],['outline','Контур'],['ghost','Без фона']], style)
                + '</select></div>'
                + '<div class="sb-field"><label for="blockButtonAlign">Выравнивание</label><select class="sb-select" id="blockButtonAlign">'
                + renderSelectOptions([['left','Слева'],['center','По центру'],['right','Справа']], buttonAlign)
                + '</select></div></div>'
                + '<label class="sb-layout2-checkbox"><input type="checkbox" id="blockButtonBlank"' + (target === '_blank' ? ' checked' : '') + '><span>Открывать в новой вкладке</span></label>';
        }

        if (type === 'html') {
            return ''
                + '<div class="sb-field"><label for="blockHtml">HTML</label>'
                + '<textarea class="sb-textarea sb-layout2-code" id="blockHtml" spellcheck="false">' + escapeHtml(content.html || '') + '</textarea>'
                + '<small class="sb-layout2-field-help">HTML используется как layout-блок. Проверяйте разметку перед сохранением.</small></div>';
        }

        return '<div class="sb-layout2-unknown-block"><strong>Для типа «' + escapeHtml(type)
            + '» визуальный редактор не определён.</strong><span>Используйте JSON в расширенном режиме ниже.</span></div>';
    }

    function renderInspector() {
        var empty = node('layoutInspectorEmpty');
        var form = node('layoutInspectorForm');
        var found = findBlock(state.currentBlockId);

        if (!found) {
            state.currentBlockId = 0;
            state.blockDirty = false;
            if (empty) empty.hidden = false;
            if (form) form.hidden = true;
            return;
        }

        var block = found.block;
        var meta = blockMeta(block.type);
        var zone = zoneMeta(found.zone);

        empty.hidden = true;
        form.hidden = false;

        node('inspectorZone').textContent = zone.title;
        node('inspectorTitle').textContent = meta.title;
        node('inspectorMeta').textContent = '#' + Number(block.id || 0) + ' · '
            + (zoneEnabled(found.zone) ? 'зона включена' : 'зона скрыта на сайте');

        node('layoutBlockFields').innerHTML = blockFieldsHtml(block);
        node('blockAdvancedContent').value = JSON.stringify(block.content || {}, null, 2);
        node('blockAdvancedProps').value = JSON.stringify(block.props || {}, null, 2);

        state.blockDirty = false;
        setBlockState('Без изменений', false);
    }

    function selectBlock(blockId) {
        if (state.blockDirty && Number(blockId) !== Number(state.currentBlockId)) {
            if (!window.confirm('У выбранного блока есть несохранённые изменения. Переключиться и потерять их?')) return;
        }

        state.currentBlockId = Number(blockId || 0);
        state.blockDirty = false;
        renderCanvas();
        renderInspector();

        if (window.innerWidth < 1120) {
            node('layoutInspector').scrollIntoView({behavior: 'smooth', block: 'start'});
        }
    }

    function clearSelection() {
        if (state.blockDirty && !window.confirm('Закрыть редактор и потерять несохранённые изменения блока?')) return;

        state.currentBlockId = 0;
        state.blockDirty = false;
        renderCanvas();
        renderInspector();
    }

    function markBlockDirty() {
        if (!state.currentBlockId || state.syncing) return;
        state.blockDirty = true;
        setBlockState('Есть несохранённые изменения', true);
    }

    function parseJsonField(id, label) {
        var raw = String(node(id).value || '{}');

        try {
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error('NOT_OBJECT');
            return parsed;
        } catch (error) {
            throw new Error(label + ' должен быть валидным JSON-объектом');
        }
    }

    function buildBlockPayload() {
        var found = findBlock(state.currentBlockId);
        if (!found) return null;

        var block = found.block;
        var type = String(block.type || '');
        var content = parseJsonField('blockAdvancedContent', 'Content');
        var props = parseJsonField('blockAdvancedProps', 'Props');

        if (type === 'text') {
            content.text = String(node('blockText').value || '');
            props.align = String(node('blockTextAlign').value || 'left');
            props.size = clamp(node('blockTextSize').value, 12, 72);
        } else if (type === 'heading') {
            content.text = String(node('blockHeadingText').value || '');
            props.level = String(node('blockHeadingLevel').value || 'h2');
            props.align = String(node('blockHeadingAlign').value || 'left');
        } else if (type === 'button') {
            var text = String(node('blockButtonText').value || '').trim() || 'Кнопка';
            content.text = text;
            content.label = text;
            content.href = String(node('blockButtonHref').value || '#').trim() || '#';
            content.target = node('blockButtonBlank').checked ? '_blank' : '_self';
            props.style = String(node('blockButtonStyle').value || 'primary');
            props.align = String(node('blockButtonAlign').value || 'left');
        } else if (type === 'html') {
            content.html = String(node('blockHtml').value || '');
        }

        return {content: content, props: props};
    }

    async function loadLayout(force) {
        if (!force && (state.settingsDirty || state.blockDirty)) {
            if (!window.confirm('Обновить данные с сервера и потерять несохранённые изменения?')) return;
        }

        state.busy = true;
        setSaveState('Загрузка…', 'working');

        try {
            var response = await api('layout.get', {siteId: SITE_ID});
            state.layout = response.layout || null;
            state.currentBlockId = 0;
            state.blockDirty = false;

            renderVersion();
            renderSettings();
            renderCanvas();
            renderInspector();
            notice('', 'info');
            setSaveState('Сохранено', 'ready');
        } catch (error) {
            handleError(error, 'Не удалось загрузить каркас сайта.');
            node('layoutCanvas').innerHTML = '<div class="sb-layout2-loading is-error">Не удалось загрузить каркас.</div>';
        } finally {
            state.busy = false;
            node('saveSettingsBtn').disabled = !state.settingsDirty;
        }
    }

    async function saveSettings() {
        if (!state.layout || !state.draftSettings || !state.settingsDirty || state.busy) return;

        state.busy = true;
        node('saveSettingsBtn').disabled = true;
        setSaveState('Сохраняю…', 'working');

        try {
            var response = await api('layout.updateSettings', {
                siteId: SITE_ID,
                expectedVersion: version(),
                settings: JSON.stringify(state.draftSettings)
            });

            state.layout = response.layout || state.layout;
            renderVersion();
            renderSettings();
            renderCanvas();
            notice('Каркас сайта сохранён.', 'success', 2200);
        } catch (error) {
            handleError(error, 'Не удалось сохранить настройки каркаса.');
            node('saveSettingsBtn').disabled = false;
        } finally {
            state.busy = false;
        }
    }

    async function addBlock(zone, type, targetIndex) {
        if (state.busy || ZONES.indexOf(zone) === -1) return;

        if (state.blockDirty) {
            notice(
                'Сначала сохраните или закройте несохранённые изменения текущего блока.',
                'error',
                3200
            );
            closeAddMenus();
            return;
        }

        var blocks = zoneBlocks(zone);
        targetIndex = targetIndex == null
            ? blocks.length
            : clamp(targetIndex, 0, blocks.length);

        state.busy = true;
        closeAddMenus();

        try {
            var response = await api('layout.block.create', {
                siteId: SITE_ID,
                expectedVersion: version(),
                zone: zone,
                type: type,
                targetIndex: targetIndex
            });

            state.layout = response.layout || state.layout;
            var newId = Number(response.block && response.block.id || 0);

            renderVersion();

            if (newId > 0) {
                state.currentBlockId = newId;
            }

            renderCanvas();

            if (newId > 0) {
                renderInspector();
                node('layoutInspector').scrollIntoView({behavior: 'smooth', block: 'nearest'});
            }

            notice('Блок добавлен в выбранное место.', 'success', 1800);
        } catch (error) {
            handleError(error, 'Не удалось добавить layout-блок.');
        } finally {
            state.busy = false;
        }
    }

    async function saveBlock() {
        if (!state.currentBlockId || !state.blockDirty || state.busy) return;

        var payload = null;

        try {
            payload = buildBlockPayload();
        } catch (error) {
            notice(error.message, 'error');
            return;
        }

        if (!payload) return;

        state.busy = true;
        setBlockState('Сохраняю…', true);

        try {
            var currentId = Number(state.currentBlockId);
            var response = await api('layout.block.update', {
                siteId: SITE_ID,
                id: currentId,
                expectedVersion: version(),
                content: JSON.stringify(payload.content),
                props: JSON.stringify(payload.props)
            });

            state.layout = response.layout || state.layout;
            state.blockDirty = false;

            renderVersion();
            renderCanvas();
            renderInspector();
            notice('Блок сохранён.', 'success', 1800);
        } catch (error) {
            handleError(error, 'Не удалось сохранить layout-блок.');
            setBlockState('Не сохранено', true);
        } finally {
            state.busy = false;
        }
    }

    async function deleteBlock(blockId) {
        blockId = Number(blockId || 0);
        if (blockId <= 0 || state.busy) return;

        var found = findBlock(blockId);
        if (!found) return;

        var meta = blockMeta(found.block.type);
        if (!window.confirm('Удалить «' + meta.title + '» #' + blockId + '?')) return;

        state.busy = true;

        try {
            var response = await api('layout.block.delete', {
                siteId: SITE_ID,
                id: blockId,
                expectedVersion: version()
            });

            state.layout = response.layout || state.layout;

            if (Number(state.currentBlockId) === blockId) {
                state.currentBlockId = 0;
                state.blockDirty = false;
            }

            renderVersion();
            renderCanvas();

            if (
                !state.blockDirty
                || Number(state.currentBlockId) === 0
            ) {
                renderInspector();
            }

            notice('Блок удалён.', 'success', 1800);
        } catch (error) {
            handleError(error, 'Не удалось удалить layout-блок.');
        } finally {
            state.busy = false;
        }
    }

    async function moveBlock(blockId, direction) {
        blockId = Number(blockId || 0);

        if (blockId <= 0 || state.busy || ['up', 'down'].indexOf(direction) === -1) return;

        state.busy = true;

        try {
            var response = await api('layout.block.move', {
                siteId: SITE_ID,
                id: blockId,
                dir: direction,
                expectedVersion: version()
            });

            state.layout = response.layout || state.layout;
            renderVersion();
            renderCanvas();

            /*
             * Moving another layout block must not wipe unsaved
             * inspector edits of the currently selected block.
             */
            if (
                state.currentBlockId
                && !state.blockDirty
            ) {
                renderInspector();
            }
        } catch (error) {
            handleError(error, 'Не удалось переместить layout-блок.');
        } finally {
            state.busy = false;
        }
    }

    function refreshInspectorLocationOnly() {
        if (!state.currentBlockId) return;

        var found = findBlock(state.currentBlockId);
        if (!found) return;

        var zone = zoneMeta(found.zone);

        if (node('inspectorZone')) {
            node('inspectorZone').textContent = zone.title;
        }

        if (node('inspectorMeta')) {
            node('inspectorMeta').textContent = '#'
                + Number(found.block.id || 0)
                + ' · '
                + (zoneEnabled(found.zone) ? 'зона включена' : 'зона скрыта на сайте');
        }
    }

    async function relocateBlock(blockId, targetZone, targetIndex) {
        blockId = Number(blockId || 0);

        if (
            blockId <= 0
            || state.busy
            || ZONES.indexOf(targetZone) === -1
        ) {
            return;
        }

        var source = findBlock(blockId);
        if (!source) return;

        targetIndex = Math.max(0, Number(targetIndex || 0));
        state.busy = true;

        try {
            var response = await api('layout.block.relocate', {
                siteId: SITE_ID,
                id: blockId,
                targetZone: targetZone,
                targetIndex: targetIndex,
                expectedVersion: version()
            });

            state.layout = response.layout || state.layout;

            /*
             * A dragged selected block remains selected. If another block
             * is currently being edited with unsaved content, its inspector
             * is left untouched.
             */
            if (!state.blockDirty || Number(state.currentBlockId) === blockId) {
                state.currentBlockId = blockId;
            }

            renderVersion();
            renderCanvas();

            if (state.blockDirty) {
                refreshInspectorLocationOnly();
            } else {
                renderInspector();
            }

            var targetMeta = zoneMeta(targetZone);
            notice(
                'Блок перемещён: ' + targetMeta.title + '.',
                'success',
                1800
            );
        } catch (error) {
            handleError(error, 'Не удалось переместить layout-блок.');
        } finally {
            state.busy = false;
        }
    }

    async function duplicateBlock(blockId) {
        blockId = Number(blockId || 0);
        if (blockId <= 0 || state.busy) return;

        var source = findBlock(blockId);
        if (!source) return;

        if (
            state.blockDirty
            && Number(state.currentBlockId) === blockId
        ) {
            notice(
                'Сначала сохраните изменения этого блока — дублируется только сохранённая версия.',
                'error',
                3200
            );
            return;
        }

        state.busy = true;

        try {
            var response = await api('layout.block.duplicate', {
                siteId: SITE_ID,
                id: blockId,
                targetZone: source.zone,
                targetIndex: source.index + 1,
                expectedVersion: version()
            });

            state.layout = response.layout || state.layout;

            var copyId = Number(
                response.block
                && response.block.id
                || 0
            );

            if (!state.blockDirty && copyId > 0) {
                state.currentBlockId = copyId;
            }

            renderVersion();
            renderCanvas();

            if (!state.blockDirty && copyId > 0) {
                renderInspector();
            }

            notice('Блок продублирован.', 'success', 1800);
        } catch (error) {
            handleError(error, 'Не удалось продублировать layout-блок.');
        } finally {
            state.busy = false;
        }
    }

    function dragGhostHtml(block) {
        var meta = blockMeta(block && block.type);

        return ''
            + '<span class="sb-layout2-drag-ghost__icon">'
            + escapeHtml(meta.icon)
            + '</span>'
            + '<span class="sb-layout2-drag-ghost__copy">'
            + '<strong>' + escapeHtml(meta.title) + '</strong>'
            + '<small data-layout-drag-destination>Перемещение…</small>'
            + '</span>';
    }

    function beginDrag(pending) {
        if (!pending || state.busy) return false;

        var found = findBlock(pending.blockId);
        if (!found) return false;

        drag.pending = pending;
        drag.active = true;
        drag.sourceId = Number(pending.blockId || 0);
        drag.sourceZone = found.zone;
        drag.sourceIndex = found.index;
        drag.targetZone = '';
        drag.targetSlotIndex = -1;
        drag.targetIndex = -1;
        drag.noOp = false;

        var ghost = document.createElement('div');
        ghost.className = 'sb-layout2-drag-ghost';
        ghost.innerHTML = dragGhostHtml(found.block);
        document.body.appendChild(ghost);
        drag.ghost = ghost;

        document.body.classList.add('sb-layout2-dragging');

        var sourceCard = document.querySelector(
            '[data-layout-block-id="' + drag.sourceId + '"]'
        );

        if (sourceCard) {
            sourceCard.classList.add('is-drag-source');
        }

        return true;
    }

    function clearDragTargets() {
        document.querySelectorAll('.is-drag-target').forEach(function (item) {
            item.classList.remove('is-drag-target');
        });

        document.querySelectorAll('.is-drag-over').forEach(function (item) {
            item.classList.remove('is-drag-over');
        });
    }

    function endDragVisuals() {
        clearDragTargets();

        document.querySelectorAll('.is-drag-source').forEach(function (item) {
            item.classList.remove('is-drag-source');
        });

        document.body.classList.remove('sb-layout2-dragging');

        if (drag.ghost && drag.ghost.parentNode) {
            drag.ghost.parentNode.removeChild(drag.ghost);
        }

        drag.ghost = null;
        drag.pending = null;
        drag.active = false;
        drag.targetZone = '';
        drag.targetSlotIndex = -1;
        drag.targetIndex = -1;
        drag.noOp = false;
    }

    function moveGhost(clientX, clientY) {
        if (!drag.ghost) return;

        drag.ghost.style.transform =
            'translate3d('
            + Math.round(clientX + 14)
            + 'px,'
            + Math.round(clientY + 14)
            + 'px,0)';
    }

    function zoneAtPoint(clientX, clientY) {
        var element = document.elementFromPoint(clientX, clientY);
        var direct = element && element.closest
            ? element.closest('[data-layout-zone-card]')
            : null;

        if (direct) return direct;

        var cards = Array.prototype.slice.call(
            document.querySelectorAll('[data-layout-zone-card]')
        );

        return cards.find(function (card) {
            var rect = card.getBoundingClientRect();

            return (
                clientX >= rect.left
                && clientX <= rect.right
                && clientY >= rect.top
                && clientY <= rect.bottom
            );
        }) || null;
    }

    function nearestSlot(zoneCard, clientY) {
        var slots = Array.prototype.slice.call(
            zoneCard.querySelectorAll('[data-layout-drop-slot]')
        );

        if (!slots.length) return null;

        var best = slots[0];
        var bestDistance = Infinity;

        slots.forEach(function (slot) {
            var rect = slot.getBoundingClientRect();
            var center = rect.top + rect.height / 2;
            var distance = Math.abs(clientY - center);

            if (distance < bestDistance) {
                bestDistance = distance;
                best = slot;
            }
        });

        return best;
    }

    function finalDropIndex(targetZone, slotIndex) {
        var count = zoneBlocks(targetZone).length;
        var finalCount = targetZone === drag.sourceZone
            ? Math.max(0, count - 1)
            : count;

        var targetIndex = Number(slotIndex || 0);

        /*
         * Slot indexes are rendered before removing the source block.
         * Backend targetIndex is defined in the final target array after
         * the source has been removed.
         */
        if (
            targetZone === drag.sourceZone
            && targetIndex > drag.sourceIndex
        ) {
            targetIndex--;
        }

        return clamp(targetIndex, 0, finalCount);
    }

    function updateDragDestination(clientX, clientY) {
        clearDragTargets();

        var zoneCard = zoneAtPoint(clientX, clientY);

        if (!zoneCard) {
            drag.targetZone = '';
            drag.targetSlotIndex = -1;
            drag.targetIndex = -1;
            drag.noOp = false;

            if (drag.ghost) {
                var noTarget = drag.ghost.querySelector('[data-layout-drag-destination]');
                if (noTarget) noTarget.textContent = 'Наведите на область сайта';
            }

            return;
        }

        var targetZone = String(
            zoneCard.getAttribute('data-layout-zone-card')
            || ''
        );

        if (ZONES.indexOf(targetZone) === -1) return;

        var slot = nearestSlot(zoneCard, clientY);
        if (!slot) return;

        var slotIndex = Number(
            slot.getAttribute('data-layout-index')
            || 0
        );

        var targetIndex = finalDropIndex(
            targetZone,
            slotIndex
        );

        drag.targetZone = targetZone;
        drag.targetSlotIndex = slotIndex;
        drag.targetIndex = targetIndex;
        drag.noOp = (
            targetZone === drag.sourceZone
            && targetIndex === drag.sourceIndex
        );

        zoneCard.classList.add('is-drag-over');
        slot.classList.add('is-drag-target');

        var destination = drag.ghost
            ? drag.ghost.querySelector('[data-layout-drag-destination]')
            : null;

        if (destination) {
            var meta = zoneMeta(targetZone);

            destination.textContent = drag.noOp
                ? 'Текущее место'
                : meta.title + ' · позиция ' + (targetIndex + 1);
        }
    }

    function autoScrollDrag(clientX, clientY) {
        var canvas = node('layoutCanvas');

        if (canvas) {
            var rect = canvas.getBoundingClientRect();
            var edge = 54;
            var dx = 0;

            if (clientX < rect.left + edge) dx = -16;
            if (clientX > rect.right - edge) dx = 16;

            if (dx !== 0) {
                canvas.scrollLeft += dx;
            }
        }

        var viewportEdge = 76;
        var dy = 0;

        if (clientY < viewportEdge) dy = -14;
        if (clientY > window.innerHeight - viewportEdge) dy = 14;

        if (dy !== 0) {
            window.scrollBy(0, dy);
        }
    }

    function cancelPendingPointer(pointerId) {
        if (
            drag.pending
            && Number(drag.pending.pointerId) === Number(pointerId)
        ) {
            drag.pending = null;
        }
    }

    document.addEventListener('pointerdown', function (event) {
        var handle = event.target.closest('[data-layout-drag-handle]');
        if (!handle) return;

        if (event.pointerType === 'mouse' && event.button !== 0) return;
        if (state.busy) return;

        var blockId = Number(
            handle.getAttribute('data-layout-drag-handle')
            || 0
        );

        var found = findBlock(blockId);
        if (!found) return;

        drag.pending = {
            pointerId: event.pointerId,
            blockId: blockId,
            startX: event.clientX,
            startY: event.clientY,
            lastX: event.clientX,
            lastY: event.clientY,
            handle: handle
        };

        try {
            handle.setPointerCapture(event.pointerId);
        } catch (error) {
            /* Pointer capture is an enhancement, not a requirement. */
        }

        event.preventDefault();
    }, true);

    document.addEventListener('pointermove', function (event) {
        if (
            !drag.pending
            || Number(drag.pending.pointerId) !== Number(event.pointerId)
        ) {
            return;
        }

        drag.pending.lastX = event.clientX;
        drag.pending.lastY = event.clientY;

        if (!drag.active) {
            var dx = event.clientX - drag.pending.startX;
            var dy = event.clientY - drag.pending.startY;

            if (Math.sqrt(dx * dx + dy * dy) < 6) {
                return;
            }

            if (!beginDrag(drag.pending)) {
                cancelPendingPointer(event.pointerId);
                return;
            }
        }

        event.preventDefault();

        moveGhost(event.clientX, event.clientY);
        autoScrollDrag(event.clientX, event.clientY);
        updateDragDestination(event.clientX, event.clientY);
    }, true);

    document.addEventListener('pointerup', function (event) {
        if (
            !drag.pending
            || Number(drag.pending.pointerId) !== Number(event.pointerId)
        ) {
            return;
        }

        var wasActive = drag.active;
        var sourceId = drag.sourceId;
        var targetZone = drag.targetZone;
        var targetIndex = drag.targetIndex;
        var noOp = drag.noOp;

        endDragVisuals();

        if (!wasActive) return;

        event.preventDefault();

        if (!targetZone || noOp) {
            if (sourceId > 0 && !state.blockDirty) {
                selectBlock(sourceId);
            }
            return;
        }

        relocateBlock(
            sourceId,
            targetZone,
            targetIndex
        );
    }, true);

    document.addEventListener('pointercancel', function (event) {
        if (
            !drag.pending
            || Number(drag.pending.pointerId) !== Number(event.pointerId)
        ) {
            return;
        }

        endDragVisuals();
    }, true);

    [
        ['showHeader', 'showHeader'],
        ['showFooter', 'showFooter'],
        ['showLeft', 'showLeft'],
        ['showRight', 'showRight']
    ].forEach(function (item) {
        var control = node(item[0]);
        if (!control) return;
        control.addEventListener('change', function () {
            readToggle(item[0], item[1]);
        });
    });

    node('leftWidthRange').addEventListener('input', function () {
        setWidth('left', this.value);
    });

    node('leftWidth').addEventListener('change', function () {
        setWidth('left', this.value);
    });

    node('rightWidthRange').addEventListener('input', function () {
        setWidth('right', this.value);
    });

    node('rightWidth').addEventListener('change', function () {
        setWidth('right', this.value);
    });

    document.addEventListener('click', function (event) {
        var modeButton = event.target.closest('[data-left-mode]');
        if (modeButton) {
            event.preventDefault();
            setLeftMode(modeButton.getAttribute('data-left-mode'));
            return;
        }

        var addToggle = event.target.closest('[data-layout-add-toggle]');
        if (addToggle) {
            event.preventDefault();
            event.stopPropagation();

            var menuKey = String(
                addToggle.getAttribute('data-layout-add-toggle')
                || ''
            );

            var menu = document.querySelector(
                '[data-layout-add-menu="' + menuKey + '"]'
            );

            var open = menu && menu.hidden;

            closeAddMenus(open ? menuKey : '');
            return;
        }

        var addType = event.target.closest('[data-layout-add-type]');
        if (addType) {
            event.preventDefault();

            addBlock(
                String(addType.getAttribute('data-layout-zone') || ''),
                String(addType.getAttribute('data-layout-add-type') || 'text'),
                Number(addType.getAttribute('data-layout-target-index') || 0)
            );
            return;
        }

        var edit = event.target.closest('[data-layout-edit]');
        if (edit) {
            event.preventDefault();
            selectBlock(Number(edit.getAttribute('data-layout-edit') || 0));
            return;
        }

        var move = event.target.closest('[data-layout-move]');
        if (move) {
            event.preventDefault();
            event.stopPropagation();

            moveBlock(
                Number(move.getAttribute('data-layout-id') || 0),
                String(move.getAttribute('data-layout-move') || '')
            );
            return;
        }

        var duplicate = event.target.closest('[data-layout-duplicate]');
        if (duplicate) {
            event.preventDefault();
            event.stopPropagation();

            duplicateBlock(
                Number(
                    duplicate.getAttribute('data-layout-duplicate')
                    || 0
                )
            );
            return;
        }

        var remove = event.target.closest('[data-layout-delete]');
        if (remove) {
            event.preventDefault();
            event.stopPropagation();
            deleteBlock(Number(remove.getAttribute('data-layout-delete') || 0));
            return;
        }

        if (!event.target.closest('.sb-layout2-add-wrap')) closeAddMenus();
    });

    node('layoutBlockFields').addEventListener('input', markBlockDirty);
    node('layoutBlockFields').addEventListener('change', markBlockDirty);
    node('blockAdvancedContent').addEventListener('input', markBlockDirty);
    node('blockAdvancedProps').addEventListener('input', markBlockDirty);

    node('saveSettingsBtn').addEventListener('click', saveSettings);
    node('reloadBtn').addEventListener('click', function () { loadLayout(false); });
    node('saveLayoutBlockBtn').addEventListener('click', saveBlock);
    node('deleteLayoutBlockBtn').addEventListener('click', function () {
        deleteBlock(state.currentBlockId);
    });
    node('closeInspectorBtn').addEventListener('click', clearSelection);

    window.addEventListener('beforeunload', function (event) {
        if (!state.settingsDirty && !state.blockDirty) return;
        event.preventDefault();
        event.returnValue = '';
    });

    loadLayout(true);
})();
