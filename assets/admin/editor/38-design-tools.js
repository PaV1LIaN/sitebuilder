/* =========================================================
   STAGE 19 / DESIGN SYSTEM AND RESPONSIVE MOTION TOOLS
   ========================================================= */
(function () {
    'use strict';

    if (typeof state === 'undefined') return;

    var STYLE_STORAGE_KEY = 'sitebuilder:block-style:v1';
    var DEVICE_KEYS = {desktop: 'desktop', tablet: 'tablet', mobile: 'mobile'};

    function dtNode(id) { return document.getElementById(id); }
    function dtValue(id, fallback) {
        var node = dtNode(id);
        return node ? String(node.value == null ? '' : node.value) : String(fallback == null ? '' : fallback);
    }
    function dtChecked(id, fallback) {
        var node = dtNode(id);
        return node ? !!node.checked : !!fallback;
    }
    function dtNumber(value, min, max, fallback) {
        value = Number(value);
        if (!isFinite(value)) value = fallback;
        return Math.max(min, Math.min(max, value));
    }
    function dtChoice(value, allowed, fallback) {
        value = String(value || '');
        return allowed.indexOf(value) !== -1 ? value : fallback;
    }
    function dtEscape(value) {
        return typeof escapeHtml === 'function' ? escapeHtml(value) : String(value == null ? '' : value);
    }

    function designDefaults() {
        return {
            desktop: true,
            tablet: true,
            mobile: true,
            animation: 'none',
            animationDelay: 0,
            animationDuration: 600,
            marginTop: 0,
            marginBottom: 0
        };
    }

    function normalizeDesign(value) {
        value = value && typeof value === 'object' ? value : {};
        var defaults = designDefaults();
        return {
            desktop: value.desktop !== false,
            tablet: value.tablet !== false,
            mobile: value.mobile !== false,
            animation: dtChoice(value.animation, ['none', 'fade', 'fade-up', 'zoom', 'slide-left', 'slide-right'], defaults.animation),
            animationDelay: dtNumber(value.animationDelay, 0, 3000, defaults.animationDelay),
            animationDuration: dtNumber(value.animationDuration, 150, 3000, defaults.animationDuration),
            marginTop: dtNumber(value.marginTop, 0, 240, defaults.marginTop),
            marginBottom: dtNumber(value.marginBottom, 0, 240, defaults.marginBottom)
        };
    }

    function fillBlockDesign(block) {
        var design = normalizeDesign(block && block.props && block.props._design);
        if (dtNode('blockVisibleDesktopInput')) dtNode('blockVisibleDesktopInput').checked = design.desktop;
        if (dtNode('blockVisibleTabletInput')) dtNode('blockVisibleTabletInput').checked = design.tablet;
        if (dtNode('blockVisibleMobileInput')) dtNode('blockVisibleMobileInput').checked = design.mobile;
        if (dtNode('blockAnimationInput')) dtNode('blockAnimationInput').value = design.animation;
        if (dtNode('blockAnimationDelayInput')) dtNode('blockAnimationDelayInput').value = String(design.animationDelay);
        if (dtNode('blockAnimationDurationInput')) dtNode('blockAnimationDurationInput').value = String(design.animationDuration);
        if (dtNode('blockMarginTopInput')) dtNode('blockMarginTopInput').value = String(design.marginTop);
        if (dtNode('blockMarginBottomInput')) dtNode('blockMarginBottomInput').value = String(design.marginBottom);
    }

    function collectBlockDesign() {
        return {
            desktop: dtChecked('blockVisibleDesktopInput', true),
            tablet: dtChecked('blockVisibleTabletInput', true),
            mobile: dtChecked('blockVisibleMobileInput', true),
            animation: dtChoice(dtValue('blockAnimationInput', 'none'), ['none', 'fade', 'fade-up', 'zoom', 'slide-left', 'slide-right'], 'none'),
            animationDelay: dtNumber(dtValue('blockAnimationDelayInput', 0), 0, 3000, 0),
            animationDuration: dtNumber(dtValue('blockAnimationDurationInput', 600), 150, 3000, 600),
            marginTop: dtNumber(dtValue('blockMarginTopInput', 0), 0, 240, 0),
            marginBottom: dtNumber(dtValue('blockMarginBottomInput', 0), 0, 240, 0)
        };
    }

    function fontStack(value) {
        var map = {
            system: 'Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
            arial: 'Arial,Helvetica,sans-serif',
            georgia: 'Georgia,"Times New Roman",serif',
            times: '"Times New Roman",Times,serif',
            mono: 'ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace'
        };
        return map[value] || map.system;
    }

    function shadowValue(value) {
        var map = {
            none: 'none',
            soft: '0 12px 32px rgba(15,23,42,.08)',
            medium: '0 18px 48px rgba(15,23,42,.14)',
            strong: '0 24px 70px rgba(15,23,42,.22)'
        };
        return map[value] || map.soft;
    }

    function applySiteDesignTokens() {
        var viewport = dtNode('editorViewport') || dtNode('blocksList');
        if (!viewport) return;
        var settings = (state.site && state.site.settings) || {};
        var values = {
            '--sb-editor-accent': settings.accent || '#2563eb',
            '--sb-editor-secondary': settings.secondaryColor || '#0f172a',
            '--sb-editor-text': settings.textColor || '#0f172a',
            '--sb-editor-muted': settings.mutedColor || '#64748b',
            '--sb-editor-surface': settings.surfaceColor || '#ffffff',
            '--sb-editor-border': settings.borderColor || '#e2e8f0',
            '--sb-editor-heading-font': fontStack(settings.headingFont || 'system'),
            '--sb-editor-body-font': fontStack(settings.bodyFont || 'system'),
            '--sb-editor-base-size': dtNumber(settings.baseFontSize, 14, 22, 16) + 'px',
            '--sb-editor-line-height': dtNumber(settings.bodyLineHeight, 1.2, 2.2, 1.6),
            '--sb-editor-heading-weight': dtNumber(settings.headingWeight, 500, 900, 800),
            '--sb-editor-radius': dtNumber(settings.radiusScale, 0, 32, 16) + 'px',
            '--sb-editor-button-radius': dtNumber(settings.buttonRadius, 0, 40, 12) + 'px',
            '--sb-editor-shadow': shadowValue(settings.shadowPreset || 'soft')
        };
        Object.keys(values).forEach(function (key) { viewport.style.setProperty(key, values[key]); });
    }

    function currentPreviewDevice() {
        return DEVICE_KEYS[String(state.previewDevice || 'desktop')] || 'desktop';
    }

    function isVisibleOn(design, device) {
        design = normalizeDesign(design);
        return design[device] !== false;
    }

    function applyPreviewDesign() {
        var device = currentPreviewDevice();
        state.blocks.forEach(function (block) {
            var node = document.querySelector('.sb-editor-block[data-block-id="' + Number(block.id || 0) + '"]');
            if (!node) return;
            var design = normalizeDesign(block.props && block.props._design);
            node.classList.toggle('is-responsive-hidden', !isVisibleOn(design, device));
            node.style.setProperty('--sb-preview-block-mt', design.marginTop + 'px');
            node.style.setProperty('--sb-preview-block-mb', design.marginBottom + 'px');
            node.setAttribute('data-preview-animation', design.animation);
            var label = node.querySelector('.sb-design-preview-badge');
            if (!label) {
                label = document.createElement('span');
                label.className = 'sb-design-preview-badge';
                var head = node.querySelector('.sb-editor-block-head');
                if (head) head.appendChild(label);
            }
            if (label) {
                var flags = [];
                if (!design.desktop) flags.push('без ПК');
                if (!design.tablet) flags.push('без планшета');
                if (!design.mobile) flags.push('без телефона');
                if (design.animation !== 'none') flags.push(design.animation);
                label.textContent = flags.join(' · ');
                label.hidden = !flags.length;
            }
        });

        state.pageSections.forEach(function (section) {
            var node = document.querySelector('[data-editor-section-id="' + Number(section.id || 0) + '"]');
            if (!node) return;
            var design = normalizeDesign(section.props && section.props._design);
            node.classList.toggle('is-responsive-hidden', !isVisibleOn(design, device));
            node.setAttribute('data-preview-animation', design.animation);
        });
    }

    function sectionDesignMarkup(section) {
        var id = Number(section.id || 0);
        var d = normalizeDesign(section.props && section.props._design);
        return ''
            + '<details class="sb-section-appearance sb-section-responsive" data-section-design-panel="' + id + '">'
            + '<summary>Адаптивность и анимация</summary>'
            + '<div class="sb-section-appearance__body">'
            + '<div class="sb-form-row sb-block-visibility-row">'
            + '<label><input type="checkbox" ' + (d.desktop ? 'checked ' : '') + 'data-section-design="desktop" data-section-id="' + id + '"> Компьютер</label>'
            + '<label><input type="checkbox" ' + (d.tablet ? 'checked ' : '') + 'data-section-design="tablet" data-section-id="' + id + '"> Планшет</label>'
            + '<label><input type="checkbox" ' + (d.mobile ? 'checked ' : '') + 'data-section-design="mobile" data-section-id="' + id + '"> Телефон</label>'
            + '</div>'
            + '<div class="sb-section-fields sb-section-fields--3">'
            + '<label>Появление<select data-section-design="animation" data-section-id="' + id + '">'
            + ['none','fade','fade-up','zoom','slide-left','slide-right'].map(function (value) {
                var labels = {none:'Без анимации',fade:'Проявление','fade-up':'Снизу вверх',zoom:'Масштаб','slide-left':'Слева','slide-right':'Справа'};
                return '<option value="' + value + '"' + (d.animation === value ? ' selected' : '') + '>' + labels[value] + '</option>';
            }).join('')
            + '</select></label>'
            + '<label>Задержка, мс<input type="number" min="0" max="3000" step="50" value="' + d.animationDelay + '" data-section-design="animationDelay" data-section-id="' + id + '"></label>'
            + '<label>Длительность, мс<input type="number" min="150" max="3000" step="50" value="' + d.animationDuration + '" data-section-design="animationDuration" data-section-id="' + id + '"></label>'
            + '</div></div></details>';
    }

    function injectSectionDesignControls() {
        state.pageSections.forEach(function (section) {
            var card = document.querySelector('[data-page-section-id="' + Number(section.id || 0) + '"]');
            if (!card || card.querySelector('[data-section-design-panel]')) return;
            var actions = card.querySelector('.sb-page-section-card__actions');
            if (actions) actions.insertAdjacentHTML('beforebegin', sectionDesignMarkup(section));
        });
    }

    function readSectionDesign(sectionId) {
        var section = typeof getSectionById === 'function' ? getSectionById(sectionId) : null;
        var current = normalizeDesign(section && section.props && section.props._design);
        var root = document.querySelector('[data-section-design-panel="' + Number(sectionId || 0) + '"]');
        if (!root) return current;
        function field(name) { return root.querySelector('[data-section-design="' + name + '"]'); }
        return {
            desktop: !!(field('desktop') && field('desktop').checked),
            tablet: !!(field('tablet') && field('tablet').checked),
            mobile: !!(field('mobile') && field('mobile').checked),
            animation: dtChoice(field('animation') && field('animation').value, ['none','fade','fade-up','zoom','slide-left','slide-right'], 'none'),
            animationDelay: dtNumber(field('animationDelay') && field('animationDelay').value, 0, 3000, 0),
            animationDuration: dtNumber(field('animationDuration') && field('animationDuration').value, 150, 3000, 600),
            marginTop: 0,
            marginBottom: 0
        };
    }

    var originalLoadSite = window.loadSite;
    if (typeof originalLoadSite === 'function') {
        window.loadSite = async function () {
            var result = await originalLoadSite.apply(this, arguments);
            applySiteDesignTokens();
            return result;
        };
    }

    var originalFillBlockForm = window.fillBlockForm;
    if (typeof originalFillBlockForm === 'function') {
        window.fillBlockForm = function () {
            var result = originalFillBlockForm.apply(this, arguments);
            fillBlockDesign(typeof getCurrentBlock === 'function' ? getCurrentBlock() : null);
            return result;
        };
    }

    var originalCollectBlock = window.collectVisualBlockData;
    if (typeof originalCollectBlock === 'function') {
        window.collectVisualBlockData = function (block) {
            var result = originalCollectBlock.apply(this, arguments);
            if (!result) return result;
            result.props = Object.assign({}, result.props || {});
            result.props._design = collectBlockDesign();
            return result;
        };
    }

    var originalRenderBlocks = window.renderBlocks;
    if (typeof originalRenderBlocks === 'function') {
        window.renderBlocks = function () {
            var result = originalRenderBlocks.apply(this, arguments);
            applySiteDesignTokens();
            window.setTimeout(applyPreviewDesign, 0);
            return result;
        };
    }

    var originalRenderSections = window.renderPageSectionsPanel;
    if (typeof originalRenderSections === 'function') {
        window.renderPageSectionsPanel = function () {
            var result = originalRenderSections.apply(this, arguments);
            injectSectionDesignControls();
            window.setTimeout(applyPreviewDesign, 0);
            return result;
        };
    }

    var originalSaveSection = window.savePageSection;
    if (typeof originalSaveSection === 'function') {
        window.savePageSection = async function (sectionId) {
            var section = typeof getSectionById === 'function' ? getSectionById(sectionId) : null;
            if (section) {
                section.props = Object.assign({}, section.props || {}, {_design: readSectionDesign(sectionId)});
            }
            return originalSaveSection.apply(this, arguments);
        };
    }

    function copyBlockStyle() {
        var block = typeof getCurrentBlock === 'function' ? getCurrentBlock() : null;
        if (!block) return;
        var collected = window.collectVisualBlockData(block);
        if (!collected) return;
        var props = Object.assign({}, collected.props || {});
        delete props.sectionId;
        delete props.column;
        delete props._placement;
        localStorage.setItem(STYLE_STORAGE_KEY, JSON.stringify({type: String(block.type || ''), props: props}));
        if (typeof showEditorToast === 'function') showEditorToast('Стиль блока скопирован', 'success');
    }

    function pasteBlockStyle() {
        var block = typeof getCurrentBlock === 'function' ? getCurrentBlock() : null;
        if (!block) return;
        var raw = localStorage.getItem(STYLE_STORAGE_KEY);
        if (!raw) {
            if (typeof showEditorToast === 'function') showEditorToast('Сначала скопируйте стиль блока', 'error');
            return;
        }
        try {
            var data = JSON.parse(raw);
            if (String(data.type || '') !== String(block.type || '')) {
                if (typeof showEditorToast === 'function') showEditorToast('Стиль можно вставить только в блок того же типа', 'error');
                return;
            }
            var placement = {
                sectionId: block.props && block.props.sectionId,
                column: block.props && block.props.column,
                _placement: block.props && block.props._placement
            };
            block.props = Object.assign({}, block.props || {}, data.props || {}, placement);
            if (typeof fillBlockForm === 'function') fillBlockForm();
            if (typeof renderBlocks === 'function') renderBlocks();
            if (typeof showEditorToast === 'function') showEditorToast('Стиль вставлен. Нажмите «Сохранить блок»', 'success');
        } catch (error) {
            console.error(error);
            if (typeof showEditorToast === 'function') showEditorToast('Не удалось вставить стиль', 'error');
        }
    }

    var copyButton = dtNode('copyBlockStyleBtn');
    var pasteButton = dtNode('pasteBlockStyleBtn');
    if (copyButton) copyButton.addEventListener('click', copyBlockStyle);
    if (pasteButton) pasteButton.addEventListener('click', pasteBlockStyle);

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-section-design]')) applyPreviewDesign();
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-preview-device]')) window.setTimeout(applyPreviewDesign, 0);
    });

    applySiteDesignTokens();
})();
