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
      rootFolderId: parsed.rootFolderId || null,
      currentFolderId: parsed.currentFolderId || null,
      settings: parsed.settings || {},
      permissions: parsed.permissions || {},
      breadcrumbs: [],
      items: [],
      selectedIds: [],
      searchQuery: '',
      viewMode: (parsed.settings && parsed.settings.viewMode) || 'table',
      loading: false,
      error: null
    };
  };

  DiskComponent.prototype.init = async function () {
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
      this.state.permissions = data.permissions || {};
      this.state.rootFolderId = data.rootFolderId || null;
      this.state.currentFolderId = data.currentFolderId || null;
      this.state.viewMode = (this.state.settings && this.state.settings.viewMode) || 'table';

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
    if (isFormData) {
      var responseForm = await fetch('/local/sitebuilder/components/disk/api.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        body: payload
      });

      return await responseForm.json();
    }

    var response = await fetch('/local/sitebuilder/components/disk/api.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    return await response.json();
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
      this.state.items = Array.isArray(res.data.items) ? res.data.items : [];
      this.state.breadcrumbs = Array.isArray(res.data.breadcrumbs) ? res.data.breadcrumbs : [];
      this.state.selectedIds = [];

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

  DiskComponent.prototype.bindStaticEvents = function () {
    var self = this;

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
        if (!files.length) {
          return;
        }

        var formData = new FormData();
        formData.append('siteId', self.state.siteId);
        formData.append('pageId', self.state.pageId);
        formData.append('blockId', self.state.blockId);
        formData.append('currentFolderId', self.state.currentFolderId);
        formData.append('sessid', self.getSessid());

        files.forEach(function (file) {
          formData.append('files[]', file);
        });

        var res = await self.api('upload', formData, true);
        if (!res || !res.ok) {
          window.alert((res && (res.message || res.error)) || 'Ошибка загрузки');
          return;
        }

        uploadInput.value = '';
        await self.loadFolder(self.state.currentFolderId);
      });
    }

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
      var clickableRow = e.target.closest('.sb-disk__row[data-id][data-entity-type="folder"], .sb-disk__card[data-id][data-entity-type="folder"]');
      if (clickableRow && !e.target.closest('button, input, label, a, span[data-viewer]')) {
        var clickFolderId = Number(clickableRow.getAttribute('data-id') || 0);

        if (clickFolderId > 0) {
          await self.loadFolder(clickFolderId);
        }

        return;
      }

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
    this.renderSubtitle();
    this.renderBreadcrumbs();
    this.renderItemsTable();
    this.renderItemsGrid();
    this.syncSelectedState();
  };

  DiskComponent.prototype.renderSubtitle = function () {
    var node = this.root.querySelector('[data-role="subtitle"]');
    if (!node) {
      return;
    }

    node.textContent = this.state.items.length + ' эл.';
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
    }).join('<span>/</span>');
  };

  DiskComponent.prototype.renderItemsTable = function () {
    var tbody = this.root.querySelector('[data-role="items-table"]');
    if (!tbody) {
      return;
    }

    tbody.innerHTML = this.state.items.map(function (item) {
      var typeText = item.entityType === 'folder' ? 'Папка' : (item.extension || 'Файл');
      var sizeText = item.size ? formatBytes(item.size) : '';
      var badge = item.entityType === 'folder'
        ? '<span class="sb-disk__badge">Папка</span>'
        : '<span class="sb-disk__badge">' + escapeHtml(item.extension || 'Файл') + '</span>';

      var openControl = '';

      if (item.entityType === 'folder') {
        openControl = '<button type="button" class="sb-disk__row-btn" data-row-action="open">Открыть</button>';
      } else if (item.previewMode === 'office') {
        openControl =
          '<span ' +
            'class="sb-disk__row-btn sb-disk__viewer-btn disk-detail-sidebar-editor-item disk-detail-sidebar-editor-item-show" ' +
            'data-viewer="" ' +
            'data-viewer-type="cloud-document" ' +
            'data-src="' + escapeHtml(item.previewUrl || '') + '" ' +
            'data-viewer-type-class="BX.Disk.Viewer.DocumentItem" ' +
            'data-viewer-extension="disk.viewer.document-item" ' +
            'data-object-id="' + escapeHtml(item.id) + '" ' +
            'data-title="' + escapeHtml(item.name) + '" ' +
            'data-actions="' + escapeHtml(JSON.stringify([{ type: 'download' }])) + '"' +
          '>Открыть</span>';
      } else {
        openControl = '<button type="button" class="sb-disk__row-btn" data-row-action="open">Открыть</button>';
      }

      return '' +
        '<tr class="sb-disk__row ' + (item.entityType === 'folder' ? 'is-clickable' : '') + '" ' +
          'data-id="' + escapeHtml(item.id) + '" ' +
          'data-entity-type="' + escapeHtml(item.entityType) + '" ' +
          'data-name="' + escapeHtml(item.name) + '" ' +
          'data-download-url="' + escapeHtml(item.downloadUrl || '') + '" ' +
          'data-preview-url="' + escapeHtml(item.previewUrl || '') + '" ' +
          'data-preview-mode="' + escapeHtml(item.previewMode || '') + '">' +
            '<td>' +
              '<input type="checkbox" class="sb-disk__item-check" data-id="' + escapeHtml(item.id) + '">' +
            '</td>' +
            '<td>' +
              '<div class="sb-disk__item-name">' +
                badge +
                '<span class="sb-disk__item-name-label">' + escapeHtml(item.name) + '</span>' +
              '</div>' +
            '</td>' +
            '<td>' + escapeHtml(typeText) + '</td>' +
            '<td>' + escapeHtml(sizeText) + '</td>' +
            '<td>' + escapeHtml(item.updatedAt || '') + '</td>' +
            '<td>' +
              '<div class="sb-disk__actions">' +
                openControl +
                (item.entityType === 'file'
                  ? '<button type="button" class="sb-disk__row-btn" data-row-action="download">Скачать</button>'
                  : '') +
                '<button type="button" class="sb-disk__row-btn" data-row-action="rename">Переим.</button>' +
                '<button type="button" class="sb-disk__row-btn" data-row-action="delete">Удалить</button>' +
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

    container.innerHTML = this.state.items.map(function (item) {
      var typeText = item.entityType === 'folder' ? 'Папка' : (item.extension || 'Файл');
      var sizeText = item.size ? formatBytes(item.size) : '—';

      var openControl = '';

      if (item.entityType === 'folder') {
        openControl = '<button type="button" class="sb-disk__row-btn" data-row-action="open">Открыть</button>';
      } else if (item.previewMode === 'office') {
        openControl =
          '<span ' +
            'class="sb-disk__row-btn sb-disk__viewer-btn disk-detail-sidebar-editor-item disk-detail-sidebar-editor-item-show" ' +
            'data-viewer="" ' +
            'data-viewer-type="cloud-document" ' +
            'data-src="' + escapeHtml(item.previewUrl || '') + '" ' +
            'data-viewer-type-class="BX.Disk.Viewer.DocumentItem" ' +
            'data-viewer-extension="disk.viewer.document-item" ' +
            'data-object-id="' + escapeHtml(item.id) + '" ' +
            'data-title="' + escapeHtml(item.name) + '" ' +
            'data-actions="' + escapeHtml(JSON.stringify([{ type: 'download' }])) + '"' +
          '>Открыть</span>';
      } else {
        openControl = '<button type="button" class="sb-disk__row-btn" data-row-action="open">Открыть</button>';
      }

      return '' +
        '<div class="sb-disk__card ' + (item.entityType === 'folder' ? 'is-clickable' : '') + '" ' +
             'data-id="' + escapeHtml(item.id) + '" ' +
             'data-entity-type="' + escapeHtml(item.entityType) + '" ' +
             'data-name="' + escapeHtml(item.name) + '" ' +
             'data-download-url="' + escapeHtml(item.downloadUrl || '') + '" ' +
             'data-preview-url="' + escapeHtml(item.previewUrl || '') + '" ' +
             'data-preview-mode="' + escapeHtml(item.previewMode || '') + '">' +
            '<div class="sb-disk__card-top">' +
              '<label>' +
                '<input type="checkbox" class="sb-disk__item-check" data-id="' + escapeHtml(item.id) + '">' +
              '</label>' +
              '<span class="sb-disk__badge">' + escapeHtml(typeText) + '</span>' +
            '</div>' +
            '<div class="sb-disk__card-name">' + escapeHtml(item.name) + '</div>' +
            '<div class="sb-disk__card-meta">' +
              '<span class="sb-disk__card-sub">Размер: ' + escapeHtml(sizeText) + '</span>' +
            '</div>' +
            '<div class="sb-disk__card-meta">' +
              '<span class="sb-disk__card-sub">' + escapeHtml(item.updatedAt || '') + '</span>' +
            '</div>' +
            '<div class="sb-disk__card-actions">' +
              openControl +
              (item.entityType === 'file'
                ? '<button type="button" class="sb-disk__row-btn" data-row-action="download">Скачать</button>'
                : '') +
              '<button type="button" class="sb-disk__row-btn" data-row-action="rename">Переим.</button>' +
              '<button type="button" class="sb-disk__row-btn" data-row-action="delete">Удалить</button>' +
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
    });

    if (!stateName) {
      return;
    }

    var node = this.root.querySelector('[data-state="' + stateName + '"]');
    if (node) {
      node.hidden = false;
    }
  };

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

      this.fillSettingsForm(
        settingsRes.data.settings || {},
        rootOptionsRes.data || {}
      );

      this.setSettingsMessage('');
    } catch (e) {
      console.error(e);
      this.setSettingsMessage('Не удалось загрузить настройки.');
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
      payload.settings = this.collectSettingsForm();

      var res = await this.api('saveSettings', payload);
      if (!res || !res.ok) {
        throw new Error((res && (res.message || res.error)) || 'SAVE_SETTINGS_ERROR');
      }

      this.state.settings = res.data.settings || this.state.settings;
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

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.sb-disk').forEach(function (root) {
      var component = new DiskComponent(root);
      component.init();
    });
  });
})();