/* =========================================================
   SITEBUILDER RESPONSIVE STAGE 3
   Business blocks: FAQ / Gallery / Pricing / Form / Video /
   Navigation / Footer.
   Extends the existing props._responsive model.
   No autosave.
   ========================================================= */
(function () {
    'use strict';

    if (!window.SB_EDITOR_CONFIG || typeof state === 'undefined') {
        return;
    }

    var TYPES = [
        'faq',
        'gallery',
        'pricing',
        'form',
        'video',
        'navigation',
        'footer'
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
        faq: [
            {
                key: 'titleSize',
                label: 'Размер заголовка, px',
                type: 'number',
                min: 18,
                max: 64,
                step: 1
            },
            {
                key: 'questionSize',
                label: 'Размер вопроса, px',
                type: 'number',
                min: 12,
                max: 28,
                step: 1
            },
            {
                key: 'itemGap',
                label: 'Промежуток, px',
                type: 'number',
                min: 0,
                max: 40,
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
            }
        ],
        gallery: [
            {
                key: 'columns',
                label: 'Колонок',
                type: 'number',
                min: 1,
                max: 6,
                step: 1
            },
            {
                key: 'gap',
                label: 'Промежуток, px',
                type: 'number',
                min: 0,
                max: 64,
                step: 1
            },
            {
                key: 'ratio',
                label: 'Формат',
                type: 'select',
                options: [
                    ['auto', 'Оригинал'],
                    ['16:9', '16:9'],
                    ['4:3', '4:3'],
                    ['1:1', '1:1'],
                    ['3:4', '3:4']
                ]
            },
            {
                key: 'radius',
                label: 'Скругление, px',
                type: 'number',
                min: 0,
                max: 40,
                step: 1
            }
        ],
        pricing: [
            {
                key: 'columns',
                label: 'Колонок',
                type: 'number',
                min: 1,
                max: 4,
                step: 1
            },
            {
                key: 'gap',
                label: 'Промежуток, px',
                type: 'number',
                min: 0,
                max: 64,
                step: 1
            },
            {
                key: 'cardPadding',
                label: 'Отступ карточки, px',
                type: 'number',
                min: 12,
                max: 56,
                step: 1
            },
            {
                key: 'titleSize',
                label: 'Размер заголовка, px',
                type: 'number',
                min: 18,
                max: 64,
                step: 1
            }
        ],
        form: [
            {
                key: 'columns',
                label: 'Колонок полей',
                type: 'number',
                min: 1,
                max: 2,
                step: 1
            },
            {
                key: 'gap',
                label: 'Промежуток, px',
                type: 'number',
                min: 0,
                max: 48,
                step: 1
            },
            {
                key: 'padding',
                label: 'Внутренний отступ, px',
                type: 'number',
                min: 0,
                max: 64,
                step: 1
            },
            {
                key: 'titleSize',
                label: 'Размер заголовка, px',
                type: 'number',
                min: 18,
                max: 64,
                step: 1
            },
            {
                key: 'buttonFullWidth',
                label: 'Кнопка на всю ширину',
                type: 'boolean'
            }
        ],
        video: [
            {
                key: 'width',
                label: 'Ширина, %',
                type: 'number',
                min: 20,
                max: 100,
                step: 1
            },
            {
                key: 'ratio',
                label: 'Формат',
                type: 'select',
                options: [
                    ['16:9', '16:9'],
                    ['4:3', '4:3'],
                    ['1:1', '1:1'],
                    ['9:16', '9:16']
                ]
            },
            {
                key: 'radius',
                label: 'Скругление, px',
                type: 'number',
                min: 0,
                max: 40,
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
        navigation: [
            {
                key: 'layout',
                label: 'Расположение',
                type: 'select',
                options: [
                    ['row', 'В строку'],
                    ['stack', 'Столбцом']
                ]
            },
            {
                key: 'gap',
                label: 'Промежуток, px',
                type: 'number',
                min: 0,
                max: 48,
                step: 1
            }
        ],
        footer: [
            {
                key: 'columns',
                label: 'Колонок',
                type: 'number',
                min: 1,
                max: 6,
                step: 1
            },
            {
                key: 'gap',
                label: 'Промежуток, px',
                type: 'number',
                min: 0,
                max: 64,
                step: 1
            },
            {
                key: 'padding',
                label: 'Внутренний отступ, px',
                type: 'number',
                min: 0,
                max: 64,
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
        return TYPES.indexOf(String(type || '')) !== -1;
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

    function esc(value) {
        return typeof escapeHtml === 'function'
            ? escapeHtml(value)
            : String(value == null ? '' : value)
                .replace(/[&<>"']/g, function (char) {
                    return {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#39;'
                    }[char];
                });
    }

    function ensurePanel() {
        panel = node('blockResponsiveOverridesPanel');
        fieldsHost = node('blockResponsiveFields');

        return panel && fieldsHost ? panel : null;
    }

    function syncButtons() {
        if (!panel) {
            return;
        }

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
            + esc(def.label || key)
            + '</span>';

        if (def.type === 'select') {
            html += '<select class="sb-select" data-stage3-responsive-key="'
                + esc(key)
                + '">'
                + '<option value="">Наследовать</option>';

            (def.options || []).forEach(function (option) {
                html += '<option value="'
                    + esc(option[0])
                    + '"'
                    + (
                        String(value) === String(option[0])
                            ? ' selected'
                            : ''
                    )
                    + '>'
                    + esc(option[1])
                    + '</option>';
            });

            html += '</select>';
        } else if (def.type === 'boolean') {
            html += '<select class="sb-select" data-stage3-responsive-key="'
                + esc(key)
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
                + ' data-stage3-responsive-key="'
                + esc(key)
                + '"'
                + ' min="' + Number(def.min) + '"'
                + ' max="' + Number(def.max) + '"'
                + ' step="' + Number(def.step || 1) + '"'
                + ' placeholder="Наследовать"'
                + ' value="'
                + (
                    value === undefined || value === null
                        ? ''
                        : esc(String(value))
                )
                + '">';
        }

        html += '</label>';

        return html;
    }

    function renderFields() {
        if (!ensurePanel() || !supported(draftType)) {
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

        if (def.type === 'boolean') {
            if (raw === 'true') {
                return true;
            }

            if (raw === 'false') {
                return false;
            }

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

        return clamp(
            raw,
            Number(def.min),
            Number(def.max)
        );
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
                '[data-stage3-responsive-key="'
                + def.key
                + '"]'
            );

            if (!input) {
                return;
            }

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

    function fillStage3(block) {
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

    function customResponsive(block, device) {
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

    function defaultPreviewConfig(block, device) {
        block = block || {};

        var type = String(block.type || '');
        var props = block.props || {};
        var content = block.content || {};
        var config = {};

        if (type === 'gallery') {
            var galleryColumns = clamp(
                props.columns == null ? 3 : props.columns,
                1,
                6
            ) || 3;

            config.columns = device === 'mobile'
                ? 1
                : (
                    device === 'tablet'
                        ? Math.min(2, galleryColumns)
                        : galleryColumns
                );
            config.gap = clamp(
                props.gap == null ? 16 : props.gap,
                0,
                64
            ) || 0;
            config.ratio = String(props.ratio || '4:3');
            config.radius = 12;
        } else if (type === 'pricing') {
            var pricingColumns = clamp(
                props.columns == null ? 2 : props.columns,
                1,
                4
            ) || 2;

            config.columns = device === 'mobile'
                ? 1
                : (
                    device === 'tablet'
                        ? Math.min(2, pricingColumns)
                        : pricingColumns
                );
            config.gap = 16;
            config.cardPadding = 18;
            config.titleSize = 24;
        } else if (type === 'form') {
            config.columns = device === 'mobile' ? 1 : 2;
            config.gap = 12;
            config.padding = 18;
            config.titleSize = 24;
            config.buttonFullWidth = false;
        } else if (type === 'video') {
            config.width = 100;
            config.ratio = String(props.ratio || '16:9');
            config.radius = 12;
            config.align = 'center';
        } else if (type === 'navigation') {
            config.layout = device === 'mobile' ? 'stack' : 'row';
            config.gap = 14;
        } else if (type === 'footer') {
            var footerCount = Math.max(
                1,
                Math.min(
                    6,
                    (
                        Array.isArray(content.columns)
                            ? content.columns.length
                            : 0
                    ) + 1
                )
            );

            config.columns = device === 'mobile'
                ? 1
                : (
                    device === 'tablet'
                        ? Math.min(2, footerCount)
                        : footerCount
                );
            config.gap = 16;
            config.padding = 18;
            config.align = 'left';
        } else if (type === 'faq') {
            config.titleSize = 24;
            config.questionSize = 14;
            config.itemGap = 8;
            config.align = 'left';
        }

        return config;
    }

    function previewConfig(block, device) {
        var result = defaultPreviewConfig(
            block,
            device
        );

        Object.assign(
            result,
            customResponsive(
                block,
                device
            )
        );

        return result;
    }

    function ratioCss(value, fallback) {
        var map = {
            'auto': 'auto',
            '16:9': '16 / 9',
            '4:3': '4 / 3',
            '1:1': '1 / 1',
            '3:4': '3 / 4',
            '9:16': '9 / 16'
        };

        return map[value] || map[fallback] || '16 / 9';
    }

    function deviceLabel(device) {
        if (device === 'mobile') {
            return 'Mobile';
        }

        if (device === 'tablet') {
            return 'Tablet';
        }

        return 'Desktop';
    }

    function wireHeader(title, device) {
        return ''
            + '<div class="sb-r3-wire__head">'
            + '<strong>' + esc(title) + '</strong>'
            + '<span>' + deviceLabel(device) + '</span>'
            + '</div>';
    }

    function faqWire(block, config, device) {
        var content = block.content || {};
        var items = Array.isArray(content.items)
            ? content.items.slice(0, 3)
            : [];

        return ''
            + '<div class="sb-r3-wire sb-r3-wire--faq"'
            + ' style="--r3-gap:'
            + Number(config.itemGap || 0)
            + 'px;--r3-title-size:'
            + Number(config.titleSize || 24)
            + 'px;--r3-question-size:'
            + Number(config.questionSize || 14)
            + 'px;text-align:'
            + esc(config.align || 'left')
            + '">'
            + wireHeader(content.title || 'FAQ', device)
            + '<div class="sb-r3-faq-items">'
            + (
                items.length
                    ? items.map(function (item) {
                        return ''
                            + '<div class="sb-r3-faq-item">'
                            + '<span>'
                            + esc(item.question || 'Вопрос')
                            + '</span><b>＋</b></div>';
                    }).join('')
                    : '<div class="sb-r3-faq-item"><span>Вопрос</span><b>＋</b></div>'
            )
            + '</div></div>';
    }

    function galleryWire(block, config, device) {
        var content = block.content || {};
        var count = Math.max(
            4,
            Math.min(
                8,
                Array.isArray(content.items)
                    ? content.items.length
                    : 0
            )
        );

        var tiles = '';

        for (var i = 0; i < count; i++) {
            tiles += '<i></i>';
        }

        return ''
            + '<div class="sb-r3-wire">'
            + wireHeader('Галерея', device)
            + '<div class="sb-r3-grid sb-r3-grid--gallery"'
            + ' style="--r3-cols:'
            + Number(config.columns || 1)
            + ';--r3-gap:'
            + Number(config.gap || 0)
            + 'px;--r3-ratio:'
            + ratioCss(config.ratio, '4:3')
            + ';--r3-radius:'
            + Number(config.radius || 0)
            + 'px">'
            + tiles
            + '</div></div>';
    }

    function pricingWire(block, config, device) {
        var content = block.content || {};
        var plans = Array.isArray(content.plans)
            ? content.plans.slice(0, 4)
            : [];

        if (!plans.length) {
            plans = [
                {name: 'Тариф', price: 'Цена'},
                {name: 'Тариф', price: 'Цена'}
            ];
        }

        return ''
            + '<div class="sb-r3-wire">'
            + wireHeader(content.title || 'Тарифы', device)
            + '<div class="sb-r3-grid sb-r3-grid--pricing"'
            + ' style="--r3-cols:'
            + Number(config.columns || 1)
            + ';--r3-gap:'
            + Number(config.gap || 0)
            + 'px;--r3-card-padding:'
            + Number(config.cardPadding || 18)
            + 'px;--r3-title-size:'
            + Number(config.titleSize || 24)
            + 'px">'
            + plans.map(function (plan) {
                return ''
                    + '<div class="sb-r3-price-card">'
                    + '<strong>' + esc(plan.name || 'Тариф') + '</strong>'
                    + '<b>' + esc(plan.price || 'Цена') + '</b>'
                    + '<i></i><i></i><button type="button">Кнопка</button>'
                    + '</div>';
            }).join('')
            + '</div></div>';
    }

    function formWire(block, config, device) {
        var content = block.content || {};
        var fields = Array.isArray(content.fields)
            ? content.fields.slice(0, 6)
            : [];

        if (!fields.length) {
            fields = [{}, {}, {}];
        }

        return ''
            + '<div class="sb-r3-wire sb-r3-form-wire"'
            + ' style="--r3-form-padding:'
            + Number(config.padding || 0)
            + 'px;--r3-title-size:'
            + Number(config.titleSize || 24)
            + 'px">'
            + wireHeader(content.title || 'Форма', device)
            + '<div class="sb-r3-grid sb-r3-grid--form"'
            + ' style="--r3-cols:'
            + Number(config.columns || 1)
            + ';--r3-gap:'
            + Number(config.gap || 0)
            + 'px">'
            + fields.map(function () {
                return '<i></i>';
            }).join('')
            + '</div>'
            + '<button class="sb-r3-form-button'
            + (config.buttonFullWidth ? ' is-full' : '')
            + '" type="button">'
            + esc(content.submitLabel || 'Отправить')
            + '</button></div>';
    }

    function videoWire(block, config, device) {
        var content = block.content || {};
        var align = String(config.align || 'center');
        var margin = align === 'center'
            ? 'auto'
            : (
                align === 'right'
                    ? 'auto 0 auto auto'
                    : '0 auto 0 0'
            );

        return ''
            + '<div class="sb-r3-wire">'
            + wireHeader(content.title || 'Видео', device)
            + '<div class="sb-r3-video"'
            + ' style="width:'
            + Number(config.width || 100)
            + '%;aspect-ratio:'
            + ratioCss(config.ratio, '16:9')
            + ';border-radius:'
            + Number(config.radius || 0)
            + 'px;margin:'
            + margin
            + '"><span>▶</span></div></div>';
    }

    function navigationWire(block, config, device) {
        var content = block.content || {};
        var links = Array.isArray(content.links)
            ? content.links.slice(0, 4)
            : [];

        return ''
            + '<div class="sb-r3-wire">'
            + wireHeader('Навигация', device)
            + '<div class="sb-r3-nav'
            + (config.layout === 'stack' ? ' is-stack' : '')
            + '" style="gap:'
            + Number(config.gap || 0)
            + 'px">'
            + '<strong>' + esc(content.brand || 'Бренд') + '</strong>'
            + '<div>'
            + (
                links.length
                    ? links.map(function (item) {
                        return '<span>'
                            + esc(item.label || 'Ссылка')
                            + '</span>';
                    }).join('')
                    : '<span>Ссылка</span><span>Ссылка</span>'
            )
            + '</div>'
            + (
                content.ctaLabel
                    ? '<button type="button">'
                        + esc(content.ctaLabel)
                        + '</button>'
                    : ''
            )
            + '</div></div>';
    }

    function footerWire(block, config, device) {
        var content = block.content || {};
        var columns = Array.isArray(content.columns)
            ? content.columns.slice(0, 5)
            : [];

        var cells = ''
            + '<div class="sb-r3-footer-cell is-about">'
            + '<strong>' + esc(content.brand || 'Бренд') + '</strong>'
            + '<i></i><i></i></div>';

        columns.forEach(function (column) {
            cells += ''
                + '<div class="sb-r3-footer-cell">'
                + '<strong>' + esc(column.title || 'Раздел') + '</strong>'
                + '<i></i><i></i></div>';
        });

        return ''
            + '<div class="sb-r3-wire">'
            + wireHeader('Подвал', device)
            + '<div class="sb-r3-grid sb-r3-grid--footer"'
            + ' style="--r3-cols:'
            + Number(config.columns || 1)
            + ';--r3-gap:'
            + Number(config.gap || 0)
            + 'px;--r3-footer-padding:'
            + Number(config.padding || 0)
            + 'px;text-align:'
            + esc(config.align || 'left')
            + '">'
            + cells
            + '</div></div>';
    }

    function businessWire(block, device) {
        var type = String(block.type || '');
        var config = previewConfig(block, device);

        if (type === 'faq') {
            return faqWire(block, config, device);
        }

        if (type === 'gallery') {
            return galleryWire(block, config, device);
        }

        if (type === 'pricing') {
            return pricingWire(block, config, device);
        }

        if (type === 'form') {
            return formWire(block, config, device);
        }

        if (type === 'video') {
            return videoWire(block, config, device);
        }

        if (type === 'navigation') {
            return navigationWire(block, config, device);
        }

        if (type === 'footer') {
            return footerWire(block, config, device);
        }

        return '';
    }

    function refreshBusinessPreviews() {
        var device = String(state.previewDevice || 'desktop');

        (state.blocks || []).forEach(function (block) {
            var type = String(block.type || '');

            if (!supported(type)) {
                return;
            }

            var card = document.querySelector(
                '.sb-editor-block[data-block-id="'
                + Number(block.id || 0)
                + '"]'
            );

            if (!card) {
                return;
            }

            var host = card.querySelector(
                '.sb-editor-block-preview'
            );

            if (!host) {
                return;
            }

            host.innerHTML = businessWire(
                block,
                device
            );

            if (device !== 'desktop') {
                var config = customResponsive(
                    block,
                    device
                );

                if (config.marginTop !== undefined) {
                    card.style.marginTop =
                        Number(config.marginTop) + 'px';
                }

                if (config.marginBottom !== undefined) {
                    card.style.marginBottom =
                        Number(config.marginBottom) + 'px';
                }
            }
        });
    }

    function schedulePreview() {
        clearTimeout(previewTimer);

        previewTimer = window.setTimeout(
            function () {
                if (typeof window.renderBlocks === 'function') {
                    window.renderBlocks();
                } else {
                    refreshBusinessPreviews();
                }
            },
            40
        );
    }

    var originalFillBlockForm = window.fillBlockForm;

    if (typeof originalFillBlockForm === 'function') {
        window.fillBlockForm = function (block) {
            var result = originalFillBlockForm.apply(
                this,
                arguments
            );

            fillStage3(
                block || currentBlock()
            );

            return result;
        };
    }

    var originalCollect = window.collectVisualBlockData;

    if (typeof originalCollect === 'function') {
        window.collectVisualBlockData = function (block) {
            var result = originalCollect.apply(
                this,
                arguments
            );

            block = block || currentBlock() || {};

            if (
                !result
                || !result.props
                || !supported(String(block.type || ''))
                || Number(block.id || 0) !== draftBlockId
            ) {
                return result;
            }

            var responsive = responsiveForSave();

            if (Object.keys(responsive).length) {
                result.props._responsive = responsive;
            } else {
                delete result.props._responsive;
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

            /*
             * 41-business-blocks.js обновляет свои старые preview
             * через setTimeout(0), поэтому ставим наш wire-preview
             * следом в ту же очередь.
             */
            window.setTimeout(
                refreshBusinessPreviews,
                0
            );

            return result;
        };
    }

    if (ensurePanel()) {
        panel.addEventListener('input', function (event) {
            if (
                !supported(draftType)
                || !event.target.closest(
                    '[data-stage3-responsive-key]'
                )
            ) {
                return;
            }

            syncDraftFromFields();
            schedulePreview();
        });

        panel.addEventListener('change', function (event) {
            if (
                !supported(draftType)
                || !event.target.closest(
                    '[data-stage3-responsive-key]'
                )
            ) {
                return;
            }

            syncDraftFromFields();
            schedulePreview();
        });

        panel.addEventListener('click', function (event) {
            var button = event.target.closest(
                '[data-responsive-device]'
            );

            if (!button || !supported(draftType)) {
                return;
            }

            syncDraftFromFields();

            currentDevice = String(
                button.getAttribute(
                    'data-responsive-device'
                ) || 'tablet'
            );

            /*
             * Stage 1 также слушает эти кнопки.
             * Рендерим наши поля последними.
             */
            window.setTimeout(
                function () {
                    renderFields();
                    schedulePreview();
                },
                0
            );
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest(
            '[data-preview-device]'
        );

        if (!button) {
            return;
        }

        var device = String(
            button.getAttribute(
                'data-preview-device'
            ) || ''
        );

        if (device === 'tablet' || device === 'mobile') {
            currentDevice = device;
        }

        window.setTimeout(
            function () {
                if (supported(draftType)) {
                    renderFields();
                }

                refreshBusinessPreviews();
            },
            0
        );
    });

    var initial = currentBlock();

    if (initial) {
        fillStage3(initial);
    }

    window.setTimeout(
        refreshBusinessPreviews,
        0
    );
})();
