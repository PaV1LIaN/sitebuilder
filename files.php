<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

global $USER, $APPLICATION;

if (!$USER->IsAuthorized()) {
    LocalRedirect('/auth/');
}

header('Content-Type: text/html; charset=UTF-8');

\Bitrix\Main\UI\Extension::load([
    'main.core',
    'ui.buttons',
    'ui.dialogs.messagebox',
    'ui.notification',
]);

$siteId = (int)($_GET['siteId'] ?? 0);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Файлы сайта</title>
  <?php $APPLICATION->ShowHead(); ?>
  <style>
    body { font-family: Arial, sans-serif; margin:0; background:#f6f7f8; }
    .top { background:#fff; border-bottom:1px solid #e5e7ea; padding:12px 16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .content { padding: 18px; }
    .card { background:#fff; border:1px solid #e5e7ea; border-radius:12px; padding:16px; }
    .muted { color:#6a737f; }
    a { color:#0b57d0; text-decoration:none; }
    a:hover { text-decoration:underline; }
    code { background:#f3f4f6; padding:2px 6px; border-radius:6px; }
    .row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
    .field { min-width: 240px; }
    table { width:100%; border-collapse:collapse; margin-top:10px; }
    th, td { padding:8px; border-bottom:1px solid #eee; text-align:left; }
  </style>
</head>
<body>
  <div class="top">
    <a href="/local/sitebuilder/index.php">← Назад</a>
    <div class="muted">Файлы сайта</div>
    <div class="muted">|</div>
    <div><b>siteId:</b> <code><?= (int)$siteId ?></code></div>
    <div style="flex:1;"></div>
    <div class="muted" id="roleBox"></div>
  </div>

  <div class="content">
    <div class="card">
      <div class="row" id="uploadRow">
        <div style="flex:1;">
          <div class="muted">
            Права:
            <b>VIEWER</b> — только просмотр/скачивание,
            <b>EDITOR+</b> — загрузка/удаление.
          </div>
        </div>

        <div class="field" id="fileField">
          <div style="font-size:12px;color:#6a737f;margin-bottom:4px;">Загрузить файл</div>
          <input type="file" id="fileInput" />
        </div>
        <div id="uploadBtnWrap">
          <button class="ui-btn ui-btn-primary" id="btnUpload">Загрузить</button>
        </div>
        <div>
          <button class="ui-btn ui-btn-light" id="btnRefresh">Обновить</button>
        </div>
      </div>

      <div id="info" class="muted" style="margin-top:10px;"></div>
      <div id="filesBox" style="margin-top:10px;"></div>
    </div>
  </div>

<script>
BX.ready(function () {
  const siteId = <?= (int)$siteId ?>;

  const roleBox = document.getElementById('roleBox');
  const info = document.getElementById('info');
  const filesBox = document.getElementById('filesBox');

  const uploadRow = document.getElementById('uploadRow');
  const fileInput = document.getElementById('fileInput');
  const btnUpload = document.getElementById('btnUpload');
  const btnRefresh = document.getElementById('btnRefresh');

  let myRole = null;

  function api(action, data) {
    return new Promise((resolve, reject) => {
      BX.ajax({
        url: '/local/sitebuilder/api.php',
        method: 'POST',
        dataType: 'json',
        data: Object.assign({ action, siteId, sessid: BX.bitrix_sessid() }, data || {}),
        onsuccess: resolve,
        onfailure: reject
      });
    });
  }

  async function apiUpload(file) {
    const fd = new FormData();
    fd.append('action', 'file.upload');
    fd.append('siteId', String(siteId));
    fd.append('sessid', BX.bitrix_sessid());
    fd.append('file', file);

    const res = await fetch('/local/sitebuilder/api.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });

    const json = await res.json().catch(() => null);
    if (!res.ok) {
      const err = json && json.error ? json.error : ('HTTP_' + res.status);
      throw new Error(err);
    }
    return json;
  }

  function rank(role) {
    role = (role || '').toUpperCase();
    if (role === 'OWNER') return 4;
    if (role === 'ADMIN') return 3;
    if (role === 'EDITOR') return 2;
    if (role === 'VIEWER') return 1;
    return 0;
  }

  function canWrite() {
    return rank(myRole) >= 2; // EDITOR+
  }

  function humanSize(bytes) {
    const n = Number(bytes || 0);
    if (n < 1024) return n + ' B';
    const kb = n / 1024;
    if (kb < 1024) return kb.toFixed(1) + ' KB';
    const mb = kb / 1024;
    if (mb < 1024) return mb.toFixed(1) + ' MB';
    const gb = mb / 1024;
    return gb.toFixed(1) + ' GB';
  }

  function downloadUrl(fileId) {
    return `/local/sitebuilder/download.php?siteId=${siteId}&fileId=${fileId}`;
  }

  function renderFiles(files) {
    if (!files || !files.length) {
      filesBox.innerHTML = '<div class="muted">Файлов пока нет.</div>';
      return;
    }

    const showDelete = canWrite();

    const rows = files.map(f => `
      <tr>
        <td>${f.id}</td>
        <td>${BX.util.htmlspecialchars(f.name)}</td>
        <td>${humanSize(f.size)}</td>
        <td class="muted">${BX.util.htmlspecialchars(f.createdAt || '')}</td>
        <td style="white-space:nowrap;">
          <a class="ui-btn ui-btn-light ui-btn-xs"
             href="${downloadUrl(f.id)}"
             target="_blank">Скачать</a>
          ${showDelete ? `<button class="ui-btn ui-btn-danger ui-btn-xs" data-del-file-id="${f.id}">Удалить</button>` : ``}
        </td>
      </tr>
    `).join('');

    filesBox.innerHTML = `
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Имя</th>
            <th>Размер</th>
            <th>Создан</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;
  }

  function applyUiByRole() {
    roleBox.textContent = myRole ? ('Роль: ' + myRole) : '';

    // Для VIEWER скрываем загрузку
    if (!canWrite()) {
      // оставим кнопку "Обновить", а загрузку уберём
      if (fileInput) fileInput.disabled = true;
      if (btnUpload) btnUpload.style.display = 'none';
      const fileField = document.getElementById('fileField');
      if (fileField) fileField.style.display = 'none';
    } else {
      if (fileInput) fileInput.disabled = false;
      if (btnUpload) btnUpload.style.display = '';
      const fileField = document.getElementById('fileField');
      if (fileField) fileField.style.display = '';
    }
  }

  function loadFiles() {
    info.textContent = 'Загрузка...';
    api('file.list').then(res => {
      if (!res || res.ok !== true) {
        info.textContent = '';
        BX.UI.Notification.Center.notify({ content: 'Не удалось загрузить файлы (возможно нет прав)' });
        return;
      }

      myRole = res.myRole || null;
      applyUiByRole();

      info.textContent = res.folder
        ? ('Папка: ' + res.folder.name + ' (diskFolderId=' + res.folder.id + ')')
        : '';

      renderFiles(res.files);
    }).catch(() => {
      info.textContent = '';
      BX.UI.Notification.Center.notify({ content: 'Ошибка запроса file.list' });
    });
  }

  btnUpload?.addEventListener('click', async function () {
    if (!canWrite()) {
      BX.UI.Notification.Center.notify({ content: 'Нет прав на загрузку (нужен EDITOR+)' });
      return;
    }

    const file = fileInput.files && fileInput.files[0];
    if (!file) {
      BX.UI.Notification.Center.notify({ content: 'Выбери файл' });
      return;
    }

    try {
      btnUpload.disabled = true;
      const res = await apiUpload(file);
      if (!res || res.ok !== true) {
        BX.UI.Notification.Center.notify({ content: 'Не удалось загрузить файл' });
        return;
      }
      BX.UI.Notification.Center.notify({ content: 'Файл загружен' });
      fileInput.value = '';
      loadFiles();
    } catch (e) {
      BX.UI.Notification.Center.notify({ content: 'Ошибка загрузки: ' + (e && e.message ? e.message : 'UNKNOWN') });
    } finally {
      btnUpload.disabled = false;
    }
  });

  btnRefresh?.addEventListener('click', loadFiles);

  document.addEventListener('click', function (e) {
    const delBtn = e.target.closest('[data-del-file-id]');
    if (!delBtn) return;

    if (!canWrite()) {
      BX.UI.Notification.Center.notify({ content: 'Нет прав на удаление (нужен EDITOR+)' });
      return;
    }

    const fileId = parseInt(delBtn.getAttribute('data-del-file-id'), 10);
    if (!fileId) return;

    BX.UI.Dialogs.MessageBox.show({
      title: 'Удалить файл?',
      message: 'Продолжить?',
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function (mb) {
        api('file.delete', { fileId }).then(res => {
          if (!res || res.ok !== true) {
            BX.UI.Notification.Center.notify({ content: 'Не удалось удалить файл (возможно нет прав)' });
            return;
          }
          BX.UI.Notification.Center.notify({ content: 'Файл удалён' });
          mb.close();
          loadFiles();
        }).catch(() => BX.UI.Notification.Center.notify({ content: 'Ошибка file.delete' }));
      }
    });
  });

  loadFiles();
});
</script>
</body>
</html>