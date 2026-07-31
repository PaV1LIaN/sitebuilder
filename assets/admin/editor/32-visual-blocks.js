/* =========================================================
   VISUAL BLOCKS / STAGE 16
   Visual block previews, extended component forms and repeaters.
   Loaded after 30-blocks.js and before 60-events.js.
   ========================================================= */
(function () {
    'use strict';

    var originalHideAllBlockTypeForms = window.hideAllBlockTypeForms;
    var originalFillVisualBlockForm = window.fillVisualBlockForm;
    var originalCollectVisualBlockData = window.collectVisualBlockData;
    var originalCreateBlock = window.createBlock;
    var originalSaveBlock = window.saveBlock;
    var originalDeleteBlock = window.deleteBlock;

    var cardsDraft = [];
    var statsDraft = [];
    var visualDrafts = {};
    var previewTimer = null;

    var VISUAL_TYPES = [
        'heading', 'text', 'button', 'image', 'hero', 'cards',
        'quote', 'stats', 'divider', 'spacer'
    ];

    function vbNumber(value, min, max, fallback) {
        value = Number(value);
        if (!isFinite(value)) value = fallback;
        return Math.max(min, Math.min(max, value));
    }

    function vbString(value, fallback) {
        value = value == null ? '' : String(value);
        return value !== '' ? value : (fallback || '');
    }

    function vbChoice(value, allowed, fallback) {
        value = String(value == null ? '' : value);
        return allowed.indexOf(value) !== -1 ? value : fallback;
    }

    function vbColor(value, fallback) {
        value = String(value || '').trim();
        return /^#[0-9a-f]{6}$/i.test(value) ? value : (fallback || '');
    }

    function vbSafeUrl(value, imageOnly) {
        value = String(value || '').trim();

        if (
            !value
            || /[\x00-\x20\x7f"'\\()<>]/.test(value)
            || value.indexOf('//') === 0
        ) {
            return '';
        }

        if (value.charAt(0) === '/' && value.charAt(1) !== '/') return value;
        if (/^https?:\/\//i.test(value)) return value;
        if (!imageOnly && /^(mailto:|tel:|#|\?)/i.test(value)) return value;
        return '';
    }

    function vbInput(id) {
        return document.getElementById(id);
    }

    function vbSetValue(id, value) {
        var node = vbInput(id);
        if (node) node.value = value == null ? '' : String(value);
    }

    function vbSetChecked(id, value) {
        var node = vbInput(id);
        if (node) node.checked = !!value;
    }

    function vbValue(id, fallback) {
        var node = vbInput(id);
        return node ? String(node.value || '') : String(fallback == null ? '' : fallback);
    }

    function vbChecked(id) {
        var node = vbInput(id);
        return !!(node && node.checked);
    }

    function vbPlacementProps(block, changes) {
        var oldProps = Object.assign({}, (block && block.props) || {});
        return Object.assign(oldProps, changes || {}, {
            sectionId: oldProps.sectionId == null ? null : oldProps.sectionId,
            column: oldProps.column == null ? null : oldProps.column,
            _placement: oldProps._placement || null
        });
    }

    function vbBlockMeta(type) {
        var map = {
            heading: ['H', 'Заголовок'],
            text: ['¶', 'Текст'],
            button: ['↗', 'Кнопка'],
            image: ['▧', 'Изображение'],
            hero: ['★', 'Первый экран'],
            cards: ['▦', 'Карточки'],
            quote: ['“', 'Цитата'],
            stats: ['№', 'Показатели'],
            divider: ['—', 'Разделитель'],
            spacer: ['↕', 'Отступ'],
            table: ['▤', 'Таблица'],
            disk: ['◫', 'Диск'],
            html: ['</>', 'HTML'],
            global: ['∞', 'Глобальный блок'],
            faq: ['?', 'Частые вопросы'],
            video: ['▶', 'Видео'],
            pricing: ['₽', 'Тарифы'],
            form: ['✉', 'Форма'],
            gallery: ['▦', 'Галерея'],
            navigation: ['☰', 'Навигация'],
            footer: ['▰', 'Подвал']
        };

        return map[type] || ['◇', type || 'Блок'];
    }

    function vbSanitizePreviewHtml(html) {
        html = String(html || '');
        var template = document.createElement('template');
        template.innerHTML = html;
        var allowed = {
            P: true, DIV: true, BR: true, H2: true, H3: true, H4: true, H5: true, H6: true, B: true, STRONG: true,
            I: true, EM: true, U: true, S: true, UL: true, OL: true,
            LI: true, A: true, BLOCKQUOTE: true, CODE: true, PRE: true, SPAN: true
        };

        Array.prototype.slice.call(template.content.querySelectorAll('*')).forEach(function (node) {
            if (!allowed[node.tagName]) {
                var fragment = document.createDocumentFragment();
                while (node.firstChild) fragment.appendChild(node.firstChild);
                node.replaceWith(fragment);
                return;
            }

            Array.prototype.slice.call(node.attributes || []).forEach(function (attr) {
                var name = String(attr.name || '').toLowerCase();
                if (node.tagName === 'A' && (name === 'href' || name === 'target' || name === 'rel')) return;
                node.removeAttribute(attr.name);
            });

            if (node.tagName === 'A') {
                var href = vbSafeUrl(node.getAttribute('href'), false);
                if (href) node.setAttribute('href', href); else node.removeAttribute('href');
                node.setAttribute('target', '_blank');
                node.setAttribute('rel', 'noopener noreferrer');
            }
        });

        return template.innerHTML;
    }

    function vbTextPreview(value, limit) {
        var node = document.createElement('div');
        node.innerHTML = vbSanitizePreviewHtml(value);
        var text = String(node.textContent || '').trim();
        if (limit && text.length > limit) text = text.slice(0, limit) + '…';
        return text;
    }

    function vbDefaultBlock(type) {
        switch (type) {
            case 'heading':
                return {
                    content: {text: 'Новый заголовок'},
                    props: {level: 'h2', align: 'left', color: '#111827', size: 0, maxWidth: 0}
                };
            case 'text':
                return {
                    content: {text: '<p>Новый текстовый блок</p>'},
                    props: {align: 'left', size: 16, color: '#374151', lineHeight: 1.65, maxWidth: 0}
                };
            case 'button':
                return {
                    content: {label: 'Подробнее', href: '#', target: '_self'},
                    props: {style: 'primary', size: 'medium', align: 'left', fullWidth: false}
                };
            case 'image':
                return {
                    content: {src: '', alt: '', caption: '', href: ''},
                    props: {ratio: '16:9', fit: 'cover', align: 'center', width: 100, radius: 18, shadow: false}
                };
            case 'hero':
                return {
                    content: {
                        eyebrow: 'Новый раздел',
                        title: 'Сильный заголовок для первого экрана',
                        text: 'Коротко объясните ценность страницы и предложите посетителю следующий шаг.',
                        primaryLabel: 'Подробнее',
                        primaryHref: '#',
                        secondaryLabel: '',
                        secondaryHref: '',
                        imageSrc: '',
                        imageAlt: ''
                    },
                    props: {theme: 'soft', align: 'left', imagePosition: 'right', minHeight: 380, radius: 28}
                };
            case 'cards':
                return {
                    content: {
                        title: 'Преимущества',
                        items: [
                            {title: 'Карточка 1', text: 'Краткое описание преимущества или услуги.', imageSrc: '', href: '', buttonText: ''},
                            {title: 'Карточка 2', text: 'Краткое описание преимущества или услуги.', imageSrc: '', href: '', buttonText: ''},
                            {title: 'Карточка 3', text: 'Краткое описание преимущества или услуги.', imageSrc: '', href: '', buttonText: ''}
                        ]
                    },
                    props: {columns: 3, style: 'elevated', imageRatio: '16:9', align: 'left'}
                };
            case 'quote':
                return {
                    content: {text: 'Здесь можно разместить важную цитату, отзыв или обращение.', author: 'Имя автора', role: 'Должность'},
                    props: {style: 'accent', align: 'left', accentColor: '#2563eb'}
                };
            case 'stats':
                return {
                    content: {
                        title: 'Ключевые показатели',
                        items: [
                            {value: '24/7', label: 'Доступность сервиса'},
                            {value: '99%', label: 'Точность данных'},
                            {value: '10+', label: 'Готовых сценариев'}
                        ]
                    },
                    props: {columns: 3, style: 'cards'}
                };
            case 'divider':
                return {
                    content: {label: ''},
                    props: {style: 'solid', color: '#cbd5e1', thickness: 1, width: 100, margin: 24}
                };
            case 'spacer':
                return {
                    content: {height: 40, tabletHeight: 32, mobileHeight: 24},
                    props: {}
                };
            default:
                return {content: {}, props: {}};
        }
    }

    function vbNormalizeCards(items) {
        items = Array.isArray(items) ? items : [];
        return items.slice(0, 24).map(function (item) {
            item = item && typeof item === 'object' ? item : {};
            return {
                title: vbString(item.title, ''),
                text: vbString(item.text, ''),
                imageSrc: vbString(item.imageSrc, ''),
                href: vbString(item.href, ''),
                buttonText: vbString(item.buttonText, '')
            };
        });
    }

    function vbNormalizeStats(items) {
        items = Array.isArray(items) ? items : [];
        return items.slice(0, 16).map(function (item) {
            item = item && typeof item === 'object' ? item : {};
            return {
                value: vbString(item.value, ''),
                label: vbString(item.label, '')
            };
        });
    }

    function vbRenderCardsEditor() {
        var host = vbInput('cardsItemsEditor');
        if (!host) return;

        if (!cardsDraft.length) {
            host.innerHTML = '<div class="sb-empty">Карточек пока нет</div>';
            return;
        }

        host.innerHTML = cardsDraft.map(function (item, index) {
            return ''
                + '<div class="sb-repeater-item" data-cards-index="' + index + '">'
                + '  <div class="sb-repeater-item__head"><span class="sb-repeater-item__title">Карточка ' + (index + 1) + '</span>'
                + '    <div class="sb-repeater-item__actions">'
                + '      <button type="button" data-cards-action="up" data-index="' + index + '" title="Выше">↑</button>'
                + '      <button type="button" data-cards-action="down" data-index="' + index + '" title="Ниже">↓</button>'
                + '      <button type="button" data-cards-action="delete" data-index="' + index + '" data-action="delete" title="Удалить">×</button>'
                + '    </div></div>'
                + '  <div class="sb-form-grid sb-form-grid--2">'
                + '    <div class="sb-field"><label>Заголовок</label><input class="sb-input" type="text" data-card-field="title" value="' + escapeHtml(item.title) + '"></div>'
                + '    <div class="sb-field"><label>Изображение</label><input class="sb-input" type="text" data-card-field="imageSrc" value="' + escapeHtml(item.imageSrc) + '"></div>'
                + '  </div>'
                + '  <div class="sb-field"><label>Описание</label><textarea class="sb-textarea sb-textarea--compact" data-card-field="text">' + escapeHtml(item.text) + '</textarea></div>'
                + '  <div class="sb-form-grid sb-form-grid--2">'
                + '    <div class="sb-field"><label>Ссылка</label><input class="sb-input" type="text" data-card-field="href" value="' + escapeHtml(item.href) + '"></div>'
                + '    <div class="sb-field"><label>Текст ссылки</label><input class="sb-input" type="text" data-card-field="buttonText" value="' + escapeHtml(item.buttonText) + '"></div>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    function vbCollectCardsEditor() {
        var host = vbInput('cardsItemsEditor');
        if (!host) return cardsDraft;

        return Array.prototype.slice.call(host.querySelectorAll('[data-cards-index]')).map(function (row) {
            function value(name) {
                var node = row.querySelector('[data-card-field="' + name + '"]');
                return node ? String(node.value || '').trim() : '';
            }

            return {
                title: value('title'),
                text: value('text'),
                imageSrc: value('imageSrc'),
                href: value('href'),
                buttonText: value('buttonText')
            };
        }).slice(0, 24);
    }

    function vbRenderStatsEditor() {
        var host = vbInput('statsItemsEditor');
        if (!host) return;

        if (!statsDraft.length) {
            host.innerHTML = '<div class="sb-empty">Показателей пока нет</div>';
            return;
        }

        host.innerHTML = statsDraft.map(function (item, index) {
            return ''
                + '<div class="sb-repeater-item" data-stats-index="' + index + '">'
                + '  <div class="sb-repeater-item__head"><span class="sb-repeater-item__title">Показатель ' + (index + 1) + '</span>'
                + '    <div class="sb-repeater-item__actions">'
                + '      <button type="button" data-stats-action="up" data-index="' + index + '" title="Выше">↑</button>'
                + '      <button type="button" data-stats-action="down" data-index="' + index + '" title="Ниже">↓</button>'
                + '      <button type="button" data-stats-action="delete" data-index="' + index + '" data-action="delete" title="Удалить">×</button>'
                + '    </div></div>'
                + '  <div class="sb-form-grid sb-form-grid--2">'
                + '    <div class="sb-field"><label>Значение</label><input class="sb-input" type="text" data-stat-field="value" value="' + escapeHtml(item.value) + '"></div>'
                + '    <div class="sb-field"><label>Подпись</label><input class="sb-input" type="text" data-stat-field="label" value="' + escapeHtml(item.label) + '"></div>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    function vbCollectStatsEditor() {
        var host = vbInput('statsItemsEditor');
        if (!host) return statsDraft;

        return Array.prototype.slice.call(host.querySelectorAll('[data-stats-index]')).map(function (row) {
            function value(name) {
                var node = row.querySelector('[data-stat-field="' + name + '"]');
                return node ? String(node.value || '').trim() : '';
            }

            return {value: value('value'), label: value('label')};
        }).slice(0, 16);
    }

    function vbMoveItem(items, index, direction) {
        index = Number(index);
        var target = direction === 'up' ? index - 1 : index + 1;
        if (index < 0 || index >= items.length || target < 0 || target >= items.length) return items;
        var copy = items.slice();
        var temp = copy[index];
        copy[index] = copy[target];
        copy[target] = temp;
        return copy;
    }

    function vbInstallTextToolbar() {
        var textarea = vbInput('textTextInput');
        if (!textarea || document.getElementById('textVisualToolbar')) return;

        var toolbar = document.createElement('div');
        toolbar.id = 'textVisualToolbar';
        toolbar.className = 'sb-rich-toolbar';
        toolbar.innerHTML = ''
            + '<button type="button" data-vb-text-command="strong" title="Жирный"><strong>B</strong></button>'
            + '<button type="button" data-vb-text-command="em" title="Курсив"><em>I</em></button>'
            + '<button type="button" data-vb-text-command="u" title="Подчёркнутый"><u>U</u></button>'
            + '<button type="button" data-vb-text-command="ul" title="Маркированный список">•≡</button>'
            + '<button type="button" data-vb-text-command="ol" title="Нумерованный список">1.</button>'
            + '<button type="button" data-vb-text-command="link" title="Ссылка">↗</button>'
            + '<button type="button" data-vb-text-command="p" title="Абзац">¶</button>';
        textarea.parentNode.insertBefore(toolbar, textarea);
    }

    function vbWrapTextSelection(command) {
        var textarea = vbInput('textTextInput');
        if (!textarea) return;

        var start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : 0;
        var end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : start;
        var selected = textarea.value.slice(start, end);
        var before = textarea.value.slice(0, start);
        var after = textarea.value.slice(end);
        var replacement = selected;

        if (command === 'strong' || command === 'em' || command === 'u' || command === 'p') {
            replacement = '<' + command + '>' + (selected || 'Текст') + '</' + command + '>';
        } else if (command === 'ul' || command === 'ol') {
            var lines = (selected || 'Элемент списка').split(/\r?\n/).filter(Boolean);
            replacement = '<' + command + '>' + lines.map(function (line) {
                return '<li>' + line + '</li>';
            }).join('') + '</' + command + '>';
        } else if (command === 'link') {
            var href = window.prompt('Адрес ссылки', 'https://');
            if (href === null) return;
            href = vbSafeUrl(href, false);
            if (!href) {
                alert('Разрешены ссылки http(s), mailto, tel, # и локальные пути');
                return;
            }
            replacement = '<a href="' + href.replace(/"/g, '') + '">' + (selected || 'Ссылка') + '</a>';
        }

        textarea.value = before + replacement + after;
        textarea.focus();
        textarea.selectionStart = start;
        textarea.selectionEnd = start + replacement.length;
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
    }

    function vbFillHeading(content, props) {
        vbSetValue('headingTextInput', content.text || '');
        vbSetValue('headingLevelInput', props.level || content.level || 'h2');
        vbSetValue('headingAlignInput', props.align || content.align || 'left');
        vbSetValue('headingColorInput', vbColor(props.color || content.color, '#111827'));
        vbSetValue('headingSizeInput', props.size || content.size || 0);
        vbSetValue('headingMaxWidthInput', props.maxWidth || content.maxWidth || 0);
    }

    function vbFillText(content, props) {
        vbSetValue('textTextInput', content.text || content.html || '');
        vbSetValue('textAlignInput', props.align || content.align || 'left');
        vbSetValue('textSizeInput', props.size || content.size || 16);
        vbSetValue('textColorInput', vbColor(props.color || content.color, '#374151'));
        vbSetValue('textLineHeightInput', props.lineHeight || content.lineHeight || 1.65);
        vbSetValue('textMaxWidthInput', props.maxWidth || content.maxWidth || 0);
    }


    function vbSyncHeroColorControls() {
        var enabled = vbChecked('heroUseCustomColorsInput');
        ['heroBackgroundColorInput', 'heroTextColorInput'].forEach(function (id) {
            var node = vbInput(id);
            if (node) node.disabled = !enabled;
        });
    }

    function vbFillButton(content, props) {
        vbSetValue('buttonLabelInput', content.label || content.text || 'Кнопка');
        vbSetValue('buttonHrefInput', content.href || '#');
        vbSetValue('buttonTargetInput', content.target || '_self');
        vbSetValue('buttonStyleInput', props.style || content.style || 'primary');
        vbSetValue('buttonSizeInput', props.size || 'medium');
        vbSetValue('buttonAlignInput', props.align || content.align || 'left');
        vbSetChecked('buttonFullWidthInput', props.fullWidth);
    }

    window.hideAllBlockTypeForms = function () {
        if (typeof originalHideAllBlockTypeForms === 'function') originalHideAllBlockTypeForms();
        ['imageBlockForm', 'heroBlockForm', 'cardsBlockForm', 'quoteBlockForm', 'statsBlockForm', 'dividerBlockForm', 'spacerBlockForm']
            .forEach(function (id) {
                var node = vbInput(id);
                if (node) {
                    node.classList.remove('is-active');
                    node.classList.add('sb-hidden');
                }
            });
    };

    window.fillVisualBlockForm = function (block) {
        block = block || {};
        var type = String(block.type || '');
        var content = block.content || {};
        var props = block.props || {};

        window.hideAllBlockTypeForms();

        if (type === 'heading') {
            showBlockTypeForm('headingBlockForm');
            vbFillHeading(content, props);
            return;
        }

        if (type === 'text') {
            showBlockTypeForm('textBlockForm');
            vbFillText(content, props);
            return;
        }

        if (type === 'button') {
            showBlockTypeForm('buttonBlockForm');
            vbFillButton(content, props);
            return;
        }

        if (type === 'image') {
            showBlockTypeForm('imageBlockForm');
            vbSetValue('imageSrcInput', content.src || '');
            vbSetValue('imageAltInput', content.alt || '');
            vbSetValue('imageHrefInput', content.href || '');
            vbSetValue('imageCaptionInput', content.caption || '');
            vbSetValue('imageRatioInput', props.ratio || '16:9');
            vbSetValue('imageFitInput', props.fit || 'cover');
            vbSetValue('imageAlignInput', props.align || 'center');
            vbSetValue('imageWidthInput', props.width || 100);
            vbSetValue('imageRadiusInput', props.radius == null ? 18 : props.radius);
            vbSetChecked('imageShadowInput', props.shadow);
            return;
        }

        if (type === 'hero') {
            showBlockTypeForm('heroBlockForm');
            vbSetValue('heroEyebrowInput', content.eyebrow || '');
            vbSetValue('heroTitleInput', content.title || '');
            vbSetValue('heroTextInput', content.text || '');
            vbSetValue('heroPrimaryLabelInput', content.primaryLabel || '');
            vbSetValue('heroPrimaryHrefInput', content.primaryHref || '');
            vbSetValue('heroSecondaryLabelInput', content.secondaryLabel || '');
            vbSetValue('heroSecondaryHrefInput', content.secondaryHref || '');
            vbSetValue('heroImageSrcInput', content.imageSrc || '');
            vbSetValue('heroImageAltInput', content.imageAlt || '');
            vbSetValue('heroThemeInput', props.theme || 'soft');
            vbSetValue('heroAlignInput', props.align || 'left');
            vbSetValue('heroImagePositionInput', props.imagePosition || 'right');
            vbSetValue('heroMinHeightInput', props.minHeight || 380);
            vbSetValue('heroRadiusInput', props.radius == null ? 28 : props.radius);
            vbSetValue('heroBackgroundColorInput', vbColor(props.backgroundColor, '#eff6ff'));
            vbSetValue('heroTextColorInput', vbColor(props.textColor, '#0f172a'));
            vbSetChecked('heroUseCustomColorsInput', !!(props.backgroundColor || props.textColor));
            vbSyncHeroColorControls();
            return;
        }

        if (type === 'cards') {
            showBlockTypeForm('cardsBlockForm');
            vbSetValue('cardsTitleInput', content.title || '');
            vbSetValue('cardsColumnsInput', props.columns || 3);
            vbSetValue('cardsStyleInput', props.style || 'elevated');
            vbSetValue('cardsImageRatioInput', props.imageRatio || '16:9');
            vbSetValue('cardsAlignInput', props.align || 'left');
            cardsDraft = vbNormalizeCards(content.items);
            vbRenderCardsEditor();
            return;
        }

        if (type === 'quote') {
            showBlockTypeForm('quoteBlockForm');
            vbSetValue('quoteTextInput', content.text || '');
            vbSetValue('quoteAuthorInput', content.author || '');
            vbSetValue('quoteRoleInput', content.role || '');
            vbSetValue('quoteStyleInput', props.style || 'accent');
            vbSetValue('quoteAlignInput', props.align || 'left');
            vbSetValue('quoteAccentColorInput', vbColor(props.accentColor, '#2563eb'));
            return;
        }

        if (type === 'stats') {
            showBlockTypeForm('statsBlockForm');
            vbSetValue('statsTitleInput', content.title || '');
            vbSetValue('statsColumnsInput', props.columns || 3);
            vbSetValue('statsStyleInput', props.style || 'cards');
            statsDraft = vbNormalizeStats(content.items);
            vbRenderStatsEditor();
            return;
        }

        if (type === 'divider') {
            showBlockTypeForm('dividerBlockForm');
            vbSetValue('dividerLabelInput', content.label || '');
            vbSetValue('dividerStyleInput', props.style || 'solid');
            vbSetValue('dividerColorInput', vbColor(props.color, '#cbd5e1'));
            vbSetValue('dividerThicknessInput', props.thickness || 1);
            vbSetValue('dividerWidthInput', props.width || 100);
            vbSetValue('dividerMarginInput', props.margin == null ? 24 : props.margin);
            return;
        }

        if (type === 'spacer') {
            showBlockTypeForm('spacerBlockForm');
            vbSetValue('spacerHeightInput', content.height == null ? 40 : content.height);
            vbSetValue('spacerTabletHeightInput', content.tabletHeight == null ? Math.min(32, Number(content.height || 40)) : content.tabletHeight);
            vbSetValue('spacerMobileHeightInput', content.mobileHeight == null ? Math.min(24, Number(content.height || 40)) : content.mobileHeight);
            if (typeof updateSpacerOutput === 'function') updateSpacerOutput();
            return;
        }

        if (typeof originalFillVisualBlockForm === 'function') {
            originalFillVisualBlockForm(block);
        }
    };

    window.collectVisualBlockData = function (block) {
        block = block || {};
        var type = String(block.type || '');

        if (type === 'heading') {
            return {
                content: {text: vbValue('headingTextInput').trim()},
                props: vbPlacementProps(block, {
                    level: vbChoice(vbValue('headingLevelInput'), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'h2'),
                    align: vbChoice(vbValue('headingAlignInput'), ['left', 'center', 'right'], 'left'),
                    color: vbColor(vbValue('headingColorInput'), '#111827'),
                    size: vbNumber(vbValue('headingSizeInput'), 0, 120, 0),
                    maxWidth: vbNumber(vbValue('headingMaxWidthInput'), 0, 1800, 0)
                })
            };
        }

        if (type === 'text') {
            return {
                content: {text: vbValue('textTextInput')},
                props: vbPlacementProps(block, {
                    align: vbChoice(vbValue('textAlignInput'), ['left', 'center', 'right', 'justify'], 'left'),
                    size: vbNumber(vbValue('textSizeInput'), 12, 72, 16),
                    color: vbColor(vbValue('textColorInput'), '#374151'),
                    lineHeight: vbNumber(vbValue('textLineHeightInput'), 1, 2.4, 1.65),
                    maxWidth: vbNumber(vbValue('textMaxWidthInput'), 0, 1800, 0)
                })
            };
        }

        if (type === 'button') {
            return {
                content: {
                    label: vbValue('buttonLabelInput').trim() || 'Кнопка',
                    href: vbValue('buttonHrefInput').trim() || '#',
                    target: vbChoice(vbValue('buttonTargetInput'), ['_self', '_blank'], '_self')
                },
                props: vbPlacementProps(block, {
                    style: vbChoice(vbValue('buttonStyleInput'), ['primary', 'secondary', 'outline', 'ghost'], 'primary'),
                    size: vbChoice(vbValue('buttonSizeInput'), ['small', 'medium', 'large'], 'medium'),
                    align: vbChoice(vbValue('buttonAlignInput'), ['left', 'center', 'right'], 'left'),
                    fullWidth: vbChecked('buttonFullWidthInput')
                })
            };
        }

        if (type === 'image') {
            return {
                content: {
                    src: vbValue('imageSrcInput').trim(),
                    alt: vbValue('imageAltInput').trim(),
                    href: vbValue('imageHrefInput').trim(),
                    caption: vbValue('imageCaptionInput').trim()
                },
                props: vbPlacementProps(block, {
                    ratio: vbChoice(vbValue('imageRatioInput'), ['auto', '16:9', '4:3', '3:2', '1:1'], '16:9'),
                    fit: vbChoice(vbValue('imageFitInput'), ['cover', 'contain', 'fill', 'none'], 'cover'),
                    align: vbChoice(vbValue('imageAlignInput'), ['left', 'center', 'right'], 'center'),
                    width: vbNumber(vbValue('imageWidthInput'), 10, 100, 100),
                    radius: vbNumber(vbValue('imageRadiusInput'), 0, 80, 18),
                    shadow: vbChecked('imageShadowInput')
                })
            };
        }

        if (type === 'hero') {
            return {
                content: {
                    eyebrow: vbValue('heroEyebrowInput').trim(),
                    title: vbValue('heroTitleInput').trim(),
                    text: vbValue('heroTextInput'),
                    primaryLabel: vbValue('heroPrimaryLabelInput').trim(),
                    primaryHref: vbValue('heroPrimaryHrefInput').trim(),
                    secondaryLabel: vbValue('heroSecondaryLabelInput').trim(),
                    secondaryHref: vbValue('heroSecondaryHrefInput').trim(),
                    imageSrc: vbValue('heroImageSrcInput').trim(),
                    imageAlt: vbValue('heroImageAltInput').trim()
                },
                props: vbPlacementProps(block, {
                    theme: vbChoice(vbValue('heroThemeInput'), ['light', 'soft', 'accent', 'dark'], 'soft'),
                    align: vbChoice(vbValue('heroAlignInput'), ['left', 'center'], 'left'),
                    imagePosition: vbChoice(vbValue('heroImagePositionInput'), ['right', 'left', 'background', 'none'], 'right'),
                    minHeight: vbNumber(vbValue('heroMinHeightInput'), 220, 900, 380),
                    radius: vbNumber(vbValue('heroRadiusInput'), 0, 80, 28),
                    backgroundColor: vbChecked('heroUseCustomColorsInput') ? vbColor(vbValue('heroBackgroundColorInput'), '') : '',
                    textColor: vbChecked('heroUseCustomColorsInput') ? vbColor(vbValue('heroTextColorInput'), '') : ''
                })
            };
        }

        if (type === 'cards') {
            cardsDraft = vbCollectCardsEditor();
            return {
                content: {title: vbValue('cardsTitleInput').trim(), items: cardsDraft},
                props: vbPlacementProps(block, {
                    columns: vbNumber(vbValue('cardsColumnsInput'), 1, 4, 3),
                    style: vbChoice(vbValue('cardsStyleInput'), ['elevated', 'outlined', 'soft', 'minimal'], 'elevated'),
                    imageRatio: vbChoice(vbValue('cardsImageRatioInput'), ['16:9', '4:3', '1:1', 'auto'], '16:9'),
                    align: vbChoice(vbValue('cardsAlignInput'), ['left', 'center'], 'left')
                })
            };
        }

        if (type === 'quote') {
            return {
                content: {
                    text: vbValue('quoteTextInput'),
                    author: vbValue('quoteAuthorInput').trim(),
                    role: vbValue('quoteRoleInput').trim()
                },
                props: vbPlacementProps(block, {
                    style: vbChoice(vbValue('quoteStyleInput'), ['accent', 'soft', 'minimal', 'dark'], 'accent'),
                    align: vbChoice(vbValue('quoteAlignInput'), ['left', 'center'], 'left'),
                    accentColor: vbColor(vbValue('quoteAccentColorInput'), '#2563eb')
                })
            };
        }

        if (type === 'stats') {
            statsDraft = vbCollectStatsEditor();
            return {
                content: {title: vbValue('statsTitleInput').trim(), items: statsDraft},
                props: vbPlacementProps(block, {
                    columns: vbNumber(vbValue('statsColumnsInput'), 1, 4, 3),
                    style: vbChoice(vbValue('statsStyleInput'), ['cards', 'line', 'plain', 'accent'], 'cards')
                })
            };
        }

        if (type === 'divider') {
            return {
                content: {label: vbValue('dividerLabelInput').trim()},
                props: vbPlacementProps(block, {
                    style: vbChoice(vbValue('dividerStyleInput'), ['solid', 'dashed', 'gradient', 'dots'], 'solid'),
                    color: vbColor(vbValue('dividerColorInput'), '#cbd5e1'),
                    thickness: vbNumber(vbValue('dividerThicknessInput'), 1, 8, 1),
                    width: vbNumber(vbValue('dividerWidthInput'), 10, 100, 100),
                    margin: vbNumber(vbValue('dividerMarginInput'), 0, 160, 24)
                })
            };
        }

        if (type === 'spacer') {
            return {
                content: {
                    height: vbNumber(vbValue('spacerHeightInput'), 0, 400, 40),
                    tabletHeight: vbNumber(vbValue('spacerTabletHeightInput'), 0, 400, 32),
                    mobileHeight: vbNumber(vbValue('spacerMobileHeightInput'), 0, 400, 24)
                },
                props: vbPlacementProps(block, {})
            };
        }

        return originalCollectVisualBlockData(block);
    };

    function vbDraftBlock(block) {
        var draft = visualDrafts[Number(block.id || 0)];
        if (!draft) return block;
        return Object.assign({}, block, {content: draft.content, props: draft.props});
    }

    function vbInlineAttrs(block, field, rich) {
        var id = Number((block && block.id) || 0);
        if (id <= 0) return '';

        return ' contenteditable="true" spellcheck="true" draggable="false"'
            + ' data-inline-block-id="' + id + '"'
            + ' data-inline-field="' + escapeHtml(field) + '"'
            + (rich ? ' data-inline-rich="true"' : ' data-inline-rich="false"')
            + ' role="textbox" tabindex="0"';
    }

    function vbHeadingHtml(block) {
        var c = block.content || {};
        var p = block.props || {};
        var size = vbNumber(p.size, 0, 120, 0);
        var defaultSize = {h1: 42, h2: 32, h3: 26, h4: 22, h5: 18, h6: 16}[p.level || c.level || 'h2'] || 32;
        var maxWidth = vbNumber(p.maxWidth, 0, 1800, 0);
        var style = '--vb-align:' + vbChoice(p.align || c.align, ['left', 'center', 'right'], 'left')
            + ';--vb-color:' + vbColor(p.color || c.color, 'inherit')
            + ';--vb-size:' + (size || defaultSize) + 'px;'
            + (maxWidth ? 'max-width:' + maxWidth + 'px;' : '');
        if ((p.align || c.align) === 'center') style += 'margin-left:auto;margin-right:auto;';
        return '<h2 class="sb-vb-heading" style="' + escapeHtml(style) + '"' + vbInlineAttrs(block, 'heading.text', false) + '>' + escapeHtml(c.text || 'Пустой заголовок') + '</h2>';
    }

    function vbTextHtml(block) {
        var c = block.content || {};
        var p = block.props || {};
        var maxWidth = vbNumber(p.maxWidth, 0, 1800, 0);
        var align = vbChoice(p.align || c.align, ['left', 'center', 'right', 'justify'], 'left');
        var style = '--vb-align:' + align
            + ';--vb-color:' + vbColor(p.color || c.color, 'inherit')
            + ';--vb-size:' + vbNumber(p.size || c.size, 12, 72, 16) + 'px'
            + ';--vb-line-height:' + vbNumber(p.lineHeight || c.lineHeight, 1, 2.4, 1.65)
            + ';--vb-max-width:' + (maxWidth ? maxWidth + 'px' : 'none') + ';';
        if (align === 'center') style += 'margin-left:auto;margin-right:auto;';
        return '<div class="sb-vb-text" style="' + escapeHtml(style) + '"' + vbInlineAttrs(block, 'text.html', true) + '>' + (vbSanitizePreviewHtml(c.text || c.html || '') || '<p>Пустой текст</p>') + '</div>';
    }

    function vbButtonHtml(block) {
        var c = block.content || {};
        var p = block.props || {};
        var style = vbChoice(p.style, ['primary', 'secondary', 'outline', 'ghost'], 'primary');
        var size = vbChoice(p.size, ['small', 'medium', 'large'], 'medium');
        var classes = ['sb-vb-button', 'is-' + style, 'is-' + size];
        if (p.fullWidth) classes.push('is-full');
        return '<div class="sb-vb-button-wrap" style="--vb-align:' + escapeHtml(vbChoice(p.align, ['left', 'center', 'right'], 'left')) + '">'
            + '<span class="' + classes.join(' ') + '"' + vbInlineAttrs(block, 'button.label', false) + '>' + escapeHtml(c.label || c.text || 'Кнопка') + '</span></div>';
    }

    function vbImageHtml(block) {
        var c = block.content || {};
        var p = block.props || {};
        var src = vbSafeUrl(c.src, true);
        var ratioMap = {'16:9': '16 / 9', '4:3': '4 / 3', '3:2': '3 / 2', '1:1': '1 / 1', auto: 'auto'};
        var classes = ['sb-vb-image', 'is-' + vbChoice(p.align, ['left', 'center', 'right'], 'center')];
        if (p.shadow) classes.push('is-shadow');
        var style = '--vb-image-width:' + vbNumber(p.width, 10, 100, 100) + '%;'
            + '--vb-image-radius:' + vbNumber(p.radius, 0, 80, 18) + 'px;'
            + '--vb-image-fit:' + vbChoice(p.fit, ['cover', 'contain', 'fill', 'none'], 'cover') + ';'
            + '--vb-image-ratio:' + (ratioMap[p.ratio] || '16 / 9') + ';';
        return '<figure class="' + classes.join(' ') + '" style="' + escapeHtml(style) + '">'
            + (src ? '<img src="' + escapeHtml(src) + '" alt="">' : '<div class="sb-vb-image-placeholder">Добавьте URL изображения</div>')
            + (c.caption ? '<figcaption class="sb-vb-image-caption">' + escapeHtml(c.caption) + '</figcaption>' : '')
            + '</figure>';
    }

    function vbHeroHtml(block) {
        var c = block.content || {};
        var p = block.props || {};
        var image = vbSafeUrl(c.imageSrc, true);
        var position = vbChoice(p.imagePosition, ['right', 'left', 'background', 'none'], 'right');
        var classes = ['sb-vb-hero', 'is-' + vbChoice(p.theme, ['light', 'soft', 'accent', 'dark'], 'soft')];
        if (p.align === 'center') classes.push('is-center');
        if (position === 'left') classes.push('is-image-left');
        if (position === 'none' || (!image && position !== 'background')) classes.push('is-no-image');
        var style = '--vb-hero-height:' + vbNumber(p.minHeight, 220, 900, 380) + 'px;--vb-hero-radius:' + vbNumber(p.radius, 0, 80, 28) + 'px;';
        var customBg = vbColor(p.backgroundColor, '');
        var customText = vbColor(p.textColor, '');
        if (customBg) style += 'background:' + customBg + ';';
        if (customText) style += 'color:' + customText + ';';
        if (position === 'background' && image) {
            style += 'background-image:linear-gradient(rgba(15,23,42,.62),rgba(15,23,42,.62)),url(&quot;' + escapeHtml(image) + '&quot;);background-position:center;background-size:cover;color:#fff;';
            classes.push('is-no-image');
        }
        return '<section class="' + classes.join(' ') + '" style="' + style + '">'
            + '<div class="sb-vb-hero__content">'
            + (c.eyebrow ? '<div class="sb-vb-hero__eyebrow"' + vbInlineAttrs(block, 'hero.eyebrow', false) + '>' + escapeHtml(c.eyebrow) + '</div>' : '<div class="sb-vb-hero__eyebrow is-inline-placeholder"' + vbInlineAttrs(block, 'hero.eyebrow', false) + '>Надзаголовок</div>')
            + '<h2 class="sb-vb-hero__title"' + vbInlineAttrs(block, 'hero.title', false) + '>' + escapeHtml(c.title || 'Первый экран') + '</h2>'
            + '<div class="sb-vb-hero__text' + (c.text ? '' : ' is-inline-placeholder') + '"' + vbInlineAttrs(block, 'hero.text', false) + '>' + escapeHtml(c.text || 'Добавьте короткое описание') + '</div>'
            + ((c.primaryLabel || c.secondaryLabel) ? '<div class="sb-vb-hero__actions">'
                + (c.primaryLabel ? '<span class="sb-vb-button">' + escapeHtml(c.primaryLabel) + '</span>' : '')
                + (c.secondaryLabel ? '<span class="sb-vb-button is-outline">' + escapeHtml(c.secondaryLabel) + '</span>' : '')
                + '</div>' : '')
            + '</div>'
            + ((image && (position === 'left' || position === 'right')) ? '<div class="sb-vb-hero__media"><img src="' + escapeHtml(image) + '" alt=""></div>' : '')
            + '</section>';
    }

    function vbCardsHtml(block) {
        var c = block.content || {};
        var p = block.props || {};
        var items = vbNormalizeCards(c.items).slice(0, 6);
        var ratio = {'16:9': '16 / 9', '4:3': '4 / 3', '1:1': '1 / 1', auto: 'auto'}[p.imageRatio] || '16 / 9';
        var align = vbChoice(p.align, ['left', 'center'], 'left');
        return '<div style="text-align:' + align + '">'
            + (c.title ? '<h3 class="sb-vb-heading" style="--vb-size:24px;margin-bottom:12px;">' + escapeHtml(c.title) + '</h3>' : '')
            + '<div class="sb-vb-cards-grid" style="--vb-columns:' + vbNumber(p.columns, 1, 4, 3) + ';--vb-card-ratio:' + ratio + '">'
            + (items.length ? items.map(function (item) {
                var image = vbSafeUrl(item.imageSrc, true);
                return '<article class="sb-vb-card">'
                    + (image ? '<div class="sb-vb-card__image" style="background-image:url(&quot;' + escapeHtml(image) + '&quot;)"></div>' : '')
                    + '<div class="sb-vb-card__body"><h4 class="sb-vb-card__title">' + escapeHtml(item.title || 'Карточка') + '</h4>'
                    + (item.text ? '<div class="sb-vb-card__text">' + escapeHtml(item.text) + '</div>' : '') + '</div></article>';
            }).join('') : '<div class="sb-vb-code">Добавьте карточки в свойствах блока</div>')
            + '</div></div>';
    }

    function vbQuoteHtml(block) {
        var c = block.content || {};
        var p = block.props || {};
        var classes = ['sb-vb-quote', 'is-' + vbChoice(p.style, ['accent', 'soft', 'minimal', 'dark'], 'accent')];
        return '<figure class="' + classes.join(' ') + '" style="--vb-align:' + escapeHtml(vbChoice(p.align, ['left', 'center'], 'left')) + ';--vb-quote-accent:' + escapeHtml(vbColor(p.accentColor, '#2563eb')) + '">'
            + '<blockquote class="sb-vb-quote__text"' + vbInlineAttrs(block, 'quote.text', false) + '>' + escapeHtml(c.text || 'Цитата') + '</blockquote>'
            + '<figcaption class="sb-vb-quote__author' + ((c.author || c.role) ? '' : ' is-inline-placeholder') + '"' + vbInlineAttrs(block, 'quote.author', false) + '>' + escapeHtml([c.author, c.role].filter(Boolean).join(' · ') || 'Автор · должность') + '</figcaption>'
            + '</figure>';
    }

    function vbStatsHtml(block) {
        var c = block.content || {};
        var p = block.props || {};
        var items = vbNormalizeStats(c.items).slice(0, 8);
        return (c.title ? '<h3 class="sb-vb-heading" style="--vb-size:24px;margin-bottom:12px;">' + escapeHtml(c.title) + '</h3>' : '')
            + '<div class="sb-vb-stats-grid" style="--vb-columns:' + vbNumber(p.columns, 1, 4, 3) + '">'
            + (items.length ? items.map(function (item) {
                return '<div class="sb-vb-stat"><div class="sb-vb-stat__value">' + escapeHtml(item.value) + '</div><div class="sb-vb-stat__label">' + escapeHtml(item.label) + '</div></div>';
            }).join('') : '<div class="sb-vb-code">Добавьте показатели в свойствах блока</div>')
            + '</div>';
    }

    function vbDividerHtml(block) {
        var c = block.content || {};
        var p = block.props || {};
        var classes = ['sb-vb-divider', 'is-' + vbChoice(p.style, ['solid', 'dashed', 'gradient', 'dots'], 'solid')];
        var style = '--vb-divider-width:' + vbNumber(p.width, 10, 100, 100) + '%;'
            + '--vb-divider-thickness:' + vbNumber(p.thickness, 1, 8, 1) + 'px;'
            + '--vb-divider-margin:' + vbNumber(p.margin, 0, 160, 24) + 'px;'
            + '--vb-divider-color:' + vbColor(p.color, '#cbd5e1') + ';';
        return '<div class="' + classes.join(' ') + '" style="' + escapeHtml(style) + '"><span class="sb-vb-divider__line"></span>'
            + (c.label ? '<span class="sb-vb-divider__label">' + escapeHtml(c.label) + '</span><span class="sb-vb-divider__line"></span>' : '')
            + '</div>';
    }

    function vbSpacerHtml(block) {
        var c = block.content || {};
        var desktop = vbNumber(c.height, 0, 400, 40);
        var tablet = vbNumber(c.tabletHeight, 0, 400, Math.min(desktop, 32));
        var mobile = vbNumber(c.mobileHeight, 0, 400, Math.min(tablet, 24));
        return '<div class="sb-vb-spacer" style="--vb-spacer-desktop:' + desktop + 'px;--vb-spacer-tablet:' + tablet + 'px;--vb-spacer-mobile:' + mobile + 'px">Адаптивный отступ</div>';
    }

    function vbBlockPreviewHtml(block) {
        var type = String(block.type || '');
        if (type === 'heading') return vbHeadingHtml(block);
        if (type === 'text') return vbTextHtml(block);
        if (type === 'button') return vbButtonHtml(block);
        if (type === 'image') return vbImageHtml(block);
        if (type === 'hero') return vbHeroHtml(block);
        if (type === 'cards') return vbCardsHtml(block);
        if (type === 'quote') return vbQuoteHtml(block);
        if (type === 'stats') return vbStatsHtml(block);
        if (type === 'divider') return vbDividerHtml(block);
        if (type === 'spacer') return vbSpacerHtml(block);
        if (type === 'table') {
            var table = block.content || {};
            return '<div class="sb-vb-table"><strong>' + escapeHtml(table.title || 'Таблица') + '</strong><br>Столбцов: ' + (Array.isArray(table.columns) ? table.columns.length : 0) + ' · строк: ' + (Array.isArray(table.rows) ? table.rows.length : 0) + '</div>';
        }
        if (type === 'disk') {
            return '<div class="sb-vb-disk"><strong>Битрикс.Диск</strong><br>' + escapeHtml((block.props && block.props.title) || 'Файлы') + '</div>';
        }
        if (type === 'html') {
            return '<div class="sb-vb-code"><strong>HTML</strong><br>' + escapeHtml(vbTextPreview((block.content || {}).html, 180) || 'Пустой HTML-блок') + '</div>';
        }
        if (type === 'global') {
            var globalId = Number((block.content || {}).globalBlockId || 0);
            var record = (state.globalBlocks || []).find(function (item) {
                return Number(item.id || 0) === globalId;
            });
            if (!record || !record.block || String(record.block.type || '') === 'global') {
                return '<div class="sb-global-block-reference"><div class="sb-global-block-reference__head"><span>Глобальный блок</span><span>#' + globalId + '</span></div><div class="sb-vb-code">Связанный блок не найден</div></div>';
            }
            return '<div class="sb-global-block-reference"><div class="sb-global-block-reference__head"><span>' + escapeHtml(record.name || 'Глобальный блок') + '</span><span>связан</span></div>' + vbBlockPreviewHtml(record.block) + '</div>';
        }
        return '<div class="sb-vb-code">' + escapeHtml(blockPreviewText(block)) + '</div>';
    }

    function vbBlockCard(block) {
        block = vbDraftBlock(block);
        var id = Number(block.id || 0);
        var active = id === Number(state.currentBlockId || 0) ? ' is-active' : '';
        var meta = vbBlockMeta(String(block.type || ''));
        return ''
            + '<div class="sb-editor-block' + active + '" draggable="false" data-block-id="' + id + '" data-block-type="' + escapeHtml(String(block.type || '')) + '">'
            + '  <div class="sb-editor-block-head">'
            + '    <span class="sb-editor-block-label"><span>' + escapeHtml(meta[0]) + '</span>' + escapeHtml(meta[1]) + '</span>'
            + '    <span class="sb-editor-block-actions">'
            + '      <span class="sb-editor-block-drag" draggable="true" data-block-drag-handle="' + id + '" title="Перетащить блок" aria-label="Перетащить блок">⋮⋮</span>'
            + '      <button type="button" data-vb-action="up" data-block-id="' + id + '" title="Выше" aria-label="Переместить выше">↑</button>'
            + '      <button type="button" data-vb-action="down" data-block-id="' + id + '" title="Ниже" aria-label="Переместить ниже">↓</button>'
            + '      <button type="button" data-vb-action="duplicate" data-block-id="' + id + '" title="Дублировать" aria-label="Дублировать">⧉</button>'
            + '      <button type="button" data-vb-action="delete" data-block-id="' + id + '" title="Удалить" aria-label="Удалить">×</button>'
            + '    </span>'
            + '  </div>'
            + '  <div class="sb-editor-block-preview">' + vbBlockPreviewHtml(block) + '</div>'
            + '</div>';
    }

    function vbSectionStyle(section) {
        var layout = section.layout || {};
        var props = section.props || {};
        var bg = vbColor(props.backgroundColor, '#ffffff');
        var color = vbColor(props.textColor, '#1f2937');
        var image = vbSafeUrl(props.backgroundImage, true);
        var radius = vbNumber(props.borderRadius, 0, 80, 0);
        var shadow = props.shadow ? '0 18px 46px rgba(15,23,42,.12)' : 'none';
        return '--sb-preview-section-bg:' + bg + ';'
            + '--sb-preview-section-color:' + color + ';'
            + '--sb-preview-section-shadow:' + shadow + ';'
            + '--sb-preview-section-pt:' + vbNumber(props.paddingTop, 0, 240, 32) + 'px;'
            + '--sb-preview-section-pb:' + vbNumber(props.paddingBottom, 0, 240, 32) + 'px;'
            + '--sb-preview-section-px:' + vbNumber(props.paddingX, 0, 160, 20) + 'px;'
            + '--sb-preview-section-min-height:' + vbNumber(props.minHeight, 0, 1200, 0) + 'px;'
            + '--sb-preview-section-image:' + (image ? 'url(&quot;' + escapeHtml(image) + '&quot;)' : 'none') + ';'
            + '--sb-preview-section-image-position:' + vbChoice(props.backgroundPosition, ['center', 'top', 'bottom', 'left', 'right'], 'center') + ';'
            + '--sb-preview-section-image-size:' + vbChoice(props.backgroundSize, ['cover', 'contain', 'auto'], 'cover') + ';'
            + 'border-radius:' + radius + 'px;'
            + '--sb-preview-columns:' + vbNumber(layout.columns, 1, 4, 1) + ';'
            + '--sb-preview-tablet-columns:' + vbNumber(layout.tabletColumns, 1, 4, Math.min(2, Number(layout.columns || 1))) + ';'
            + '--sb-preview-mobile-columns:' + vbNumber(layout.mobileColumns, 1, 2, 1) + ';'
            + '--sb-preview-gap:' + vbNumber(layout.gap, 0, 120, 24) + 'px;'
            + '--sb-preview-align:' + vbChoice(layout.verticalAlign, ['start', 'center', 'end', 'stretch'], 'start') + ';';
    }

    window.renderBlocks = function () {
        if (!blocksList) return;

        if (!state.currentPageId) {
            blocksList.innerHTML = '<div class="sb-editor-empty-big"><strong>Страница не выбрана</strong>Выберите страницу слева, чтобы редактировать её содержимое</div>';
            return;
        }

        if (!state.pageSections.length) {
            blocksList.innerHTML = state.blocks.length
                ? state.blocks.map(vbBlockCard).join('')
                : '<div class="sb-editor-empty-big"><strong>На странице пока нет блоков</strong>Добавьте первый блок через панель сверху</div>';
            return;
        }

        var grouped = groupBlocksBySectionAndColumn();
        blocksList.innerHTML = state.pageSections.map(function (section) {
            var sectionId = Number(section.id || 0);
            var layout = section.layout || {};
            var columns = vbNumber(layout.columns, 1, 4, 1);
            var active = sectionId === Number(state.currentSectionId || 0) ? ' is-active' : '';
            var html = ''
                + '<section class="sb-editor-section-preview' + active + '" data-editor-section-id="' + sectionId + '" style="' + vbSectionStyle(section) + '">'
                + '  <div class="sb-editor-section-preview__head" data-page-section-select="' + sectionId + '">'
                + '    <div><h3 class="sb-editor-section-preview__title">' + escapeHtml(section.title || 'Секция') + '</h3>'
                + '      <div class="sb-editor-section-preview__meta"><span>' + columns + ' кол.</span><span>' + escapeHtml(layout.container || 'default') + '</span></div></div>'
                + '    <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-add-block-to-section="' + sectionId + '">Выбрать секцию</button>'
                + '  </div>'
                + '  <div class="sb-editor-section-preview__body">'
                + '  <div class="sb-editor-section-preview__grid">';

            for (var column = 1; column <= columns; column++) {
                var blocks = grouped[sectionId] && grouped[sectionId][column] ? grouped[sectionId][column] : [];
                var target = sectionId === Number(state.currentSectionId || 0) && column === Number(state.currentColumn || 1);
                html += '<div class="sb-editor-section-preview__column' + (target ? ' is-target' : '') + '" data-section-id="' + sectionId + '" data-column="' + column + '">'
                    + '<div class="sb-editor-section-preview__column-head"><span class="sb-editor-section-preview__column-title">Колонка ' + column + '</span>'
                    + '<button class="sb-btn sb-btn-light sb-btn-small" type="button" data-set-add-target="' + sectionId + '" data-column="' + column + '">' + (target ? 'Выбрано' : 'Добавлять сюда') + '</button></div>'
                    + (blocks.length ? blocks.map(vbBlockCard).join('') : '<div class="sb-editor-section-preview__empty">Перетащите блок или выберите «Добавлять сюда»</div>')
                    + '</div>';
            }

            html += '</div></div></section>';
            return html;
        }).join('');
    };

    window.createBlock = async function (type) {
        type = String(type || '');
        if (VISUAL_TYPES.indexOf(type) === -1) return originalCreateBlock(type);
        if (!state.currentPageId) {
            alert('Сначала выберите страницу');
            return;
        }

        var defaults = vbDefaultBlock(type);
        var targetSectionId = getDefaultSectionId();
        var targetColumn = getDefaultColumn();
        var props = Object.assign({}, defaults.props, {
            sectionId: targetSectionId,
            column: targetColumn,
            _placement: {sectionId: targetSectionId, column: targetColumn}
        });

        var response = await api('block.create', {
            pageId: state.currentPageId,
            type: type,
            content: JSON.stringify(defaults.content),
            props: JSON.stringify(props),
            sectionId: targetSectionId,
            column: targetColumn
        });

        await loadBlocks();
        var createdId = Number(
            (response.block && response.block.id)
            || (response.data && response.data.block && response.data.block.id)
            || 0
        );

        if (!createdId && state.blocks.length) {
            createdId = Number(state.blocks.slice().sort(function (a, b) {
                return Number(b.id || 0) - Number(a.id || 0);
            })[0].id || 0);
        }

        if (createdId > 0) {
            state.currentBlockId = createdId;
            state.currentSectionId = targetSectionId;
            state.currentColumn = targetColumn;
            fillBlockForm();
            window.renderBlocks();
            if (typeof setInspectorTab === 'function') setInspectorTab('block');
        }

        return response;
    };

    window.saveBlock = async function () {
        var block = getCurrentBlock();
        if (!block) return null;

        var type = String(block.type || '');
        if (VISUAL_TYPES.indexOf(type) === -1) {
            return originalSaveBlock();
        }

        var id = Number(block.id || 0);
        var result = await originalSaveBlock();
        if (id > 0) delete visualDrafts[id];
        window.renderBlocks();
        if (typeof showEditorToast === 'function') showEditorToast('Блок сохранён', 'success');
        return result;
    };

    window.deleteBlock = async function () {
        var id = Number(state.currentBlockId || 0);
        var result = await originalDeleteBlock();
        if (id > 0) delete visualDrafts[id];
        return result;
    };

    function vbSchedulePreview() {
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(function () {
            var block = getCurrentBlock();
            if (!block || VISUAL_TYPES.indexOf(String(block.type || '')) === -1) return;
            var collected = window.collectVisualBlockData(block);
            if (!collected) return;
            visualDrafts[Number(block.id || 0)] = collected;
            window.renderBlocks();
        }, 130);
    }

    function vbHandleRepeaterAction(button) {
        var action = String(button.getAttribute('data-cards-action') || button.getAttribute('data-stats-action') || '');
        var index = Number(button.getAttribute('data-index') || 0);

        if (button.hasAttribute('data-cards-action')) {
            cardsDraft = vbCollectCardsEditor();
            if (action === 'add') cardsDraft.push({title: 'Новая карточка', text: '', imageSrc: '', href: '', buttonText: ''});
            if (action === 'delete') cardsDraft.splice(index, 1);
            if (action === 'up' || action === 'down') cardsDraft = vbMoveItem(cardsDraft, index, action);
            cardsDraft = cardsDraft.slice(0, 24);
            vbRenderCardsEditor();
            vbSchedulePreview();
            return true;
        }

        if (button.hasAttribute('data-stats-action')) {
            statsDraft = vbCollectStatsEditor();
            if (action === 'add') statsDraft.push({value: '100%', label: 'Новый показатель'});
            if (action === 'delete') statsDraft.splice(index, 1);
            if (action === 'up' || action === 'down') statsDraft = vbMoveItem(statsDraft, index, action);
            statsDraft = statsDraft.slice(0, 16);
            vbRenderStatsEditor();
            vbSchedulePreview();
            return true;
        }

        return false;
    }

    document.addEventListener('click', function (event) {
        var textButton = event.target.closest('[data-vb-text-command]');
        if (textButton) {
            event.preventDefault();
            vbWrapTextSelection(String(textButton.getAttribute('data-vb-text-command') || ''));
            return;
        }

        var repeaterButton = event.target.closest('[data-cards-action], [data-stats-action]');
        if (repeaterButton && vbHandleRepeaterAction(repeaterButton)) {
            event.preventDefault();
        }
    });

    if (blocksList) {
        blocksList.addEventListener('click', function (event) {
            var actionButton = event.target.closest('[data-vb-action][data-block-id]');
            if (!actionButton) return;

            event.preventDefault();
            event.stopImmediatePropagation();

            var id = Number(actionButton.getAttribute('data-block-id') || 0);
            var action = String(actionButton.getAttribute('data-vb-action') || '');
            if (id <= 0) return;
            state.currentBlockId = id;

            Promise.resolve().then(async function () {
                if (action === 'duplicate') await duplicateBlock();
                if (action === 'up') await moveBlock('up');
                if (action === 'down') await moveBlock('down');
                if (action === 'delete') await window.deleteBlock();
            }).catch(function (error) {
                console.error(error);
                if (typeof showEditorToast === 'function') showEditorToast('Не удалось выполнить действие с блоком', 'error');
            });
        }, true);

        blocksList.addEventListener('click', function (event) {
            if (event.target.closest('[data-block-id]') && typeof setInspectorTab === 'function') {
                window.setTimeout(function () { setInspectorTab('block'); }, 0);
            }
        });
    }

    var inspector = vbInput('blockInspector');
    if (inspector) {
        inspector.addEventListener('input', function (event) {
            if (event.target.closest('#blockJsonFields')) return;
            if (event.target.matches('[data-card-field], [data-stat-field]')) {
                cardsDraft = vbCollectCardsEditor();
                statsDraft = vbCollectStatsEditor();
            }
            vbSchedulePreview();
        });
        inspector.addEventListener('change', function (event) {
            if (event.target.closest('#blockJsonFields')) return;
            if (event.target.id === 'heroUseCustomColorsInput') vbSyncHeroColorControls();
            vbSchedulePreview();
        });
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-page-section-select], [data-add-block-to-section], [data-set-add-target]')) {
            if (typeof setInspectorTab === 'function') window.setTimeout(function () { setInspectorTab('section'); }, 0);
        }
    });

    window.SBVisualBuilder = {
        blockPreviewHtml: vbBlockPreviewHtml,
        blockCard: vbBlockCard,
        sectionStyle: vbSectionStyle,
        draftBlock: vbDraftBlock,
        sanitizePreviewHtml: vbSanitizePreviewHtml,
        setDraft: function (blockId, draft) {
            blockId = Number(blockId || 0);
            if (blockId > 0 && draft && typeof draft === 'object') {
                visualDrafts[blockId] = {
                    content: draft.content || {},
                    props: draft.props || {}
                };
            }
        },
        clearDraft: function (blockId) {
            delete visualDrafts[Number(blockId || 0)];
        },
        collectCurrent: function () {
            var block = getCurrentBlock();
            return block ? window.collectVisualBlockData(block) : null;
        }
    };

    vbInstallTextToolbar();
})();
