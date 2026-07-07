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

            + '      <label>'
            + '          Ширина'
            + '          <select data-section-field="container" data-section-id="' + id + '">'
            + '              <option value="default"' + (container === 'default' ? ' selected' : '') + '>Обычная</option>'
            + '              <option value="wide"' + (container === 'wide' ? ' selected' : '') + '>Широкая</option>'
            + '              <option value="full"' + (container === 'full' ? ' selected' : '') + '>На всю ширину</option>'
            + '          </select>'
            + '      </label>'
            + '  </div>'

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