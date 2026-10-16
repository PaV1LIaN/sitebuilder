(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('uc-config').textContent);
    const $ = id => document.getElementById('uc-' + id);
    const state = { busy: false, pending: null, target: null, user: null, sites: [], object: null, employee: null,
        ruleGroup: null, ruleTarget: null, dirty: false, objectOffset: 0, memberOffset: 0, legacyOffset: 0 };
    const labels = { VIEWER: 'Чтение', EDITOR: 'Редактирование', OWNER: 'Владелец', ADMIN: 'Администратор',
        INHERIT: 'Наследование', DENY: 'Нет доступа', FULL: 'Полный доступ', ADD: 'Добавление', CUSTOM: 'Нестандартное правило' };
    const scopes = { direct: 'На этом объекте', inherited: 'От родителя / сайта', traverse: 'Только проход к подпапке', container: 'Страница с разрешённым Диском' };
    const errors = { GROUP_VERSION_CONFLICT: 'Группа изменена другим администратором. Обновите данные и повторите действие.',
        ACCESS_DENIED: 'Недостаточно прав.', GROUP_MIGRATION_REQUIRED: 'Требуются таблицы групп (миграция №25).',
        USER_NOT_FOUND: 'Сотрудник не найден в тестовом Битриксе.', BAD_SESSID: 'Сессия истекла. Обновите страницу.',
        GROUP_NOT_FOUND: 'Группа уже удалена. Обновите данные.', USER_VALIDATION_FAILED: 'Сотрудник неактивен или является техническим гостем.',
        DISK_ROOT_NOT_CONFIGURED: 'У Диска не настроена корневая папка. Проверьте привязку на тестовом портале.',
        FOLDER_OUT_OF_SCOPE: 'Папка не относится к корню выбранного Диска.', CONSOLE_RULE_LIMIT: 'На сайте более 5 000 групповых правил. Полный результат не был загружен.',
        ACCESS_BUSY: 'Объект занят. Повторите запрос.', GROUP_RULE_LIMIT: 'Достигнут лимит правил выбранной группы.' };
    const flag = value => value === true || ['t', 'true', 1, '1'].includes(value);
    const node = (tag, value, cls) => { const el = document.createElement(tag); if (value != null) el.textContent = value; if (cls) el.className = cls; return el; };
    const url = (view, params = {}) => config.basePath + '/access.php?' + new URLSearchParams({ view, ...params });
    const targetParams = t => t ? Object.fromEntries(['kind', 'siteId', 'pageId', 'blockId', 'folderId'].map(k => [k, t[k] || (k === 'kind' ? 'site' : 0)])) : {};
    const link = (label, href) => { const el = node('a', label, 'uc-link'); el.href = href; return el; };
    const button = (label, callback, cls = 'sbg-button') => { const el = node('button', label, cls); el.type = 'button'; el.onclick = () => task(callback); return el; };
    const notify = (value, bad = false) => { $('message').textContent = value; $('message').hidden = !value; $('message').className = 'sbg-message' + (bad ? ' error' : ''); };
    function describe(error) {
        return [errors[error.code] || error.message, ...(error.details?.users || []).map(u => `ID ${u.userId}: ${u.name || ''} — ${u.error}`),
            error.incident ? `Код диагностики: ${error.incident}` : ''].filter(Boolean).join('\n');
    }
    async function api(action, payload = {}) {
        const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 60000);
        try {
            const response = await fetch(config.basePath + '/api/access_admin.php', { method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams({ action, sessid: config.sessid, payload: JSON.stringify(payload) }) });
            let data;
            try { data = await response.json(); } catch (_) { throw new Error('Ответ сервера не распознан. Для записи используйте повтор запроса.'); }
            if (!response.ok || !data.ok) throw Object.assign(new Error(data.error || `HTTP ${response.status}`),
                { code: data.error, details: data.details, incident: data.incident, status: response.status });
            return data.data;
        } catch (error) {
            if (error.name === 'AbortError' || error instanceof TypeError) throw new Error('Нет подтверждения сервера. Для записи используйте «Повторить запрос».');
            throw error;
        } finally { clearTimeout(timeout); }
    }
    function sync() {
        if ($('flags')) $('flags').disabled = !state.ruleGroup || state.busy || !!state.pending;
        if ($('save-rule')) $('save-rule').disabled = !state.ruleGroup || !state.ruleTarget || state.busy || !!state.pending;
        $('retry-panel').hidden = !state.pending;
    }
    async function task(fn, retry = false) {
        if (state.busy) return;
        if (state.pending && !retry) { notify('Сначала подтвердите предыдущую запись кнопкой «Повторить запрос».', true); return; }
        state.busy = true;
        const controls = [...document.querySelectorAll('#uc-app button, #uc-app input, #uc-app select')].map(el => [el, el.disabled]);
        controls.forEach(([el]) => { el.disabled = true; });
        try { await fn(); } catch (error) { notify(describe(error), true); }
        finally { state.busy = false; controls.forEach(([el, disabled]) => { if (el.isConnected) el.disabled = disabled; }); sync(); }
    }
    function guard() { if (state.dirty) throw new Error('Есть несохранённое назначение группы. Сохраните его или нажмите «Отменить изменения».'); }
    async function write(action, payload) {
        state.pending = { action, payload: { ...payload, requestId: [...crypto.getRandomValues(new Uint8Array(16))].map(v => v.toString(16).padStart(2, '0')).join('') } };
        return sendPending();
    }
    async function sendPending() {
        const op = state.pending;
        try { const data = await api(op.action, op.payload); state.pending = null; return data; }
        catch (error) { if (error.status && error.status < 500) state.pending = null; throw error; }
    }
    function reflectUrl() {
        const params = { ...targetParams(state.target), ...(state.user ? { userId: state.user.id } : {}) };
        history.replaceState(null, '', url(config.view, params));
    }
    function search(inputId, resultsId, action, pick) {
        let timer, generation = 0;
        $(inputId).oninput = () => {
            clearTimeout(timer); const current = ++generation; $(resultsId).replaceChildren();
            const query = $(inputId).value.trim(); if (!query) return;
            timer = setTimeout(async () => {
                if (state.busy || state.pending) return;
                try {
                    const data = await api(action, { query }); if (current !== generation) return;
                    $(resultsId).replaceChildren(); const rows = data.users || data.groups || [];
                    for (const row of rows) {
                        const label = action === 'search' ? `${row.name} · ID ${row.id} · ${row.login}` : `${row.name} · ${row.rules_count} назначений`;
                        $(resultsId).append(button(label, () => pick(row)));
                    }
                    if (!rows.length) $(resultsId).append(node('p', 'Совпадений нет.', 'sbg-muted'));
                    if (data.hasMore) $(resultsId).append(node('p', 'Показаны первые 100 групп. Уточните название.', 'sbg-muted'));
                } catch (error) { if (current === generation) notify(describe(error), true); }
            }, 350);
        };
    }
    function renderTree(nodes, parent, request = null) {
        const ul = node('ul'); parent.append(ul);
        for (const t of nodes) {
            const li = node('li'), line = node('div'), children = node('div'); children.hidden = true;
            if (t.expandable) {
                const expand = button('›', async () => {
                    if (!children.dataset.loaded) { const data = await api('tree', targetParams(t)); renderTree(data.nodes, children, t); children.dataset.loaded = '1'; }
                    children.hidden = !children.hidden; expand.textContent = children.hidden ? '›' : '⌄'; expand.setAttribute('aria-expanded', String(!children.hidden));
                }); expand.setAttribute('aria-label', 'Раскрыть ' + t.title); expand.setAttribute('aria-expanded', 'false'); line.append(expand);
            }
            const choose = button(t.title, async () => {
                guard();
                if (config.view === 'objects') await loadObject(t); else { state.target = t; reflectUrl(); await explain(); }
                $('tree').querySelectorAll('[aria-pressed]').forEach(el => el.setAttribute('aria-pressed', 'false')); choose.setAttribute('aria-pressed', 'true');
            });
            li.dataset.siteName = t.kind === 'site' ? t.title.toLocaleLowerCase() : '';
            line.append(choose); li.append(line, children); ul.append(li);
        }
        // The original tree API may paginate folder children; never silently stop at 100.
        if (request && ['disk', 'folder'].includes(request.kind) && nodes.length === 100) {
            const more = button('Ещё папки…', async () => {
                const offset = Number(more.dataset.offset || 100);
                const data = await api('tree', { ...targetParams(request), offset });
                renderTree(data.nodes, parent); more.dataset.offset = offset + 100;
                if (!data.hasMore || !data.nodes.length) more.remove();
            }); parent.append(more);
        }
    }
    function setRuleForm(group, target, flags = null) {
        state.ruleGroup = group; state.ruleTarget = target; state.dirty = false;
        const disk = ['disk', 'folder'].includes(target.kind);
        $('rule-editor').open = true; $('edit-target').textContent = target.title || state.target.title;
        $('chosen-group').textContent = 'Группа: ' + group.name; $('group-search').value = ''; $('group-results').replaceChildren();
        $('delete-wrap').hidden = !disk; $('open-page-wrap').hidden = !disk;
        $('edit').checked = !!flags?.canEdit; $('delete').checked = disk && !!flags?.canDelete;
        $('children').checked = flags ? flags.includeChildren : true; $('open-page').checked = disk && (flags ? flags.openPage : true); sync();
    }
    async function chooseRuleGroup(group) {
        guard();
        const data = await api('group.view', { groupId: Number(group.id) });
        const t = state.target;
        const existing = data.rules.find(r => r.kind === t.kind && Number(r.site_id) === t.siteId && Number(r.page_id) === t.pageId && Number(r.block_id) === t.blockId && Number(r.folder_id) === t.folderId);
        setRuleForm(data.group, t, existing ? { canEdit: flag(existing.can_edit), canDelete: flag(existing.can_delete), includeChildren: flag(existing.include_children), openPage: flag(existing.open_page) } : null);
    }
    function renderObjectRules(data, append = false) {
        if (!append) $('object-rules').replaceChildren();
        for (const r of data.rules) {
            const row = node('div', null, 'uc-row'), info = node('div');
            info.append(link(r.groupName, url('groups', { groupId: r.groupId, ruleId: r.ruleId })), node('p', scopes[r.scope], 'sbg-muted'), node('p', r.title, 'sbg-person'));
            for (const [key, label] of [['view', 'Просмотр'], ['edit', 'Редактирование'], ['delete', 'Удаление'], ['traverse', 'Только проход'], ['diskView', 'Компонент Диска']]) if (r.caps[key]) info.append(node('span', label, 'sbg-badge'));
            const actions = node('div', null, 'sbg-actions');
            actions.append(link('Источник правила', url('objects', r.target)));
            if (r.scope === 'direct') {
                actions.append(button('Изменить', async () => {
                    guard(); const { target } = await api('group.target', { target: r.target });
                    setRuleForm({ id: r.groupId, name: r.groupName, version: r.version }, target, r.flags);
                }), button('Снять это правило', async () => {
                    guard(); await write('group.rule.remove', { groupId: r.groupId, version: r.version, ruleId: r.ruleId });
                    await loadObject(state.target); notify('Правило этой группы снято. Другие группы и личные назначения сохранены.');
                }, 'sbg-button sbg-danger'));
            }
            row.append(info, actions); $('object-rules').append(row);
        }
        if (!append && !data.rules.length) $('object-rules').append(node('p', 'Групповых назначений на этот объект нет. Старые личные права могут продолжать действовать.', 'sbg-muted'));
        $('more-rules').hidden = !data.hasMore; state.objectOffset = data.offset;
    }
    function legacyRow(row, object = false) {
        const box = node('div', null, 'uc-row'), info = node('div');
        const assignment = object ? row.assignment : { role: row.role, diskRole: row.disk_role };
        const name = object ? row.name : row.title;
        if (object && row.userId) info.append(link(name, url('employees', { userId: row.userId, ...targetParams(state.target) })));
        else if (!object) info.append(link(name, url('objects', { kind: row.kind, siteId: row.site_id, pageId: row.page_id, blockId: row.block_id, folderId: row.folder_id })));
        else info.append(node('strong', name));
        info.append(node('p', `${labels[assignment.role] || assignment.role}${assignment.diskRole ? '; Диск: ' + (labels[assignment.diskRole] || assignment.diskRole) : ''}`, 'sbg-muted'));
        if (assignment.role === 'DENY') info.append(node('span', 'Существующее персональное ограничение', 'uc-deny'));
        box.append(info); return box;
    }
    function renderLegacy(data, append = false) {
        if (!append) $('legacy').replaceChildren();
        data.legacy.forEach(row => $('legacy').append(legacyRow(row, config.view === 'objects')));
        if (!append && !data.legacy.length) $('legacy').append(node('p', 'Прямых назначений в выбранной области не найдено. Наследование и штатные права учитываются в проверке сотрудника.', 'sbg-muted'));
        $('more-legacy').hidden = !data.legacyHasMore; state.legacyOffset = data.legacyOffset;
    }
    async function loadObject(target) {
        const data = await api('console.object', { target: targetParams(target) });
        state.target = data.target; state.object = data; state.ruleGroup = null; state.ruleTarget = null; state.dirty = false;
        $('group-results').replaceChildren(); $('group-search').value = '';
        $('title').textContent = data.target.title; $('subtitle').textContent = 'Групповых правил: ' + data.total + '. Старых прямых назначений: ' + data.legacyCount + '.';
        if (data.target.mode === 'bitrix_disk') $('subtitle').append(node('span', ' Штатные права Битрикс.Диска дополнительно ограничивают доступ.'));
        $('work').hidden = false; $('empty').hidden = true; $('rule-editor').open = false; $('chosen-group').textContent = '';
        $('context-link').href = url('groups', targetParams(data.target)); $('context-link').textContent = 'Все группы системы'; $('context-link').hidden = false;
        renderObjectRules(data); renderLegacy(data); reflectUrl(); if (state.user) await explain();
    }
    function renderMemberships(data, append = false) {
        if (!append) $('memberships').replaceChildren();
        for (const g of data.groups) {
            const row = node('div', null, 'uc-row'), info = node('div');
            info.append(link(g.name, url('groups', { groupId: g.id })), node('p', `${g.rules_count} назначений этой группы на разных объектах`, 'sbg-muted'));
            row.append(info, button('Исключить из этой группы', async () => {
                await write('group.members.remove', { groupId: Number(g.id), version: Number(g.version), userIds: [state.user.id] });
                await loadEmployee(state.user.id); notify('Сотрудник исключён из группы. Личные права и остальные группы сохранены.');
            }, 'sbg-button sbg-danger')); $('memberships').append(row);
        }
        if (!append && !data.groups.length) $('memberships').append(node('p', 'Не состоит в локальных группах Sitebuilder.', 'sbg-muted'));
        $('more-memberships').hidden = !data.hasMore; state.memberOffset = data.offset;
    }
    async function loadEmployee(id) {
        const data = await api('console.employee', { userId: Number(id) });
        state.user = data.user; state.employee = data;
        $('member-group-results').replaceChildren(); $('member-group-search').value = '';
        $('title').textContent = data.user.name; $('subtitle').textContent = `ID ${data.user.id} · ${data.user.login} · ${data.groupCount} групп` + (data.protected ? ' · Защищённый администратор' : '') + (!data.user.active ? ' · Неактивен' : '');
        $('empty').hidden = true; $('work').hidden = false; $('context-link').hidden = true;
        $('member-group-search').disabled = data.guest || !data.user.active;
        renderMemberships(data); renderLegacy(data); reflectUrl(); if (state.target) await explain();
    }
    async function explain() {
        if (!state.user || !state.target) return;
        const data = await api('console.explain', { target: targetParams(state.target), userId: state.user.id });
        $('explain-title').textContent = data.user.name + ' → ' + data.target.title;
        $('explanation').replaceChildren();
        data.warnings.forEach(w => $('explanation').append(node('p', w, 'uc-note')));
        const wrap = node('div', null, 'uc-table-wrap'), table = node('table', null, 'uc-capabilities'), head = node('thead'), hr = node('tr');
        ['Действие', 'Результат', 'Источники и ограничения'].forEach(t => hr.append(node('th', t))); head.append(hr); table.append(head);
        const body = node('tbody');
        for (const cap of data.capabilities) {
            const row = node('tr'), status = node('td'), info = node('td');
            status.append(node('span', cap.allowed ? 'Разрешено' : 'Не разрешено', cap.allowed ? 'uc-allow' : 'uc-deny'));
            for (const source of cap.sources) {
                const p = node('p');
                if (source.groupId) p.append(link(source.label, url('groups', { groupId: source.groupId, ruleId: source.ruleId })));
                else p.textContent = source.label;
                if (source.scope) p.append(node('small', scopes[source.scope] || source.scope)); info.append(p);
            }
            cap.limits.forEach(l => info.append(node('p', l, 'uc-limit')));
            if (cap.note) info.append(node('p', cap.note, 'sbg-muted'));
            if (!cap.allowed && cap.sources.length) info.append(node('small', 'Перечисленные назначения не дают итогового разрешения с учётом ограничений.'));
            row.append(node('td', cap.label), status, info); body.append(row);
        }
        table.append(body); wrap.append(table); $('explanation').append(wrap);
    }
    $('reload').onclick = () => task(async () => { guard(); if (config.view === 'objects' && state.target) await loadObject(state.target); else if (state.user) await loadEmployee(state.user.id); notify('Данные перечитаны.'); });
    $('retry').onclick = () => task(async () => { await sendPending(); state.dirty = false;
        if (config.view === 'objects') await loadObject(state.target); else await loadEmployee(state.user.id);
        notify('Запись подтверждена, данные обновлены.'); }, true);
    $('more-legacy').onclick = () => task(async () => {
        const data = await api(config.view === 'objects' ? 'console.object' : 'console.employee',
            config.view === 'objects' ? { target: targetParams(state.target), legacyOffset: state.legacyOffset + 100 } : { userId: state.user.id, legacyOffset: state.legacyOffset + 100 });
        renderLegacy(data, true);
    });
    if (config.view === 'objects') {
        search('explain-search', 'explain-results', 'search', async user => { state.user = user; $('explain-results').replaceChildren(); reflectUrl(); await explain(); });
        search('group-search', 'group-results', 'group.list', chooseRuleGroup);
        $('open-rule').onclick = () => { $('rule-editor').open = true; $('edit-target').textContent = state.target.title; $('group-search').focus(); };
        ['edit', 'delete', 'children', 'open-page'].forEach(id => { $(id).onchange = () => { state.dirty = true; }; });
        $('cancel-rule').onclick = () => { if (state.pending || state.busy) return; state.dirty = false; state.ruleGroup = null; state.ruleTarget = null; $('rule-editor').open = false; sync(); };
        $('save-rule').onclick = () => task(async () => {
            if (!state.ruleGroup || !state.ruleTarget) return;
            const disk = ['disk', 'folder'].includes(state.ruleTarget.kind);
            await write('group.rule.put', { groupId: Number(state.ruleGroup.id), version: Number(state.ruleGroup.version), target: targetParams(state.ruleTarget),
                canView: true, canEdit: $('edit').checked, canDelete: disk && $('delete').checked, includeChildren: $('children').checked, openPage: disk && $('open-page').checked });
            state.dirty = false; await loadObject(state.target); notify('Назначение группы сохранено. Оно видно и в разделе «Группы».');
        });
        $('more-rules').onclick = () => task(async () => { const data = await api('console.object', { target: targetParams(state.target), offset: state.objectOffset + 100 }); renderObjectRules(data, true); });
        $('site-filter').oninput = () => { const q = $('site-filter').value.trim().toLocaleLowerCase(); $('tree').querySelectorAll('li[data-site-name]').forEach(li => { if (li.dataset.siteName) li.hidden = !li.dataset.siteName.includes(q); }); };
    } else {
        search('person-search', 'person-results', 'search', user => loadEmployee(user.id));
        search('member-group-search', 'member-group-results', 'group.list', async group => {
            const target = $('member-group-results'); target.replaceChildren();
            target.append(node('p', `«${group.name}»: сотрудник получит доступ по всем ${group.rules_count} назначениям группы.`, 'uc-note'),
                link('Посмотреть назначения группы', url('groups', { groupId: group.id })), button('Включить сотрудника в группу', async () => {
                    await write('group.members.add', { groupId: Number(group.id), version: Number(group.version), userIds: [state.user.id] });
                    target.replaceChildren(); await loadEmployee(state.user.id); notify('Сотрудник включён в группу. Персональные назначения не создавались.');
                }, 'sbg-primary'));
        });
        $('more-memberships').onclick = () => task(async () => { const data = await api('console.employee', { userId: state.user.id, offset: state.memberOffset + 100 }); renderMemberships(data, true); });
        $('inspect-site').onchange = () => task(async () => { $('tree').replaceChildren(); const site = state.sites.find(s => s.siteId === Number($('inspect-site').value));
            if (site) renderTree([site], $('tree')); });
    }
    document.querySelectorAll('.sbg-nav a').forEach(a => a.addEventListener('click', e => {
        if (state.pending || state.busy || state.dirty) { e.preventDefault(); notify('Завершите или отмените текущее изменение перед переходом.', true); }
    }));
    window.addEventListener('beforeunload', e => { if (state.pending || state.busy || state.dirty) { e.preventDefault(); e.returnValue = ''; } });
    task(async () => {
        const data = await api('tree'); state.sites = data.nodes;
        if (config.view === 'objects') {
            $('tree').replaceChildren(); renderTree(state.sites, $('tree')); if (config.initial.siteId) await loadObject(config.initial);
            if (config.initial.userId && state.target) { const employee = await api('console.employee', { userId: config.initial.userId }); state.user = employee.user; await explain(); }
        } else {
            for (const site of state.sites) { const option = node('option', site.title); option.value = site.siteId; $('inspect-site').append(option); }
            if (config.initial.siteId) state.target = config.initial;
            if (config.initial.userId) await loadEmployee(config.initial.userId);
        }
    });
})();
