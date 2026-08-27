(function () {
    'use strict';

    function gbNode(id) {
        return document.getElementById(id);
    }

    function gbToast(message, type) {
        if (typeof showEditorToast === 'function') {
            showEditorToast(message, type || 'info');
        } else if (type === 'error') {
            window.alert(message);
        }
    }

    function gbItems() {
        return Array.isArray(state.globalBlocks) ? state.globalBlocks : [];
    }

    function gbRecord(id) {
        id = Number(id || 0);
        return gbItems().find(function (item) {
            return Number(item.id || 0) === id;
        }) || null;
    }

    function gbCurrentBlock() {
        return typeof getCurrentBlock === 'function' ? getCurrentBlock() : null;
    }

    function gbCurrentSourceBlock() {
        var block = gbCurrentBlock();
        return block && String(block.type || '') !== 'global' ? block : null;
    }

    function gbPayload(res) {
        return res && res.data ? res.data : (res || {});
    }

    async function loadGlobalBlocks() {
        var response = await api('globalBlock.list', {siteId: siteId});
        var data = gbPayload(response);
        state.globalBlocks = Array.isArray(data.items) ? data.items : [];
        renderGlobalBlocks();
        return state.globalBlocks;
    }

    function openGlobalBlocks() {
        var modal = gbNode('globalBlocksModal');
        if (!modal) return;
        modal.hidden = false;
        document.documentElement.classList.add('sb-modal-open');
        var search = gbNode('globalBlocksSearch');
        if (search) {
            search.value = '';
            window.setTimeout(function () { search.focus(); }, 20);
        }
        loadGlobalBlocks().catch(function (error) {
            console.error(error);
            var status = gbNode('globalBlocksStatus');
            if (status) status.textContent = 'Не удалось загрузить глобальные блоки';
        });
    }

    function closeGlobalBlocks() {
        var modal = gbNode('globalBlocksModal');
        if (modal) modal.hidden = true;
        document.documentElement.classList.remove('sb-modal-open');
    }

    function globalPreview(record) {
        var block = record && record.block ? record.block : null;
        if (!block) return '<div class="sb-vb-code">Предпросмотр недоступен</div>';
        if (window.SBVisualBuilder && typeof window.SBVisualBuilder.blockPreviewHtml === 'function') {
            return window.SBVisualBuilder.blockPreviewHtml(block);
        }
        return '<div class="sb-vb-code">' + escapeHtml(record.blockType || 'Блок') + '</div>';
    }

    function renderGlobalBlocks() {
        var host = gbNode('globalBlocksList');
        var status = gbNode('globalBlocksStatus');
        if (!host) return;

        var search = String((gbNode('globalBlocksSearch') || {}).value || '').trim().toLowerCase();
        var items = gbItems().filter(function (item) {
            if (!search) return true;
            return String(item.name || '').toLowerCase().indexOf(search) !== -1
                || String(item.blockType || '').toLowerCase().indexOf(search) !== -1;
        });
        var source = gbCurrentSourceBlock();

        if (status) {
            status.textContent = items.length
                ? 'Найдено: ' + items.length + '. Выберите блок, чтобы вставить связанную копию.'
                : 'Глобальные блоки не найдены.';
        }

        if (!items.length) {
            host.innerHTML = '<div class="sb-global-block-empty"><strong>Пока нет глобальных блоков</strong><br>Выберите обычный блок на холсте и нажмите «Сохранить как глобальный».</div>';
            return;
        }

        host.innerHTML = items.map(function (item) {
            var usage = Number(item.usageCount || 0);
            return ''
                + '<article class="sb-global-block-card" data-global-block-card="' + Number(item.id || 0) + '">'
                + '  <div class="sb-global-block-card__preview">' + globalPreview(item) + '</div>'
                + '  <div class="sb-global-block-card__body">'
                + '    <div class="sb-global-block-card__title-row">'
                + '      <div><h3 class="sb-global-block-card__title">' + escapeHtml(item.name || 'Глобальный блок') + '</h3>'
                + '      <div class="sb-global-block-card__meta">' + escapeHtml(item.blockType || 'block') + ' · используется: ' + usage + '</div></div>'
                + '      <span class="sb-global-block-card__badge">∞ связан</span>'
                + '    </div>'
                + '    <div class="sb-global-block-card__actions">'
                + '      <button class="sb-btn sb-btn-primary sb-btn-small" type="button" data-global-block-insert="' + Number(item.id || 0) + '">Вставить</button>'
                + (source ? '<button class="sb-btn sb-btn-light sb-btn-small" type="button" data-global-block-update="' + Number(item.id || 0) + '">Обновить из выбранного</button>' : '')
                + '      <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-global-block-rename="' + Number(item.id || 0) + '">Переименовать</button>'
                + '      <button class="sb-btn sb-btn-danger sb-btn-small" type="button" data-global-block-delete="' + Number(item.id || 0) + '"' + (usage > 0 ? ' disabled title="Сначала удалите связанные экземпляры"' : '') + '>Удалить</button>'
                + '    </div>'
                + '  </div>'
                + '</article>';
        }).join('');
    }

    async function saveCurrentAsGlobal() {
        var block = gbCurrentSourceBlock();
        if (!block) {
            gbToast('Выберите обычный блок. Связанный глобальный экземпляр нельзя использовать как источник.', 'error');
            return;
        }
        var proposed = String((block.content || {}).title || (block.content || {}).text || '').replace(/<[^>]+>/g, '').trim().slice(0, 70);
        var name = window.prompt('Название глобального блока', proposed || 'Глобальный блок');
        if (name === null) return;
        name = String(name || '').trim();
        if (!name) {
            gbToast('Введите название глобального блока', 'error');
            return;
        }
        try {
            var response = await api('globalBlock.create', {
                siteId: siteId,
                blockId: Number(block.id || 0),
                name: name
            });
            var item = gbPayload(response).item;
            await loadGlobalBlocks();
            gbToast('Глобальный блок «' + (item && item.name ? item.name : name) + '» создан', 'success');
        } catch (error) {
            console.error(error);
            gbToast('Не удалось создать глобальный блок', 'error');
        }
    }

    async function insertGlobalBlock(globalBlockId) {
        if (!state.currentPageId) {
            gbToast('Сначала выберите страницу', 'error');
            return;
        }
        var record = gbRecord(globalBlockId);
        if (!record) return;
        var sectionId = typeof getDefaultSectionId === 'function' ? Number(getDefaultSectionId() || 0) : 0;
        var column = typeof getDefaultColumn === 'function' ? Number(getDefaultColumn() || 1) : 1;
        var props = {
            sectionId: sectionId,
            column: column,
            _placement: {sectionId: sectionId, column: column}
        };
        try {
            var response = await api('block.create', {
                pageId: Number(state.currentPageId || 0),
                type: 'global',
                content: JSON.stringify({globalBlockId: Number(globalBlockId || 0)}),
                props: JSON.stringify(props),
                sectionId: sectionId,
                column: column
            });
            var data = gbPayload(response);
            var created = data.block || response.block || null;
            if (created && sectionId > 0 && typeof assignBlockToSection === 'function') {
                await assignBlockToSection(Number(created.id || 0), sectionId, column);
            }
            await loadBlocks();
            if (created) state.currentBlockId = Number(created.id || 0);
            if (typeof renderBlocks === 'function') renderBlocks();
            if (typeof fillBlockForm === 'function') fillBlockForm();
            closeGlobalBlocks();
            gbToast('Глобальный блок добавлен на страницу', 'success');
        } catch (error) {
            console.error(error);
            gbToast('Не удалось вставить глобальный блок', 'error');
        }
    }

    async function updateGlobalBlock(globalBlockId) {
        var source = gbCurrentSourceBlock();
        var record = gbRecord(globalBlockId);
        if (!source || !record) {
            gbToast('Выберите обычный блок, содержимым которого нужно обновить глобальный блок', 'error');
            return;
        }
        if (!window.confirm('Обновить «' + record.name + '» содержимым выбранного блока? Все связанные экземпляры изменятся.')) return;
        try {
            await api('globalBlock.update', {
                siteId: siteId,
                globalBlockId: Number(globalBlockId || 0),
                blockId: Number(source.id || 0)
            });
            await loadGlobalBlocks();
            if (typeof renderBlocks === 'function') renderBlocks();
            gbToast('Глобальный блок обновлён во всех местах', 'success');
        } catch (error) {
            console.error(error);
            gbToast('Не удалось обновить глобальный блок', 'error');
        }
    }

    async function renameGlobalBlock(globalBlockId) {
        var record = gbRecord(globalBlockId);
        if (!record) return;
        var name = window.prompt('Новое название', record.name || 'Глобальный блок');
        if (name === null) return;
        name = String(name || '').trim();
        if (!name) return;
        try {
            await api('globalBlock.rename', {siteId: siteId, globalBlockId: Number(globalBlockId || 0), name: name});
            await loadGlobalBlocks();
            if (typeof renderBlocks === 'function') renderBlocks();
            gbToast('Название изменено', 'success');
        } catch (error) {
            console.error(error);
            gbToast('Не удалось переименовать глобальный блок', 'error');
        }
    }

    async function deleteGlobalBlock(globalBlockId) {
        var record = gbRecord(globalBlockId);
        if (!record) return;
        if (Number(record.usageCount || 0) > 0) {
            gbToast('Сначала удалите все связанные экземпляры этого блока', 'error');
            return;
        }
        if (!window.confirm('Удалить глобальный блок «' + record.name + '»?')) return;
        try {
            await api('globalBlock.delete', {siteId: siteId, globalBlockId: Number(globalBlockId || 0)});
            await loadGlobalBlocks();
            gbToast('Глобальный блок удалён', 'success');
        } catch (error) {
            console.error(error);
            gbToast('Не удалось удалить глобальный блок', 'error');
        }
    }

    function ensureReferenceInfo(block) {
        var inspector = gbNode('blockInspector');
        if (!inspector) return;
        var node = gbNode('globalBlockReferenceInfo');
        if (!node) {
            node = document.createElement('div');
            node.id = 'globalBlockReferenceInfo';
            node.className = 'sb-global-block-reference sb-hidden';
            var designPanel = gbNode('blockDesignPanel');
            var modeTabs = gbNode('blockInspectorModes');
            var advancedToggle = gbNode('toggleAdvancedJsonBtn');

            /*
             * После появления вкладок инспектора designPanel
             * находится внутри панели «Адаптивность» и больше
             * не является прямым ребёнком blockInspector.
             */
            if (designPanel && designPanel.parentNode === inspector) {
                inspector.insertBefore(node, designPanel);
            } else if (modeTabs && modeTabs.parentNode === inspector) {
                inspector.insertBefore(node, modeTabs);
            } else if (
                advancedToggle
                && advancedToggle.parentNode === inspector
            ) {
                inspector.insertBefore(node, advancedToggle);
            } else {
                inspector.prepend(node);
            }
        }
        if (!block || String(block.type || '') !== 'global') {
            node.classList.add('sb-hidden');
            var saveButton = gbNode('saveGlobalBlockBtn');
            if (saveButton) saveButton.disabled = !block;
            return;
        }
        var record = gbRecord(Number((block.content || {}).globalBlockId || 0));
        node.classList.remove('sb-hidden');
        node.innerHTML = ''
            + '<div class="sb-global-block-reference__head"><span>Связанный глобальный блок</span><span>∞</span></div>'
            + '<strong>' + escapeHtml(record ? record.name : 'Блок не найден') + '</strong>'
            + '<p class="sb-block-form-note">Содержимое редактируется централизованно. Удаление этого экземпляра не удаляет глобальное определение.</p>'
            + '<button class="sb-btn sb-btn-light sb-btn-small" type="button" data-open-global-blocks-inline>Открыть библиотеку</button>';
        var save = gbNode('saveGlobalBlockBtn');
        if (save) save.disabled = true;
    }

    var originalLoadSite = window.loadSite;
    if (typeof originalLoadSite === 'function') {
        window.loadSite = async function () {
            var result = await originalLoadSite.apply(this, arguments);
            await loadGlobalBlocks();
            return result;
        };
    }

    var originalFillBlockForm = window.fillBlockForm;
    if (typeof originalFillBlockForm === 'function') {
        window.fillBlockForm = function () {
            var result = originalFillBlockForm.apply(this, arguments);
            ensureReferenceInfo(gbCurrentBlock());
            return result;
        };
    }

    var originalRenderBlocks = window.renderBlocks;
    if (typeof originalRenderBlocks === 'function') {
        window.renderBlocks = function () {
            var result = originalRenderBlocks.apply(this, arguments);
            ensureReferenceInfo(gbCurrentBlock());
            return result;
        };
    }

    var saveButton = gbNode('saveGlobalBlockBtn');
    var openButton = gbNode('openGlobalBlocksBtn');
    var refreshButton = gbNode('globalBlocksRefresh');
    var searchInput = gbNode('globalBlocksSearch');
    if (saveButton) saveButton.addEventListener('click', saveCurrentAsGlobal);
    if (openButton) openButton.addEventListener('click', openGlobalBlocks);
    if (refreshButton) refreshButton.addEventListener('click', function () { loadGlobalBlocks().catch(console.error); });
    if (searchInput) searchInput.addEventListener('input', renderGlobalBlocks);

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-close-global-blocks]')) {
            closeGlobalBlocks();
            return;
        }
        if (event.target.closest('[data-open-global-blocks-inline], [data-open-global-blocks-library]')) {
            var blockLibrary = gbNode('blockLibraryModal');
            if (blockLibrary) blockLibrary.hidden = true;
            openGlobalBlocks();
            return;
        }
        var insert = event.target.closest('[data-global-block-insert]');
        if (insert) {
            insertGlobalBlock(Number(insert.getAttribute('data-global-block-insert') || 0));
            return;
        }
        var update = event.target.closest('[data-global-block-update]');
        if (update) {
            updateGlobalBlock(Number(update.getAttribute('data-global-block-update') || 0));
            return;
        }
        var rename = event.target.closest('[data-global-block-rename]');
        if (rename) {
            renameGlobalBlock(Number(rename.getAttribute('data-global-block-rename') || 0));
            return;
        }
        var remove = event.target.closest('[data-global-block-delete]');
        if (remove) {
            deleteGlobalBlock(Number(remove.getAttribute('data-global-block-delete') || 0));
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && gbNode('globalBlocksModal') && !gbNode('globalBlocksModal').hidden) {
            closeGlobalBlocks();
        }
    });

    window.SBGlobalBlocks = {
        load: loadGlobalBlocks,
        open: openGlobalBlocks,
        close: closeGlobalBlocks,
        render: renderGlobalBlocks
    };
})();
