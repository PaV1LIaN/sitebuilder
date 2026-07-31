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
        /* Raw API responses belong to browser developer tools, not the editor UI. */
        apiPanel.hidden = true;
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
            siteId: siteId,
            expectedVersion: Number((state.site && state.site.version) || 1)
        });

        state.site = res.site || state.site;

        renderBitrixGroupPanel();

        if (resultNode) {
            resultNode.textContent = res.queued
                ? 'Создание рабочей группы поставлено в очередь. Задание #' + Number((res.job && res.job.id) || 0)
                : JSON.stringify(res, null, 2);
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
            var syncJob = res.jobs && res.jobs.sync ? res.jobs.sync : null;
            resultNode.textContent = res.queued
                ? 'Синхронизация прав поставлена в очередь. Задание #' + Number((syncJob && syncJob.id) || 0)
                : JSON.stringify(res, null, 2);
        }
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
            if (groupSync.queued) {
                syncText = '\nСинхронизация с группой Битрикс24 поставлена в очередь (задание #' + Number(groupSync.jobId || 0) + ').';
            } else if (groupSync.ok) {
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