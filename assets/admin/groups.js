(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('sbg-config').textContent);
    const $ = id => document.getElementById('sbg-' + id);
    const state = { group: null, members: [], rules: [], selected: new Set(), preview: new Map(), sites: [],
        target: null, busy: false, pending: null, generation: 0, listGeneration: 0, listOffset: 0, memberOffset: 0, historyOffset: 0 };
    const text = (tag, value, cls) => { const el = document.createElement(tag); if (value != null) el.textContent = value; if (cls) el.className = cls; return el; };
    const flag = v => v === true || ['t', 'true', 1, '1'].includes(v);
    const messages = {
        GROUP_MIGRATION_REQUIRED: 'Сначала примените миграцию №25 на странице развёртывания Sitebuilder.',
        ACCESS_GROUP_SCHEMA_INCOMPLETE: 'Миграция групп применена не полностью. Проверьте страницу развёртывания.',
        GROUP_NAME_EXISTS: 'Группа с таким названием уже существует. Укажите другое название.',
        INVALID_GROUP_NAME: 'Введите название от 1 до 160 символов, без переносов строк.',
        GROUP_VERSION_CONFLICT: 'Группу изменил другой администратор. Нажмите «Обновить», затем повторите нужное действие.',
        GROUP_NOT_FOUND: 'Группа уже удалена. Обновите список.', USER_VALIDATION_FAILED: 'Пакет не сохранён: есть неактивный или технический пользователь.',
        ACCESS_DENIED: 'Нет прав на управление группами.', BAD_SESSID: 'Сессия истекла. Обновите страницу.',
        DISK_ROOT_NOT_CONFIGURED: 'Корневая папка диска не настроена. Укажите тестовую папку в настройках блока.',
        FOLDER_OUT_OF_SCOPE: 'Папка больше не относится к выбранному диску.', INVALID_GROUP_RULE: 'Проверьте выбранный объект и флажки разрешений.',
        ACCESS_BUSY: 'Объект занят другим запросом. Повторите действие.', GROUP_RULE_LIMIT: 'В группе уже 1 000 правил.',
        REQUEST_ID_REUSED: 'Этот номер запроса уже использован для другого действия.',
    };
    function notify(value, error = false) { $('message').textContent = value; $('message').hidden = !value; $('message').className = 'sbg-message' + (error ? ' error' : ''); }
    function describe(e) {
        const users = (e.details?.users || []).map(u => `${u.name || ''} (ID ${u.userId}): ${u.error}`);
        return [messages[e.code] || e.message, ...users, e.incident ? `Код диагностики: ${e.incident}` : ''].filter(Boolean).join('\n');
    }
    async function api(action, payload = {}) {
        let response;
        try { response = await fetch(config.basePath + '/api/access_admin.php', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: new URLSearchParams({ action, sessid: config.sessid, payload: JSON.stringify(payload) }) }); }
        catch (_) { throw new Error('Нет ответа сервера. Не создавайте операцию заново: воспользуйтесь повтором запроса.'); }
        let result;
        try { result = await response.json(); } catch (_) { throw new Error('Сервер вернул некорректный ответ.'); }
        if (!response.ok || !result.ok) { const e = new Error(result.error || `HTTP ${response.status}`); Object.assign(e, { code: result.error, status: response.status, details: result.details, incident: result.incident }); throw e; }
        return result.data;
    }
    function lock(busy) {
        state.busy = busy;
        for (const el of document.querySelectorAll('#sbg-app button, #sbg-app input, #sbg-app select, #sbg-app textarea')) {
            if (busy) { el.dataset.wasDisabled = el.disabled ? '1' : '0'; el.disabled = true; }
            else if ('wasDisabled' in el.dataset) { el.disabled = el.dataset.wasDisabled === '1'; delete el.dataset.wasDisabled; }
        }
    }
    function requestId() { return Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join(''); }
    async function mutate(action, payload) {
        if (state.pending) throw new Error('Сначала повторите неподтверждённый запрос.');
        const data = { ...payload, requestId: requestId() };
        if (action !== 'group.create') { data.groupId = Number(state.group.id); data.version = Number(state.group.version); }
        state.pending = { action, data };
        return sendPending();
    }
    async function sendPending() {
        const pending = state.pending;
        try {
            const result = await api(pending.action, pending.data);
            state.pending = null; $('retry-panel').hidden = true;
            if (state.group && Number(state.group.id) === result.groupId) state.group.version = result.version;
            return result;
        } catch (e) {
            if (e.status && e.status < 500) state.pending = null;
            $('retry-panel').hidden = !state.pending;
            throw e;
        }
    }
    async function task(callback) {
        if (state.busy) return;
        lock(true);
        try { await callback(); } catch (e) { notify(describe(e), true); }
        finally { lock(false); syncButtons(); }
    }
    function syncButtons() {
        $('add').disabled = !state.preview.size || !state.group || state.busy || !!state.pending;
        $('remove-members').disabled = !state.selected.size || state.busy || !!state.pending;
        $('flags').disabled = !state.target || state.busy || !!state.pending;
        $('view').disabled = true;
        $('retry').disabled = state.busy;
    }
    async function loadGroups(append = false) {
        const generation = ++state.listGeneration;
        const offset = append ? state.listOffset + 100 : 0;
        const data = await api('group.list', { query: $('search').value, offset });
        if (generation !== state.listGeneration) return;
        if (!append) $('list').replaceChildren();
        for (const group of data.groups) {
            const button = text('button'); button.type = 'button'; button.dataset.group = group.id;
            button.setAttribute('aria-pressed', String(Number(state.group?.id) === Number(group.id)));
            button.append(text('strong', group.name), text('small', `${group.members_count} участников · ${group.rules_count} правил`));
            button.onclick = () => task(() => selectGroup(Number(group.id)));
            $('list').append(button);
        }
        if (!data.groups.length && !append) $('list').append(text('p', 'Группы не найдены.'));
        state.listOffset = offset; $('more-groups').hidden = !data.hasMore;
    }
    async function selectGroup(id) {
        if (state.pending) throw new Error('Сначала повторите неподтверждённый запрос.');
        notify('');
        const generation = ++state.generation;
        const data = await api('group.view', { groupId: id });
        if (generation !== state.generation) return;
        state.group = data.group; state.members = data.members; state.rules = data.rules; state.memberOffset = 0;
        state.selected.clear(); state.preview.clear(); state.target = null;
        $('name').value = data.group.name; $('id').textContent = '#' + id;
        $('summary').textContent = `${data.memberCount} участников · ${data.rules.length} назначений`;
        $('member-badge').textContent = data.memberCount; $('rule-badge').textContent = data.rules.length;
        $('paste').value = ''; $('preview-count').textContent = ''; $('unresolved').replaceChildren(); $('check-all').checked = false;
        $('site').value = ''; $('tree').replaceChildren(); $('target-title').textContent = 'Выберите сайт, страницу или папку'; $('target-note').textContent = '';
        $('empty').hidden = true; $('work').hidden = false; $('more-members').hidden = !data.hasMore;
        $('rule-panel').open = false; $('add-panel').open = false;
        for (const button of $('list').querySelectorAll('[data-group]')) button.setAttribute('aria-pressed', String(Number(button.dataset.group) === id));
        if (config.initial) history.replaceState(null, '', config.basePath + '/access.php?view=groups&groupId=' + id);
        renderMembers(); renderRules(); syncButtons();
        if ($('tab-history').getAttribute('aria-selected') === 'true') await loadHistory();
    }
    function renderMembers() {
        $('members').replaceChildren();
        for (const user of state.members) {
            const tr = text('tr'), cell = text('td'), check = text('input'); check.type = 'checkbox'; check.checked = state.selected.has(user.id);
            check.setAttribute('aria-label', `Выбрать участника ${user.id}`);
            check.onchange = () => { if (check.checked) state.selected.add(user.id); else state.selected.delete(user.id); syncButtons(); };
            cell.append(check); tr.append(cell);
            const person = text('td'), employeeLink = text('a', user.name, 'uc-link');
            employeeLink.href = config.basePath + '/access.php?view=employees&userId=' + user.id;
            person.append(employeeLink, text('small', 'ID ' + user.id));
            tr.append(person, text('td', user.login)); const status = text('td'); status.append(text('span', user.active ? 'Активен' : 'Неактивен / удалён', 'sbg-status' + (user.active ? '' : ' inactive'))); tr.append(status); $('members').append(tr);
        }
        if (!state.members.length) { const tr = text('tr'), cell = text('td', 'В группе пока нет сотрудников. Добавьте их списком выше.'); cell.colSpan = 4; tr.append(cell); $('members').append(tr); }
    }
    function renderRules() {
        $('rules').replaceChildren();
        for (const rule of state.rules) {
            const tr = text('tr'), target = text('td'), objectLink = text('a', rule.title, 'uc-link');
            objectLink.href = config.basePath + '/access.php?' + new URLSearchParams({ view: 'objects', kind: rule.kind, siteId: rule.site_id, pageId: rule.page_id, blockId: rule.block_id, folderId: rule.folder_id });
            target.append(objectLink, text('small',
                `${{ site: 'Сайт', page: 'Страница', disk: 'Диск', folder: 'Папка' }[rule.kind]} · ID ${rule.folder_id || rule.page_id || rule.site_id} · ${rule.permission_mode}`));
            const caps = text('td'); caps.append(text('span', 'Просмотр', 'sbg-badge'));
            if (flag(rule.can_edit)) caps.append(text('span', 'Редактирование', 'sbg-badge'));
            if (flag(rule.can_delete)) caps.append(text('span', 'Удаление', 'sbg-badge'));
            const inherited = text('td', rule.kind === 'site' ? 'Все страницы сайта' : (flag(rule.include_children) ? 'С вложенными' : 'Только объект'));
            if (flag(rule.open_page)) inherited.append(text('small', 'С просмотром страницы диска'));
            const action = text('td'), buttons = text('div', null, 'sbg-actions');
            const modify = text('button', 'Изменить', 'sbg-button'); modify.onclick = () => task(async () => {
                $('rule-panel').open = true;
                await selectTarget({ kind: rule.kind, siteId: Number(rule.site_id), pageId: Number(rule.page_id), blockId: Number(rule.block_id), folderId: Number(rule.folder_id) }, rule);
                $('rule-panel').scrollIntoView({ block: 'start', behavior: 'smooth' });
            });
            const remove = text('button', 'Снять', 'sbg-button sbg-danger'); remove.onclick = () => task(async () => {
                await mutate('group.rule.remove', { ruleId: Number(rule.id) }); await refreshCurrent(); notify('Правило группы снято. Личные права и другие группы не изменены.');
            });
            buttons.append(modify, remove); action.append(buttons); tr.append(target, caps, inherited, action); $('rules').append(tr);
        }
        if (!state.rules.length) { const tr = text('tr'), cell = text('td', 'Права ещё не назначены. Выберите объект и разрешённые действия.'); cell.colSpan = 4; tr.append(cell); $('rules').append(tr); }
    }
    async function refreshCurrent() { await selectGroup(Number(state.group.id)); await loadGroups(); }
    async function loadHistory(append = false) {
        const offset = append ? state.historyOffset + 50 : 0;
        const data = await api('group.history', { groupId: Number(state.group.id), offset });
        if (!append) $('history').replaceChildren();
        const labels = { create: 'Группа создана', rename: 'Название изменено', delete: 'Группа удалена', 'members.add': 'Сотрудники добавлены', 'members.remove': 'Сотрудники удалены', 'rule.put': 'Правило сохранено', 'rule.remove': 'Правило снято' };
        for (const event of data.events) {
            const el = text('div', null, 'sbg-event'); el.append(text('strong', labels[event.action.replace('accessGroup.', '')] || event.action), text('small', `${event.created_at} · администратор ID ${event.actor_user_id}`));
            const details = text('details'), summary = text('summary', 'Подробности'); details.append(summary, text('pre', JSON.stringify({ before: event.details.before, after: event.details.after }, null, 2))); el.append(details); $('history').append(el);
        }
        if (!append && !data.events.length) $('history').append(text('p', 'Изменений пока нет.', 'sbg-muted'));
        state.historyOffset = offset; $('more-history').hidden = !data.hasMore;
    }
    function showTab(tab) {
        for (const key of ['members', 'rights', 'history']) { $('panel-' + key).hidden = key !== tab; $('tab-' + key).setAttribute('aria-selected', String(key === tab)); }
    }
    document.querySelectorAll('[data-tab]').forEach(button => {
        button.onclick = () => task(async () => { showTab(button.dataset.tab); if (button.dataset.tab === 'history' && state.group) await loadHistory(); });
        button.onkeydown = event => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault(); const tabs = [...document.querySelectorAll('[data-tab]')], i = tabs.indexOf(button), next = tabs[(i + (event.key === 'ArrowRight' ? 1 : 2)) % 3]; next.focus(); next.click();
        };
    });
    $('create').onsubmit = e => { e.preventDefault(); task(async () => {
        const result = await mutate('group.create', { name: $('new-name').value }); $('new-name').value = ''; $('search').value = '';
        await loadGroups(); await selectGroup(result.groupId); showTab('members'); notify('Группа создана. Добавьте сотрудников и назначьте права.');
    }); };
    $('rename').onsubmit = e => { e.preventDefault(); task(async () => { await mutate('group.rename', { name: $('name').value }); await refreshCurrent(); notify('Название группы сохранено.'); }); };
    $('delete').onclick = () => task(async () => {
        if (!confirm(`Удалить группу «${state.group.name}»? Её участники и групповые правила будут удалены. Личные права и другие группы сохранятся.`)) return;
        await mutate('group.delete', {}); state.group = null; state.preview.clear(); state.selected.clear(); $('work').hidden = true; $('empty').hidden = false; await loadGroups(); notify('Группа удалена. Её назначения больше не действуют.');
    });
    $('resolve').onclick = () => task(async () => {
        if (state.pending) throw new Error('Сначала повторите неподтверждённое действие.');
        const { lines } = await api('parse', { text: $('paste').value }); state.preview.clear(); $('unresolved').replaceChildren(); let unresolved = 0;
        for (let offset = 0; offset < lines.length; offset += 100) {
            const data = await api('resolve', { lines: lines.slice(offset, offset + 100) });
            for (const row of data.rows) {
                if (row.user) { state.preview.set(row.user.id, row.user); continue; }
                unresolved++; const el = text('div', null, 'sbg-resolution'); el.append(text('span', `Строка ${row.line}: ${row.input} — ${row.status === 'ambiguous' ? 'несколько совпадений' : 'не найдено'}`));
                if (row.candidates?.length) {
                    const select = text('select'); select.setAttribute('aria-label', 'Уточнить строку ' + row.line);
                    const placeholder = text('option', 'Выберите сотрудника'); placeholder.value = ''; select.append(placeholder);
                    for (const user of row.candidates) { const option = text('option', `${user.name} · ID ${user.id} · ${user.login}`); option.value = user.id; select.append(option); }
                    select.onchange = () => { const user = row.candidates.find(u => u.id === Number(select.value)); if (user) { state.preview.set(user.id, user); el.remove(); $('preview-count').textContent = `Найдено уникальных сотрудников: ${state.preview.size}.`; syncButtons(); } }; el.append(select);
                }
                $('unresolved').append(el);
            }
            $('preview-count').textContent = `Распознано ${Math.min(offset + 100, lines.length)} из ${lines.length} строк…`;
        }
        $('preview-count').textContent = `Найдено уникальных сотрудников: ${state.preview.size}. Не распознано: ${unresolved}. Повторы не создадут дубликаты.`;
    });
    $('add').onclick = () => task(async () => {
        const ids = [...state.preview.keys()]; let saved = 0;
        for (let offset = 0; offset < ids.length; offset += 100) {
            const batch = ids.slice(offset, offset + 100); const result = await mutate('group.members.add', { userIds: batch }); saved += result.saved;
            batch.forEach(id => state.preview.delete(id)); notify(`Добавлено: ${saved}. Осталось: ${ids.length - offset - batch.length}.`);
        }
        await refreshCurrent(); notify(`Добавлено сотрудников: ${saved}. Существующие участники не продублированы. Права группы действуют со следующего запроса.`);
    });
    $('check-all').onchange = () => { state.selected.clear(); if ($('check-all').checked) state.members.forEach(user => state.selected.add(user.id)); renderMembers(); syncButtons(); };
    $('remove-members').onclick = () => task(async () => { const ids = [...state.selected]; let saved = 0;
        for (let i = 0; i < ids.length; i += 100) { const r = await mutate('group.members.remove', { userIds: ids.slice(i, i + 100) }); saved += r.saved; }
        await refreshCurrent(); notify(`Удалено из группы: ${saved}. Остальные источники доступа сохранены.`);
    });
    $('more-members').onclick = () => task(async () => {
        const data = await api('group.view', { groupId: Number(state.group.id), offset: state.memberOffset + 100 });
        if (Number(data.group.version) !== Number(state.group.version)) throw Object.assign(new Error(), { code: 'GROUP_VERSION_CONFLICT' });
        state.members.push(...data.members); state.memberOffset = data.offset; $('more-members').hidden = !data.hasMore; renderMembers();
    });
    $('more-groups').onclick = () => task(() => loadGroups(true)); $('more-history').onclick = () => task(() => loadHistory(true));
    let timer; $('search').oninput = () => { clearTimeout(timer); timer = setTimeout(() => { if (!state.busy) task(() => loadGroups()); }, 300); };
    $('reload').onclick = () => task(async () => { if (state.pending) throw new Error('Сначала повторите неподтверждённый запрос.'); await loadGroups(); if (state.group) await selectGroup(Number(state.group.id)); notify('Данные обновлены.'); });
    $('retry').onclick = () => task(async () => {
        const wasCreate = state.pending?.action === 'group.create';
        const result = await sendPending();
        if (wasCreate) $('new-name').value = '';
        if (result.deleted) { state.group = null; $('work').hidden = true; $('empty').hidden = false; }
        else await selectGroup(result.groupId);
        await loadGroups(); notify('Операция подтверждена. Для оставшихся строк списка повторно вставьте список: участники не продублируются.');
    });
    async function selectTarget(target, rule = null) {
        const data = await api('group.target', { target }); state.target = data.target;
        $('target-title').textContent = data.target.title;
        const disk = ['disk', 'folder'].includes(data.target.kind);
        $('delete-cap').hidden = !disk; $('open-page-wrap').hidden = !disk;
        $('view-help').textContent = disk ? 'Просмотр и скачивание файлов в разрешённой области' : 'Чтение выбранной страницы / страниц сайта';
        $('edit-help').textContent = disk ? 'Загрузка, создание папок, переименование и изменение файлов. Без удаления.' : 'Изменение содержимого страниц. Без управления группами и настройками сайта.';
        $('target-note').textContent = data.target.mode === 'bitrix_disk'
            ? 'Штатный режим: группа действует только внутри Sitebuilder и не выдаёт права в Битрикс.Диске. Итог ограничен также существующими штатными правами сотрудника.'
            : (disk ? 'Локальное групповое правило. Личные назначения и режим диска не меняются.' : 'Флажки выдают разрешения только от этой группы. Остальные источники и личные ограничения можно проверить в разделе «Объекты».');
        $('edit').checked = rule ? flag(rule.can_edit) : false; $('can-delete').checked = disk && rule ? flag(rule.can_delete) : false;
        $('children').checked = rule ? flag(rule.include_children) : true; $('open-page').checked = disk && (rule ? flag(rule.open_page) : true);
        syncButtons();
    }
    function renderTree(nodes, container) {
        const ul = text('ul'); container.append(ul);
        for (const target of nodes) {
            const li = text('li'), line = text('div'), children = text('div'); children.hidden = true;
            if (target.expandable) {
                const expand = text('button', '›'); expand.type = 'button'; expand.setAttribute('aria-label', 'Раскрыть ' + target.title); expand.setAttribute('aria-expanded', 'false');
                expand.onclick = () => task(async () => { if (!children.dataset.loaded) { const data = await api('tree', target); renderTree(data.nodes, children); children.dataset.loaded = '1'; }
                    children.hidden = !children.hidden; expand.textContent = children.hidden ? '›' : '⌄'; expand.setAttribute('aria-expanded', String(!children.hidden)); }); line.append(expand);
            }
            const choose = text('button', target.title); choose.type = 'button'; choose.onclick = () => task(async () => { await selectTarget(target); $('tree').querySelectorAll('[aria-pressed]').forEach(b => b.setAttribute('aria-pressed', 'false')); choose.setAttribute('aria-pressed', 'true'); });
            line.append(choose); li.append(line, children); ul.append(li);
        }
    }
    $('site').onchange = () => task(async () => {
        $('tree').replaceChildren(); state.target = null; syncButtons();
        const site = state.sites.find(s => Number(s.siteId) === Number($('site').value));
        if (!site) return;
        const all = text('button', 'Весь сайт: ' + site.title, 'sbg-button'); all.onclick = () => task(() => selectTarget(site)); $('tree').append(all);
        const data = await api('tree', site); renderTree(data.nodes, $('tree'));
    });
    $('save-rule').onclick = () => task(async () => {
        const disk = ['disk', 'folder'].includes(state.target.kind);
        await mutate('group.rule.put', { target: state.target, canView: true, canEdit: $('edit').checked, canDelete: disk && $('can-delete').checked,
            includeChildren: $('children').checked, openPage: disk && $('open-page').checked });
        await refreshCurrent(); showTab('rights'); notify('Право группы сохранено. Оно действует для текущих и новых участников.');
    });
    window.addEventListener('beforeunload', e => { if (state.pending || state.busy) { e.preventDefault(); e.returnValue = ''; } });
    task(async () => {
        await loadGroups(); const data = await api('tree'); state.sites = data.nodes;
        for (const site of state.sites) { const option = text('option', site.title); option.value = site.siteId; $('site').append(option); }
        const initial = config.initial || {};
        if (initial.groupId) {
            await selectGroup(initial.groupId);
            const rule = state.rules.find(r => Number(r.id) === initial.ruleId);
            if (rule) {
                showTab('rights'); $('rule-panel').open = true;
                await selectTarget({ kind: rule.kind, siteId: Number(rule.site_id), pageId: Number(rule.page_id), blockId: Number(rule.block_id), folderId: Number(rule.folder_id) }, rule);
            } else if (initial.siteId) {
                showTab('rights'); $('rule-panel').open = true; await selectTarget(initial);
            }
        }
    });
})();
