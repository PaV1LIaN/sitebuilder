function setManagementPanelsVisible(canManage) {
    var groupPanel = document.getElementById('siteGroupPanel');
    var accessPanel = document.getElementById('siteAccessPanel');
    var pageAccessPanel = document.getElementById('pageAccessPanel');
    var apiPanel = document.getElementById('apiOutputPanel');
    var deleteSiteBtn = document.getElementById('deleteSiteBtn');

    if (groupPanel) {
        groupPanel.hidden = !canManage;
    }

    if (accessPanel) {
        accessPanel.hidden = !canManage;
    }

    if (pageAccessPanel) {
        pageAccessPanel.hidden = !canManageCurrentPageAccess();
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

    if (typeof refreshInspectorAccessTab === 'function') {
        refreshInspectorAccessTab();
    }
}

function currentSiteRoleRank() {
    var rank = Number(state.site && state.site.currentUserRoleRank);

    if (rank > 0) {
        return rank;
    }

    var role = String((state.site && state.site.currentUserRole) || '').toUpperCase();

    return {
        VIEWER: 1,
        EDITOR: 2,
        ADMIN: 3,
        OWNER: 4
    }[role] || 0;
}

function canManageCurrentPageAccess() {
    return Number(state.currentPageId || 0) > 0
        && (IS_BITRIX_ADMIN || currentSiteRoleRank() >= 3);
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

function setPageAccessMessage(message, type) {
    var node = document.getElementById('pageAccessMessage');
    if (!node) return;

    node.classList.remove('sb-hidden', 'is-success', 'is-error');

    if (type === 'success') node.classList.add('is-success');
    if (type === 'error') node.classList.add('is-error');

    node.textContent = message || '';
}

function hidePageAccessMessage() {
    var node = document.getElementById('pageAccessMessage');
    if (!node) return;

    node.classList.add('sb-hidden');
    node.textContent = '';
}

function pageAccessControl(id) {
    return document.getElementById(id);
}

function setPageAccessPermissions(item) {
    item = item || {};

    var canView = pageAccessControl('pageAccessCanView');
    var canEdit = pageAccessControl('pageAccessCanEdit');
    var canDiskView = pageAccessControl('pageAccessCanDiskView');
    var canDiskEdit = pageAccessControl('pageAccessCanDiskEdit');
    var includeChildren = pageAccessControl('pageAccessIncludeChildren');

    if (canView) canView.checked = Object.keys(item).length ? !!item.canView : true;
    if (canEdit) canEdit.checked = !!item.canEdit;
    if (canDiskView) canDiskView.checked = !!item.canDiskView;
    if (canDiskEdit) canDiskEdit.checked = !!item.canDiskEdit;
    if (includeChildren) includeChildren.checked = !!item.includeChildren;
}

function renderPageAccessUserSearchResults(users) {
    var results = document.getElementById('pageAccessUserSearchResults');
    if (!results) return;

    state.pageAccessUserSearchResults = Array.isArray(users) ? users : [];

    if (!state.pageAccessUserSearchResults.length) {
        results.innerHTML = '';
        results.classList.add('sb-hidden');
        return;
    }

    results.innerHTML = state.pageAccessUserSearchResults.map(function (user) {
        var id = Number(user.id || 0);
        var title = user.title || user.name || ('Пользователь #' + id);
        var meta = [];

        if (user.login) meta.push(user.login);
        if (user.email) meta.push(user.email);

        return ''
            + '<button class="sb-access-result-item" type="button" data-select-page-access-user="' + id + '" style="display:grid;grid-template-columns:32px minmax(0,1fr);gap:10px;align-items:center;width:100%;min-height:44px;padding:7px 10px;box-sizing:border-box;">'
            +      userAvatarHtml(user, 'sb-access-result-avatar')
            + '  <div class="sb-access-result-body" style="min-width:0;overflow:hidden;">'
            + '      <div class="sb-access-result-title" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(title) + '</div>'
            + '      <div class="sb-access-result-meta" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">ID: ' + id + (meta.length ? ' · ' + escapeHtml(meta.join(' · ')) : '') + '</div>'
            + '  </div>'
            + '</button>';
    }).join('');

    results.classList.remove('sb-hidden');
}

function renderSelectedPageAccessUser() {
    var selectedNode = document.getElementById('pageAccessSelectedUser');
    if (!selectedNode) return;

    var user = state.selectedPageAccessUser;

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
        + '      <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-clear-page-access-user>Сбросить</button>'
        + '  </div>'
        + '</div>';

    selectedNode.classList.remove('sb-hidden');
}

async function searchPageAccessUsers() {
    var input = document.getElementById('pageAccessUserSearchInput');
    if (!input) return;

    var query = String(input.value || '').trim();

    state.selectedPageAccessUser = null;
    renderSelectedPageAccessUser();

    if (query === '' || (!/^\d+$/.test(query) && query.length < 2)) {
        renderPageAccessUserSearchResults([]);
        return;
    }

    try {
        var res = await api('user.search', {
            siteId: siteId,
            query: query,
            limit: 10
        });

        renderPageAccessUserSearchResults(Array.isArray(res.users) ? res.users : []);
    } catch (e) {
        renderPageAccessUserSearchResults([]);
        setPageAccessMessage('Не удалось выполнить поиск пользователя', 'error');
    }
}

function selectPageAccessUser(user, permissionItem) {
    state.selectedPageAccessUser = user || null;

    var input = document.getElementById('pageAccessUserSearchInput');
    if (input && user) {
        input.value = user.title || user.name || ('Пользователь #' + Number(user.id || 0));
    }

    renderPageAccessUserSearchResults([]);
    renderSelectedPageAccessUser();
    setPageAccessPermissions(permissionItem || null);
    hidePageAccessMessage();
}

function clearSelectedPageAccessUser() {
    state.selectedPageAccessUser = null;

    var input = document.getElementById('pageAccessUserSearchInput');
    if (input) input.value = '';

    renderSelectedPageAccessUser();
    renderPageAccessUserSearchResults([]);
    setPageAccessPermissions(null);
}

function pageAccessUserId(item) {
    var directId = Number(item && item.userId);
    if (directId > 0) return directId;

    var match = String((item && item.accessCode) || '').match(/^U(\d+)$/i);
    return match ? Number(match[1]) : 0;
}

function pageAccessUserFromItem(item) {
    var userId = pageAccessUserId(item);

    return {
        id: userId,
        title: item.userName || item.title || ('Пользователь #' + userId),
        name: item.userName || item.title || '',
        login: item.login || '',
        email: item.email || '',
        avatarUrl: item.avatarUrl || ''
    };
}

function pagePermissionBadges(item) {
    var labels = [];

    if (item.canEdit) {
        labels.push('Редактирование');
    } else if (item.canView) {
        labels.push('Просмотр');
    }

    if (item.canDiskEdit) {
        labels.push('Файлы: изменение');
    } else if (item.canDiskView) {
        labels.push('Файлы: просмотр');
    }

    if (item.includeChildren) labels.push('С дочерними');

    return labels.map(function (label) {
        return '<span class="sb-page-access-badge">' + escapeHtml(label) + '</span>';
    }).join('');
}

function renderPageAccessList() {
    var list = document.getElementById('pageAccessList');
    if (!list) return;

    if (!Number(state.currentPageId || 0)) {
        list.innerHTML = '<div class="sb-empty">Выберите страницу</div>';
        return;
    }

    if (!Array.isArray(state.pageAccessItems) || !state.pageAccessItems.length) {
        list.innerHTML = '<div class="sb-empty">Точечные права на эту страницу ещё не выданы</div>';
        return;
    }

    list.innerHTML = state.pageAccessItems.map(function (item) {
        var userId = pageAccessUserId(item);
        var accessCode = String(item.accessCode || '');
        var name = item.userName || item.title || (userId > 0 ? 'Пользователь #' + userId : accessCode);

        return ''
            + '<div class="sb-access-item">'
            + '  <div class="sb-access-item__main">'
            + '      <div class="sb-access-item__name">' + escapeHtml(name) + '</div>'
            + '      <div class="sb-access-item__meta">' + escapeHtml(accessCode) + '</div>'
            + '      <div class="sb-page-access-badges">' + pagePermissionBadges(item) + '</div>'
            + '  </div>'
            + '  <div class="sb-access-item__side">'
            + '      <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-page-access-edit-id="' + Number(item.id || 0) + '">Изменить</button>'
            + '      <button class="sb-btn sb-btn-danger sb-btn-small" type="button" data-page-access-delete-id="' + Number(item.id || 0) + '">Удалить</button>'
            + '  </div>'
            + '</div>';
    }).join('');
}

function updatePageAccessHeading() {
    var node = document.getElementById('pageAccessPageTitle');
    if (!node) return;

    var page = typeof getCurrentPage === 'function' ? getCurrentPage() : null;
    node.textContent = page
        ? (page.title || ('Страница #' + Number(page.id || 0)))
        : 'Страница не выбрана';
}

async function loadPageAccessList(force) {
    var panel = document.getElementById('pageAccessPanel');
    if (!panel) return;

    var pageId = Number(state.currentPageId || 0);
    var canManage = canManageCurrentPageAccess();

    panel.hidden = !canManage;
    updatePageAccessHeading();

    if (typeof refreshInspectorAccessTab === 'function') {
        refreshInspectorAccessTab();
    }

    if (!canManage) {
        if (Number(state.pageAccessContextPageId || 0) > 0) {
            clearSelectedPageAccessUser();
            hidePageAccessMessage();
        }
        state.pageAccessItems = [];
        state.pageAccessLoadedPageId = 0;
        state.pageAccessLoadingPageId = 0;
        state.pageAccessContextPageId = 0;
        renderPageAccessList();
        return;
    }

    var previousPageId = Number(state.pageAccessContextPageId || 0);
    if (previousPageId > 0 && previousPageId !== pageId) {
        clearSelectedPageAccessUser();
        hidePageAccessMessage();
    }
    state.pageAccessContextPageId = pageId;

    if (!force && (
        Number(state.pageAccessLoadedPageId || 0) === pageId
        || Number(state.pageAccessLoadingPageId || 0) === pageId
    )) {
        renderPageAccessList();
        return;
    }

    state.pageAccessLoadingPageId = pageId;
    state.pageAccessItems = [];

    var list = document.getElementById('pageAccessList');
    if (list) list.innerHTML = '<div class="sb-empty">Загрузка прав...</div>';

    try {
        var res = await api('pageAccess.list', {
            siteId: siteId,
            pageId: pageId
        });
        var data = apiData(res) || {};

        if (Number(state.currentPageId || 0) !== pageId) return;

        state.pageAccessItems = Array.isArray(data.items) ? data.items : [];
        state.pageAccessLoadedPageId = pageId;
        renderPageAccessList();
    } catch (e) {
        if (Number(state.currentPageId || 0) !== pageId) return;

        state.pageAccessItems = [];
        state.pageAccessLoadedPageId = 0;
        renderPageAccessList();
        setPageAccessMessage('Не удалось загрузить права страницы: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
    } finally {
        if (Number(state.pageAccessLoadingPageId || 0) === pageId) {
            state.pageAccessLoadingPageId = 0;
        }
    }
}

async function savePageAccess() {
    var pageId = Number(state.currentPageId || 0);
    var user = state.selectedPageAccessUser;
    var userId = Number(user && user.id);

    if (pageId <= 0) {
        setPageAccessMessage('Сначала выберите страницу', 'error');
        return;
    }

    if (userId <= 0) {
        setPageAccessMessage('Сначала найдите и выберите пользователя', 'error');
        var searchInput = document.getElementById('pageAccessUserSearchInput');
        if (searchInput) searchInput.focus();
        return;
    }

    var canView = !!(pageAccessControl('pageAccessCanView') || {}).checked;
    var canEdit = !!(pageAccessControl('pageAccessCanEdit') || {}).checked;
    var canDiskView = !!(pageAccessControl('pageAccessCanDiskView') || {}).checked;
    var canDiskEdit = !!(pageAccessControl('pageAccessCanDiskEdit') || {}).checked;
    var includeChildren = !!(pageAccessControl('pageAccessIncludeChildren') || {}).checked;

    if (canEdit) canView = true;
    if (canDiskEdit) canDiskView = true;

    if (!canView && !canEdit && !canDiskView && !canDiskEdit) {
        setPageAccessMessage('Выберите хотя бы одно право', 'error');
        return;
    }

    try {
        setPageAccessMessage('Сохраняю права страницы...', '');

        await api('pageAccess.save', {
            siteId: siteId,
            pageId: pageId,
            accessCode: 'U' + userId,
            canView: canView,
            canEdit: canEdit,
            canDiskView: canDiskView,
            canDiskEdit: canDiskEdit,
            includeChildren: includeChildren
        });

        clearSelectedPageAccessUser();
        state.pageAccessLoadedPageId = 0;
        await loadPageAccessList(true);
        setPageAccessMessage('Права страницы сохранены', 'success');
    } catch (e) {
        setPageAccessMessage('Ошибка сохранения прав: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
    }
}

function editPageAccessItem(id) {
    id = Number(id || 0);

    var item = (state.pageAccessItems || []).find(function (current) {
        return Number(current.id || 0) === id;
    });

    if (!item) return;

    selectPageAccessUser(pageAccessUserFromItem(item), item);

    var input = document.getElementById('pageAccessUserSearchInput');
    if (input) input.scrollIntoView({block: 'nearest'});
}

async function deletePageAccessItem(id) {
    id = Number(id || 0);

    var pageId = Number(state.currentPageId || 0);
    var item = (state.pageAccessItems || []).find(function (current) {
        return Number(current.id || 0) === id;
    });

    if (id <= 0 || pageId <= 0 || !item) return;

    if (!confirm('Удалить права ' + String(item.accessCode || '') + ' на эту страницу?')) return;

    try {
        hidePageAccessMessage();

        await api('pageAccess.delete', {
            id: id,
            siteId: siteId,
            pageId: pageId
        });

        if (state.selectedPageAccessUser && pageAccessUserId(item) === Number(state.selectedPageAccessUser.id || 0)) {
            clearSelectedPageAccessUser();
        }

        state.pageAccessLoadedPageId = 0;
        await loadPageAccessList(true);
        setPageAccessMessage('Права страницы удалены', 'success');
    } catch (e) {
        setPageAccessMessage('Ошибка удаления прав: ' + ((e && (e.error || e.message)) || 'UNKNOWN_ERROR'), 'error');
    }
}

function syncPageAccessPermissionInputs(changedId) {
    var canView = pageAccessControl('pageAccessCanView');
    var canEdit = pageAccessControl('pageAccessCanEdit');
    var canDiskView = pageAccessControl('pageAccessCanDiskView');
    var canDiskEdit = pageAccessControl('pageAccessCanDiskEdit');

    if (changedId === 'pageAccessCanEdit' && canEdit && canEdit.checked && canView) canView.checked = true;
    if (changedId === 'pageAccessCanView' && canView && !canView.checked && canEdit) canEdit.checked = false;
    if (changedId === 'pageAccessCanDiskEdit' && canDiskEdit && canDiskEdit.checked && canDiskView) canDiskView.checked = true;
    if (changedId === 'pageAccessCanDiskView' && canDiskView && !canDiskView.checked && canDiskEdit) canDiskEdit.checked = false;
}
