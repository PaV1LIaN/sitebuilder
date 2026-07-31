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


function pageSectionVersionMap() {
    var versions = {};

    state.pageSections.forEach(function (section) {
        var id = Number(section.id || 0);
        var version = Number(section.version || 1);

        if (id > 0 && version > 0) {
            versions[id] = version;
        }
    });

    return versions;
}

async function refreshSectionsAfterConflict(error) {
    if (!error || error.error !== 'VERSION_CONFLICT') {
        return false;
    }

    await loadPageSections();
    setPageSectionsMessage('Секции были изменены в другой вкладке. Загружена актуальная версия.', 'error');
    return true;
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

function normalizeEditorColor(value, fallback) {
    value = String(value || '').trim();
    return /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
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
        var tabletColumns = Math.max(1, Math.min(columns, Number(layout.tabletColumns || Math.min(columns, 2))));
        var mobileColumns = Math.max(1, Math.min(tabletColumns, Number(layout.mobileColumns || 1)));
        var container = String(layout.container || 'default');
        var gap = Number(layout.gap || 24);
        var verticalAlign = String(layout.verticalAlign || 'start');
        var paddingTop = Number(props.paddingTop == null ? 32 : props.paddingTop);
        var paddingBottom = Number(props.paddingBottom == null ? 32 : props.paddingBottom);
        var paddingX = Number(props.paddingX == null ? 24 : props.paddingX);
        var minHeight = Number(props.minHeight || 0);
        var borderRadius = Number(props.borderRadius || 0);
        var backgroundColor = normalizeEditorColor(props.backgroundColor, '#ffffff');
        var textColor = normalizeEditorColor(props.textColor, '#111827');
        var backgroundImage = String(props.backgroundImage || '');
        var backgroundPosition = String(props.backgroundPosition || 'center');
        var backgroundSize = String(props.backgroundSize || 'cover');
        var shadow = !!props.shadow;
        var active = Number(state.currentSectionId || 0) === id ? ' is-active' : '';
        var isDecorated = !!(props.backgroundColor || props.backgroundImage || borderRadius || shadow);

        return ''
            + '<div class="sb-page-section-card' + active + '" data-page-section-id="' + id + '">'
            + '  <div class="sb-page-section-card__top" data-page-section-select="' + id + '">'
            + '      <div class="sb-page-section-card__index">' + (index + 1) + '</div>'
            + '      <div class="sb-page-section-card__main">'
            + '          <input class="sb-page-section-card__title-input" type="text" value="' + escapeHtml(title) + '" data-section-field="title" data-section-id="' + id + '">'
            + '          <div class="sb-page-section-card__meta">'
            + '              <span>' + columns + ' / ' + tabletColumns + ' / ' + mobileColumns + ' кол.</span>'
            + '              <span>' + escapeHtml(container) + '</span>'
            + '              <span>' + gap + 'px</span>'
            + '              ' + (isDecorated ? '<span class="sb-section-style-dot" style="background:' + escapeHtml(backgroundColor) + '"></span>' : '')
            + '          </div>'
            + '      </div>'
            + '  </div>'
            + '  <details class="sb-section-appearance">'
            + '      <summary>Сетка и оформление</summary>'
            + '      <div class="sb-section-appearance__body">'
            + '          <div class="sb-section-fields sb-section-fields--3">'
            + '              <label>Компьютер<select data-section-field="columns" data-section-id="' + id + '">'
            + '                  <option value="1"' + (columns === 1 ? ' selected' : '') + '>1 колонка</option>'
            + '                  <option value="2"' + (columns === 2 ? ' selected' : '') + '>2 колонки</option>'
            + '                  <option value="3"' + (columns === 3 ? ' selected' : '') + '>3 колонки</option>'
            + '                  <option value="4"' + (columns === 4 ? ' selected' : '') + '>4 колонки</option>'
            + '              </select></label>'
            + '              <label>Планшет<select data-section-field="tabletColumns" data-section-id="' + id + '">'
            + '                  <option value="1"' + (tabletColumns === 1 ? ' selected' : '') + '>1 колонка</option>'
            + '                  <option value="2"' + (tabletColumns === 2 ? ' selected' : '') + '>2 колонки</option>'
            + '                  <option value="3"' + (tabletColumns === 3 ? ' selected' : '') + '>3 колонки</option>'
            + '                  <option value="4"' + (tabletColumns === 4 ? ' selected' : '') + '>4 колонки</option>'
            + '              </select></label>'
            + '              <label>Телефон<select data-section-field="mobileColumns" data-section-id="' + id + '">'
            + '                  <option value="1"' + (mobileColumns === 1 ? ' selected' : '') + '>1 колонка</option>'
            + '                  <option value="2"' + (mobileColumns === 2 ? ' selected' : '') + '>2 колонки</option>'
            + '              </select></label>'
            + '          </div>'
            + '          <div class="sb-section-fields sb-section-fields--3">'
            + '              <label>Ширина<select data-section-field="container" data-section-id="' + id + '">'
            + '                  <option value="default"' + (container === 'default' ? ' selected' : '') + '>Обычная</option>'
            + '                  <option value="wide"' + (container === 'wide' ? ' selected' : '') + '>Широкая</option>'
            + '                  <option value="full"' + (container === 'full' ? ' selected' : '') + '>Полная</option>'
            + '              </select></label>'
            + '              <label>Промежуток<input type="number" min="0" max="120" value="' + gap + '" data-section-field="gap" data-section-id="' + id + '"></label>'
            + '              <label>По вертикали<select data-section-field="verticalAlign" data-section-id="' + id + '">'
            + '                  <option value="start"' + (verticalAlign === 'start' ? ' selected' : '') + '>Сверху</option>'
            + '                  <option value="center"' + (verticalAlign === 'center' ? ' selected' : '') + '>По центру</option>'
            + '                  <option value="end"' + (verticalAlign === 'end' ? ' selected' : '') + '>Снизу</option>'
            + '                  <option value="stretch"' + (verticalAlign === 'stretch' ? ' selected' : '') + '>Растянуть</option>'
            + '              </select></label>'
            + '          </div>'
            + '          <div class="sb-section-fields sb-section-fields--2">'
            + '              <label>Цвет фона<input type="color" value="' + escapeHtml(backgroundColor) + '" data-section-field="backgroundColor" data-section-id="' + id + '"></label>'
            + '              <label>Цвет текста<input type="color" value="' + escapeHtml(textColor) + '" data-section-field="textColor" data-section-id="' + id + '"></label>'
            + '          </div>'
            + '          <div class="sb-section-fields sb-section-fields--3">'
            + '              <label>Сверху<input type="number" min="0" max="240" value="' + paddingTop + '" data-section-field="paddingTop" data-section-id="' + id + '"></label>'
            + '              <label>Снизу<input type="number" min="0" max="240" value="' + paddingBottom + '" data-section-field="paddingBottom" data-section-id="' + id + '"></label>'
            + '              <label>По бокам<input type="number" min="0" max="160" value="' + paddingX + '" data-section-field="paddingX" data-section-id="' + id + '"></label>'
            + '          </div>'
            + '          <div class="sb-section-fields sb-section-fields--3">'
            + '              <label>Скругление<input type="number" min="0" max="80" value="' + borderRadius + '" data-section-field="borderRadius" data-section-id="' + id + '"></label>'
            + '              <label>Мин. высота<input type="number" min="0" max="1200" value="' + minHeight + '" data-section-field="minHeight" data-section-id="' + id + '"></label>'
            + '              <label class="sb-section-check"><input type="checkbox"' + (shadow ? ' checked' : '') + ' data-section-field="shadow" data-section-id="' + id + '"> Тень</label>'
            + '          </div>'
            + '          <label class="sb-section-wide-field">Фоновое изображение<input type="text" value="' + escapeHtml(backgroundImage) + '" placeholder="https://... или /upload/..." data-section-field="backgroundImage" data-section-id="' + id + '"></label>'
            + '          <div class="sb-section-fields sb-section-fields--2">'
            + '              <label>Размер фона<select data-section-field="backgroundSize" data-section-id="' + id + '">'
            + '                  <option value="cover"' + (backgroundSize === 'cover' ? ' selected' : '') + '>Заполнить</option>'
            + '                  <option value="contain"' + (backgroundSize === 'contain' ? ' selected' : '') + '>Вместить</option>'
            + '                  <option value="auto"' + (backgroundSize === 'auto' ? ' selected' : '') + '>Оригинал</option>'
            + '              </select></label>'
            + '              <label>Позиция<select data-section-field="backgroundPosition" data-section-id="' + id + '">'
            + '                  <option value="center"' + (backgroundPosition === 'center' ? ' selected' : '') + '>По центру</option>'
            + '                  <option value="top"' + (backgroundPosition === 'top' ? ' selected' : '') + '>Сверху</option>'
            + '                  <option value="bottom"' + (backgroundPosition === 'bottom' ? ' selected' : '') + '>Снизу</option>'
            + '                  <option value="left"' + (backgroundPosition === 'left' ? ' selected' : '') + '>Слева</option>'
            + '                  <option value="right"' + (backgroundPosition === 'right' ? ' selected' : '') + '>Справа</option>'
            + '              </select></label>'
            + '          </div>'
            + '      </div>'
            + '  </details>'
            + '  <div class="sb-page-section-card__actions">'
            + '      <button class="sb-icon-btn" type="button" data-section-action="move-up" data-section-id="' + id + '" title="Выше">↑</button>'
            + '      <button class="sb-icon-btn" type="button" data-section-action="move-down" data-section-id="' + id + '" title="Ниже">↓</button>'
            + '      <button class="sb-btn sb-btn-primary sb-btn-small" type="button" data-section-action="save" data-section-id="' + id + '">Сохранить</button>'
            + '      <button class="sb-icon-btn sb-icon-btn--danger" type="button" data-section-action="delete" data-section-id="' + id + '" title="Удалить">×</button>'
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

    var res = await api('pageSection.assignBlock', {
        blockId: Number(block.id || 0),
        sectionId: sectionId,
        column: column,
        expectedVersion: entityVersion(block)
    });

    if (res.block) {
        replaceStateBlock(res.block);
        return res.block;
    }

    return block;
}

async function assignBlockToSection(blockId, sectionId, column) {
    blockId = Number(blockId || 0);
    sectionId = Number(sectionId || 0);
    column = Number(column || 1);

    if (blockId <= 0 || sectionId <= 0) {
        return;
    }

    var block = state.blocks.find(function (item) {
        return Number(item.id || 0) === blockId;
    }) || null;

    var payload = {
        blockId: blockId,
        sectionId: sectionId,
        column: column
    };

    if (block) {
        payload.expectedVersion = entityVersion(block);
    }

    var res = await api('pageSection.assignBlock', payload);

    if (res.block) {
        replaceStateBlock(res.block);
        return res.block;
    }

    return block;
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
                tabletColumns: 1,
                mobileColumns: 1,
                gap: 24,
                verticalAlign: 'start'
            }),
            props: JSON.stringify({
                backgroundColor: '',
                textColor: '',
                backgroundImage: '',
                backgroundPosition: 'center',
                backgroundSize: 'cover',
                paddingTop: 40,
                paddingBottom: 40,
                paddingX: 24,
                minHeight: 0,
                borderRadius: 0,
                shadow: false
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

    function field(name) {
        return card.querySelector('[data-section-field="' + name + '"]');
    }

    var titleInput = field('title');
    var columnsSelect = field('columns');
    var tabletColumnsSelect = field('tabletColumns');
    var mobileColumnsSelect = field('mobileColumns');
    var containerSelect = field('container');
    var gapInput = field('gap');
    var verticalAlignInput = field('verticalAlign');

    var title = titleInput ? String(titleInput.value || '').trim() : section.title;
    var columns = columnsSelect ? Number(columnsSelect.value || 1) : Number((section.layout || {}).columns || 1);
    var tabletColumns = tabletColumnsSelect ? Number(tabletColumnsSelect.value || 1) : Number((section.layout || {}).tabletColumns || Math.min(columns, 2));
    var mobileColumns = mobileColumnsSelect ? Number(mobileColumnsSelect.value || 1) : Number((section.layout || {}).mobileColumns || 1);
    var container = containerSelect ? String(containerSelect.value || 'default') : String((section.layout || {}).container || 'default');
    var gap = gapInput ? Number(gapInput.value || 0) : Number((section.layout || {}).gap || 24);
    var verticalAlign = verticalAlignInput ? String(verticalAlignInput.value || 'start') : String((section.layout || {}).verticalAlign || 'start');

    var normalizedColumns = Math.max(1, Math.min(4, columns));
    var normalizedTabletColumns = Math.max(1, Math.min(normalizedColumns, tabletColumns));
    var normalizedMobileColumns = Math.max(1, Math.min(Math.min(normalizedTabletColumns, 2), mobileColumns));

    var layout = Object.assign({}, section.layout || {}, {
        columns: normalizedColumns,
        tabletColumns: normalizedTabletColumns,
        mobileColumns: normalizedMobileColumns,
        container: container,
        gap: Math.max(0, Math.min(120, gap)),
        verticalAlign: verticalAlign
    });

    var backgroundColorInput = field('backgroundColor');
    var textColorInput = field('textColor');
    var backgroundImageInput = field('backgroundImage');
    var backgroundPositionInput = field('backgroundPosition');
    var backgroundSizeInput = field('backgroundSize');
    var paddingTopInput = field('paddingTop');
    var paddingBottomInput = field('paddingBottom');
    var paddingXInput = field('paddingX');
    var minHeightInput = field('minHeight');
    var borderRadiusInput = field('borderRadius');
    var shadowInput = field('shadow');

    var props = Object.assign({}, section.props || {}, {
        backgroundColor: backgroundColorInput ? String(backgroundColorInput.value || '') : '',
        textColor: textColorInput ? String(textColorInput.value || '') : '',
        backgroundImage: backgroundImageInput ? String(backgroundImageInput.value || '').trim() : '',
        backgroundPosition: backgroundPositionInput ? String(backgroundPositionInput.value || 'center') : 'center',
        backgroundSize: backgroundSizeInput ? String(backgroundSizeInput.value || 'cover') : 'cover',
        paddingTop: paddingTopInput ? Math.max(0, Math.min(240, Number(paddingTopInput.value || 0))) : 32,
        paddingBottom: paddingBottomInput ? Math.max(0, Math.min(240, Number(paddingBottomInput.value || 0))) : 32,
        paddingX: paddingXInput ? Math.max(0, Math.min(160, Number(paddingXInput.value || 0))) : 24,
        minHeight: minHeightInput ? Math.max(0, Math.min(1200, Number(minHeightInput.value || 0))) : 0,
        borderRadius: borderRadiusInput ? Math.max(0, Math.min(80, Number(borderRadiusInput.value || 0))) : 0,
        shadow: !!(shadowInput && shadowInput.checked)
    });

    setPageSectionsMessage('Сохраняю секцию...', 'info');

    try {
        var res = await api('pageSection.update', {
            sectionId: sectionId,
            title: title,
            layout: JSON.stringify(layout),
            props: JSON.stringify(props),
            expectedVersion: Number(section.version || 1)
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
        if (await refreshSectionsAfterConflict(e)) return;
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
            dir: dir,
            expectedVersions: JSON.stringify(pageSectionVersionMap())
        });

        var data = apiData(res);

        state.pageSections = Array.isArray(data.sections) ? data.sections : state.pageSections;

        renderPageSectionsPanel();
        renderBlocks();
    } catch (e) {
        console.error(e);
        if (await refreshSectionsAfterConflict(e)) return;
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
            sectionId: sectionId,
            expectedVersion: Number((section && section.version) || 1)
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
        if (await refreshSectionsAfterConflict(e)) return;
        setPageSectionsMessage('Не удалось удалить секцию: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
    }
}