(function () {
    window.SB_TABLE_EDIT_LOADED = 'v12-stable-types-pagination';

    var config = window.SB_PUBLIC_EDIT_CONFIG || {};
    var API_URL = config.apiUrl || '/local/sitebuilder/api.php';
    var sessid = config.sessid || '';

    var activeResize = null;

    function parseJson(value, fallback) {
        try {
            return JSON.parse(value || '');
        } catch (e) {
            return fallback;
        }
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }

        return String(value).replace(/"/g, '\\"');
    }

    function normalizeAlign(align) {
        align = String(align || 'left');

        if (align !== 'left' && align !== 'center' && align !== 'right') {
            return 'left';
        }

        return align;
    }

    function normalizeType(type) {
        type = String(type || 'text');

        if (['text', 'number', 'date', 'link', 'image', 'formula'].indexOf(type) === -1) {
            return 'text';
        }

        return type;
    }

    function normalizeColumnCode(code, index, id) {
        code = String(code || '').trim();
        id = String(id || '').trim();

        code = code.replace(/^Код:\s*/i, '');

        if (!code) {
            var idMatch = id.match(/^c_?(\d+)$/i);

            if (idMatch) {
                code = 'c' + idMatch[1];
            } else {
                code = 'c' + (index + 1);
            }
        }

        var codeMatch = code.match(/^c_(\d+)$/i);

        if (codeMatch) {
            code = 'c' + codeMatch[1];
        }

        code = code.replace(/[^A-Za-z0-9_]/g, '');

        if (!code) {
            code = 'c' + (index + 1);
        }

        return code;
    }

    function normalizeSettings(settings) {
        settings = settings && typeof settings === 'object' ? settings : {};

        var maxRows = Number(settings.maxRows || 0);
        var pageSize = Number(settings.pageSize || 10);
        var currentPage = Number(settings.currentPage || 1);

        if (!Number.isFinite(maxRows) || maxRows < 0) {
            maxRows = 0;
        }

        if (!Number.isFinite(pageSize) || pageSize < 1) {
            pageSize = 10;
        }

        if (pageSize > 200) {
            pageSize = 200;
        }

        if (!Number.isFinite(currentPage) || currentPage < 1) {
            currentPage = 1;
        }

        return {
            maxRows: Math.floor(maxRows),
            pageSize: Math.floor(pageSize),
            pagination: !!settings.pagination,
            currentPage: Math.floor(currentPage)
        };
    }

    function textValue(node) {
        return String(node ? (node.innerText || node.textContent || '') : '')
            .replace(/\u00a0/g, ' ')
            .trim();
    }

    function getClientX(e) {
        if (e.touches && e.touches[0]) {
            return e.touches[0].clientX;
        }

        if (e.changedTouches && e.changedTouches[0]) {
            return e.changedTouches[0].clientX;
        }

        return e.clientX;
    }

    function getContent(root) {
        return parseJson(root.getAttribute('data-content'), {});
    }

    function setContent(root, content) {
        root.setAttribute('data-content', JSON.stringify(content || {}));
    }

    function setDirty(root, isDirty) {
        root.classList.toggle('is-dirty', !!isDirty);

        var btn = root.querySelector('[data-table-save-all]');

        if (btn) {
            btn.textContent = isDirty ? 'Сохранить изменения *' : 'Сохранить изменения';
        }
    }

    function clampWidth(width) {
        width = Math.round(Number(width || 0));

        if (width < 80) {
            width = 80;
        }

        if (width > 1200) {
            width = 1200;
        }

        return width;
    }

    function valueToText(value) {
        if (value && typeof value === 'object') {
            if (value.text) {
                return String(value.text);
            }

            if (value.url) {
                return String(value.url);
            }

            if (value.src) {
                return String(value.src);
            }

            if (value.alt) {
                return String(value.alt);
            }

            return '';
        }

        return String(value || '');
    }

    function looksLikeUrl(value) {
        value = String(value || '').trim();

        return /^(https?:\/\/|\/|mailto:)/i.test(value);
    }

    function looksLikeImageSrc(value) {
        value = String(value || '').trim();

        return /^(https?:\/\/|\/)/i.test(value) && /\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i.test(value);
    }

    function normalizeNumberValue(value) {
        value = String(value || '')
            .replace(/\s+/g, '')
            .replace(',', '.');

        if (value === '') {
            return '';
        }

        var match = value.match(/-?\d+(?:\.\d+)?/);

        if (!match) {
            return '';
        }

        var number = Number(match[0]);

        if (!Number.isFinite(number)) {
            return '';
        }

        return String(number);
    }

    function formatNumberInputLive(value) {
        value = String(value || '')
            .replace(/\s+/g, '')
            .replace(',', '.')
            .replace(/[^0-9.\-]/g, '');

        value = value.replace(/(?!^)-/g, '');

        var minus = value.charAt(0) === '-' ? '-' : '';

        if (minus) {
            value = value.slice(1);
        }

        var parts = value.split('.');

        if (parts.length > 2) {
            value = parts.shift() + '.' + parts.join('');
        }

        return minus + value;
    }

    function isValidDateParts(day, month, year) {
        day = Number(day);
        month = Number(month);
        year = Number(year);

        if (!day || !month || !year) {
            return false;
        }

        if (year < 1900 || year > 2200) {
            return false;
        }

        var date = new Date(year, month - 1, day);

        return date.getFullYear() === year
            && date.getMonth() === month - 1
            && date.getDate() === day;
    }

    function normalizeDateValue(value) {
        value = valueToText(value).trim();

        if (!value) {
            return '';
        }

        var matchRu = value.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);

        if (matchRu) {
            var dayRu = matchRu[1].padStart(2, '0');
            var monthRu = matchRu[2].padStart(2, '0');
            var yearRu = matchRu[3];

            if (isValidDateParts(dayRu, monthRu, yearRu)) {
                return dayRu + '.' + monthRu + '.' + yearRu;
            }

            return '';
        }

        var matchIso = value.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);

        if (matchIso) {
            var yearIso = matchIso[1];
            var monthIso = matchIso[2].padStart(2, '0');
            var dayIso = matchIso[3].padStart(2, '0');

            if (isValidDateParts(dayIso, monthIso, yearIso)) {
                return dayIso + '.' + monthIso + '.' + yearIso;
            }

            return '';
        }

        var digits = value.replace(/\D/g, '');

        if (digits.length === 8) {
            var day = digits.slice(0, 2);
            var month = digits.slice(2, 4);
            var year = digits.slice(4, 8);

            if (isValidDateParts(day, month, year)) {
                return day + '.' + month + '.' + year;
            }
        }

        return '';
    }

    function formatDateInputLive(value) {
        value = String(value || '').trim();

        var iso = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);

        if (iso) {
            return iso[3] + '.' + iso[2] + '.' + iso[1];
        }

        var digits = value.replace(/\D/g, '').slice(0, 8);

        if (digits.length <= 2) {
            return digits;
        }

        if (digits.length <= 4) {
            return digits.slice(0, 2) + '.' + digits.slice(2);
        }

        return digits.slice(0, 2) + '.' + digits.slice(2, 4) + '.' + digits.slice(4);
    }

    function valueToNumber(value) {
        var normalized = normalizeNumberValue(valueToText(value));

        if (normalized === '') {
            return 0;
        }

        var number = Number(normalized);

        return Number.isFinite(number) ? number : 0;
    }

    function evaluateMathExpression(expression) {
        expression = String(expression || '').replace(/,/g, '.').trim();

        if (!expression) {
            return '';
        }

        if (!/^[0-9+\-*\/().\s]+$/.test(expression)) {
            return 'Ошибка';
        }

        var tokens = expression.match(/\d+(?:\.\d+)?|[+\-*\/()]/g) || [];

        if (!tokens.length) {
            return '';
        }

        var raw = expression.replace(/\s+/g, '');
        var joined = tokens.join('');

        if (raw !== joined) {
            return 'Ошибка';
        }

        var i = 0;
        var hasError = false;

        function parseFactor() {
            if (i >= tokens.length) {
                hasError = true;
                return 0;
            }

            var token = tokens[i];

            if (token === '+') {
                i++;
                return parseFactor();
            }

            if (token === '-') {
                i++;
                return -parseFactor();
            }

            if (token === '(') {
                i++;

                var value = parseExpression();

                if (tokens[i] === ')') {
                    i++;
                } else {
                    hasError = true;
                }

                return value;
            }

            if (!/^\d+(?:\.\d+)?$/.test(token)) {
                hasError = true;
                return 0;
            }

            i++;

            return Number(token);
        }

        function parseTerm() {
            var value = parseFactor();

            while (tokens[i] === '*' || tokens[i] === '/') {
                var op = tokens[i];
                i++;

                var right = parseFactor();

                if (op === '*') {
                    value *= right;
                } else {
                    if (Math.abs(right) < 0.0000001) {
                        hasError = true;
                        return 0;
                    }

                    value /= right;
                }
            }

            return value;
        }

        function parseExpression() {
            var value = parseTerm();

            while (tokens[i] === '+' || tokens[i] === '-') {
                var op = tokens[i];
                i++;

                var right = parseTerm();

                if (op === '+') {
                    value += right;
                } else {
                    value -= right;
                }
            }

            return value;
        }

        var result = parseExpression();

        if (hasError || i < tokens.length || !Number.isFinite(result)) {
            return 'Ошибка';
        }

        result = Math.round(result * 1000000) / 1000000;

        var text = String(result);

        if (text.indexOf('.') !== -1) {
            text = text.replace(/0+$/, '').replace(/\.$/, '');
        }

        return text === '-0' ? '0' : text;
    }

    function calculateFormula(content, row, formula) {
        formula = String(formula || '').trim();

        if (!formula) {
            return '';
        }

        var cells = row && row.cells ? row.cells : {};
        var columns = Array.isArray(content.columns) ? content.columns : [];

        var expression = formula.replace(/\b[A-Za-z_][A-Za-z0-9_]*\b/g, function (token) {
            var column = null;

            columns.some(function (item, index) {
                var id = String(item.id || '');
                var code = normalizeColumnCode(item.code || '', index, id);
                var legacyCode = code.replace(/^c(\d+)$/i, 'c_$1');

                if (token === id || token === code || token === legacyCode) {
                    column = item;
                    return true;
                }

                return false;
            });

            if (!column) {
                return '0';
            }

            return String(valueToNumber(cells[column.id]));
        });

        return evaluateMathExpression(expression);
    }

    function getColumnCurrentWidth(table, columnId) {
        var th = table.querySelector('th[data-column-id="' + cssEscape(columnId) + '"]');

        if (!th) {
            return 160;
        }

        return clampWidth(th.getBoundingClientRect().width || 160);
    }

    function getColumnAlignFromTh(th) {
        var select = th.querySelector('[data-column-align]');

        if (select) {
            return normalizeAlign(select.value);
        }

        return normalizeAlign(th.getAttribute('data-column-align-value') || 'left');
    }

    function getColumnTypeFromTh(th) {
        var select = th.querySelector('[data-column-type]');

        if (select) {
            return normalizeType(select.value);
        }

        return normalizeType(th.getAttribute('data-column-type-value') || 'text');
    }

    function getColumnFormulaFromTh(th) {
        var input = th.querySelector('[data-column-formula]');

        if (input) {
            return String(input.value || '').trim();
        }

        return '';
    }

    function getColumnCodeFromTh(th, index, columnId, oldColumn) {
        var codeNode = th.querySelector('[data-column-code]');
        var codeFromDom = codeNode ? textValue(codeNode).replace(/^Код:\s*/i, '') : '';
        var oldCode = oldColumn && oldColumn.code ? String(oldColumn.code) : '';

        return normalizeColumnCode(oldCode || codeFromDom, index, columnId);
    }

    function findOldColumn(oldContent, columnId) {
        var columns = Array.isArray(oldContent.columns) ? oldContent.columns : [];

        return columns.find(function (column) {
            return String(column.id || '') === String(columnId);
        }) || null;
    }

    function findOldRow(oldContent, rowId) {
        var rows = Array.isArray(oldContent.rows) ? oldContent.rows : [];

        return rows.find(function (row) {
            return String(row.id || '') === String(rowId);
        }) || null;
    }

    function ensureSettingsControls(root) {
        var editbarMain = root.querySelector('.sb-public-table-editbar__main');

        if (!editbarMain) {
            return;
        }

        if (
            root.querySelector('[data-public-table-settings]') ||
            root.querySelector('[data-table-max-rows]') ||
            root.querySelector('[data-table-page-size]') ||
            root.querySelector('[data-table-pagination-enabled]')
        ) {
            return;
        }

        var box = document.createElement('div');
        box.className = 'sb-public-table-settings';
        box.setAttribute('data-public-table-settings', '');

        box.innerHTML = ''
            + '<label class="sb-public-table-settings__field">'
            + '  Макс. строк'
            + '  <input type="number" min="0" step="1" value="0" placeholder="0 = без лимита" data-table-max-rows>'
            + '</label>'
            + '<label class="sb-public-table-settings__field">'
            + '  Строк на странице'
            + '  <input type="number" min="1" max="200" step="1" value="10" data-table-page-size>'
            + '</label>'
            + '<label class="sb-public-table-settings__check">'
            + '  <input type="checkbox" data-table-pagination-enabled>'
            + '  Пагинация'
            + '</label>';

        editbarMain.appendChild(box);
    }

    function getSettingsFromDom(root, oldContent) {
        oldContent = oldContent || getContent(root);

        var oldSettings = normalizeSettings(oldContent.settings || {});
        var maxRowsInput = root.querySelector('[data-table-max-rows]');
        var pageSizeInput = root.querySelector('[data-table-page-size]');
        var paginationInput = root.querySelector('[data-table-pagination-enabled]');

        return normalizeSettings({
            maxRows: maxRowsInput ? Number(maxRowsInput.value || 0) : oldSettings.maxRows,
            pageSize: pageSizeInput ? Number(pageSizeInput.value || 10) : oldSettings.pageSize,
            pagination: paginationInput ? !!paginationInput.checked : oldSettings.pagination,
            currentPage: oldSettings.currentPage || 1
        });
    }

    function setSettingsToDom(root, settings) {
        settings = normalizeSettings(settings);

        var maxRowsInput = root.querySelector('[data-table-max-rows]');
        var pageSizeInput = root.querySelector('[data-table-page-size]');
        var paginationInput = root.querySelector('[data-table-pagination-enabled]');

        if (maxRowsInput) {
            maxRowsInput.value = String(settings.maxRows);
        }

        if (pageSizeInput) {
            pageSizeInput.value = String(settings.pageSize);
        }

        if (paginationInput) {
            paginationInput.checked = !!settings.pagination;
        }
    }

    function ensurePaginationBox(root) {
        var box = root.querySelector('[data-table-pagination]');

        if (box) {
            return box;
        }

        box = document.createElement('div');
        box.className = 'sb-public-table-pagination';
        box.setAttribute('data-table-pagination', '');

        var wrap = root.querySelector('.sb-public-table-wrap');

        if (wrap) {
            wrap.appendChild(box);
        } else {
            root.appendChild(box);
        }

        return box;
    }

    function getRawCellValueFromTd(td, oldValue) {
        var linkText = td.querySelector('[data-link-text]');
        var linkUrl = td.querySelector('[data-link-url]');

        if (linkText || linkUrl) {
            return {
                text: String(linkText ? linkText.value : '').trim(),
                url: String(linkUrl ? linkUrl.value : '').trim()
            };
        }

        var imageSrc = td.querySelector('[data-image-src]');
        var imageAlt = td.querySelector('[data-image-alt]');

        if (imageSrc || imageAlt) {
            return {
                src: String(imageSrc ? imageSrc.value : '').trim(),
                alt: String(imageAlt ? imageAlt.value : '').trim()
            };
        }

        var numberInput = td.querySelector('[data-number-cell]');

        if (numberInput) {
            return normalizeNumberValue(numberInput.value);
        }

        var dateInput = td.querySelector('[data-date-cell]');

        if (dateInput) {
            return normalizeDateValue(dateInput.value);
        }

        if (td.querySelector('[data-formula-cell]')) {
            return '';
        }

        var value = textValue(td);

        if (value !== '') {
            return value;
        }

        return oldValue;
    }

    function convertCellValueToType(rawValue, type) {
        type = normalizeType(type);

        if (type === 'formula') {
            return '';
        }

        if (type === 'number') {
            return normalizeNumberValue(valueToText(rawValue));
        }

        if (type === 'date') {
            return normalizeDateValue(valueToText(rawValue));
        }

        if (type === 'link') {
            if (rawValue && typeof rawValue === 'object') {
                return {
                    text: String(rawValue.text || rawValue.url || '').trim(),
                    url: String(rawValue.url || '').trim()
                };
            }

            var linkText = valueToText(rawValue);

            return {
                text: linkText,
                url: looksLikeUrl(linkText) ? linkText : ''
            };
        }

        if (type === 'image') {
            if (rawValue && typeof rawValue === 'object') {
                return {
                    src: String(rawValue.src || '').trim(),
                    alt: String(rawValue.alt || rawValue.text || '').trim()
                };
            }

            var imageText = valueToText(rawValue);

            return {
                src: looksLikeImageSrc(imageText) ? imageText : '',
                alt: looksLikeImageSrc(imageText) ? '' : imageText
            };
        }

        return valueToText(rawValue);
    }

    function collectContentFromDom(root) {
        ensureSettingsControls(root);

        var oldContent = getContent(root);
        var table = root.querySelector('.sb-public-table');
        var titleInput = root.querySelector('[data-table-title-input]');

        var columns = [];
        var rows = [];

        if (!table) {
            return oldContent;
        }

        var thList = table.querySelectorAll('thead th[data-column-id]');

        thList.forEach(function (th, index) {
            var columnId = String(th.getAttribute('data-column-id') || '').trim();

            if (!columnId) {
                columnId = 'c_' + (index + 1);
                th.setAttribute('data-column-id', columnId);
            }

            var labelNode = th.querySelector('[data-column-label]') || th.querySelector('.sb-public-table__th-text');
            var label = textValue(labelNode);

            if (!label) {
                label = 'Столбец ' + (index + 1);
            }

            var oldColumn = findOldColumn(oldContent, columnId);
            var width = oldColumn && oldColumn.width
                ? Number(oldColumn.width)
                : getColumnCurrentWidth(table, columnId);

            columns.push({
                id: columnId,
                code: getColumnCodeFromTh(th, index, columnId, oldColumn),
                label: label,
                width: clampWidth(width),
                align: getColumnAlignFromTh(th),
                type: getColumnTypeFromTh(th),
                formula: getColumnFormulaFromTh(th)
            });
        });

        if (!columns.length && Array.isArray(oldContent.columns) && oldContent.columns.length) {
            oldContent.columns.forEach(function (column, index) {
                columns.push({
                    id: String(column.id || ('c_' + (index + 1))),
                    code: normalizeColumnCode(column.code || '', index, column.id || ('c_' + (index + 1))),
                    label: String(column.label || ('Столбец ' + (index + 1))),
                    width: clampWidth(column.width || 160),
                    align: normalizeAlign(column.align || 'left'),
                    type: normalizeType(column.type || 'text'),
                    formula: String(column.formula || '')
                });
            });
        }

        table.querySelectorAll('tbody tr[data-row-id]').forEach(function (tr, rowIndex) {
            var rowId = String(tr.getAttribute('data-row-id') || '').trim();

            if (!rowId) {
                rowId = 'row_' + (Date.now() + rowIndex);
                tr.setAttribute('data-row-id', rowId);
            }

            var oldRow = findOldRow(oldContent, rowId);
            var oldCells = oldRow && oldRow.cells ? oldRow.cells : {};
            var cells = {};

            columns.forEach(function (column) {
                if (column.type === 'formula') {
                    return;
                }

                var td = tr.querySelector('td[data-column-id="' + cssEscape(column.id) + '"]');
                var oldValue = oldCells[column.id] !== undefined ? oldCells[column.id] : '';

                if (!td) {
                    cells[column.id] = convertCellValueToType(oldValue, column.type);
                    return;
                }

                var rawValue = getRawCellValueFromTd(td, oldValue);
                cells[column.id] = convertCellValueToType(rawValue, column.type);
            });

            rows.push({
                id: rowId,
                cells: cells
            });
        });

        if (!rows.length && Array.isArray(oldContent.rows) && oldContent.rows.length) {
            oldContent.rows.forEach(function (row, rowIndex) {
                var rowId = String(row.id || ('row_' + (rowIndex + 1)));
                var oldCells = row.cells || {};
                var cells = {};

                columns.forEach(function (column) {
                    if (column.type !== 'formula') {
                        cells[column.id] = convertCellValueToType(oldCells[column.id], column.type);
                    }
                });

                rows.push({
                    id: rowId,
                    cells: cells
                });
            });
        }

        return {
            title: titleInput ? String(titleInput.value || '').trim() || 'Таблица' : (oldContent.title || 'Таблица'),
            columns: columns,
            rows: rows,
            settings: getSettingsFromDom(root, oldContent)
        };
    }

    function applyColumnAlign(root, columnId, align) {
        var table = root.querySelector('.sb-public-table');

        if (!table) {
            return;
        }

        align = normalizeAlign(align);

        var th = table.querySelector('th[data-column-id="' + cssEscape(columnId) + '"]');

        if (th) {
            th.style.textAlign = align;
            th.setAttribute('data-column-align-value', align);

            var select = th.querySelector('[data-column-align]');

            if (select) {
                select.value = align;
            }
        }

        table.querySelectorAll('td[data-column-id="' + cssEscape(columnId) + '"]').forEach(function (td) {
            td.style.textAlign = align;
        });
    }

    function applyAllAligns(root) {
        var content = getContent(root);
        var columns = Array.isArray(content.columns) ? content.columns : [];

        columns.forEach(function (column) {
            applyColumnAlign(root, String(column.id || ''), normalizeAlign(column.align || 'left'));
        });
    }

    function applyWidths(root) {
        var table = root.querySelector('.sb-public-table');
        var content = getContent(root);
        var columns = Array.isArray(content.columns) ? content.columns : [];

        if (!table || !columns.length) {
            return;
        }

        var total = root.querySelector('.sb-public-table__control-col') ? 72 : 0;

        columns.forEach(function (column) {
            var columnId = String(column.id || '');
            var width = clampWidth(column.width || getColumnCurrentWidth(table, columnId));

            column.width = width;
            total += width;

            var col = table.querySelector('col[data-column-id="' + cssEscape(columnId) + '"]');
            var th = table.querySelector('th[data-column-id="' + cssEscape(columnId) + '"]');

            if (col) {
                col.style.setProperty('width', width + 'px', 'important');
                col.setAttribute('width', String(width));
            }

            if (th) {
                th.style.setProperty('width', width + 'px', 'important');
                th.style.setProperty('min-width', width + 'px', 'important');
                th.style.setProperty('max-width', width + 'px', 'important');
            }
        });

        table.style.setProperty('table-layout', 'fixed', 'important');
        table.style.setProperty('width', total + 'px', 'important');
        table.style.setProperty('min-width', total + 'px', 'important');

        content.columns = columns;
        setContent(root, content);
    }

    function createTypeSelect(value) {
        var select = document.createElement('select');

        select.className = 'sb-public-table-type-select';
        select.setAttribute('data-column-type', '');

        select.innerHTML = ''
            + '<option value="text">Текст</option>'
            + '<option value="number">Число</option>'
            + '<option value="date">Дата</option>'
            + '<option value="link">Гиперссылка</option>'
            + '<option value="image">Рисунок</option>'
            + '<option value="formula">Формула</option>';

        select.value = normalizeType(value);

        return select;
    }

    function createAlignSelect(value) {
        var select = document.createElement('select');

        select.className = 'sb-public-table-align-select';
        select.setAttribute('data-column-align', '');

        select.innerHTML = ''
            + '<option value="left">Слева</option>'
            + '<option value="center">Центр</option>'
            + '<option value="right">Справа</option>';

        select.value = normalizeAlign(value);

        return select;
    }

    function createFormulaTools(columns, currentColumn) {
        var formulaTools = document.createElement('div');

        formulaTools.className = 'sb-public-table-formula-tools';
        formulaTools.setAttribute('data-formula-tools', '');

        if (currentColumn.type !== 'formula') {
            formulaTools.style.display = 'none';
        }

        var formulaColumnSelect = document.createElement('select');
        formulaColumnSelect.setAttribute('data-formula-insert-column', '');

        columns.forEach(function (item, itemIndex) {
            if (item.id === currentColumn.id) {
                return;
            }

            var optionCode = normalizeColumnCode(item.code || '', itemIndex, item.id);
            var option = document.createElement('option');

            option.value = optionCode;
            option.textContent = optionCode + ' — ' + (item.label || 'Столбец');

            formulaColumnSelect.appendChild(option);
        });

        var formulaInsertBtn = document.createElement('button');
        formulaInsertBtn.type = 'button';
        formulaInsertBtn.setAttribute('data-formula-insert-btn', '');
        formulaInsertBtn.textContent = 'Вставить';

        var formulaInsertRow = document.createElement('div');
        formulaInsertRow.className = 'sb-public-table-formula-tools__row';
        formulaInsertRow.appendChild(formulaColumnSelect);
        formulaInsertRow.appendChild(formulaInsertBtn);

        var formulaOps = document.createElement('div');
        formulaOps.className = 'sb-public-table-formula-tools__row';

        ['+', '-', '*', '/', '(', ')'].forEach(function (op) {
            var btn = document.createElement('button');

            btn.type = 'button';
            btn.setAttribute('data-formula-op', op);
            btn.textContent = op;

            formulaOps.appendChild(btn);
        });

        formulaTools.appendChild(formulaInsertRow);
        formulaTools.appendChild(formulaOps);

        return formulaTools;
    }

    function renderCellEditor(td, column, row, content) {
        var type = normalizeType(column.type);
        var value = row.cells ? row.cells[column.id] : '';

        td.innerHTML = '';
        td.removeAttribute('contenteditable');
        td.removeAttribute('data-cell-editable');

        td.setAttribute('data-column-id', column.id);
        td.setAttribute('data-column-type', type);
        td.style.textAlign = normalizeAlign(column.align);

        if (type === 'text') {
            td.setAttribute('contenteditable', 'true');
            td.setAttribute('data-cell-editable', '');
            td.textContent = valueToText(value);
            return;
        }

        if (type === 'number') {
            var numberInput = document.createElement('input');

            numberInput.className = 'sb-public-table-cell-input';
            numberInput.type = 'text';
            numberInput.inputMode = 'decimal';
            numberInput.placeholder = '0';
            numberInput.setAttribute('data-number-cell', '');
            numberInput.value = normalizeNumberValue(value);

            td.appendChild(numberInput);
            return;
        }

        if (type === 'date') {
            var dateInput = document.createElement('input');

            dateInput.className = 'sb-public-table-cell-input';
            dateInput.type = 'text';
            dateInput.placeholder = 'дд.мм.гггг';
            dateInput.maxLength = 10;
            dateInput.setAttribute('data-date-cell', '');
            dateInput.value = normalizeDateValue(value);

            td.appendChild(dateInput);
            return;
        }

        if (type === 'link') {
            var linkValue = convertCellValueToType(value, 'link');

            var linkWrap = document.createElement('div');
            linkWrap.className = 'sb-public-table-cell-link';
            linkWrap.setAttribute('data-link-cell', '');

            var linkText = document.createElement('input');
            linkText.type = 'text';
            linkText.setAttribute('data-link-text', '');
            linkText.placeholder = 'Текст ссылки';
            linkText.value = String(linkValue.text || '');

            var linkUrl = document.createElement('input');
            linkUrl.type = 'text';
            linkUrl.setAttribute('data-link-url', '');
            linkUrl.placeholder = 'https://...';
            linkUrl.value = String(linkValue.url || '');

            linkWrap.appendChild(linkText);
            linkWrap.appendChild(linkUrl);
            td.appendChild(linkWrap);
            return;
        }

        if (type === 'image') {
            var imageValue = convertCellValueToType(value, 'image');

            var imageWrap = document.createElement('div');
            imageWrap.className = 'sb-public-table-cell-image';
            imageWrap.setAttribute('data-image-cell', '');

            var imageSrc = document.createElement('input');
            imageSrc.type = 'text';
            imageSrc.setAttribute('data-image-src', '');
            imageSrc.placeholder = '/upload/... или https://...';
            imageSrc.value = String(imageValue.src || '');

            var imageAlt = document.createElement('input');
            imageAlt.type = 'text';
            imageAlt.setAttribute('data-image-alt', '');
            imageAlt.placeholder = 'Описание';
            imageAlt.value = String(imageValue.alt || '');

            imageWrap.appendChild(imageSrc);
            imageWrap.appendChild(imageAlt);
            td.appendChild(imageWrap);
            return;
        }

        if (type === 'formula') {
            var formulaSpan = document.createElement('span');

            formulaSpan.className = 'sb-public-table-formula-value';
            formulaSpan.setAttribute('data-formula-cell', '');
            formulaSpan.textContent = calculateFormula(content, row, column.formula || '');

            td.appendChild(formulaSpan);
        }
    }

    function renderTableFromContent(root, sourceContent) {
        ensureSettingsControls(root);

        var content = sourceContent || getContent(root);
        var table = root.querySelector('.sb-public-table');

        if (!table) {
            return;
        }

        var columns = Array.isArray(content.columns) ? content.columns : [];
        var rows = Array.isArray(content.rows) ? content.rows : [];
        var settings = normalizeSettings(content.settings || {});

        if (!columns.length) {
            var fallback = getContent(root);

            if (Array.isArray(fallback.columns) && fallback.columns.length) {
                columns = fallback.columns;
                rows = Array.isArray(fallback.rows) ? fallback.rows : rows;
                settings = normalizeSettings(fallback.settings || settings);
            }
        }

        if (!columns.length) {
            console.warn('SiteBuilder table: render stopped because columns is empty');
            return;
        }

        columns = columns.map(function (column, index) {
            var id = String(column.id || ('c_' + (index + 1)));

            return {
                id: id,
                code: normalizeColumnCode(column.code || '', index, id),
                label: String(column.label || ('Столбец ' + (index + 1))),
                width: clampWidth(column.width || 160),
                align: normalizeAlign(column.align || 'left'),
                type: normalizeType(column.type || 'text'),
                formula: String(column.formula || '')
            };
        });

        rows = rows.map(function (row, index) {
            return {
                id: String(row.id || ('row_' + (index + 1))),
                cells: row.cells || {}
            };
        });

        content.columns = columns;
        content.rows = rows;
        content.settings = settings;

        setContent(root, content);
        setSettingsToDom(root, settings);

        var colgroup = table.querySelector('colgroup');

        if (!colgroup) {
            colgroup = document.createElement('colgroup');
            table.insertBefore(colgroup, table.firstChild);
        }

        colgroup.innerHTML = '';

        var controlCol = document.createElement('col');
        controlCol.className = 'sb-public-table__control-col';
        controlCol.style.width = '72px';
        colgroup.appendChild(controlCol);

        columns.forEach(function (column) {
            var col = document.createElement('col');

            col.setAttribute('data-column-id', column.id);
            col.setAttribute('width', String(column.width));
            col.style.width = column.width + 'px';

            colgroup.appendChild(col);
        });

        var thead = table.querySelector('thead');

        if (!thead) {
            thead = document.createElement('thead');
            table.appendChild(thead);
        }

        var headRow = thead.querySelector('tr');

        if (!headRow) {
            headRow = document.createElement('tr');
            thead.appendChild(headRow);
        }

        headRow.innerHTML = '';

        var controlTh = document.createElement('th');
        controlTh.className = 'sb-public-table__control-th';
        controlTh.textContent = '№';
        headRow.appendChild(controlTh);

        columns.forEach(function (column) {
            var th = document.createElement('th');

            th.setAttribute('data-column-id', column.id);
            th.setAttribute('data-column-align-value', column.align);
            th.setAttribute('data-column-type-value', column.type);
            th.style.textAlign = column.align;

            var inner = document.createElement('div');
            inner.className = 'sb-public-table-th-inner';

            var label = document.createElement('span');
            label.className = 'sb-public-table__th-text';
            label.setAttribute('contenteditable', 'true');
            label.setAttribute('data-column-label', '');
            label.textContent = column.label;

            var code = document.createElement('span');
            code.className = 'sb-public-table-column-code';
            code.setAttribute('data-column-code', '');
            code.textContent = column.code;

            var typeSelect = createTypeSelect(column.type);

            var formulaInput = document.createElement('input');
            formulaInput.className = 'sb-public-table-formula-input';
            formulaInput.type = 'text';
            formulaInput.placeholder = 'Например: c1 * c2';
            formulaInput.value = column.formula || '';
            formulaInput.setAttribute('data-column-formula', '');

            if (column.type !== 'formula') {
                formulaInput.style.display = 'none';
            }

            var formulaTools = createFormulaTools(columns, column);
            var alignSelect = createAlignSelect(column.align);

            var deleteButton = document.createElement('button');
            deleteButton.className = 'sb-public-table-column-delete';
            deleteButton.type = 'button';
            deleteButton.setAttribute('data-table-delete-column', '');
            deleteButton.title = 'Удалить столбец';
            deleteButton.textContent = 'Удалить столбец';

            inner.appendChild(label);
            inner.appendChild(code);
            inner.appendChild(typeSelect);
            inner.appendChild(formulaInput);
            inner.appendChild(formulaTools);
            inner.appendChild(alignSelect);
            inner.appendChild(deleteButton);

            var resizer = document.createElement('span');
            resizer.className = 'sb-public-table-resizer';
            resizer.setAttribute('data-column-resizer', '');

            th.appendChild(inner);
            th.appendChild(resizer);

            headRow.appendChild(th);
        });

        var tbody = table.querySelector('tbody');

        if (!tbody) {
            tbody = document.createElement('tbody');
            table.appendChild(tbody);
        }

        tbody.innerHTML = '';

        if (!rows.length) {
            var emptyTr = document.createElement('tr');
            emptyTr.setAttribute('data-empty-row', '');

            var emptyTd = document.createElement('td');
            emptyTd.setAttribute('colspan', String(columns.length + 1));
            emptyTd.textContent = 'Нет данных';

            emptyTr.appendChild(emptyTd);
            tbody.appendChild(emptyTr);
        } else {
            rows.forEach(function (row, rowIndex) {
                row.cells = row.cells || {};

                var tr = document.createElement('tr');
                tr.setAttribute('data-row-id', row.id);

                var controlTd = document.createElement('td');
                controlTd.className = 'sb-public-table__control-td';
                controlTd.innerHTML = ''
                    + '<div class="sb-public-table-row-actions">'
                    + '  <span class="sb-public-table-row-num">' + (rowIndex + 1) + '</span>'
                    + '  <button type="button" class="sb-public-table-row-delete" data-table-delete-row title="Удалить строку">×</button>'
                    + '</div>';

                tr.appendChild(controlTd);

                columns.forEach(function (column) {
                    var td = document.createElement('td');

                    renderCellEditor(td, column, row, content);

                    tr.appendChild(td);
                });

                tbody.appendChild(tr);
            });
        }

        applyWidths(root);
        applyAllAligns(root);
        renumberRows(root);
        updateFormulaCells(root);
        applyPagination(root);
    }

    function updateFormulaCells(root) {
        var content = collectContentFromDom(root);
        var table = root.querySelector('.sb-public-table');

        if (!table) {
            return;
        }

        var formulaColumns = content.columns.filter(function (column) {
            return column.type === 'formula';
        });

        table.querySelectorAll('tbody tr[data-row-id]').forEach(function (tr) {
            var rowId = String(tr.getAttribute('data-row-id') || '');
            var row = content.rows.find(function (item) {
                return String(item.id || '') === rowId;
            });

            if (!row) {
                return;
            }

            formulaColumns.forEach(function (column) {
                var td = tr.querySelector('td[data-column-id="' + cssEscape(column.id) + '"]');
                var cell = td ? td.querySelector('[data-formula-cell]') : null;

                if (cell) {
                    cell.textContent = calculateFormula(content, row, column.formula);
                }
            });
        });

        setContent(root, content);
    }

    function applyPagination(root) {
        var content = getContent(root);
        var settings = normalizeSettings(content.settings || {});
        var rows = Array.isArray(content.rows) ? content.rows : [];
        var tbodyRows = Array.prototype.slice.call(root.querySelectorAll('tbody tr[data-row-id]'));
        var paginationBox = ensurePaginationBox(root);

        if (!settings.pagination || !rows.length) {
            paginationBox.innerHTML = '';

            tbodyRows.forEach(function (tr) {
                tr.style.display = '';
            });

            return;
        }

        var pageSize = settings.pageSize || 10;
        var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));

        if (settings.currentPage > totalPages) {
            settings.currentPage = totalPages;
        }

        if (settings.currentPage < 1) {
            settings.currentPage = 1;
        }

        var start = (settings.currentPage - 1) * pageSize;
        var end = start + pageSize;

        tbodyRows.forEach(function (tr, index) {
            tr.style.display = index >= start && index < end ? '' : 'none';

            var num = tr.querySelector('.sb-public-table-row-num');

            if (num) {
                num.textContent = String(index + 1);
            }
        });

        paginationBox.innerHTML = '';

        if (totalPages <= 1) {
            content.settings = settings;
            setContent(root, content);
            return;
        }

        var prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.textContent = 'Назад';
        prevBtn.disabled = settings.currentPage <= 1;
        prevBtn.setAttribute('data-table-page-prev', '');

        var info = document.createElement('span');
        info.className = 'sb-public-table-pagination__info';
        info.textContent = 'Страница ' + settings.currentPage + ' из ' + totalPages + ', строк: ' + rows.length;

        var nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.textContent = 'Вперёд';
        nextBtn.disabled = settings.currentPage >= totalPages;
        nextBtn.setAttribute('data-table-page-next', '');

        paginationBox.appendChild(prevBtn);
        paginationBox.appendChild(info);
        paginationBox.appendChild(nextBtn);

        content.settings = settings;
        setContent(root, content);
    }

    function setTablePage(root, page) {
        var content = getContent(root);

        content.settings = normalizeSettings(content.settings || {});
        content.settings.currentPage = page;

        setContent(root, content);
        applyPagination(root);
        setDirty(root, true);
    }

    function updateColumnWidth(root, columnId, width) {
        var content = collectContentFromDom(root);
        var columns = Array.isArray(content.columns) ? content.columns : [];

        width = clampWidth(width);

        columns = columns.map(function (column) {
            if (String(column.id || '') === String(columnId)) {
                column.width = width;
            }

            return column;
        });

        content.columns = columns;
        setContent(root, content);

        applyWidths(root);
        applyAllAligns(root);
        applyPagination(root);
        setDirty(root, true);
    }

    function renumberRows(root) {
        root.querySelectorAll('tbody tr[data-row-id]').forEach(function (tr, index) {
            var num = tr.querySelector('.sb-public-table-row-num');

            if (num) {
                num.textContent = String(index + 1);
            }
        });
    }

    function generateColumnId(columns) {
        var used = {};

        columns.forEach(function (column) {
            used[String(column.id || '')] = true;
        });

        for (var i = 1; i <= 9999; i++) {
            var id = 'c_' + i;

            if (!used[id]) {
                return id;
            }
        }

        return 'c_' + Date.now();
    }

    function addColumn(root) {
        var content = collectContentFromDom(root);
        var columns = Array.isArray(content.columns) ? content.columns : [];
        var rows = Array.isArray(content.rows) ? content.rows : [];

        var newIndex = columns.length + 1;
        var newColumnId = generateColumnId(columns);

        columns.push({
            id: newColumnId,
            code: 'c' + newIndex,
            label: 'Столбец ' + newIndex,
            width: 160,
            align: 'left',
            type: 'text',
            formula: ''
        });

        rows = rows.map(function (row) {
            row.cells = row.cells || {};
            row.cells[newColumnId] = '';
            return row;
        });

        content.columns = columns;
        content.rows = rows;

        setContent(root, content);
        renderTableFromContent(root, content);
        setDirty(root, true);
    }

    function deleteColumn(root, columnId) {
        var content = collectContentFromDom(root);
        var columns = Array.isArray(content.columns) ? content.columns : [];
        var rows = Array.isArray(content.rows) ? content.rows : [];

        if (columns.length <= 1) {
            alert('Нельзя удалить последний столбец');
            return;
        }

        var column = columns.find(function (item) {
            return String(item.id || '') === String(columnId);
        });

        var columnName = column && column.label ? column.label : columnId;

        if (!confirm('Удалить столбец "' + columnName + '"? Данные в этом столбце будут удалены.')) {
            return;
        }

        columns = columns.filter(function (item) {
            return String(item.id || '') !== String(columnId);
        });

        rows = rows.map(function (row) {
            row.cells = row.cells || {};
            delete row.cells[columnId];
            return row;
        });

        content.columns = columns;
        content.rows = rows;

        setContent(root, content);
        renderTableFromContent(root, content);
        setDirty(root, true);
    }

    function addRow(root) {
        var content = collectContentFromDom(root);
        var columns = Array.isArray(content.columns) ? content.columns : [];
        var rows = Array.isArray(content.rows) ? content.rows : [];
        var settings = normalizeSettings(content.settings || {});

        if (settings.maxRows > 0 && rows.length >= settings.maxRows) {
            alert('Достигнут лимит строк: ' + settings.maxRows);
            return;
        }

        var rowId = 'row_' + Date.now();
        var cells = {};

        columns.forEach(function (column) {
            if (column.type !== 'formula') {
                cells[column.id] = convertCellValueToType('', column.type);
            }
        });

        rows.push({
            id: rowId,
            cells: cells
        });

        content.rows = rows;
        content.settings = settings;

        setContent(root, content);
        renderTableFromContent(root, content);
        setDirty(root, true);
    }

    function deleteRow(root, btn) {
        var tr = btn.closest('tr[data-row-id]');

        if (!tr) {
            return;
        }

        var rowId = String(tr.getAttribute('data-row-id') || '');

        if (!confirm('Удалить строку?')) {
            return;
        }

        var content = collectContentFromDom(root);
        var rows = Array.isArray(content.rows) ? content.rows : [];

        content.rows = rows.filter(function (row) {
            return String(row.id || '') !== rowId;
        });

        content.settings = normalizeSettings(content.settings || {});

        setContent(root, content);
        renderTableFromContent(root, content);
        setDirty(root, true);
    }

    function saveBlock(root) {
        var blockId = Number(root.getAttribute('data-block-id') || 0);
        var content = collectContentFromDom(root);
        var props = parseJson(root.getAttribute('data-props'), {});

        if (!blockId) {
            alert('Не найден ID блока таблицы');
            return;
        }

        setContent(root, content);

        var formData = new FormData();

        formData.append('action', 'block.update');
        formData.append('sessid', sessid);
        formData.append('id', String(blockId));
        formData.append('content', JSON.stringify(content));
        formData.append('props', JSON.stringify(props || {}));

        var btn = root.querySelector('[data-table-save-all]');

        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Сохраняю...';
        }

        fetch(API_URL, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (res) {
                if (!res || !res.ok) {
                    throw new Error((res && (res.message || res.error)) || 'SAVE_ERROR');
                }

                setDirty(root, false);

                if (btn) {
                    btn.textContent = 'Сохранено';

                    setTimeout(function () {
                        btn.textContent = 'Сохранить изменения';
                    }, 1000);
                }
            })
            .catch(function (err) {
                console.error(err);
                alert('Не удалось сохранить таблицу: ' + err.message);
                setDirty(root, true);
            })
            .finally(function () {
                if (btn) {
                    btn.disabled = false;
                }
            });
    }

    function startResize(e, th) {
        var root = th.closest('[data-public-editable-table]');
        var table = root ? root.querySelector('.sb-public-table') : null;
        var columnId = String(th.getAttribute('data-column-id') || '');

        if (!root || !table || !columnId) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        activeResize = {
            root: root,
            table: table,
            columnId: columnId,
            startX: getClientX(e),
            startWidth: getColumnCurrentWidth(table, columnId)
        };

        document.body.classList.add('sb-public-table-resizing');
    }

    function moveResize(e) {
        if (!activeResize) {
            return;
        }

        e.preventDefault();

        var diff = getClientX(e) - activeResize.startX;
        var newWidth = activeResize.startWidth + diff;

        updateColumnWidth(activeResize.root, activeResize.columnId, newWidth);
    }

    function stopResize() {
        if (!activeResize) {
            return;
        }

        activeResize = null;
        document.body.classList.remove('sb-public-table-resizing');
    }

    function initTable(root) {
        ensureSettingsControls(root);
        ensurePaginationBox(root);

        var content = collectContentFromDom(root);

        if (!content.columns || !content.columns.length) {
            return;
        }

        content.settings = normalizeSettings(content.settings || {});

        setContent(root, content);
        setSettingsToDom(root, content.settings);
        renderTableFromContent(root, content);
        setDirty(root, false);
    }

    function initAllTables() {
        document.querySelectorAll('[data-public-editable-table]').forEach(initTable);
    }

    document.addEventListener('input', function (e) {
        var root = e.target.closest('[data-public-editable-table]');

        if (!root) {
            return;
        }

        if (e.target.matches('[data-number-cell]')) {
            e.target.value = formatNumberInputLive(e.target.value);
        }

        if (e.target.matches('[data-date-cell]')) {
            e.target.value = formatDateInputLive(e.target.value);
        }

        if (
            e.target.matches('[data-table-title-input]') ||
            e.target.matches('[data-table-max-rows]') ||
            e.target.matches('[data-table-page-size]') ||
            e.target.matches('[data-column-label]') ||
            e.target.matches('[data-cell-editable]') ||
            e.target.matches('[data-column-formula]') ||
            e.target.matches('[data-link-text]') ||
            e.target.matches('[data-link-url]') ||
            e.target.matches('[data-image-src]') ||
            e.target.matches('[data-image-alt]') ||
            e.target.matches('[data-number-cell]') ||
            e.target.matches('[data-date-cell]')
        ) {
            var content = collectContentFromDom(root);

            content.settings = normalizeSettings(content.settings || {});

            if (
                e.target.matches('[data-table-max-rows]') ||
                e.target.matches('[data-table-page-size]')
            ) {
                content.settings.currentPage = 1;
            }

            setContent(root, content);
            updateFormulaCells(root);
            applyPagination(root);
            setDirty(root, true);
        }
    }, true);

    document.addEventListener('blur', function (e) {
        var root = e.target.closest('[data-public-editable-table]');

        if (!root) {
            return;
        }

        if (e.target.matches('[data-number-cell]')) {
            e.target.value = normalizeNumberValue(e.target.value);
        }

        if (e.target.matches('[data-date-cell]')) {
            e.target.value = normalizeDateValue(e.target.value);
        }

        if (e.target.matches('[data-number-cell], [data-date-cell]')) {
            var content = collectContentFromDom(root);

            setContent(root, content);
            updateFormulaCells(root);
            applyPagination(root);
            setDirty(root, true);
        }
    }, true);

    document.addEventListener('change', function (e) {
        var paginationCheckbox = e.target.closest('[data-table-pagination-enabled]');

        if (paginationCheckbox) {
            var paginationRoot = paginationCheckbox.closest('[data-public-editable-table]');

            if (!paginationRoot) {
                return;
            }

            e.stopImmediatePropagation();

            var paginationContent = collectContentFromDom(paginationRoot);

            paginationContent.settings = normalizeSettings(paginationContent.settings || {});
            paginationContent.settings.currentPage = 1;

            setContent(paginationRoot, paginationContent);
            applyPagination(paginationRoot);
            setDirty(paginationRoot, true);
            return;
        }

        var typeSelect = e.target.closest('[data-column-type]');

        if (typeSelect) {
            var typeRoot = typeSelect.closest('[data-public-editable-table]');

            if (!typeRoot) {
                return;
            }

            e.stopImmediatePropagation();

            var typeContent = collectContentFromDom(typeRoot);

            if (!typeContent.columns || !typeContent.columns.length) {
                console.warn('SiteBuilder table: type change stopped because columns is empty');
                return;
            }

            setContent(typeRoot, typeContent);
            renderTableFromContent(typeRoot, typeContent);
            setDirty(typeRoot, true);
            return;
        }

        var select = e.target.closest('[data-column-align]');

        if (!select) {
            return;
        }

        var root = select.closest('[data-public-editable-table]');
        var th = select.closest('th[data-column-id]');
        var columnId = th ? String(th.getAttribute('data-column-id') || '') : '';

        if (!root || !columnId) {
            return;
        }

        e.stopImmediatePropagation();

        applyColumnAlign(root, columnId, normalizeAlign(select.value));

        setContent(root, collectContentFromDom(root));
        applyPagination(root);
        setDirty(root, true);
    }, true);

    document.addEventListener('click', function (e) {
        var formulaInsertBtn = e.target.closest('[data-formula-insert-btn]');

        if (formulaInsertBtn) {
            var formulaRoot = formulaInsertBtn.closest('[data-public-editable-table]');
            var formulaTh = formulaInsertBtn.closest('th[data-column-id]');
            var formulaInput = formulaTh ? formulaTh.querySelector('[data-column-formula]') : null;
            var formulaSelect = formulaTh ? formulaTh.querySelector('[data-formula-insert-column]') : null;

            if (formulaRoot && formulaInput && formulaSelect && formulaSelect.value) {
                e.preventDefault();
                e.stopImmediatePropagation();

                formulaInput.value = String(formulaInput.value || '').trim();

                if (formulaInput.value !== '') {
                    formulaInput.value += ' ';
                }

                formulaInput.value += formulaSelect.value;

                setContent(formulaRoot, collectContentFromDom(formulaRoot));
                updateFormulaCells(formulaRoot);
                applyPagination(formulaRoot);
                setDirty(formulaRoot, true);
            }

            return;
        }

        var formulaOpBtn = e.target.closest('[data-formula-op]');

        if (formulaOpBtn) {
            var opRoot = formulaOpBtn.closest('[data-public-editable-table]');
            var opTh = formulaOpBtn.closest('th[data-column-id]');
            var opInput = opTh ? opTh.querySelector('[data-column-formula]') : null;
            var op = formulaOpBtn.getAttribute('data-formula-op') || '';

            if (opRoot && opInput && op) {
                e.preventDefault();
                e.stopImmediatePropagation();

                opInput.value = String(opInput.value || '').trim();

                if (opInput.value !== '' && op !== ')') {
                    opInput.value += ' ';
                }

                opInput.value += op;

                if (op !== '(') {
                    opInput.value += ' ';
                }

                setContent(opRoot, collectContentFromDom(opRoot));
                updateFormulaCells(opRoot);
                applyPagination(opRoot);
                setDirty(opRoot, true);
            }

            return;
        }

        var deleteColumnBtn = e.target.closest('[data-table-delete-column]');

        if (deleteColumnBtn) {
            var deleteRoot = deleteColumnBtn.closest('[data-public-editable-table]');
            var deleteTh = deleteColumnBtn.closest('th[data-column-id]');
            var deleteColumnId = deleteTh ? String(deleteTh.getAttribute('data-column-id') || '') : '';

            if (deleteRoot && deleteColumnId) {
                e.preventDefault();
                e.stopImmediatePropagation();
                deleteColumn(deleteRoot, deleteColumnId);
            }

            return;
        }

        var root = e.target.closest('[data-public-editable-table]');

        if (!root) {
            return;
        }

        if (e.target.closest('[data-table-page-prev]')) {
            e.preventDefault();
            e.stopImmediatePropagation();

            var prevContent = getContent(root);
            var prevSettings = normalizeSettings(prevContent.settings || {});

            setTablePage(root, prevSettings.currentPage - 1);
            return;
        }

        if (e.target.closest('[data-table-page-next]')) {
            e.preventDefault();
            e.stopImmediatePropagation();

            var nextContent = getContent(root);
            var nextSettings = normalizeSettings(nextContent.settings || {});

            setTablePage(root, nextSettings.currentPage + 1);
            return;
        }

        if (e.target.closest('[data-table-add-column]')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            addColumn(root);
            return;
        }

        if (e.target.closest('[data-table-add-row]')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            addRow(root);
            return;
        }

        if (e.target.closest('[data-table-save-all]')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            saveBlock(root);
            return;
        }

        var deleteBtn = e.target.closest('[data-table-delete-row]');

        if (deleteBtn) {
            e.preventDefault();
            e.stopImmediatePropagation();
            deleteRow(root, deleteBtn);
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.target.matches('[data-cell-editable], [data-column-label]')) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                e.target.blur();
            }
        }
    }, true);

    document.addEventListener('mousedown', function (e) {
        if (
            e.target.closest('[data-column-align]') ||
            e.target.closest('[data-column-type]') ||
            e.target.closest('[data-column-formula]') ||
            e.target.closest('[data-table-delete-column]') ||
            e.target.closest('[data-link-text]') ||
            e.target.closest('[data-link-url]') ||
            e.target.closest('[data-image-src]') ||
            e.target.closest('[data-image-alt]') ||
            e.target.closest('[data-number-cell]') ||
            e.target.closest('[data-date-cell]') ||
            e.target.closest('[data-formula-insert-column]') ||
            e.target.closest('[data-formula-insert-btn]') ||
            e.target.closest('[data-formula-op]') ||
            e.target.closest('[data-table-max-rows]') ||
            e.target.closest('[data-table-page-size]') ||
            e.target.closest('[data-table-pagination-enabled]') ||
            e.target.closest('[data-table-page-prev]') ||
            e.target.closest('[data-table-page-next]')
        ) {
            return;
        }

        var resizer = e.target.closest('[data-column-resizer]');

        if (resizer) {
            var thFromResizer = resizer.closest('th[data-column-id]');

            if (thFromResizer) {
                startResize(e, thFromResizer);
            }

            return;
        }

        var th = e.target.closest('.sb-public-table--editable th[data-column-id]');

        if (!th) {
            return;
        }

        var rect = th.getBoundingClientRect();
        var distanceFromRight = rect.right - e.clientX;

        if (distanceFromRight >= 0 && distanceFromRight <= 18) {
            startResize(e, th);
        }
    }, true);

    document.addEventListener('mousemove', moveResize, true);
    document.addEventListener('mouseup', stopResize, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllTables);
    } else {
        initAllTables();
    }
})();