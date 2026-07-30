function historyOperationLabel(operation) {
    var labels = {
        seed: 'Исходное состояние',
        create: 'Создание',
        write: 'Изменение',
        save: 'Сохранение страницы',
        meta_update: 'Свойства страницы',
        parent_change: 'Изменение родителя',
        status_change: 'Изменение статуса',
        content_update: 'Изменение блока',
        placement_change: 'Перемещение по секциям',
        reorder: 'Изменение порядка',
        restore: 'Восстановление версии',
        delete: 'Удаление'
    };

    return labels[String(operation || '')] || String(operation || 'Изменение');
}

function historyActorName(userId) {
    var user = state.historyUsers[String(userId)] || state.historyUsers[Number(userId)] || null;
    return user ? (user.name || user.login || ('Пользователь #' + userId)) : (userId ? ('Пользователь #' + userId) : 'Система');
}

function formatHistoryDate(value) {
    if (!value) return '';

    var date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return date.toLocaleString('ru-RU');
}

function setHistoryMessage(message, isError) {
    var node = document.getElementById('historyMessage');
    if (!node) return;

    node.textContent = message || '';
    node.classList.toggle('sb-history-message--error', !!isError);
    node.hidden = !message;
}

function renderHistory() {
    var list = document.getElementById('historyList');
    if (!list) return;

    if (!state.historyItems.length) {
        list.innerHTML = '';
        setHistoryMessage('История пока пуста.', false);
        return;
    }

    setHistoryMessage('', false);

    list.innerHTML = state.historyItems.map(function (item) {
        var isDelete = String(item.operation || '') === 'delete';
        var restoredText = Number(item.restoredFromRevisionId || 0) > 0
            ? '<div class="sb-history-item__meta">Восстановлено из ревизии #' + Number(item.restoredFromRevisionId || 0) + '</div>'
            : '';

        return ''
            + '<div class="sb-history-item">'
            + '  <div class="sb-history-item__main">'
            + '      <div class="sb-history-item__title">Версия ' + Number(item.version || 1) + ' · ' + escapeHtml(historyOperationLabel(item.operation)) + '</div>'
            + '      <div class="sb-history-item__meta">' + escapeHtml(historyActorName(item.createdBy)) + ' · ' + escapeHtml(formatHistoryDate(item.createdAt)) + '</div>'
            +        restoredText
            + '  </div>'
            + '  <div class="sb-history-item__actions">'
            + '      <button class="sb-btn sb-btn-light sb-btn-small" type="button" data-history-details="' + Number(item.id || 0) + '">JSON</button>'
            + (isDelete
                ? '<span class="sb-editor-chip sb-editor-chip--yellow">удалено</span>'
                : '<button class="sb-btn sb-btn-primary sb-btn-small" type="button" data-history-restore="' + Number(item.id || 0) + '">Восстановить</button>')
            + '  </div>'
            + '</div>';
    }).join('');
}

async function loadHistory(entityType, entityId) {
    entityType = String(entityType || '');
    entityId = Number(entityId || 0);

    if (!entityType || entityId <= 0) {
        setHistoryMessage('Сначала выберите страницу или блок.', true);
        return;
    }

    state.historyEntityType = entityType;
    state.historyEntityId = entityId;
    setHistoryMessage('Загрузка истории...', false);

    try {
        var res = await api('history.list', {
            entityType: entityType,
            entityId: entityId,
            limit: 50
        });

        state.historyItems = Array.isArray(res.items) ? res.items : [];
        state.historyUsers = res.users || {};
        renderHistory();
    } catch (error) {
        console.error(error);
        setHistoryMessage('Не удалось загрузить историю.', true);
    }
}

async function showRevisionDetails(revisionId) {
    try {
        var res = await api('history.get', {
            revisionId: Number(revisionId || 0)
        });

        var revision = res.revision || {};
        alert(JSON.stringify(revision.snapshot || {}, null, 2));
    } catch (error) {
        console.error(error);
        setHistoryMessage('Не удалось загрузить ревизию.', true);
    }
}

async function restoreRevision(revisionId) {
    var entity = state.historyEntityType === 'page'
        ? getCurrentPage()
        : getCurrentBlock();

    if (!entity || Number(entity.id || 0) !== Number(state.historyEntityId || 0)) {
        setHistoryMessage('Текущий объект изменился. Откройте историю заново.', true);
        return;
    }

    if (!confirm('Восстановить выбранную версию? Текущее состояние останется в истории.')) {
        return;
    }

    try {
        var res = await api('history.restore', {
            revisionId: Number(revisionId || 0),
            expectedVersion: entityVersion(entity)
        });

        if (state.historyEntityType === 'page') {
            if (res.entity) replaceStatePage(res.entity);
            await loadPages();
            await loadBlocks();
        } else {
            if (res.entity) replaceStateBlock(res.entity);
            await loadBlocks();
        }

        await loadHistory(state.historyEntityType, state.historyEntityId);
    } catch (error) {
        console.error(error);
        if (!error || error.error !== 'VERSION_CONFLICT') {
            setHistoryMessage('Не удалось восстановить версию.', true);
        }
    }
}

var pageHistoryBtn = document.getElementById('pageHistoryBtn');
if (pageHistoryBtn) {
    pageHistoryBtn.addEventListener('click', function () {
        var page = getCurrentPage();
        loadHistory('page', page ? page.id : 0);
    });
}

var blockHistoryBtn = document.getElementById('blockHistoryBtn');
if (blockHistoryBtn) {
    blockHistoryBtn.addEventListener('click', function () {
        var block = getCurrentBlock();
        loadHistory('block', block ? block.id : 0);
    });
}

var historyList = document.getElementById('historyList');
if (historyList) {
    historyList.addEventListener('click', function (event) {
        var detailsButton = event.target.closest('[data-history-details]');
        if (detailsButton) {
            showRevisionDetails(detailsButton.getAttribute('data-history-details'));
            return;
        }

        var restoreButton = event.target.closest('[data-history-restore]');
        if (restoreButton) {
            restoreRevision(restoreButton.getAttribute('data-history-restore'));
        }
    });
}
