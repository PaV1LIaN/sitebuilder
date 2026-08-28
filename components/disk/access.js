(function () {
  'use strict';

  var TASK_LABELS = {
    inherit: 'Наследовать',
    none: 'Нет доступа',
    disk_access_read: 'Чтение',
    disk_access_add: 'Добавление',
    disk_access_edit: 'Редактирование',
    disk_access_full: 'Полный доступ'
  };

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function roleLabel(role) {
    var labels = {
      OWNER: 'Владелец сайта',
      ADMIN: 'Администратор сайта',
      EDITOR: 'Редактор сайта',
      VIEWER: 'Наблюдатель сайта',
      USER: 'Участник сайта',
      MEMBER: 'Участник сайта'
    };
    return labels[String(role || '').toUpperCase()] || 'Доступ к странице';
  }

  function addPermissionModeOption(root) {
    var select = root.querySelector('[data-role="settings-form"] [name="permissionMode"]');
    if (!select || select.querySelector('option[value="bitrix_disk"]')) {
      return;
    }

    var option = document.createElement('option');
    option.value = 'bitrix_disk';
    option.textContent = 'Точные права Битрикс24.Диск';
    select.appendChild(option);
  }

  function buildPanel(root) {
    var modal = root.querySelector('[data-role="settings-modal"]');
    var form = root.querySelector('[data-role="settings-form"]');
    var accessPanel = form && form.querySelector('[data-settings-panel="access"]');
    if (!modal || !form || !accessPanel || modal.querySelector('[data-role="disk-access-panel"]')) {
      return null;
    }

    var panel = document.createElement('section');
    panel.className = 'sb-disk-access';
    panel.setAttribute('data-role', 'disk-access-panel');
    panel.innerHTML = [
      '<div class="sb-disk-access__head">',
      '  <div>',
      '    <h3 class="sb-disk-access__title">Права пользователей Битрикс24.Диск</h3>',
      '    <p class="sb-disk-access__lead">Прямое право назначается каждому пользователю, который может открыть эту страницу. Итоговый доступ учитывает наследование папки и группы Битрикс24.</p>',
      '  </div>',
      '  <button type="button" class="sb-disk__btn sb-disk__btn--ghost sb-disk-access__refresh" data-action="disk-access-refresh">Обновить</button>',
      '</div>',
      '<div class="sb-disk-access__mode-note" data-role="disk-access-mode-note"></div>',
      '<div class="sb-disk-access__warning" data-role="disk-access-root-warning" hidden></div>',
      '<div class="sb-disk-access__toolbar">',
      '  <label class="sb-disk-access__search-label">',
      '    <span>Поиск пользователя</span>',
      '    <input type="search" class="sb-disk-form__input" placeholder="ФИО, логин, email или ID" data-role="disk-access-search">',
      '  </label>',
      '  <div class="sb-disk-access__count" data-role="disk-access-count"></div>',
      '</div>',
      '<div class="sb-disk-access__table-wrap" data-role="disk-access-table">',
      '  <div class="sb-disk-access__empty">Загрузка матрицы прав…</div>',
      '</div>',
      '<div class="sb-disk-access__footer">',
      '  <div class="sb-disk-access__status" data-role="disk-access-status" aria-live="polite"></div>',
      '  <button type="button" class="sb-disk__btn" data-action="disk-access-save" disabled>Сохранить права</button>',
      '</div>'
    ].join('');

    accessPanel.appendChild(panel);

    return panel;
  }

  function AccessController(root, component) {
    this.root = root;
    this.component = component;
    this.panel = buildPanel(root);
    this.matrix = null;
    this.loading = false;
    this.saving = false;
    this.filter = '';
    this.selections = {};
    this.dirtyUserIds = {};

    if (!this.panel) {
      return;
    }

    this.bind();
    this.updateModeNote();
  }

  AccessController.prototype.bind = function () {
    var self = this;
    var settingsButton = this.root.querySelector('[data-action="settings"]');
    var refreshButton = this.panel.querySelector('[data-action="disk-access-refresh"]');
    var saveButton = this.panel.querySelector('[data-action="disk-access-save"]');
    var searchInput = this.panel.querySelector('[data-role="disk-access-search"]');
    var modeSelect = this.root.querySelector('[data-role="settings-form"] [name="permissionMode"]');

    if (settingsButton) {
      settingsButton.addEventListener('click', function () {
        window.setTimeout(function () {
          self.load();
        }, 0);
        window.setTimeout(function () {
          self.updateModeNote();
        }, 600);
      });
    }

    if (refreshButton) {
      refreshButton.addEventListener('click', function () {
        self.load();
      });
    }

    if (saveButton) {
      saveButton.addEventListener('click', function () {
        self.save();
      });
    }

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        self.filter = String(searchInput.value || '').trim().toLowerCase();
        self.renderUsers();
      });
    }

    if (modeSelect) {
      modeSelect.addEventListener('change', function () {
        self.updateModeNote();
      });
    }

    this.panel.addEventListener('change', function (event) {
      if (event.target.matches('[data-user-right]')) {
        var userId = Number(event.target.getAttribute('data-user-right') || 0);
        var selectedTaskName = String(event.target.value || 'inherit');
        self.selections[userId] = selectedTaskName;
        if (userId > 0) {
          var matrixUser = (Array.isArray(self.matrix && self.matrix.users) ? self.matrix.users : []).find(function (user) {
            return Number(user.userId || 0) === userId;
          });
          if (matrixUser && String(matrixUser.directTaskName || 'inherit') === selectedTaskName) {
            delete self.dirtyUserIds[userId];
          } else {
            self.dirtyUserIds[userId] = true;
          }
        }
        event.target.setAttribute('data-dirty', self.dirtyUserIds[userId] ? '1' : '0');
        self.renderUsers();
        self.setStatus(
          self.hasPendingChanges() ? 'Есть несохранённые изменения.' : 'Матрица прав актуальна.',
          self.hasPendingChanges() ? 'pending' : 'success'
        );
      }
    });
  };

  AccessController.prototype.hasPendingChanges = function () {
    return Object.keys(this.dirtyUserIds || {}).length > 0;
  };

  AccessController.prototype.payload = function () {
    var payload = this.component.getBasePayload();
    payload.sessid = this.component.getSessid();
    return payload;
  };

  AccessController.prototype.load = async function () {
    if (this.loading || !this.panel) {
      return;
    }

    this.loading = true;
    this.setStatus('Загрузка матрицы прав…', 'loading');
    this.toggleButtons();

    try {
      var response = await this.component.api('getAccessMatrix', this.payload());
      if (!response || !response.ok) {
        throw new Error((response && (response.message || response.error)) || 'GET_ACCESS_MATRIX_ERROR');
      }

      this.matrix = response.data || {};
      this.syncSelectionsFromMatrix();
      this.renderRootWarning();
      this.renderUsers();
      this.setStatus('Матрица прав актуальна.', 'success');
    } catch (error) {
      console.error(error);
      this.matrix = null;
      this.renderError(error.message || 'Не удалось загрузить права.');
      this.setStatus('Не удалось загрузить права.', 'error');
    } finally {
      this.loading = false;
      this.toggleButtons();
      this.updateModeNote();
    }
  };

  AccessController.prototype.save = async function () {
    if (this.saving || !this.matrix) {
      return false;
    }

    if (!this.hasPendingChanges()) {
      return true;
    }

    var self = this;
    var rights = (Array.isArray(this.matrix.users) ? this.matrix.users : [])
      .filter(function (user) {
        return !user.isAclProtected;
      })
      .map(function (user) {
        return {
          userId: Number(user.userId || 0),
          taskName: String(self.selections[Number(user.userId || 0)] || user.directTaskName || 'inherit')
        };
      });

    this.saving = true;
    this.setStatus('Сохранение прав в Битрикс24.Диск…', 'loading');
    this.toggleButtons();

    try {
      var payload = this.payload();
      payload.expectedRightsRevision = String(this.matrix.rightsRevision || '');
      payload.rights = rights;

      var response = await this.component.api('saveAccessMatrix', payload);
      if (!response || !response.ok) {
        if (response && response.error === 'DISK_RIGHTS_VERSION_CONFLICT') {
          throw new Error('Права изменились в другой вкладке. Матрица будет обновлена.');
        }
        throw new Error((response && (response.message || response.error)) || 'SAVE_ACCESS_MATRIX_ERROR');
      }

      this.matrix = response.data || {};
      this.syncSelectionsFromMatrix();
      this.renderRootWarning();
      this.renderUsers();

      var blockedUsers = this.findEffectiveConflicts(rights);
      if (blockedUsers.length) {
        this.setStatus(
          'Прямые права сохранены, но итоговый доступ для ' + blockedUsers.length
            + ' польз. ограничен наследуемыми правилами Битрикс24.Диска.',
          'error'
        );
        return false;
      }

      this.setStatus('Права сохранены и применены в Битрикс24.Диске.', 'success');
      return true;
    } catch (error) {
      console.error(error);
      this.setStatus(error.message || 'Не удалось сохранить права.', 'error');
      if (String(error.message || '').indexOf('другой вкладке') !== -1) {
        await this.load();
      }
      return false;
    } finally {
      this.saving = false;
      this.toggleButtons();
    }
  };

  AccessController.prototype.renderUsers = function () {
    var container = this.panel.querySelector('[data-role="disk-access-table"]');
    var countNode = this.panel.querySelector('[data-role="disk-access-count"]');
    if (!container || !this.matrix) {
      return;
    }

    var users = Array.isArray(this.matrix.users) ? this.matrix.users : [];
    var tasks = Array.isArray(this.matrix.tasks) ? this.matrix.tasks : [];
    var filter = this.filter;
    var filtered = users.filter(function (user) {
      if (!filter) {
        return true;
      }
      return [user.name, user.login, user.email, user.userId]
        .join(' ')
        .toLowerCase()
        .indexOf(filter) !== -1;
    });

    if (countNode) {
      countNode.textContent = filter
        ? 'Показано: ' + filtered.length + ' из ' + users.length
        : 'Пользователей: ' + users.length;
    }

    if (!filtered.length) {
      container.innerHTML = '<div class="sb-disk-access__empty">Пользователи не найдены.</div>';
      return;
    }

    var taskOptions = tasks.map(function (task) {
      return '<option value="' + escapeHtml(task.name) + '">' + escapeHtml(task.label) + '</option>';
    }).join('');

    var controller = this;
    var rows = filtered.map(function (user) {
      var userId = Number(user.userId || 0);
      var pageAccess = user.pageAccess || {};
      var details = [];
      if (user.login) {
        details.push('@' + user.login);
      }
      if (user.email) {
        details.push(user.email);
      }
      details.push('ID ' + user.userId);

      var badges = [];
      badges.push('<span class="sb-disk-access__badge">' + escapeHtml(roleLabel(user.globalRole)) + '</span>');
      if (pageAccess.canEdit) {
        badges.push('<span class="sb-disk-access__badge">Редактирует страницу</span>');
      }
      if (pageAccess.canDiskEdit) {
        badges.push('<span class="sb-disk-access__badge">Диск: изменение</span>');
      } else if (pageAccess.canDiskView) {
        badges.push('<span class="sb-disk-access__badge">Диск: просмотр</span>');
      }
      if (user.isCurrentUser) {
        badges.push('<span class="sb-disk-access__badge sb-disk-access__badge--accent">Вы</span>');
      }

      var adminNote = user.isAclProtected
        ? '<div class="sb-disk-access__admin-note">Администратор или владелец сохраняет полный доступ независимо от ACL папки.</div>'
        : '';

      var isPending = !!controller.dirtyUserIds[userId];
      var pendingTaskName = String(controller.selections[userId] || user.directTaskName || 'inherit');
      var effectiveTaskName = String(user.effectiveTaskName || 'none');
      var effectiveLabel = TASK_LABELS[effectiveTaskName] || effectiveTaskName || 'Нет доступа';
      var effectiveClass = effectiveTaskName;

      if (isPending) {
        effectiveLabel = 'После сохранения: ' + (TASK_LABELS[pendingTaskName] || pendingTaskName);
        effectiveClass = 'pending';
      }

      return [
        '<tr data-access-user-row data-search="' + escapeHtml(details.join(' ')) + '">',
        '  <td>',
        '    <div class="sb-disk-access__person">',
        user.avatarUrl
          ? '      <img class="sb-disk-access__avatar" src="' + escapeHtml(user.avatarUrl) + '" alt="">'
          : '      <span class="sb-disk-access__avatar sb-disk-access__avatar--placeholder">' + escapeHtml(String(user.name || '?').charAt(0).toUpperCase()) + '</span>',
        '      <div class="sb-disk-access__person-copy">',
        '        <div class="sb-disk-access__name">' + escapeHtml(user.name || ('Пользователь #' + user.userId)) + '</div>',
        '        <div class="sb-disk-access__details">' + escapeHtml(details.join(' · ')) + '</div>',
        '        <div class="sb-disk-access__badges">' + badges.join('') + '</div>',
        '        ' + adminNote,
        '      </div>',
        '    </div>',
        '  </td>',
        '  <td>',
        '    <select class="sb-disk-form__select sb-disk-access__select" data-user-right="' + userId + '"' + (user.isAclProtected ? ' disabled' : '') + '>',
        taskOptions,
        '    </select>',
        '    <div class="sb-disk-access__source">' + (user.rightSource === 'system_admin' ? 'Системный полный доступ' : (user.rightSource === 'direct' ? 'Прямое правило' : 'Прямого правила нет')) + '</div>',
        '  </td>',
        '  <td>',
        '    <span class="sb-disk-access__effective sb-disk-access__effective--' + escapeHtml(effectiveClass) + '">' + escapeHtml(effectiveLabel) + '</span>',
        '  </td>',
        '</tr>'
      ].join('');
    }).join('');

    container.innerHTML = [
      '<table class="sb-disk-access__table">',
      '  <thead><tr><th>Пользователь и доступ к странице</th><th>Прямое право Диска</th><th>Итоговое право</th></tr></thead>',
      '  <tbody>' + rows + '</tbody>',
      '</table>'
    ].join('');

    filtered.forEach(function (user) {
      var select = container.querySelector('[data-user-right="' + Number(user.userId) + '"]');
      if (select) {
        select.value = String(this.selections[Number(user.userId)] || user.directTaskName || 'inherit');
      }
    }, this);
  };

  AccessController.prototype.syncSelectionsFromMatrix = function () {
    var selections = {};
    (Array.isArray(this.matrix && this.matrix.users) ? this.matrix.users : []).forEach(function (user) {
      selections[Number(user.userId || 0)] = String(user.directTaskName || 'inherit');
    });
    this.selections = selections;
    this.dirtyUserIds = {};
  };

  AccessController.prototype.findEffectiveConflicts = function (requestedRights) {
    var rank = {
      inherit: 0,
      none: 0,
      disk_access_read: 1,
      disk_access_add: 2,
      disk_access_edit: 3,
      disk_access_full: 4
    };
    var requestedByUser = {};
    (Array.isArray(requestedRights) ? requestedRights : []).forEach(function (right) {
      requestedByUser[Number(right.userId || 0)] = String(right.taskName || 'inherit');
    });

    return (Array.isArray(this.matrix && this.matrix.users) ? this.matrix.users : []).filter(function (user) {
      if (user.isAclProtected) {
        return false;
      }
      var requested = requestedByUser[Number(user.userId || 0)] || 'inherit';
      if (requested === 'inherit' || requested === 'none') {
        return false;
      }
      return Number(rank[user.effectiveTaskName] || 0) < Number(rank[requested] || 0);
    });
  };

  AccessController.prototype.renderRootWarning = function () {
    var warning = this.panel.querySelector('[data-role="disk-access-root-warning"]');
    if (!warning || !this.matrix) {
      return;
    }

    if (this.matrix.rootSource === 'site') {
      warning.hidden = false;
      warning.textContent = 'Используется общая папка сайта #' + this.matrix.folderId + '. Изменения ACL затронут все блоки, которые используют эту же папку.';
    } else {
      warning.hidden = true;
      warning.textContent = '';
    }
  };

  AccessController.prototype.renderError = function (message) {
    var container = this.panel.querySelector('[data-role="disk-access-table"]');
    if (container) {
      container.innerHTML = '<div class="sb-disk-access__empty sb-disk-access__empty--error">' + escapeHtml(message) + '</div>';
    }
  };

  AccessController.prototype.updateModeNote = function () {
    var modeSelect = this.root.querySelector('[data-role="settings-form"] [name="permissionMode"]');
    var note = this.panel && this.panel.querySelector('[data-role="disk-access-mode-note"]');
    var isActive = !!(modeSelect && modeSelect.value === 'bitrix_disk');
    if (!note) {
      return;
    }

    this.panel.classList.toggle('sb-disk-access--active', isActive);
    note.className = 'sb-disk-access__mode-note' + (isActive ? ' is-active' : '');
    note.textContent = isActive
      ? 'Выбран точный режим: сохраните общие настройки отдельной кнопкой. После сохранения операции компонента будут проверяться по итоговым правам папки Битрикс24.Диск.'
      : 'Чтобы применить эту матрицу в компоненте, выберите режим «Точные права Битрикс24.Диск» и сохраните общие настройки.';
  };

  AccessController.prototype.setStatus = function (message, kind) {
    var status = this.panel.querySelector('[data-role="disk-access-status"]');
    if (!status) {
      return;
    }
    status.textContent = message || '';
    status.setAttribute('data-status-kind', kind || '');
  };

  AccessController.prototype.toggleButtons = function () {
    if (!this.panel) {
      return;
    }
    var save = this.panel.querySelector('[data-action="disk-access-save"]');
    var refresh = this.panel.querySelector('[data-action="disk-access-refresh"]');
    if (save) {
      save.disabled = this.loading || this.saving || !this.matrix;
    }
    if (refresh) {
      refresh.disabled = this.loading || this.saving;
    }
  };

  function init() {
    document.querySelectorAll('.sb-disk').forEach(function (root) {
      if (root.getAttribute('data-disk-access-ready') === '1') {
        return;
      }

      addPermissionModeOption(root);
      var component = root.__diskComponent;
      if (!component) {
        var attempts = Number(root.getAttribute('data-disk-access-attempts') || 0) + 1;
        root.setAttribute('data-disk-access-attempts', String(attempts));
        if (attempts < 100) {
          window.setTimeout(init, 50);
        }
        return;
      }

      root.removeAttribute('data-disk-access-attempts');
      root.setAttribute('data-disk-access-ready', '1');
      root.__diskAccessController = new AccessController(root, component);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
