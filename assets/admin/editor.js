(function () {
    var config = window.SB_EDITOR_CONFIG || {};

    var BASE_PATH = config.basePath || '';
    var API_URL = config.apiUrl || (BASE_PATH + '/api.php');
    var siteId = Number(config.siteId || 0);
    var IS_BITRIX_ADMIN = !!config.isBitrixAdmin;

    var state = {
        site: null,
        pages: [],
        currentPageId: 0,
        blocks: [],
        currentBlockId: 0,
        pageSections: [],
        currentSectionId: 0,
        currentColumn: 1,
        accessItems: [],
        userSearchResults: [],
        selectedAccessUser: null,
        userSearchTimer: null
    };

    var output = document.getElementById('output') || document.getElementById('outputFallback');
    var pagesList = document.getElementById('pagesList');
    var blocksList = document.getElementById('blocksList');
    var newPageParentId = document.getElementById('newPageParentId');

    function print(data) {
        if (!output) return;

        try {
            output.textContent = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
        } catch (e) {
            output.textContent = String(data);
        }
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function userAvatarHtml(user, className) {
        user = user || {};
        className = className || '';

        var avatar = user.avatarUrl || user.avatar || user.photoUrl || user.userAvatarUrl || '';
        var title = user.title || user.name || user.userName || '';
        var initials = 'U';

        if (title) {
            var parts = String(title).trim().split(/\s+/).filter(Boolean);

            if (parts.length === 1) {
                initials = parts[0].substring(0, 1).toUpperCase();
            } else if (parts.length >= 2) {
                initials = (parts[0].substring(0, 1) + parts[1].substring(0, 1)).toUpperCase();
            }
        }

        var size = '32px';

        if (className.indexOf('selected') !== -1) {
            size = '42px';
        }

        var wrapStyle = [
            'width:' + size,
            'height:' + size,
            'min-width:' + size,
            'max-width:' + size,
            'min-height:' + size,
            'max-height:' + size,
            'border-radius:50%',
            'overflow:hidden',
            'display:flex',
            'align-items:center',
            'justify-content:center',
            'background:#eef2ff',
            'color:#3730a3',
            'font-size:11px',
            'font-weight:700',
            'line-height:1'
        ].join(';');

        if (avatar) {
            return ''
                + '<div class="' + className + '" style="' + wrapStyle + '">'
                + '  <img src="' + escapeHtml(avatar) + '" alt="" style="width:' + size + ';height:' + size + ';min-width:' + size + ';max-width:' + size + ';min-height:' + size + ';max-height:' + size + ';object-fit:cover;display:block;">'
                + '</div>';
        }

        return ''
            + '<div class="' + className + '" style="' + wrapStyle + '">'
            + escapeHtml(initials)
            + '</div>';
    }

    function getSessid() {
        if (window.BX && typeof BX.bitrix_sessid === 'function') {
            return BX.bitrix_sessid();
        }

        return config.sessid || '';
    }

    function api(action, data) {
        return new Promise(function (resolve, reject) {
            BX.ajax({
                url: API_URL,
                method: 'POST',
                dataType: 'json',
                timeout: 60,
                data: Object.assign({
                    action: action,
                    sessid: getSessid()
                }, data || {}),
                onsuccess: function (res) {
                    print(res);

                    if (res && res.ok) {
                        resolve(res);
                    } else {
                        reject(res || {error: 'UNKNOWN'});
                    }
                },
                onfailure: function (err) {
                    print({
                        ok: false,
                        error: 'AJAX_ERROR',
                        detail: err
                    });

                    reject(err);
                }
            });
        });
    }

    function apiData(res) {
        return res && res.data ? res.data : res;
    }

    function getInputValue(id) {
        var el = document.getElementById(id);
        return el ? String(el.value || '') : '';
    }

    function getChecked(id) {
        var el = document.getElementById(id);
        return !!(el && el.checked);
    }

    function getCurrentPage() {
        return state.pages.find(function (page) {
            return Number(page.id || 0) === state.currentPageId;
        }) || null;
    }

    function getCurrentBlock() {
        return state.blocks.find(function (block) {
            return Number(block.id || 0) === state.currentBlockId;
        }) || null;
    }

    function getBlockSectionId(block) {
        block = block || {};

        var props = block.props || {};
        var placement = props._placement || {};

        return Number(
            block.sectionId ||
            props.sectionId ||
            placement.sectionId ||
            0
        );
    }

    function getBlockColumn(block) {
        block = block || {};

        var props = block.props || {};
        var placement = props._placement || {};

        return Number(
            block.column ||
            props.column ||
            placement.column ||
            1
        );
    }

    function pageHasChildren(pageId) {
        return state.pages.some(function (page) {
            return Number(page.parentId || 0) === Number(pageId || 0);
        });
    }

    function buildPageTree(pages, parentId, depth, result) {
        result = result || [];
        depth = depth || 0;

        var branch = pages
            .filter(function (page) {
                return Number(page.parentId || 0) === Number(parentId || 0);
            })
            .sort(function (a, b) {
                var sortCmp = Number(a.sort || 0) - Number(b.sort || 0);
                if (sortCmp !== 0) return sortCmp;
                return Number(a.id || 0) - Number(b.id || 0);
            });

        branch.forEach(function (page) {
            result.push({
                page: page,
                depth: depth
            });

            buildPageTree(pages, Number(page.id || 0), depth + 1, result);
        });

        return result;
    }

    /* =========================================================
       PAGE SECTIONS
       ========================================================= */

    function getCurrentPageId() {
        return Number(state.currentPageId || 0);
    }

    function getDefaultSectionId() {
        if (state.currentSectionId > 0) {
            return Number(state.currentSectionId);
        }

        if (state.pageSections.length) {
            return Number(state.pageSections[0].id || 0);
        }

        return 0;
    }

    function getDefaultColumn() {
        var sectionId = getDefaultSectionId();
        var columns = getSectionColumns(sectionId);
        var column = Number(state.currentColumn || 1);

        if (column < 1) column = 1;
        if (column > columns) column = columns;

        return column;
    }

    function getSectionById(sectionId) {
        sectionId = Number(sectionId || 0);

        return state.pageSections.find(function (section) {
            return Number(section.id || 0) === sectionId;
        }) || null;
    }

    function getSectionColumns(sectionId) {
        var section = getSectionById(sectionId);

        if (!section) {
            return 1;
        }

        var layout = section.layout || {};
        var columns = Number(layout.columns || 1);

        if (columns < 1) columns = 1;
        if (columns > 4) columns = 4;

        return columns;
    }

    function setPageSectionsMessage(text, type) {
        var node = document.getElementById('pageSectionsMessage');

        if (!node) {
            return;
        }

        node.hidden = !text;
        node.textContent = text || '';
        node.className = 'sb-page-sections-message' + (type ? ' is-' + type : '');
    }

    async function loadPageSections() {
        var pageId = getCurrentPageId();
        var list = document.getElementById('pageSectionsList');

        if (!pageId) {
            state.pageSections = [];
            state.currentSectionId = 0;
            state.currentColumn = 1;

            if (list) {
                list.innerHTML = '<div class="sb-empty">Выберите страницу</div>';
            }

            return;
        }

        try {
            var res = await api('pageSection.list', {
                siteId: siteId,
                pageId: pageId
            });

            var data = apiData(res);

            state.pageSections = Array.isArray(data.sections) ? data.sections : [];

            if (!state.currentSectionId && state.pageSections.length) {
                state.currentSectionId = Number(state.pageSections[0].id || 0);
                state.currentColumn = 1;
            }

            if (
                state.currentSectionId &&
                !state.pageSections.some(function (section) {
                    return Number(section.id || 0) === Number(state.currentSectionId);
                })
            ) {
                state.currentSectionId = state.pageSections.length ? Number(state.pageSections[0].id || 0) : 0;
                state.currentColumn = 1;
            }

            if (state.currentColumn < 1) {
                state.currentColumn = 1;
            }

            if (state.currentColumn > getSectionColumns(state.currentSectionId)) {
                state.currentColumn = getSectionColumns(state.currentSectionId);
            }

            renderPageSectionsPanel();
        } catch (e) {
            console.error(e);

            if (list) {
                list.innerHTML = '<div class="sb-empty">Не удалось загрузить секции</div>';
            }

            setPageSectionsMessage('Ошибка загрузки секций: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        }
    }

    function renderPageSectionsPanel() {
        var list = document.getElementById('pageSectionsList');

        if (!list) {
            return;
        }

        if (!state.currentPageId) {
            list.innerHTML = '<div class="sb-empty">Выберите страницу</div>';
            return;
        }

        if (!state.pageSections.length) {
            list.innerHTML = '<div class="sb-empty">Секций пока нет</div>';
            return;
        }

        list.innerHTML = state.pageSections.map(function (section, index) {
            var id = Number(section.id || 0);
            var title = section.title || 'Секция';
            var layout = section.layout || {};
            var props = section.props || {};
            var columns = Number(layout.columns || 1);
            var container = String(layout.container || 'default');
            var paddingTop = Number(props.paddingTop || 0);
            var paddingBottom = Number(props.paddingBottom || 0);
            var active = Number(state.currentSectionId || 0) === id ? ' is-active' : '';

            return ''
                + '<div class="sb-page-section-card' + active + '" data-page-section-id="' + id + '">'
                + '  <div class="sb-page-section-card__top" data-page-section-select="' + id + '">'
                + '      <div class="sb-page-section-card__index">' + (index + 1) + '</div>'
                + '      <div class="sb-page-section-card__main">'
                + '          <input class="sb-page-section-card__title-input" '
                + '                 type="text" '
                + '                 value="' + escapeHtml(title) + '" '
                + '                 data-section-field="title" '
                + '                 data-section-id="' + id + '">'
                + '          <div class="sb-page-section-card__meta">'
                + '              <span>' + columns + ' кол.</span>'
                + '              <span>' + escapeHtml(container) + '</span>'
                + '              <span>' + paddingTop + '/' + paddingBottom + 'px</span>'
                + '          </div>'
                + '      </div>'
                + '  </div>'
                + ''
                + '  <div class="sb-page-section-card__settings">'
                + '      <label>'
                + '          Колонки'
                + '          <select data-section-field="columns" data-section-id="' + id + '">'
                + '              <option value="1"' + (columns === 1 ? ' selected' : '') + '>1</option>'
                + '              <option value="2"' + (columns === 2 ? ' selected' : '') + '>2</option>'
                + '              <option value="3"' + (columns === 3 ? ' selected' : '') + '>3</option>'
                + '              <option value="4"' + (columns === 4 ? ' selected' : '') + '>4</option>'
                + '          </select>'
                + '      </label>'
                + ''
                + '      <label>'
                + '          Ширина'
                + '          <select data-section-field="container" data-section-id="' + id + '">'
                + '              <option value="default"' + (container === 'default' ? ' selected' : '') + '>Обычная</option>'
                + '              <option value="wide"' + (container === 'wide' ? ' selected' : '') + '>Широкая</option>'
                + '              <option value="full"' + (container === 'full' ? ' selected' : '') + '>На всю ширину</option>'
                + '          </select>'
                + '      </label>'
                + '  </div>'
                + ''
                + '  <div class="sb-page-section-card__actions">'
                + '      <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-section-action="move-up" data-section-id="' + id + '">↑</button>'
                + '      <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-section-action="move-down" data-section-id="' + id + '">↓</button>'
                + '      <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-section-action="save" data-section-id="' + id + '">Сохранить</button>'
                + '      <button class="sb-btn sb-btn-danger sb-btn-small" type="button" data-section-action="delete" data-section-id="' + id + '">Удалить</button>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    function groupBlocksBySectionAndColumn() {
        var result = {};
        var firstSectionId = state.pageSections.length ? Number(state.pageSections[0].id || 0) : 0;

        state.pageSections.forEach(function (section) {
            var sectionId = Number(section.id || 0);
            var columns = getSectionColumns(sectionId);

            result[sectionId] = {};

            for (var i = 1; i <= columns; i++) {
                result[sectionId][i] = [];
            }
        });

        state.blocks.forEach(function (block) {
            var sectionId = getBlockSectionId(block);

            if (!sectionId || !result[sectionId]) {
                sectionId = firstSectionId;
            }

            if (!sectionId || !result[sectionId]) {
                return;
            }

            var columns = getSectionColumns(sectionId);
            var column = getBlockColumn(block);

            if (column < 1) column = 1;
            if (column > columns) column = columns;

            result[sectionId][column].push(block);
        });

        return result;
    }

    function fillBlockPlacementForm(block) {
        var sectionSelect = document.getElementById('blockSectionInput');
        var columnSelect = document.getElementById('blockColumnInput');

        if (!sectionSelect || !columnSelect) {
            return;
        }

        if (!block) {
            sectionSelect.innerHTML = '<option value="0">Нет секций</option>';
            columnSelect.innerHTML = '<option value="1">Колонка 1</option>';
            return;
        }

        if (!state.pageSections.length) {
            sectionSelect.innerHTML = '<option value="0">Нет секций</option>';
            columnSelect.innerHTML = '<option value="1">Колонка 1</option>';
            return;
        }

        var currentSectionId = getBlockSectionId(block);

        if (!currentSectionId || !getSectionById(currentSectionId)) {
            currentSectionId = getDefaultSectionId();
        }

        sectionSelect.innerHTML = state.pageSections.map(function (section) {
            var id = Number(section.id || 0);
            var title = section.title || ('Секция #' + id);

            return '<option value="' + id + '"' + (id === currentSectionId ? ' selected' : '') + '>' + escapeHtml(title) + '</option>';
        }).join('');

        var columns = getSectionColumns(currentSectionId);
        var currentColumn = getBlockColumn(block);

        if (currentColumn < 1) currentColumn = 1;
        if (currentColumn > columns) currentColumn = columns;

        var columnHtml = '';

        for (var i = 1; i <= columns; i++) {
            columnHtml += '<option value="' + i + '"' + (i === currentColumn ? ' selected' : '') + '>Колонка ' + i + '</option>';
        }

        columnSelect.innerHTML = columnHtml;
    }

    async function saveBlockPlacement(block) {
        if (!block) {
            return;
        }

        var sectionSelect = document.getElementById('blockSectionInput');
        var columnSelect = document.getElementById('blockColumnInput');

        if (!sectionSelect || !columnSelect) {
            return;
        }

        var sectionId = Number(sectionSelect.value || 0);
        var column = Number(columnSelect.value || 1);

        if (sectionId <= 0) {
            return;
        }

        await api('pageSection.assignBlock', {
            blockId: Number(block.id || 0),
            sectionId: sectionId,
            column: column
        });
    }

    async function assignBlockToSection(blockId, sectionId, column) {
        blockId = Number(blockId || 0);
        sectionId = Number(sectionId || 0);
        column = Number(column || 1);

        if (blockId <= 0 || sectionId <= 0) {
            return;
        }

        await api('pageSection.assignBlock', {
            blockId: blockId,
            sectionId: sectionId,
            column: column
        });
    }

    async function ensureUnsectionedBlocksAssigned() {
        var sectionId = getDefaultSectionId();

        if (!sectionId) {
            return;
        }

        var changed = false;

        for (var i = 0; i < state.blocks.length; i++) {
            var block = state.blocks[i];

            if (getBlockSectionId(block) > 0) {
                continue;
            }

            await assignBlockToSection(Number(block.id || 0), sectionId, 1);
            changed = true;
        }

        if (changed) {
            var res = await api('block.list', {
                pageId: state.currentPageId
            });

            state.blocks = Array.isArray(res.blocks) ? res.blocks : [];
        }
    }

    async function createPageSection() {
        var pageId = getCurrentPageId();

        if (!pageId) {
            alert('Сначала выберите страницу');
            return;
        }

        var title = prompt('Название секции', 'Новая секция');

        if (title === null) {
            return;
        }

        title = String(title || '').trim();

        if (!title) {
            title = 'Новая секция';
        }

        setPageSectionsMessage('Создаю секцию...', 'info');

        try {
            var res = await api('pageSection.create', {
                siteId: siteId,
                pageId: pageId,
                title: title,
                layout: JSON.stringify({
                    container: 'default',
                    columns: 1,
                    gap: 24
                }),
                props: JSON.stringify({
                    backgroundColor: '',
                    backgroundImage: '',
                    paddingTop: 40,
                    paddingBottom: 40,
                    minHeight: 0
                })
            });

            var data = apiData(res);

            state.pageSections = Array.isArray(data.sections) ? data.sections : [];
            state.currentSectionId = data.section && data.section.id ? Number(data.section.id) : getDefaultSectionId();
            state.currentColumn = 1;

            renderPageSectionsPanel();
            renderBlocks();

            setPageSectionsMessage('Секция создана', 'success');
        } catch (e) {
            console.error(e);
            setPageSectionsMessage('Не удалось создать секцию: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        }
    }

    async function savePageSection(sectionId) {
        sectionId = Number(sectionId || 0);

        var section = getSectionById(sectionId);

        if (!section) {
            alert('Секция не найдена');
            return;
        }

        var card = document.querySelector('[data-page-section-id="' + sectionId + '"]');

        if (!card) {
            return;
        }

        var titleInput = card.querySelector('[data-section-field="title"]');
        var columnsSelect = card.querySelector('[data-section-field="columns"]');
        var containerSelect = card.querySelector('[data-section-field="container"]');

        var title = titleInput ? String(titleInput.value || '').trim() : section.title;
        var columns = columnsSelect ? Number(columnsSelect.value || 1) : Number((section.layout || {}).columns || 1);
        var container = containerSelect ? String(containerSelect.value || 'default') : String((section.layout || {}).container || 'default');

        var layout = Object.assign({}, section.layout || {}, {
            columns: columns,
            container: container
        });

        setPageSectionsMessage('Сохраняю секцию...', 'info');

        try {
            var res = await api('pageSection.update', {
                sectionId: sectionId,
                title: title,
                layout: JSON.stringify(layout)
            });

            var data = apiData(res);

            state.pageSections = Array.isArray(data.sections) ? data.sections : [];

            if (state.currentColumn > getSectionColumns(state.currentSectionId)) {
                state.currentColumn = getSectionColumns(state.currentSectionId);
            }

            renderPageSectionsPanel();
            await loadBlocks();

            setPageSectionsMessage('Секция сохранена', 'success');
        } catch (e) {
            console.error(e);
            setPageSectionsMessage('Не удалось сохранить секцию: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        }
    }

    async function movePageSection(sectionId, dir) {
        sectionId = Number(sectionId || 0);

        if (!sectionId) {
            return;
        }

        try {
            var res = await api('pageSection.move', {
                sectionId: sectionId,
                dir: dir
            });

            var data = apiData(res);

            state.pageSections = Array.isArray(data.sections) ? data.sections : state.pageSections;

            renderPageSectionsPanel();
            renderBlocks();
        } catch (e) {
            console.error(e);
            setPageSectionsMessage('Не удалось переместить секцию: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        }
    }

    async function deletePageSection(sectionId) {
        sectionId = Number(sectionId || 0);

        if (!sectionId) {
            return;
        }

        var section = getSectionById(sectionId);
        var title = section && section.title ? section.title : 'секцию';

        if (!confirm('Удалить "' + title + '"? Компоненты будут перенесены в другую секцию.')) {
            return;
        }

        try {
            var res = await api('pageSection.delete', {
                sectionId: sectionId
            });

            var data = apiData(res);

            state.pageSections = Array.isArray(data.sections) ? data.sections : [];

            if (
                state.currentSectionId === sectionId ||
                !state.pageSections.some(function (s) {
                    return Number(s.id || 0) === Number(state.currentSectionId);
                })
            ) {
                state.currentSectionId = state.pageSections.length ? Number(state.pageSections[0].id || 0) : 0;
                state.currentColumn = 1;
            }

            renderPageSectionsPanel();
            await loadBlocks();

            setPageSectionsMessage('Секция удалена', 'success');
        } catch (e) {
            console.error(e);
            setPageSectionsMessage('Не удалось удалить секцию: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        }
    }

    async function loadSite() {
        var res = await api('site.get', {
            siteId: siteId
        });

        state.site = res.site || null;
    }

    async function loadPages() {
        var res = await api('page.list', {
            siteId: siteId
        });

        state.pages = Array.isArray(res.pages) ? res.pages : [];

        if (!state.currentPageId && state.pages.length) {
            state.currentPageId = Number(state.pages[0].id || 0);
        }

        fillParentOptions();
        renderPages();
        fillPageForm();
        updateCanvasHeader();
    }

    async function loadBlocks() {
        if (!state.currentPageId) {
            state.blocks = [];
            state.currentBlockId = 0;
            state.pageSections = [];
            state.currentSectionId = 0;
            state.currentColumn = 1;
            renderPageSectionsPanel();
            renderBlocks();
            fillBlockForm();
            return;
        }

        await loadPageSections();

        var res = await api('block.list', {
            pageId: state.currentPageId
        });

        state.blocks = Array.isArray(res.blocks) ? res.blocks : [];

        await ensureUnsectionedBlocksAssigned();

        if (state.currentBlockId) {
            var exists = state.blocks.some(function (b) {
                return Number(b.id || 0) === state.currentBlockId;
            });

            if (!exists) {
                state.currentBlockId = 0;
            }
        }

        renderBlocks();
        fillBlockForm();
        updateCanvasHeader();
    }

    function fillParentOptions() {
        var currentValue = String(newPageParentId.value || '0');
        var html = '<option value="0">Без родителя</option>';

        state.pages.forEach(function (page) {
            html += '<option value="' + Number(page.id || 0) + '">' + escapeHtml(page.title || ('Страница #' + page.id)) + '</option>';
        });

        newPageParentId.innerHTML = html;
        newPageParentId.value = currentValue;
    }

    function fillPageParentEditorOptions() {
        var select = document.getElementById('pageParentInput');
        if (!select) return;

        var currentPageId = Number(state.currentPageId || 0);
        var currentValue = String(select.value || '0');

        var html = '<option value="0">Без родителя</option>';

        state.pages.forEach(function (page) {
            var id = Number(page.id || 0);

            if (id === currentPageId) {
                return;
            }

            html += '<option value="' + id + '">' + escapeHtml(page.title || ('Страница #' + id)) + '</option>';
        });

        select.innerHTML = html;

        if (currentValue && select.querySelector('option[value="' + currentValue + '"]')) {
            select.value = currentValue;
        }
    }

    function renderPages() {
        if (!state.pages.length) {
            pagesList.innerHTML = '<div class="sb-empty">Страниц пока нет</div>';
            return;
        }

        var tree = buildPageTree(state.pages, 0, 0, []);

        pagesList.innerHTML = tree.map(function (item) {
            var page = item.page;
            var depth = item.depth;
            var active = Number(page.id || 0) === state.currentPageId ? ' is-active' : '';
            var hasChildren = pageHasChildren(page.id);
            var status = String(page.status || 'draft');

            return ''
                + '<div class="sb-editor-page-item' + active + '" data-page-id="' + Number(page.id || 0) + '" style="margin-left:' + (depth * 18) + 'px;">'
                + '  <div class="sb-editor-page-top">'
                + '      <div>'
                + '          <h3 class="sb-editor-page-title">' + escapeHtml(page.title || '') + '</h3>'
                + '          <div class="sb-editor-page-meta">'
                +               '<span class="sb-editor-chip">' + escapeHtml(page.slug || '') + '</span>'
                +               '<span class="sb-editor-chip ' + (status === 'published' ? 'sb-editor-chip--green' : 'sb-editor-chip--yellow') + '">' + escapeHtml(status) + '</span>'
                +               (hasChildren ? '<span class="sb-editor-chip sb-editor-chip--blue">section</span>' : '')
                + '          </div>'
                + '      </div>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    function updateCanvasHeader() {
        var page = getCurrentPage();
        var pageTitle = document.getElementById('canvasPageTitle');
        var pageMeta = document.getElementById('canvasPageMeta');
        var previewHeading = document.getElementById('pagePreviewHeading');

        if (!page) {
            pageTitle.textContent = 'Страница';
            pageMeta.textContent = 'Выберите страницу слева';
            previewHeading.textContent = 'Выберите страницу';
            return;
        }

        pageTitle.textContent = page.title || 'Страница';
        pageMeta.textContent = 'slug: ' + (page.slug || '') + ' · статус: ' + (page.status || 'draft') + ' · блоков: ' + state.blocks.length;
        previewHeading.textContent = page.title || 'Страница';
    }

    function blockPreviewText(block) {
        var type = String(block.type || '');
        var content = block.content || {};
        var props = block.props || {};
        var sectionId = getBlockSectionId(block);
        var column = getBlockColumn(block);

        var placementText = sectionId > 0 ? ' · секция #' + sectionId + ' · колонка ' + column : '';

        if (type === 'heading') {
            return (content.text || '[пустой заголовок]') + placementText;
        }

        if (type === 'text') {
            return (content.text || '[пустой текст]') + placementText;
        }

        if (type === 'button') {
            return (content.label || 'Кнопка') + (content.href ? ' → ' + content.href : '') + placementText;
        }

        if (type === 'html') {
            return ((content.html || '').slice(0, 220) || '[пустой HTML]') + placementText;
        }

        if (type === 'disk') {
            return 'Компонент "Диск": ' + (props.title || 'Файлы') + ' · rootMode=' + (props.rootMode || 'site') + ' · view=' + (props.viewMode || 'table') + placementText;
        }

        try {
            return JSON.stringify(content) + placementText;
        } catch (e) {
            return '[контент блока]' + placementText;
        }
    }

    function renderBlocks() {
        if (!state.currentPageId) {
            blocksList.innerHTML = ''
                + '<div class="sb-editor-empty-big">'
                + '   <strong>Страница не выбрана</strong>'
                + '   Выбери страницу слева, чтобы редактировать блоки'
                + '</div>';
            return;
        }

        if (!state.pageSections.length) {
            if (!state.blocks.length) {
                blocksList.innerHTML = ''
                    + '<div class="sb-editor-empty-big">'
                    + '   <strong>На странице пока нет блоков</strong>'
                    + '   Добавь первый блок через панель сверху'
                    + '</div>';
                return;
            }

            blocksList.innerHTML = state.blocks.map(function (block) {
                var active = Number(block.id || 0) === state.currentBlockId ? ' is-active' : '';

                return ''
                    + '<div class="sb-editor-block' + active + '" data-block-id="' + Number(block.id || 0) + '">'
                    + '  <div class="sb-editor-block-head">'
                    + '      <div>'
                    + '          <h3 class="sb-editor-block-title">' + escapeHtml(block.type || 'block') + '</h3>'
                    + '          <div class="sb-editor-chip">block #' + Number(block.id || 0) + '</div>'
                    + '      </div>'
                    + '  </div>'
                    + '  <div class="sb-editor-block-preview">' + escapeHtml(blockPreviewText(block)) + '</div>'
                    + '</div>';
            }).join('');

            return;
        }

        var grouped = groupBlocksBySectionAndColumn();

        blocksList.innerHTML = state.pageSections.map(function (section) {
            var sectionId = Number(section.id || 0);
            var layout = section.layout || {};
            var columns = getSectionColumns(sectionId);
            var activeSection = Number(state.currentSectionId || 0) === sectionId ? ' is-active' : '';

            var html = ''
                + '<div class="sb-editor-section-preview' + activeSection + '" data-editor-section-id="' + sectionId + '">'
                + '  <div class="sb-editor-section-preview__head" data-page-section-select="' + sectionId + '">'
                + '      <div>'
                + '          <h3 class="sb-editor-section-preview__title">' + escapeHtml(section.title || 'Секция') + '</h3>'
                + '          <div class="sb-editor-section-preview__meta">'
                + '              <span>' + columns + ' кол.</span>'
                + '              <span>' + escapeHtml(layout.container || 'default') + '</span>'
                + '          </div>'
                + '      </div>'
                + '      <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-add-block-to-section="' + sectionId + '">Выбрать</button>'
                + '  </div>'
                + '  <div class="sb-editor-section-preview__grid sb-editor-section-preview__grid--' + columns + '">';

            for (var column = 1; column <= columns; column++) {
                var blocks = grouped[sectionId] && grouped[sectionId][column]
                    ? grouped[sectionId][column]
                    : [];

                var isTargetColumn =
                    Number(state.currentSectionId || 0) === sectionId &&
                    Number(state.currentColumn || 1) === column;

                html += ''
                    + '<div class="sb-editor-section-preview__column' + (isTargetColumn ? ' is-target' : '') + '" data-section-id="' + sectionId + '" data-column="' + column + '">'
                    + '  <div class="sb-editor-section-preview__column-head">'
                    + '      <div class="sb-editor-section-preview__column-title">Колонка ' + column + '</div>'
                    + '      <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-set-add-target="' + sectionId + '" data-column="' + column + '">'
                    +          (isTargetColumn ? 'Выбрано' : 'Добавлять сюда')
                    + '      </button>'
                    + '  </div>';

                if (!blocks.length) {
                    html += '<div class="sb-editor-section-preview__empty">Пусто</div>';
                } else {
                    html += blocks.map(function (block) {
                        var active = Number(block.id || 0) === state.currentBlockId ? ' is-active' : '';

                        return ''
                            + '<div class="sb-editor-block' + active + '" data-block-id="' + Number(block.id || 0) + '">'
                            + '  <div class="sb-editor-block-head">'
                            + '      <div>'
                            + '          <h3 class="sb-editor-block-title">' + escapeHtml(block.type || 'block') + '</h3>'
                            + '          <div class="sb-editor-chip">block #' + Number(block.id || 0) + '</div>'
                            + '      </div>'
                            + '  </div>'
                            + '  <div class="sb-editor-block-preview">' + escapeHtml(blockPreviewText(block)) + '</div>'
                            + '</div>';
                    }).join('');
                }

                html += '</div>';
            }

            html += ''
                + '  </div>'
                + '</div>';

            return html;
        }).join('');
    }

    function hideAllBlockTypeForms() {
        [
            'headingBlockForm',
            'textBlockForm',
            'buttonBlockForm',
            'htmlBlockForm',
            'diskBlockForm',
            'unknownBlockForm'
        ].forEach(function (id) {
            var node = document.getElementById(id);
            if (node) {
                node.classList.remove('is-active');
                node.classList.add('sb-hidden');
            }
        });
    }

    function showBlockTypeForm(id) {
        var node = document.getElementById(id);
        if (!node) return;

        node.classList.add('is-active');
        node.classList.remove('sb-hidden');
    }

    function fillVisualBlockForm(block) {
        hideAllBlockTypeForms();

        var type = String(block.type || '');
        var content = block.content || {};

        if (type === 'heading') {
            showBlockTypeForm('headingBlockForm');
            document.getElementById('headingTextInput').value = content.text || '';
            return;
        }

        if (type === 'text') {
            showBlockTypeForm('textBlockForm');
            document.getElementById('textTextInput').value = content.text || '';
            return;
        }

        if (type === 'button') {
            showBlockTypeForm('buttonBlockForm');
            document.getElementById('buttonLabelInput').value = content.label || '';
            document.getElementById('buttonHrefInput').value = content.href || '';
            document.getElementById('buttonTargetInput').value = content.target || '_self';
            return;
        }

        if (type === 'html') {
            showBlockTypeForm('htmlBlockForm');
            document.getElementById('htmlInput').value = content.html || '';
            return;
        }

        if (type === 'disk') {
            showBlockTypeForm('diskBlockForm');
            return;
        }

        showBlockTypeForm('unknownBlockForm');

        var jsonFields = document.getElementById('blockJsonFields');
        if (jsonFields) {
            jsonFields.classList.add('is-open');
        }
    }

    function fillDiskForm(props) {
        props = props || {};

        document.getElementById('diskTitleInput').value = props.title || 'Файлы';
        document.getElementById('diskRootModeInput').value = props.rootMode || 'site';
        document.getElementById('diskViewModeInput').value = props.viewMode || 'table';
        document.getElementById('diskPermissionModeInput').value = props.permissionMode || 'inherit_site';
        document.getElementById('diskMaxFileSizeInput').value = props.maxFileSize || 52428800;
        document.getElementById('diskAllowedExtensionsInput').value = Array.isArray(props.allowedExtensions) ? props.allowedExtensions.join(' ') : '';

        document.getElementById('diskAllowUploadInput').checked = !!props.allowUpload;
        document.getElementById('diskAllowCreateFolderInput').checked = !!props.allowCreateFolder;
        document.getElementById('diskAllowRenameInput').checked = !!props.allowRename;
        document.getElementById('diskAllowDeleteInput').checked = !!props.allowDelete;
        document.getElementById('diskAllowDownloadInput').checked = !!props.allowDownload;
        document.getElementById('diskShowSearchInput').checked = !!props.showSearch;
        document.getElementById('diskShowBreadcrumbsInput').checked = !!props.showBreadcrumbs;
        document.getElementById('diskUseSiteRootFallbackInput').checked = !!props.useSiteRootFallback;
    }

    function fillBlockForm() {
        var block = getCurrentBlock();
        var emptyNode = document.getElementById('blockInspectorEmpty');
        var formNode = document.getElementById('blockInspector');

        if (!block) {
            emptyNode.classList.remove('sb-hidden');
            formNode.classList.add('sb-hidden');

            hideAllBlockTypeForms();

            document.getElementById('blockTypeInput').value = '';
            document.getElementById('blockContentInput').value = '';
            document.getElementById('blockPropsInput').value = '';

            var jsonFieldsEmpty = document.getElementById('blockJsonFields');
            if (jsonFieldsEmpty) {
                jsonFieldsEmpty.classList.remove('is-open');
            }

            fillBlockPlacementForm(null);

            return;
        }

        emptyNode.classList.add('sb-hidden');
        formNode.classList.remove('sb-hidden');

        var content = block.content || {};
        var props = block.props || {};

        document.getElementById('blockTypeInput').value = block.type || '';
        document.getElementById('blockContentInput').value = JSON.stringify(content, null, 2);
        document.getElementById('blockPropsInput').value = JSON.stringify(props, null, 2);

        var jsonFields = document.getElementById('blockJsonFields');
        if (jsonFields) {
            jsonFields.classList.remove('is-open');
        }

        if (block.type === 'disk') {
            fillDiskForm(props);
        }

        fillVisualBlockForm(block);
        fillBlockPlacementForm(block);
    }

    function fillPageForm() {
        var page = getCurrentPage();

        fillPageParentEditorOptions();

        document.getElementById('pageTitleInput').value = page ? (page.title || '') : '';
        document.getElementById('pageSlugInput').value = page ? (page.slug || '') : '';
        document.getElementById('pageStatusInput').value = page ? (page.status || 'draft') : 'draft';

        var parentSelect = document.getElementById('pageParentInput');
        if (parentSelect) {
            parentSelect.value = page ? String(page.parentId || 0) : '0';
        }
    }

    function collectDiskBlockProps(block) {
        var oldProps = block.props || {};

        return {
            title: getInputValue('diskTitleInput').trim() || 'Файлы',
            rootMode: getInputValue('diskRootModeInput') || 'site',
            rootFolderId: oldProps.rootFolderId || null,
            viewMode: getInputValue('diskViewModeInput') || 'table',
            permissionMode: getInputValue('diskPermissionModeInput') || 'inherit_site',
            maxFileSize: Number(getInputValue('diskMaxFileSizeInput') || 0),
            allowedExtensions: String(getInputValue('diskAllowedExtensionsInput') || '')
                .trim()
                .split(/\s+/)
                .filter(Boolean),
            allowUpload: getChecked('diskAllowUploadInput'),
            allowCreateFolder: getChecked('diskAllowCreateFolderInput'),
            allowRename: getChecked('diskAllowRenameInput'),
            allowDelete: getChecked('diskAllowDeleteInput'),
            allowDownload: getChecked('diskAllowDownloadInput'),
            showSearch: getChecked('diskShowSearchInput'),
            showBreadcrumbs: getChecked('diskShowBreadcrumbsInput'),
            useSiteRootFallback: getChecked('diskUseSiteRootFallbackInput'),
            defaultSort: oldProps.defaultSort || 'updatedAt',
            defaultSortDirection: oldProps.defaultSortDirection || 'desc',

            sectionId: oldProps.sectionId || null,
            column: oldProps.column || null,
            _placement: oldProps._placement || null
        };
    }

    function collectVisualBlockData(block) {
        var type = String(block.type || '');
        var content = {};
        var props = block.props || {};

        if (type === 'heading') {
            return {
                content: {
                    text: getInputValue('headingTextInput').trim()
                },
                props: props
            };
        }

        if (type === 'text') {
            return {
                content: {
                    text: getInputValue('textTextInput')
                },
                props: props
            };
        }

        if (type === 'button') {
            return {
                content: {
                    label: getInputValue('buttonLabelInput').trim() || 'Кнопка',
                    href: getInputValue('buttonHrefInput').trim() || '#',
                    target: getInputValue('buttonTargetInput') || '_self'
                },
                props: props
            };
        }

        if (type === 'html') {
            return {
                content: {
                    html: getInputValue('htmlInput')
                },
                props: props
            };
        }

        if (type === 'disk') {
            return {
                content: block.content || {},
                props: collectDiskBlockProps(block)
            };
        }

        try {
            content = JSON.parse(document.getElementById('blockContentInput').value || '{}');
        } catch (e) {
            alert('Контент блока должен быть валидным JSON');
            return null;
        }

        try {
            props = JSON.parse(document.getElementById('blockPropsInput').value || '{}');
        } catch (e) {
            alert('Свойства блока должны быть валидным JSON');
            return null;
        }

        return {
            content: content,
            props: props
        };
    }

    async function createPage() {
        var title = getInputValue('newPageTitle').trim();
        var slug = getInputValue('newPageSlug').trim();
        var parentId = Number(getInputValue('newPageParentId') || 0);

        if (!title) {
            alert('Введите название страницы');
            document.getElementById('newPageTitle').focus();
            return;
        }

        await api('page.create', {
            siteId: siteId,
            title: title,
            slug: slug,
            parentId: parentId
        });

        document.getElementById('newPageTitle').value = '';
        document.getElementById('newPageSlug').value = '';
        document.getElementById('newPageParentId').value = '0';

        await loadPages();
        await loadBlocks();
    }

    async function savePage() {
        if (!state.currentPageId) return;

        var parentId = Number(getInputValue('pageParentInput') || 0);

        await api('page.updateMeta', {
            id: state.currentPageId,
            title: getInputValue('pageTitleInput').trim(),
            slug: getInputValue('pageSlugInput').trim(),
            parentId: parentId
        });

        await api('page.setStatus', {
            id: state.currentPageId,
            status: getInputValue('pageStatusInput')
        });

        await loadPages();
        await loadBlocks();
    }

    async function deletePage() {
        if (!state.currentPageId) return;
        if (!confirm('Удалить страницу? Дочерние страницы и блоки этой страницы тоже будут удалены.')) return;

        var idToDelete = state.currentPageId;

        await api('page.delete', {
            id: idToDelete
        });

        if (state.currentPageId === idToDelete) {
            state.currentPageId = 0;
            state.currentSectionId = 0;
            state.currentColumn = 1;
        }

        await loadPages();
        await loadBlocks();
    }

    async function movePage(dir) {
        if (!state.currentPageId) return;

        await api('page.move', {
            id: state.currentPageId,
            dir: dir
        });

        await loadPages();
    }

    async function createBlock(type) {
        if (!state.currentPageId) {
            alert('Сначала выберите страницу');
            return;
        }

        var content = {};
        var props = {};

        if (type === 'heading') {
            content = {text: 'Новый заголовок'};
        } else if (type === 'text') {
            content = {text: 'Новый текстовый блок'};
        } else if (type === 'button') {
            content = {
                label: 'Кнопка',
                href: '#',
                target: '_self'
            };
        } else if (type === 'html') {
            content = {html: '<div>Новый HTML блок</div>'};
        } else if (type === 'disk') {
            content = {};
            props = {
                title: 'Файлы',
                rootMode: 'site',
                rootFolderId: null,
                viewMode: 'table',
                allowUpload: true,
                allowCreateFolder: true,
                allowRename: true,
                allowDelete: true,
                allowDownload: true,
                showSearch: true,
                showBreadcrumbs: true,
                defaultSort: 'updatedAt',
                defaultSortDirection: 'desc',
                allowedExtensions: [],
                maxFileSize: 52428800,
                permissionMode: 'inherit_site',
                useSiteRootFallback: true
            };
        }

        var targetSectionId = getDefaultSectionId();
        var targetColumn = getDefaultColumn();

        props.sectionId = targetSectionId;
        props.column = targetColumn;
        props._placement = {
            sectionId: targetSectionId,
            column: targetColumn
        };

        var createRes = await api('block.create', {
            pageId: state.currentPageId,
            type: type,
            content: JSON.stringify(content),
            props: JSON.stringify(props),
            sectionId: targetSectionId,
            column: targetColumn
        });

        await loadBlocks();

        var createdBlockId = Number(
            (createRes.block && createRes.block.id) ||
            (createRes.data && createRes.data.block && createRes.data.block.id) ||
            0
        );

        if (!createdBlockId && state.blocks.length) {
            var sortedBlocks = state.blocks.slice().sort(function (a, b) {
                return Number(b.id || 0) - Number(a.id || 0);
            });

            createdBlockId = Number(sortedBlocks[0].id || 0);
        }

        if (createdBlockId > 0 && targetSectionId > 0) {
            await assignBlockToSection(createdBlockId, targetSectionId, targetColumn);
            state.currentBlockId = createdBlockId;
            await loadBlocks();
        }
    }

    async function saveBlock() {
        var block = getCurrentBlock();
        if (!block) return;

        var collected = collectVisualBlockData(block);

        if (!collected) {
            return;
        }

        await api('block.update', {
            id: block.id,
            content: JSON.stringify(collected.content),
            props: JSON.stringify(collected.props)
        });

        await saveBlockPlacement(block);

        await loadBlocks();
    }

    async function duplicateBlock() {
        var block = getCurrentBlock();
        if (!block) return;

        await api('block.duplicate', {
            id: block.id
        });

        await loadBlocks();
    }

    async function deleteBlock() {
        var block = getCurrentBlock();
        if (!block) return;
        if (!confirm('Удалить блок?')) return;

        await api('block.delete', {
            id: block.id
        });

        state.currentBlockId = 0;
        await loadBlocks();
    }

    async function moveBlock(dir) {
        var block = getCurrentBlock();
        if (!block) return;

        await api('block.move', {
            id: block.id,
            dir: dir
        });

        await loadBlocks();
    }

    function setManagementPanelsVisible(canManage) {
        var groupPanel = document.getElementById('siteGroupPanel');
        var accessPanel = document.getElementById('siteAccessPanel');
        var apiPanel = document.getElementById('apiOutputPanel');
        var deleteSiteBtn = document.getElementById('deleteSiteBtn');

        if (groupPanel) {
            groupPanel.hidden = !canManage;
        }

        if (accessPanel) {
            accessPanel.hidden = !canManage;
        }

        if (apiPanel) {
            apiPanel.hidden = !canManage;
        }

        if (deleteSiteBtn) {
            var role = state.site && state.site.currentUserRole
                ? String(state.site.currentUserRole)
                : '';

            var canDeleteSite = IS_BITRIX_ADMIN || role === 'OWNER' || canManage;

            deleteSiteBtn.classList.toggle('sb-hidden', !canDeleteSite);
        }
    }

    function renderBitrixGroupPanel() {
        var site = state.site || {};
        var groupId = Number(site.bitrixGroupId || 0);
        var node = document.getElementById('bitrixGroupInfo');

        if (!node) return;

        if (groupId > 0) {
            node.innerHTML = ''
                + '<div><strong>Группа создана</strong></div>'
                + '<div class="sb-muted">ID группы: ' + groupId + '</div>'
                + '<div style="margin-top:8px;">'
                + '  <a class="sb-btn sb-btn-light sb-btn-small" target="_blank" href="/workgroups/group/' + groupId + '/">Открыть группу</a>'
                + '</div>';

            return;
        }

        node.innerHTML = ''
            + '<div><strong>Группа Битрикс24 не создана</strong></div>'
            + '<div class="sb-muted">Можно создать группу и затем синхронизировать права.</div>';
    }

    async function ensureBitrixGroup() {
        var resultNode = document.getElementById('syncAccessResult');

        try {
            var res = await api('site.ensureGroup', {
                siteId: siteId
            });

            state.site = res.site || state.site;

            renderBitrixGroupPanel();

            if (resultNode) {
                resultNode.textContent = JSON.stringify(res, null, 2);
            }
        } catch (e) {
            if (resultNode) {
                resultNode.textContent = JSON.stringify(e, null, 2);
            }
        }
    }

    async function syncAccess() {
        var resultNode = document.getElementById('syncAccessResult');

        try {
            var res = await api('site.syncAccess', {
                siteId: siteId
            });

            if (resultNode) {
                resultNode.textContent = JSON.stringify(res, null, 2);
            }

            await loadAccessList();
        } catch (e) {
            if (resultNode) {
                resultNode.textContent = JSON.stringify(e, null, 2);
            }
        }
    }

    function setAccessMessage(message, type) {
        var node = document.getElementById('accessMessage');
        if (!node) return;

        node.classList.remove('sb-hidden', 'is-success', 'is-error');

        if (type === 'success') {
            node.classList.add('is-success');
        }

        if (type === 'error') {
            node.classList.add('is-error');
        }

        node.textContent = message || '';
    }

    function hideAccessMessage() {
        var node = document.getElementById('accessMessage');
        if (!node) return;

        node.classList.add('sb-hidden');
        node.textContent = '';
    }

    function renderAccessUserSearchResults(users) {
        var results = document.getElementById('accessUserSearchResults');
        if (!results) return;

        state.userSearchResults = Array.isArray(users) ? users : [];

        if (!state.userSearchResults.length) {
            results.innerHTML = '';
            results.classList.add('sb-hidden');
            return;
        }

        results.innerHTML = state.userSearchResults.map(function (user) {
            var id = Number(user.id || 0);
            var title = user.title || user.name || ('Пользователь #' + id);
            var meta = [];

            if (user.login) meta.push(user.login);
            if (user.email) meta.push(user.email);

            return ''
                + '<button class="sb-access-result-item" type="button" data-select-access-user="' + id + '" style="display:grid;grid-template-columns:32px minmax(0,1fr);gap:10px;align-items:center;width:100%;min-height:44px;padding:7px 10px;box-sizing:border-box;">'
                +      userAvatarHtml(user, 'sb-access-result-avatar')
                + '  <div class="sb-access-result-body" style="min-width:0;overflow:hidden;">'
                + '      <div class="sb-access-result-title" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(title) + '</div>'
                + '      <div class="sb-access-result-meta" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">ID: ' + id + (meta.length ? ' · ' + escapeHtml(meta.join(' · ')) : '') + '</div>'
                + '  </div>'
                + '</button>';
        }).join('');

        results.classList.remove('sb-hidden');
    }

    function renderSelectedAccessUser() {
        var selectedNode = document.getElementById('accessSelectedUser');
        if (!selectedNode) return;

        var user = state.selectedAccessUser;

        if (!user) {
            selectedNode.innerHTML = '';
            selectedNode.classList.add('sb-hidden');
            return;
        }

        var userId = Number(user.id || 0);
        var meta = [];

        if (user.login) meta.push(user.login);
        if (user.email) meta.push(user.email);

        selectedNode.innerHTML = ''
            + '<div class="sb-access-selected-user">'
            +      userAvatarHtml(user, 'sb-access-selected-avatar')
            + '  <div class="sb-access-selected-body">'
            + '      <div class="sb-access-selected-title">' + escapeHtml(user.title || user.name || ('Пользователь #' + userId)) + '</div>'
            + '      <div class="sb-access-selected-meta">ID: ' + userId + (meta.length ? ' · ' + escapeHtml(meta.join(' · ')) : '') + '</div>'
            + '  </div>'
            + '  <div class="sb-access-selected-actions">'
            + '      <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-clear-access-user>Сбросить</button>'
            + '  </div>'
            + '</div>';

        selectedNode.classList.remove('sb-hidden');
    }

    async function searchAccessUsers() {
        var input = document.getElementById('accessUserSearchInput');
        if (!input) return;

        var query = String(input.value || '').trim();

        state.selectedAccessUser = null;
        renderSelectedAccessUser();

        if (query === '') {
            renderAccessUserSearchResults([]);
            return;
        }

        if (!/^\d+$/.test(query) && query.length < 2) {
            renderAccessUserSearchResults([]);
            return;
        }

        try {
            var res = await api('user.search', {
                siteId: siteId,
                query: query,
                limit: 10
            });

            renderAccessUserSearchResults(Array.isArray(res.users) ? res.users : []);
        } catch (e) {
            renderAccessUserSearchResults([]);
        }
    }

    function selectAccessUser(user) {
        state.selectedAccessUser = user || null;

        var input = document.getElementById('accessUserSearchInput');
        if (input && user) {
            input.value = user.title || user.name || '';
        }

        renderAccessUserSearchResults([]);
        renderSelectedAccessUser();
    }

    function clearSelectedAccessUser() {
        state.selectedAccessUser = null;

        var input = document.getElementById('accessUserSearchInput');
        if (input) {
            input.value = '';
            input.focus();
        }

        renderSelectedAccessUser();
        renderAccessUserSearchResults([]);
    }

    function roleBadge(role) {
        role = String(role || 'VIEWER');

        var cls = 'sb-role-badge--viewer';

        if (role === 'OWNER') {
            cls = 'sb-role-badge--owner';
        } else if (role === 'ADMIN') {
            cls = 'sb-role-badge--admin';
        } else if (role === 'EDITOR') {
            cls = 'sb-role-badge--editor';
        }

        return '<span class="sb-role-badge ' + cls + '">' + escapeHtml(role) + '</span>';
    }

    function renderAccessList() {
        var list = document.getElementById('accessList');
        if (!list) return;

        if (!Array.isArray(state.accessItems) || !state.accessItems.length) {
            list.innerHTML = '<div class="sb-empty">Права ещё не выданы</div>';
            return;
        }

        list.innerHTML = state.accessItems.map(function (item) {
            var userId = Number(item.userId || 0);
            var name = item.userName || item.title || ('Пользователь #' + userId);
            var role = item.role || '';

            return ''
                + '<div class="sb-access-item">'
                + '  <div class="sb-access-item__main">'
                + '      <div class="sb-access-item__name">' + escapeHtml(name) + '</div>'
                + '      <div class="sb-access-item__meta">ID: ' + userId + ' · ' + escapeHtml(item.accessCode || '') + '</div>'
                + '  </div>'
                + '  <div class="sb-access-item__side">'
                +        roleBadge(role)
                + '      <button class="sb-btn sb-btn-danger sb-btn-small" type="button" data-access-remove-user="' + userId + '">Удалить</button>'
                + '  </div>'
                + '</div>';
        }).join('');
    }

    async function loadAccessList() {
        var panel = document.getElementById('siteAccessPanel');
        if (!panel) return;

        try {
            var res = await api('site.accessList', {
                siteId: siteId
            });

            state.accessItems = Array.isArray(res.items) ? res.items : [];

            setManagementPanelsVisible(true);
            renderBitrixGroupPanel();
            renderAccessList();
        } catch (e) {
            state.accessItems = [];
            setManagementPanelsVisible(false);
        }
    }

    async function grantAccessRole() {
        var roleInput = document.getElementById('accessRoleInput');
        if (!roleInput) return;

        var user = state.selectedAccessUser;
        var userId = user ? Number(user.id || 0) : 0;
        var role = String(roleInput.value || '').trim();

        if (userId <= 0) {
            setAccessMessage('Сначала найди и выбери пользователя из списка', 'error');

            var searchInput = document.getElementById('accessUserSearchInput');
            if (searchInput) {
                searchInput.focus();
            }

            return;
        }

        if (!role) {
            setAccessMessage('Выбери роль', 'error');
            return;
        }

        try {
            setAccessMessage('Сохраняю права...', '');

            var res = await api('site.accessSet', {
                siteId: siteId,
                userId: userId,
                role: role
            });

            state.accessItems = Array.isArray(res.items) ? res.items : [];

            clearSelectedAccessUser();
            renderAccessList();

            var groupSync = res.result && res.result.groupSync ? res.result.groupSync : null;
            var syncText = '';

            if (groupSync) {
                if (groupSync.ok) {
                    syncText = '\nПользователь также синхронизирован с группой Битрикс24.';
                } else if (groupSync.error) {
                    syncText = '\nНо с группой Битрикс24 не синхронизировался: ' + groupSync.error;
                } else if (groupSync.message) {
                    syncText = '\nГруппа Битрикс24: ' + groupSync.message;
                }
            }

            setAccessMessage('Роль выдана: U' + userId + ' → ' + role + syncText, 'success');
        } catch (e) {
            setAccessMessage('Ошибка выдачи роли: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        }
    }

    async function removeAccessRole(userId) {
        userId = Number(userId || 0);

        if (userId <= 0) return;

        if (!confirm('Удалить доступ пользователя #' + userId + '?')) {
            return;
        }

        try {
            hideAccessMessage();

            var res = await api('site.accessRemove', {
                siteId: siteId,
                userId: userId
            });

            state.accessItems = Array.isArray(res.items) ? res.items : [];
            renderAccessList();

            setAccessMessage('Доступ удалён', 'success');
        } catch (e) {
            setAccessMessage('Ошибка удаления доступа: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
        }
    }

    function openTemplateModal() {
        if (!IS_BITRIX_ADMIN) {
            alert('Создавать шаблоны может только администратор Битрикса');
            return;
        }

        var modal = document.getElementById('saveTemplateModal');
        if (!modal) return;

        var nameInput = document.getElementById('templateNameInput');
        var descInput = document.getElementById('templateDescriptionInput');
        var message = document.getElementById('templateMessage');

        if (nameInput && !nameInput.value) {
            var siteName = state.site && state.site.name ? state.site.name : 'Сайт';
            nameInput.value = siteName;
        }

        if (descInput && !descInput.value) {
            descInput.value = '';
        }

        if (message) {
            message.hidden = true;
            message.textContent = '';
            message.className = 'sb-template-message';
        }

        modal.hidden = false;

        setTimeout(function () {
            if (nameInput) {
                nameInput.focus();
                nameInput.select();
            }
        }, 50);
    }

    function closeTemplateModal() {
        var modal = document.getElementById('saveTemplateModal');
        if (!modal) return;

        modal.hidden = true;
    }

    function setTemplateMessage(text, type) {
        var message = document.getElementById('templateMessage');
        if (!message) return;

        message.hidden = !text;
        message.textContent = text || '';
        message.className = 'sb-template-message' + (type ? ' is-' + type : '');
    }

    async function createTemplateFromSite() {
        if (!IS_BITRIX_ADMIN) {
            alert('Создавать шаблоны может только администратор Битрикса');
            return;
        }

        var nameInput = document.getElementById('templateNameInput');
        var descInput = document.getElementById('templateDescriptionInput');
        var btn = document.getElementById('createTemplateBtn');

        var name = nameInput ? String(nameInput.value || '').trim() : '';
        var description = descInput ? String(descInput.value || '').trim() : '';

        if (!name) {
            alert('Введите название шаблона');
            if (nameInput) nameInput.focus();
            return;
        }

        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Создаю...';
        }

        setTemplateMessage('Создаю шаблон...', 'info');

        try {
            await api('template.createFromSite', {
                siteId: siteId,
                name: name,
                description: description
            });

            setTemplateMessage('Шаблон создан', 'success');

            setTimeout(function () {
                closeTemplateModal();
            }, 350);
        } catch (e) {
            var message = e && (e.message || e.error) ? (e.message || e.error) : 'UNKNOWN_ERROR';
            setTemplateMessage('Не удалось создать шаблон: ' + message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Создать шаблон';
            }
        }
    }

    async function deleteSite() {
        var siteName = state.site && state.site.name ? state.site.name : ('siteId ' + siteId);

        if (!confirm('Удалить сайт "' + siteName + '"?')) {
            return;
        }

        if (!confirm('Подтверди удаление ещё раз. Это действие нельзя отменить через интерфейс.')) {
            return;
        }

        await api('site.delete', {
            id: siteId
        });

        alert('Сайт удалён');
        window.location.href = BASE_PATH + '/index.php';
    }

    pagesList.addEventListener('click', async function (e) {
        var item = e.target.closest('[data-page-id]');
        if (!item) return;

        state.currentPageId = Number(item.getAttribute('data-page-id') || 0);
        state.currentBlockId = 0;
        state.currentSectionId = 0;
        state.currentColumn = 1;

        renderPages();
        fillPageForm();

        await loadBlocks();
    });

    blocksList.addEventListener('click', function (e) {
        var item = e.target.closest('[data-block-id]');
        if (!item) return;

        state.currentBlockId = Number(item.getAttribute('data-block-id') || 0);

        var selectedBlock = getCurrentBlock();

        if (selectedBlock) {
            var selectedSectionId = getBlockSectionId(selectedBlock);
            var selectedColumn = getBlockColumn(selectedBlock);

            if (selectedSectionId > 0) {
                state.currentSectionId = selectedSectionId;
            }

            state.currentColumn = selectedColumn > 0 ? selectedColumn : 1;
        }

        renderPageSectionsPanel();
        renderBlocks();
        fillBlockForm();
    });

    var addPageSectionBtn = document.getElementById('addPageSectionBtn');
    if (addPageSectionBtn) {
        addPageSectionBtn.addEventListener('click', createPageSection);
    }

    document.addEventListener('click', function (e) {
        var addTargetBtn = e.target.closest('[data-set-add-target]');
        if (addTargetBtn) {
            var targetSectionId = Number(addTargetBtn.getAttribute('data-set-add-target') || 0);
            var targetColumn = Number(addTargetBtn.getAttribute('data-column') || 1);

            if (targetSectionId > 0) {
                state.currentSectionId = targetSectionId;
                state.currentColumn = targetColumn > 0 ? targetColumn : 1;

                renderPageSectionsPanel();
                renderBlocks();

                setPageSectionsMessage(
                    'Новые компоненты будут добавляться в секцию #' + targetSectionId + ', колонку ' + state.currentColumn,
                    'success'
                );
            }

            return;
        }

        var selectSection = e.target.closest('[data-page-section-select], [data-add-block-to-section]');

        if (selectSection) {
            var sectionId = Number(
                selectSection.getAttribute('data-page-section-select') ||
                selectSection.getAttribute('data-add-block-to-section') ||
                0
            );

            if (sectionId > 0) {
                state.currentSectionId = sectionId;
                state.currentColumn = 1;
                renderPageSectionsPanel();
                renderBlocks();
            }

            return;
        }

        var sectionBtn = e.target.closest('[data-section-action]');

        if (!sectionBtn) {
            return;
        }

        var action = sectionBtn.getAttribute('data-section-action');
        var sectionId = Number(sectionBtn.getAttribute('data-section-id') || 0);

        if (action === 'move-up') {
            movePageSection(sectionId, 'up');
            return;
        }

        if (action === 'move-down') {
            movePageSection(sectionId, 'down');
            return;
        }

        if (action === 'save') {
            savePageSection(sectionId);
            return;
        }

        if (action === 'delete') {
            deletePageSection(sectionId);
        }
    });

    document.addEventListener('change', function (e) {
        var sectionField = e.target.closest('[data-section-field="columns"], [data-section-field="container"]');

        if (sectionField) {
            var sectionId = Number(sectionField.getAttribute('data-section-id') || 0);

            if (sectionId > 0) {
                savePageSection(sectionId);
            }

            return;
        }

        if (e.target && e.target.id === 'blockSectionInput') {
            var block = getCurrentBlock();
            var newSectionId = Number(e.target.value || 0);

            if (block) {
                state.currentSectionId = newSectionId;
                state.currentColumn = 1;

                fillBlockPlacementForm(Object.assign({}, block, {
                    sectionId: newSectionId,
                    column: 1,
                    props: Object.assign({}, block.props || {}, {
                        sectionId: newSectionId,
                        column: 1,
                        _placement: {
                            sectionId: newSectionId,
                            column: 1
                        }
                    })
                }));

                renderPageSectionsPanel();
                renderBlocks();
            }

            return;
        }

        if (e.target && e.target.id === 'blockColumnInput') {
            var columnValue = Number(e.target.value || 1);

            state.currentColumn = columnValue > 0 ? columnValue : 1;

            renderBlocks();
            return;
        }
    });

    document.getElementById('createPageBtn').addEventListener('click', createPage);
    document.getElementById('savePageBtn').addEventListener('click', savePage);
    document.getElementById('deletePageBtn').addEventListener('click', deletePage);

    document.getElementById('movePageUpBtn').addEventListener('click', function () {
        movePage('up');
    });

    document.getElementById('movePageDownBtn').addEventListener('click', function () {
        movePage('down');
    });

    document.getElementById('publishPageBtn').addEventListener('click', async function () {
        if (!state.currentPageId) return;

        await api('page.setStatus', {
            id: state.currentPageId,
            status: 'published'
        });

        await loadPages();
    });

    document.querySelectorAll('[data-add-block]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            createBlock(btn.getAttribute('data-add-block'));
        });
    });

    document.getElementById('saveBlockBtn').addEventListener('click', saveBlock);
    document.getElementById('duplicateBlockBtn').addEventListener('click', duplicateBlock);
    document.getElementById('deleteBlockBtn').addEventListener('click', deleteBlock);

    document.getElementById('moveBlockUpBtn').addEventListener('click', function () {
        moveBlock('up');
    });

    document.getElementById('moveBlockDownBtn').addEventListener('click', function () {
        moveBlock('down');
    });

    var deleteSiteBtn = document.getElementById('deleteSiteBtn');
    if (deleteSiteBtn) {
        deleteSiteBtn.addEventListener('click', deleteSite);
    }

    var saveAsTemplateBtn = document.getElementById('saveAsTemplateBtn');
    if (saveAsTemplateBtn) {
        saveAsTemplateBtn.addEventListener('click', openTemplateModal);
    }

    var createTemplateBtn = document.getElementById('createTemplateBtn');
    if (createTemplateBtn) {
        createTemplateBtn.addEventListener('click', createTemplateFromSite);
    }

    document.querySelectorAll('[data-close-template-modal]').forEach(function (btn) {
        btn.addEventListener('click', closeTemplateModal);
    });

    var syncAccessBtn = document.getElementById('syncAccessBtn');
    if (syncAccessBtn) {
        syncAccessBtn.addEventListener('click', syncAccess);
    }

    var ensureBitrixGroupBtn = document.getElementById('ensureBitrixGroupBtn');
    if (ensureBitrixGroupBtn) {
        ensureBitrixGroupBtn.addEventListener('click', ensureBitrixGroup);
    }

    var grantAccessBtn = document.getElementById('grantAccessBtn');
    if (grantAccessBtn) {
        grantAccessBtn.addEventListener('click', grantAccessRole);
    }

    var reloadAccessBtn = document.getElementById('reloadAccessBtn');
    if (reloadAccessBtn) {
        reloadAccessBtn.addEventListener('click', loadAccessList);
    }

    var accessUserSearchInput = document.getElementById('accessUserSearchInput');
    if (accessUserSearchInput) {
        accessUserSearchInput.addEventListener('input', function () {
            clearTimeout(state.userSearchTimer);

            state.userSearchTimer = setTimeout(function () {
                searchAccessUsers();
            }, 300);
        });

        accessUserSearchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();

                if (state.userSearchResults.length) {
                    selectAccessUser(state.userSearchResults[0]);
                }
            }
        });
    }

    document.addEventListener('click', function (e) {
        var selectBtn = e.target.closest('[data-select-access-user]');
        if (selectBtn) {
            var userId = Number(selectBtn.getAttribute('data-select-access-user') || 0);
            var user = (state.userSearchResults || []).find(function (item) {
                return Number(item.id || 0) === userId;
            });

            if (user) {
                selectAccessUser(user);
            }

            return;
        }

        var clearBtn = e.target.closest('[data-clear-access-user]');
        if (clearBtn) {
            clearSelectedAccessUser();
            return;
        }

        var removeBtn = e.target.closest('[data-access-remove-user]');
        if (removeBtn) {
            removeAccessRole(Number(removeBtn.getAttribute('data-access-remove-user') || 0));
            return;
        }
    });

    document.addEventListener('mousedown', function (e) {
        var wrap = e.target.closest('.sb-access-search-wrap');
        if (!wrap) {
            renderAccessUserSearchResults([]);
        }
    });

    window.onerror = function (message, source, lineno, colno, error) {
        print({
            jsError: true,
            message: message,
            source: source,
            line: lineno,
            column: colno,
            stack: error && error.stack ? error.stack : null
        });
    };

    (async function init() {
        try {
            setManagementPanelsVisible(false);

            await loadSite();
            await loadPages();
            await loadBlocks();
            await loadAccessList();
        } catch (e) {
            print(e);
            alert('Не удалось загрузить редактор');
        }
    })();
})();