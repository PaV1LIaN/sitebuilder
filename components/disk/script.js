(function () {
  function DiskComponent(root) {
    this.root = root;
    this.state = this.readInitialState();
  }

  DiskComponent.prototype.readInitialState = function () {
    var raw = this.root.getAttribute('data-initial-state') || '{}';
    var parsed = {};

    try {
      parsed = JSON.parse(raw);
    } catch (e) {
      parsed = {};
    }

    return {
      siteId: Number(parsed.siteId || this.root.dataset.siteId || 0),
      pageId: Number(parsed.pageId || this.root.dataset.pageId || 0),
      blockId: Number(parsed.blockId || this.root.dataset.blockId || 0),
      blockVersion: Number(parsed.blockVersion || 1),
      rootFolderId: parsed.rootFolderId || null,
      currentFolderId: parsed.currentFolderId || null,
      settings: parsed.settings || {},
      permissions: parsed.permissions || {},
      breadcrumbs: [],
      items: [],
      selectedIds: [],
      searchQuery: '',
      page: 1,
      pageSize: 20,
      pageSizeOptions: [10, 20, 50, 100],
      viewMode: (parsed.settings && parsed.settings.viewMode) || 'table',
      folderAccessItems: [],
      folderAccessUsers: [],
      selectedFolderAccessUser: null,
      loading: false,
      error: null
    };
  };

  DiskComponent.prototype.init = async function () {
    this.prepareModernUi();
    this.bindStaticEvents();

    try {
      var payload = this.getBasePayload();
      payload.sessid = this.getSessid();

      var res = await this.api('bootstrap', payload);
      if (!res || !res.ok) {
        throw new Error((res && (res.message || res.error)) || 'BOOTSTRAP_ERROR');
      }

      var data = res.data || {};

      this.state.siteId = Number(data.siteId || this.state.siteId || 0);
      this.state.pageId = Number(data.pageId || this.state.pageId || 0);
      this.state.blockId = Number(data.blockId || this.state.blockId || 0);
      this.state.settings = data.settings || {};
      this.state.blockVersion = Number(data.blockVersion || this.state.blockVersion || 1);
      this.state.permissions = data.permissions || {};
      this.state.rootFolderId = data.rootFolderId || null;
      this.state.currentFolderId = data.currentFolderId || null;
      this.state.viewMode = (this.state.settings && this.state.settings.viewMode) || 'table';

      this.prepareModernUi();
      this.applyPermissions();
      this.applyInitialViewMode();

      if (!this.state.permissions.canView) {
        this.renderState('no-access');
        return;
      }

      if (!this.state.rootFolderId) {
        this.renderState('no-root');
        return;
      }

      await this.loadFolder(this.state.rootFolderId);
    } catch (e) {
      console.error(e);
      this.state.error = e.message || 'BOOTSTRAP_ERROR';
      this.renderState('error');
    }
  };

  DiskComponent.prototype.prepareModernUi = function () {
    this.root.classList.add('sb-disk--modern');

    var toolbarCandidates = [
      '[data-role="search-input"]',
      '[data-role="sort-select"]',
      '[data-action="upload"]',
      '[data-action="create-folder"]',
      '[data-action="refresh"]',
      '[data-action="settings"]',
      '.sb-disk__view-btn'
    ];

    toolbarCandidates.forEach(function (selector) {
      this.root.querySelectorAll(selector).forEach(function (node) {
        node.classList.add('sb-disk-modern-control');
      });
    }, this);

    var uploadBtn = this.root.querySelector('[data-action="upload"]');
    if (uploadBtn) {
      uploadBtn.classList.add('sb-disk-modern-primary');
    }

    var searchInput = this.root.querySelector('[data-role="search-input"]');
    if (searchInput && !searchInput.getAttribute('placeholder')) {
      searchInput.setAttribute('placeholder', 'Поиск файлов и папок');
    }

    this.renameTypeColumnToAddedBy();
  };

  DiskComponent.prototype.applyPermissions = function () {
    var permissions = this.state.permissions || {};

    this.root.querySelectorAll('[data-permission]').forEach(function (node) {
      var key = node.getAttribute('data-permission') || '';
      node.hidden = !permissions[key];
    });

    var accessButton = this.root.querySelector('[data-action="folder-access"]');
    if (accessButton) {
      accessButton.hidden = !permissions.canManageAccess;
    }
  };

  DiskComponent.prototype.renameTypeColumnToAddedBy = function () {
    var table = this.root.querySelector('table');

    if (!table) {
        return;
    }

    var headers = table.querySelectorAll('thead th');

    headers.forEach(function (th) {
        var text = String(th.textContent || '').trim().toLowerCase();

        if (text === 'тип') {
        th.textContent = 'Добавил';
        }
    });
    };

  DiskComponent.prototype.getBasePayload = function () {
    return {
      siteId: this.state.siteId,
      pageId: this.state.pageId,
      blockId: this.state.blockId
    };
  };

  DiskComponent.prototype.getSessid = function () {
    if (window.BX && typeof BX.bitrix_sessid === 'function') {
      var bxSessid = BX.bitrix_sessid();
      if (bxSessid) {
        return String(bxSessid);
      }
    }

    var sessidFromData = this.root.getAttribute('data-sessid');
    if (sessidFromData) {
      return String(sessidFromData);
    }

    return '';
  };

  DiskComponent.prototype.api = async function (action, payload, isFormData) {
    var url = '/local/sitebuilder/components/disk/api.php?action=' + encodeURIComponent(action);

    var response;

    if (isFormData) {
        response = await fetch(url, {
        method: 'POST',
        body: payload
        });
    } else {
        response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload || {})
        });
    }

    var text = await response.text();
    var json = null;

    try {
        json = JSON.parse(text);
    } catch (e) {
        console.error('Disk API returned non JSON for action=' + action, text);

        var cleanText = String(text || '')
        .replace(/<script[\s\S]*?<\/script>/gi, ' ')
        .replace(/<style[\s\S]*?<\/style>/gi, ' ')
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 500);

        throw new Error(
        'API вернул HTML вместо JSON. action=' + action + '. Ответ: ' + cleanText
        );
    }

    return json;
    };

  DiskComponent.prototype.apiUploadWithProgress = function (action, formData, onProgress) {
    return new Promise(function (resolve, reject) {
        var xhr = new XMLHttpRequest();

        xhr.open(
        'POST',
        '/local/sitebuilder/components/disk/api.php?action=' + encodeURIComponent(action),
        true
        );

        xhr.upload.addEventListener('progress', function (event) {
        if (!event.lengthComputable) {
            return;
        }

        if (typeof onProgress === 'function') {
            onProgress({
            loaded: event.loaded,
            total: event.total,
            percent: event.total > 0 ? Math.round((event.loaded / event.total) * 100) : 0
            });
        }
        });

        xhr.addEventListener('load', function () {
        var text = xhr.responseText || '';
        var json = null;

        try {
            json = JSON.parse(text);
        } catch (e) {
            reject(new Error('UPLOAD_BAD_RESPONSE'));
            return;
        }

        resolve(json);
        });

        xhr.addEventListener('error', function () {
        reject(new Error('UPLOAD_NETWORK_ERROR'));
        });

        xhr.addEventListener('abort', function () {
        reject(new Error('UPLOAD_ABORTED'));
        });

        xhr.send(formData);
    });
    };

  DiskComponent.prototype.applyInitialViewMode = function () {
    this.setViewMode(this.state.viewMode || 'table');
  };

  DiskComponent.prototype.setViewMode = function (mode) {
    this.state.viewMode = mode === 'grid' ? 'grid' : 'table';

    var tableContainer = this.root.querySelector('[data-view-container="table"]');
    var gridContainer = this.root.querySelector('[data-view-container="grid"]');
    var buttons = this.root.querySelectorAll('.sb-disk__view-btn');

    if (tableContainer) {
      tableContainer.hidden = this.state.viewMode !== 'table';
    }

    if (gridContainer) {
      gridContainer.hidden = this.state.viewMode !== 'grid';

      if (!gridContainer.classList.contains('sb-disk__grid')) {
        gridContainer.classList.add('sb-disk__grid');
      }
    }

    buttons.forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-view') === mode);
    });
  };

  DiskComponent.prototype.getSortValue = function () {
    var select = this.root.querySelector('[data-role="sort-select"]');
    return select && select.value ? String(select.value) : 'updatedAt:desc';
  };

  DiskComponent.prototype.getSortBy = function () {
    return this.getSortValue().split(':')[0] || 'updatedAt';
  };

  DiskComponent.prototype.getSortDir = function () {
    return this.getSortValue().split(':')[1] || 'desc';
  };

  DiskComponent.prototype.loadFolder = async function (folderId) {
    try {
      this.setLoading(true);

      var payload = this.getBasePayload();
      payload.currentFolderId = folderId;
      payload.sortBy = this.getSortBy();
      payload.sortDir = this.getSortDir();
      payload.filters = {};
      payload.sessid = this.getSessid();

      var res = await this.api('list', payload);

      if (!res || !res.ok) {
        throw new Error((res && (res.message || res.error)) || 'LIST_ERROR');
      }

      this.state.currentFolderId = folderId;
      this.state.page = 1;
      this.state.items = Array.isArray(res.data.items) ? res.data.items : [];
      this.state.breadcrumbs = Array.isArray(res.data.breadcrumbs) ? res.data.breadcrumbs : [];
      this.state.permissions = res.data.permissions || this.state.permissions || {};
      this.state.selectedIds = [];

      this.applyPermissions();
      this.renderAll();

      if (res.meta && res.meta.noRoot) {
        this.renderState('no-root');
        return;
      }

      if (!this.state.items.length) {
        this.renderState('empty');
      } else {
        this.renderState(null);
      }
    } catch (e) {
      console.error(e);
      this.state.error = e.message || 'LIST_ERROR';
      this.renderState('error');
    } finally {
      this.setLoading(false);
    }
  };

  DiskComponent.prototype.search = async function (query) {
    try {
      var payload = this.getBasePayload();
      payload.query = query;
      payload.sessid = this.getSessid();

      var res = await this.api('search', payload);

      if (!res || !res.ok) {
        throw new Error((res && (res.message || res.error)) || 'SEARCH_ERROR');
      }

      this.state.items = Array.isArray(res.data.items) ? res.data.items : [];
      this.state.page = 1;
      this.state.selectedIds = [];
      this.renderAll();

      if (!this.state.items.length) {
        this.renderState('empty');
      } else {
        this.renderState(null);
      }
    } catch (e) {
      console.error(e);
      this.renderState('error');
    }
  };

  /* =========================================================
     DISPLAY ORDER / HISTORY HELPERS
     ========================================================= */

     DiskComponent.prototype.getHistoryNameInfo = function (item) {
        var name = String(item && item.name ? item.name : '');

        var prefixes = [
            '_history__',
            'history__',
            '_history_',
            'history_'
        ];

        for (var i = 0; i < prefixes.length; i++) {
            var prefix = prefixes[i];

            if (name.indexOf(prefix) !== 0) {
            continue;
            }

            var rest = name.slice(prefix.length);
            var sepIndex = rest.indexOf('__');

            if (sepIndex < 0) {
            return {
                isHistory: true,
                originalName: name,
                timestamp: 0
            };
            }

            var timePart = rest.slice(0, sepIndex);
            var originalName = rest.slice(sepIndex + 2);
            var timestamp = Number(String(timePart).split('_')[0] || 0);

            return {
            isHistory: true,
            originalName: originalName || name,
            timestamp: timestamp > 0 ? timestamp : 0
            };
        }

        return {
            isHistory: false,
            originalName: name,
            timestamp: 0
        };
        };

        DiskComponent.prototype.isHistoryItem = function (item) {
        return this.getHistoryNameInfo(item).isHistory;
        };

        DiskComponent.prototype.getHistoryOriginalName = function (item) {
        return this.getHistoryNameInfo(item).originalName;
        };

        DiskComponent.prototype.getHistoryTime = function (item) {
        return this.getHistoryNameInfo(item).timestamp;
        };

        DiskComponent.prototype.formatHistoryDate = function (timestamp) {
        timestamp = Number(timestamp || 0);

        if (!timestamp) {
            return 'Старая версия';
        }

        var date = new Date(timestamp);

        return date.toLocaleString('ru-RU');
        };

        DiskComponent.prototype.buildHistoryFileName = function (originalName) {
        originalName = String(originalName || '').trim();

        if (!originalName) {
            originalName = 'file';
        }

        var stamp = Date.now() + '_' + Math.floor(Math.random() * 100000);

        return 'history__' + stamp + '__' + originalName;
        };

  DiskComponent.prototype.getHistoryItemsForFile = function (fileName) {
    fileName = String(fileName || '').trim().toLowerCase();

    if (!fileName) {
      return [];
    }

    return this.state.items
      .filter(function (item) {
        if (!this.isHistoryItem(item)) {
          return false;
        }

        return String(this.getHistoryOriginalName(item) || '').trim().toLowerCase() === fileName;
      }, this)
      .sort(function (a, b) {
        return this.getHistoryTime(b) - this.getHistoryTime(a);
      }.bind(this));
  };

  DiskComponent.prototype.getDisplayItems = function () {
    var folders = [];
    var files = [];

    this.state.items.forEach(function (item) {
      if (this.isHistoryItem(item)) {
        return;
      }

      if (String(item.entityType || '').toLowerCase() === 'folder') {
        folders.push(item);
      } else {
        files.push(item);
      }
    }, this);

    return folders.concat(files);
  };

  DiskComponent.prototype.getPaginationInfo = function () {
    var items = this.getDisplayItems();
    var options = Array.isArray(this.state.pageSizeOptions)
      ? this.state.pageSizeOptions
      : [10, 20, 50, 100];

    var pageSize = Number(this.state.pageSize || 20);

    if (options.indexOf(pageSize) === -1) {
      pageSize = 20;
    }

    var total = items.length;
    var totalPages = Math.max(1, Math.ceil(total / pageSize));
    var page = Number(this.state.page || 1);

    if (!Number.isFinite(page)) {
      page = 1;
    }

    page = Math.max(1, Math.min(totalPages, Math.floor(page)));

    var start = total > 0 ? (page - 1) * pageSize : 0;
    var end = Math.min(start + pageSize, total);

    this.state.page = page;
    this.state.pageSize = pageSize;

    return {
      items: items,
      total: total,
      totalPages: totalPages,
      page: page,
      pageSize: pageSize,
      start: start,
      end: end
    };
  };

  DiskComponent.prototype.getPagedDisplayItems = function () {
    var info = this.getPaginationInfo();

    return info.items.slice(info.start, info.end);
  };

  DiskComponent.prototype.getPaginationTokens = function (page, totalPages) {
    if (totalPages <= 7) {
      var allPages = [];

      for (var allPage = 1; allPage <= totalPages; allPage++) {
        allPages.push(allPage);
      }

      return allPages;
    }

    var tokens = [1];
    var start = Math.max(2, page - 1);
    var end = Math.min(totalPages - 1, page + 1);

    if (page <= 4) {
      end = Math.min(totalPages - 1, 5);
    }

    if (page >= totalPages - 3) {
      start = Math.max(2, totalPages - 4);
    }

    if (start > 2) {
      tokens.push('ellipsis-left');
    }

    for (var current = start; current <= end; current++) {
      tokens.push(current);
    }

    if (end < totalPages - 1) {
      tokens.push('ellipsis-right');
    }

    tokens.push(totalPages);

    return tokens;
  };

  DiskComponent.prototype.setPage = function (page) {
    var info = this.getPaginationInfo();
    var nextPage = Math.max(
      1,
      Math.min(info.totalPages, Math.floor(Number(page || 1)))
    );

    this.state.page = nextPage;
    this.state.selectedIds = [];

    var selectAll = this.root.querySelector('[data-role="select-all"]');

    if (selectAll) {
      selectAll.checked = false;
      selectAll.indeterminate = false;
    }

    this.renderAll();
  };

  DiskComponent.prototype.setPageSize = function (pageSize) {
    var options = Array.isArray(this.state.pageSizeOptions)
      ? this.state.pageSizeOptions
      : [10, 20, 50, 100];

    pageSize = Number(pageSize || 20);

    if (options.indexOf(pageSize) === -1) {
      pageSize = 20;
    }

    this.state.pageSize = pageSize;
    this.state.page = 1;
    this.state.selectedIds = [];

    var selectAll = this.root.querySelector('[data-role="select-all"]');

    if (selectAll) {
      selectAll.checked = false;
      selectAll.indeterminate = false;
    }

    this.renderAll();
  };

  DiskComponent.prototype.renderPagination = function () {
    var self = this;
    var info = this.getPaginationInfo();
    var tableContainer = this.root.querySelector(
      '[data-view-container="table"]'
    );
    var gridContainer = this.root.querySelector(
      '[data-view-container="grid"]'
    );
    var anchor = gridContainer || tableContainer;

    if (!anchor || !anchor.parentNode) {
      return;
    }

    var pagination = this.root.querySelector(
      '[data-role="disk-pagination"]'
    );

    if (!pagination) {
      pagination = document.createElement('div');
      pagination.className = 'sb-disk__pagination';
      pagination.setAttribute('data-role', 'disk-pagination');

      anchor.parentNode.insertBefore(
        pagination,
        anchor.nextSibling
      );
    }

    if (info.total <= 0) {
      pagination.hidden = true;
      pagination.innerHTML = '';
      return;
    }

    pagination.hidden = false;

    var pageSizeOptions = (
      Array.isArray(this.state.pageSizeOptions)
        ? this.state.pageSizeOptions
        : [10, 20, 50, 100]
    ).map(function (value) {
      return ''
        + '<option value="' + value + '"'
        + (value === info.pageSize ? ' selected' : '')
        + '>' + value + '</option>';
    }).join('');

    var pageButtons = this.getPaginationTokens(
      info.page,
      info.totalPages
    ).map(function (token) {
      if (typeof token !== 'number') {
        return '<span class="sb-disk__pagination-ellipsis">…</span>';
      }

      var active = token === info.page;

      return ''
        + '<button type="button"'
        + ' class="sb-disk__pagination-btn'
        + (active ? ' is-active' : '') + '"'
        + ' data-pagination-page="' + token + '"'
        + (active ? ' aria-current="page"' : '')
        + '>' + token + '</button>';
    }).join('');

    pagination.innerHTML = ''
      + '<div class="sb-disk__pagination-summary">'
      + '  <span>Показано '
      + (info.start + 1)
      + '–'
      + info.end
      + ' из '
      + info.total
      + '</span>'
      + '  <label class="sb-disk__page-size">'
      + '    <span>На странице</span>'
      + '    <select data-role="page-size">' + pageSizeOptions + '</select>'
      + '  </label>'
      + '</div>'
      + '<div class="sb-disk__pagination-pages">'
      + '  <button type="button" class="sb-disk__pagination-btn sb-disk__pagination-btn--wide"'
      + ' data-pagination-page="' + (info.page - 1) + '"'
      + (info.page <= 1 ? ' disabled' : '')
      + '>Назад</button>'
      + pageButtons
      + '  <button type="button" class="sb-disk__pagination-btn sb-disk__pagination-btn--wide"'
      + ' data-pagination-page="' + (info.page + 1) + '"'
      + (info.page >= info.totalPages ? ' disabled' : '')
      + '>Вперёд</button>'
      + '</div>';

    pagination.querySelectorAll('[data-pagination-page]').forEach(
      function (button) {
        button.addEventListener('click', function () {
          if (button.disabled) {
            return;
          }

          self.setPage(
            Number(button.getAttribute('data-pagination-page') || 1)
          );
        });
      }
    );

    var pageSizeSelect = pagination.querySelector(
      '[data-role="page-size"]'
    );

    if (pageSizeSelect) {
      pageSizeSelect.addEventListener('change', function () {
        self.setPageSize(Number(pageSizeSelect.value || 20));
      });
    }
  };
  DiskComponent.prototype.renderHistoryControl = function (item) {
    if (!item || String(item.entityType || '') !== 'file') {
      return '';
    }

    if (this.isHistoryItem(item)) {
      return '';
    }

    var historyItems = this.getHistoryItemsForFile(item.name);

    if (!historyItems.length) {
      return '';
    }

    return '<button type="button" class="sb-disk__row-btn" data-row-action="history">История</button>';
  };

  DiskComponent.prototype.openHistoryModalForFile = function (fileName) {
    var self = this;
    var historyItems = this.getHistoryItemsForFile(fileName);

    if (!historyItems.length) {
      alert('Истории замен для этого файла пока нет');
      return;
    }

    var modal = document.createElement('div');
    modal.className = 'sb-disk-history-modal';

    modal.innerHTML = ''
      + '<div class="sb-disk-history-modal__backdrop" data-history-action="close"></div>'
      + '<div class="sb-disk-history-modal__dialog">'
      + '  <div class="sb-disk-history-modal__head">'
      + '    <div>'
      + '      <div class="sb-disk-history-modal__title">История файла</div>'
      + '      <div class="sb-disk-history-modal__subtitle">' + escapeHtml(fileName) + '</div>'
      + '    </div>'
      + '    <button type="button" class="sb-disk-history-modal__close" data-history-action="close">×</button>'
      + '  </div>'
      + '  <div class="sb-disk-history-modal__body">'
      + historyItems.map(function (item) {
        var time = self.formatHistoryDate(self.getHistoryTime(item));
        var size = item.size ? formatBytes(item.size) : '—';
        var addedByText = getItemAddedByText(item);

          return ''
            + '<div class="sb-disk-history-item" data-history-id="' + escapeHtml(item.id) + '">'
            + '  <div class="sb-disk-history-item__main">'
            + '    <div class="sb-disk-history-item__name">' + escapeHtml(self.getHistoryOriginalName(item)) + '</div>'
            + '    <div class="sb-disk-history-item__meta">'
            + '      <span>' + escapeHtml(time) + '</span>'
            + '      <span>' + escapeHtml(size) + '</span>'
            + '      <span>Добавил: ' + escapeHtml(addedByText) + '</span>'
            + '    </div>'
            + '  </div>'
            + '  <div class="sb-disk-history-item__actions">'
            + '    <button type="button" class="sb-disk-history-btn is-primary" data-history-action="open" data-history-id="' + escapeHtml(item.id) + '">Открыть</button>'
            + (item.downloadUrl
                ? '    <button type="button" class="sb-disk-history-btn" data-history-action="download" data-history-id="' + escapeHtml(item.id) + '">Скачать</button>'
                : '')
            + '  </div>'
            + '</div>';
        }).join('')
      + '  </div>'
      + '</div>';

    function findHistoryItem(id) {
      id = Number(id || 0);

      for (var i = 0; i < historyItems.length; i++) {
        if (Number(historyItems[i].id || 0) === id) {
          return historyItems[i];
        }
      }

      return null;
    }

    function close() {
      if (modal && modal.parentNode) {
        modal.parentNode.removeChild(modal);
      }

      document.removeEventListener('keydown', onKeyDown);
    }

    function onKeyDown(e) {
      if (e.key === 'Escape') {
        close();
      }
    }

    modal.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-history-action]');

      if (!btn) {
        return;
      }

      var action = btn.getAttribute('data-history-action');

      if (action === 'close') {
        close();
        return;
      }

      var id = Number(btn.getAttribute('data-history-id') || 0);
      var item = findHistoryItem(id);

      if (!item) {
        return;
      }

      if (action === 'open') {
        if (item.previewUrl) {
          window.open(item.previewUrl, '_blank');
          return;
        }

        if (item.downloadUrl) {
          window.open(item.downloadUrl, '_blank');
        }

        return;
      }

      if (action === 'download') {
        if (item.downloadUrl) {
          window.open(item.downloadUrl, '_blank');
        }
      }
    });

    document.addEventListener('keydown', onKeyDown);
    document.body.appendChild(modal);
  };

  DiskComponent.prototype.renameDiskItem = async function (item, newName) {
    var payload = this.getBasePayload();

    payload.entityType = item.entityType || 'file';
    payload.entityId = Number(item.id || 0);
    payload.newName = newName;
    payload.sessid = this.getSessid();

    var res = await this.api('rename', payload);

    if (!res || !res.ok) {
      throw new Error((res && (res.message || res.error)) || 'RENAME_ERROR');
    }

    return res;
  };

  DiskComponent.prototype.archiveExistingFileToHistory = async function (existingItem) {
    if (!existingItem || String(existingItem.entityType || '') !== 'file') {
      return;
    }

    var oldName = String(existingItem.name || '').trim();

    if (!oldName) {
      return;
    }

    var historyName = this.buildHistoryFileName(oldName);

    await this.renameDiskItem(existingItem, historyName);

    existingItem.name = historyName;
  };

  /* =========================================================
     DOUBLE CLICK OPEN
     ========================================================= */

  DiskComponent.prototype.openFileFromElement = function (element) {
    if (!element) {
      return;
    }

    var entityType = element.getAttribute('data-entity-type') || '';

    if (entityType !== 'file') {
      return;
    }

    var entityId = Number(element.getAttribute('data-id') || 0);
    var previewMode = element.getAttribute('data-preview-mode') || '';
    var previewUrl = element.getAttribute('data-preview-url') || '';
    var downloadUrl = element.getAttribute('data-download-url') || '';

    var openUrl = previewUrl || downloadUrl || '';
    var openKey = 'file:' + entityId + ':' + openUrl;
    var now = Date.now();

    window.__SB_DISK_OPEN_LOCK__ = window.__SB_DISK_OPEN_LOCK__ || {
      key: '',
      time: 0
    };

    if (
      window.__SB_DISK_OPEN_LOCK__.key === openKey &&
      now - window.__SB_DISK_OPEN_LOCK__.time < 1200
    ) {
      return;
    }

    window.__SB_DISK_OPEN_LOCK__.key = openKey;
    window.__SB_DISK_OPEN_LOCK__.time = now;

    if (previewMode === 'office') {
      var viewerBtn = element.querySelector('[data-viewer]');

      if (viewerBtn) {
        viewerBtn.click();
        return;
      }
    }

    if (previewUrl) {
      window.open(previewUrl, '_blank');
      return;
    }

    if (downloadUrl) {
      window.open(downloadUrl, '_blank');
    }
  };

  /* =========================================================
     DUPLICATE FILE UPLOAD
     ========================================================= */

  DiskComponent.prototype.findExistingFileByName = function (fileName) {
    fileName = String(fileName || '').trim().toLowerCase();

    if (!fileName) {
      return null;
    }

    for (var i = 0; i < this.state.items.length; i++) {
      var item = this.state.items[i];

      if (this.isHistoryItem(item)) {
        continue;
      }

      if (String(item.entityType || '').toLowerCase() !== 'file') {
        continue;
      }

      if (String(item.name || '').trim().toLowerCase() === fileName) {
        return item;
      }
    }

    return null;
  };

  DiskComponent.prototype.splitFileName = function (fileName) {
    fileName = String(fileName || '').trim();

    var dotIndex = fileName.lastIndexOf('.');

    if (dotIndex <= 0) {
      return {
        base: fileName,
        ext: ''
      };
    }

    return {
      base: fileName.slice(0, dotIndex),
      ext: fileName.slice(dotIndex)
    };
  };

  DiskComponent.prototype.suggestDuplicateFileName = function (fileName) {
    var parts = this.splitFileName(fileName);
    var base = parts.base || 'file';
    var ext = parts.ext || '';
    var index = 1;
    var candidate = base + ' (копия)' + ext;

    while (this.findExistingFileByName(candidate)) {
      index++;
      candidate = base + ' (копия ' + index + ')' + ext;
    }

    return candidate;
  };

  DiskComponent.prototype.makeRenamedFile = function (file, newName) {
    newName = String(newName || '').trim();

    if (!newName) {
      throw new Error('EMPTY_FILE_NAME');
    }

    if (typeof File === 'function') {
      return new File([file], newName, {
        type: file.type,
        lastModified: file.lastModified
      });
    }

    throw new Error('Ваш браузер не поддерживает переименование файла перед загрузкой');
  };

  DiskComponent.prototype.deleteDiskItems = async function (items) {
    var payload = this.getBasePayload();

    payload.items = items;
    payload.sessid = this.getSessid();

    var res = await this.api('delete', payload);

    if (!res || !res.ok) {
      throw new Error((res && (res.message || res.error)) || 'DELETE_ERROR');
    }

    return res;
  };

  DiskComponent.prototype.askDuplicateUploadAction = function (file, existingItem) {
    var self = this;

    return new Promise(function (resolve) {
      var fileName = String(file && file.name ? file.name : '');
      var suggestedName = self.suggestDuplicateFileName(fileName);

      var modal = document.createElement('div');
      modal.className = 'sb-disk-duplicate-modal';

      modal.innerHTML = ''
        + '<div class="sb-disk-duplicate-modal__backdrop" data-duplicate-action="cancel"></div>'
        + '<div class="sb-disk-duplicate-modal__dialog">'
        + '  <div class="sb-disk-duplicate-modal__head">'
        + '    <div>'
        + '      <div class="sb-disk-duplicate-modal__title">Файл уже существует</div>'
        + '      <div class="sb-disk-duplicate-modal__subtitle">В этой папке уже есть файл с таким именем.</div>'
        + '    </div>'
        + '    <button type="button" class="sb-disk-duplicate-modal__close" data-duplicate-action="cancel">×</button>'
        + '  </div>'
        + ''
        + '  <div class="sb-disk-duplicate-modal__body">'
        + '    <div class="sb-disk-duplicate-file">'
        + '      <div class="sb-disk-duplicate-file__label">Файл:</div>'
        + '      <div class="sb-disk-duplicate-file__name">' + escapeHtml(fileName) + '</div>'
        + '    </div>'
        + ''
        + '    <div class="sb-disk-duplicate-field">'
        + '      <label>Новое имя, если выбрать “Переименовать”</label>'
        + '      <input type="text" class="sb-disk-duplicate-input" value="' + escapeHtml(suggestedName) + '">'
        + '    </div>'
        + ''
        + '    <div class="sb-disk-duplicate-note">'
        + '      “Заменить” сохранит старый файл в историю и загрузит новый с тем же именем.'
        + '    </div>'
        + '  </div>'
        + ''
        + '  <div class="sb-disk-duplicate-modal__footer">'
        + '    <button type="button" class="sb-disk-duplicate-btn" data-duplicate-action="cancel">Отмена</button>'
        + '    <button type="button" class="sb-disk-duplicate-btn" data-duplicate-action="rename">Переименовать</button>'
        + '    <button type="button" class="sb-disk-duplicate-btn is-primary" data-duplicate-action="replace">Заменить</button>'
        + '  </div>'
        + '</div>';

      function close(result) {
        if (modal && modal.parentNode) {
          modal.parentNode.removeChild(modal);
        }

        document.removeEventListener('keydown', onKeyDown);

        resolve(result);
      }

      function onKeyDown(e) {
        if (e.key === 'Escape') {
          close({
            action: 'cancel'
          });
        }
      }

      modal.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-duplicate-action]');

        if (!btn) {
          return;
        }

        var action = btn.getAttribute('data-duplicate-action');

        if (action === 'cancel') {
          close({
            action: 'cancel'
          });
          return;
        }

        if (action === 'replace') {
          close({
            action: 'replace',
            existingItem: existingItem
          });
          return;
        }

        if (action === 'rename') {
          var input = modal.querySelector('.sb-disk-duplicate-input');
          var newName = input ? String(input.value || '').trim() : '';

          if (!newName) {
            alert('Введите новое имя файла');
            if (input) input.focus();
            return;
          }

          if (self.findExistingFileByName(newName)) {
            alert('Файл с таким именем уже есть. Укажите другое имя.');
            if (input) input.focus();
            return;
          }

          close({
            action: 'rename',
            name: newName
          });
        }
      });

      document.addEventListener('keydown', onKeyDown);
      document.body.appendChild(modal);

      setTimeout(function () {
        var input = modal.querySelector('.sb-disk-duplicate-input');
        if (input) {
          input.focus();
          input.select();
        }
      }, 50);
    });
  };

  /* =========================================================
   UPLOAD STATUS MODAL
   ========================================================= */

    DiskComponent.prototype.ensureUploadStatusModal = function () {
    if (this.uploadStatusModal && document.body.contains(this.uploadStatusModal)) {
        return this.uploadStatusModal;
    }

    var modal = document.createElement('div');

    modal.className = 'sb-disk-upload-status-modal';
    modal.hidden = true;

    modal.innerHTML = ''
        + '<div class="sb-disk-upload-status-modal__backdrop"></div>'
        + '<div class="sb-disk-upload-status-modal__dialog">'
        + '  <div class="sb-disk-upload-status-modal__head">'
        + '    <div>'
        + '      <div class="sb-disk-upload-status-modal__title">Загрузка файлов</div>'
        + '      <div class="sb-disk-upload-status-modal__subtitle" data-upload-status-subtitle>Подготовка...</div>'
        + '    </div>'
        + '    <button type="button" class="sb-disk-upload-status-modal__close" data-upload-status-close hidden>×</button>'
        + '  </div>'
        + ''
        + '  <div class="sb-disk-upload-status-modal__body">'
        + '    <div class="sb-disk-upload-progress">'
        + '      <div class="sb-disk-upload-progress__top">'
        + '        <span data-upload-status-message>Подготовка файлов...</span>'
        + '        <strong data-upload-status-percent>0%</strong>'
        + '      </div>'
        + '      <div class="sb-disk-upload-progress__track">'
        + '        <div class="sb-disk-upload-progress__bar" data-upload-status-bar></div>'
        + '      </div>'
        + '      <div class="sb-disk-upload-progress__size" data-upload-status-size>0 Б / 0 Б</div>'
        + '    </div>'
        + ''
        + '    <div class="sb-disk-upload-file-list" data-upload-status-files></div>'
        + '  </div>'
        + '</div>';

    var closeBtn = modal.querySelector('[data-upload-status-close]');

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
        modal.hidden = true;
        });
    }

    document.body.appendChild(modal);

    this.uploadStatusModal = modal;

    return modal;
    };

    DiskComponent.prototype.showUploadStatusModal = function (files) {
    files = Array.prototype.slice.call(files || []);

    var modal = this.ensureUploadStatusModal();
    var subtitle = modal.querySelector('[data-upload-status-subtitle]');
    var message = modal.querySelector('[data-upload-status-message]');
    var percent = modal.querySelector('[data-upload-status-percent]');
    var bar = modal.querySelector('[data-upload-status-bar]');
    var size = modal.querySelector('[data-upload-status-size]');
    var list = modal.querySelector('[data-upload-status-files]');
    var closeBtn = modal.querySelector('[data-upload-status-close]');

    var totalSize = files.reduce(function (sum, file) {
        return sum + Number(file && file.size ? file.size : 0);
    }, 0);

    if (subtitle) {
        subtitle.textContent = 'Файлов: ' + files.length;
    }

    if (message) {
        message.textContent = 'Начинаю загрузку...';
    }

    if (percent) {
        percent.textContent = '0%';
    }

    if (bar) {
        bar.style.width = '0%';
    }

    if (size) {
        size.textContent = '0 Б / ' + formatBytes(totalSize);
    }

    if (list) {
        list.innerHTML = files.map(function (file) {
        return ''
            + '<div class="sb-disk-upload-file">'
            + '  <div class="sb-disk-upload-file__name">' + escapeHtml(file.name || 'file') + '</div>'
            + '  <div class="sb-disk-upload-file__size">' + escapeHtml(formatBytes(file.size || 0)) + '</div>'
            + '</div>';
        }).join('');
    }

    if (closeBtn) {
        closeBtn.hidden = true;
    }

    modal.classList.remove('is-success', 'is-error');
    modal.hidden = false;
    };

    DiskComponent.prototype.updateUploadStatusModal = function (data) {
    data = data || {};

    var modal = this.ensureUploadStatusModal();
    var message = modal.querySelector('[data-upload-status-message]');
    var percent = modal.querySelector('[data-upload-status-percent]');
    var bar = modal.querySelector('[data-upload-status-bar]');
    var size = modal.querySelector('[data-upload-status-size]');

    var loaded = Number(data.loaded || 0);
    var total = Number(data.total || 0);
    var progress = Number(data.percent || 0);

    if (progress < 0) {
        progress = 0;
    }

    if (progress > 100) {
        progress = 100;
    }

    if (message) {
        message.textContent = data.message || 'Загружаю файлы...';
    }

    if (percent) {
        percent.textContent = progress + '%';
    }

    if (bar) {
        bar.style.width = progress + '%';
    }

    if (size) {
        size.textContent = formatBytes(loaded) + ' / ' + formatBytes(total);
    }
    };

    DiskComponent.prototype.finishUploadStatusModal = function (success, messageText) {
    var modal = this.ensureUploadStatusModal();
    var message = modal.querySelector('[data-upload-status-message]');
    var percent = modal.querySelector('[data-upload-status-percent]');
    var bar = modal.querySelector('[data-upload-status-bar]');
    var closeBtn = modal.querySelector('[data-upload-status-close]');

    modal.classList.toggle('is-success', !!success);
    modal.classList.toggle('is-error', !success);

    if (message) {
        message.textContent = messageText || (success ? 'Загрузка завершена' : 'Ошибка загрузки');
    }

    if (success) {
        if (percent) {
        percent.textContent = '100%';
        }

        if (bar) {
        bar.style.width = '100%';
        }

        setTimeout(function () {
        modal.hidden = true;
        }, 900);
    } else if (closeBtn) {
        closeBtn.hidden = false;
    }
    };

    /* =========================================================
   UNPACK STATUS MODAL
   ========================================================= */

    DiskComponent.prototype.ensureUnpackStatusModal = function () {
    if (this.unpackStatusModal && document.body.contains(this.unpackStatusModal)) {
        return this.unpackStatusModal;
    }

    var modal = document.createElement('div');

    modal.className = 'sb-disk-upload-status-modal sb-disk-unpack-status-modal';
    modal.hidden = true;

    modal.innerHTML = ''
        + '<div class="sb-disk-upload-status-modal__backdrop"></div>'
        + '<div class="sb-disk-upload-status-modal__dialog">'
        + '  <div class="sb-disk-upload-status-modal__head">'
        + '    <div>'
        + '      <div class="sb-disk-upload-status-modal__title">Распаковка архива</div>'
        + '      <div class="sb-disk-upload-status-modal__subtitle" data-unpack-status-subtitle>Подготовка...</div>'
        + '    </div>'
        + '    <button type="button" class="sb-disk-upload-status-modal__close" data-unpack-status-close hidden>×</button>'
        + '  </div>'
        + ''
        + '  <div class="sb-disk-upload-status-modal__body">'
        + '    <div class="sb-disk-upload-progress">'
        + '      <div class="sb-disk-upload-progress__top">'
        + '        <span data-unpack-status-message>Подготовка архива...</span>'
        + '        <strong data-unpack-status-percent>0%</strong>'
        + '      </div>'
        + '      <div class="sb-disk-upload-progress__track">'
        + '        <div class="sb-disk-upload-progress__bar" data-unpack-status-bar></div>'
        + '      </div>'
        + '      <div class="sb-disk-upload-progress__size" data-unpack-status-info>Ожидание...</div>'
        + '    </div>'
        + ''
        + '    <div class="sb-disk-upload-file-list">'
        + '      <div class="sb-disk-upload-file">'
        + '        <div class="sb-disk-upload-file__name" data-unpack-status-file>Архив</div>'
        + '        <div class="sb-disk-upload-file__size">ZIP</div>'
        + '      </div>'
        + '    </div>'
        + '  </div>'
        + '</div>';

    var closeBtn = modal.querySelector('[data-unpack-status-close]');

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
        modal.hidden = true;
        });
    }

    document.body.appendChild(modal);

    this.unpackStatusModal = modal;

    return modal;
    };

    DiskComponent.prototype.showUnpackStatusModal = function (fileName) {
    var modal = this.ensureUnpackStatusModal();

    var subtitle = modal.querySelector('[data-unpack-status-subtitle]');
    var message = modal.querySelector('[data-unpack-status-message]');
    var percent = modal.querySelector('[data-unpack-status-percent]');
    var bar = modal.querySelector('[data-unpack-status-bar]');
    var info = modal.querySelector('[data-unpack-status-info]');
    var file = modal.querySelector('[data-unpack-status-file]');
    var closeBtn = modal.querySelector('[data-unpack-status-close]');

    if (subtitle) {
        subtitle.textContent = 'Файл: ' + (fileName || 'архив.zip');
    }

    if (message) {
        message.textContent = 'Начинаю распаковку...';
    }

    if (percent) {
        percent.textContent = '0%';
    }

    if (bar) {
        bar.style.width = '0%';
    }

    if (info) {
        info.textContent = 'Проверяю архив и подготавливаю распаковку...';
    }

    if (file) {
        file.textContent = fileName || 'архив.zip';
    }

    if (closeBtn) {
        closeBtn.hidden = true;
    }

    modal.classList.remove('is-success', 'is-error');
    modal.hidden = false;

    this.stopUnpackProgressTicker();
    };

    DiskComponent.prototype.updateUnpackStatusModal = function (data) {
    data = data || {};

    var modal = this.ensureUnpackStatusModal();
    var message = modal.querySelector('[data-unpack-status-message]');
    var percent = modal.querySelector('[data-unpack-status-percent]');
    var bar = modal.querySelector('[data-unpack-status-bar]');
    var info = modal.querySelector('[data-unpack-status-info]');

    var progress = Number(data.percent || 0);

    if (progress < 0) {
        progress = 0;
    }

    if (progress > 100) {
        progress = 100;
    }

    if (message) {
        message.textContent = data.message || 'Распаковываю архив...';
    }

    if (percent) {
        percent.textContent = progress + '%';
    }

    if (bar) {
        bar.style.width = progress + '%';
    }

    if (info) {
        info.textContent = data.info || 'Пожалуйста, подождите...';
    }
    };

    DiskComponent.prototype.startUnpackProgressTicker = function () {
    var self = this;

    this.stopUnpackProgressTicker();

    this.unpackProgressValue = 0;

    this.unpackProgressTimer = setInterval(function () {
        var value = Number(self.unpackProgressValue || 0);

        if (value < 30) {
        value += 7;
        } else if (value < 60) {
        value += 4;
        } else if (value < 85) {
        value += 2;
        } else if (value < 90) {
        value += 1;
        } else {
        value = 90;
        }

        self.unpackProgressValue = value;

        self.updateUnpackStatusModal({
        percent: value,
        message: value < 40 ? 'Проверяю архив...' : 'Распаковываю файлы...',
        info: 'Это может занять несколько секунд.'
        });
    }, 350);
    };

    DiskComponent.prototype.stopUnpackProgressTicker = function () {
    if (this.unpackProgressTimer) {
        clearInterval(this.unpackProgressTimer);
        this.unpackProgressTimer = null;
    }
    };

    DiskComponent.prototype.finishUnpackStatusModal = function (success, messageText, data) {
    data = data || {};

    this.stopUnpackProgressTicker();

    var modal = this.ensureUnpackStatusModal();
    var message = modal.querySelector('[data-unpack-status-message]');
    var percent = modal.querySelector('[data-unpack-status-percent]');
    var bar = modal.querySelector('[data-unpack-status-bar]');
    var info = modal.querySelector('[data-unpack-status-info]');
    var closeBtn = modal.querySelector('[data-unpack-status-close]');

    modal.classList.toggle('is-success', !!success);
    modal.classList.toggle('is-error', !success);

    if (message) {
        message.textContent = messageText || (success ? 'Распаковка завершена' : 'Ошибка распаковки');
    }

    if (success) {
        if (percent) {
        percent.textContent = '100%';
        }

        if (bar) {
        bar.style.width = '100%';
        }

        if (info) {
        var extractedFiles = Number(data.extractedFiles || 0);
        var createdFolders = Number(data.createdFolders || 0);

        info.textContent = 'Файлов: ' + extractedFiles + ' · Папок: ' + createdFolders;
        }

        setTimeout(function () {
        modal.hidden = true;
        }, 1200);
    } else {
        if (info) {
        info.textContent = 'Проверьте архив или настройки сервера.';
        }

        if (closeBtn) {
        closeBtn.hidden = false;
        }
    }
    };

    DiskComponent.prototype.sleep = function (ms) {
        return new Promise(function (resolve) {
            setTimeout(resolve, ms);
        });
        };

        DiskComponent.prototype.unpackArchiveFromRow = async function (unpackRow) {
            var fileName = unpackRow.getAttribute('data-name') || 'архив';

            this.showUnpackStatusModal(fileName);

            this.updateUnpackStatusModal({
                percent: 1,
                message: 'Создаю задачу распаковки...',
                info: 'Проверяю архив.'
            });

            var startPayload = this.getBasePayload();

            startPayload.fileId = Number(unpackRow.getAttribute('data-id') || 0);
            startPayload.sessid = this.getSessid();

            var startRes = await this.api('unpackArchiveStart', startPayload);

            if (!startRes || !startRes.ok) {
                this.finishUnpackStatusModal(
                false,
                (startRes && (startRes.message || startRes.error)) || 'Не удалось начать распаковку',
                {}
                );
                return;
            }

            var startData = startRes.data || {};
            var jobId = startData.jobId || '';

            if (!jobId) {
                this.finishUnpackStatusModal(false, 'UNPACK_JOB_ID_EMPTY', {});
                return;
            }

            var totalEntries = Number(startData.totalEntries || 0);
            var totalFiles = Number(startData.totalFiles || 0);
            var totalFolders = Number(startData.totalFolders || 0);
            var nextEntryName = startData.nextEntryName || '';

            this.updateUnpackStatusModal({
                percent: 1,
                message: 'Архив проверен. Начинаю распаковку...',
                info:
                'Файлов: ' + totalFiles +
                ' · Папок: ' + totalFolders +
                ' · Всего элементов: ' + totalEntries
            });

            var done = false;
            var lastData = null;

            while (!done) {
                if (nextEntryName) {
                this.updateUnpackStatusModal({
                    percent: lastData && lastData.percent ? lastData.percent : 1,
                    message: 'Распаковываю: ' + nextEntryName,
                    info:
                    'Обработано: ' +
                    (lastData && lastData.index ? lastData.index : 0) +
                    ' из ' +
                    totalEntries
                });
                }

                var stepPayload = this.getBasePayload();

                stepPayload.jobId = jobId;
                stepPayload.sessid = this.getSessid();

                var stepRes = await this.api('unpackArchiveStep', stepPayload);

                if (!stepRes || !stepRes.ok) {
                this.finishUnpackStatusModal(
                    false,
                    (stepRes && (stepRes.message || stepRes.error)) || 'Ошибка шага распаковки',
                    {}
                );
                return;
                }

                var stepData = stepRes.data || {};

                lastData = stepData;
                done = !!stepData.done;
                nextEntryName = stepData.nextEntryName || '';

                var percent = Number(stepData.percent || 1);

                if (percent < 1) {
                percent = 1;
                }

                if (percent > 100) {
                percent = 100;
                }

                this.updateUnpackStatusModal({
                percent: percent,
                message: done
                    ? 'Завершаю распаковку...'
                    : 'Распаковано: ' + (stepData.lastEntryName || 'элемент'),
                info:
                    'Обработано: ' +
                    (stepData.index || 0) +
                    ' из ' +
                    (stepData.totalEntries || totalEntries) +
                    ' · Файлов: ' +
                    (stepData.extractedFiles || 0) +
                    ' · Папок: ' +
                    (stepData.createdFolders || 0)
                });

                if (!done) {
                await this.sleep(120);
                }
            }

            this.finishUnpackStatusModal(true, 'Распаковка завершена', {
                extractedFiles: lastData && lastData.extractedFiles ? lastData.extractedFiles : 0,
                createdFolders: lastData && lastData.createdFolders ? lastData.createdFolders : 0,
                totalSize: lastData && lastData.totalSize ? lastData.totalSize : 0
            });

            var targetFolder = lastData && lastData.targetFolder ? lastData.targetFolder : null;
            var self = this;

            setTimeout(async function () {
                if (targetFolder && targetFolder.id) {
                await self.loadFolder(Number(targetFolder.id));
                } else {
                await self.loadFolder(self.state.currentFolderId || self.state.rootFolderId);
                }
            }, 700);
            };



  /* =========================================================
     EVENTS
     ========================================================= */

     DiskComponent.prototype.setDragOver = function (active) {
        this.root.classList.toggle('is-dragover', !!active);
        };

        DiskComponent.prototype.uploadFiles = async function (files) {
            files = Array.prototype.slice.call(files || []);

            if (!files.length) {
                return;
            }

            if (!this.state.permissions.canUpload) {
                alert('У вас нет прав на загрузку файлов');
                return;
            }

            var preparedFiles = [];

            try {
                for (var i = 0; i < files.length; i++) {
                var file = files[i];

                if (!file || !file.name) {
                    continue;
                }

                var existingItem = this.findExistingFileByName(file.name);

                if (!existingItem) {
                    preparedFiles.push(file);
                    continue;
                }

                var decision = await this.askDuplicateUploadAction(file, existingItem);

                if (!decision || decision.action === 'cancel') {
                    continue;
                }

                if (decision.action === 'replace') {
                    await this.archiveExistingFileToHistory(existingItem);

                    preparedFiles.push(file);
                    continue;
                }

                if (decision.action === 'rename') {
                    preparedFiles.push(this.makeRenamedFile(file, decision.name));
                }
                }

                if (!preparedFiles.length) {
                return;
                }

                this.showUploadStatusModal(preparedFiles);

                var formData = new FormData();

                formData.append('siteId', this.state.siteId);
                formData.append('pageId', this.state.pageId);
                formData.append('blockId', this.state.blockId);
                formData.append('currentFolderId', this.state.currentFolderId);
                formData.append('sessid', this.getSessid());

                preparedFiles.forEach(function (file) {
                formData.append('files[]', file);
                });

                var self = this;

                var res = await this.apiUploadWithProgress('upload', formData, function (progress) {
                self.updateUploadStatusModal({
                    loaded: progress.loaded,
                    total: progress.total,
                    percent: progress.percent,
                    message: 'Загружаю файлы...'
                });
                });

                if (!res || !res.ok) {
                this.finishUploadStatusModal(false, (res && (res.message || res.error)) || 'Ошибка загрузки');
                return;
                }

                this.updateUploadStatusModal({
                loaded: 1,
                total: 1,
                percent: 100,
                message: 'Загрузка завершена. Обновляю список...'
                });

                await this.loadFolder(this.state.currentFolderId);

                this.finishUploadStatusModal(true, 'Загрузка завершена');
            } catch (err) {
                console.error(err);

                this.finishUploadStatusModal(false, err && err.message ? err.message : 'Ошибка загрузки');
            }
            };


  DiskComponent.prototype.bindStaticEvents = function () {
    var self = this;

    this.root.addEventListener('dblclick', async function (e) {
      var item = e.target.closest(
        '.sb-disk__row[data-id][data-entity-type], .sb-disk__card[data-id][data-entity-type]'
      );

      if (!item || !self.root.contains(item)) {
        return;
      }

      if (e.target.closest('button, input, label, a')) {
        return;
      }

      e.preventDefault();
      e.stopPropagation();

      if (typeof e.stopImmediatePropagation === 'function') {
        e.stopImmediatePropagation();
      }

      var entityType = item.getAttribute('data-entity-type') || '';
      var entityId = Number(item.getAttribute('data-id') || 0);

      var clickKey = entityType + ':' + entityId;
      var now = Date.now();

      window.__SB_DISK_DBLCLICK_LOCK__ = window.__SB_DISK_DBLCLICK_LOCK__ || {
        key: '',
        time: 0
      };

      if (
        window.__SB_DISK_DBLCLICK_LOCK__.key === clickKey &&
        now - window.__SB_DISK_DBLCLICK_LOCK__.time < 1200
      ) {
        return;
      }

      window.__SB_DISK_DBLCLICK_LOCK__.key = clickKey;
      window.__SB_DISK_DBLCLICK_LOCK__.time = now;

      if (entityType === 'folder') {
        if (entityId > 0) {
          await self.loadFolder(entityId);
        }

        return;
      }

      if (entityType === 'file') {
        self.openFileFromElement(item);
      }
    }, true);

    var refreshBtn = this.root.querySelector('[data-action="refresh"]');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        self.loadFolder(self.state.currentFolderId || self.state.rootFolderId);
      });
    }

    var createFolderBtn = this.root.querySelector('[data-action="create-folder"]');
    if (createFolderBtn) {
      createFolderBtn.addEventListener('click', async function () {
        if (!self.state.permissions.canCreateFolder) {
          return;
        }

        var name = window.prompt('Название папки');
        if (!name) {
          return;
        }

        var payload = self.getBasePayload();
        payload.currentFolderId = self.state.currentFolderId;
        payload.name = name;
        payload.sessid = self.getSessid();

        var res = await self.api('createFolder', payload);
        if (!res || !res.ok) {
          window.alert((res && (res.message || res.error)) || 'Ошибка создания папки');
          return;
        }

        await self.loadFolder(self.state.currentFolderId);
      });
    }

    var uploadBtn = this.root.querySelector('[data-action="upload"]');
    var uploadInput = this.root.querySelector('[data-role="upload-input"]');

    if (uploadBtn && uploadInput) {
      uploadBtn.addEventListener('click', function () {
        if (!self.state.permissions.canUpload) {
          return;
        }

        uploadInput.click();
      });

      uploadInput.addEventListener('change', async function (e) {
        var files = Array.prototype.slice.call(e.target.files || []);

        try {
            await self.uploadFiles(files);
        } finally {
            uploadInput.value = '';
        }
        });

    }

    var dragDepth = 0;

    function hasDraggedFiles(e) {
    var types = e.dataTransfer && e.dataTransfer.types;

    if (!types) {
        return false;
    }

    return Array.prototype.indexOf.call(types, 'Files') !== -1;
    }

    this.root.addEventListener('dragenter', function (e) {
    if (!hasDraggedFiles(e)) {
        return;
    }

    e.preventDefault();
    e.stopPropagation();

    dragDepth++;
    self.setDragOver(true);
    });

    this.root.addEventListener('dragover', function (e) {
    if (!hasDraggedFiles(e)) {
        return;
    }

    e.preventDefault();
    e.stopPropagation();

    if (e.dataTransfer) {
        e.dataTransfer.dropEffect = self.state.permissions.canUpload ? 'copy' : 'none';
    }

    self.setDragOver(true);
    });

    this.root.addEventListener('dragleave', function (e) {
    if (!hasDraggedFiles(e)) {
        return;
    }

    e.preventDefault();
    e.stopPropagation();

    dragDepth--;

    if (dragDepth <= 0) {
        dragDepth = 0;
        self.setDragOver(false);
    }
    });

    this.root.addEventListener('drop', async function (e) {
    if (!hasDraggedFiles(e)) {
        return;
    }

    e.preventDefault();
    e.stopPropagation();

    dragDepth = 0;
    self.setDragOver(false);

    var files = Array.prototype.slice.call(
        e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files : []
    );

    await self.uploadFiles(files);
    });


    var sortSelect = this.root.querySelector('[data-role="sort-select"]');
    if (sortSelect) {
      sortSelect.addEventListener('change', function () {
        self.loadFolder(self.state.currentFolderId || self.state.rootFolderId);
      });
    }

    var searchInput = this.root.querySelector('[data-role="search-input"]');
    if (searchInput) {
      var searchTimer = null;

      searchInput.addEventListener('input', function () {
        var value = String(searchInput.value || '').trim();

        clearTimeout(searchTimer);

        searchTimer = setTimeout(function () {
          self.state.searchQuery = value;

          if (value === '') {
            self.loadFolder(self.state.currentFolderId || self.state.rootFolderId);
            return;
          }

          self.search(value);
        }, 250);
      });
    }

    var selectAll = this.root.querySelector('[data-role="select-all"]');
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        var checked = !!selectAll.checked;
        var checkboxes = self.root.querySelectorAll('.sb-disk__item-check');

        self.state.selectedIds = [];

        checkboxes.forEach(function (checkbox) {
          checkbox.checked = checked;

          var id = Number(checkbox.getAttribute('data-id') || 0);
          if (checked && id > 0) {
            self.state.selectedIds.push(id);
          }
        });

        self.syncSelectedState();
      });
    }

    var viewButtons = this.root.querySelectorAll('.sb-disk__view-btn');
    viewButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var mode = btn.getAttribute('data-view') || 'table';
        self.setViewMode(mode);
      });
    });

    var settingsBtn = this.root.querySelector('[data-action="settings"]');
    if (settingsBtn) {
      settingsBtn.addEventListener('click', async function () {
        await self.openSettingsModal();
      });
    }

    var closeSettingsBtns = this.root.querySelectorAll('[data-action="close-settings"]');
    closeSettingsBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        self.closeSettingsModal();
      });
    });

    var saveSettingsBtn = this.root.querySelector('[data-action="save-settings"]');
    if (saveSettingsBtn) {
      saveSettingsBtn.addEventListener('click', async function () {
        await self.saveSettings();
      });
    }

    var folderAccessBtn = this.root.querySelector('[data-action="folder-access"]');
    if (folderAccessBtn) {
      folderAccessBtn.addEventListener('click', async function () {
        await self.openFolderAccessModal();
      });
    }

    this.root.querySelectorAll('[data-action="close-folder-access"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        self.closeFolderAccessModal();
      });
    });

    var folderAccessSearchBtn = this.root.querySelector('[data-action="search-folder-access-user"]');
    if (folderAccessSearchBtn) {
      folderAccessSearchBtn.addEventListener('click', async function () {
        await self.searchFolderAccessUsers();
      });
    }

    var folderAccessQuery = this.root.querySelector('[data-role="folder-access-query"]');
    if (folderAccessQuery) {
      folderAccessQuery.addEventListener('keydown', async function (event) {
        if (event.key === 'Enter') {
          event.preventDefault();
          await self.searchFolderAccessUsers();
        }
      });
    }

    var folderAccessSaveBtn = this.root.querySelector('[data-action="save-folder-access"]');
    if (folderAccessSaveBtn) {
      folderAccessSaveBtn.addEventListener('click', async function () {
        await self.saveFolderAccess();
      });
    }

    var initSiteRootBtn = this.root.querySelector('[data-action="init-site-root"]');
    if (initSiteRootBtn) {
      initSiteRootBtn.addEventListener('click', async function () {
        await self.initSiteRoot();
      });
    }

    var initBlockRootBtn = this.root.querySelector('[data-action="init-block-root"]');
    if (initBlockRootBtn) {
      initBlockRootBtn.addEventListener('click', async function () {
        await self.initBlockRoot();
      });
    }

    this.root.addEventListener('click', async function (e) {
      var crumb = e.target.closest('.sb-disk__crumb');
      if (crumb) {
        var crumbFolderId = Number(crumb.getAttribute('data-folder-id') || 0);

        if (crumbFolderId > 0) {
          await self.loadFolder(crumbFolderId);
        }

        return;
      }

      var openBtn = e.target.closest('[data-row-action="open"]');
      if (openBtn) {
        var row = e.target.closest('[data-id][data-entity-type]');
        if (!row) {
          return;
        }

        var entityType = row.getAttribute('data-entity-type');
        var entityId = Number(row.getAttribute('data-id') || 0);

        if (entityType === 'folder' && entityId > 0) {
          await self.loadFolder(entityId);
          return;
        }

        if (entityType === 'file') {
          var previewMode = row.getAttribute('data-preview-mode') || '';

          if (previewMode === 'office') {
            return;
          }

          var previewUrl = row.getAttribute('data-preview-url') || '';
          var downloadUrl = row.getAttribute('data-download-url') || '';

          if (previewUrl) {
            window.open(previewUrl, '_blank');
          } else if (downloadUrl) {
            window.open(downloadUrl, '_blank');
          }

          return;
        }

        return;
      }

      var historyBtn = e.target.closest('[data-row-action="history"]');
      if (historyBtn) {
        var historyRow = e.target.closest('[data-id][data-entity-type="file"]');

        if (!historyRow) {
          return;
        }

        var fileName = historyRow.getAttribute('data-name') || '';

        self.openHistoryModalForFile(fileName);
        return;
      }

      var downloadBtn = e.target.closest('[data-row-action="download"]');
      if (downloadBtn) {
        var downloadRow = e.target.closest('[data-id][data-entity-type="file"]');
        if (!downloadRow) {
          return;
        }

        var directDownloadUrl = downloadRow.getAttribute('data-download-url') || '';
        if (directDownloadUrl) {
          window.open(directDownloadUrl, '_blank');
        }

        return;
      }

      var unpackBtn = e.target.closest('[data-row-action="unpack"]');

        if (unpackBtn) {
        var unpackRow = e.target.closest('[data-id][data-entity-type="file"]');

        if (!unpackRow) {
            return;
        }

        var fileName = unpackRow.getAttribute('data-name') || 'архив';

        var confirmUnpack = window.confirm(
            'Распаковать архив "' + fileName + '"?\n\n' +
            'Файлы будут распакованы в текущую папку.'
        );

        if (!confirmUnpack) {
            return;
        }

        try {
            self.setLoading(true);

            await self.unpackArchiveFromRow(unpackRow);
        } catch (err) {
            console.error(err);

            self.finishUnpackStatusModal(
            false,
            err && err.message ? err.message : 'Ошибка распаковки',
            {}
            );
        } finally {
            self.setLoading(false);
        }

        return;
        }

      var renameBtn = e.target.closest('[data-row-action="rename"]');
      if (renameBtn) {
        var renameRow = e.target.closest('[data-id][data-entity-type]');
        if (!renameRow) {
          return;
        }

        var renameEntityType = renameRow.getAttribute('data-entity-type');
        var renameEntityId = Number(renameRow.getAttribute('data-id') || 0);
        var currentName = renameRow.getAttribute('data-name') || '';

        var newName = window.prompt('Новое название', currentName);
        if (!newName) {
          return;
        }

        var renamePayload = self.getBasePayload();
        renamePayload.entityType = renameEntityType;
        renamePayload.entityId = renameEntityId;
        renamePayload.newName = newName;
        renamePayload.sessid = self.getSessid();

        var renameRes = await self.api('rename', renamePayload);
        if (!renameRes || !renameRes.ok) {
          window.alert((renameRes && (renameRes.message || renameRes.error)) || 'Ошибка переименования');
          return;
        }

        await self.loadFolder(self.state.currentFolderId);
        return;
      }

      var deleteBtn = e.target.closest('[data-row-action="delete"]');
      if (deleteBtn) {
        var deleteRow = e.target.closest('[data-id][data-entity-type]');
        if (!deleteRow) {
          return;
        }

        var confirmDelete = window.confirm('Удалить элемент?');
        if (!confirmDelete) {
          return;
        }

        var deletePayload = self.getBasePayload();
        deletePayload.items = [{
          id: Number(deleteRow.getAttribute('data-id') || 0),
          entityType: deleteRow.getAttribute('data-entity-type')
        }];
        deletePayload.sessid = self.getSessid();

        var deleteRes = await self.api('delete', deletePayload);
        if (!deleteRes || !deleteRes.ok) {
          window.alert((deleteRes && (deleteRes.message || deleteRes.error)) || 'Ошибка удаления');
          return;
        }

        await self.loadFolder(self.state.currentFolderId);
        return;
      }

      var deleteSelectedBtn = e.target.closest('[data-action="delete-selected"]');
      if (deleteSelectedBtn) {
        if (!self.state.selectedIds.length) {
          return;
        }

        var confirmBulkDelete = window.confirm('Удалить выбранные элементы?');
        if (!confirmBulkDelete) {
          return;
        }

        var selectedItems = self.collectSelectedItemsPayload();
        if (!selectedItems.length) {
          return;
        }

        var bulkDeletePayload = self.getBasePayload();
        bulkDeletePayload.items = selectedItems;
        bulkDeletePayload.sessid = self.getSessid();

        var bulkDeleteRes = await self.api('delete', bulkDeletePayload);
        if (!bulkDeleteRes || !bulkDeleteRes.ok) {
          window.alert((bulkDeleteRes && (bulkDeleteRes.message || bulkDeleteRes.error)) || 'Ошибка удаления');
          return;
        }

        await self.loadFolder(self.state.currentFolderId);
        return;
      }

      var downloadSelectedBtn = e.target.closest('[data-action="download-selected"]');
      if (downloadSelectedBtn) {
        var rows = self.root.querySelectorAll('[data-id][data-entity-type="file"]');

        rows.forEach(function (row) {
          var id = Number(row.getAttribute('data-id') || 0);
          var downloadUrl = row.getAttribute('data-download-url') || '';

          if (self.state.selectedIds.indexOf(id) !== -1 && downloadUrl) {
            window.open(downloadUrl, '_blank');
          }
        });
      }
    });

    this.root.addEventListener('change', function (e) {
      var checkbox = e.target.closest('.sb-disk__item-check');
      if (!checkbox) {
        return;
      }

      var id = Number(checkbox.getAttribute('data-id') || 0);
      if (id <= 0) {
        return;
      }

      if (checkbox.checked) {
        if (self.state.selectedIds.indexOf(id) === -1) {
          self.state.selectedIds.push(id);
        }
      } else {
        self.state.selectedIds = self.state.selectedIds.filter(function (value) {
          return value !== id;
        });
      }

      self.syncSelectedState();
    });
  };

  DiskComponent.prototype.collectSelectedItemsPayload = function () {
    var rows = this.root.querySelectorAll('[data-id][data-entity-type]');
    var items = [];

    rows.forEach(function (row) {
      var id = Number(row.getAttribute('data-id') || 0);
      if (id <= 0) {
        return;
      }

      if (this.state.selectedIds.indexOf(id) !== -1) {
        items.push({
          id: id,
          entityType: row.getAttribute('data-entity-type')
        });
      }
    }, this);

    return items;
  };

  DiskComponent.prototype.syncSelectedState = function () {
    var rows = this.root.querySelectorAll('[data-id][data-entity-type]');

    rows.forEach(function (row) {
      var id = Number(row.getAttribute('data-id') || 0);
      var selected = this.state.selectedIds.indexOf(id) !== -1;
      row.classList.toggle('is-selected', selected);
    }, this);

    var cards = this.root.querySelectorAll('.sb-disk__card[data-id]');

    cards.forEach(function (card) {
      var id = Number(card.getAttribute('data-id') || 0);
      var selected = this.state.selectedIds.indexOf(id) !== -1;
      card.classList.toggle('is-selected', selected);
    }, this);

    var bulkbar = this.root.querySelector('[data-role="bulkbar"]');
    var bulkbarText = this.root.querySelector('[data-role="bulkbar-text"]');

    if (bulkbar && bulkbarText) {
      bulkbar.hidden = !this.state.selectedIds.length;
      bulkbarText.textContent = 'Выбрано: ' + this.state.selectedIds.length;
    }
  };

  DiskComponent.prototype.renderAll = function () {
    this.prepareModernUi();
    this.renderSubtitle();
    this.renderBreadcrumbs();
    this.renderItemsTable();
    this.renderItemsGrid();
    this.renderPagination();

    var selectAll = this.root.querySelector('[data-role="select-all"]');

    if (selectAll) {
      selectAll.checked = false;
      selectAll.indeterminate = false;
    }

    this.syncSelectedState();
    this.arrangeModernLayout();
  };

  DiskComponent.prototype.arrangeModernLayout = function () {
    var root = this.root;

    var breadcrumbs = root.querySelector('[data-role="breadcrumbs"]');
    var refreshBtn = root.querySelector('[data-action="refresh"]');
    var settingsBtn = root.querySelector('[data-action="settings"]');

    var searchInput = root.querySelector('[data-role="search-input"]');
    var sortSelect = root.querySelector('[data-role="sort-select"]');
    var uploadBtn = root.querySelector('[data-action="upload"]');
    var createFolderBtn = root.querySelector('[data-action="create-folder"]');

    var viewButtons = Array.prototype.slice.call(root.querySelectorAll('.sb-disk__view-btn'));

    var tableContainer = root.querySelector('[data-view-container="table"]');
    var gridContainer = root.querySelector('[data-view-container="grid"]');
    var bulkbar = root.querySelector('[data-role="bulkbar"]');

    var anchor = bulkbar || tableContainer || gridContainer || root.firstElementChild;

    if (!anchor) {
      return;
    }

    var header = root.querySelector('.sb-disk__smart-header');
    if (!header) {
      header = document.createElement('div');
      header.className = 'sb-disk__smart-header';

      var headerLeft = document.createElement('div');
      headerLeft.className = 'sb-disk__smart-header-left';

      var headerRight = document.createElement('div');
      headerRight.className = 'sb-disk__smart-header-right';

      header.appendChild(headerLeft);
      header.appendChild(headerRight);

      root.insertBefore(header, anchor);
    }

    var headerLeftNode = header.querySelector('.sb-disk__smart-header-left');
    var headerRightNode = header.querySelector('.sb-disk__smart-header-right');

    if (breadcrumbs) {
      headerLeftNode.appendChild(breadcrumbs);
    }

    if (refreshBtn) {
      headerRightNode.appendChild(refreshBtn);
    }

    if (settingsBtn) {
      headerRightNode.appendChild(settingsBtn);
    }

    var toolbar = root.querySelector('.sb-disk__smart-toolbar');
    if (!toolbar) {
      toolbar = document.createElement('div');
      toolbar.className = 'sb-disk__smart-toolbar';

      var toolbarLeft = document.createElement('div');
      toolbarLeft.className = 'sb-disk__smart-toolbar-left';

      var toolbarRight = document.createElement('div');
      toolbarRight.className = 'sb-disk__smart-toolbar-right';

      toolbar.appendChild(toolbarLeft);
      toolbar.appendChild(toolbarRight);

      if (header.nextSibling) {
        root.insertBefore(toolbar, header.nextSibling);
      } else {
        root.appendChild(toolbar);
      }
    }

    var toolbarLeftNode = toolbar.querySelector('.sb-disk__smart-toolbar-left');
    var toolbarRightNode = toolbar.querySelector('.sb-disk__smart-toolbar-right');

    if (searchInput) {
      toolbarLeftNode.appendChild(searchInput);
    }

    if (sortSelect) {
      toolbarLeftNode.appendChild(sortSelect);
    }

    if (uploadBtn) {
      toolbarRightNode.appendChild(uploadBtn);
    }

    if (createFolderBtn) {
      toolbarRightNode.appendChild(createFolderBtn);
    }

    viewButtons.forEach(function (btn) {
      toolbarRightNode.appendChild(btn);
    });
  };

  DiskComponent.prototype.renderSubtitle = function () {
    var node = this.root.querySelector('[data-role="subtitle"]');
    if (!node) {
      return;
    }

    var folders = 0;
    var files = 0;

    this.state.items.forEach(function (item) {
      if (this.isHistoryItem(item)) {
        return;
      }

      if (item.entityType === 'folder') {
        folders++;
      } else {
        files++;
      }
    }, this);

    node.textContent = files + ' файлов · ' + folders + ' папок';
  };

  DiskComponent.prototype.renderBreadcrumbs = function () {
    var container = this.root.querySelector('[data-role="breadcrumbs"]');
    if (!container) {
      return;
    }

    var crumbs = Array.isArray(this.state.breadcrumbs) ? this.state.breadcrumbs.slice() : [];

    if (this.state.rootFolderId) {
      var startIndex = crumbs.findIndex(function (item) {
        return Number(item.id || 0) === Number(this.state.rootFolderId || 0);
      }, this);

      if (startIndex >= 0) {
        crumbs = crumbs.slice(startIndex);
      }
    }

    if (crumbs.length) {
      crumbs[0] = {
        id: crumbs[0].id,
        name: this.state.settings && this.state.settings.title ? this.state.settings.title : 'Файлы'
      };
    }

    container.innerHTML = crumbs.map(function (item) {
      return '<button type="button" class="sb-disk__crumb" data-folder-id="' + escapeHtml(item.id) + '">' +
        escapeHtml(item.name) +
      '</button>';
    }).join('<span class="sb-disk__crumb-separator">/</span>');
  };

  DiskComponent.prototype.renderItemsTable = function () {
    var tbody = this.root.querySelector('[data-role="items-table"]');
    if (!tbody) {
        return;
    }

    var self = this;

    tbody.innerHTML = this.getPagedDisplayItems().map(function (item) {
        var typeText = getItemTypeText(item);
        var addedByText = getItemAddedByText(item);
        var sizeText = item.entityType === 'folder' ? '—' : (item.size ? formatBytes(item.size) : '—');
        var iconHtml = renderItemIcon(item);
        var openControl = renderOpenControl(item);
        var historyControl = self.renderHistoryControl(item);
        var permissions = self.state.permissions || {};

        return '' +
        '<tr class="sb-disk__row ' + (item.entityType === 'folder' ? 'is-clickable' : '') + '" ' +
            'data-id="' + escapeHtml(item.id) + '" ' +
            'data-entity-type="' + escapeHtml(item.entityType) + '" ' +
            'data-name="' + escapeHtml(item.name) + '" ' +
            'data-download-url="' + escapeHtml(item.downloadUrl || '') + '" ' +
            'data-preview-url="' + escapeHtml(item.previewUrl || '') + '" ' +
            'data-preview-mode="' + escapeHtml(item.previewMode || '') + '">' +
            '<td class="sb-disk__check-cell">' +
                '<input type="checkbox" class="sb-disk__item-check" data-id="' + escapeHtml(item.id) + '">' +
            '</td>' +
            '<td class="sb-disk__name-cell">' +
                '<div class="sb-disk__modern-name">' +
                iconHtml +
                '<div class="sb-disk__modern-name-main">' +
                    '<div class="sb-disk__modern-name-title">' + escapeHtml(item.name) + '</div>' +
                    '<div class="sb-disk__modern-name-sub">' + escapeHtml(typeText) + '</div>' +
                '</div>' +
                '</div>' +
            '</td>' +
            '<td><span class="sb-disk__added-by">' + escapeHtml(addedByText) + '</span></td>' +
            '<td>' + escapeHtml(sizeText) + '</td>' +
            '<td>' + escapeHtml(item.updatedAt || '—') + '</td>' +
            '<td>' +
                '<div class="sb-disk__actions">' +
                openControl +
                historyControl +
                (item.entityType === 'file' && permissions.canDownload
                    ? '<button type="button" class="sb-disk__row-btn" data-row-action="download">Скачать</button>'
                    : '') +
                (isArchiveItem(item) && permissions.canUpload
                    ? '<button type="button" class="sb-disk__row-btn" data-row-action="unpack">Распаковать</button>'
                    : '') +
                (permissions.canRename ? '<button type="button" class="sb-disk__row-btn" data-row-action="rename">Переим.</button>' : '') +
                (permissions.canDelete ? '<button type="button" class="sb-disk__row-btn is-danger" data-row-action="delete">Удалить</button>' : '') +
                '</div>' +
            '</td>' +
        '</tr>';
    }).join('');
    };

    DiskComponent.prototype.renderItemsGrid = function () {
        var container = this.root.querySelector('[data-view-container="grid"]');
        if (!container) {
            return;
        }

        container.classList.add('sb-disk__grid');

        var self = this;

        container.innerHTML = this.getPagedDisplayItems().map(function (item) {
            var typeText = getItemTypeText(item);
            var addedByText = getItemAddedByText(item);
            var sizeText = item.entityType === 'folder' ? 'Папка' : (item.size ? formatBytes(item.size) : '—');
            var openControl = renderOpenControl(item);
            var historyControl = self.renderHistoryControl(item);
            var permissions = self.state.permissions || {};

            return '' +
            '<div class="sb-disk__card ' + (item.entityType === 'folder' ? 'is-clickable' : '') + '" ' +
                'data-id="' + escapeHtml(item.id) + '" ' +
                'data-entity-type="' + escapeHtml(item.entityType) + '" ' +
                'data-name="' + escapeHtml(item.name) + '" ' +
                'data-download-url="' + escapeHtml(item.downloadUrl || '') + '" ' +
                'data-preview-url="' + escapeHtml(item.previewUrl || '') + '" ' +
                'data-preview-mode="' + escapeHtml(item.previewMode || '') + '">' +
                '<div class="sb-disk__card-top">' +
                    '<label class="sb-disk__card-check">' +
                    '<input type="checkbox" class="sb-disk__item-check" data-id="' + escapeHtml(item.id) + '">' +
                    '</label>' +
                    '<span class="sb-disk__type-pill">' + escapeHtml(typeText) + '</span>' +
                '</div>' +
                '<div class="sb-disk__card-preview">' +
                    renderItemIcon(item) +
                '</div>' +
                '<div class="sb-disk__card-name">' + escapeHtml(item.name) + '</div>' +
                '<div class="sb-disk__card-meta">' +
                    '<span class="sb-disk__card-sub">' + escapeHtml(sizeText) + '</span>' +
                '</div>' +
                '<div class="sb-disk__card-meta">' +
                    '<span class="sb-disk__card-sub">Добавил: ' + escapeHtml(addedByText) + '</span>' +
                '</div>' +
                '<div class="sb-disk__card-meta">' +
                    '<span class="sb-disk__card-sub">' + escapeHtml(item.updatedAt || '') + '</span>' +
                '</div>' +
                '<div class="sb-disk__card-actions">' +
                    openControl +
                    historyControl +
                    (item.entityType === 'file' && permissions.canDownload
                    ? '<button type="button" class="sb-disk__row-btn" data-row-action="download">Скачать</button>'
                    : '') +
                    (isArchiveItem(item) && permissions.canUpload
                    ? '<button type="button" class="sb-disk__row-btn" data-row-action="unpack">Распаковать</button>'
                    : '') +
                    (permissions.canRename ? '<button type="button" class="sb-disk__row-btn" data-row-action="rename">Переим.</button>' : '') +
                    (permissions.canDelete ? '<button type="button" class="sb-disk__row-btn is-danger" data-row-action="delete">Удалить</button>' : '') +
                '</div>' +
            '</div>';
        }).join('');
        };


  DiskComponent.prototype.setLoading = function (loading) {
    this.state.loading = !!loading;

    var loadingNode = this.root.querySelector('[data-state="loading"]');
    if (loadingNode) {
      loadingNode.hidden = !loading;
    }
  };

  DiskComponent.prototype.renderState = function (stateName) {
    var nodes = this.root.querySelectorAll('[data-state]');

    nodes.forEach(function (node) {
      node.hidden = true;

      if (node.getAttribute('data-state') === 'empty') {
        node.classList.add('sb-disk-empty-enhanced');

        if (!node.getAttribute('data-modern-empty-ready')) {
          node.setAttribute('data-modern-empty-ready', '1');
          node.innerHTML = '' +
            '<div class="sb-disk-empty-icon">📁</div>' +
            '<strong>Пока здесь пусто</strong>' +
            '<span>Загрузите первый файл или создайте новую папку.</span>';
        }
      }
    });

    if (!stateName) {
      return;
    }

    var node = this.root.querySelector('[data-state="' + stateName + '"]');
    if (node) {
      node.hidden = false;
    }
  };

  /* =========================================================
     FOLDER ACCESS
     ========================================================= */

  DiskComponent.prototype.openFolderAccessModal = async function () {
    var modal = this.root.querySelector('[data-role="folder-access-modal"]');
    if (!modal || !this.state.permissions.canManageAccess || !this.state.currentFolderId) {
      return;
    }

    modal.hidden = false;
    this.state.selectedFolderAccessUser = null;

    var editor = this.root.querySelector('[data-role="folder-access-editor"]');
    if (editor) {
      editor.hidden = true;
    }

    var warning = this.root.querySelector('[data-role="folder-access-warning"]');
    if (warning) {
      warning.hidden = (this.state.settings.permissionMode || 'inherit_site') === 'custom';
    }

    var currentCrumb = this.state.breadcrumbs.length
      ? this.state.breadcrumbs[this.state.breadcrumbs.length - 1]
      : null;
    var folderNode = this.root.querySelector('[data-role="folder-access-folder"]');
    if (folderNode) {
      folderNode.textContent = (currentCrumb && currentCrumb.name ? currentCrumb.name + ' · ' : '')
        + 'ID ' + Number(this.state.currentFolderId || 0);
    }

    await this.loadFolderAccess();
  };

  DiskComponent.prototype.closeFolderAccessModal = function () {
    var modal = this.root.querySelector('[data-role="folder-access-modal"]');
    if (modal) {
      modal.hidden = true;
    }
  };

  DiskComponent.prototype.setFolderAccessMessage = function (message) {
    var node = this.root.querySelector('[data-role="folder-access-message"]');
    if (node) {
      node.textContent = message || '';
    }
  };

  DiskComponent.prototype.loadFolderAccess = async function () {
    try {
      this.setFolderAccessMessage('Загрузка прав...');
      var payload = this.getBasePayload();
      payload.folderId = Number(this.state.currentFolderId || 0);
      payload.sessid = this.getSessid();

      var res = await this.api('folderAccessList', payload);
      if (!res || !res.ok) {
        throw new Error((res && (res.message || res.error)) || 'FOLDER_ACCESS_LIST_ERROR');
      }

      this.state.folderAccessItems = Array.isArray(res.data.items) ? res.data.items : [];
      this.renderFolderAccessList();
      this.setFolderAccessMessage('');
    } catch (e) {
      console.error(e);
      this.setFolderAccessMessage('Не удалось загрузить права папки.');
    }
  };

  DiskComponent.prototype.renderFolderAccessList = function () {
    var container = this.root.querySelector('[data-role="folder-access-list"]');
    if (!container) {
      return;
    }

    var self = this;
    var roleLabels = {
      VIEWER: 'Просмотр',
      EDITOR: 'Редактирование',
      DENY: 'Нет доступа'
    };

    if (!this.state.folderAccessItems.length) {
      container.innerHTML = '<div class="sb-disk-folder-access__empty">Для этой папки нет собственных правил — используются права родительской папки или сайта.</div>';
      return;
    }

    container.innerHTML = this.state.folderAccessItems.map(function (item) {
      return '<div class="sb-disk-folder-access__item" data-folder-access-user-id="' + escapeHtml(item.userId) + '">' +
        '<div><strong>' + escapeHtml(item.userName || ('ID ' + item.userId)) + '</strong>' +
        '<span>' + escapeHtml(roleLabels[item.role] || item.role) + '</span></div>' +
        '<div class="sb-disk-folder-access__actions">' +
        '<button type="button" class="sb-disk__btn sb-disk__btn--ghost" data-action="edit-folder-access">Изменить</button>' +
        '<button type="button" class="sb-disk__btn sb-disk__btn--ghost" data-action="delete-folder-access">Наследовать</button>' +
        '</div></div>';
    }).join('');

    container.querySelectorAll('[data-action="edit-folder-access"]').forEach(function (button) {
      button.addEventListener('click', function () {
        var row = button.closest('[data-folder-access-user-id]');
        var userId = Number(row ? row.getAttribute('data-folder-access-user-id') : 0);
        var item = self.state.folderAccessItems.find(function (current) {
          return Number(current.userId || 0) === userId;
        });
        if (item) {
          self.selectFolderAccessUser({id: item.userId, name: item.userName}, item.role);
        }
      });
    });

    container.querySelectorAll('[data-action="delete-folder-access"]').forEach(function (button) {
      button.addEventListener('click', async function () {
        var row = button.closest('[data-folder-access-user-id]');
        var userId = Number(row ? row.getAttribute('data-folder-access-user-id') : 0);
        if (userId > 0) {
          await self.deleteFolderAccess(userId);
        }
      });
    });
  };

  DiskComponent.prototype.searchFolderAccessUsers = async function () {
    var queryNode = this.root.querySelector('[data-role="folder-access-query"]');
    var query = queryNode ? String(queryNode.value || '').trim() : '';

    if (!query || (!/^\d+$/.test(query) && query.length < 2)) {
      this.setFolderAccessMessage('Введите не менее двух символов.');
      return;
    }

    try {
      this.setFolderAccessMessage('Поиск...');
      var payload = this.getBasePayload();
      payload.folderId = Number(this.state.currentFolderId || 0);
      payload.query = query;
      payload.sessid = this.getSessid();

      var res = await this.api('userSearch', payload);
      if (!res || !res.ok) {
        throw new Error((res && (res.message || res.error)) || 'USER_SEARCH_ERROR');
      }

      this.state.folderAccessUsers = Array.isArray(res.data.users) ? res.data.users : [];
      this.renderFolderAccessSearchResults();
      this.setFolderAccessMessage(this.state.folderAccessUsers.length ? '' : 'Пользователи не найдены.');
    } catch (e) {
      console.error(e);
      this.setFolderAccessMessage('Не удалось выполнить поиск.');
    }
  };

  DiskComponent.prototype.renderFolderAccessSearchResults = function () {
    var container = this.root.querySelector('[data-role="folder-access-results"]');
    if (!container) {
      return;
    }

    var self = this;
    container.innerHTML = this.state.folderAccessUsers.map(function (user) {
      var meta = [user.login, user.email].filter(Boolean).join(' · ');
      return '<button type="button" class="sb-disk-folder-access__user" data-user-id="' + escapeHtml(user.id) + '">' +
        '<strong>' + escapeHtml(user.name || ('ID ' + user.id)) + '</strong>' +
        (meta ? '<span>' + escapeHtml(meta) + '</span>' : '') +
        '</button>';
    }).join('');

    container.querySelectorAll('[data-user-id]').forEach(function (button) {
      button.addEventListener('click', function () {
        var userId = Number(button.getAttribute('data-user-id') || 0);
        var user = self.state.folderAccessUsers.find(function (current) {
          return Number(current.id || 0) === userId;
        });
        if (user) {
          self.selectFolderAccessUser(user, 'VIEWER');
        }
      });
    });
  };

  DiskComponent.prototype.selectFolderAccessUser = function (user, role) {
    this.state.selectedFolderAccessUser = user || null;
    var editor = this.root.querySelector('[data-role="folder-access-editor"]');
    var label = this.root.querySelector('[data-role="folder-access-selected-user"]');
    var select = this.root.querySelector('[data-role="folder-access-role"]');

    if (editor) {
      editor.hidden = !user;
    }
    if (label) {
      label.textContent = user ? (user.name || ('ID ' + user.id)) : '';
    }
    if (select) {
      select.value = role || 'VIEWER';
    }
  };

  DiskComponent.prototype.saveFolderAccess = async function () {
    var user = this.state.selectedFolderAccessUser;
    var roleNode = this.root.querySelector('[data-role="folder-access-role"]');
    if (!user || !roleNode) {
      return;
    }

    try {
      this.setFolderAccessMessage('Сохранение...');
      var payload = this.getBasePayload();
      payload.folderId = Number(this.state.currentFolderId || 0);
      payload.userId = Number(user.id || 0);
      payload.role = String(roleNode.value || 'VIEWER');
      payload.sessid = this.getSessid();

      var res = await this.api('folderAccessSet', payload);
      if (!res || !res.ok) {
        throw new Error((res && (res.message || res.error)) || 'FOLDER_ACCESS_SAVE_ERROR');
      }

      this.selectFolderAccessUser(null, 'VIEWER');
      await this.loadFolderAccess();
      this.setFolderAccessMessage('Права сохранены.');
    } catch (e) {
      console.error(e);
      this.setFolderAccessMessage('Не удалось сохранить права.');
    }
  };

  DiskComponent.prototype.deleteFolderAccess = async function (userId) {
    try {
      var payload = this.getBasePayload();
      payload.folderId = Number(this.state.currentFolderId || 0);
      payload.userId = Number(userId || 0);
      payload.sessid = this.getSessid();

      var res = await this.api('folderAccessDelete', payload);
      if (!res || !res.ok) {
        throw new Error((res && (res.message || res.error)) || 'FOLDER_ACCESS_DELETE_ERROR');
      }

      await this.loadFolderAccess();
      this.setFolderAccessMessage('Собственное правило удалено; снова действует наследование.');
    } catch (e) {
      console.error(e);
      this.setFolderAccessMessage('Не удалось удалить правило.');
    }
  };

  /* =========================================================
     SETTINGS
     ========================================================= */

  DiskComponent.prototype.openSettingsModal = async function () {
    var modal = this.root.querySelector('[data-role="settings-modal"]');
    if (!modal) {
      return;
    }

    modal.hidden = false;
    this.setSettingsMessage('Загрузка настроек...');

    try {
      var settingsPayload = this.getBasePayload();
      settingsPayload.sessid = this.getSessid();

      var settingsRes = await this.api('getSettings', settingsPayload);
      if (!settingsRes || !settingsRes.ok) {
        throw new Error((settingsRes && (settingsRes.message || settingsRes.error)) || 'GET_SETTINGS_ERROR');
      }

      var rootsPayload = this.getBasePayload();
      rootsPayload.sessid = this.getSessid();

      var rootOptionsRes = await this.api('getRootOptions', rootsPayload);
      if (!rootOptionsRes || !rootOptionsRes.ok) {
        throw new Error((rootOptionsRes && (rootOptionsRes.message || rootOptionsRes.error)) || 'GET_ROOT_OPTIONS_ERROR');
      }

      this.state.blockVersion = Number(settingsRes.data.blockVersion || this.state.blockVersion || 1);

      this.fillSettingsForm(
        settingsRes.data.settings || {},
        rootOptionsRes.data || {}
      );

      this.arrangeSettingsModal();

      this.setSettingsMessage('');
    } catch (e) {
      console.error(e);
      this.setSettingsMessage('Не удалось загрузить настройки.');
    }
  };

  DiskComponent.prototype.arrangeSettingsModal = function () {
    var modal = this.root.querySelector('[data-role="settings-modal"]');
    var form = this.root.querySelector('[data-role="settings-form"]');

    if (!modal || !form) {
      return;
    }

    modal.classList.add('sb-disk-settings-modal');

    var shell = modal.firstElementChild;
    if (shell) {
      shell.classList.add('sb-disk-settings-shell');
    }

    if (!form.querySelector('.sb-disk-settings-section-main')) {
      var mainTitle = document.createElement('div');
      mainTitle.className = 'sb-disk-settings-section-main';
      mainTitle.textContent = 'Основные настройки';
      form.insertBefore(mainTitle, form.firstChild);
    }

    var checkboxLabels = Array.prototype.slice.call(
      form.querySelectorAll('label')
    ).filter(function (label) {
      return !!label.querySelector('input[type="checkbox"]');
    });

    if (checkboxLabels.length && !form.querySelector('.sb-disk-settings-checks')) {
      var checksTitle = document.createElement('div');
      checksTitle.className = 'sb-disk-settings-section-title';
      checksTitle.textContent = 'Возможности';

      var checksWrap = document.createElement('div');
      checksWrap.className = 'sb-disk-settings-checks';

      checkboxLabels.forEach(function (label) {
        checksWrap.appendChild(label);
      });

      form.appendChild(checksTitle);
      form.appendChild(checksWrap);
    }

    var actionButtons = Array.prototype.slice.call(
      modal.querySelectorAll('[data-action="save-settings"], [data-action="close-settings"]')
    ).filter(function (button) {
      var text = String(button.textContent || '').trim().toLowerCase();
      return text !== '×' && text !== 'x';
    });

    if (actionButtons.length && !modal.querySelector('.sb-disk-settings-footer')) {
      var footer = document.createElement('div');
      footer.className = 'sb-disk-settings-footer';

      actionButtons.forEach(function (button) {
        footer.appendChild(button);
      });

      if (shell) {
        shell.appendChild(footer);
      } else {
        modal.appendChild(footer);
      }
    }
  };

  DiskComponent.prototype.closeSettingsModal = function () {
    var modal = this.root.querySelector('[data-role="settings-modal"]');
    if (!modal) {
      return;
    }

    modal.hidden = true;
  };

  DiskComponent.prototype.setSettingsMessage = function (message) {
    var node = this.root.querySelector('[data-role="settings-message"]');
    if (!node) {
      return;
    }

    node.textContent = message || '';
  };

  DiskComponent.prototype.fillSettingsForm = function (settings, rootData) {
    var form = this.root.querySelector('[data-role="settings-form"]');
    if (!form) {
      return;
    }

    var rootSelect = form.querySelector('[data-role="root-select"]');
    if (rootSelect) {
      var options = Array.isArray(rootData.options) ? rootData.options : [];
      rootSelect.innerHTML = '';

      if (rootData.siteRootFolderId) {
        rootSelect.insertAdjacentHTML('beforeend', '<option value="">Использовать корень сайта</option>');
      } else {
        rootSelect.insertAdjacentHTML('beforeend', '<option value="">Корень сайта не создан</option>');
      }

      options.forEach(function (option) {
        if (option.type === 'block_root' && option.folderId) {
          rootSelect.insertAdjacentHTML(
            'beforeend',
            '<option value="' + escapeHtml(option.folderId) + '">Собственная папка блока #' + escapeHtml(option.folderId) + '</option>'
          );
        }
      });
    }

    setFormValue(form, 'title', settings.title || 'Файлы');
    setFormValue(form, 'rootFolderId', settings.rootFolderId || '');
    setFormValue(form, 'viewMode', settings.viewMode || 'table');
    setFormValue(form, 'defaultSort', settings.defaultSort || 'updatedAt');
    setFormValue(form, 'defaultSortDirection', settings.defaultSortDirection || 'desc');
    setFormValue(form, 'maxFileSize', settings.maxFileSize || 52428800);
    setFormValue(form, 'permissionMode', settings.permissionMode || 'inherit_site');

    var extValue = Array.isArray(settings.allowedExtensions)
      ? settings.allowedExtensions.join(' ')
      : '';

    setFormValue(form, 'allowedExtensions', extValue);

    setFormCheckbox(form, 'allowUpload', !!settings.allowUpload);
    setFormCheckbox(form, 'allowCreateFolder', !!settings.allowCreateFolder);
    setFormCheckbox(form, 'allowRename', !!settings.allowRename);
    setFormCheckbox(form, 'allowDelete', !!settings.allowDelete);
    setFormCheckbox(form, 'allowDownload', !!settings.allowDownload);
    setFormCheckbox(form, 'showSearch', !!settings.showSearch);
    setFormCheckbox(form, 'showBreadcrumbs', !!settings.showBreadcrumbs);
    setFormCheckbox(form, 'useSiteRootFallback', !!settings.useSiteRootFallback);
  };

  DiskComponent.prototype.collectSettingsForm = function () {
    var form = this.root.querySelector('[data-role="settings-form"]');
    if (!form) {
      return {};
    }

    return {
      title: getFormValue(form, 'title'),
      rootFolderId: getFormValue(form, 'rootFolderId'),
      viewMode: getFormValue(form, 'viewMode'),
      defaultSort: getFormValue(form, 'defaultSort'),
      defaultSortDirection: getFormValue(form, 'defaultSortDirection'),
      maxFileSize: Number(getFormValue(form, 'maxFileSize') || 0),
      allowedExtensions: String(getFormValue(form, 'allowedExtensions') || '')
        .trim()
        .split(/\s+/)
        .filter(Boolean),
      permissionMode: getFormValue(form, 'permissionMode'),
      allowUpload: getFormCheckbox(form, 'allowUpload'),
      allowCreateFolder: getFormCheckbox(form, 'allowCreateFolder'),
      allowRename: getFormCheckbox(form, 'allowRename'),
      allowDelete: getFormCheckbox(form, 'allowDelete'),
      allowDownload: getFormCheckbox(form, 'allowDownload'),
      showSearch: getFormCheckbox(form, 'showSearch'),
      showBreadcrumbs: getFormCheckbox(form, 'showBreadcrumbs'),
      useSiteRootFallback: getFormCheckbox(form, 'useSiteRootFallback')
    };
  };

  DiskComponent.prototype.saveSettings = async function () {
    try {
      this.setSettingsMessage('Сохранение...');

      var payload = this.getBasePayload();
      payload.sessid = this.getSessid();
      payload.expectedVersion = Number(this.state.blockVersion || 1);
      payload.settings = this.collectSettingsForm();

      var res = await this.api('saveSettings', payload);
      if (!res || !res.ok) {
        throw new Error((res && (res.message || res.error)) || 'SAVE_SETTINGS_ERROR');
      }

      this.state.settings = res.data.settings || this.state.settings;
      this.state.blockVersion = Number(res.data.blockVersion || this.state.blockVersion || 1);
      this.state.viewMode = this.state.settings.viewMode || 'table';

      this.applyInitialViewMode();

      this.setSettingsMessage('Настройки сохранены.');
      this.closeSettingsModal();

      await this.loadResolvedRoot();
    } catch (e) {
      console.error(e);
      this.setSettingsMessage('Не удалось сохранить настройки.');
    }
  };

  DiskComponent.prototype.initSiteRoot = async function () {
    try {
      var payload = {
        siteId: this.state.siteId,
        sessid: this.getSessid()
      };

      var res = await this.api('initSiteRoot', payload);
      if (!res || !res.ok) {
        throw new Error((res && (res.message || res.error)) || 'INIT_SITE_ROOT_ERROR');
      }

      await this.loadResolvedRoot();
    } catch (e) {
      console.error(e);
      alert('Не удалось создать корень сайта');
    }
  };

  DiskComponent.prototype.initBlockRoot = async function () {
    try {
      var payload = this.getBasePayload();
      payload.sessid = this.getSessid();

      var res = await this.api('initBlockRoot', payload);
      if (!res || !res.ok) {
        throw new Error((res && (res.message || res.error)) || 'INIT_BLOCK_ROOT_ERROR');
      }

      await this.loadResolvedRoot();
    } catch (e) {
      console.error(e);
      alert('Не удалось создать папку блока');
    }
  };

  DiskComponent.prototype.loadResolvedRoot = async function () {
    var payload = this.getBasePayload();
    payload.sessid = this.getSessid();

    var rootRes = await this.api('resolveRoot', payload);
    if (!rootRes || !rootRes.ok) {
      throw new Error((rootRes && (rootRes.message || rootRes.error)) || 'RESOLVE_ROOT_ERROR');
    }

    var data = getApiData(rootRes);

    this.state.rootFolderId = data.rootFolderId || null;
    this.state.currentFolderId = this.state.rootFolderId || null;

    if (this.state.rootFolderId) {
      await this.loadFolder(this.state.rootFolderId);
    } else {
      this.renderState('no-root');
    }
  };

  /* =========================================================
     HELPERS
     ========================================================= */

     function getItemAddedByText(item) {
        if (!item) {
            return '—';
        }

        var value =
            item.createdByName ||
            item.createdByTitle ||
            item.createdByFullName ||
            item.authorName ||
            item.userName ||
            item.createdBy ||
            item.author ||
            '';

        value = String(value || '').trim();

        if (value) {
            return value;
        }

        var id =
            item.createdById ||
            item.authorId ||
            item.userId ||
            0;

        id = Number(id || 0);

        if (id > 0) {
            return 'ID ' + id;
        }

        return '—';
        }

  function getItemExtension(item) {
    var ext = String(item.extension || '').trim().toLowerCase();

    if (ext) {
      return ext.replace(/^\./, '');
    }

    var name = String(item.name || '');
    var parts = name.split('.');

    if (parts.length < 2) {
      return '';
    }

    return String(parts.pop() || '').toLowerCase();
  }

  function getItemTypeText(item) {
    if (item.entityType === 'folder') {
      return 'Папка';
    }

    var ext = getItemExtension(item);

    return ext ? ext.toUpperCase() : 'Файл';
  }

  function getItemIconText(item) {
    if (item.entityType === 'folder') {
      return '📁';
    }

    var ext = getItemExtension(item);

    if (ext === 'pdf') return 'PDF';
    if (['doc', 'docx', 'rtf'].indexOf(ext) !== -1) return 'DOC';
    if (['xls', 'xlsx', 'csv'].indexOf(ext) !== -1) return 'XLS';
    if (['ppt', 'pptx'].indexOf(ext) !== -1) return 'PPT';
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].indexOf(ext) !== -1) return 'IMG';
    if (['zip', 'rar', '7z'].indexOf(ext) !== -1) return 'ZIP';
    if (['txt', 'log'].indexOf(ext) !== -1) return 'TXT';

    return 'FILE';
  }

  function getItemIconClass(item) {
    if (item.entityType === 'folder') {
      return 'sb-disk__modern-icon sb-disk__modern-icon--folder';
    }

    var ext = getItemExtension(item);

    if (ext === 'pdf') return 'sb-disk__modern-icon sb-disk__modern-icon--pdf';
    if (['doc', 'docx', 'rtf'].indexOf(ext) !== -1) return 'sb-disk__modern-icon sb-disk__modern-icon--doc';
    if (['xls', 'xlsx', 'csv'].indexOf(ext) !== -1) return 'sb-disk__modern-icon sb-disk__modern-icon--xls';
    if (['ppt', 'pptx'].indexOf(ext) !== -1) return 'sb-disk__modern-icon sb-disk__modern-icon--ppt';
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].indexOf(ext) !== -1) return 'sb-disk__modern-icon sb-disk__modern-icon--img';
    if (['zip', 'rar', '7z'].indexOf(ext) !== -1) return 'sb-disk__modern-icon sb-disk__modern-icon--zip';

    return 'sb-disk__modern-icon sb-disk__modern-icon--file';
  }

  function renderItemIcon(item) {
    return '<span class="' + escapeHtml(getItemIconClass(item)) + '">' + escapeHtml(getItemIconText(item)) + '</span>';
  }

  function renderOpenControl(item) {
    if (item.entityType === 'folder') {
      return '';
    }

    if (item.previewMode === 'office') {
      return '' +
        '<span ' +
          'class="sb-disk__hidden-viewer disk-detail-sidebar-editor-item disk-detail-sidebar-editor-item-show" ' +
          'data-viewer="" ' +
          'data-row-action="open" ' +
          'data-viewer-type="cloud-document" ' +
          'data-src="' + escapeHtml(item.previewUrl || '') + '" ' +
          'data-viewer-type-class="BX.Disk.Viewer.DocumentItem" ' +
          'data-viewer-extension="disk.viewer.document-item" ' +
          'data-object-id="' + escapeHtml(item.id) + '" ' +
          'data-title="' + escapeHtml(item.name) + '" ' +
          'data-actions="' + escapeHtml(JSON.stringify([{ type: 'download' }])) + '"' +
        '></span>';
    }

    return '';
  }

  function isArchiveItem(item) {
    if (!item || item.entityType !== 'file') {
        return false;
    }

    var extension = String(item.extension || '').toLowerCase();
    var name = String(item.name || '').toLowerCase();

    return extension === 'zip' || name.slice(-4) === '.zip';
    }

  function formatBytes(bytes) {
    bytes = Number(bytes || 0);
    if (bytes <= 0) {
      return '0 Б';
    }

    var units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    var unitIndex = 0;

    while (bytes >= 1024 && unitIndex < units.length - 1) {
      bytes /= 1024;
      unitIndex++;
    }

    var value = unitIndex === 0 ? Math.round(bytes) : bytes.toFixed(1);

    return String(value) + ' ' + units[unitIndex];
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setFormValue(form, name, value) {
    var node = form.querySelector('[name="' + name + '"]');
    if (!node) {
      return;
    }

    node.value = value == null ? '' : value;
  }

  function getFormValue(form, name) {
    var node = form.querySelector('[name="' + name + '"]');
    return node ? String(node.value || '') : '';
  }

  function setFormCheckbox(form, name, checked) {
    var node = form.querySelector('[name="' + name + '"]');
    if (!node) {
      return;
    }

    node.checked = !!checked;
  }

  function getFormCheckbox(form, name) {
    var node = form.querySelector('[name="' + name + '"]');
    return !!(node && node.checked);
  }

  function getApiData(res) {
    return res && res.data ? res.data : {};
  }

  function initDisks() {
    document.querySelectorAll('.sb-disk').forEach(function (root) {
      if (root.getAttribute('data-disk-component-ready') === '1') {
        return;
      }

      root.setAttribute('data-disk-component-ready', '1');

      var component = new DiskComponent(root);
      root.__diskComponent = component;
      component.init();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDisks);
  } else {
    initDisks();
  }


})();
