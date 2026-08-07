/* =========================================================
   SITEBUILDER BLOCK INSPECTOR MODES / STAGE 23
   Content / Appearance / Responsive.
   No autosave and no API changes.
   ========================================================= */
(function () {
    'use strict';

    if (!window.SB_EDITOR_CONFIG) {
        return;
    }

    var inspector = document.getElementById('blockInspector');

    if (!inspector) {
        return;
    }

    var TYPE_NAMES = {
        heading: 'Заголовок',
        text: 'Текст',
        button: 'Кнопка',
        image: 'Изображение',
        hero: 'Первый экран',
        cards: 'Карточки',
        quote: 'Цитата',
        stats: 'Показатели',
        divider: 'Разделитель',
        spacer: 'Отступ',
        html: 'HTML',
        table: 'Таблица',
        disk: 'Битрикс.Диск',
        global: 'Глобальный блок',
        faq: 'FAQ',
        video: 'Видео',
        pricing: 'Тарифы',
        form: 'Форма',
        gallery: 'Галерея',
        navigation: 'Навигация',
        footer: 'Подвал',
        unknown: 'Блок'
    };

    var FORM_TYPES = {
        headingBlockForm: 'heading',
        textBlockForm: 'text',
        buttonBlockForm: 'button',
        imageBlockForm: 'image',
        heroBlockForm: 'hero',
        cardsBlockForm: 'cards',
        quoteBlockForm: 'quote',
        statsBlockForm: 'stats',
        dividerBlockForm: 'divider',
        spacerBlockForm: 'spacer',
        htmlBlockForm: 'html',
        tableBlockForm: 'table',
        diskBlockForm: 'disk',
        unknownBlockForm: 'unknown',
        faqBlockForm: 'faq',
        videoBlockForm: 'video',
        pricingBlockForm: 'pricing',
        formBlockForm: 'form',
        galleryBlockForm: 'gallery',
        navigationBlockForm: 'navigation',
        footerBlockForm: 'footer'
    };

    var APPEARANCE_FIELDS = {
        heading: ['headingAlignInput', 'headingColorInput', 'headingSizeInput', 'headingMaxWidthInput'],
        text: ['textAlignInput', 'textSizeInput', 'textColorInput', 'textLineHeightInput', 'textMaxWidthInput'],
        button: ['buttonStyleInput', 'buttonSizeInput', 'buttonAlignInput', 'buttonFullWidthInput'],
        image: ['imageRatioInput', 'imageFitInput', 'imageAlignInput', 'imageWidthInput', 'imageRadiusInput', 'imageShadowInput'],
        hero: ['heroThemeInput', 'heroAlignInput', 'heroImagePositionInput', 'heroMinHeightInput', 'heroRadiusInput', 'heroBackgroundColorInput', 'heroTextColorInput', 'heroUseCustomColorsInput'],
        cards: ['cardsColumnsInput', 'cardsStyleInput', 'cardsImageRatioInput', 'cardsAlignInput'],
        quote: ['quoteStyleInput', 'quoteAlignInput', 'quoteAccentColorInput'],
        stats: ['statsColumnsInput', 'statsStyleInput'],
        divider: ['dividerStyleInput', 'dividerColorInput', 'dividerThicknessInput', 'dividerWidthInput', 'dividerMarginInput'],
        disk: ['diskViewModeInput', 'diskShowSearchInput', 'diskShowBreadcrumbsInput'],
        faq: ['faqStyleInput'],
        video: ['videoRatioInput'],
        pricing: ['pricingColumnsInput', 'pricingStyleInput'],
        form: ['formStyleInput'],
        gallery: ['galleryColumnsInput', 'galleryGapInput', 'galleryRatioInput'],
        navigation: ['navStyleInput'],
        footer: ['footerStyleInput']
    };

    var ADAPTIVE_FIELDS = {
        spacer: ['spacerHeightInput', 'spacerTabletHeightInput', 'spacerMobileHeightInput']
    };

    var currentMode = 'content';
    var tabs = null;
    var panes = {};
    var appearanceEmpty = null;
    var adaptiveTypeHost = null;
    var appearanceTypeHost = null;
    var contextType = null;
    var contextMeta = null;
    var contextId = null;
    var styleActions = null;
    var designPanel = null;

    function node(id) {
        return document.getElementById(id);
    }

    function currentBlock() {
        return typeof getCurrentBlock === 'function' ? getCurrentBlock() : null;
    }

    function currentType() {
        var block = currentBlock();

        if (block && block.type) {
            return String(block.type);
        }

        var input = node('blockTypeInput');
        var value = input ? String(input.value || '').trim() : '';

        return value || 'unknown';
    }

    function typeName(type) {
        return TYPE_NAMES[type] || type || 'Блок';
    }

    function formType(form) {
        if (!form) {
            return 'unknown';
        }

        if (FORM_TYPES[form.id]) {
            return FORM_TYPES[form.id];
        }

        var id = String(form.id || '');

        if (/BlockForm$/.test(id)) {
            return id.replace(/BlockForm$/, '').replace(/^unknown$/, 'unknown');
        }

        return 'unknown';
    }

    function createPane(id, title, text) {
        var pane = document.createElement('section');
        pane.className = 'sb-block-mode-pane';
        pane.id = id;
        pane.hidden = true;
        pane.innerHTML = ''
            + '<div class="sb-block-mode-pane__head">'
            + '  <strong>' + title + '</strong>'
            + '  <span>' + text + '</span>'
            + '</div>';

        return pane;
    }

    function ensureStructure() {
        if (node('blockInspectorModes')) {
            tabs = node('blockInspectorModes');
            panes.content = node('blockInspectorModeContent');
            panes.appearance = node('blockInspectorModeAppearance');
            panes.adaptive = node('blockInspectorModeAdaptive');
            appearanceTypeHost = node('blockAppearanceTypeHost');
            adaptiveTypeHost = node('blockAdaptiveTypeHost');
            appearanceEmpty = node('blockAppearanceEmpty');
            contextType = node('blockInspectorContextType');
            contextMeta = node('blockInspectorContextMeta');
            contextId = node('blockInspectorContextId');
            designPanel = node('blockDesignPanel');
            return;
        }

        var blockTypeInput = node('blockTypeInput');
        var placement = inspector.querySelector('.sb-block-placement-row');
        designPanel = node('blockDesignPanel');
        var advancedToggle = node('toggleAdvancedJsonBtn');

        if (!designPanel || !advancedToggle) {
            return;
        }

        if (blockTypeInput) {
            var typeField = blockTypeInput.closest('.sb-field');

            if (typeField) {
                typeField.classList.add('sb-block-type-field--legacy');
            }
        }

        var context = document.createElement('div');
        context.className = 'sb-block-inspector-context';
        context.innerHTML = ''
            + '<div class="sb-block-inspector-context__main">'
            + '  <span class="sb-block-inspector-context__eyebrow">Выбранный блок</span>'
            + '  <strong id="blockInspectorContextType">Блок</strong>'
            + '  <small id="blockInspectorContextMeta">Выберите блок на холсте</small>'
            + '</div>'
            + '<span class="sb-block-inspector-context__id" id="blockInspectorContextId">—</span>';

        if (placement) {
            inspector.insertBefore(context, placement);
        } else {
            inspector.insertBefore(context, designPanel);
        }

        tabs = document.createElement('div');
        tabs.id = 'blockInspectorModes';
        tabs.className = 'sb-block-mode-tabs';
        tabs.setAttribute('role', 'tablist');
        tabs.setAttribute('aria-label', 'Настройки блока');
        tabs.innerHTML = ''
            + '<button class="is-active" type="button" role="tab" aria-selected="true" data-block-mode="content">Контент</button>'
            + '<button type="button" role="tab" aria-selected="false" data-block-mode="appearance">Оформление</button>'
            + '<button type="button" role="tab" aria-selected="false" data-block-mode="adaptive">Адаптивность</button>';

        inspector.insertBefore(tabs, designPanel);

        panes.content = createPane('blockInspectorModeContent', 'Контент', 'Текст, ссылки, изображения и данные блока.');
        panes.appearance = createPane('blockInspectorModeAppearance', 'Оформление', 'Внешний вид выбранного типа блока.');
        panes.adaptive = createPane('blockInspectorModeAdaptive', 'Адаптивность', 'Видимость, отступы и анимация на устройствах.');

        inspector.insertBefore(panes.content, designPanel);
        inspector.insertBefore(panes.appearance, designPanel);
        inspector.insertBefore(panes.adaptive, designPanel);

        appearanceTypeHost = document.createElement('div');
        appearanceTypeHost.id = 'blockAppearanceTypeHost';
        appearanceTypeHost.className = 'sb-block-mode-type-host';
        panes.appearance.appendChild(appearanceTypeHost);

        appearanceEmpty = document.createElement('div');
        appearanceEmpty.id = 'blockAppearanceEmpty';
        appearanceEmpty.className = 'sb-block-mode-empty';
        appearanceEmpty.innerHTML = ''
            + '<strong>Отдельных параметров оформления нет</strong>'
            + '<span>Для этого типа доступны контент и общие настройки адаптивности.</span>';
        panes.appearance.appendChild(appearanceEmpty);

        adaptiveTypeHost = document.createElement('div');
        adaptiveTypeHost.id = 'blockAdaptiveTypeHost';
        adaptiveTypeHost.className = 'sb-block-mode-type-host';
        panes.adaptive.appendChild(adaptiveTypeHost);

        var copyButton = node('copyBlockStyleBtn');

        if (copyButton) {
            styleActions = copyButton.closest('.sb-editor-inspector-actions');

            if (styleActions) {
                styleActions.classList.add('sb-block-mode-style-actions');
                panes.appearance.appendChild(styleActions);
            }
        }

        var globalButton = node('saveGlobalBlockBtn');

        if (globalButton) {
            var globalActions = globalButton.closest('.sb-global-block-actions');

            if (globalActions) {
                globalActions.classList.add('sb-block-common-actions');
                inspector.insertBefore(globalActions, advancedToggle);
            }
        }

        designPanel.open = true;
        designPanel.classList.add('sb-block-design-panel--mode');
        panes.adaptive.appendChild(designPanel);

        contextType = node('blockInspectorContextType');
        contextMeta = node('blockInspectorContextMeta');
        contextId = node('blockInspectorContextId');

        tabs.addEventListener('click', function (event) {
            var button = event.target.closest('[data-block-mode]');

            if (!button) {
                return;
            }

            setMode(String(button.getAttribute('data-block-mode') || 'content'));
        });

        ['blockSectionInput', 'blockColumnInput'].forEach(function (id) {
            var field = node(id);

            if (field) {
                field.addEventListener('change', updateContext);
            }
        });

        setMode('content');
    }

    function ensureTypeGroup(host, type, mode) {
        if (!host) {
            return null;
        }

        var selector = '[data-block-mode-type="' + type + '"]';
        var existing = host.querySelector(selector);

        if (existing) {
            return existing;
        }

        var group = document.createElement('div');
        group.className = 'sb-block-mode-type';
        group.setAttribute('data-block-mode-type', type);
        group.setAttribute('data-block-mode-kind', mode);
        group.hidden = true;

        var grid = document.createElement('div');
        grid.className = 'sb-block-mode-fields';
        group.appendChild(grid);
        host.appendChild(group);

        return group;
    }

    function fieldUnit(form, id) {
        var field = node(id);

        if (!field || !form.contains(field)) {
            return null;
        }

        var unit = field.closest('.sb-field, .sb-switch, .sb-checkbox');

        if (!unit || !form.contains(unit)) {
            var label = field.closest('label');

            if (label && form.contains(label)) {
                unit = label;
            }
        }

        return unit && form.contains(unit) ? unit : null;
    }

    function moveFieldUnits(form, type, ids, host, mode) {
        if (!Array.isArray(ids) || !ids.length || !host) {
            return;
        }

        var group = null;
        var moved = [];

        ids.forEach(function (id) {
            var unit = fieldUnit(form, id);

            if (!unit || moved.indexOf(unit) !== -1) {
                return;
            }

            if (!group) {
                group = ensureTypeGroup(host, type, mode);
            }

            var grid = group.querySelector('.sb-block-mode-fields');

            unit.classList.add('sb-block-mode-field-unit');
            unit.style.marginTop = '';
            grid.appendChild(unit);
            moved.push(unit);
        });
    }

    function removeEmptyLayouts(form) {
        if (!form) {
            return;
        }

        Array.prototype.slice.call(form.querySelectorAll('.sb-form-grid, .sb-form-row')).reverse().forEach(function (layout) {
            if (layout.children.length === 0) {
                layout.remove();
            }
        });
    }

    function prepareForm(form) {
        if (!form || form.getAttribute('data-block-mode-prepared') === '1') {
            return;
        }

        var type = formType(form);

        form.setAttribute('data-block-mode-type-source', type);
        form.setAttribute('data-block-mode-prepared', '1');
        panes.content.appendChild(form);

        moveFieldUnits(form, type, APPEARANCE_FIELDS[type] || [], appearanceTypeHost, 'appearance');
        moveFieldUnits(form, type, ADAPTIVE_FIELDS[type] || [], adaptiveTypeHost, 'adaptive');
        removeEmptyLayouts(form);
    }

    function prepareAllForms() {
        ensureStructure();

        if (!panes.content) {
            return;
        }

        Array.prototype.slice.call(inspector.querySelectorAll('.sb-block-type-form')).forEach(prepareForm);
    }

    function setMode(mode) {
        mode = ['content', 'appearance', 'adaptive'].indexOf(mode) !== -1 ? mode : 'content';
        currentMode = mode;

        if (tabs) {
            tabs.querySelectorAll('[data-block-mode]').forEach(function (button) {
                var active = button.getAttribute('data-block-mode') === mode;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }

        Object.keys(panes).forEach(function (name) {
            if (panes[name]) {
                panes[name].hidden = name !== mode;
            }
        });

        updateTypeVisibility();
    }

    function updateTypeVisibility() {
        var type = currentType();
        var foundAppearance = false;

        if (appearanceTypeHost) {
            appearanceTypeHost.querySelectorAll('[data-block-mode-type]').forEach(function (group) {
                var active = group.getAttribute('data-block-mode-type') === type;
                group.hidden = !active;

                if (active) {
                    foundAppearance = true;
                }
            });
        }

        if (appearanceEmpty) {
            appearanceEmpty.hidden = foundAppearance;
        }

        if (adaptiveTypeHost) {
            adaptiveTypeHost.querySelectorAll('[data-block-mode-type]').forEach(function (group) {
                group.hidden = group.getAttribute('data-block-mode-type') !== type;
            });
        }
    }

    function selectedText(select) {
        if (!select) {
            return '';
        }

        var option = select.options && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
        return option ? String(option.textContent || '').trim() : '';
    }

    function updateContext() {
        var block = currentBlock();
        var type = currentType();
        var section = selectedText(node('blockSectionInput'));
        var column = selectedText(node('blockColumnInput'));
        var parts = [];

        if (section) {
            parts.push(section);
        }

        if (column) {
            parts.push(column);
        }

        if (contextType) {
            contextType.textContent = typeName(type);
        }

        if (contextMeta) {
            contextMeta.textContent = parts.length ? parts.join(' · ') : 'Положение блока не задано';
        }

        if (contextId) {
            contextId.textContent = block && Number(block.id || 0) > 0 ? '#' + Number(block.id || 0) : '—';
        }

        updateTypeVisibility();
    }

    function syncInspector() {
        prepareAllForms();
        updateContext();
        setMode(currentMode);
    }

    ensureStructure();
    prepareAllForms();

    var originalFillBlockForm = window.fillBlockForm;

    if (typeof originalFillBlockForm === 'function') {
        window.fillBlockForm = function () {
            var result = originalFillBlockForm.apply(this, arguments);
            syncInspector();
            return result;
        };
    }

    var observer = new MutationObserver(function (mutations) {
        var hasNewForm = mutations.some(function (mutation) {
            return Array.prototype.some.call(mutation.addedNodes || [], function (added) {
                if (!added || added.nodeType !== 1) {
                    return false;
                }

                return (added.matches && added.matches('.sb-block-type-form'))
                    || (added.querySelector && !!added.querySelector('.sb-block-type-form'));
            });
        });

        if (hasNewForm) {
            prepareAllForms();
            updateContext();
        }
    });

    observer.observe(inspector, {
        childList: true,
        subtree: true
    });

    syncInspector();

    window.SBBlockInspectorModes = {
        setMode: setMode,
        sync: syncInspector
    };
})();
