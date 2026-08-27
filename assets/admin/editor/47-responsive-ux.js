/* =========================================================
   SITEBUILDER RESPONSIVE UX / FINAL POLISH
   Makes Desktop -> Tablet -> Mobile inheritance explicit.
   Adds source badges, per-device override counters and reset.
   No API changes. No autosave.
   ========================================================= */
(function () {
    'use strict';

    if (!window.SB_EDITOR_CONFIG || typeof state === 'undefined') {
        return;
    }

    var FIELD_SELECTOR = [
        '[data-responsive-key]',
        '[data-stage2-responsive-key]',
        '[data-stage3-responsive-key]'
    ].join(',');

    var panel = null;
    var fieldsHost = null;
    var chain = null;
    var toolbar = null;
    var resetButton = null;
    var currentBlockId = 0;
    var shadow = {};
    var decorateTimer = null;

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

    function hasOwn(object, key) {
        return !!object
            && Object.prototype.hasOwnProperty.call(object, key);
    }

    function hasValue(value) {
        return value !== undefined
            && value !== null
            && String(value) !== '';
    }

    function deviceName(device) {
        if (device === 'mobile') {
            return 'Mobile';
        }

        if (device === 'tablet') {
            return 'Tablet';
        }

        return 'Desktop';
    }

    function responsiveKey(input) {
        return String(
            input.getAttribute('data-responsive-key')
            || input.getAttribute('data-stage2-responsive-key')
            || input.getAttribute('data-stage3-responsive-key')
            || ''
        );
    }

    function directInputValue(input) {
        var value = String(
            input.value == null ? '' : input.value
        ).trim();

        if (value === '') {
            return undefined;
        }

        if (value === 'true') {
            return true;
        }

        if (value === 'false') {
            return false;
        }

        return value;
    }

    function activeDevice() {
        if (panel) {
            var active = panel.querySelector(
                '[data-responsive-device].is-active'
            );

            if (active) {
                var fromButton = String(
                    active.getAttribute('data-responsive-device') || ''
                );

                if (
                    fromButton === 'tablet'
                    || fromButton === 'mobile'
                ) {
                    return fromButton;
                }
            }
        }

        var preview = String(state.previewDevice || '');

        return preview === 'mobile' ? 'mobile' : 'tablet';
    }

    function normalizeShadowEntry(block) {
        block = block || {};

        var blockId = Number(block.id || 0);

        if (blockId <= 0) {
            return;
        }

        currentBlockId = blockId;

        if (!shadow[blockId]) {
            shadow[blockId] = clone(
                block.props && block.props._responsive
                    ? block.props._responsive
                    : {}
            );
        }

        shadow[blockId].tablet =
            shadow[blockId].tablet
            && typeof shadow[blockId].tablet === 'object'
                ? shadow[blockId].tablet
                : {};

        shadow[blockId].mobile =
            shadow[blockId].mobile
            && typeof shadow[blockId].mobile === 'object'
                ? shadow[blockId].mobile
                : {};
    }

    function reseedBlock(block) {
        block = block || {};

        var blockId = Number(block.id || 0);

        if (blockId <= 0) {
            currentBlockId = 0;
            return;
        }

        currentBlockId = blockId;
        shadow[blockId] = clone(
            block.props && block.props._responsive
                ? block.props._responsive
                : {}
        );

        normalizeShadowEntry(block);
    }

    function currentShadow() {
        var block = currentBlock();

        if (!block) {
            return null;
        }

        normalizeShadowEntry(block);

        return shadow[currentBlockId] || null;
    }

    function syncVisibleFieldsToShadow() {
        if (!fieldsHost || !currentBlockId) {
            return;
        }

        var responsive = currentShadow();

        if (!responsive) {
            return;
        }

        var device = activeDevice();

        responsive[device] =
            responsive[device]
            && typeof responsive[device] === 'object'
                ? responsive[device]
                : {};

        fieldsHost.querySelectorAll(FIELD_SELECTOR)
            .forEach(function (input) {
                var key = responsiveKey(input);

                if (!key) {
                    return;
                }

                var value = directInputValue(input);

                if (value === undefined) {
                    delete responsive[device][key];
                } else {
                    responsive[device][key] = value;
                }
            });
    }

    function ensureStructure() {
        panel = node('blockResponsiveOverridesPanel');
        fieldsHost = node('blockResponsiveFields');

        if (!panel || !fieldsHost) {
            return false;
        }

        var oldHint = node('blockResponsiveInheritanceHint');

        if (oldHint) {
            oldHint.hidden = true;
            oldHint.setAttribute('aria-hidden', 'true');
        }

        chain = node('blockResponsiveInheritanceChain');

        if (!chain) {
            chain = document.createElement('div');
            chain.id = 'blockResponsiveInheritanceChain';
            chain.className = 'sb-rux-chain';
            chain.setAttribute(
                'aria-label',
                'Наследование адаптивных настроек'
            );
            chain.innerHTML = ''
                + '<span data-rux-chain-device="desktop">'
                + '  <b>Desktop</b><small>база</small>'
                + '</span>'
                + '<i>→</i>'
                + '<span data-rux-chain-device="tablet">'
                + '  <b>Tablet</b><small>наследует Desktop</small>'
                + '</span>'
                + '<i>→</i>'
                + '<span data-rux-chain-device="mobile">'
                + '  <b>Mobile</b><small>наследует Tablet</small>'
                + '</span>';

            var devices = panel.querySelector(
                '.sb-responsive-overrides__devices'
            );

            if (devices && devices.parentNode === panel) {
                devices.insertAdjacentElement(
                    'afterend',
                    chain
                );
            } else {
                panel.insertBefore(
                    chain,
                    fieldsHost
                );
            }
        }

        toolbar = node('blockResponsiveUxToolbar');

        if (!toolbar) {
            toolbar = document.createElement('div');
            toolbar.id = 'blockResponsiveUxToolbar';
            toolbar.className = 'sb-rux-toolbar';
            toolbar.innerHTML = ''
                + '<div class="sb-rux-toolbar__copy">'
                + '  <strong id="blockResponsiveUxTitle">Tablet</strong>'
                + '  <span id="blockResponsiveUxDescription">'
                + 'Пустое поле использует Desktop.'
                + '  </span>'
                + '</div>'
                + '<button type="button"'
                + ' id="blockResponsiveResetDeviceBtn"'
                + ' class="sb-rux-reset">'
                + 'Сбросить Tablet'
                + '</button>';

            chain.insertAdjacentElement(
                'afterend',
                toolbar
            );
        }

        resetButton = node(
            'blockResponsiveResetDeviceBtn'
        );

        ensureDeviceCounters();

        return true;
    }

    function ensureDeviceCounters() {
        if (!panel) {
            return;
        }

        panel.querySelectorAll('[data-responsive-device]')
            .forEach(function (button) {
                if (
                    button.querySelector(
                        '.sb-rux-device-count'
                    )
                ) {
                    return;
                }

                var counter = document.createElement('span');
                counter.className = 'sb-rux-device-count';
                counter.textContent = '0';
                button.appendChild(counter);
            });
    }

    function sourceForField(key, directValue, device) {
        if (directValue !== undefined) {
            return {
                kind: 'override',
                text: 'Своё · ' + deviceName(device)
            };
        }

        var responsive = currentShadow();

        if (
            device === 'mobile'
            && responsive
            && responsive.tablet
            && hasOwn(responsive.tablet, key)
            && hasValue(responsive.tablet[key])
        ) {
            return {
                kind: 'tablet',
                text: 'Наследовано · Tablet'
            };
        }

        return {
            kind: 'desktop',
            text: 'Наследовано · Desktop'
        };
    }

    function decorateField(input) {
        var key = responsiveKey(input);

        if (!key) {
            return;
        }

        var field = input.closest(
            '.sb-responsive-field'
        );

        if (!field) {
            return;
        }

        var badge = field.querySelector(
            '.sb-rux-source'
        );

        if (!badge) {
            badge = document.createElement('small');
            badge.className = 'sb-rux-source';
            field.appendChild(badge);
        }

        var source = sourceForField(
            key,
            directInputValue(input),
            activeDevice()
        );

        badge.className =
            'sb-rux-source is-' + source.kind;
        badge.textContent = source.text;

        field.classList.toggle(
            'has-responsive-override',
            source.kind === 'override'
        );
    }

    function countOverrides(device) {
        var responsive = currentShadow();

        if (
            !responsive
            || !responsive[device]
            || typeof responsive[device] !== 'object'
        ) {
            return 0;
        }

        return Object.keys(responsive[device])
            .filter(function (key) {
                return hasValue(
                    responsive[device][key]
                );
            })
            .length;
    }

    function updateCounters() {
        if (!panel) {
            return;
        }

        ['tablet', 'mobile'].forEach(
            function (device) {
                var button = panel.querySelector(
                    '[data-responsive-device="'
                    + device
                    + '"]'
                );

                if (!button) {
                    return;
                }

                var counter = button.querySelector(
                    '.sb-rux-device-count'
                );

                if (counter) {
                    var count = countOverrides(device);
                    counter.textContent = String(count);
                    counter.hidden = count <= 0;
                }
            }
        );
    }

    function updateChain() {
        if (!chain) {
            return;
        }

        var device = activeDevice();

        chain.querySelectorAll(
            '[data-rux-chain-device]'
        ).forEach(function (item) {
            item.classList.toggle(
                'is-active',
                item.getAttribute(
                    'data-rux-chain-device'
                ) === device
            );
        });

        var title = node('blockResponsiveUxTitle');
        var description = node(
            'blockResponsiveUxDescription'
        );

        if (title) {
            title.textContent = deviceName(device);
        }

        if (description) {
            description.textContent =
                device === 'mobile'
                    ? 'Пустое поле: Tablet → Desktop.'
                    : 'Пустое поле использует Desktop.';
        }

        if (resetButton) {
            resetButton.textContent =
                'Сбросить '
                + deviceName(device);

            resetButton.disabled =
                countOverrides(device) <= 0;
        }
    }

    function decorateBlockPanel() {
        if (!ensureStructure()) {
            return;
        }

        var block = currentBlock();

        if (!block) {
            toolbar.hidden = true;
            chain.hidden = true;
            return;
        }

        normalizeShadowEntry(block);

        var visibleFields =
            fieldsHost.querySelectorAll(
                FIELD_SELECTOR
            );

        var hasFields = visibleFields.length > 0
            && !panel.hidden;

        toolbar.hidden = !hasFields;
        chain.hidden = !hasFields;

        if (!hasFields) {
            return;
        }

        syncVisibleFieldsToShadow();

        visibleFields.forEach(
            decorateField
        );

        updateCounters();
        updateChain();
    }

    function scheduleDecorate(delay) {
        clearTimeout(decorateTimer);

        decorateTimer = window.setTimeout(
            decorateAll,
            Number(delay || 0)
        );
    }

    function resetCurrentDevice() {
        if (
            !fieldsHost
            || !currentBlockId
        ) {
            return;
        }

        var device = activeDevice();

        fieldsHost.querySelectorAll(FIELD_SELECTOR)
            .forEach(function (input) {
                input.value = '';

                input.dispatchEvent(
                    new Event(
                        'input',
                        {bubbles: true}
                    )
                );
            });

        var responsive = currentShadow();

        if (responsive) {
            responsive[device] = {};
        }

        if (
            typeof window.renderBlocks
            === 'function'
        ) {
            window.renderBlocks();
        }

        scheduleDecorate(25);
    }

    /* =====================================================
       SECTION SOURCE LABELS
       Stage 2 already owns reset buttons for sections.
       ===================================================== */

    function sectionDirectValue(input) {
        var value = String(
            input.value == null ? '' : input.value
        ).trim();

        return value === ''
            ? undefined
            : value;
    }

    function sectionTabletValue(
        sectionId,
        key
    ) {
        var tablet = document.querySelector(
            '[data-section-responsive-id="'
            + sectionId
            + '"]'
            + '[data-section-responsive-device="tablet"]'
            + '[data-section-responsive-key="'
            + key
            + '"]'
        );

        return tablet
            ? sectionDirectValue(tablet)
            : undefined;
    }

    function decorateSectionInput(input) {
        var field = input.closest(
            '.sb-section-responsive-field'
        );

        if (!field) {
            return;
        }

        var device = String(
            input.getAttribute(
                'data-section-responsive-device'
            ) || ''
        );

        var sectionId = Number(
            input.getAttribute(
                'data-section-responsive-id'
            ) || 0
        );

        var key = String(
            input.getAttribute(
                'data-section-responsive-key'
            ) || ''
        );

        var direct = sectionDirectValue(input);
        var source;

        if (direct !== undefined) {
            source = {
                kind: 'override',
                text: 'Своё · ' + deviceName(device)
            };
        } else if (
            device === 'mobile'
            && sectionTabletValue(
                sectionId,
                key
            ) !== undefined
        ) {
            source = {
                kind: 'tablet',
                text: 'Наследовано · Tablet'
            };
        } else {
            source = {
                kind: 'desktop',
                text: 'Наследовано · Desktop'
            };
        }

        var badge = field.querySelector(
            '.sb-rux-source'
        );

        if (!badge) {
            badge = document.createElement('small');
            badge.className = 'sb-rux-source';
            field.appendChild(badge);
        }

        badge.className =
            'sb-rux-source is-' + source.kind;
        badge.textContent = source.text;
    }

    function ensureSectionChain(details) {
        if (
            !details
            || details.querySelector(
                '.sb-section-rux-chain'
            )
        ) {
            return;
        }

        var body = details.querySelector(
            '.sb-section-responsive__body'
        );

        if (!body) {
            return;
        }

        var chainNode =
            document.createElement('div');

        chainNode.className =
            'sb-section-rux-chain';

        chainNode.innerHTML = ''
            + '<span><b>Desktop</b><small>база</small></span>'
            + '<i>→</i>'
            + '<span><b>Tablet</b><small>override</small></span>'
            + '<i>→</i>'
            + '<span><b>Mobile</b><small>override</small></span>';

        var description = body.querySelector('p');

        if (description) {
            description.insertAdjacentElement(
                'afterend',
                chainNode
            );
        } else {
            body.insertBefore(
                chainNode,
                body.firstChild
            );
        }
    }

    function decorateSections() {
        document.querySelectorAll(
            '.sb-section-responsive'
        ).forEach(function (details) {
            ensureSectionChain(details);

            details.querySelectorAll(
                '[data-section-responsive-id]'
            ).forEach(
                decorateSectionInput
            );

            details.querySelectorAll(
                '[data-section-responsive-reset]'
            ).forEach(function (button) {
                var device = String(
                    button.getAttribute(
                        'data-section-responsive-reset-device'
                    ) || ''
                );

                if (
                    device === 'tablet'
                    || device === 'mobile'
                ) {
                    button.textContent =
                        'Сбросить '
                        + deviceName(device);
                }
            });
        });
    }

    function decorateAll() {
        decorateBlockPanel();
        decorateSections();
    }

    if (!ensureStructure()) {
        return;
    }

    resetButton.addEventListener(
        'click',
        resetCurrentDevice
    );

    /*
     * We load after Stage 1/2/3. Wrapping fillBlockForm here means
     * all responsive modules have already rendered their fields.
     */
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

            if (block) {
                reseedBlock(block);
            }

            scheduleDecorate(15);

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

                scheduleDecorate(20);

                return result;
            };
    }

    panel.addEventListener(
        'input',
        function (event) {
            if (
                !event.target.closest(
                    FIELD_SELECTOR
                )
            ) {
                return;
            }

            syncVisibleFieldsToShadow();
            scheduleDecorate(0);
        }
    );

    panel.addEventListener(
        'change',
        function (event) {
            if (
                !event.target.closest(
                    FIELD_SELECTOR
                )
            ) {
                return;
            }

            syncVisibleFieldsToShadow();
            scheduleDecorate(0);
        }
    );

    panel.addEventListener(
        'click',
        function (event) {
            if (
                !event.target.closest(
                    '[data-responsive-device]'
                )
            ) {
                return;
            }

            syncVisibleFieldsToShadow();

            /*
             * Stage 1/2/3 render their device-specific fields in
             * setTimeout(0). Decorate after them.
             */
            scheduleDecorate(30);
        }
    );

    document.addEventListener(
        'click',
        function (event) {
            if (
                event.target.closest(
                    '[data-preview-device]'
                )
            ) {
                syncVisibleFieldsToShadow();
                scheduleDecorate(35);
                return;
            }

            if (
                event.target.closest(
                    '[data-section-responsive-reset]'
                )
            ) {
                scheduleDecorate(30);
            }
        }
    );

    document.addEventListener(
        'input',
        function (event) {
            if (
                event.target.closest(
                    '[data-section-responsive-id]'
                )
            ) {
                scheduleDecorate(0);
            }
        }
    );

    document.addEventListener(
        'change',
        function (event) {
            if (
                event.target.closest(
                    '[data-section-responsive-id]'
                )
            ) {
                scheduleDecorate(0);
            }
        }
    );

    var initial = currentBlock();

    if (initial) {
        reseedBlock(initial);
    }

    scheduleDecorate(20);
})();
