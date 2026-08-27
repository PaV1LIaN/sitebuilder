/* =========================================================
   SITEBUILDER RESPONSIVE STAGE 2
   Quote / Stats / Divider / Spacer + Page Sections.
   Extends Stage 1 without autosave or API changes.
   ========================================================= */
(function () {
    'use strict';

    if (!window.SB_EDITOR_CONFIG || typeof state === 'undefined') {
        return;
    }

    var BLOCK_TYPES = [
        'quote',
        'stats',
        'divider',
        'spacer'
    ];

    var COMMON_FIELDS = [
        {
            key: 'marginTop',
            label: 'Отступ сверху, px',
            type: 'number',
            min: 0,
            max: 240,
            step: 1
        },
        {
            key: 'marginBottom',
            label: 'Отступ снизу, px',
            type: 'number',
            min: 0,
            max: 240,
            step: 1
        }
    ];

    var TYPE_FIELDS = {
        quote: [
            {
                key: 'align',
                label: 'Выравнивание',
                type: 'select',
                options: [
                    ['left', 'Слева'],
                    ['center', 'По центру']
                ]
            },
            {
                key: 'textSize',
                label: 'Размер цитаты, px',
                type: 'number',
                min: 14,
                max: 48,
                step: 1
            }
        ],
        stats: [
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
                label: 'Выравнивание',
                type: 'select',
                options: [
                    ['left', 'Слева'],
                    ['center', 'По центру']
                ]
            },
            {
                key: 'valueSize',
                label: 'Размер значения, px',
                type: 'number',
                min: 18,
                max: 72,
                step: 1
            }
        ],
        divider: [
            {
                key: 'width',
                label: 'Ширина, %',
                type: 'number',
                min: 10,
                max: 100,
                step: 1
            },
            {
                key: 'thickness',
                label: 'Толщина, px',
                type: 'number',
                min: 1,
                max: 8,
                step: 1
            },
            {
                key: 'margin',
                label: 'Внешний отступ, px',
                type: 'number',
                min: 0,
                max: 160,
                step: 1
            }
        ],
        spacer: [
            {
                key: 'height',
                label: 'Высота, px',
                type: 'number',
                min: 0,
                max: 400,
                step: 1
            }
        ]
    };

    var panel = null;
    var fieldsHost = null;
    var currentDevice = 'tablet';
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

    function clone(value) {
        try {
            return JSON.parse(JSON.stringify(value || {}));
        } catch (error) {
            return {};
        }
    }

    function supported(type) {
        return BLOCK_TYPES.indexOf(String(type || '')) !== -1;
    }

    function definitions(type) {
        return (TYPE_FIELDS[type] || []).concat(COMMON_FIELDS);
    }

    function clamp(value, min, max) {
        value = Number(value);

        if (!isFinite(value)) {
            return null;
        }

        return Math.max(min, Math.min(max, value));
    }

    function ensurePanel() {
        panel = node('blockResponsiveOverridesPanel');
        fieldsHost = node('blockResponsiveFields');

        return panel && fieldsHost ? panel : null;
    }

    function syncButtons() {
        if (!panel) return;

        panel.querySelectorAll('[data-responsive-device]')
            .forEach(function (button) {
                button.classList.toggle(
                    'is-active',
                    button.getAttribute('data-responsive-device')
                        === currentDevice
                );
            });
    }

    function fieldHtml(def, value) {
        var key = String(def.key || '');
        var html = ''
            + '<label class="sb-responsive-field">'
            + '<span class="sb-responsive-field__label">'
            + escapeHtml(def.label || key)
            + '</span>';

        if (def.type === 'select') {
            html += '<select class="sb-select" data-stage2-responsive-key="'
                + escapeHtml(key)
                + '">'
                + '<option value="">Наследовать</option>';

            (def.options || []).forEach(function (option) {
                html += '<option value="'
                    + escapeHtml(option[0])
                    + '"'
                    + (String(value) === String(option[0])
                        ? ' selected'
                        : '')
                    + '>'
                    + escapeHtml(option[1])
                    + '</option>';
            });

            html += '</select>';
        } else {
            html += '<input class="sb-input" type="number"'
                + ' data-stage2-responsive-key="'
                + escapeHtml(key)
                + '"'
                + ' min="' + Number(def.min) + '"'
                + ' max="' + Number(def.max) + '"'
                + ' step="' + Number(def.step || 1) + '"'
                + ' placeholder="Наследовать"'
                + ' value="'
                + (
                    value === undefined || value === null
                        ? ''
                        : escapeHtml(String(value))
                )
                + '">';
        }

        html += '</label>';

        return html;
    }

    function hideLegacySpacerDeviceFields(type) {
        [
            'spacerTabletHeightInput',
            'spacerMobileHeightInput'
        ].forEach(function (id) {
            var input = node(id);

            if (!input) return;

            var unit = input.closest(
                '.sb-block-mode-field-unit, .sb-field, label'
            );

            if (!unit) return;

            if (type === 'spacer') {
                unit.hidden = true;
                unit.setAttribute(
                    'data-stage2-spacer-legacy-hidden',
                    '1'
                );
            } else if (
                unit.getAttribute(
                    'data-stage2-spacer-legacy-hidden'
                ) === '1'
            ) {
                unit.hidden = false;
                unit.removeAttribute(
                    'data-stage2-spacer-legacy-hidden'
                );
            }
        });
    }

    function seedLegacySpacer(block) {
        if (
            draftType !== 'spacer'
            || !block
        ) {
            return;
        }

        var content = block.content || {};

        if (
            (
                !draftResponsive.tablet
                || draftResponsive.tablet.height === undefined
            )
            && content.tabletHeight !== undefined
        ) {
            draftResponsive.tablet =
                draftResponsive.tablet || {};

            draftResponsive.tablet.height = clamp(
                content.tabletHeight,
                0,
                400
            );
        }

        if (
            (
                !draftResponsive.mobile
                || draftResponsive.mobile.height === undefined
            )
            && content.mobileHeight !== undefined
        ) {
            draftResponsive.mobile =
                draftResponsive.mobile || {};

            draftResponsive.mobile.height = clamp(
                content.mobileHeight,
                0,
                400
            );
        }
    }

    function renderFields() {
        if (!ensurePanel()) {
            return;
        }

        if (!supported(draftType)) {
            return;
        }

        panel.hidden = false;

        var hint = node('blockResponsiveInheritanceHint');

        if (hint) {
            hint.textContent = currentDevice === 'mobile'
                ? 'Пусто → Планшет → Desktop'
                : 'Пусто → Desktop';
        }

        var config = (
            draftResponsive[currentDevice]
            && typeof draftResponsive[currentDevice] === 'object'
        )
            ? draftResponsive[currentDevice]
            : {};

        fieldsHost.innerHTML = definitions(draftType)
            .map(function (def) {
                return fieldHtml(
                    def,
                    Object.prototype.hasOwnProperty.call(
                        config,
                        def.key
                    )
                        ? config[def.key]
                        : undefined
                );
            })
            .join('');

        syncButtons();
    }

    function readValue(input, def) {
        var raw = String(
            input.value == null ? '' : input.value
        ).trim();

        if (raw === '') {
            return undefined;
        }

        if (def.type === 'select') {
            var allowed = (def.options || [])
                .map(function (option) {
                    return String(option[0]);
                });

            return allowed.indexOf(raw) !== -1
                ? raw
                : undefined;
        }

        return clamp(raw, def.min, def.max);
    }

    function syncDraftFromFields() {
        if (
            !panel
            || !fieldsHost
            || !supported(draftType)
        ) {
            return;
        }

        var config = (
            draftResponsive[currentDevice]
            && typeof draftResponsive[currentDevice] === 'object'
        )
            ? clone(draftResponsive[currentDevice])
            : {};

        definitions(draftType).forEach(function (def) {
            var input = fieldsHost.querySelector(
                '[data-stage2-responsive-key="'
                + def.key
                + '"]'
            );

            if (!input) return;

            var value = readValue(input, def);

            if (value === undefined || value === null) {
                delete config[def.key];
            } else {
                config[def.key] = value;
            }
        });

        if (Object.keys(config).length) {
            draftResponsive[currentDevice] = config;
        } else {
            delete draftResponsive[currentDevice];
        }
    }

    function fillStage2(block) {
        block = block || {};

        draftBlockId = Number(block.id || 0);
        draftType = String(block.type || '');
        draftResponsive = clone(
            block.props && block.props._responsive
                ? block.props._responsive
                : {}
        );

        var device = String(state.previewDevice || '');

        if (device === 'tablet' || device === 'mobile') {
            currentDevice = device;
        }

        seedLegacySpacer(block);
        hideLegacySpacerDeviceFields(draftType);

        if (supported(draftType)) {
            renderFields();
        }
    }

    function responsiveForSave() {
        syncDraftFromFields();

        var result = clone(draftResponsive);

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
        )
            ? responsiveForSave()
            : clone(
                block.props && block.props._responsive
                    ? block.props._responsive
                    : {}
            );

        var result = {};

        if (
            responsive.tablet
            && typeof responsive.tablet === 'object'
        ) {
            Object.assign(
                result,
                responsive.tablet
            );
        }

        if (
            device === 'mobile'
            && responsive.mobile
            && typeof responsive.mobile === 'object'
        ) {
            Object.assign(
                result,
                responsive.mobile
            );
        }

        return result;
    }

    function applyBlockPreview() {
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

            if (!host) return;

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

            if (type === 'quote') {
                var quote = host.querySelector(
                    '.sb-vb-quote'
                );

                if (quote && config.align !== undefined) {
                    quote.style.setProperty(
                        '--vb-align',
                        String(config.align)
                    );
                    quote.style.textAlign =
                        String(config.align);
                }

                var quoteText = host.querySelector(
                    '.sb-vb-quote__text'
                );

                if (
                    quoteText
                    && config.textSize !== undefined
                ) {
                    quoteText.style.fontSize =
                        Number(config.textSize)
                        + 'px';
                }
            } else if (type === 'stats') {
                var statsGrid = host.querySelector(
                    '.sb-vb-stats-grid'
                );

                if (
                    statsGrid
                    && config.columns !== undefined
                ) {
                    statsGrid.style.setProperty(
                        '--vb-columns',
                        Number(config.columns)
                    );
                }

                host.querySelectorAll('.sb-vb-stat')
                    .forEach(function (item) {
                        if (config.align !== undefined) {
                            item.style.textAlign =
                                String(config.align);
                        }
                    });

                host.querySelectorAll(
                    '.sb-vb-stat__value'
                ).forEach(function (item) {
                    if (
                        config.valueSize
                        !== undefined
                    ) {
                        item.style.fontSize =
                            Number(config.valueSize)
                            + 'px';
                    }
                });
            } else if (type === 'divider') {
                var divider = host.querySelector(
                    '.sb-vb-divider'
                );

                if (!divider) return;

                if (config.width !== undefined) {
                    divider.style.setProperty(
                        '--vb-divider-width',
                        Number(config.width) + '%'
                    );
                }

                if (
                    config.thickness
                    !== undefined
                ) {
                    divider.style.setProperty(
                        '--vb-divider-thickness',
                        Number(config.thickness)
                        + 'px'
                    );
                }

                if (config.margin !== undefined) {
                    divider.style.setProperty(
                        '--vb-divider-margin',
                        Number(config.margin) + 'px'
                    );
                }
            } else if (type === 'spacer') {
                var spacer = host.querySelector(
                    '.sb-vb-spacer'
                );

                if (
                    spacer
                    && config.height !== undefined
                ) {
                    spacer.style.setProperty(
                        '--vb-spacer-' + device,
                        Number(config.height) + 'px'
                    );
                }
            }
        });
    }

    function schedulePreview() {
        clearTimeout(previewTimer);

        previewTimer = window.setTimeout(
            function () {
                if (
                    typeof window.renderBlocks
                    === 'function'
                ) {
                    window.renderBlocks();
                }
            },
            40
        );
    }

    /* =====================================================
       SECTION RESPONSIVE UI
       ===================================================== */

    var SECTION_FIELDS = [
        {
            key: 'gap',
            label: 'Промежуток, px',
            min: 0,
            max: 120
        },
        {
            key: 'paddingTop',
            label: 'Отступ сверху, px',
            min: 0,
            max: 240
        },
        {
            key: 'paddingBottom',
            label: 'Отступ снизу, px',
            min: 0,
            max: 240
        },
        {
            key: 'paddingX',
            label: 'Отступ по бокам, px',
            min: 0,
            max: 160
        },
        {
            key: 'minHeight',
            label: 'Мин. высота, px',
            min: 0,
            max: 1200
        }
    ];

    function sectionById(id) {
        id = Number(id || 0);

        if (typeof getSectionById === 'function') {
            return getSectionById(id);
        }

        return (state.pageSections || [])
            .find(function (section) {
                return Number(section.id || 0)
                    === id;
            }) || null;
    }

    function sectionResponsive(section) {
        return clone(
            section
            && section.props
            && section.props._responsive
                ? section.props._responsive
                : {}
        );
    }

    function sectionFieldHtml(
        sectionId,
        device,
        def,
        value
    ) {
        return ''
            + '<label class="sb-section-responsive-field">'
            + '<span>' + escapeHtml(def.label)
            + '</span>'
            + '<input type="number"'
            + ' min="' + Number(def.min) + '"'
            + ' max="' + Number(def.max) + '"'
            + ' step="1"'
            + ' placeholder="Наследовать"'
            + ' data-section-responsive-id="'
            + Number(sectionId)
            + '"'
            + ' data-section-responsive-device="'
            + escapeHtml(device)
            + '"'
            + ' data-section-responsive-key="'
            + escapeHtml(def.key)
            + '"'
            + ' value="'
            + (
                value === undefined
                || value === null
                    ? ''
                    : escapeHtml(String(value))
            )
            + '">'
            + '</label>';
    }

    function sectionDeviceHtml(
        sectionId,
        device,
        title,
        config
    ) {
        var html = ''
            + '<div class="sb-section-responsive-device"'
            + ' data-section-responsive-group="'
            + Number(sectionId)
            + '"'
            + ' data-section-responsive-device-group="'
            + escapeHtml(device)
            + '">'
            + '<div class="sb-section-responsive-device__head">'
            + '<strong>' + escapeHtml(title)
            + '</strong>'
            + '<button type="button"'
            + ' data-section-responsive-reset="'
            + Number(sectionId)
            + '"'
            + ' data-section-responsive-reset-device="'
            + escapeHtml(device)
            + '">Сбросить</button>'
            + '</div>'
            + '<div class="sb-section-responsive-grid">';

        SECTION_FIELDS.forEach(function (def) {
            html += sectionFieldHtml(
                sectionId,
                device,
                def,
                Object.prototype.hasOwnProperty.call(
                    config,
                    def.key
                )
                    ? config[def.key]
                    : undefined
            );
        });

        var align = String(
            config.verticalAlign || ''
        );

        html += ''
            + '<label class="sb-section-responsive-field">'
            + '<span>По вертикали</span>'
            + '<select'
            + ' data-section-responsive-id="'
            + Number(sectionId)
            + '"'
            + ' data-section-responsive-device="'
            + escapeHtml(device)
            + '"'
            + ' data-section-responsive-key="verticalAlign">'
            + '<option value="">Наследовать</option>'
            + '<option value="start"'
            + (align === 'start' ? ' selected' : '')
            + '>Сверху</option>'
            + '<option value="center"'
            + (align === 'center' ? ' selected' : '')
            + '>По центру</option>'
            + '<option value="end"'
            + (align === 'end' ? ' selected' : '')
            + '>Снизу</option>'
            + '<option value="stretch"'
            + (align === 'stretch' ? ' selected' : '')
            + '>Растянуть</option>'
            + '</select>'
            + '</label>'
            + '</div>'
            + '</div>';

        return html;
    }

    function enhanceSectionCards() {
        document.querySelectorAll(
            '[data-page-section-id]'
        ).forEach(function (card) {
            var sectionId = Number(
                card.getAttribute(
                    'data-page-section-id'
                ) || 0
            );

            if (
                sectionId <= 0
                || card.querySelector(
                    '.sb-section-responsive'
                )
            ) {
                return;
            }

            var section = sectionById(sectionId);

            if (!section) return;

            var responsive =
                sectionResponsive(section);

            var tablet = (
                responsive.tablet
                && typeof responsive.tablet
                    === 'object'
            )
                ? responsive.tablet
                : {};

            var mobile = (
                responsive.mobile
                && typeof responsive.mobile
                    === 'object'
            )
                ? responsive.mobile
                : {};

            var details =
                document.createElement('details');

            details.className =
                'sb-section-responsive';

            details.innerHTML = ''
                + '<summary>Адаптивность секции</summary>'
                + '<div class="sb-section-responsive__body">'
                + '<p>Колонки уже задаются выше. Здесь —'
                + ' точные отступы и геометрия.'
                + ' Пустое поле наследует Desktop,'
                + ' а Mobile — Tablet → Desktop.</p>'
                + '<div class="sb-section-responsive-devices">'
                + sectionDeviceHtml(
                    sectionId,
                    'tablet',
                    'Планшет',
                    tablet
                )
                + sectionDeviceHtml(
                    sectionId,
                    'mobile',
                    'Телефон',
                    mobile
                )
                + '</div>'
                + '</div>';

            var actions = card.querySelector(
                '.sb-page-section-card__actions'
            );

            if (actions) {
                card.insertBefore(
                    details,
                    actions
                );
            } else {
                card.appendChild(details);
            }
        });
    }

    function sectionInputValue(
        card,
        device,
        key
    ) {
        var input = card.querySelector(
            '[data-section-responsive-device="'
            + device
            + '"]'
            + '[data-section-responsive-key="'
            + key
            + '"]'
        );

        if (!input) return undefined;

        var raw = String(
            input.value == null ? '' : input.value
        ).trim();

        if (raw === '') {
            return undefined;
        }

        if (key === 'verticalAlign') {
            return [
                'start',
                'center',
                'end',
                'stretch'
            ].indexOf(raw) !== -1
                ? raw
                : undefined;
        }

        var def = SECTION_FIELDS.find(
            function (item) {
                return item.key === key;
            }
        );

        return def
            ? clamp(
                raw,
                def.min,
                def.max
            )
            : undefined;
    }

    function syncSectionResponsive(sectionId) {
        var section = sectionById(sectionId);

        if (!section) return;

        var card = document.querySelector(
            '[data-page-section-id="'
            + Number(sectionId)
            + '"]'
        );

        if (!card) return;

        var responsive =
            sectionResponsive(section);

        ['tablet', 'mobile'].forEach(
            function (device) {
                var config = {};

                SECTION_FIELDS.forEach(
                    function (def) {
                        var value =
                            sectionInputValue(
                                card,
                                device,
                                def.key
                            );

                        if (
                            value !== undefined
                            && value !== null
                        ) {
                            config[def.key] =
                                value;
                        }
                    }
                );

                var align =
                    sectionInputValue(
                        card,
                        device,
                        'verticalAlign'
                    );

                if (align !== undefined) {
                    config.verticalAlign = align;
                }

                if (Object.keys(config).length) {
                    responsive[device] = config;
                } else {
                    delete responsive[device];
                }
            }
        );

        section.props = Object.assign(
            {},
            section.props || {}
        );

        if (Object.keys(responsive).length) {
            section.props._responsive =
                responsive;
        } else {
            delete section.props._responsive;
        }
    }

    function effectiveSectionConfig(
        section,
        device
    ) {
        if (!section || device === 'desktop') {
            return {};
        }

        var responsive =
            sectionResponsive(section);

        var result = {};

        if (
            responsive.tablet
            && typeof responsive.tablet
                === 'object'
        ) {
            Object.assign(
                result,
                responsive.tablet
            );
        }

        if (
            device === 'mobile'
            && responsive.mobile
            && typeof responsive.mobile
                === 'object'
        ) {
            Object.assign(
                result,
                responsive.mobile
            );
        }

        return result;
    }

    function applySectionPreview() {
        var device = String(
            state.previewDevice || 'desktop'
        );

        if (device === 'desktop') {
            return;
        }

        (state.pageSections || [])
            .forEach(function (section) {
                var preview =
                    document.querySelector(
                        '.sb-editor-section-preview'
                        + '[data-editor-section-id="'
                        + Number(section.id || 0)
                        + '"]'
                    );

                if (!preview) return;

                var config =
                    effectiveSectionConfig(
                        section,
                        device
                    );

                if (config.gap !== undefined) {
                    preview.style.setProperty(
                        '--sb-preview-gap',
                        Number(config.gap)
                        + 'px'
                    );
                }

                if (
                    config.paddingTop
                    !== undefined
                ) {
                    preview.style.setProperty(
                        '--sb-preview-section-pt',
                        Number(config.paddingTop)
                        + 'px'
                    );
                }

                if (
                    config.paddingBottom
                    !== undefined
                ) {
                    preview.style.setProperty(
                        '--sb-preview-section-pb',
                        Number(config.paddingBottom)
                        + 'px'
                    );
                }

                if (
                    config.paddingX
                    !== undefined
                ) {
                    preview.style.setProperty(
                        '--sb-preview-section-px',
                        Number(config.paddingX)
                        + 'px'
                    );
                }

                if (
                    config.minHeight
                    !== undefined
                ) {
                    preview.style.setProperty(
                        '--sb-preview-section-min-height',
                        Number(config.minHeight)
                        + 'px'
                    );
                }

                if (
                    config.verticalAlign
                    !== undefined
                ) {
                    preview.style.setProperty(
                        '--sb-preview-align',
                        String(
                            config.verticalAlign
                        )
                    );
                }
            });
    }

    /* =====================================================
       WRAPPERS
       ===================================================== */

    var originalFillBlockForm =
        window.fillBlockForm;

    if (
        typeof originalFillBlockForm
        === 'function'
    ) {
        window.fillBlockForm = function (block) {
            var result =
                originalFillBlockForm.apply(
                    this,
                    arguments
                );

            fillStage2(
                block || currentBlock()
            );

            return result;
        };
    }

    var originalCollectVisual =
        window.collectVisualBlockData;

    if (
        typeof originalCollectVisual
        === 'function'
    ) {
        window.collectVisualBlockData =
            function (block) {
                var result =
                    originalCollectVisual.apply(
                        this,
                        arguments
                    );

                block =
                    block
                    || currentBlock()
                    || {};

                if (
                    !result
                    || !result.props
                    || !supported(
                        String(block.type || '')
                    )
                    || Number(block.id || 0)
                        !== draftBlockId
                ) {
                    return result;
                }

                var responsive =
                    responsiveForSave();

                if (
                    Object.keys(
                        responsive
                    ).length
                ) {
                    result.props._responsive =
                        responsive;
                } else {
                    delete result.props._responsive;
                }

                if (
                    String(block.type || '')
                    === 'spacer'
                    && result.content
                ) {
                    var tablet =
                        responsive.tablet
                        && responsive.tablet
                            .height !== undefined
                            ? responsive.tablet
                                .height
                            : undefined;

                    var mobile =
                        responsive.mobile
                        && responsive.mobile
                            .height !== undefined
                            ? responsive.mobile
                                .height
                            : tablet;

                    if (tablet !== undefined) {
                        result.content
                            .tabletHeight =
                            Number(tablet);
                    }

                    if (mobile !== undefined) {
                        result.content
                            .mobileHeight =
                            Number(mobile);
                    }
                }

                return result;
            };
    }

    var originalRenderBlocks =
        window.renderBlocks;

    if (
        typeof originalRenderBlocks
        === 'function'
    ) {
        window.renderBlocks = function () {
            var result =
                originalRenderBlocks.apply(
                    this,
                    arguments
                );

            applyBlockPreview();
            applySectionPreview();

            return result;
        };
    }

    var originalRenderSections =
        window.renderPageSectionsPanel;

    if (
        typeof originalRenderSections
        === 'function'
    ) {
        window.renderPageSectionsPanel =
            function () {
                var result =
                    originalRenderSections.apply(
                        this,
                        arguments
                    );

                enhanceSectionCards();

                return result;
            };
    }

    var originalSaveSection =
        window.savePageSection;

    if (
        typeof originalSaveSection
        === 'function'
    ) {
        window.savePageSection =
            async function (sectionId) {
                syncSectionResponsive(
                    Number(sectionId || 0)
                );

                return originalSaveSection.apply(
                    this,
                    arguments
                );
            };
    }

    if (ensurePanel()) {
        panel.addEventListener(
            'input',
            function (event) {
                if (
                    !supported(draftType)
                    || !event.target.closest(
                        '[data-stage2-responsive-key]'
                    )
                ) {
                    return;
                }

                syncDraftFromFields();
                schedulePreview();
            }
        );

        panel.addEventListener(
            'change',
            function (event) {
                if (
                    !supported(draftType)
                    || !event.target.closest(
                        '[data-stage2-responsive-key]'
                    )
                ) {
                    return;
                }

                syncDraftFromFields();
                schedulePreview();
            }
        );

        panel.addEventListener(
            'click',
            function (event) {
                var button =
                    event.target.closest(
                        '[data-responsive-device]'
                    );

                if (
                    !button
                    || !supported(draftType)
                ) {
                    return;
                }

                syncDraftFromFields();

                currentDevice = String(
                    button.getAttribute(
                        'data-responsive-device'
                    ) || 'tablet'
                );

                window.setTimeout(
                    function () {
                        renderFields();
                        schedulePreview();
                    },
                    0
                );
            }
        );
    }

    document.addEventListener(
        'input',
        function (event) {
            var input = event.target.closest(
                '[data-section-responsive-id]'
            );

            if (!input) return;

            var sectionId = Number(
                input.getAttribute(
                    'data-section-responsive-id'
                ) || 0
            );

            syncSectionResponsive(sectionId);
            schedulePreview();
        }
    );

    document.addEventListener(
        'change',
        function (event) {
            var input = event.target.closest(
                '[data-section-responsive-id]'
            );

            if (!input) return;

            var sectionId = Number(
                input.getAttribute(
                    'data-section-responsive-id'
                ) || 0
            );

            syncSectionResponsive(sectionId);
            schedulePreview();
        }
    );

    document.addEventListener(
        'click',
        function (event) {
            var reset =
                event.target.closest(
                    '[data-section-responsive-reset]'
                );

            if (reset) {
                var sectionId = Number(
                    reset.getAttribute(
                        'data-section-responsive-reset'
                    ) || 0
                );

                var device = String(
                    reset.getAttribute(
                        'data-section-responsive-reset-device'
                    ) || ''
                );

                var group = document.querySelector(
                    '[data-section-responsive-group="'
                    + sectionId
                    + '"]'
                    + '[data-section-responsive-device-group="'
                    + device
                    + '"]'
                );

                if (group) {
                    group.querySelectorAll(
                        'input, select'
                    ).forEach(
                        function (input) {
                            input.value = '';
                        }
                    );

                    syncSectionResponsive(
                        sectionId
                    );

                    schedulePreview();
                }

                return;
            }

            var previewButton =
                event.target.closest(
                    '[data-preview-device]'
                );

            if (!previewButton) return;

            var device = String(
                previewButton.getAttribute(
                    'data-preview-device'
                ) || ''
            );

            if (
                device === 'tablet'
                || device === 'mobile'
            ) {
                currentDevice = device;
            }

            window.setTimeout(
                function () {
                    if (
                        supported(draftType)
                    ) {
                        renderFields();
                    }

                    applySectionPreview();
                },
                0
            );
        }
    );

    enhanceSectionCards();

    var initial = currentBlock();

    if (initial) {
        fillStage2(initial);
    }
})();
