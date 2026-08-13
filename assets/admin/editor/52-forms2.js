/* =========================================================
   SITEBUILDER / FORMS 2.0 / STAGE 1
   Enhances the existing form field builder without replacing it.
   - Russian field type labels
   - number + radio
   - move / duplicate fields
   - context-aware options / placeholder
   - preserves options when an existing form is saved unchanged
   No autosave.
   ========================================================= */
(function () {
    'use strict';

    if (
        !window.SB_EDITOR_CONFIG
        || typeof state === 'undefined'
    ) {
        return;
    }

    var host =
        document.getElementById(
            'formFieldsEditor'
        );

    if (!host) {
        return;
    }

    var addButton =
        document.querySelector(
            '[data-biz-add="form"]'
        );

    var TYPE_OPTIONS = [
        ['text', 'Текст'],
        ['email', 'Email'],
        ['phone', 'Телефон'],
        ['number', 'Число'],
        ['textarea', 'Многострочный текст'],
        ['select', 'Выпадающий список'],
        ['radio', 'Радиокнопки'],
        ['checkbox', 'Чекбокс']
    ];

    var memory = {};
    var decorating = false;
    var originalCollect =
        window.collectVisualBlockData;

    function esc(value) {
        if (typeof escapeHtml === 'function') {
            return escapeHtml(value);
        }

        return String(value == null ? '' : value)
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

    function currentFormBlock() {
        if (
            typeof getCurrentBlock
            !== 'function'
        ) {
            return null;
        }

        var block = getCurrentBlock();

        return (
            block
            && String(block.type || '')
                === 'form'
        )
            ? block
            : null;
    }

    function fieldCards() {
        return Array.prototype.slice.call(
            host.querySelectorAll(
                ':scope > .sb-biz-item'
            )
        );
    }

    function fieldControl(index, field) {
        return host.querySelector(
            '[data-biz-list="form"]'
            + '[data-index="'
            + Number(index)
            + '"]'
            + '[data-field="'
            + field
            + '"]'
        );
    }

    function fieldIndex(card) {
        var control =
            card.querySelector(
                '[data-biz-list="form"]'
                + '[data-index]'
            );

        return control
            ? Number(
                control.getAttribute(
                    'data-index'
                ) || -1
            )
            : -1;
    }

    function supportedType(value) {
        value = String(value || '');

        return TYPE_OPTIONS.some(
            function (item) {
                return item[0] === value;
            }
        )
            ? value
            : 'text';
    }

    function typeLabel(value) {
        value = supportedType(value);

        var found = TYPE_OPTIONS.find(
            function (item) {
                return item[0] === value;
            }
        );

        return found
            ? found[1]
            : 'Текст';
    }

    function savedFieldForKey(
        key,
        index
    ) {
        var block =
            currentFormBlock();

        if (!block) {
            return null;
        }

        var content =
            block.content
            && typeof block.content
                === 'object'
                ? block.content
                : {};

        var fields =
            Array.isArray(content.fields)
                ? content.fields
                : [];

        if (key) {
            var byKey =
                fields.find(
                    function (field) {
                        return String(
                            field
                            && field.key
                            || ''
                        ) === key;
                    }
                );

            if (byKey) {
                return byKey;
            }
        }

        var indexed =
            fields[index] || null;

        if (
            indexed
            && (
                !key
                || String(
                    indexed.key || ''
                ) === key
            )
        ) {
            return indexed;
        }

        return null;
    }

    function ensureTypeOptions(
        select,
        desiredType
    ) {
        if (!select) {
            return;
        }

        desiredType =
            supportedType(
                desiredType
                || select.value
            );

        select.innerHTML =
            TYPE_OPTIONS.map(
                function (item) {
                    return ''
                        + '<option value="'
                        + esc(item[0])
                        + '">'
                        + esc(item[1])
                        + '</option>';
                }
            ).join('');

        select.value = desiredType;
    }

    function snapshot(index) {
        index = Number(index);

        var data = {};

        [
            'key',
            'label',
            'type',
            'width',
            'placeholder',
            'optionsText',
            'required'
        ].forEach(function (field) {
            var control =
                fieldControl(
                    index,
                    field
                );

            if (!control) {
                return;
            }

            data[field] =
                control.type
                    === 'checkbox'
                    ? !!control.checked
                    : String(
                        control.value == null
                            ? ''
                            : control.value
                    );
        });

        data.type =
            supportedType(
                data.type || 'text'
            );

        return data;
    }

    function rememberSnapshot(data) {
        if (
            !data
            || typeof data !== 'object'
        ) {
            return;
        }

        var key =
            String(
                data.key || ''
            ).trim();

        if (key) {
            memory[key] =
                Object.assign(
                    {},
                    data
                );
        }
    }

    function rememberAll() {
        fieldCards().forEach(
            function (card) {
                var index =
                    fieldIndex(card);

                if (index < 0) {
                    return;
                }

                rememberSnapshot(
                    snapshot(index)
                );
            }
        );
    }

    function dispatchControl(
        control,
        eventName
    ) {
        if (!control) return;

        control.dispatchEvent(
            new Event(
                eventName,
                {
                    bubbles: true
                }
            )
        );
    }

    function writeSnapshot(
        index,
        data
    ) {
        index = Number(index);

        if (
            !data
            || typeof data !== 'object'
        ) {
            return;
        }

        var typeControl =
            fieldControl(
                index,
                'type'
            );

        ensureTypeOptions(
            typeControl,
            data.type
        );

        [
            'key',
            'label',
            'width',
            'placeholder',
            'optionsText'
        ].forEach(function (field) {
            var control =
                fieldControl(
                    index,
                    field
                );

            if (!control) {
                return;
            }

            control.value =
                data[field] == null
                    ? ''
                    : String(
                        data[field]
                    );

            dispatchControl(
                control,
                'input'
            );
        });

        if (typeControl) {
            typeControl.value =
                supportedType(
                    data.type
                );

            dispatchControl(
                typeControl,
                'change'
            );
        }

        var required =
            fieldControl(
                index,
                'required'
            );

        if (required) {
            required.checked =
                !!data.required;

            dispatchControl(
                required,
                'change'
            );
        }

        rememberSnapshot(
            snapshot(index)
        );
    }

    function optionValues(text) {
        return String(text || '')
            .split(/\r?\n/)
            .map(function (value) {
                return value.trim();
            })
            .filter(Boolean)
            .slice(0, 50);
    }

    function decorateConditional(
        card,
        index,
        type
    ) {
        type =
            supportedType(type);

        var placeholder =
            fieldControl(
                index,
                'placeholder'
            );
        var options =
            fieldControl(
                index,
                'optionsText'
            );

        var usesOptions =
            type === 'select'
            || type === 'radio';

        var usesPlaceholder =
            [
                'text',
                'email',
                'phone',
                'number',
                'textarea'
            ].indexOf(type) !== -1;

        if (placeholder) {
            placeholder.hidden =
                !usesPlaceholder;

            placeholder.placeholder =
                type === 'number'
                    ? 'Подсказка, например 10'
                    : 'Подсказка внутри поля';
        }

        if (options) {
            options.hidden =
                !usesOptions;

            options.placeholder =
                usesOptions
                    ? 'Варианты ответа, по одному в строке'
                    : '';
        }

        card.classList.toggle(
            'is-options-field',
            usesOptions
        );

        card.classList.toggle(
            'is-check-field',
            type === 'checkbox'
        );

        var badge =
            card.querySelector(
                '.sb-form2-type-badge'
            );

        if (badge) {
            badge.textContent =
                typeLabel(type);
        }
    }

    function addFieldActions(
        card,
        index,
        total
    ) {
        var head =
            card.querySelector(
                '.sb-biz-item__head'
            );

        if (!head) {
            return;
        }

        var remove =
            head.querySelector(
                '[data-biz-remove="form"]'
            );

        if (!remove) {
            return;
        }

        remove.title =
            'Удалить поле';
        remove.setAttribute(
            'aria-label',
            'Удалить поле'
        );

        var actions =
            head.querySelector(
                '.sb-form2-field-actions'
            );

        if (!actions) {
            actions =
                document.createElement(
                    'span'
                );

            actions.className =
                'sb-form2-field-actions';

            head.appendChild(
                actions
            );
        }

        actions.innerHTML = ''
            + '<button type="button"'
            + ' data-form2-action="up"'
            + ' data-index="'
            + index
            + '"'
            + (index <= 0
                ? ' disabled'
                : '')
            + ' title="Переместить выше"'
            + ' aria-label="Переместить поле выше">↑</button>'
            + '<button type="button"'
            + ' data-form2-action="down"'
            + ' data-index="'
            + index
            + '"'
            + (index >= total - 1
                ? ' disabled'
                : '')
            + ' title="Переместить ниже"'
            + ' aria-label="Переместить поле ниже">↓</button>'
            + '<button type="button"'
            + ' data-form2-action="duplicate"'
            + ' data-index="'
            + index
            + '"'
            + ' title="Дублировать поле"'
            + ' aria-label="Дублировать поле">⧉</button>';

        actions.appendChild(
            remove
        );

        var title =
            head.querySelector(
                'strong'
            );

        if (
            title
            && !head.querySelector(
                '.sb-form2-type-badge'
            )
        ) {
            var badge =
                document.createElement(
                    'span'
                );

            badge.className =
                'sb-form2-type-badge';

            title.insertAdjacentElement(
                'afterend',
                badge
            );
        }
    }

    function decorateCard(
        card,
        index,
        total
    ) {
        if (
            !card
            || index < 0
        ) {
            return;
        }

        card.classList.add(
            'sb-form2-field-card'
        );

        var keyControl =
            fieldControl(
                index,
                'key'
            );
        var key =
            keyControl
                ? String(
                    keyControl.value
                    || ''
                ).trim()
                : '';

        var typeControl =
            fieldControl(
                index,
                'type'
            );

        var saved =
            savedFieldForKey(
                key,
                index
            );

        var desiredType =
            (
                key
                && memory[key]
                && memory[key].type
            )
            || (
                saved
                && saved.type
            )
            || (
                typeControl
                && typeControl.value
            )
            || 'text';

        ensureTypeOptions(
            typeControl,
            desiredType
        );

        addFieldActions(
            card,
            index,
            total
        );

        decorateConditional(
            card,
            index,
            typeControl
                ? typeControl.value
                : desiredType
        );
    }

    function decorateAll() {
        if (decorating) {
            return;
        }

        decorating = true;

        try {
            var cards =
                fieldCards();

            cards.forEach(
                function (card) {
                    var index =
                        fieldIndex(card);

                    decorateCard(
                        card,
                        index,
                        cards.length
                    );
                }
            );

            var form =
                document.getElementById(
                    'formBlockForm'
                );

            if (form) {
                form.classList.add(
                    'sb-form2-editor'
                );
            }

            var add =
                document.querySelector(
                    '[data-biz-add="form"]'
                );

            if (add) {
                add.textContent =
                    '+ Добавить поле';
            }
        } finally {
            decorating = false;
        }
    }

    function swapFields(
        firstIndex,
        secondIndex
    ) {
        var first =
            snapshot(firstIndex);
        var second =
            snapshot(secondIndex);

        if (
            !first
            || !second
        ) {
            return;
        }

        writeSnapshot(
            firstIndex,
            second
        );
        writeSnapshot(
            secondIndex,
            first
        );

        decorateAll();
    }

    function uniqueCopyKey(
        sourceKey
    ) {
        var normalized =
            String(
                sourceKey || 'field'
            )
            .toLowerCase()
            .replace(
                /[^a-z0-9_]+/g,
                '_'
            )
            .replace(
                /^_+|_+$/g,
                ''
            );

        if (!normalized) {
            normalized = 'field';
        }

        var used = {};

        fieldCards().forEach(
            function (card) {
                var index =
                    fieldIndex(card);
                var control =
                    fieldControl(
                        index,
                        'key'
                    );

                if (control) {
                    used[
                        String(
                            control.value
                            || ''
                        )
                    ] = true;
                }
            }
        );

        var candidate =
            normalized + '_copy';
        var counter = 2;

        while (used[candidate]) {
            candidate =
                normalized
                + '_copy_'
                + counter++;
        }

        return candidate;
    }

    function duplicateField(index) {
        if (!addButton) {
            addButton =
                document.querySelector(
                    '[data-biz-add="form"]'
                );
        }

        if (!addButton) {
            return;
        }

        rememberAll();

        var source =
            snapshot(index);

        if (!source) {
            return;
        }

        source.key =
            uniqueCopyKey(
                source.key
            );
        source.label =
            String(
                source.label
                || 'Поле'
            ) + ' — копия';

        addButton.click();

        window.setTimeout(
            function () {
                decorateAll();

                var cards =
                    fieldCards();
                var lastIndex =
                    cards.length - 1;

                if (lastIndex < 0) {
                    return;
                }

                writeSnapshot(
                    lastIndex,
                    source
                );

                while (
                    lastIndex
                    > index + 1
                ) {
                    swapFields(
                        lastIndex,
                        lastIndex - 1
                    );

                    lastIndex--;
                }

                decorateAll();
            },
            0
        );
    }

    function normalizeCollectedForm(
        result
    ) {
        if (
            !result
            || !result.content
        ) {
            return result;
        }

        var cards =
            fieldCards();

        if (!cards.length) {
            return result;
        }

        var fields =
            cards.map(
                function (card) {
                    var index =
                        fieldIndex(card);
                    var data =
                        snapshot(index);

                    var type =
                        supportedType(
                            data.type
                        );

                    return {
                        key:
                            String(
                                data.key || ''
                            ).trim(),
                        label:
                            String(
                                data.label || ''
                            ).trim(),
                        type: type,
                        required:
                            !!data.required,
                        placeholder:
                            [
                                'text',
                                'email',
                                'phone',
                                'number',
                                'textarea'
                            ].indexOf(type)
                                !== -1
                                ? String(
                                    data.placeholder
                                    || ''
                                ).trim()
                                : '',
                        options:
                            (
                                type === 'select'
                                || type === 'radio'
                            )
                                ? optionValues(
                                    data.optionsText
                                )
                                : [],
                        width:
                            data.width
                                === 'half'
                                ? 'half'
                                : 'full'
                    };
                }
            );

        result.content.fields =
            fields;

        return result;
    }

    if (
        typeof originalCollect
        === 'function'
    ) {
        window.collectVisualBlockData =
            function (block) {
                var result =
                    originalCollect.apply(
                        this,
                        arguments
                    );

                if (
                    block
                    && String(
                        block.type || ''
                    ) === 'form'
                ) {
                    return normalizeCollectedForm(
                        result
                    );
                }

                return result;
            };
    }

    /*
     * Capture lets us remember Number/Radio values before the old
     * renderer rebuilds the list after Add/Delete.
     */
    document.addEventListener(
        'click',
        function (event) {
            var action =
                event.target.closest(
                    '[data-form2-action]'
                );

            if (action) {
                event.preventDefault();
                event.stopImmediatePropagation();

                var index =
                    Number(
                        action.getAttribute(
                            'data-index'
                        ) || 0
                    );
                var kind =
                    String(
                        action.getAttribute(
                            'data-form2-action'
                        ) || ''
                    );

                if (
                    kind === 'up'
                    && index > 0
                ) {
                    rememberAll();
                    swapFields(
                        index,
                        index - 1
                    );
                } else if (
                    kind === 'down'
                    && index
                        < fieldCards().length - 1
                ) {
                    rememberAll();
                    swapFields(
                        index,
                        index + 1
                    );
                } else if (
                    kind === 'duplicate'
                ) {
                    duplicateField(index);
                }

                return;
            }

            var legacyMutation =
                event.target.closest(
                    '[data-biz-add="form"],'
                    + '[data-biz-remove="form"]'
                );

            if (legacyMutation) {
                rememberAll();

                window.setTimeout(
                    decorateAll,
                    0
                );
            }
        },
        true
    );

    function handleFieldChange(event) {
        var control =
            event.target.closest(
                '[data-biz-list="form"]'
                + '[data-index]'
            );

        if (!control) {
            return;
        }

        var index =
            Number(
                control.getAttribute(
                    'data-index'
                ) || 0
            );

        var data =
            snapshot(index);

        rememberSnapshot(data);

        if (
            control.getAttribute(
                'data-field'
            ) === 'type'
        ) {
            var card =
                control.closest(
                    '.sb-biz-item'
                );

            if (card) {
                decorateConditional(
                    card,
                    index,
                    control.value
                );
            }
        }
    }

    document.addEventListener(
        'input',
        handleFieldChange
    );
    document.addEventListener(
        'change',
        handleFieldChange
    );

    var observer =
        new MutationObserver(
            function () {
                decorateAll();
            }
        );

    observer.observe(
        host,
        {
            childList: true
        }
    );

    decorateAll();

    window.SBForms2 = {
        refresh: decorateAll
    };
})();
