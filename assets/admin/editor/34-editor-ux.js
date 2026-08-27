/* =========================================================
   SITEBUILDER EDITOR UX / STAGE 17
   Resizable workspace, page tree DnD, section/block DnD,
   inline editing, local undo/redo and draft recovery.
   ========================================================= */
(function () {
    'use strict';

    if (!window.SB_EDITOR_CONFIG || typeof state === 'undefined') {
        return;
    }

    var UX_VERSION = 17;
    var uiStorageKey = 'sitebuilder.editor.ui.' + siteId;
    var draftPrefix = 'sitebuilder.editor.draft.' + siteId + '.';
    var root = document.documentElement;
    var body = document.body;
    var editorShell = document.getElementById('editorShell');
    var pageContextMenu = null;
    var draggedPageId = 0;
    var draggedBlockId = 0;
    var draggedSectionId = 0;
    var pendingDraft = null;
    var suppressDraftCheck = false;
    var draftSaveTimer = 0;
    var mutationTimer = 0;
    var applyingHistory = false;
    var mutationBurst = false;
    var historyLastSnapshot = null;
    var undoStack = [];
    var redoStack = [];
    var MAX_HISTORY = 80;
    var dirtyKinds = {page: false, block: false, section: false};

    function deepClone(value) {
        try {
            return JSON.parse(JSON.stringify(value));
        } catch (error) {
            return value;
        }
    }

    function safeParse(value, fallback) {
        try {
            var parsed = JSON.parse(String(value || ''));
            return parsed == null ? fallback : parsed;
        } catch (error) {
            return fallback;
        }
    }

    function storageGet(key, fallback) {
        try {
            var value = window.localStorage.getItem(key);
            return value === null ? fallback : safeParse(value, fallback);
        } catch (error) {
            return fallback;
        }
    }

    function storageSet(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch (error) {
            // localStorage can be disabled or full.
        }
    }

    function storageRemove(key) {
        try {
            window.localStorage.removeItem(key);
        } catch (error) {
            // Ignore unavailable localStorage.
        }
    }

    function clamp(number, min, max) {
        return Math.max(min, Math.min(max, Number(number || 0)));
    }

    function pageById(pageId) {
        pageId = Number(pageId || 0);
        return (state.pages || []).find(function (page) {
            return Number(page.id || 0) === pageId;
        }) || null;
    }

    function sectionById(sectionId) {
        sectionId = Number(sectionId || 0);
        return (state.pageSections || []).find(function (section) {
            return Number(section.id || 0) === sectionId;
        }) || null;
    }

    function blockById(blockId) {
        blockId = Number(blockId || 0);
        return (state.blocks || []).find(function (block) {
            return Number(block.id || 0) === blockId;
        }) || null;
    }

    function currentDraftKey(pageId) {
        pageId = Number(pageId || state.currentPageId || 0);
        return pageId > 0 ? draftPrefix + pageId : '';
    }

    /* -----------------------------------------------------
       Workspace state and panel resizing
       ----------------------------------------------------- */

    function defaultUiState() {
        var compact = window.matchMedia && window.matchMedia('(max-width: 980px)').matches;
        return {
            version: UX_VERSION,
            leftWidth: 300,
            rightWidth: 390,
            leftCollapsed: compact,
            rightCollapsed: compact,
            focusMode: false,
            theme: 'light',
            collapsedPages: {}
        };
    }

    var uiState = Object.assign(defaultUiState(), storageGet(uiStorageKey, {}));
    if (window.matchMedia && window.matchMedia('(max-width: 980px)').matches) {
        uiState.leftCollapsed = true;
        uiState.rightCollapsed = true;
        uiState.focusMode = false;
    }
    uiState.collapsedPages = uiState.collapsedPages && typeof uiState.collapsedPages === 'object'
        ? uiState.collapsedPages
        : {};

    function saveUiState() {
        storageSet(uiStorageKey, uiState);
    }

    function applyUiState() {
        root.style.setProperty('--sb-editor-left-width', clamp(uiState.leftWidth, 230, 500) + 'px');
        root.style.setProperty('--sb-editor-right-width', clamp(uiState.rightWidth, 300, 590) + 'px');
        body.classList.toggle('sb-editor-left-collapsed', !!uiState.leftCollapsed);
        body.classList.toggle('sb-editor-right-collapsed', !!uiState.rightCollapsed);
        body.classList.toggle('sb-editor-focus-mode', !!uiState.focusMode);
        body.classList.toggle('sb-editor-theme-dark', uiState.theme === 'dark');

        var leftButton = document.getElementById('togglePagesPanelBtn');
        var rightButton = document.getElementById('toggleInspectorPanelBtn');
        var focusButton = document.getElementById('toggleFocusModeBtn');
        var themeButton = document.getElementById('toggleEditorThemeBtn');

        if (leftButton) {
            leftButton.setAttribute('aria-pressed', uiState.leftCollapsed ? 'true' : 'false');
            leftButton.title = uiState.leftCollapsed ? 'Показать дерево страниц' : 'Свернуть дерево страниц';
        }
        if (rightButton) {
            rightButton.setAttribute('aria-pressed', uiState.rightCollapsed ? 'true' : 'false');
            rightButton.title = uiState.rightCollapsed ? 'Показать инспектор' : 'Свернуть инспектор';
        }
        if (focusButton) {
            focusButton.setAttribute('aria-pressed', uiState.focusMode ? 'true' : 'false');
            focusButton.title = uiState.focusMode ? 'Выйти из режима фокуса' : 'Фокус на холсте';
        }
        if (themeButton) {
            themeButton.setAttribute('aria-pressed', uiState.theme === 'dark' ? 'true' : 'false');
            themeButton.textContent = uiState.theme === 'dark' ? '☀' : '◐';
            themeButton.title = uiState.theme === 'dark' ? 'Светлая тема' : 'Тёмная тема';
        }
    }

    function togglePanel(name) {
        if (name === 'left') {
            uiState.leftCollapsed = !uiState.leftCollapsed;
            if (!uiState.leftCollapsed) uiState.focusMode = false;
        } else if (name === 'right') {
            uiState.rightCollapsed = !uiState.rightCollapsed;
            if (!uiState.rightCollapsed) uiState.focusMode = false;
        }
        applyUiState();
        saveUiState();
    }

    function installPanelControls() {
        var leftButton = document.getElementById('togglePagesPanelBtn');
        var rightButton = document.getElementById('toggleInspectorPanelBtn');
        var focusButton = document.getElementById('toggleFocusModeBtn');
        var themeButton = document.getElementById('toggleEditorThemeBtn');

        if (leftButton) leftButton.addEventListener('click', function () { togglePanel('left'); });
        if (rightButton) rightButton.addEventListener('click', function () { togglePanel('right'); });
        if (focusButton) {
            focusButton.addEventListener('click', function () {
                uiState.focusMode = !uiState.focusMode;
                applyUiState();
                saveUiState();
            });
        }
        if (themeButton) {
            themeButton.addEventListener('click', function () {
                uiState.theme = uiState.theme === 'dark' ? 'light' : 'dark';
                applyUiState();
                saveUiState();
            });
        }

        document.querySelectorAll('[data-panel-resizer]').forEach(function (resizer) {
            var side = String(resizer.getAttribute('data-panel-resizer') || '');
            var startX = 0;
            var startWidth = 0;

            function finishResize() {
                if (!resizer.classList.contains('is-resizing')) return;
                resizer.classList.remove('is-resizing');
                body.classList.remove('sb-editor-resizing');
                saveUiState();
            }

            resizer.addEventListener('pointerdown', function (event) {
                if (event.button !== 0) return;
                event.preventDefault();
                startX = event.clientX;
                startWidth = side === 'left' ? Number(uiState.leftWidth || 300) : Number(uiState.rightWidth || 390);
                resizer.classList.add('is-resizing');
                body.classList.add('sb-editor-resizing');
                if (typeof resizer.setPointerCapture === 'function') {
                    resizer.setPointerCapture(event.pointerId);
                }
            });

            resizer.addEventListener('pointermove', function (event) {
                if (!resizer.classList.contains('is-resizing')) return;
                var delta = side === 'left' ? event.clientX - startX : startX - event.clientX;
                var next = clamp(startWidth + delta, side === 'left' ? 230 : 300, side === 'left' ? 500 : 590);
                if (side === 'left') uiState.leftWidth = next;
                if (side === 'right') uiState.rightWidth = next;
                applyUiState();
            });

            resizer.addEventListener('pointerup', finishResize);
            resizer.addEventListener('pointercancel', finishResize);
            resizer.addEventListener('lostpointercapture', finishResize);
            resizer.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
                event.preventDefault();
                var step = event.shiftKey ? 25 : 10;
                var direction = event.key === 'ArrowRight' ? 1 : -1;
                if (side === 'right') direction *= -1;
                if (side === 'left') uiState.leftWidth = clamp(Number(uiState.leftWidth || 300) + direction * step, 230, 500);
                if (side === 'right') uiState.rightWidth = clamp(Number(uiState.rightWidth || 390) + direction * step, 300, 590);
                applyUiState();
                saveUiState();
            });
        });
    }

    /* -----------------------------------------------------
       Page tree
       ----------------------------------------------------- */

    function sortedPages(items) {
        return (items || []).slice().sort(function (first, second) {
            var sortCompare = Number(first.sort || 0) - Number(second.sort || 0);
            return sortCompare !== 0 ? sortCompare : Number(first.id || 0) - Number(second.id || 0);
        });
    }

    function pageTreeModel() {
        var byId = {};
        var children = {};
        var visited = {};
        var rows = [];

        (state.pages || []).forEach(function (page) {
            var id = Number(page.id || 0);
            if (id <= 0) return;
            byId[id] = page;
            var parentId = Number(page.parentId || 0);
            if (!children[parentId]) children[parentId] = [];
            children[parentId].push(page);
        });

        Object.keys(children).forEach(function (key) {
            children[key] = sortedPages(children[key]);
        });

        function walk(parentId, depth, ancestry) {
            (children[parentId] || []).forEach(function (page) {
                var id = Number(page.id || 0);
                if (visited[id] || ancestry[id]) return;
                visited[id] = true;
                rows.push({page: page, depth: depth, hasChildren: !!(children[id] && children[id].length)});
                var nextAncestry = Object.assign({}, ancestry);
                nextAncestry[id] = true;
                walk(id, depth + 1, nextAncestry);
            });
        }

        walk(0, 0, {});
        sortedPages(state.pages).forEach(function (page) {
            var id = Number(page.id || 0);
            if (id <= 0 || visited[id]) return;
            rows.push({page: page, depth: 0, hasChildren: !!(children[id] && children[id].length), orphan: true});
            walk(id, 1, (function () { var map = {}; map[id] = true; return map; })());
        });

        return {rows: rows, byId: byId, children: children};
    }

    function pageSearchVisibility(model, query) {
        var matched = {};
        var visible = {};
        query = String(query || '').trim().toLowerCase();
        if (!query) return {matched: matched, visible: visible};

        model.rows.forEach(function (row) {
            var page = row.page;
            var id = Number(page.id || 0);
            var haystack = [page.title, page.slug, page.status, id].join(' ').toLowerCase();
            if (haystack.indexOf(query) === -1) return;
            matched[id] = true;
            visible[id] = true;
            var parentId = Number(page.parentId || 0);
            var guard = 0;
            while (parentId > 0 && model.byId[parentId] && guard < 200) {
                visible[parentId] = true;
                parentId = Number(model.byId[parentId].parentId || 0);
                guard++;
            }
        });
        return {matched: matched, visible: visible};
    }

    function pageIsHiddenByCollapsedAncestor(page, model, query) {
        if (query) return false;
        var parentId = Number(page.parentId || 0);
        var guard = 0;
        while (parentId > 0 && model.byId[parentId] && guard < 200) {
            if (uiState.collapsedPages[parentId]) return true;
            parentId = Number(model.byId[parentId].parentId || 0);
            guard++;
        }
        return false;
    }

    function renderPages17() {
        if (!pagesList) return;
        if (!state.pages.length) {
            pagesList.innerHTML = '<div class="sb-empty">Страниц пока нет</div>';
            updateEditorContextSelection();
            return;
        }

        var model = pageTreeModel();
        var query = String(state.pageSearch || '').trim().toLowerCase();
        var search = pageSearchVisibility(model, query);
        var homePageId = Number((state.site && (state.site.homePageId || state.site.home_page_id)) || 0);
        var html = [];

        model.rows.forEach(function (row) {
            var page = row.page;
            var id = Number(page.id || 0);
            if (query && !search.visible[id]) return;
            if (pageIsHiddenByCollapsedAncestor(page, model, query)) return;

            var status = String(page.status || 'draft');
            var collapsed = !!uiState.collapsedPages[id] && !query;
            var active = id === Number(state.currentPageId || 0);
            var matched = !!search.matched[id];
            var title = String(page.title || ('Страница #' + id));
            var slug = String(page.slug || '');

            html.push(''
                + '<div class="sb-page-tree-node" data-page-tree-node="' + id + '">'
                + '<div class="sb-page-tree-row' + (active ? ' is-active' : '') + (matched ? ' is-search-match' : '') + '"'
                + ' draggable="true" data-page-id="' + id + '" data-page-tree-row="' + id + '"'
                + ' style="--sb-page-depth:' + Number(row.depth || 0) + '" role="treeitem" aria-level="' + (Number(row.depth || 0) + 1) + '"'
                + (row.hasChildren ? ' aria-expanded="' + (collapsed ? 'false' : 'true') + '"' : '') + '>'
                + '<button class="sb-page-tree-toggle' + (row.hasChildren ? '' : ' is-empty') + '" type="button" data-page-collapse="' + id + '" aria-label="' + (collapsed ? 'Развернуть' : 'Свернуть') + '" aria-expanded="' + (collapsed ? 'false' : 'true') + '">'
                + '<svg viewBox="0 0 12 12" aria-hidden="true"><path d="M4 2.5 8 6 4 9.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                + '</button>'
                + '<div class="sb-page-tree-main">'
                + '<span class="sb-page-tree-status' + (status === 'published' ? ' is-published' : '') + '" title="' + escapeHtml(status === 'published' ? 'Опубликована' : 'Черновик') + '"></span>'
                + '<div class="sb-page-tree-copy">'
                + '<div class="sb-page-tree-title">' + (id === homePageId ? '<span class="is-home" title="Главная страница">⌂</span>' : '') + escapeHtml(title) + '</div>'
                + '<div class="sb-page-tree-meta">/' + escapeHtml(slug) + (row.orphan ? ' · нет родителя' : '') + '</div>'
                + '</div></div>'
                + '<button class="sb-page-tree-menu" type="button" data-page-menu="' + id + '" aria-label="Действия со страницей" title="Действия">•••</button>'
                + '</div></div>');
        });

        pagesList.innerHTML = html.length ? html.join('') : '<div class="sb-empty">По запросу ничего не найдено</div>';
        pagesList.setAttribute('role', 'tree');
        updateEditorContextSelection();
    }

    window.renderPages = renderPages17;

    function clearPageDropClasses() {
        if (!pagesList) return;
        pagesList.querySelectorAll('.sb-page-tree-row').forEach(function (row) {
            row.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-inside', 'is-dragging');
        });
    }

    function pageDropPosition(row, clientY) {
        var rect = row.getBoundingClientRect();
        var ratio = rect.height > 0 ? (clientY - rect.top) / rect.height : .5;
        if (ratio < .27) return 'before';
        if (ratio > .73) return 'after';
        return 'inside';
    }

    async function selectPage17(pageId) {
        pageId = Number(pageId || 0);
        if (pageId <= 0 || !pageById(pageId)) return;
        closePageContextMenu();
        var previousPageId = Number(state.currentPageId || 0);
        if (previousPageId > 0 && previousPageId !== pageId) {
            if (anyDirty()) saveLocalDraft();
            if (window.SBVisualBuilder && typeof window.SBVisualBuilder.clearDraft === 'function') {
                (state.blocks || []).forEach(function (block) {
                    window.SBVisualBuilder.clearDraft(Number(block.id || 0));
                });
            }
            dirtyKinds = {page: false, block: false, section: false};
            pendingDraft = null;
            var recoveryBanner = document.getElementById('draftRecoveryBanner');
            if (recoveryBanner) recoveryBanner.hidden = true;
        }
        state.currentPageId = pageId;
        state.currentBlockId = 0;
        state.currentSectionId = 0;
        state.currentColumn = 1;
        renderPages17();
        fillPageForm();
        updateCanvasHeader();
        if (typeof setInspectorTab === 'function') setInspectorTab('page');
        historyReset();
        await loadBlocks();
        if (window.matchMedia && window.matchMedia('(max-width: 980px)').matches) {
            uiState.leftCollapsed = true;
            applyUiState();
        }
    }

    function closePageContextMenu() {
        if (pageContextMenu && pageContextMenu.parentNode) {
            pageContextMenu.parentNode.removeChild(pageContextMenu);
        }
        pageContextMenu = null;
    }

    function openPageContextMenu(pageId, anchor) {
        closePageContextMenu();
        var page = pageById(pageId);
        if (!page || !anchor) return;
        var rect = anchor.getBoundingClientRect();
        var status = String(page.status || 'draft');
        var menu = document.createElement('div');
        menu.className = 'sb-page-context-menu';
        menu.setAttribute('data-page-context-menu', String(pageId));
        menu.innerHTML = ''
            + '<button type="button" data-page-context-action="open"><span>↗</span>Открыть страницу</button>'
            + '<button type="button" data-page-context-action="duplicate"><span>⧉</span>Дублировать</button>'
            + '<button type="button" data-page-context-action="toggle-status"><span>' + (status === 'published' ? '◐' : '●') + '</span>' + (status === 'published' ? 'Снять с публикации' : 'Опубликовать') + '</button>'
            + '<div class="sb-page-context-menu__separator"></div>'
            + '<button class="is-danger" type="button" data-page-context-action="delete"><span>×</span>Удалить страницу</button>';
        document.body.appendChild(menu);
        var left = Math.min(window.innerWidth - menu.offsetWidth - 8, Math.max(8, rect.right - menu.offsetWidth));
        var top = Math.min(window.innerHeight - menu.offsetHeight - 8, rect.bottom + 5);
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
        pageContextMenu = menu;
    }

    async function pageContextAction(action, pageId) {
        var page = pageById(pageId);
        if (!page) return;
        closePageContextMenu();

        if (action === 'open') {
            window.open(BASE_PATH + '/public.php?siteId=' + siteId + '&pageId=' + pageId, '_blank', 'noopener');
            return;
        }

        if (action === 'duplicate') {
            var duplicateResult = await api('page.duplicate', {id: pageId});
            await loadPages();
            var created = duplicateResult.page || (duplicateResult.data && duplicateResult.data.page) || null;
            if (created && created.id) await selectPage17(Number(created.id));
            if (typeof showEditorToast === 'function') showEditorToast('Копия страницы создана', 'success');
            return;
        }

        if (action === 'toggle-status') {
            await api('page.setStatus', {
                id: pageId,
                status: String(page.status || 'draft') === 'published' ? 'draft' : 'published',
                expectedVersion: entityVersion(page)
            });
            await loadPages();
            if (typeof showEditorToast === 'function') showEditorToast('Статус страницы изменён', 'success');
            return;
        }

        if (action === 'delete') {
            await selectPage17(pageId);
            await deletePage();
        }
    }

    /* -----------------------------------------------------
       Visual canvas and drag-and-drop
       ----------------------------------------------------- */

    function sortedBlocks(items) {
        return (items || []).slice().sort(function (first, second) {
            var sortCompare = Number(first.sort || 0) - Number(second.sort || 0);
            return sortCompare !== 0 ? sortCompare : Number(first.id || 0) - Number(second.id || 0);
        });
    }

    function sectionDropSlot(beforeSectionId) {
        return '<div class="sb-editor-section-drop-slot" data-section-drop-slot="1" data-before-section-id="' + Number(beforeSectionId || 0) + '"></div>';
    }

    function blockDropSlot(sectionId, column, beforeBlockId) {
        return '<div class="sb-editor-block-drop-slot" data-block-drop-slot="1" data-section-id="' + Number(sectionId || 0) + '" data-column="' + Number(column || 1) + '" data-before-block-id="' + Number(beforeBlockId || 0) + '"></div>';
    }

    function renderBlocks17() {
        if (!blocksList) return;
        var vb = window.SBVisualBuilder;
        if (!vb || typeof vb.blockCard !== 'function' || typeof vb.sectionStyle !== 'function') {
            return;
        }

        if (!state.currentPageId) {
            blocksList.innerHTML = '<div class="sb-editor-empty-big"><strong>Страница не выбрана</strong>Выберите страницу слева, чтобы редактировать её содержимое</div>';
            updateEditorContextSelection();
            return;
        }

        if (!state.pageSections.length) {
            var loose = sortedBlocks(state.blocks);
            var looseHtml = blockDropSlot(0, 1, loose.length ? Number(loose[0].id || 0) : 0);
            loose.forEach(function (block, index) {
                looseHtml += vb.blockCard(block);
                looseHtml += blockDropSlot(0, 1, loose[index + 1] ? Number(loose[index + 1].id || 0) : 0);
            });
            blocksList.innerHTML = loose.length ? looseHtml : '<div class="sb-editor-empty-big"><strong>На странице пока нет блоков</strong>Создайте секцию или добавьте первый компонент</div>';
            updateEditorContextSelection();
            return;
        }

        var grouped = groupBlocksBySectionAndColumn();
        var sections = (state.pageSections || []).slice().sort(function (first, second) {
            var sortCompare = Number(first.sort || 0) - Number(second.sort || 0);
            return sortCompare !== 0 ? sortCompare : Number(first.id || 0) - Number(second.id || 0);
        });
        var html = [];

        sections.forEach(function (section, sectionIndex) {
            var sectionId = Number(section.id || 0);
            var layout = section.layout || {};
            var columns = clamp(Number(layout.columns || 1), 1, 4);
            var active = sectionId === Number(state.currentSectionId || 0);
            html.push(sectionDropSlot(sectionId));
            html.push(''
                + '<section class="sb-editor-section-preview' + (active ? ' is-active' : '') + '" data-editor-section-id="' + sectionId + '" style="' + vb.sectionStyle(section) + '">'
                + '<div class="sb-editor-section-preview__head" data-page-section-select="' + sectionId + '">'
                + '<div class="sb-editor-section-preview__identity"><span class="sb-editor-section-preview__drag" draggable="true" data-section-drag-handle="' + sectionId + '" title="Перетащить секцию">⋮⋮</span><h3 class="sb-editor-section-preview__title">' + escapeHtml(section.title || ('Секция ' + (sectionIndex + 1))) + '</h3></div>'
                + '<div class="sb-editor-section-preview__tools">'
                + '<button type="button" data-stage17-section-action="select" data-section-id="' + sectionId + '" title="Настройки секции">⚙</button>'
                + '<button type="button" data-stage17-section-action="add" data-section-id="' + sectionId + '" title="Добавить блок">＋</button>'
                + '</div></div>'
                + '<div class="sb-editor-section-preview__body"><div class="sb-editor-section-preview__grid">');

            for (var column = 1; column <= columns; column++) {
                var group = sortedBlocks(grouped[sectionId] && grouped[sectionId][column] ? grouped[sectionId][column] : []);
                var target = active && column === Number(state.currentColumn || 1);
                html.push('<div class="sb-editor-section-preview__column' + (target ? ' is-target' : '') + '" data-section-id="' + sectionId + '" data-column="' + column + '">'
                    + '<div class="sb-editor-section-preview__column-head"><button class="sb-btn sb-btn-light sb-btn-small" type="button" data-set-add-target="' + sectionId + '" data-column="' + column + '">' + (target ? 'Выбрано' : 'Добавлять сюда') + '</button></div>');

                html.push(blockDropSlot(sectionId, column, group.length ? Number(group[0].id || 0) : 0));
                group.forEach(function (block, index) {
                    html.push(vb.blockCard(block));
                    html.push(blockDropSlot(sectionId, column, group[index + 1] ? Number(group[index + 1].id || 0) : 0));
                });
                if (!group.length) {
                    html.push('<div class="sb-editor-section-preview__empty">Перетащите сюда блок или нажмите «＋»</div>');
                }
                html.push('</div>');
            }
            html.push('</div></div></section>');
        });
        html.push(sectionDropSlot(0));
        blocksList.innerHTML = html.join('');
        updateEditorContextSelection();
    }

    window.renderBlocks = renderBlocks17;


    /* -----------------------------------------------------
       Drag & Drop 2.0
       Large hit areas, nearest insertion point, target HUD
       and canvas auto-scroll.
       ----------------------------------------------------- */

    function clearCanvasDropTargets() {
        if (!blocksList) return;

        blocksList.querySelectorAll(
            '.is-drag-over,'
            + '.is-dnd2-target,'
            + '.is-dnd2-target-section,'
            + '.is-dnd2-noop'
        ).forEach(function (node) {
            node.classList.remove(
                'is-drag-over',
                'is-dnd2-target',
                'is-dnd2-target-section',
                'is-dnd2-noop'
            );
        });

        blocksList.querySelectorAll(
            '[data-dnd2-label]'
        ).forEach(function (node) {
            node.removeAttribute('data-dnd2-label');
        });

        var hud = document.getElementById('sbDnd2Hud');
        if (hud) hud.hidden = true;
    }

    function clearCanvasDragClasses() {
        clearCanvasDropTargets();

        if (blocksList) {
            blocksList.querySelectorAll('.is-dragging')
                .forEach(function (node) {
                    node.classList.remove('is-dragging');
                    node.removeAttribute('aria-grabbed');
                });
        }

        body.classList.remove(
            'sb-dnd2-active',
            'sb-dnd2-block-dragging',
            'sb-dnd2-section-dragging'
        );
    }

    function dnd2Hud() {
        var hud = document.getElementById('sbDnd2Hud');

        if (hud) {
            return hud;
        }

        hud = document.createElement('div');
        hud.id = 'sbDnd2Hud';
        hud.className = 'sb-dnd2-hud';
        hud.hidden = true;
        hud.setAttribute('aria-hidden', 'true');
        document.body.appendChild(hud);

        return hud;
    }

    function dnd2ShowHud(text, event) {
        var hud = dnd2Hud();
        hud.textContent = String(text || '');
        hud.hidden = false;

        var x = Number(event && event.clientX || 0) + 18;
        var y = Number(event && event.clientY || 0) + 18;

        hud.style.left = x + 'px';
        hud.style.top = y + 'px';

        var rect = hud.getBoundingClientRect();

        if (rect.right > window.innerWidth - 10) {
            hud.style.left = Math.max(
                10,
                window.innerWidth - rect.width - 10
            ) + 'px';
        }

        if (rect.bottom > window.innerHeight - 10) {
            hud.style.top = Math.max(
                10,
                Number(event && event.clientY || 0)
                    - rect.height
                    - 18
            ) + 'px';
        }
    }

    function dnd2DirectChildren(container, selector) {
        if (!container) return [];

        return Array.prototype.slice
            .call(container.querySelectorAll(selector))
            .filter(function (node) {
                return node.parentElement === container;
            });
    }

    function dnd2NearestSlot(slots, clientY) {
        if (!slots || !slots.length) {
            return null;
        }

        var best = null;
        var bestDistance = Infinity;

        slots.forEach(function (slot) {
            var rect = slot.getBoundingClientRect();
            var center = rect.top + rect.height / 2;
            var distance = Math.abs(
                Number(clientY || 0) - center
            );

            if (distance < bestDistance) {
                best = slot;
                bestDistance = distance;
            }
        });

        return best;
    }

    function dnd2ResolveBlockSlot(event) {
        if (!event || !event.target || !blocksList) {
            return null;
        }

        var direct = event.target.closest(
            '[data-block-drop-slot]'
        );

        if (direct && blocksList.contains(direct)) {
            return direct;
        }

        var column = event.target.closest(
            '.sb-editor-section-preview__column'
            + '[data-section-id][data-column]'
        );

        if (column && blocksList.contains(column)) {
            return dnd2NearestSlot(
                dnd2DirectChildren(
                    column,
                    '[data-block-drop-slot]'
                ),
                event.clientY
            );
        }

        if (
            !state.pageSections.length
            && blocksList.contains(event.target)
        ) {
            return dnd2NearestSlot(
                dnd2DirectChildren(
                    blocksList,
                    '[data-block-drop-slot]'
                ),
                event.clientY
            );
        }

        return null;
    }

    function dnd2ResolveSectionSlot(event) {
        if (!event || !event.target || !blocksList) {
            return null;
        }

        var direct = event.target.closest(
            '[data-section-drop-slot]'
        );

        if (direct && blocksList.contains(direct)) {
            return direct;
        }

        var section = event.target.closest(
            '.sb-editor-section-preview'
            + '[data-editor-section-id]'
        );

        if (section && blocksList.contains(section)) {
            var rect = section.getBoundingClientRect();
            var before = section.previousElementSibling;
            var after = section.nextElementSibling;

            before = before
                && before.matches('[data-section-drop-slot]')
                    ? before
                    : null;

            after = after
                && after.matches('[data-section-drop-slot]')
                    ? after
                    : null;

            if (
                Number(event.clientY || 0)
                < rect.top + rect.height / 2
            ) {
                return before || after;
            }

            return after || before;
        }

        if (blocksList.contains(event.target)) {
            return dnd2NearestSlot(
                dnd2DirectChildren(
                    blocksList,
                    '[data-section-drop-slot]'
                ),
                event.clientY
            );
        }

        return null;
    }

    function dnd2BlockName(blockId) {
        var block = blockById(blockId);

        if (!block) {
            return 'блок';
        }

        if (typeof blockTypeMeta === 'function') {
            return blockTypeMeta(block.type).title;
        }

        return String(block.type || 'блок');
    }

    function dnd2SectionName(sectionId) {
        var section = sectionById(sectionId);

        return section
            ? String(
                section.title
                || ('Секция #' + sectionId)
            )
            : (
                sectionId > 0
                    ? 'Секция #' + sectionId
                    : 'Страница'
            );
    }

    function dnd2BlockSlotIsNoop(
        blockId,
        sectionId,
        column,
        beforeBlockId
    ) {
        var block = blockById(blockId);

        if (!block) {
            return false;
        }

        if (
            getBlockSectionId(block) !== Number(sectionId || 0)
            || getBlockColumn(block) !== Number(column || 1)
        ) {
            return false;
        }

        var currentIds = sortedBlocks(state.blocks)
            .filter(function (item) {
                return getBlockSectionId(item)
                        === Number(sectionId || 0)
                    && getBlockColumn(item)
                        === Number(column || 1);
            })
            .map(function (item) {
                return Number(item.id || 0);
            });

        var index = currentIds.indexOf(
            Number(blockId || 0)
        );

        if (index < 0) {
            return false;
        }

        var nextId = currentIds[index + 1]
            ? Number(currentIds[index + 1])
            : 0;

        return Number(beforeBlockId || 0)
                === Number(blockId || 0)
            || Number(beforeBlockId || 0)
                === nextId;
    }

    function dnd2MarkBlockTarget(slot, event) {
        if (!slot) return;

        clearCanvasDropTargets();

        var sectionId = Number(
            slot.getAttribute('data-section-id') || 0
        );
        var column = Number(
            slot.getAttribute('data-column') || 1
        );
        var beforeBlockId = Number(
            slot.getAttribute('data-before-block-id') || 0
        );
        var noop = dnd2BlockSlotIsNoop(
            draggedBlockId,
            sectionId,
            column,
            beforeBlockId
        );

        slot.classList.add('is-drag-over');
        slot.classList.toggle('is-dnd2-noop', noop);
        slot.setAttribute(
            'data-dnd2-label',
            noop
                ? 'Уже здесь'
                : (
                    beforeBlockId > 0
                        ? 'Вставить перед'
                        : 'Вставить в конец'
                )
        );

        var targetColumn = slot.closest(
            '.sb-editor-section-preview__column'
        );

        if (targetColumn) {
            targetColumn.classList.add('is-dnd2-target');

            var targetSection = targetColumn.closest(
                '.sb-editor-section-preview'
            );

            if (targetSection) {
                targetSection.classList.add(
                    'is-dnd2-target-section'
                );
            }
        }

        var positionText = beforeBlockId > 0
            ? 'перед «'
                + dnd2BlockName(beforeBlockId)
                + '»'
            : 'в конец';

        dnd2ShowHud(
            noop
                ? 'Блок уже находится здесь'
                : (
                    dnd2SectionName(sectionId)
                    + ' · Колонка '
                    + column
                    + ' · '
                    + positionText
                ),
            event
        );
    }

    function dnd2MarkSectionTarget(slot, event) {
        if (!slot) return;

        clearCanvasDropTargets();

        var beforeSectionId = Number(
            slot.getAttribute(
                'data-before-section-id'
            ) || 0
        );

        slot.classList.add('is-drag-over');
        slot.setAttribute(
            'data-dnd2-label',
            beforeSectionId > 0
                ? 'Вставить секцию перед'
                : 'Переместить в конец'
        );

        dnd2ShowHud(
            beforeSectionId > 0
                ? 'Секция · перед «'
                    + dnd2SectionName(beforeSectionId)
                    + '»'
                : 'Секция · в конец страницы',
            event
        );
    }

    function dnd2AutoScroll(clientY) {
        var canvasBody = document.getElementById(
            'editorCanvasBody'
        );

        if (!canvasBody) {
            return;
        }

        var rect = canvasBody.getBoundingClientRect();
        var threshold = Math.min(
            96,
            Math.max(54, rect.height * 0.14)
        );
        var y = Number(clientY || 0);
        var delta = 0;

        if (y < rect.top + threshold) {
            delta = -Math.ceil(
                (rect.top + threshold - y)
                / threshold
                * 24
            );
        } else if (
            y > rect.bottom - threshold
        ) {
            delta = Math.ceil(
                (y - (rect.bottom - threshold))
                / threshold
                * 24
            );
        }

        if (delta !== 0) {
            canvasBody.scrollTop += delta;
        }
    }

    function dnd2SetDragImage(
        event,
        title,
        subtitle
    ) {
        if (
            !event
            || !event.dataTransfer
            || typeof event.dataTransfer.setDragImage
                !== 'function'
        ) {
            return;
        }

        var ghost = document.createElement('div');
        ghost.className = 'sb-dnd2-ghost';
        ghost.innerHTML = ''
            + '<strong>'
            + escapeHtml(String(title || 'Перемещение'))
            + '</strong>'
            + '<span>'
            + escapeHtml(String(subtitle || ''))
            + '</span>';

        document.body.appendChild(ghost);

        try {
            event.dataTransfer.setDragImage(
                ghost,
                24,
                18
            );
        } catch (error) {
            // Native fallback is fine.
        }

        window.setTimeout(function () {
            if (ghost.parentNode) {
                ghost.parentNode.removeChild(ghost);
            }
        }, 0);
    }


    function blockVisualOrder(movedBlockId, targetSectionId, targetColumn, beforeBlockId) {
        var order = [];
        var sections = (state.pageSections || []).slice().sort(function (a, b) {
            var cmp = Number(a.sort || 0) - Number(b.sort || 0);
            return cmp !== 0 ? cmp : Number(a.id || 0) - Number(b.id || 0);
        });
        var grouped = {};

        sections.forEach(function (section) {
            var sectionId = Number(section.id || 0);
            grouped[sectionId] = {};
            for (var column = 1; column <= getSectionColumns(sectionId); column++) grouped[sectionId][column] = [];
        });

        sortedBlocks(state.blocks).forEach(function (block) {
            var id = Number(block.id || 0);
            if (id === movedBlockId) return;
            var sectionId = getBlockSectionId(block);
            var column = getBlockColumn(block);
            if (!grouped[sectionId]) return;
            column = clamp(column, 1, getSectionColumns(sectionId));
            grouped[sectionId][column].push(id);
        });

        if (!grouped[targetSectionId]) grouped[targetSectionId] = {};
        if (!grouped[targetSectionId][targetColumn]) grouped[targetSectionId][targetColumn] = [];
        var targetIds = grouped[targetSectionId][targetColumn];
        var insertAt = beforeBlockId > 0 ? targetIds.indexOf(beforeBlockId) : targetIds.length;
        if (insertAt < 0) insertAt = targetIds.length;
        targetIds.splice(insertAt, 0, movedBlockId);

        sections.forEach(function (section) {
            var sectionId = Number(section.id || 0);
            for (var column = 1; column <= getSectionColumns(sectionId); column++) {
                (grouped[sectionId] && grouped[sectionId][column] ? grouped[sectionId][column] : []).forEach(function (id) { order.push(id); });
            }
        });

        sortedBlocks(state.blocks).forEach(function (block) {
            var id = Number(block.id || 0);
            if (order.indexOf(id) === -1) order.push(id);
        });
        return order;
    }

    async function moveBlockToSlot(blockId, sectionId, column, beforeBlockId) {
        blockId = Number(blockId || 0);
        sectionId = Number(sectionId || 0);
        column = Math.max(1, Number(column || 1));
        beforeBlockId = Number(beforeBlockId || 0);
        var block = blockById(blockId);
        if (!block) return;

        if (
            dnd2BlockSlotIsNoop(
                blockId,
                sectionId,
                column,
                beforeBlockId
            )
        ) {
            state.currentBlockId = blockId;
            state.currentSectionId = sectionId;
            state.currentColumn = column;
            return;
        }

        if (sectionId > 0 && (getBlockSectionId(block) !== sectionId || getBlockColumn(block) !== column)) {
            await assignBlockToSection(blockId, sectionId, column);
        }

        var order;
        if (sectionId <= 0 || !state.pageSections.length) {
            order = sortedBlocks(state.blocks).map(function (item) { return Number(item.id || 0); }).filter(function (id) { return id > 0 && id !== blockId; });
            var looseInsertAt = beforeBlockId > 0 ? order.indexOf(beforeBlockId) : order.length;
            if (looseInsertAt < 0) looseInsertAt = order.length;
            order.splice(looseInsertAt, 0, blockId);
        } else {
            order = blockVisualOrder(blockId, sectionId, column, beforeBlockId);
        }
        await api('block.reorder', {
            pageId: Number(state.currentPageId || 0),
            order: JSON.stringify(order),
            expectedVersions: JSON.stringify(buildVersionMap(state.blocks))
        });
        state.currentBlockId = blockId;
        state.currentSectionId = sectionId;
        state.currentColumn = column;
        await loadBlocks();
        if (typeof setInspectorTab === 'function') setInspectorTab('block');
        if (typeof showEditorToast === 'function') showEditorToast('Блок перемещён', 'success');
    }

    async function reorderSections(movedSectionId, beforeSectionId) {
        movedSectionId = Number(movedSectionId || 0);
        beforeSectionId = Number(beforeSectionId || 0);
        var ids = (state.pageSections || []).slice().sort(function (a, b) {
            var cmp = Number(a.sort || 0) - Number(b.sort || 0);
            return cmp !== 0 ? cmp : Number(a.id || 0) - Number(b.id || 0);
        }).map(function (section) { return Number(section.id || 0); }).filter(function (id) { return id > 0 && id !== movedSectionId; });
        var insertAt = beforeSectionId > 0 ? ids.indexOf(beforeSectionId) : ids.length;
        if (insertAt < 0) insertAt = ids.length;
        ids.splice(insertAt, 0, movedSectionId);
        var response = await api('pageSection.reorder', {
            siteId: siteId,
            pageId: Number(state.currentPageId || 0),
            order: JSON.stringify(ids),
            expectedVersions: JSON.stringify(pageSectionVersionMap())
        });
        var data = apiData(response);
        if (Array.isArray(data.sections)) state.pageSections = data.sections;
        else await loadPageSections();
        state.currentSectionId = movedSectionId;
        renderPageSectionsPanel();
        renderBlocks17();
        if (typeof showEditorToast === 'function') showEditorToast('Секции переупорядочены', 'success');
    }

    /* -----------------------------------------------------
       Inline content editing
       ----------------------------------------------------- */

    function selectBlockWithoutLosingCaret(blockId) {
        blockId = Number(blockId || 0);
        var block = blockById(blockId);
        if (!block) return;
        state.currentBlockId = blockId;
        var sectionId = getBlockSectionId(block);
        if (sectionId > 0) state.currentSectionId = sectionId;
        state.currentColumn = Math.max(1, getBlockColumn(block));
        fillBlockForm();
        renderPageSectionsPanel();
        if (blocksList) {
            blocksList.querySelectorAll('.sb-editor-block[data-block-id]').forEach(function (node) {
                node.classList.toggle('is-active', Number(node.getAttribute('data-block-id') || 0) === blockId);
            });
        }
        if (typeof setInspectorTab === 'function') setInspectorTab('block');
        updateEditorContextSelection();
    }

    function setInputValue(id, value) {
        var input = document.getElementById(id);
        if (input) input.value = value == null ? '' : String(value);
    }

    function inlineDraftFromElement(editable) {
        var blockId = Number(editable.getAttribute('data-inline-block-id') || 0);
        var field = String(editable.getAttribute('data-inline-field') || '');
        var rich = editable.getAttribute('data-inline-rich') === 'true';
        var block = blockById(blockId);
        if (!block || !window.SBVisualBuilder) return;

        if (state.currentBlockId !== blockId) selectBlockWithoutLosingCaret(blockId);
        var value = rich ? editable.innerHTML : editable.textContent;

        if (field === 'heading.text') setInputValue('headingTextInput', value);
        if (field === 'text.html') setInputValue('textTextInput', value);
        if (field === 'button.label') setInputValue('buttonLabelInput', value);
        if (field === 'hero.eyebrow') setInputValue('heroEyebrowInput', value);
        if (field === 'hero.title') setInputValue('heroTitleInput', value);
        if (field === 'hero.text') setInputValue('heroTextInput', value);
        if (field === 'quote.text') setInputValue('quoteTextInput', value);
        if (field === 'quote.author') {
            var parts = String(value || '').split(/\s+·\s+/);
            setInputValue('quoteAuthorInput', parts.shift() || '');
            setInputValue('quoteRoleInput', parts.join(' · '));
        }

        var collected = typeof window.collectVisualBlockData === 'function'
            ? window.collectVisualBlockData(block)
            : null;
        if (collected) window.SBVisualBuilder.setDraft(blockId, collected);
        markDirty('block');
    }

    function positionInlineToolbar() {
        var toolbar = document.getElementById('inlineTextToolbar');
        var selection = window.getSelection ? window.getSelection() : null;
        if (!toolbar || !selection || selection.rangeCount < 1 || selection.isCollapsed) {
            if (toolbar) toolbar.hidden = true;
            return;
        }
        var range = selection.getRangeAt(0);
        var node = range.commonAncestorContainer.nodeType === 1 ? range.commonAncestorContainer : range.commonAncestorContainer.parentElement;
        var editable = node && node.closest ? node.closest('[data-inline-rich="true"]') : null;
        if (!editable) {
            toolbar.hidden = true;
            return;
        }
        var rect = range.getBoundingClientRect();
        if (!rect || (!rect.width && !rect.height)) rect = editable.getBoundingClientRect();
        toolbar.hidden = false;
        var left = clamp(rect.left + rect.width / 2 - toolbar.offsetWidth / 2, 8, window.innerWidth - toolbar.offsetWidth - 8);
        var top = Math.max(8, rect.top - toolbar.offsetHeight - 8);
        toolbar.style.left = left + 'px';
        toolbar.style.top = top + 'px';
    }

    function installInlineToolbar() {
        var toolbar = document.getElementById('inlineTextToolbar');
        if (!toolbar) return;
        document.addEventListener('selectionchange', function () {
            window.requestAnimationFrame(positionInlineToolbar);
        });
        toolbar.addEventListener('mousedown', function (event) { event.preventDefault(); });
        toolbar.addEventListener('click', function (event) {
            var button = event.target.closest('[data-inline-command]');
            if (!button) return;
            event.preventDefault();
            var command = String(button.getAttribute('data-inline-command') || '');
            var value = null;
            if (command === 'createLink') {
                value = window.prompt('Адрес ссылки', 'https://');
                if (!value) return;
            }
            try {
                document.execCommand(command, false, value);
                var selection = window.getSelection ? window.getSelection() : null;
                if (selection && selection.anchorNode) {
                    var element = selection.anchorNode.nodeType === 1 ? selection.anchorNode : selection.anchorNode.parentElement;
                    var editable = element && element.closest ? element.closest('[data-inline-block-id]') : null;
                    if (editable) {
                        editable.dispatchEvent(new Event('input', {bubbles: true}));
                        editable.focus();
                    }
                }
            } catch (error) {
                console.error(error);
            }
            positionInlineToolbar();
        });
    }

    /* -----------------------------------------------------
       Local history and drafts
       ----------------------------------------------------- */

    function collectSectionDraft(sectionId) {
        var section = sectionById(sectionId);
        if (!section) return null;
        var draft = deepClone(section);
        var card = document.querySelector('[data-page-section-id="' + Number(sectionId) + '"]');
        if (!card) return draft;
        function field(name) { return card.querySelector('[data-section-field="' + name + '"]'); }
        var layout = Object.assign({}, draft.layout || {});
        var props = Object.assign({}, draft.props || {});
        var title = field('title');
        if (title) draft.title = String(title.value || '');
        ['columns', 'tabletColumns', 'mobileColumns', 'gap'].forEach(function (name) {
            var input = field(name); if (input) layout[name] = Number(input.value || 0);
        });
        ['container', 'verticalAlign'].forEach(function (name) {
            var input = field(name); if (input) layout[name] = String(input.value || '');
        });
        ['backgroundColor', 'textColor', 'backgroundImage', 'backgroundPosition', 'backgroundSize'].forEach(function (name) {
            var input = field(name); if (input) props[name] = String(input.value || '');
        });
        ['paddingTop', 'paddingBottom', 'paddingX', 'minHeight', 'borderRadius'].forEach(function (name) {
            var input = field(name); if (input) props[name] = Number(input.value || 0);
        });
        var shadow = field('shadow');
        if (shadow) props.shadow = !!shadow.checked;
        draft.layout = layout;
        draft.props = props;
        return draft;
    }

    function captureSnapshot() {
        var page = getCurrentPage();
        var block = getCurrentBlock();
        var blockData = null;
        if (block && typeof window.collectVisualBlockData === 'function') {
            try { blockData = window.collectVisualBlockData(block); } catch (error) { blockData = null; }
        }
        return {
            version: UX_VERSION,
            pageId: Number(state.currentPageId || 0),
            selectedBlockId: Number(state.currentBlockId || 0),
            selectedSectionId: Number(state.currentSectionId || 0),
            currentColumn: Number(state.currentColumn || 1),
            page: page ? {
                id: Number(page.id || 0),
                version: entityVersion(page),
                title: getInputValue('pageTitleInput'),
                slug: getInputValue('pageSlugInput'),
                status: getInputValue('pageStatusInput'),
                parentId: Number(getInputValue('pageParentInput') || 0)
            } : null,
            block: block && blockData ? {
                id: Number(block.id || 0),
                version: entityVersion(block),
                type: String(block.type || ''),
                content: deepClone(blockData.content || {}),
                props: deepClone(blockData.props || {})
            } : null,
            section: state.currentSectionId ? collectSectionDraft(state.currentSectionId) : null,
            dirty: Object.assign({}, dirtyKinds),
            capturedAt: new Date().toISOString()
        };
    }

    function snapshotsEqual(first, second) {
        if (!first || !second) return false;
        try { return JSON.stringify(first) === JSON.stringify(second); } catch (error) { return false; }
    }

    function updateHistoryButtons() {
        var undo = document.getElementById('undoEditorBtn');
        var redo = document.getElementById('redoEditorBtn');
        if (undo) undo.disabled = undoStack.length === 0;
        if (redo) redo.disabled = redoStack.length === 0;
    }

    function historyReset() {
        undoStack = [];
        redoStack = [];
        mutationBurst = false;
        window.clearTimeout(mutationTimer);
        historyLastSnapshot = captureSnapshot();
        updateHistoryButtons();
    }

    function beginMutation(kind) {
        if (applyingHistory) return;
        if (!historyLastSnapshot || Number(historyLastSnapshot.pageId || 0) !== Number(state.currentPageId || 0)) {
            historyLastSnapshot = captureSnapshot();
        }
        if (!mutationBurst) {
            undoStack.push(deepClone(historyLastSnapshot));
            if (undoStack.length > MAX_HISTORY) undoStack.shift();
            redoStack = [];
            mutationBurst = true;
        }
        window.clearTimeout(mutationTimer);
        mutationTimer = window.setTimeout(function () {
            mutationBurst = false;
            historyLastSnapshot = captureSnapshot();
            updateHistoryButtons();
        }, 650);
        if (kind) markDirty(kind);
        historyLastSnapshot = captureSnapshot();
        updateHistoryButtons();
    }

    function applyBlockSnapshot(blockSnapshot) {
        if (!blockSnapshot) return;
        var id = Number(blockSnapshot.id || 0);
        var index = state.blocks.findIndex(function (block) { return Number(block.id || 0) === id; });
        if (index < 0) return;
        var original = state.blocks[index];
        var temporary = Object.assign({}, original, {
            content: deepClone(blockSnapshot.content || {}),
            props: deepClone(blockSnapshot.props || {})
        });
        state.currentBlockId = id;
        state.blocks[index] = temporary;
        fillBlockForm();
        state.blocks[index] = original;
        if (window.SBVisualBuilder) window.SBVisualBuilder.setDraft(id, blockSnapshot);
    }

    function applySnapshot(snapshot) {
        if (!snapshot || Number(snapshot.pageId || 0) !== Number(state.currentPageId || 0)) return;
        applyingHistory = true;
        try {
            if (snapshot.page) {
                setInputValue('pageTitleInput', snapshot.page.title);
                setInputValue('pageSlugInput', snapshot.page.slug);
                setInputValue('pageStatusInput', snapshot.page.status);
                setInputValue('pageParentInput', snapshot.page.parentId);
            }
            if (snapshot.section) {
                var sectionId = Number(snapshot.section.id || 0);
                state.pageSections = state.pageSections.map(function (section) {
                    return Number(section.id || 0) === sectionId ? deepClone(snapshot.section) : section;
                });
                state.currentSectionId = sectionId;
            }
            if (snapshot.block) applyBlockSnapshot(snapshot.block);
            state.currentBlockId = Number(snapshot.selectedBlockId || 0);
            state.currentSectionId = Number(snapshot.selectedSectionId || state.currentSectionId || 0);
            state.currentColumn = Number(snapshot.currentColumn || 1);
            dirtyKinds = Object.assign({page: false, block: false, section: false}, snapshot.dirty || {});
            renderPageSectionsPanel();
            renderBlocks17();
            fillPageFormFromSnapshot(snapshot.page);
            if (snapshot.block) applyBlockSnapshot(snapshot.block);
            if (typeof setInspectorTab === 'function') {
                if (snapshot.block) setInspectorTab('block');
                else if (snapshot.section) setInspectorTab('section');
                else setInspectorTab('page');
            }
            setEditorStatus('dirty', 'Есть изменения');
            scheduleLocalDraftSave();
        } finally {
            applyingHistory = false;
        }
    }

    function fillPageFormFromSnapshot(pageSnapshot) {
        if (!pageSnapshot) return;
        setInputValue('pageTitleInput', pageSnapshot.title);
        setInputValue('pageSlugInput', pageSnapshot.slug);
        setInputValue('pageStatusInput', pageSnapshot.status);
        setInputValue('pageParentInput', pageSnapshot.parentId);
    }

    function undoEditor() {
        if (!undoStack.length) return;
        var current = captureSnapshot();
        var previous = undoStack.pop();
        redoStack.push(current);
        applySnapshot(previous);
        historyLastSnapshot = deepClone(previous);
        mutationBurst = false;
        updateHistoryButtons();
        if (typeof showEditorToast === 'function') showEditorToast('Действие отменено', 'info', 1400);
    }

    function redoEditor() {
        if (!redoStack.length) return;
        var current = captureSnapshot();
        var next = redoStack.pop();
        undoStack.push(current);
        applySnapshot(next);
        historyLastSnapshot = deepClone(next);
        mutationBurst = false;
        updateHistoryButtons();
        if (typeof showEditorToast === 'function') showEditorToast('Действие повторено', 'info', 1400);
    }

    function anyDirty() {
        return !!(dirtyKinds.page || dirtyKinds.block || dirtyKinds.section);
    }

    function markDirty(kind) {
        if (kind && Object.prototype.hasOwnProperty.call(dirtyKinds, kind)) dirtyKinds[kind] = true;
        setEditorStatus('dirty', 'Есть изменения');
        scheduleLocalDraftSave();
    }

    function scheduleLocalDraftSave() {
        window.clearTimeout(draftSaveTimer);
        draftSaveTimer = window.setTimeout(saveLocalDraft, 500);
    }

    function saveLocalDraft() {
        var key = currentDraftKey();
        if (!key || !anyDirty()) return;
        var snapshot = captureSnapshot();
        snapshot.dirty = Object.assign({}, dirtyKinds);
        storageSet(key, {
            version: UX_VERSION,
            siteId: siteId,
            pageId: Number(state.currentPageId || 0),
            updatedAt: new Date().toISOString(),
            snapshot: snapshot
        });
    }

    function discardLocalDraft(pageId) {
        var key = currentDraftKey(pageId);
        if (key) storageRemove(key);
        pendingDraft = null;
        var banner = document.getElementById('draftRecoveryBanner');
        if (banner) banner.hidden = true;
    }

    function draftMatchesServer(draft) {
        if (!draft || !draft.snapshot || Number(draft.pageId || 0) !== Number(state.currentPageId || 0)) return false;
        var snapshot = draft.snapshot;
        var page = getCurrentPage();
        if (snapshot.page && page && Number(snapshot.page.version || 0) !== entityVersion(page)) return false;
        if (snapshot.block) {
            var block = blockById(snapshot.block.id);
            if (!block || Number(snapshot.block.version || 0) !== entityVersion(block)) return false;
        }
        if (snapshot.section) {
            var section = sectionById(snapshot.section.id);
            if (!section || Number(snapshot.section.version || 0) !== Number(section.version || 1)) return false;
        }
        return true;
    }

    function checkLocalDraft() {
        var banner = document.getElementById('draftRecoveryBanner');
        if (!banner || suppressDraftCheck || !state.currentPageId) return;
        var draft = storageGet(currentDraftKey(), null);
        if (!draft) {
            banner.hidden = true;
            pendingDraft = null;
            return;
        }
        if (!draftMatchesServer(draft)) {
            discardLocalDraft(state.currentPageId);
            return;
        }
        pendingDraft = draft;
        var text = document.getElementById('draftRecoveryText');
        if (text) {
            var time = draft.updatedAt ? new Date(draft.updatedAt).toLocaleString() : '';
            text.textContent = 'Несохранённая версия' + (time ? ' от ' + time : '') + '.';
        }
        banner.hidden = false;
    }

    function clearSavedKind(kind) {
        if (kind && Object.prototype.hasOwnProperty.call(dirtyKinds, kind)) dirtyKinds[kind] = false;
        if (!anyDirty()) {
            discardLocalDraft(state.currentPageId);
            setEditorStatus('ready', 'Сохранено');
        } else {
            saveLocalDraft();
            setEditorStatus('dirty', 'Есть изменения');
        }
        historyLastSnapshot = captureSnapshot();
    }

    function installSaveWrappers() {
        var originalSavePage = window.savePage;
        var originalSaveBlock = window.saveBlock;
        var originalSaveSection = window.savePageSection;
        var originalLoadBlocks = window.loadBlocks;
        var originalLoadPages = window.loadPages;

        if (typeof originalLoadBlocks === 'function') {
            window.loadBlocks = async function () {
                var result = await originalLoadBlocks.apply(this, arguments);
                updateEditorContextSelection();
                if (!suppressDraftCheck) checkLocalDraft();
                historyLastSnapshot = captureSnapshot();
                updateHistoryButtons();
                return result;
            };
        }
        if (typeof originalLoadPages === 'function') {
            window.loadPages = async function () {
                var result = await originalLoadPages.apply(this, arguments);
                renderPages17();
                updateEditorContextSelection();
                return result;
            };
        }
        if (typeof originalSavePage === 'function') {
            window.savePage = async function () {
                suppressDraftCheck = true;
                try {
                    var result = await originalSavePage.apply(this, arguments);
                    clearSavedKind('page');
                    return result;
                } finally {
                    suppressDraftCheck = false;
                }
            };
        }
        if (typeof originalSaveBlock === 'function') {
            window.saveBlock = async function () {
                suppressDraftCheck = true;
                try {
                    var result = await originalSaveBlock.apply(this, arguments);
                    clearSavedKind('block');
                    return result;
                } finally {
                    suppressDraftCheck = false;
                }
            };
        }
        if (typeof originalSaveSection === 'function') {
            window.savePageSection = async function () {
                suppressDraftCheck = true;
                try {
                    var result = await originalSaveSection.apply(this, arguments);
                    clearSavedKind('section');
                    return result;
                } finally {
                    suppressDraftCheck = false;
                }
            };
        }
    }

    function targetDirtyKind(target) {
        if (!target || !target.closest) return '';
        if (target.closest('[data-inline-block-id], #blockInspector')) return 'block';
        if (target.closest('[data-page-section-id]')) return 'section';
        if (target.matches('#pageTitleInput, #pageSlugInput, #pageStatusInput, #pageParentInput')) return 'page';
        return '';
    }

    function isTextEditingTarget(target) {
        if (!target) return false;
        var tag = String(target.tagName || '').toLowerCase();
        return !!(target.isContentEditable || tag === 'input' || tag === 'textarea' || tag === 'select');
    }

    /* -----------------------------------------------------
       Event interception
       ----------------------------------------------------- */

    document.addEventListener('click', function (event) {
        var collapse = event.target.closest('[data-page-collapse]');
        if (collapse) {
            event.preventDefault();
            event.stopImmediatePropagation();
            var collapseId = Number(collapse.getAttribute('data-page-collapse') || 0);
            uiState.collapsedPages[collapseId] = !uiState.collapsedPages[collapseId];
            saveUiState();
            renderPages17();
            return;
        }

        var menuButton = event.target.closest('[data-page-menu]');
        if (menuButton) {
            event.preventDefault();
            event.stopImmediatePropagation();
            openPageContextMenu(Number(menuButton.getAttribute('data-page-menu') || 0), menuButton);
            return;
        }

        var contextAction = event.target.closest('[data-page-context-action]');
        if (contextAction && pageContextMenu) {
            event.preventDefault();
            event.stopImmediatePropagation();
            var contextPageId = Number(pageContextMenu.getAttribute('data-page-context-menu') || 0);
            pageContextAction(String(contextAction.getAttribute('data-page-context-action') || ''), contextPageId).catch(function (error) {
                console.error(error);
                if (typeof showEditorToast === 'function') showEditorToast('Не удалось выполнить действие со страницей', 'error');
            });
            return;
        }

        var pageRow = event.target.closest('[data-page-tree-row]');
        if (pageRow && !event.target.closest('button, a')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            selectPage17(Number(pageRow.getAttribute('data-page-tree-row') || 0)).catch(console.error);
            return;
        }

        var sectionAction = event.target.closest('[data-stage17-section-action]');
        if (sectionAction) {
            event.preventDefault();
            event.stopImmediatePropagation();
            var sectionId = Number(sectionAction.getAttribute('data-section-id') || 0);
            var action = String(sectionAction.getAttribute('data-stage17-section-action') || '');
            if (sectionId > 0) {
                state.currentSectionId = sectionId;
                state.currentColumn = 1;
                renderPageSectionsPanel();
                renderBlocks17();
                if (typeof setInspectorTab === 'function') setInspectorTab('section');
                if (action === 'add' && typeof openBlockLibrary === 'function') openBlockLibrary();
            }
            return;
        }

        var inline = event.target.closest('[data-inline-block-id]');
        if (inline) {
            event.stopImmediatePropagation();
            selectBlockWithoutLosingCaret(Number(inline.getAttribute('data-inline-block-id') || 0));
            return;
        }

        var blockNode = event.target.closest('.sb-editor-block[data-block-id]');
        if (blockNode && !event.target.closest('[data-vb-action], [data-block-drag-handle]')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            var blockId = Number(blockNode.getAttribute('data-block-id') || 0);
            state.currentBlockId = blockId;
            var selectedBlock = blockById(blockId);
            if (selectedBlock) {
                state.currentSectionId = getBlockSectionId(selectedBlock) || state.currentSectionId;
                state.currentColumn = getBlockColumn(selectedBlock) || 1;
            }
            renderPageSectionsPanel();
            renderBlocks17();
            fillBlockForm();
            if (typeof setInspectorTab === 'function') setInspectorTab('block');
            updateEditorContextSelection();
            return;
        }

        if (pageContextMenu && !event.target.closest('.sb-page-context-menu')) closePageContextMenu();
    }, true);


    document.addEventListener('dragstart', function (event) {
        var pageRow = event.target.closest('[data-page-tree-row]');
        if (pageRow && !event.target.closest('button, a')) {
            draggedPageId = Number(pageRow.getAttribute('data-page-tree-row') || 0);
            if (draggedPageId <= 0) return;
            pageRow.classList.add('is-dragging');
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', 'page:' + draggedPageId);
            }
            return;
        }

        var blockHandle = event.target.closest('[data-block-drag-handle]');
        if (blockHandle) {
            event.stopImmediatePropagation();
            draggedBlockId = Number(blockHandle.getAttribute('data-block-drag-handle') || 0);
            state.draggedBlockId = draggedBlockId;

            clearCanvasDropTargets();
            body.classList.add(
                'sb-dnd2-active',
                'sb-dnd2-block-dragging'
            );

            var blockNode = blockHandle.closest('.sb-editor-block');
            if (blockNode) {
                blockNode.classList.add('is-dragging');
                blockNode.setAttribute('aria-grabbed', 'true');
            }

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', 'block:' + draggedBlockId);

                dnd2SetDragImage(
                    event,
                    dnd2BlockName(draggedBlockId),
                    'Перемещение блока'
                );
            }
            return;
        }

        var sectionHandle = event.target.closest('[data-section-drag-handle]');
        if (sectionHandle) {
            event.stopImmediatePropagation();
            draggedSectionId = Number(sectionHandle.getAttribute('data-section-drag-handle') || 0);

            clearCanvasDropTargets();
            body.classList.add(
                'sb-dnd2-active',
                'sb-dnd2-section-dragging'
            );

            var sectionNode = sectionHandle.closest('.sb-editor-section-preview');
            if (sectionNode) {
                sectionNode.classList.add('is-dragging');
                sectionNode.setAttribute('aria-grabbed', 'true');
            }

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', 'section:' + draggedSectionId);

                dnd2SetDragImage(
                    event,
                    dnd2SectionName(draggedSectionId),
                    'Перемещение секции'
                );
            }
        }
    }, true);

    document.addEventListener('dragover', function (event) {
        var pageRow = event.target.closest('[data-page-tree-row]');
        if (draggedPageId > 0 && pageRow) {
            event.preventDefault();
            clearPageDropClasses();
            var position = pageDropPosition(pageRow, event.clientY);
            pageRow.classList.add('is-drop-' + position);
            pageRow.setAttribute('data-current-drop-position', position);
            if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
            return;
        }

        if (draggedBlockId > 0) {
            var blockSlot = dnd2ResolveBlockSlot(event);

            if (blockSlot) {
                event.preventDefault();
                event.stopImmediatePropagation();

                dnd2AutoScroll(event.clientY);
                dnd2MarkBlockTarget(
                    blockSlot,
                    event
                );

                var currentBlock = blocksList
                    && blocksList.querySelector(
                        '.sb-editor-block[data-block-id="'
                        + draggedBlockId
                        + '"]'
                    );

                if (currentBlock) {
                    currentBlock.classList.add(
                        'is-dragging'
                    );
                }

                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'move';
                }
                return;
            }
        }

        if (draggedSectionId > 0) {
            var sectionSlot = dnd2ResolveSectionSlot(
                event
            );

            if (sectionSlot) {
                event.preventDefault();
                event.stopImmediatePropagation();

                dnd2AutoScroll(event.clientY);
                dnd2MarkSectionTarget(
                    sectionSlot,
                    event
                );

                var currentSection = blocksList
                    && blocksList.querySelector(
                        '[data-editor-section-id="'
                        + draggedSectionId
                        + '"]'
                    );

                if (currentSection) {
                    currentSection.classList.add(
                        'is-dragging'
                    );
                }

                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'move';
                }
            }
        }
    }, true);

    document.addEventListener('drop', function (event) {
        var pageRow = event.target.closest('[data-page-tree-row]');
        if (draggedPageId > 0 && pageRow) {
            event.preventDefault();
            event.stopImmediatePropagation();
            var targetId = Number(pageRow.getAttribute('data-page-tree-row') || 0);
            var position = String(pageRow.getAttribute('data-current-drop-position') || pageDropPosition(pageRow, event.clientY));
            clearPageDropClasses();
            var pageId = draggedPageId;
            draggedPageId = 0;
            if (pageId === targetId) return;
            api('page.reorderTree', {
                id: pageId,
                targetId: targetId,
                position: position,
                expectedVersions: JSON.stringify(buildVersionMap(state.pages))
            }).then(async function () {
                await loadPages();
                state.currentPageId = pageId;
                renderPages17();
                if (typeof showEditorToast === 'function') showEditorToast('Страница перемещена', 'success');
            }).catch(function (error) {
                console.error(error);
                if (typeof showEditorToast === 'function') showEditorToast('Не удалось переместить страницу', 'error');
            });
            return;
        }

        if (draggedBlockId > 0) {
            var blockSlot = dnd2ResolveBlockSlot(event);

            if (blockSlot) {
                event.preventDefault();
                event.stopImmediatePropagation();

                var blockId = draggedBlockId;
                var sectionId = Number(
                    blockSlot.getAttribute(
                        'data-section-id'
                    ) || 0
                );
                var column = Number(
                    blockSlot.getAttribute(
                        'data-column'
                    ) || 1
                );
                var beforeBlockId = Number(
                    blockSlot.getAttribute(
                        'data-before-block-id'
                    ) || 0
                );
                var noop = dnd2BlockSlotIsNoop(
                    blockId,
                    sectionId,
                    column,
                    beforeBlockId
                );

                draggedBlockId = 0;
                state.draggedBlockId = 0;
                clearCanvasDragClasses();

                if (noop) {
                    state.currentBlockId = blockId;
                    state.currentSectionId = sectionId;
                    state.currentColumn = column;

                    if (typeof setInspectorTab === 'function') {
                        setInspectorTab('block');
                    }
                    return;
                }

                moveBlockToSlot(
                    blockId,
                    sectionId,
                    column,
                    beforeBlockId
                ).catch(function (error) {
                    console.error(error);
                    if (typeof showEditorToast === 'function') {
                        showEditorToast(
                            'Не удалось переместить блок',
                            'error'
                        );
                    }
                });
                return;
            }
        }

        if (draggedSectionId > 0) {
            var sectionSlot = dnd2ResolveSectionSlot(
                event
            );

            if (sectionSlot) {
                event.preventDefault();
                event.stopImmediatePropagation();

                var sectionIdToMove = draggedSectionId;
                draggedSectionId = 0;

                var beforeSectionId = Number(
                    sectionSlot.getAttribute(
                        'data-before-section-id'
                    ) || 0
                );

                clearCanvasDragClasses();

                if (
                    sectionIdToMove
                    === beforeSectionId
                ) {
                    return;
                }

                reorderSections(
                    sectionIdToMove,
                    beforeSectionId
                ).catch(function (error) {
                    console.error(error);
                    if (typeof showEditorToast === 'function') {
                        showEditorToast(
                            'Не удалось переместить секцию',
                            'error'
                        );
                    }
                });
            }
        }
    }, true);

    document.addEventListener('dragend', function () {
        draggedPageId = 0;
        draggedBlockId = 0;
        draggedSectionId = 0;
        state.draggedBlockId = 0;
        clearPageDropClasses();
        clearCanvasDragClasses();
    }, true);

    document.addEventListener('focusin', function (event) {
        var inline = event.target.closest && event.target.closest('[data-inline-block-id]');
        if (inline) {
            var inlineBlockId = Number(inline.getAttribute('data-inline-block-id') || 0);
            if (inlineBlockId > 0 && inlineBlockId !== Number(state.currentBlockId || 0)) {
                selectBlockWithoutLosingCaret(inlineBlockId);
            }
        }
        var kind = targetDirtyKind(event.target);
        if (kind && !applyingHistory) {
            historyLastSnapshot = captureSnapshot();
            mutationBurst = false;
        }
    }, true);

    document.addEventListener('input', function (event) {
        var editable = event.target.closest && event.target.closest('[data-inline-block-id]');
        if (editable) {
            inlineDraftFromElement(editable);
            beginMutation('block');
            return;
        }
        var kind = targetDirtyKind(event.target);
        if (kind) beginMutation(kind);
    }, true);

    document.addEventListener('change', function (event) {
        var kind = targetDirtyKind(event.target);
        if (kind) beginMutation(kind);
        if (event.target.closest && event.target.closest('[data-section-field]')) {
            /* Stage 17 keeps section changes local until explicit Save/Ctrl+S. */
            event.stopImmediatePropagation();
        }
    }, true);

    document.addEventListener('keydown', function (event) {
        var key = String(event.key || '').toLowerCase();
        var command = event.ctrlKey || event.metaKey;

        if (event.target && event.target.closest && event.target.closest('[data-inline-block-id][data-inline-rich="false"]') && event.key === 'Enter') {
            event.preventDefault();
            event.target.blur();
            return;
        }

        if (command && key === 'z' && !isTextEditingTarget(event.target)) {
            event.preventDefault();
            if (event.shiftKey) redoEditor(); else undoEditor();
            return;
        }

        if (command && key === 'y' && !isTextEditingTarget(event.target)) {
            event.preventDefault();
            redoEditor();
            return;
        }

        if (event.key === 'Escape') {
            closePageContextMenu();
            var toolbar = document.getElementById('inlineTextToolbar');
            if (toolbar) toolbar.hidden = true;
        }
    }, true);

    document.addEventListener('paste', function (event) {
        var editable = event.target.closest && event.target.closest('[data-inline-rich="false"]');
        if (!editable) return;
        event.preventDefault();
        var text = (event.clipboardData || window.clipboardData).getData('text/plain');
        document.execCommand('insertText', false, text);
    }, true);

    window.addEventListener('beforeunload', function (event) {
        if (!anyDirty()) return;
        event.preventDefault();
        event.returnValue = '';
    });

    window.addEventListener('resize', closePageContextMenu);
    window.addEventListener('scroll', closePageContextMenu, true);

    /* -----------------------------------------------------
       Miscellaneous UI state
       ----------------------------------------------------- */

    function updateEditorContextSelection() {
        var node = document.getElementById('editorContextSelection');
        if (!node) return;
        var text = node.querySelector('span:last-child');
        if (!text) return;
        var page = getCurrentPage();
        var block = getCurrentBlock();
        var section = sectionById(state.currentSectionId);
        if (!page) {
            text.textContent = 'Выберите страницу';
            return;
        }
        var label = page.title || ('Страница #' + page.id);
        if (section) label += ' / ' + (section.title || ('Секция #' + section.id));
        if (block) label += ' / ' + ((typeof blockTypeMeta === 'function' ? blockTypeMeta(block.type).title : block.type) || 'Блок');
        text.textContent = label;
    }

    function installHistoryControls() {
        var undo = document.getElementById('undoEditorBtn');
        var redo = document.getElementById('redoEditorBtn');
        if (undo) undo.addEventListener('click', undoEditor);
        if (redo) redo.addEventListener('click', redoEditor);
        updateHistoryButtons();
    }

    function installDraftControls() {
        var restore = document.getElementById('restoreDraftBtn');
        var discard = document.getElementById('discardDraftBtn');
        if (restore) {
            restore.addEventListener('click', function () {
                if (!pendingDraft || !pendingDraft.snapshot) return;
                applySnapshot(pendingDraft.snapshot);
                var banner = document.getElementById('draftRecoveryBanner');
                if (banner) banner.hidden = true;
                pendingDraft = null;
                if (typeof showEditorToast === 'function') showEditorToast('Локальные изменения восстановлены', 'success');
            });
        }
        if (discard) {
            discard.addEventListener('click', function () {
                discardLocalDraft(state.currentPageId);
                if (typeof showEditorToast === 'function') showEditorToast('Локальная копия удалена', 'info');
            });
        }
    }

    function enhanceInspectorForMobile() {
        document.querySelectorAll('[data-inspector-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                if (window.matchMedia && window.matchMedia('(max-width: 980px)').matches) {
                    uiState.rightCollapsed = false;
                    applyUiState();
                }
            });
        });
    }

    applyUiState();
    installPanelControls();
    installInlineToolbar();
    installHistoryControls();
    installDraftControls();
    installSaveWrappers();
    enhanceInspectorForMobile();

    window.SBEditorUX = {
        selectPage: selectPage17,
        renderPages: renderPages17,
        renderBlocks: renderBlocks17,
        undo: undoEditor,
        redo: redoEditor,
        checkDraft: checkLocalDraft,
        uiState: uiState
    };
})();
