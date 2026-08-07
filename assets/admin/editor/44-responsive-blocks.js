/* =========================================================
   SITEBUILDER RESPONSIVE BLOCK OVERRIDES / STAGE 24
   Tablet / Mobile overrides with inheritance.
   No autosave. Data is stored in props._responsive.
   ========================================================= */
(function () {
    'use strict';

    if (!window.SB_EDITOR_CONFIG || typeof state === 'undefined') {
        return;
    }

    var SUPPORTED_TYPES = [
        'heading',
        'text',
        'button',
        'image',
        'hero',
        'cards'
    ];

    var COMMON_FIELDS = [
        {
            key: 'marginTop',
            label: 'Отступ сверху, px',
            type: 'number',
            min: 0,
            max: 240,
            step: 1,
            hint: 'Пусто = наследовать общий отступ блока.'
        },
        {
            key: 'marginBottom',
            label: 'Отступ снизу, px',
            type: 'number',
            min: 0,
            max: 240,
            step: 1,
            hint: 'Пусто = наследовать общий отступ блока.'
        }
    ];

    var TYPE_FIELDS = {
        heading: [
            {
                key: 'size',
                label: 'Размер шрифта, px',
                type: 'number',
                min: 12,
                max: 120,
                step: 1
            },
            {
                key: 'align',
                label: 'Выравнивание',
                type: 'select',
                options: [
                    ['left', 'Слева'],
                    ['center', 'По центру'],
                    ['right', 'Справа']
                ]
            },
            {
                key: 'maxWidth',
                label: 'Макс. ширина, px',
                type: 'number',
                min: 0,
                max: 1800,
                step: 1,
                hint: '0 = без ограничения.'
            }
        ],
        text: [
            {
                key: 'size',
                label: 'Размер текста, px',
                type: 'number',
                min: 12,
                max: 72,
                step: 1
            },
            {
                key: 'align',
                label: 'Выравнивание',
                type: 'select',
                options: [
                    ['left', 'Слева'],
                    ['center', 'По центру'],
                    ['right', 'Справа'],
                    ['justify', 'По ширине']
                ]
            },
            {
                key: 'lineHeight',
                label: 'Межстрочный интервал',
                type: 'number',
                min: 1,
                max: 2.4,
                step: 0.05
            },
            {
                key: 'maxWidth',
                label: 'Макс. ширина, px',
                type: 'number',
                min: 0,
                max: 1800,
                step: 1,
                hint: '0 = без ограничения.'
            }
        ],
        button: [
            {
                key: 'align',
                label: 'Выравнивание',
                type: 'select',
                options: [
                    ['left', 'Слева'],
                    ['center', 'По центру'],
                    ['right', 'Справа']
                ]
            },
            {
                key: 'fullWidth',
                label: 'На всю ширину',
                type: 'boolean'
            }
        ],
        image: [
            {
                key: 'width',
                label: 'Ширина, %',
                type: 'number',
                min: 10,
                max: 100,
                step: 1
            },
            {
                key: 'radius',
                label: 'Скругление, px',
                type: 'number',
                min: 0,
                max: 80,
                step: 1
            },
            {
                key: 'align',
                label: 'Выравнивание',
                type: 'select',
                options: [
                    ['left', 'Слева'],
                    ['center', 'По центру'],
                    ['right', 'Справа']
                ]
            }
        ],
        hero: [
            {
                key: 'minHeight',
                label: 'Мин. высота, px',
                type: 'number',
                min: 220,
                max: 900,
                step: 1
            },
            {
                key: 'radius',
                label: 'Скругление, px',
                type: 'number',
                min: 0,
                max: 80,
                step: 1
            },
            {
                key: 'titleSize',
                label: 'Размер заголовка, px',
                type: 'number',
                min: 18,
                max: 96,
                step: 1
            },
            {
                key: 'align',
                label: 'Выравнивание контента',
                type: 'select',
                options: [
                    ['left', 'Слева'],
                    ['center', 'По центру']
                ]
            }
        ],
        cards: [
            {
                key: 'columns',
                label: 'Колонок',
                type: 'number',
                min: 1,
                max: 4,
                step: 1
            },
            {
                key: 'align',
                label: 'Выравнивание текста',
                type: 'select',
                options: [
                    ['left', 'Слева'],
                    ['center', 'По центру']
                ]
            }
        ]
    };

    var panel = null;
    var fieldsHost = null;
    var deviceButtons = null;
    var currentResponsiveDevice = 'tablet';
    var draftBlockId = 0;
    var draftType = '';
    var draftResponsive = {};
    var previewTimer = null;

    function node(id) {
        return document.getElementById(id);
    }

    function currentBlock() {
        return typeof getCurrentBlock === 'function'
            ? getCurrentBlock()
            : null;
    }

    function cloneObject(value) {
        try {
            return JSON.parse(JSON.stringify(value || {}));
        } catch (error) {
            return {};
        }
    }

    function supported(type) {
        return SUPPORTED_TYPES.indexOf(String(type || '')) !== -1;
    }

    function fieldDefinitions(type) {
        return (TYPE_FIELDS[type] || []).concat(COMMON_FIELDS);
    }

    function clampNumber(value, min, max) {
        value = Number(value);

        if (!isFinite(value)) {
            return null;
        }

        return Math.max(min, Math.min(max, value));
    }

    function ensurePanel() {
        if (panel && document.body.contains(panel)) {
            return panel;
        }

        var adaptivePane = node('blockInspectorModeAdaptive');

        if (!adaptivePane) {
            return null;
        }

        panel = document.createElement('section');
        panel.id = 'blockResponsiveOverridesPanel';
        panel.className = 'sb-responsive-overrides';
        panel.hidden = true;
        panel.innerHTML = ''
            + '<div class="sb-responsive-overrides__head">'
            + '  <div>'
            + '    <strong>Точные настройки по устройствам</strong>'
            + '    <span>Пустое значение наследуется от более широкого экрана.</span>'
            + '  </div>'
            + '  <span class="sb-responsive-overrides__badge">_responsive</span>'
            + '</div>'
            + '<div class="sb-responsive-overrides__devices" role="tablist" aria-label="Адаптивные настройки">'
            + '  <button type="button" class="is-active" data-responsive-device="tablet">Планшет</button>'
            + '  <button type="button" data-responsive-device="mobile">Телефон</button>'
            + '</div>'
            + '<div class="sb-responsive-overrides__inherit" id="blockResponsiveInheritanceHint"></div>'
            + '<div class="sb-responsive-overrides__fields" id="blockResponsiveFields"></div>';

        var designPanel = node('blockDesignPanel');

        if (designPanel && designPanel.parentNode === adaptivePane) {
            adaptivePane.insertBefore(panel, designPanel);
        } else {
            adaptivePane.appendChild(panel);
        }

        fieldsHost = node('blockResponsiveFields');
        deviceButtons = panel.querySelector('.sb-responsive-overrides__devices');

        deviceButtons.addEventListener('click', function (event) {
            var button = event.target.closest('[data-responsive-device]');

            if (!button) {
                return;
            }

            syncDraftFromFields();

            currentResponsiveDevice = String(
                button.getAttribute('data-responsive-device') || 'tablet'
            );

            renderFields();
            syncDeviceButtons();

            var previewButton = document.querySelector(
                '[data-preview-device="' + currentResponsiveDevice + '"]'
            );

            if (
                previewButton
                && !previewButton.classList.contains('is-active')
            ) {
                previewButton.click();
            } else {
                schedulePreview();
            }
        });

        panel.addEventListener('input', handleFieldChange);
        panel.addEventListener('change', handleFieldChange);

        return panel;
    }

    function handleFieldChange(event) {
        if (!event.target.closest('[data-responsive-key]')) {
            return;
        }

        syncDraftFromFields();
        schedulePreview();
    }

    function syncDeviceButtons() {
        if (!deviceButtons) {
            return;
        }

        deviceButtons.querySelectorAll('[data-responsive-device]')
            .forEach(function (button) {
                button.classList.toggle(
                    'is-active',
                    button.getAttribute('data-responsive-device')
                        === currentResponsiveDevice
                );
            });
    }

    function inheritedFromText() {
        return currentResponsiveDevice === 'mobile'
            ? 'Пусто → наследовать Планшет → Desktop'
            : 'Пусто → наследовать Desktop';
    }

    function fieldHtml(definition, value) {
        var key = String(definition.key || '');
        var label = String(definition.label || key);
        var hint = String(definition.hint || '');
        var commonClass = COMMON_FIELDS.some(function (item) {
            return item.key === key;
        }) ? ' is-common' : '';

        var html = '<label class="sb-responsive-field' + commonClass + '">'
            + '<span class="sb-responsive-field__label">'
            + escapeHtml(label)
            + '</span>';

        if (definition.type === 'select') {
            html += '<select class="sb-select" data-responsive-key="'
                + escapeHtml(key)
                + '">'
                + '<option value="">Наследовать</option>';

            (definition.options || []).forEach(function (option) {
                html += '<option value="' + escapeHtml(option[0]) + '"'
                    + (String(value) === String(option[0]) ? ' selected' : '')
                    + '>'
                    + escapeHtml(option[1])
                    + '</option>';
            });

            html += '</select>';
        } else if (definition.type === 'boolean') {
            html += '<select class="sb-select" data-responsive-key="'
                + escapeHtml(key)
                + '">'
                + '<option value="">Наследовать</option>'
                + '<option value="true"'
                + (value === true ? ' selected' : '')
                + '>Да</option>'
                + '<option value="false"'
                + (value === false ? ' selected' : '')
                + '>Нет</option>'
                + '</select>';
        } else {
            html += '<input class="sb-input" type="number"'
                + ' data-responsive-key="' + escapeHtml(key) + '"'
                + ' min="' + Number(definition.min) + '"'
                + ' max="' + Number(definition.max) + '"'
                + ' step="' + Number(definition.step || 1) + '"'
                + ' placeholder="Наследовать"'
                + ' value="' + (
                    value === undefined || value === null
                        ? ''
                        : escapeHtml(String(value))
                ) + '">';
        }

        if (hint) {
            html += '<small>' + escapeHtml(hint) + '</small>';
        }

        html += '</label>';

        return html;
    }

    function renderFields() {
        ensurePanel();

        if (!panel || !fieldsHost) {
            return;
        }

        panel.hidden = !supported(draftType);

        if (!supported(draftType)) {
            fieldsHost.innerHTML = '';
            return;
        }

        var hint = node('blockResponsiveInheritanceHint');

        if (hint) {
            hint.textContent = inheritedFromText();
        }

        var config = (
            draftResponsive
            && typeof draftResponsive[currentResponsiveDevice] === 'object'
            && draftResponsive[currentResponsiveDevice] !== null
        ) ? draftResponsive[currentResponsiveDevice] : {};

        fieldsHost.innerHTML = fieldDefinitions(draftType)
            .map(function (definition) {
                return fieldHtml(
                    definition,
                    Object.prototype.hasOwnProperty.call(
                        config,
                        definition.key
                    ) ? config[definition.key] : undefined
                );
            })
            .join('');

        syncDeviceButtons();
    }

    function readFieldValue(input, definition) {
        var raw = String(input.value == null ? '' : input.value).trim();

        if (raw === '') {
            return undefined;
        }

        if (definition.type === 'boolean') {
            if (raw === 'true') {
                return true;
            }

            if (raw === 'false') {
                return false;
            }

            return undefined;
        }

        if (definition.type === 'select') {
            var allowed = (definition.options || []).map(function (item) {
                return String(item[0]);
            });

            return allowed.indexOf(raw) !== -1
                ? raw
                : undefined;
        }

        return clampNumber(
            raw,
            Number(definition.min),
            Number(definition.max)
        );
    }

    function syncDraftFromFields() {
        if (
            !panel
            || panel.hidden
            || !fieldsHost
            || !supported(draftType)
        ) {
            return;
        }

        var oldConfig = (
            draftResponsive[currentResponsiveDevice]
            && typeof draftResponsive[currentResponsiveDevice] === 'object'
        ) ? cloneObject(draftResponsive[currentResponsiveDevice]) : {};

        var definitions = fieldDefinitions(draftType);

        definitions.forEach(function (definition) {
            var input = fieldsHost.querySelector(
                '[data-responsive-key="' + definition.key + '"]'
            );

            if (!input) {
                return;
            }

            var value = readFieldValue(input, definition);

            if (value === undefined || value === null) {
                delete oldConfig[definition.key];
            } else {
                oldConfig[definition.key] = value;
            }
        });

        if (Object.keys(oldConfig).length) {
            draftResponsive[currentResponsiveDevice] = oldConfig;
        } else {
            delete draftResponsive[currentResponsiveDevice];
        }
    }

    function fillResponsivePanel(block) {
        ensurePanel();

        block = block || {};
        draftBlockId = Number(block.id || 0);
        draftType = String(block.type || '');
        draftResponsive = cloneObject(
            block.props && block.props._responsive
                ? block.props._responsive
                : {}
        );

        var previewDevice = String(state.previewDevice || '');

        if (
            previewDevice === 'tablet'
            || previewDevice === 'mobile'
        ) {
            currentResponsiveDevice = previewDevice;
        }

        renderFields();
    }

    function responsiveDataForSave() {
        syncDraftFromFields();

        var result = cloneObject(draftResponsive);

        ['tablet', 'mobile'].forEach(function (device) {
            if (
                result[device]
                && typeof result[device] === 'object'
                && !Object.keys(result[device]).length
            ) {
                delete result[device];
            }
        });

        return result;
    }

    function effectiveResponsive(block, device) {
        if (!block || device === 'desktop') {
            return {};
        }

        var responsive = (
            Number(block.id || 0) === draftBlockId
            && String(block.type || '') === draftType
        ) ? responsiveDataForSave() : cloneObject(
            block.props && block.props._responsive
                ? block.props._responsive
                : {}
        );

        var result = {};

        if (
            responsive.tablet
            && typeof responsive.tablet === 'object'
        ) {
            Object.assign(result, responsive.tablet);
        }

        if (
            device === 'mobile'
            && responsive.mobile
            && typeof responsive.mobile === 'object'
        ) {
            Object.assign(result, responsive.mobile);
        }

        return result;
    }

    function alignMargins(nodeElement, align) {
        if (!nodeElement) {
            return;
        }

        if (align === 'center') {
            nodeElement.style.marginLeft = 'auto';
            nodeElement.style.marginRight = 'auto';
        } else if (align === 'right') {
            nodeElement.style.marginLeft = 'auto';
            nodeElement.style.marginRight = '0';
        } else {
            nodeElement.style.marginLeft = '0';
            nodeElement.style.marginRight = 'auto';
        }
    }

    function applyHeadingPreview(host, config) {
        var element = host.querySelector('.sb-vb-heading');

        if (!element) {
            return;
        }

        if (config.size !== undefined) {
            element.style.fontSize = Number(config.size) + 'px';
        }

        if (config.align !== undefined) {
            element.style.textAlign = String(config.align);
            alignMargins(element, String(config.align));
        }

        if (config.maxWidth !== undefined) {
            element.style.maxWidth = Number(config.maxWidth) > 0
                ? Number(config.maxWidth) + 'px'
                : 'none';
        }
    }

    function applyTextPreview(host, config) {
        var element = host.querySelector('.sb-vb-text');

        if (!element) {
            return;
        }

        if (config.size !== undefined) {
            element.style.fontSize = Number(config.size) + 'px';
        }

        if (config.align !== undefined) {
            element.style.textAlign = String(config.align);
            alignMargins(element, String(config.align));
        }

        if (config.lineHeight !== undefined) {
            element.style.lineHeight = String(config.lineHeight);
        }

        if (config.maxWidth !== undefined) {
            element.style.maxWidth = Number(config.maxWidth) > 0
                ? Number(config.maxWidth) + 'px'
                : 'none';
        }
    }

    function applyButtonPreview(host, config) {
        var wrap = host.querySelector('.sb-vb-button-wrap');
        var button = host.querySelector('.sb-vb-button');

        if (wrap && config.align !== undefined) {
            wrap.style.setProperty(
                '--vb-align',
                String(config.align)
            );
            wrap.style.textAlign = String(config.align);
        }

        if (
            button
            && Object.prototype.hasOwnProperty.call(
                config,
                'fullWidth'
            )
        ) {
            button.classList.toggle(
                'is-full',
                !!config.fullWidth
            );
        }
    }

    function applyImagePreview(host, config) {
        var figure = host.querySelector('.sb-vb-image');

        if (!figure) {
            return;
        }

        if (config.width !== undefined) {
            figure.style.setProperty(
                '--vb-image-width',
                Number(config.width) + '%'
            );
        }

        if (config.radius !== undefined) {
            figure.style.setProperty(
                '--vb-image-radius',
                Number(config.radius) + 'px'
            );
        }

        if (config.align !== undefined) {
            figure.classList.remove(
                'is-left',
                'is-center',
                'is-right'
            );
            figure.classList.add(
                'is-' + String(config.align)
            );
        }
    }

    function applyHeroPreview(host, config) {
        var hero = host.querySelector('.sb-vb-hero');

        if (!hero) {
            return;
        }

        if (config.minHeight !== undefined) {
            hero.style.setProperty(
                '--vb-hero-height',
                Number(config.minHeight) + 'px'
            );
        }

        if (config.radius !== undefined) {
            hero.style.setProperty(
                '--vb-hero-radius',
                Number(config.radius) + 'px'
            );
        }

        if (config.align !== undefined) {
            hero.classList.toggle(
                'is-center',
                String(config.align) === 'center'
            );
        }

        var title = hero.querySelector('.sb-vb-hero__title');

        if (title && config.titleSize !== undefined) {
            title.style.fontSize =
                Number(config.titleSize) + 'px';
        }
    }

    function applyCardsPreview(host, config) {
        var grid = host.querySelector('.sb-vb-cards-grid');

        if (grid && config.columns !== undefined) {
            grid.style.setProperty(
                '--vb-columns',
                Number(config.columns)
            );
        }

        if (config.align !== undefined) {
            var preview = host.querySelector(
                '.sb-editor-block-preview > div'
            );

            if (preview) {
                preview.style.textAlign =
                    String(config.align);
            }
        }
    }

    function applyResponsivePreview() {
        var device = String(
            state.previewDevice || 'desktop'
        );

        if (device === 'desktop') {
            return;
        }

        (state.blocks || []).forEach(function (block) {
            var type = String(block.type || '');

            if (!supported(type)) {
                return;
            }

            var host = document.querySelector(
                '.sb-editor-block[data-block-id="'
                + Number(block.id || 0)
                + '"]'
            );

            if (!host) {
                return;
            }

            var config = effectiveResponsive(
                block,
                device
            );

            if (config.marginTop !== undefined) {
                host.style.marginTop =
                    Number(config.marginTop) + 'px';
            }

            if (config.marginBottom !== undefined) {
                host.style.marginBottom =
                    Number(config.marginBottom) + 'px';
            }

            if (type === 'heading') {
                applyHeadingPreview(host, config);
            } else if (type === 'text') {
                applyTextPreview(host, config);
            } else if (type === 'button') {
                applyButtonPreview(host, config);
            } else if (type === 'image') {
                applyImagePreview(host, config);
            } else if (type === 'hero') {
                applyHeroPreview(host, config);
            } else if (type === 'cards') {
                applyCardsPreview(host, config);
            }
        });
    }

    function schedulePreview() {
        clearTimeout(previewTimer);

        previewTimer = window.setTimeout(function () {
            if (typeof window.renderBlocks === 'function') {
                window.renderBlocks();
            } else {
                applyResponsivePreview();
            }
        }, 35);
    }

    ensurePanel();

    var originalFillBlockForm = window.fillBlockForm;

    if (typeof originalFillBlockForm === 'function') {
        window.fillBlockForm = function (block) {
            var result = originalFillBlockForm.apply(
                this,
                arguments
            );

            fillResponsivePanel(block || currentBlock());

            return result;
        };
    }

    var originalCollectVisualBlockData =
        window.collectVisualBlockData;

    if (
        typeof originalCollectVisualBlockData
        === 'function'
    ) {
        window.collectVisualBlockData = function (block) {
            var result =
                originalCollectVisualBlockData.apply(
                    this,
                    arguments
                );

            block = block || currentBlock() || {};

            if (
                result
                && result.props
                && supported(String(block.type || ''))
                && Number(block.id || 0) === draftBlockId
            ) {
                var responsive =
                    responsiveDataForSave();

                if (Object.keys(responsive).length) {
                    result.props._responsive =
                        responsive;
                } else {
                    delete result.props._responsive;
                }
            }

            return result;
        };
    }

    var originalRenderBlocks = window.renderBlocks;

    if (typeof originalRenderBlocks === 'function') {
        window.renderBlocks = function () {
            var result = originalRenderBlocks.apply(
                this,
                arguments
            );

            applyResponsivePreview();

            return result;
        };
    }

    document.addEventListener('click', function (event) {
        var previewButton = event.target.closest(
            '[data-preview-device]'
        );

        if (!previewButton) {
            return;
        }

        window.setTimeout(function () {
            var device = String(
                previewButton.getAttribute(
                    'data-preview-device'
                ) || ''
            );

            if (
                device === 'tablet'
                || device === 'mobile'
            ) {
                currentResponsiveDevice = device;
                renderFields();
            }

            if (typeof window.renderBlocks === 'function') {
                window.renderBlocks();
            }
        }, 0);
    });

    var initial = currentBlock();

    if (initial) {
        fillResponsivePanel(initial);
    }
})();
