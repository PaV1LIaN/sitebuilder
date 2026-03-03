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
  <title>Меню сайта</title>
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
    .row { display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .btns { display:flex; gap:6px; flex-wrap:wrap; }
    .menuCard { border:1px solid #eee; border-radius:12px; padding:12px; margin-top:12px; }
    table { width:100%; border-collapse:collapse; margin-top:10px; }
    th, td { padding:8px; border-bottom:1px solid #eee; text-align:left; vertical-align:top; }
    select, input { padding:8px; border:1px solid #d0d7de; border-radius:8px; }
    .small { font-size:12px; }
  </style>
</head>
<body>
  <div class="top">
    <a href="/local/sitebuilder/index.php">← Назад</a>
    <div class="muted">Меню сайта</div>
    <div class="muted">|</div>
    <div><b>siteId:</b> <code><?= (int)$siteId ?></code></div>
    <div style="flex:1;"></div>
    <button class="ui-btn ui-btn-light" id="btnRefresh">Обновить</button>
    <button class="ui-btn ui-btn-primary" id="btnCreateMenu">+ Меню</button>
  </div>

  <div class="content">
    <div class="card">
      <div class="muted">Меню — это набор пунктов. Пункт может вести на страницу сайта (type=page) или на внешний URL (type=url).</div>
      <div id="box" style="margin-top:12px;"></div>
    </div>
  </div>

<script>
BX.ready(function () {
  const siteId = <?= (int)$siteId ?>;

  const box = document.getElementById('box');
  const btnRefresh = document.getElementById('btnRefresh');
  const btnCreateMenu = document.getElementById('btnCreateMenu');

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

  async function loadAll() {
    const [menusRes, pagesRes, siteRes] = await Promise.all([
        api('menu.list'),
        api('page.list'),
        api('site.get', { siteId }) // можно и без siteId, но так надёжнее
    ]);

    if (!menusRes || menusRes.ok !== true) throw new Error('menu.list failed');
    if (!pagesRes || pagesRes.ok !== true) throw new Error('page.list failed');
    if (!siteRes || siteRes.ok !== true) throw new Error('site.get failed');

    return {
        menus: menusRes.menus || [],
        pages: pagesRes.pages || [],
        site: siteRes.site || {}
    };
  }

  function pageTitle(pages, pageId) {
    const p = pages.find(x => parseInt(x.id,10) === parseInt(pageId,10));
    return p ? p.title : ('page#' + pageId);
  }

  function render({ menus, pages, site }) {
    const topMenuId = parseInt(site?.topMenuId || 0, 10) || 0;
    if (!menus.length) {
      box.innerHTML = '<div class="muted">Меню пока нет. Нажми “+ Меню”.</div>';
      return;
    }

    box.innerHTML = menus.map(m => {
      const items = m.items || [];
      const rows = items.length ? items.map(it => {
        const type = it.type;
        const title = it.title || '';
        const sort = it.sort || 0;
        const id = it.id;

        let target = '';
        if (type === 'page') {
          target = 'pageId=' + it.pageId + ' (' + BX.util.htmlspecialchars(pageTitle(pages, it.pageId)) + ')';
        } else {
          target = BX.util.htmlspecialchars(it.url || '');
        }

        return `
          <tr>
            <td>${id}</td>
            <td>${BX.util.htmlspecialchars(type)}</td>
            <td>${BX.util.htmlspecialchars(title)}</td>
            <td class="muted">${BX.util.htmlspecialchars(String(target))}</td>
            <td class="muted">${sort}</td>
            <td style="white-space:nowrap;">
              <button class="ui-btn ui-btn-light ui-btn-xs" data-item-move="${id}" data-menu-id="${m.id}" data-dir="up">↑</button>
              <button class="ui-btn ui-btn-light ui-btn-xs" data-item-move="${id}" data-menu-id="${m.id}" data-dir="down">↓</button>
              <button class="ui-btn ui-btn-danger ui-btn-xs" data-item-del="${id}" data-menu-id="${m.id}">Удалить</button>
            </td>
          </tr>
        `;
      }).join('') : `<tr><td colspan="6" class="muted">Пунктов нет</td></tr>`;

      return `
        <div class="menuCard">
          <div class="row">
            <div>
              <b>${BX.util.htmlspecialchars(m.name || ('Меню #' + m.id))}</b>
              <span class="muted small"> (menuId: ${m.id})</span>
            </div>
            <div class="btns">
                ${parseInt(m.id,10) === (topMenuId||0)
                    ? `<span class="muted small" style="padding:6px 8px;">Верхнее меню</span>`
                    : `<button class="ui-btn ui-btn-success ui-btn-xs" data-menu-set-top="${m.id}">Сделать верхним</button>`
                }
                <button class="ui-btn ui-btn-light ui-btn-xs" data-menu-rename="${m.id}">Переименовать</button>
                <button class="ui-btn ui-btn-primary ui-btn-xs" data-item-add="${m.id}">+ Пункт</button>
                <button class="ui-btn ui-btn-danger ui-btn-xs" data-menu-delete="${m.id}">Удалить меню</button>
            </div>
          </div>

          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Тип</th>
                <th>Название</th>
                <th>Куда</th>
                <th>Sort</th>
                <th>Действия</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      `;
    }).join('');
  }

  function createMenu() {
    BX.UI.Dialogs.MessageBox.show({
      title: 'Создать меню',
      message: `<input id="new_menu_name" style="width:100%;" placeholder="например: Верхнее меню" />`,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function (mb) {
        const name = (document.getElementById('new_menu_name')?.value || '').trim();
        if (!name) {
          BX.UI.Notification.Center.notify({ content: 'Введите название' });
          return;
        }
        api('menu.create', { name }).then(res => {
          if (!res || res.ok !== true) {
            BX.UI.Notification.Center.notify({ content: 'Не удалось создать меню (нужен EDITOR+)' });
            return;
          }
          BX.UI.Notification.Center.notify({ content: 'Меню создано' });
          mb.close();
          refresh();
        });
      }
    });
  }

  function renameMenu(menuId) {
    BX.UI.Dialogs.MessageBox.show({
      title: 'Переименовать меню #' + menuId,
      message: `<input id="rename_menu_name" style="width:100%;" placeholder="Новое название" />`,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function (mb) {
        const name = (document.getElementById('rename_menu_name')?.value || '').trim();
        if (!name) { BX.UI.Notification.Center.notify({ content: 'Введите название' }); return; }
        api('menu.update', { menuId, name }).then(res => {
          if (!res || res.ok !== true) {
            BX.UI.Notification.Center.notify({ content: 'Не удалось переименовать (нужен EDITOR+)' });
            return;
          }
          BX.UI.Notification.Center.notify({ content: 'Сохранено' });
          mb.close();
          refresh();
        });
      }
    });
  }

  function addItem(menuId, pages) {
    BX.UI.Dialogs.MessageBox.show({
      title: 'Добавить пункт меню #' + menuId,
      message: `
        <div class="small muted">Тип пункта:</div>
        <select id="it_type" style="width:100%;margin-top:6px;">
          <option value="page">Страница сайта</option>
          <option value="url">Внешний URL</option>
        </select>

        <div style="margin-top:10px;" id="it_page_wrap">
          <div class="small muted">Страница:</div>
          <select id="it_page" style="width:100%;margin-top:6px;">
            ${pages.map(p => `<option value="${p.id}">${BX.util.htmlspecialchars(p.title)} (id ${p.id})</option>`).join('')}
          </select>
        </div>

        <div style="margin-top:10px;display:none;" id="it_url_wrap">
          <div class="small muted">URL:</div>
          <input id="it_url" style="width:100%;margin-top:6px;" placeholder="https://example.com или /local/..." />
        </div>

        <div style="margin-top:10px;">
          <div class="small muted">Название пункта (можно оставить пустым):</div>
          <input id="it_title" style="width:100%;margin-top:6px;" placeholder="например: Главная" />
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function (mb) {
        const type = (document.getElementById('it_type')?.value || 'page');
        const title = (document.getElementById('it_title')?.value || '').trim();

        if (type === 'page') {
          const pageId = parseInt(document.getElementById('it_page')?.value || '0', 10);
          api('menu.item.add', { menuId, type: 'page', pageId, title }).then(res => {
            if (!res || res.ok !== true) {
              BX.UI.Notification.Center.notify({ content: 'Не удалось добавить пункт (нужен EDITOR+)' });
              return;
            }
            BX.UI.Notification.Center.notify({ content: 'Пункт добавлен' });
            mb.close();
            refresh();
          });
        } else {
          const url = (document.getElementById('it_url')?.value || '').trim();
          if (!url) { BX.UI.Notification.Center.notify({ content: 'Введите URL' }); return; }
          api('menu.item.add', { menuId, type: 'url', url, title }).then(res => {
            if (!res || res.ok !== true) {
              BX.UI.Notification.Center.notify({ content: 'Не удалось добавить пункт (нужен EDITOR+)' });
              return;
            }
            BX.UI.Notification.Center.notify({ content: 'Пункт добавлен' });
            mb.close();
            refresh();
          });
        }
      }
    });

    // переключатель type
    setTimeout(() => {
      const t = document.getElementById('it_type');
      const pageWrap = document.getElementById('it_page_wrap');
      const urlWrap = document.getElementById('it_url_wrap');
      if (!t || !pageWrap || !urlWrap) return;

      const apply = () => {
        const v = t.value;
        if (v === 'page') {
          pageWrap.style.display = '';
          urlWrap.style.display = 'none';
        } else {
          pageWrap.style.display = 'none';
          urlWrap.style.display = '';
        }
      };
      t.addEventListener('change', apply);
      apply();
    }, 0);
  }

  function deleteItem(menuId, itemId) {
    BX.UI.Dialogs.MessageBox.show({
      title: 'Удалить пункт #' + itemId + '?',
      message: 'Продолжить?',
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function (mb) {
        api('menu.item.delete', { menuId, itemId }).then(res => {
          if (!res || res.ok !== true) {
            BX.UI.Notification.Center.notify({ content: 'Не удалось удалить (нужен EDITOR+)' });
            return;
          }
          BX.UI.Notification.Center.notify({ content: 'Удалено' });
          mb.close();
          refresh();
        });
      }
    });
  }

  function moveItem(menuId, itemId, dir) {
    api('menu.item.move', { menuId, itemId, dir }).then(res => {
      if (!res || res.ok !== true) {
        BX.UI.Notification.Center.notify({ content: 'Не удалось переместить (нужен EDITOR+)' });
        return;
      }
      refresh();
    });
  }

  async function refresh() {
    try {
      const data = await loadAll();
      render(data);
    } catch (e) {
      BX.UI.Notification.Center.notify({ content: 'Ошибка загрузки меню/страниц (возможно нет прав VIEWER)' });
      box.innerHTML = '<div class="muted">Ошибка загрузки.</div>';
    }
  }

  btnRefresh.addEventListener('click', refresh);
  btnCreateMenu.addEventListener('click', createMenu);

  document.addEventListener('click', async function (e) {
    const rn = e.target.closest('[data-menu-rename]');
    if (rn) {
      renameMenu(parseInt(rn.getAttribute('data-menu-rename'), 10));
      return;
    }

    const md = e.target.closest('[data-menu-delete]');
    if (md) {
        const menuId = parseInt(md.getAttribute('data-menu-delete'), 10);
        BX.UI.Dialogs.MessageBox.show({
            title: 'Удалить меню #' + menuId + '?',
            message: 'Меню будет удалено навсегда. Продолжить?',
            buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
            onOk: function(mb){
            api('menu.delete', { menuId }).then(res => {
                if (!res || res.ok !== true) {
                BX.UI.Notification.Center.notify({ content: 'Не удалось удалить меню (нужен EDITOR+)' });
                return;
                }
                BX.UI.Notification.Center.notify({ content: 'Меню удалено' });
                mb.close();
                refresh();
            }).catch(() => BX.UI.Notification.Center.notify({ content: 'Ошибка menu.delete' }));
            }
        });
        return;
    }

    const add = e.target.closest('[data-item-add]');
    if (add) {
      const menuId = parseInt(add.getAttribute('data-item-add'), 10);
      try {
        const pagesRes = await api('page.list');
        if (!pagesRes || pagesRes.ok !== true) throw new Error();
        addItem(menuId, pagesRes.pages || []);
      } catch (e) {
        BX.UI.Notification.Center.notify({ content: 'Не удалось загрузить страницы' });
      }
      return;
    }

    const del = e.target.closest('[data-item-del]');
    if (del) {
      const menuId = parseInt(del.getAttribute('data-menu-id'), 10);
      const itemId = parseInt(del.getAttribute('data-item-del'), 10);
      deleteItem(menuId, itemId);
      return;
    }

    const mv = e.target.closest('[data-item-move]');
    if (mv) {
      const menuId = parseInt(mv.getAttribute('data-menu-id'), 10);
      const itemId = parseInt(mv.getAttribute('data-item-move'), 10);
      const dir = mv.getAttribute('data-dir');
      moveItem(menuId, itemId, dir);
      return;
    }

    const st = e.target.closest('[data-menu-set-top]');
    if (st) {
        const menuId = parseInt(st.getAttribute('data-menu-set-top'), 10);
        api('menu.setTop', { menuId }).then(res => {
            if (!res || res.ok !== true) {
            BX.UI.Notification.Center.notify({ content: 'Не удалось назначить верхнее меню (нужен EDITOR+)' });
            return;
            }
            BX.UI.Notification.Center.notify({ content: 'Верхнее меню назначено' });
            refresh();
        }).catch(() => BX.UI.Notification.Center.notify({ content: 'Ошибка menu.setTop' }));
        return;
    }
  });

  refresh();
});
</script>
</body>
</html>