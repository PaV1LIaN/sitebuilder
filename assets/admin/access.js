(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('sba-config').textContent);
    const $ = id => document.getElementById(`sba-${id}`);
    const labels = { INHERIT: 'Наследовать', VIEWER: 'Чтение', EDITOR: 'Редактирование',
        ADMIN: 'Администратор сайта', OWNER: 'Владелец', DENY: 'Нет доступа', NONE: 'Нет доступа',
        PORTAL_ADMIN: 'Администратор Битрикс', ADD: 'Добавление', FULL: 'Полный доступ', CUSTOM: 'Нестандартное правило', UNKNOWN: 'В настройках Диска' };
    const errors = {
        ACCESS_DENIED: 'Нет прав на управление доступом.', BAD_SESSID: 'Сессия истекла. Обновите страницу.',
        ACCESS_VERSION_CONFLICT: 'Права изменены другим запросом. Пакет не сохранён. Перечитайте права кнопкой «Обновить / отменить изменения».',
        LAST_OWNER_PROTECTED: 'Нельзя снять права последнего владельца сайта. Сначала назначьте другого владельца.',
        USER_VALIDATION_FAILED: 'Весь текущий пакет отменён: в нём есть недоступный пользователь.',
        MODE_READ_ONLY: 'В этом режиме запись через новую админку пока недоступна.',
        REQUIRED_TABLE_MISSING: 'Не найдена одна из существующих таблиц Sitebuilder. Проверьте ранее установленные миграции на странице развёртывания.',
        DISK_ROOT_NOT_CONFIGURED: 'Корневая папка диска ещё не настроена. Укажите её в настройках блока.',
        TOO_MANY_USER_MATCHES: 'Слишком много совпадений. Используйте ID или логины вместо ФИО.',
        USER_LIST_LIMIT: 'Допустимо до 5 000 строк, не более 250 символов в строке.',
        LINKED_FOLDER_NOT_SUPPORTED: 'Это ссылка на другую папку. Откройте исходную папку в её диске.',
        ACCESS_BUSY: 'Объект занят другим изменением. Повторите сохранение.',
        ACCESS_ADMIN_SERVER_ERROR: 'Ошибка сервера. Текущий пакет не подтверждён; повторное сохранение использует тот же идентификатор.',
        DISK_RIGHTS_BUSY: 'Права этой папки меняются другим запросом. Повторите сохранение.',
        NATIVE_PORTAL_ADMIN_REQUIRED: 'Штатные права Диска изменяет только администратор Битрикс.',
        NATIVE_PACKET_FAILED: 'Пакет Битрикс.Диска не подтверждён. Ниже указаны этап и все затронутые сотрудники, а не предполагаемый виновник. Повтор использует тот же идентификатор.',
        NATIVE_RECONCILIATION_REQUIRED: 'Состояние прав отличается и от исходного, и от ожидаемого. Автоматическая перезапись остановлена. Перечитайте права перед новым назначением.',
        PAGE_HIERARCHY_INVALID: 'Обнаружен цикл или слишком большая глубина страниц. Доступ не выдан.',
        REQUEST_ID_REUSED: 'Этот идентификатор операции уже использован для другого запроса.',
    };
    const state = { target: null, revision: '', rows: new Map(), offset: 0, total: 0, hasMore: false,
        saving: false, resolving: false, pending: null, generation: 0 };
    let searchGeneration = 0, pasteGeneration = 0, searchTimer, pasteTimer;
    const node = (tag, text, cls) => {
        const element = document.createElement(tag);
        if (text !== undefined) element.textContent = text;
        if (cls) element.className = cls;
        return element;
    };
    const message = (text, error = false) => {
        $('message').hidden = !text;
        $('message').textContent = text;
        $('message').className = error ? 'sba-error' : '';
    };
    function errorText(error) {
        const detail = error.details || {};
        const users = (detail.users || []).map(u => `${u.name || ''} (ID ${u.userId}): ${u.error}${u.groups?.length ? '; группы Битрикс: ' + u.groups.join(', ') : ''}`);
        if (detail.userId) users.push(`Пользователь ID ${detail.userId}, строка пакета ${detail.row || '—'}`);
        return [errors[error.code] || error.message, detail.folderId ? `Папка ID ${detail.folderId}` : '', detail.phase ? `Этап: ${detail.phase}` : '', ...users, error.incident ? `Код диагностики: ${error.incident}` : ''].filter(Boolean).join('\n');
    }
    async function api(action, payload = {}) {
        let response;
        try {
            response = await fetch(`${config.basePath}/api/access_admin.php`, { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams({ action, sessid: config.sessid, payload: JSON.stringify(payload) }) });
        } catch (_) {
            throw new Error('Нет ответа сервера. При сохранении повторите запрос: уже записанный пакет не будет применён повторно.');
        }
        let result;
        try { result = await response.json(); }
        catch (_) { throw new Error(`Сервер вернул некорректный ответ (HTTP ${response.status}).`); }
        if (!response.ok || !result.ok) {
            const error = new Error(result.error || `HTTP ${response.status}`);
            Object.assign(error, { code: result.error, details: result.details, incident: result.incident, status: response.status });
            throw error;
        }
        return result.data;
    }
    function roleOptions(kind, diskColumn = false) {
        if (kind === 'site') return ['INHERIT', 'VIEWER', 'EDITOR', 'ADMIN', 'OWNER'];
        if (kind === 'page') return ['INHERIT', 'VIEWER', 'EDITOR', ...(!diskColumn && state.target?.pageDenyAvailable ? ['DENY'] : [])];
        return state.target?.mode === 'bitrix_disk'
            ? ['INHERIT', 'DENY', 'VIEWER', 'ADD', 'EDITOR', 'FULL'] : ['INHERIT', 'DENY', 'VIEWER', 'EDITOR'];
    }
    function fillOptions(select, options, value, prompt = false) {
        select.replaceChildren();
        if (prompt) { const o = node('option', 'Не менять'); o.value = ''; select.append(o); }
        for (const role of options) {
            const o = node('option', state.target?.kind === 'site' && role === 'EDITOR' ? 'Работа с файлами' : labels[role] || role);
            o.value = role; select.append(o);
        }
        if (value === 'CUSTOM' && !options.includes(value)) {
            const custom = node('option', labels.CUSTOM); custom.value = value; custom.disabled = true; select.append(custom);
        }
        select.value = value;
    }
    function makeSelect(row, key, options) {
        const select = node('select');
        fillOptions(select, options, row.assignment[key]);
        select.setAttribute('aria-label', `${key === 'diskRole' ? 'Диск страницы' : 'Право'}: ${row.user.name}`);
        select.disabled = row.protected || !state.target.editable || !!state.pending;
        select.addEventListener('change', () => { row.assignment[key] = select.value; row.dirty = true; renderRows(); });
        return select;
    }
    const dirtyRows = () => [...state.rows.values()].filter(row => row.dirty);
    function renderRows() {
        const page = state.target?.kind === 'page';
        const head = node('tr'), checkAll = node('input');
        checkAll.type = 'checkbox'; checkAll.setAttribute('aria-label', 'Выбрать всех отображаемых сотрудников');
        checkAll.disabled = !state.target?.editable;
        checkAll.addEventListener('change', () => { for (const row of state.rows.values()) row.selected = checkAll.checked && !row.protected; renderRows(); });
        const first = node('th'); first.append(checkAll); head.append(first);
        for (const title of ['Пользователь', 'Назначено здесь', ...(page ? ['Диск страницы', 'Дочерние'] : []), 'Итоговый доступ / источник']) head.append(node('th', title));
        $('head').replaceChildren(head);
        const body = document.createDocumentFragment();
        for (const row of state.rows.values()) {
            const tr = node('tr', undefined, row.dirty ? 'sba-dirty' : '');
            const checkCell = node('td'), check = node('input'); check.type = 'checkbox'; check.checked = !!row.selected;
            check.disabled = row.protected || !state.target.editable;
            check.setAttribute('aria-label', `Выбрать ${row.user.name}`);
            check.addEventListener('change', () => { row.selected = check.checked; });
            checkCell.append(check); tr.append(checkCell);
            const person = node('td'); person.append(node('span', row.user.name, 'sba-user-name'),
                node('span', `ID ${row.user.id}${row.user.login ? ' · ' + row.user.login : ''}${row.user.active ? '' : ' · Неактивен / удалён'}`, 'sba-meta'));
            if (row.protected) person.append(node('span', 'Защищённый административный доступ', 'sba-meta'));
            tr.append(person);
            const rights = node('td');
            if (state.target.editable) rights.append(makeSelect(row, 'role', roleOptions(state.target.kind)));
            else rights.append(node('span', labels[row.assignment.role] || row.assignment.role || 'Наследование'));
            tr.append(rights);
            if (page) {
                const disk = node('td'); disk.append(makeSelect(row, 'diskRole', roleOptions('page', true)));
                if (row.assignment.role === 'DENY') disk.append(node('span', 'Право Диска сохраняется, но не действует, пока страница запрещена', 'sba-warning'));
                tr.append(disk);
                const children = node('td'), childCheck = node('input'); childCheck.type = 'checkbox';
                childCheck.checked = row.assignment.includeChildren; childCheck.disabled = row.protected || !!state.pending;
                childCheck.setAttribute('aria-label', `Применять к дочерним страницам: ${row.user.name}`);
                childCheck.addEventListener('change', () => { row.assignment.includeChildren = childCheck.checked; row.dirty = true; renderRows(); });
                children.append(childCheck); tr.append(children);
            }
            const effective = node('td');
            effective.append(node('span', labels[row.effective.role] || row.effective.role));
            if (page) effective.append(node('span', `Диск: ${labels[row.effective.diskRole] || row.effective.diskRole}`, 'sba-meta'));
            effective.append(node('span', row.effective.source, 'sba-meta'));
            if (row.effective.warning) effective.append(node('span', row.effective.warning, 'sba-warning'));
            if (row.dirty) effective.append(node('span', 'Итог показан до сохранения', 'sba-warning'));
            tr.append(effective); body.append(tr);
        }
        if (!state.rows.size) { const tr = node('tr'), td = node('td', 'Назначений пока нет. Добавьте пользователей.'); td.colSpan = page ? 6 : 4; tr.append(td); body.append(tr); }
        $('rows').replaceChildren(body);
        $('count').textContent = `Показано: ${state.rows.size}. Несохранённых изменений: ${dirtyRows().length}.`;
        $('save').disabled = state.saving || state.resolving || !state.target?.editable || (!dirtyRows().length && !state.pending);
        $('more').hidden = !state.hasMore;
    }
    function absorb(data, clear = false) {
        if (clear) state.rows.clear();
        // Never silently rebase edits onto another administrator's revision.
        if (!dirtyRows().length && !state.pending) state.revision = data.revision;
        for (const row of data.rows) {
            if (!state.rows.has(row.user.id) || !state.rows.get(row.user.id).dirty) state.rows.set(row.user.id, { ...row, selected: false, dirty: false });
        }
        state.total = data.total; state.hasMore = data.hasMore; state.offset = data.offset;
    }
    async function selectTarget(target, button) {
        if (state.saving || state.resolving) return;
        if (dirtyRows().length || state.pending) { message('Есть несохранённые изменения. Сохраните их или нажмите «Обновить / отменить изменения».', true); return; }
        const generation = ++state.generation;
        ++pasteGeneration; ++searchGeneration;
        try {
            const data = await api('view', target);
            if (generation !== state.generation) return;
            state.target = data.target; state.revision = data.revision; state.pending = null; absorb(data, true);
            $('title').textContent = data.target.title; $('note').textContent = data.note;
            $('controls').hidden = !data.target.editable; $('table-wrap').hidden = false; $('footer').hidden = false;
            $('editor').href = `${config.basePath}/editor.php?siteId=${data.target.siteId}`;
            $('history').href = `${config.basePath}/audit.php?siteId=${data.target.siteId}`;
            $('editor').hidden = false; $('history').hidden = false;
            $('paste').value = ''; $('search').value = ''; $('search-results').replaceChildren();
            $('resolve-status').textContent = ''; $('unresolved').replaceChildren();
            fillOptions($('bulk-role'), roleOptions(data.target.kind), '', true);
            fillOptions($('bulk-disk'), roleOptions('page', true), '', true);
            $('bulk-disk-wrap').hidden = data.target.kind !== 'page';
            document.querySelectorAll('.sba-selected').forEach(el => el.classList.remove('sba-selected'));
            if (button) button.classList.add('sba-selected');
            message(''); renderRows();
        } catch (error) { if (generation === state.generation) message(errorText(error), true); }
    }
    function renderNodes(nodes, list) {
        for (const target of nodes) {
            const li = node('li'), row = node('div', undefined, 'sba-tree-row');
            const expand = node('button', target.expandable ? '›' : '·', 'sba-expand'); expand.type = 'button';
            expand.disabled = !target.expandable; expand.setAttribute('aria-label', `Развернуть ${target.title}`);
            const choose = node('button', target.title, 'sba-node'); choose.type = 'button';
            choose.append(node('span', { site: 'Сайт', page: 'Страница', disk: 'Диск', folder: 'Папка' }[target.kind], 'sba-kind'));
            choose.addEventListener('click', () => selectTarget(target, choose));
            const children = node('ul'); children.hidden = true;
            let loaded = false;
            const loadMore = async (offset = 0, moreButton = null) => {
                const data = await api('tree', { ...target, offset });
                moreButton?.remove(); renderNodes(data.nodes, children);
                if (data.hasMore) { const more = node('button', 'Ещё папки'); more.type = 'button';
                    more.addEventListener('click', () => loadMore(data.nextOffset, more).catch(e => message(errorText(e), true))); children.append(more); }
            };
            expand.addEventListener('click', async () => {
                if (state.saving) return;
                expand.disabled = true;
                try {
                    if (!loaded) { await loadMore(); loaded = true; }
                    children.hidden = !children.hidden; expand.textContent = children.hidden ? '›' : '⌄';
                    expand.setAttribute('aria-expanded', String(!children.hidden));
                } catch (error) { message(errorText(error), true); }
                finally { expand.disabled = false; }
            });
            row.append(expand, choose); li.append(row, children); list.append(li);
        }
    }
    async function addUsers(users, generation = state.generation, isCurrent = () => true) {
        if (!state.target || !state.target.editable || state.saving) return;
        const unique = [...new Map(users.map(user => [user.id, user])).values()];
        if (state.pending) throw new Error('Сначала повторите неподтверждённое сохранение или отмените изменения.');
        for (let i = 0; i < unique.length; i += 100) {
            const ids = unique.slice(i, i + 100).map(user => user.id);
            const data = await api('view', { ...state.target, userIds: ids });
            if (generation !== state.generation || state.saving || !isCurrent()) return;
            if (data.revision !== state.revision) throw Object.assign(new Error(), { code: 'ACCESS_VERSION_CONFLICT' });
            for (const row of data.rows) {
                if (state.rows.has(row.user.id)) { state.rows.get(row.user.id).selected = !row.protected; continue; }
                const fresh = row.assignment.role === 'INHERIT' && !row.protected;
                if (fresh) row.assignment.role = 'VIEWER';
                state.rows.set(row.user.id, { ...row, selected: !row.protected, dirty: fresh });
            }
        }
        renderRows();
    }
    $('search').addEventListener('input', () => {
        clearTimeout(searchTimer); const generation = ++searchGeneration;
        searchTimer = setTimeout(async () => {
            try {
                const data = await api('search', { query: $('search').value });
                if (generation !== searchGeneration || state.saving) return;
                $('search-results').replaceChildren();
                for (const user of data.users) {
                    const button = node('button', `${user.name} · ID ${user.id} · ${user.login}`, 'sba-search-item'); button.type = 'button';
                    button.addEventListener('click', async () => { try { await addUsers([user]); button.remove(); } catch (e) { message(errorText(e), true); } });
                    $('search-results').append(button);
                }
            } catch (e) { if (generation === searchGeneration) message(errorText(e), true); }
        }, 350);
    });
    $('paste').addEventListener('input', () => {
        clearTimeout(pasteTimer); const generation = ++pasteGeneration;
        state.resolving = false;
        if (state.target) renderRows();
        pasteTimer = setTimeout(async () => {
            if (!state.target || state.saving || !$('paste').value.trim()) return;
            state.resolving = true; renderRows();
            const objectGeneration = state.generation;
            try {
                const { lines } = await api('parse', { text: $('paste').value });
                const users = new Map(), unresolved = [];
                for (let i = 0; i < lines.length; i += 100) {
                    if (generation !== pasteGeneration) return;
                    $('resolve-status').textContent = `Распознавание: ${i} из ${lines.length}…`;
                    const data = await api('resolve', { lines: lines.slice(i, i + 100) });
                    for (const row of data.rows) {
                        if (row.user) users.set(row.user.id, row.user); else unresolved.push(row);
                    }
                }
                if (generation !== pasteGeneration) return;
                await addUsers([...users.values()], objectGeneration, () => generation === pasteGeneration);
                if (generation !== pasteGeneration) return;
                $('unresolved').replaceChildren();
                for (const row of unresolved) {
                    const wrap = node('div', undefined, 'sba-resolution');
                    wrap.append(node('span', `Строка ${row.line}: ${row.input} — ${row.status === 'ambiguous' ? 'несколько совпадений' : 'не найдено'}`));
                    if (row.candidates.length) {
                        const select = node('select'); select.setAttribute('aria-label', `Уточнить пользователя в строке ${row.line}`);
                        const placeholder = node('option', 'Выберите сотрудника'); placeholder.value = ''; select.append(placeholder);
                        for (const user of row.candidates) { const option = node('option', `${user.name} · ID ${user.id} · ${user.login}`); option.value = user.id; select.append(option); }
                        select.addEventListener('change', async () => { const user = row.candidates.find(u => u.id === Number(select.value));
                            if (user) { try { await addUsers([user]); wrap.remove(); } catch (e) { message(errorText(e), true); } } });
                        wrap.append(select);
                    }
                    $('unresolved').append(wrap);
                }
                $('resolve-status').textContent = `Найдено уникальных сотрудников: ${users.size}. Требуют уточнения: ${unresolved.length}. Выберите право и сохраните изменения.`;
            } catch (e) { if (generation === pasteGeneration) message(errorText(e), true); }
            finally { if (generation === pasteGeneration) { state.resolving = false; renderRows(); } }
        }, 650);
    });
    function setBulk(key, value) {
        if (!value || state.saving) return;
        if (state.pending) { message('Сначала повторите неподтверждённое сохранение или отмените изменения.', true); return; }
        for (const row of state.rows.values()) {
            if (row.selected && !row.protected) { row.assignment[key] = value; row.dirty = true; }
        }
        renderRows();
    }
    $('bulk-role').addEventListener('change', () => setBulk('role', $('bulk-role').value));
    $('bulk-disk').addEventListener('change', () => setBulk('diskRole', $('bulk-disk').value));
    $('remove').addEventListener('click', () => { setBulk('role', 'INHERIT'); if (state.target.kind === 'page') setBulk('diskRole', 'INHERIT'); });
    $('more').addEventListener('click', async () => {
        if (state.saving) return;
        try { const data = await api('view', { ...state.target, offset: state.offset + 100 });
            if (data.revision !== state.revision) throw Object.assign(new Error(), { code: 'ACCESS_VERSION_CONFLICT' });
            absorb(data); renderRows(); } catch (e) { message(errorText(e), true); }
    });
    function requestId() {
        return Array.from(crypto.getRandomValues(new Uint8Array(16)), v => v.toString(16).padStart(2, '0')).join('');
    }
    $('save').addEventListener('click', async () => {
        if (state.saving || state.resolving) return;
        state.saving = true;
        const controls = [...document.querySelectorAll('#sba-app input, #sba-app select, #sba-app textarea, #sba-app button')].map(el => [el, el.disabled]);
        controls.forEach(([el]) => { el.disabled = true; });
        const savedIds = []; let confirmed = 0;
        try {
            while (state.pending || dirtyRows().length) {
                if (!state.pending) state.pending = { ...state.target, revision: state.revision, requestId: requestId(),
                    changes: dirtyRows().slice(0, 100).map(row => ({ userId: row.user.id, ...row.assignment })) };
                const data = await api('apply', state.pending);
                state.revision = data.revision;
                for (const id of data.userIds) { const row = state.rows.get(id); if (row) row.dirty = false; savedIds.push(id); }
                confirmed += data.saved; state.pending = null;
                message(`Подтверждено назначений: ${confirmed}. Осталось: ${dirtyRows().length}.`);
            }
            // Separate read requests avoid same-request permission caches after writes.
            for (let i = 0; i < savedIds.length; i += 100) {
                const data = await api('view', { ...state.target, userIds: savedIds.slice(i, i + 100) });
                for (const row of data.rows) state.rows.set(row.user.id, { ...row, selected: false, dirty: false });
                state.revision = data.revision;
            }
            message(`Сохранено назначений: ${confirmed}. Итоговые права перечитаны. Группы Битрикса не изменялись.`);
        } catch (e) {
            if (e.status && e.status < 500) state.pending = null;
            message(`Подтверждено назначений в этом запуске: ${confirmed}. ${errorText(e)}`, true);
        } finally {
            state.saving = false; controls.forEach(([el, disabled]) => { el.disabled = disabled; }); renderRows();
        }
    });
    $('reload').addEventListener('click', () => {
        if (state.saving || state.resolving) return;
        for (const row of state.rows.values()) row.dirty = false;
        state.pending = null;
        if (state.target) selectTarget(state.target); else init();
    });
    window.addEventListener('beforeunload', event => {
        if (dirtyRows().length || state.pending || state.saving) { event.preventDefault(); event.returnValue = ''; }
    });
    async function init() {
        try {
            const data = await api('tree'); const list = node('ul'); renderNodes(data.nodes, list); $('tree').replaceChildren(list);
            if (config.siteId > 0) { const target = data.nodes.find(n => n.siteId === config.siteId); if (target) await selectTarget(target); }
        } catch (e) { $('tree').textContent = 'Не удалось загрузить объекты.'; message(errorText(e), true); }
    }
    init();
})();
