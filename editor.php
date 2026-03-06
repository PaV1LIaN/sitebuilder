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
$pageId = (int)($_GET['pageId'] ?? 0);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Редактор блоков</title>
  <?php $APPLICATION->ShowHead(); ?>
  <style>
    body { font-family: Arial, sans-serif; margin:0; background:#f6f7f8; }
    .top { background:#fff; border-bottom:1px solid #e5e7ea; padding:12px 16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .content { padding: 18px; }
    .card { background:#fff; border:1px solid #e5e7ea; border-radius:12px; padding:16px; }
    .muted { color:#6a737f; }
    .block { border:1px solid #eee; border-radius:10px; padding:12px; margin-top:10px; }
    .row { display:flex; gap:8px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .btns { display:flex; gap:6px; flex-wrap:wrap; }
    pre { white-space:pre-wrap; margin:10px 0 0; background:#f9fafb; border:1px solid #eee; border-radius:8px; padding:10px; }
    a { color:#0b57d0; text-decoration:none; }
    a:hover { text-decoration:underline; }
    code { background:#f3f4f6; padding:2px 6px; border-radius:6px; }
    .imgPrev { margin-top:10px; max-width: 420px; border:1px solid #eee; border-radius:10px; overflow:hidden; background:#fafafa; }
    .imgPrev img { display:block; width:100%; height:auto; }
    .field { margin-top:10px; }
    .field label { display:block; font-size:12px; color:#6a737f; margin-bottom:4px; }
    .input, select, textarea { width:100%; padding:8px; border:1px solid #d0d7de; border-radius:8px; box-sizing:border-box; }

    .btnPreview { margin-top:10px; display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid #e5e7ea; text-decoration:none; }
    .btnPrimary { background:#2563eb; color:#fff; border-color:#2563eb; }
    .btnSecondary { background:#fff; color:#111; }

    .headingPreview { margin-top:10px; border:1px dashed #e5e7ea; border-radius:10px; padding:10px; }
    .headingPreview h1, .headingPreview h2, .headingPreview h3 { margin:0; }

    /* columns2 preview */
    .colsPreview { margin-top:10px; border:1px dashed #e5e7ea; border-radius:10px; padding:10px; display:grid; gap:10px; }
    .colsPreview .cell { background:#fafafa; border:1px solid #eee; border-radius:10px; padding:10px; min-height:48px; }
    .colsPreview pre { margin:0; background:transparent; border:none; padding:0; }

    /* gallery preview */
    .galPrev { margin-top:10px; display:grid; gap:10px; }
    .galPrev img { width:100%; height:auto; display:block; border-radius:10px; border:1px solid #eee; background:#fafafa; }
    .galPick { margin-top:10px; max-height:260px; overflow:auto; border:1px solid #e5e7ea; border-radius:10px; padding:10px; background:#fff; }
    .galPick .row { display:flex; justify-content:flex-start; gap:10px; align-items:center; margin:6px 0; }
    .galPick small { color:#6a737f; }

    /* cards builder */
    .cardsBuilder { margin-top:10px; }
    .cardsBuilder .item { border:1px solid #e5e7ea; border-radius:12px; padding:10px; margin-top:10px; background:#fff; }
    .cardsBuilder .itemHead { display:flex; gap:8px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .cardsBuilder .miniBtns { display:flex; gap:6px; flex-wrap:wrap; }
    .cardsBuilder .grid2 { display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
    @media (max-width: 720px){ .cardsBuilder .grid2 { grid-template-columns: 1fr; } }

    /* sections library */
    .secGrid{display:grid;gap:12px;margin-top:12px;}
    @media (min-width: 820px){ .secGrid{grid-template-columns: 1fr 1fr;} }
    .secCard{border:1px solid #e5e7ea;border-radius:14px;background:#fff;padding:12px;}
    .secTitle{font-weight:800;}
    .secMeta{color:#6a737f;font-size:12px;margin-top:4px;}
    .secBtns{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;}
    .secSearch{margin-top:10px;}
  </style>
</head>
<body>
  <div class="top">
    <a href="/local/sitebuilder/index.php">← Назад</a>
    <div class="muted">Редактор блоков</div>
    <div class="muted">|</div>
    <div><b>siteId:</b> <code><?= (int)$siteId ?></code></div>
    <div><b>pageId:</b> <code><?= (int)$pageId ?></code></div>
    <div><b>Статус:</b> <span id="pageStatusBadge" class="muted">...</span></div>

    <div style="flex:1;"></div>

    <a class="ui-btn ui-btn-light" target="_blank"
       href="/local/sitebuilder/view.php?siteId=<?= (int)$siteId ?>&pageId=<?= (int)$pageId ?>">Открыть просмотр</a>
    <button class="ui-btn ui-btn-light" id="btnToggleStatus">Сменить статус</button>
    <a class="ui-btn ui-btn-light" href="javascript:void(0)" id="btnSaveTemplate">Сохранить как шаблон</a>
    <a class="ui-btn ui-btn-light" href="javascript:void(0)" id="btnApplyTemplate">Вставить шаблон</a>
    <a class="ui-btn ui-btn-light" href="javascript:void(0)" id="btnSections">Каталог секций</a>

    <a class="ui-btn ui-btn-light" target="_blank"
       href="/local/sitebuilder/files.php?siteId=<?= (int)$siteId ?>">Файлы</a>
  </div>

  <div class="content">
    <div class="card">
      <div class="row">
        <div class="muted">
          Блоки: <b>Text</b>, <b>Image</b>, <b>Button</b>, <b>Heading</b>, <b>Columns2</b>, <b>Gallery</b>, <b>Spacer</b>, <b>Card</b>.
        </div>
        <div class="btns">
          <button class="ui-btn ui-btn-primary" id="btnAddText">+ Text</button>
          <button class="ui-btn ui-btn-primary" id="btnAddImage">+ Image</button>
          <button class="ui-btn ui-btn-primary" id="btnAddButton">+ Button</button>
          <button class="ui-btn ui-btn-primary" id="btnAddHeading">+ Heading</button>
          <button class="ui-btn ui-btn-primary" id="btnAddCols2">+ Columns2</button>
          <button class="ui-btn ui-btn-primary" id="btnAddGallery">+ Gallery</button>
          <button class="ui-btn ui-btn-primary" id="btnAddSpacer">+ Spacer</button>
          <button class="ui-btn ui-btn-primary" id="btnAddCard">+ Card</button>
          <button class="ui-btn ui-btn-primary" id="btnAddCards">+ Cards</button>
        </div>
      </div>

      <div id="blocksBox" style="margin-top:12px;"></div>
    </div>
  </div>

<script>
BX.ready(function () {
  const siteId = <?= (int)$siteId ?>;
  const pageId = <?= (int)$pageId ?>;

  const blocksBox = document.getElementById('blocksBox');

  const btnAddText = document.getElementById('btnAddText');
  const btnAddImage = document.getElementById('btnAddImage');
  const btnAddButton = document.getElementById('btnAddButton');
  const btnAddHeading = document.getElementById('btnAddHeading');
  const btnAddCols2 = document.getElementById('btnAddCols2');
  const btnAddGallery = document.getElementById('btnAddGallery');
  const btnAddSpacer = document.getElementById('btnAddSpacer');
  const btnAddCard = document.getElementById('btnAddCard');
  const btnAddCards = document.getElementById('btnAddCards');

  const btnSaveTemplate = document.getElementById('btnSaveTemplate');
  const btnApplyTemplate = document.getElementById('btnApplyTemplate');
  const btnSections = document.getElementById('btnSections');

  const btnToggleStatus = document.getElementById('btnToggleStatus');
  const pageStatusBadge = document.getElementById('pageStatusBadge');

  let pageMeta = null;

function saveTemplateFromPage(){
  BX.UI.Dialogs.MessageBox.show({
    title:'Сохранить как шаблон',
    message:`<div class="field"><label>Название шаблона</label><input id="tpl_name" class="input" placeholder="например: Обложка + преимущества"></div>`,
    buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
    onOk: function(mb){
      const name = (document.getElementById('tpl_name')?.value || '').trim();
      if(!name){ notify('Введите название'); return; }
      api('template.createFromPage', { siteId, pageId, name }).then(r=>{
        if(!r || r.ok!==true){ notify('Не удалось сохранить шаблон'); return; }
        notify('Шаблон сохранён');
        mb.close();
      }).catch(()=>notify('Ошибка template.createFromPage'));
    }
  });
}

async function applyTemplateToPage(){
  let res;
  try { res = await api('template.list', {}); } catch(e){ notify('Ошибка template.list'); return; }
  if(!res || res.ok!==true){ notify('Не удалось получить шаблоны'); return; }
  const list = res.templates || [];
  if(!list.length){ notify('Шаблонов нет. Сначала сохрани один.'); return; }

  const opts = list.map(t=>`<option value="${t.id}">${BX.util.htmlspecialchars(t.name)} (id ${t.id})</option>`).join('');
  BX.UI.Dialogs.MessageBox.show({
    title:'Вставить шаблон',
    message:`
      <div class="field">
        <label>Шаблон</label>
        <select id="tpl_id" class="input">${opts}</select>
      </div>
      <div class="field">
        <label>Режим</label>
        <select id="tpl_mode" class="input">
          <option value="append">Добавить в конец</option>
          <option value="replace">Заменить страницу</option>
        </select>
      </div>
    `,
    buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
    onOk: function(mb){
      const templateId = parseInt(document.getElementById('tpl_id')?.value || '0', 10);
      const mode = document.getElementById('tpl_mode')?.value || 'append';
      if(!templateId){ notify('Выбери шаблон'); return; }

      api('template.applyToPage', { siteId, pageId, templateId, mode }).then(r=>{
        if(!r || r.ok!==true){ notify('Не удалось применить шаблон'); return; }
        notify('Готово: добавлено блоков ' + (r.added || 0));
        mb.close();
        loadBlocks();
      }).catch(()=>notify('Ошибка template.applyToPage'));
    }
  });
}

  // ===== helpers =====

  function notify(msg) {
    BX.UI.Notification.Center.notify({ content: msg });
  }

  function api(action, data) {
    return new Promise((resolve, reject) => {
      BX.ajax({
        url: '/local/sitebuilder/api.php',
        method: 'POST',
        dataType: 'json',
        data: Object.assign({ action, sessid: BX.bitrix_sessid() }, data || {}),
        onsuccess: resolve,
        onfailure: reject
      });
    });
  }

  function fileDownloadUrl(fileId) {
    return `/local/sitebuilder/download.php?siteId=${siteId}&fileId=${fileId}`;
  }

  async function loadPageMeta() {
    const res = await api('page.list', { siteId });
    if (!res || res.ok !== true) throw new Error('page.list failed');
  
    const pages = res.pages || [];
    pageMeta = pages.find(p => parseInt(p.id, 10) === pageId) || null;
  
    const status = (pageMeta && pageMeta.status) ? String(pageMeta.status).toUpperCase() : 'DRAFT';
  
    if (pageStatusBadge) {
      pageStatusBadge.textContent = status;
      pageStatusBadge.style.color = (status === 'PUBLISHED') ? '#15803d' : '#b45309';
      pageStatusBadge.style.fontWeight = '700';
    }
  
    if (btnToggleStatus) {
      btnToggleStatus.textContent = (status === 'PUBLISHED')
        ? 'Сделать DRAFT'
        : 'Опубликовать';
    }
  }

  function togglePageStatus() {
    if (!pageMeta) {
      notify('Страница ещё не загружена');
      return;
    }
  
    const currentStatus = String(pageMeta.status || 'DRAFT').toUpperCase();
    const nextStatus = (currentStatus === 'PUBLISHED') ? 'DRAFT' : 'PUBLISHED';
  
    api('page.setStatus', { id: pageId, status: nextStatus })
      .then(res => {
        if (!res || res.ok !== true) {
          notify('Не удалось сменить статус: ' + (res?.error || 'UNKNOWN'));
          console.log('page.setStatus failed', res);
          return;
        }
        notify('Статус изменён: ' + nextStatus);
        loadPageMeta();
      })
      .catch(err => {
        console.log('page.setStatus error', err);
        notify('Ошибка page.setStatus');
      });
  }

  async function getFilesForSite() {
    const res = await api('file.list', { siteId });
    if (!res || res.ok !== true) throw new Error(res?.error || 'file.list failed');
    return res.files || [];
  }

  function btnClass(variant) {
    return (variant === 'secondary') ? 'btnPreview btnSecondary' : 'btnPreview btnPrimary';
  }

  function headingTag(level) {
    return (level === 'h1' || level === 'h2' || level === 'h3') ? level : 'h2';
  }

  function headingAlign(align) {
    return (align === 'left' || align === 'center' || align === 'right') ? align : 'left';
  }

  function colsGridTemplate(ratio) {
    if (ratio === '33-67') return '1fr 2fr';
    if (ratio === '67-33') return '2fr 1fr';
    return '1fr 1fr';
  }

  function galleryTemplate(columns) {
    if (columns === 2) return '1fr 1fr';
    if (columns === 4) return '1fr 1fr 1fr 1fr';
    return '1fr 1fr 1fr';
  }

  // ===== CARDS BUILDER (визуальный конструктор) =====
    function cardsNormalizeItem(it) {
    const x = (it && typeof it === 'object') ? it : {};
    return {
        title: (x.title || '').toString(),
        text: (x.text || '').toString(),
        imageFileId: parseInt(x.imageFileId || 0, 10) || 0,
        buttonText: (x.buttonText || '').toString(),
        buttonUrl: (x.buttonUrl || '').toString(),
    };
    }

    function cardsRenderBuilderItems(items, files) {
    const fileOptions = (selectedId) => {
        const opts = ['<option value="0">— без картинки —</option>'];
        files.forEach(f => {
        const s = (parseInt(f.id,10) === selectedId) ? 'selected' : '';
        opts.push(`<option value="${f.id}" ${s}>${BX.util.htmlspecialchars(f.name)} (id ${f.id})</option>`);
        });
        return opts.join('');
    };

    return items.map((it, idx) => {
        const title = BX.util.htmlspecialchars(it.title || '');
        const text = BX.util.htmlspecialchars(it.text || '');
        const btnText = BX.util.htmlspecialchars(it.buttonText || '');
        const btnUrl = BX.util.htmlspecialchars(it.buttonUrl || '');
        const imgId = parseInt(it.imageFileId || 0, 10) || 0;

        const imgPrev = imgId ? `<div class="imgPrev"><img src="${fileDownloadUrl(imgId)}" alt=""></div>` : '';

        return `
        <div class="item" data-ci="${idx}">
            <div class="itemHead">
            <div><b>Карточка ${idx + 1}</b></div>
            <div class="miniBtns">
                <button class="ui-btn ui-btn-light ui-btn-xs" data-card-up="${idx}">↑</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-card-down="${idx}">↓</button>
                <button class="ui-btn ui-btn-danger ui-btn-xs" data-card-del="${idx}">Удалить</button>
            </div>
            </div>

            <div class="grid2" style="margin-top:10px;">
            <div>
                <div class="field">
                <label>Заголовок</label>
                <input class="input" data-card-title="${idx}" value="${title}">
                </div>
                <div class="field">
                <label>Текст</label>
                <textarea class="input" data-card-text="${idx}" style="height:120px;">${text}</textarea>
                </div>
            </div>

            <div>
                <div class="field">
                <label>Картинка</label>
                <select class="input" data-card-img="${idx}">
                    ${fileOptions(imgId)}
                </select>
                </div>
                <div data-card-img-prev="${idx}">${imgPrev}</div>

                <div class="field">
                <label>Текст кнопки (опц.)</label>
                <input class="input" data-card-btntext="${idx}" value="${btnText}">
                </div>
                <div class="field">
                <label>URL кнопки (опц.)</label>
                <input class="input" data-card-btnurl="${idx}" value="${btnUrl}">
                </div>
            </div>
            </div>
        </div>
        `;
    }).join('');
    }

    async function openCardsBuilderDialog(mode, blockId, currentContent) {
    let cols = currentContent?.columns ? parseInt(currentContent.columns, 10) : 3;
    if (![2,3,4].includes(cols)) cols = 3;

    let items = Array.isArray(currentContent?.items) ? currentContent.items.map(cardsNormalizeItem) : [];
    if (!items.length) items = [
        cardsNormalizeItem({title:'Преимущество 1', text:'Короткое описание'}),
        cardsNormalizeItem({title:'Преимущество 2', text:'Короткое описание'}),
        cardsNormalizeItem({title:'Преимущество 3', text:'Короткое описание'}),
    ];

    let files = [];
    try { files = await getFilesForSite(); } catch(e) { files = []; }

    const render = () => `
        <div class="cardsBuilder">
        <div class="field">
            <label>Колонки</label>
            <select id="cb_cols" class="input">
            <option value="2" ${cols===2?'selected':''}>2</option>
            <option value="3" ${cols===3?'selected':''}>3</option>
            <option value="4" ${cols===4?'selected':''}>4</option>
            </select>
        </div>

        <div style="margin-top:10px;">
            <button class="ui-btn ui-btn-light" id="cb_add">+ Добавить карточку</button>
        </div>

        <div id="cb_items">${cardsRenderBuilderItems(items, files)}</div>
        <div class="muted" style="margin-top:10px;">Минимум у карточки должен быть заголовок.</div>
        </div>
    `;

    BX.UI.Dialogs.MessageBox.show({
        title: mode === 'edit' ? ('Редактировать Cards #' + blockId) : 'Новый Cards блок',
        message: render(),
        buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
        onOk: function(mb){
        cols = parseInt(document.getElementById('cb_cols')?.value || '3', 10);
        if (![2,3,4].includes(cols)) { notify('columns должен быть 2/3/4'); return; }

        const collected = items.map((_, idx) => {
            const title = (document.querySelector(`[data-card-title="${idx}"]`)?.value || '').trim();
            const text = (document.querySelector(`[data-card-text="${idx}"]`)?.value || '');
            const imageFileId = parseInt(document.querySelector(`[data-card-img="${idx}"]`)?.value || '0', 10) || 0;
            const buttonText = (document.querySelector(`[data-card-btntext="${idx}"]`)?.value || '').trim();
            const buttonUrl = (document.querySelector(`[data-card-btnurl="${idx}"]`)?.value || '').trim();
            return { title, text, imageFileId, buttonText, buttonUrl };
        }).filter(x => x.title !== '');

        if (!collected.length) { notify('Добавь хотя бы одну карточку с заголовком'); return; }

        const payload = { columns: cols, items: JSON.stringify(collected) };
        const call = (mode === 'edit')
            ? api('block.update', Object.assign({ id: blockId }, payload))
            : api('block.create', Object.assign({ pageId, type:'cards' }, payload));

        call.then(res => {
            if (!res || res.ok !== true) { notify('Не удалось сохранить cards'); return; }
            notify(mode === 'edit' ? 'Сохранено' : 'Cards создан');
            mb.close(); loadBlocks();
        }).catch(() => notify('Ошибка запроса cards'));
        }
    });

    setTimeout(() => {
        const root = document.querySelector('.cardsBuilder');
        if (!root) return;

        const snapshot = () => {
        items = items.map((it, idx) => ({
            title: (document.querySelector(`[data-card-title="${idx}"]`)?.value || it.title || ''),
            text: (document.querySelector(`[data-card-text="${idx}"]`)?.value || it.text || ''),
            imageFileId: parseInt(document.querySelector(`[data-card-img="${idx}"]`)?.value || it.imageFileId || 0, 10) || 0,
            buttonText: (document.querySelector(`[data-card-btntext="${idx}"]`)?.value || it.buttonText || ''),
            buttonUrl: (document.querySelector(`[data-card-btnurl="${idx}"]`)?.value || it.buttonUrl || ''),
        }));
        cols = parseInt(document.getElementById('cb_cols')?.value || String(cols), 10);
        if (![2,3,4].includes(cols)) cols = 3;
        };

        const rerender = () => {
        snapshot();
        root.innerHTML = render();
        bind();
        };

        const bind = () => {
        const addBtn = document.getElementById('cb_add');
        if (addBtn) addBtn.onclick = () => { snapshot(); items.push(cardsNormalizeItem({ title: 'Новая карточка' })); rerender(); };

        root.querySelectorAll('[data-card-up]').forEach(btn => {
            btn.onclick = () => { snapshot(); const i = parseInt(btn.getAttribute('data-card-up'), 10);
            if (i > 0) { [items[i-1], items[i]] = [items[i], items[i-1]]; rerender(); }
            };
        });
        root.querySelectorAll('[data-card-down]').forEach(btn => {
            btn.onclick = () => { snapshot(); const i = parseInt(btn.getAttribute('data-card-down'), 10);
            if (i < items.length - 1) { [items[i+1], items[i]] = [items[i], items[i+1]]; rerender(); }
            };
        });
        root.querySelectorAll('[data-card-del]').forEach(btn => {
            btn.onclick = () => { snapshot(); const i = parseInt(btn.getAttribute('data-card-del'), 10);
            items.splice(i, 1); if (!items.length) items.push(cardsNormalizeItem({ title: 'Новая карточка' })); rerender();
            };
        });

        root.querySelectorAll('select[data-card-img]').forEach(sel => {
            sel.onchange = () => {
            const idx = parseInt(sel.getAttribute('data-card-img'), 10);
            const fid = parseInt(sel.value || '0', 10);
            const box = root.querySelector(`[data-card-img-prev="${idx}"]`);
            if (!box) return;
            box.innerHTML = fid ? `<div class="imgPrev"><img src="${fileDownloadUrl(fid)}" alt=""></div>` : '';
            };
        });
        };

        bind();
    }, 0);
    }

  // ===== render =====

  function renderBlocks(blocks) {
    if (!blocks || !blocks.length) {
      blocksBox.innerHTML = '<div class="muted">Блоков нет. Добавь первый.</div>';
      return;
    }

    blocksBox.innerHTML = blocks.map(b => {
      const type = b.type || '';
      const sort = b.sort;
      const id = b.id;

      // TEXT
      if (type === 'text') {
        const text = (b.content && typeof b.content.text === 'string') ? b.content.text : '';
        return `
          <div class="block">
            <div class="row">
              <div><b>#${id}</b> <span class="muted">(text | sort ${sort})</span></div>
              <div class="btns">
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="up">↑</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="down">↓</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-text-id="${id}">Редактировать</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-dup-block-id="${id}">Дублировать</button>
                <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
              </div>
            </div>
            <pre>${BX.util.htmlspecialchars(text)}</pre>
          </div>
        `;
      }

      // IMAGE
      if (type === 'image') {
        const fileId = b.content && b.content.fileId ? parseInt(b.content.fileId, 10) : 0;
        const alt = b.content && typeof b.content.alt === 'string' ? b.content.alt : '';
        const img = fileId
          ? `<div class="imgPrev"><img src="${fileDownloadUrl(fileId)}" alt="${BX.util.htmlspecialchars(alt)}"></div>`
          : '<div class="muted" style="margin-top:10px;">Файл не выбран</div>';

        return `
          <div class="block">
            <div class="row">
              <div><b>#${id}</b> <span class="muted">(image | sort ${sort} | fileId ${fileId || '-'})</span></div>
              <div class="btns">
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="up">↑</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="down">↓</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-image-id="${id}">Редактировать</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-dup-block-id="${id}">Дублировать</button>
                <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
              </div>
            </div>
            <div class="muted" style="margin-top:8px;">alt: ${BX.util.htmlspecialchars(alt)}</div>
            ${img}
          </div>
        `;
      }

      // BUTTON
      if (type === 'button') {
        const text = (b.content && typeof b.content.text === 'string') ? b.content.text : '';
        const url = (b.content && typeof b.content.url === 'string') ? b.content.url : '';
        const variant = (b.content && typeof b.content.variant === 'string') ? b.content.variant : 'primary';

        return `
          <div class="block">
            <div class="row">
              <div><b>#${id}</b> <span class="muted">(button | sort ${sort} | ${BX.util.htmlspecialchars(variant)})</span></div>
              <div class="btns">
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="up">↑</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="down">↓</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-button-id="${id}">Редактировать</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-dup-block-id="${id}">Дублировать</button>
                <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
              </div>
            </div>
            <div class="muted" style="margin-top:8px;">url: ${BX.util.htmlspecialchars(url)}</div>
            <a class="${btnClass(variant)}" href="${BX.util.htmlspecialchars(url)}" target="_blank" rel="noopener noreferrer">
              ${BX.util.htmlspecialchars(text)}
            </a>
          </div>
        `;
      }

      // HEADING
      if (type === 'heading') {
        const text = (b.content && typeof b.content.text === 'string') ? b.content.text : '';
        const level = (b.content && typeof b.content.level === 'string') ? b.content.level : 'h2';
        const align = (b.content && typeof b.content.align === 'string') ? b.content.align : 'left';
        const tag = headingTag(level);
        const al = headingAlign(align);

        return `
          <div class="block">
            <div class="row">
              <div><b>#${id}</b> <span class="muted">(heading | sort ${sort} | ${BX.util.htmlspecialchars(tag)} | ${BX.util.htmlspecialchars(al)})</span></div>
              <div class="btns">
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="up">↑</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="down">↓</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-heading-id="${id}">Редактировать</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-dup-block-id="${id}">Дублировать</button>
                <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
              </div>
            </div>
            <div class="headingPreview" style="text-align:${BX.util.htmlspecialchars(al)};">
              <${tag}>${BX.util.htmlspecialchars(text)}</${tag}>
            </div>
          </div>
        `;
      }

      // COLUMNS2
      if (type === 'columns2') {
        const left = (b.content && typeof b.content.left === 'string') ? b.content.left : '';
        const right = (b.content && typeof b.content.right === 'string') ? b.content.right : '';
        const ratio = (b.content && typeof b.content.ratio === 'string') ? b.content.ratio : '50-50';
        const tpl = colsGridTemplate(ratio);

        return `
          <div class="block">
            <div class="row">
              <div><b>#${id}</b> <span class="muted">(columns2 | sort ${sort} | ratio ${BX.util.htmlspecialchars(ratio)})</span></div>
              <div class="btns">
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="up">↑</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="down">↓</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-cols2-id="${id}">Редактировать</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-dup-block-id="${id}">Дублировать</button>
                <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
              </div>
            </div>
            <div class="colsPreview" style="grid-template-columns:${tpl};">
              <div class="cell"><pre>${BX.util.htmlspecialchars(left)}</pre></div>
              <div class="cell"><pre>${BX.util.htmlspecialchars(right)}</pre></div>
            </div>
          </div>
        `;
      }

      // GALLERY
      if (type === 'gallery') {
        const columns = (b.content && b.content.columns) ? parseInt(b.content.columns, 10) : 3;
        const imgs = (b.content && Array.isArray(b.content.images)) ? b.content.images : [];
        const tpl = galleryTemplate(columns);

        const prev = imgs.map(it => {
          const fid = parseInt(it.fileId || 0, 10);
          if (!fid) return '';
          return `<img src="${fileDownloadUrl(fid)}" alt="${BX.util.htmlspecialchars(it.alt || '')}">`;
        }).join('');

        return `
          <div class="block">
            <div class="row">
              <div><b>#${id}</b> <span class="muted">(gallery | sort ${sort} | cols ${columns} | images ${imgs.length})</span></div>
              <div class="btns">
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="up">↑</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="down">↓</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-gallery-id="${id}">Редактировать</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-dup-block-id="${id}">Дублировать</button>
                <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
              </div>
            </div>
            <div class="galPrev" style="grid-template-columns:${tpl};">${prev}</div>
          </div>
        `;
      }

      // SPACER
      if (type === 'spacer') {
        const height = (b.content && b.content.height) ? parseInt(b.content.height, 10) : 40;
        const line = (b.content && (b.content.line === true || b.content.line === 'true')) ? true : false;

        return `
          <div class="block">
            <div class="row">
              <div><b>#${id}</b> <span class="muted">(spacer | sort ${sort} | ${height}px | line ${line ? 'yes' : 'no'})</span></div>
              <div class="btns">
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="up">↑</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="down">↓</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-spacer-id="${id}">Редактировать</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-dup-block-id="${id}">Дублировать</button>
                <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
              </div>
            </div>

            <div style="margin-top:10px; border:1px dashed #e5e7ea; border-radius:10px; padding:10px;">
              <div style="height:${height}px; position:relative; background:#fafafa; border-radius:10px;">
                ${line ? '<div style="position:absolute; left:0; right:0; top:50%; height:1px; background:#e5e7ea;"></div>' : ''}
              </div>
            </div>
          </div>
        `;
      }

      // CARD
      if (type === 'card') {
        const title = (b.content && typeof b.content.title === 'string') ? b.content.title : '';
        const text = (b.content && typeof b.content.text === 'string') ? b.content.text : '';
        const imageFileId = (b.content && b.content.imageFileId) ? parseInt(b.content.imageFileId, 10) : 0;
        const buttonText = (b.content && typeof b.content.buttonText === 'string') ? b.content.buttonText : '';
        const buttonUrl = (b.content && typeof b.content.buttonUrl === 'string') ? b.content.buttonUrl : '';

        const img = imageFileId ? `<div class="imgPrev"><img src="${fileDownloadUrl(imageFileId)}" alt=""></div>` : '';

        return `
          <div class="block">
            <div class="row">
              <div><b>#${id}</b> <span class="muted">(card | sort ${sort})</span></div>
              <div class="btns">
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="up">↑</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="down">↓</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-card-id="${id}">Редактировать</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-dup-block-id="${id}">Дублировать</button>
                <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
              </div>
            </div>

            <div style="margin-top:10px;">
              <div style="font-weight:700;">${BX.util.htmlspecialchars(title)}</div>
              <div class="muted" style="margin-top:6px; white-space:pre-wrap;">${BX.util.htmlspecialchars(text)}</div>
              ${img}
              ${buttonUrl ? `<a class="${btnClass('secondary')}" href="${BX.util.htmlspecialchars(buttonUrl)}" target="_blank" rel="noopener noreferrer">${BX.util.htmlspecialchars(buttonText || 'Открыть')}</a>` : ''}
            </div>
          </div>
        `;
      }

      // CARDS
      if (type === 'cards') {
        const columns = (b.content && b.content.columns) ? parseInt(b.content.columns, 10) : 3;
        const items = (b.content && Array.isArray(b.content.items)) ? b.content.items : [];
        return `
            <div class="block">
            <div class="row">
                <div><b>#${id}</b> <span class="muted">(cards | sort ${sort} | cols ${columns} | items ${items.length})</span></div>
                <div class="btns">
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="up">↑</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="down">↓</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-cards-id="${id}">Редактировать</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-dup-block-id="${id}">Дублировать</button>
                <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
                </div>
            </div>
            <div class="muted" style="margin-top:8px;">Пока редактирование через JSON (быстро). Потом сделаем визуальный конструктор.</div>
            <pre>${BX.util.htmlspecialchars(JSON.stringify({columns, items}, null, 2))}</pre>
            </div>
        `;
      }



      // UNKNOWN
      return `
        <div class="block">
          <div class="row">
            <div><b>#${id}</b> <span class="muted">(unknown)</span></div>
            <div class="btns">
              <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
            </div>
          </div>
          <div class="muted" style="margin-top:10px;">Неизвестный тип: ${BX.util.htmlspecialchars(type)}</div>
        </div>
      `;
    }).join('');
  }

  function loadBlocks() {
    api('block.list', { pageId }).then(res => {
      if (!res || res.ok !== true) {
        notify('Не удалось загрузить блоки');
        return;
      }
      renderBlocks(res.blocks);
    }).catch(() => notify('Ошибка block.list'));
  }

  async function openSectionsLibrary() {
    let res;
    try { res = await api('template.list', {}); }
    catch (e) { notify('Ошибка template.list'); return; }

    if (!res || res.ok !== true) { notify('Не удалось получить шаблоны'); return; }

    let templates = res.templates || [];
    if (!templates.length) { notify('Шаблонов нет. Сначала сохрани страницу как шаблон.'); return; }

    const containerId = 'sb_sections_root_' + Date.now();

    const render = (q) => {
        const query = (q || '').trim().toLowerCase();
        const filtered = templates.filter(t => ((t.name || '') + '').toLowerCase().includes(query));

        const cards = filtered.map(t => {
        const blocksCount = Array.isArray(t.blocks) ? t.blocks.length : 0;
        const createdAt = (t.createdAt || '').replace('T',' ').replace('Z','');

        return `
            <div class="secCard">
            <div class="secTitle">${BX.util.htmlspecialchars(t.name || ('Template #' + t.id))}</div>
            <div class="secMeta">id: ${t.id} • блоков: ${blocksCount} • создан: ${BX.util.htmlspecialchars(createdAt)}</div>

            <div class="secBtns">
                <button class="ui-btn ui-btn-primary ui-btn-xs" data-tpl-apply="${t.id}" data-mode="append">Вставить</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-tpl-apply="${t.id}" data-mode="replace">Заменить</button>
                <button class="ui-btn ui-btn-light ui-btn-xs" data-tpl-rename="${t.id}">Переименовать</button>
                <button class="ui-btn ui-btn-danger ui-btn-xs" data-tpl-delete="${t.id}">Удалить</button>
            </div>
            </div>
        `;
        }).join('');

        return `
        <div id="${containerId}">
            <div class="secSearch">
            <input id="${containerId}_q" class="input" placeholder="Поиск шаблонов..." value="${BX.util.htmlspecialchars(q || '')}">
            </div>
            <div class="secGrid">${cards || '<div class="muted">Ничего не найдено</div>'}</div>
        </div>
        `;
    };

    BX.UI.Dialogs.MessageBox.show({
        title: 'Каталог секций',
        message: render(''),
        buttons: BX.UI.Dialogs.MessageBoxButtons.CANCEL,
        onCancel: function (mb) { mb.close(); }
    });

    setTimeout(() => {
        const root = document.getElementById(containerId);
        if (!root) {
        notify('Каталог секций: не найден контейнер (проверь messagebox DOM)');
        return;
        }

        const rerender = (q) => {
        root.outerHTML = render(q);
        const newRoot = document.getElementById(containerId);
        if (!newRoot) return;

        // заново вешаем обработчики на новый корень
        bind(newRoot);
        };

        const bind = (r) => {
        // поиск
        const q = document.getElementById(containerId + '_q');
        if (q) q.oninput = () => rerender(q.value);

        // делегирование кликов по кнопкам в карточках
        r.onclick = async (e) => {
            const applyBtn = e.target.closest('[data-tpl-apply]');
            if (applyBtn) {
            const tplId = parseInt(applyBtn.getAttribute('data-tpl-apply'), 10);
            const mode = applyBtn.getAttribute('data-mode') || 'append';
            try {
                const r2 = await api('template.applyToPage', { siteId, pageId, templateId: tplId, mode });
                if (!r2 || r2.ok !== true) { notify('Не удалось применить'); return; }
                notify('Готово: добавлено блоков ' + (r2.added || 0));
                loadBlocks();
            } catch (err) {
                notify('Ошибка apply');
            }
            return;
            }

            const renameBtn = e.target.closest('[data-tpl-rename]');
            if (renameBtn) {
            const tplId = parseInt(renameBtn.getAttribute('data-tpl-rename'), 10);
            const cur = templates.find(x => parseInt(x.id, 10) === tplId);

            BX.UI.Dialogs.MessageBox.show({
                title: 'Переименовать',
                message: `<div class="field"><label>Название</label><input id="rn_name" class="input" value="${BX.util.htmlspecialchars(cur?.name || '')}"></div>`,
                buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
                onOk: function (mb2) {
                const name = (document.getElementById('rn_name')?.value || '').trim();
                if (!name) { notify('Введите название'); return; }

                api('template.rename', { id: tplId, name }).then(r3 => {
                    if (!r3 || r3.ok !== true) { notify('Не удалось переименовать'); return; }
                    notify('Переименовано');
                    const idx = templates.findIndex(x => parseInt(x.id, 10) === tplId);
                    if (idx >= 0) templates[idx].name = name;
                    mb2.close();
                    const qv = document.getElementById(containerId + '_q')?.value || '';
                    rerender(qv);
                }).catch(() => notify('Ошибка template.rename'));
                }
            });
            return;
            }

            const delBtn = e.target.closest('[data-tpl-delete]');
            if (delBtn) {
            const tplId = parseInt(delBtn.getAttribute('data-tpl-delete'), 10);

            BX.UI.Dialogs.MessageBox.show({
                title: 'Удалить шаблон?',
                message: 'Удалить навсегда?',
                buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
                onOk: function (mb2) {
                api('template.delete', { id: tplId }).then(r4 => {
                    if (!r4 || r4.ok !== true) { notify('Не удалось удалить'); return; }
                    notify('Удалено');
                    templates = templates.filter(x => parseInt(x.id, 10) !== tplId);
                    mb2.close();
                    const qv = document.getElementById(containerId + '_q')?.value || '';
                    rerender(qv);
                }).catch(() => notify('Ошибка template.delete'));
                }
            });
            return;
            }
        };
        };

        bind(root);
    }, 0);
  }

  // ===== add blocks =====

  function addTextBlock() {
    BX.UI.Dialogs.MessageBox.show({
      title: 'Новый Text блок',
      message: '<textarea id="new_text" style="width:100%;height:140px;padding:8px;border:1px solid #d0d7de;border-radius:8px;"></textarea>',
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function (mb) {
        const text = document.getElementById('new_text')?.value ?? '';
        api('block.create', { pageId, type: 'text', text })
          .then(res => {
            if (!res || res.ok !== true) { notify('Не удалось создать блок'); return; }
            notify('Блок создан');
            mb.close();
            loadBlocks();
          })
          .catch(() => notify('Ошибка block.create'));
      }
    });
  }

  function addImageBlock() {
    BX.UI.Dialogs.MessageBox.show({
      title: 'Новый Image блок',
      message: `
        <div>
          <div class="muted">Выбери файл из “Файлы” этого сайта.</div>
          <div class="field">
            <label>Файл</label>
            <select id="img_file" class="input">
              <option value="">Загрузка списка...</option>
            </select>
          </div>
          <div class="field">
            <label>ALT</label>
            <input id="img_alt" class="input" placeholder="например: Логотип" />
          </div>
          <div id="img_preview" class="imgPrev" style="display:none;">
            <img id="img_preview_img" src="" alt="">
          </div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function (mb) {
        const fileId = parseInt(document.getElementById('img_file')?.value || '0', 10);
        const alt = (document.getElementById('img_alt')?.value || '').trim();
        if (!fileId) { notify('Выбери файл'); return; }

        api('block.create', { pageId, type: 'image', fileId, alt })
          .then(res => {
            if (!res || res.ok !== true) { notify('Не удалось создать image-блок'); return; }
            notify('Image-блок создан');
            mb.close();
            loadBlocks();
          })
          .catch(() => notify('Ошибка block.create (image)'));
      }
    });

    setTimeout(async function () {
      const sel = document.getElementById('img_file');
      if (!sel) return;

      try {
        const files = await getFilesForSite();
        if (!files.length) { sel.innerHTML = '<option value="">Файлов нет (загрузите в “Файлы”)</option>'; return; }

        sel.innerHTML = '<option value="">— Выберите файл —</option>' + files.map(f =>
          `<option value="${f.id}">${BX.util.htmlspecialchars(f.name)} (${f.id})</option>`
        ).join('');

        sel.addEventListener('change', function () {
          const id = parseInt(sel.value || '0', 10);
          const wrap = document.getElementById('img_preview');
          const img = document.getElementById('img_preview_img');
          if (!wrap || !img) return;
          if (!id) { wrap.style.display = 'none'; img.src = ''; return; }
          wrap.style.display = 'block';
          img.src = fileDownloadUrl(id);
        });
      } catch (e) {
        sel.innerHTML = '<option value="">Ошибка загрузки файлов</option>';
        notify('Не удалось получить список файлов');
      }
    }, 0);
  }

  function addButtonBlock() {
    BX.UI.Dialogs.MessageBox.show({
      title: 'Новый Button блок',
      message: `
        <div>
          <div class="field">
            <label>Текст кнопки</label>
            <input id="btn_text" class="input" placeholder="например: Купить" />
          </div>
          <div class="field">
            <label>URL</label>
            <input id="btn_url" class="input" placeholder="https://... или /local/..." />
          </div>
          <div class="field">
            <label>Вариант</label>
            <select id="btn_variant" class="input">
              <option value="primary">primary</option>
              <option value="secondary">secondary</option>
            </select>
          </div>
          <div class="muted" style="margin-top:10px;">Превью:</div>
          <a id="btn_preview" class="btnPreview btnPrimary" href="#" target="_blank" rel="noopener noreferrer">Кнопка</a>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function (mb) {
        const text = (document.getElementById('btn_text')?.value || '').trim();
        const url  = (document.getElementById('btn_url')?.value || '').trim();
        const variant = (document.getElementById('btn_variant')?.value || 'primary');

        if (!text) { notify('Введите текст'); return; }
        if (!url)  { notify('Введите URL'); return; }

        api('block.create', { pageId, type: 'button', text, url, variant })
          .then(res => {
            if (!res || res.ok !== true) { notify('Не удалось создать button-блок'); return; }
            notify('Button-блок создан');
            mb.close();
            loadBlocks();
          })
          .catch(() => notify('Ошибка block.create (button)'));
      }
    });

    setTimeout(() => {
      const t = document.getElementById('btn_text');
      const u = document.getElementById('btn_url');
      const v = document.getElementById('btn_variant');
      const p = document.getElementById('btn_preview');
      if (!t || !u || !v || !p) return;

      const update = () => {
        p.textContent = t.value || 'Кнопка';
        p.href = u.value || '#';
        p.className = (v.value === 'secondary') ? 'btnPreview btnSecondary' : 'btnPreview btnPrimary';
      };

      t.addEventListener('input', update);
      u.addEventListener('input', update);
      v.addEventListener('change', update);
      update();
    }, 0);
  }

  function addHeadingBlock() {
    BX.UI.Dialogs.MessageBox.show({
      title: 'Новый Heading блок',
      message: `
        <div>
          <div class="field">
            <label>Текст</label>
            <input id="h_text" class="input" placeholder="например: О нас" />
          </div>
          <div class="field">
            <label>Уровень</label>
            <select id="h_level" class="input">
              <option value="h1">h1</option>
              <option value="h2" selected>h2</option>
              <option value="h3">h3</option>
            </select>
          </div>
          <div class="field">
            <label>Выравнивание</label>
            <select id="h_align" class="input">
              <option value="left" selected>left</option>
              <option value="center">center</option>
              <option value="right">right</option>
            </select>
          </div>
          <div class="muted" style="margin-top:10px;">Превью:</div>
          <div id="h_preview_wrap" class="headingPreview" style="text-align:left;"><h2 id="h_preview">Заголовок</h2></div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function (mb) {
        const text = (document.getElementById('h_text')?.value || '').trim();
        const level = (document.getElementById('h_level')?.value || 'h2');
        const align = (document.getElementById('h_align')?.value || 'left');

        if (!text) { notify('Введите текст'); return; }

        api('block.create', { pageId, type:'heading', text, level, align })
          .then(res => {
            if (!res || res.ok !== true) { notify('Не удалось создать heading'); return; }
            notify('Heading создан');
            mb.close();
            loadBlocks();
          })
          .catch(() => notify('Ошибка block.create (heading)'));
      }
    });

    setTimeout(() => {
      const t = document.getElementById('h_text');
      const l = document.getElementById('h_level');
      const a = document.getElementById('h_align');
      const wrap = document.getElementById('h_preview_wrap');
      const prev = document.getElementById('h_preview');
      if (!t || !l || !a || !wrap || !prev) return;

      const update = () => {
        const txt = t.value || 'Заголовок';
        const tag = headingTag(l.value);
        const al = headingAlign(a.value);
        wrap.style.textAlign = al;
        prev.outerHTML = `<${tag} id="h_preview">${BX.util.htmlspecialchars(txt)}</${tag}>`;
      };

      t.addEventListener('input', update);
      l.addEventListener('change', update);
      a.addEventListener('change', update);
      update();
    }, 0);
  }

  function addCols2Block() {
    BX.UI.Dialogs.MessageBox.show({
      title: 'Новый Columns2 блок',
      message: `
        <div>
          <div class="field">
            <label>Соотношение</label>
            <select id="c_ratio" class="input">
              <option value="50-50" selected>50 / 50</option>
              <option value="33-67">33 / 67</option>
              <option value="67-33">67 / 33</option>
            </select>
          </div>
          <div class="field">
            <label>Левая колонка (текст)</label>
            <textarea id="c_left" class="input" style="height:120px;"></textarea>
          </div>
          <div class="field">
            <label>Правая колонка (текст)</label>
            <textarea id="c_right" class="input" style="height:120px;"></textarea>
          </div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function (mb) {
        const ratio = (document.getElementById('c_ratio')?.value || '50-50');
        const left = (document.getElementById('c_left')?.value || '');
        const right = (document.getElementById('c_right')?.value || '');

        api('block.create', { pageId, type:'columns2', ratio, left, right })
          .then(res => {
            if (!res || res.ok !== true) { notify('Не удалось создать columns2'); return; }
            notify('Columns2 создан');
            mb.close();
            loadBlocks();
          })
          .catch(() => notify('Ошибка block.create (columns2)'));
      }
    });
  }

  function addSpacerBlock() {
    BX.UI.Dialogs.MessageBox.show({
      title: 'Новый Spacer блок',
      message: `
        <div>
          <div class="field">
            <label>Высота (10..200 px)</label>
            <input id="sp_h" class="input" type="number" min="10" max="200" value="40" />
          </div>
          <div class="field">
            <label><input id="sp_line" type="checkbox" /> Рисовать линию</label>
          </div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function(mb){
        const height = parseInt(document.getElementById('sp_h')?.value || '40', 10);
        const line = document.getElementById('sp_line')?.checked ? '1' : '0';

        api('block.create', { pageId, type:'spacer', height, line })
          .then(res => {
            if (!res || res.ok !== true) { notify('Не удалось создать spacer'); return; }
            notify('Spacer создан');
            mb.close();
            loadBlocks();
          })
          .catch(() => notify('Ошибка block.create (spacer)'));
      }
    });
  }

  async function openGalleryDialog(mode, blockId, currentContent) {
    const currentCols = currentContent?.columns ? parseInt(currentContent.columns, 10) : 3;
    const currentImages = Array.isArray(currentContent?.images) ? currentContent.images : [];

    BX.UI.Dialogs.MessageBox.show({
      title: mode === 'edit' ? ('Редактировать Gallery #' + blockId) : 'Новый Gallery блок',
      message: `
        <div>
          <div class="field">
            <label>Колонки</label>
            <select id="g_cols" class="input">
              <option value="2" ${currentCols===2?'selected':''}>2</option>
              <option value="3" ${currentCols===3?'selected':''}>3</option>
              <option value="4" ${currentCols===4?'selected':''}>4</option>
            </select>
          </div>

          <div class="muted" style="margin-top:8px;">Выбери файлы из “Файлы” сайта:</div>
          <div id="g_list" class="galPick">Загрузка списка...</div>

          <div class="muted" style="margin-top:10px;">Превью:</div>
          <div id="g_prev" class="galPrev"></div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function(mb){
        const cols = parseInt(document.getElementById('g_cols')?.value || '3', 10);
        const list = document.getElementById('g_list');
        if (!list) return;

        const checks = list.querySelectorAll('input[type="checkbox"][data-fid]');
        const selected = [];
        checks.forEach(ch => {
          if (!ch.checked) return;
          const fid = parseInt(ch.getAttribute('data-fid'), 10);
          const altEl = list.querySelector(`input[data-alt-for="${fid}"]`);
          const alt = (altEl?.value || '').trim();
          if (fid) selected.push({ fileId: fid, alt });
        });

        if (!selected.length) { notify('Выбери хотя бы 1 файл'); return; }

        const images = JSON.stringify(selected);
        const payload = { columns: cols, images };

        const call = (mode === 'edit')
          ? api('block.update', Object.assign({ id: blockId }, payload))
          : api('block.create', Object.assign({ pageId, type:'gallery' }, payload));

        call.then(res => {
          if (!res || res.ok !== true) { notify('Не удалось сохранить gallery'); return; }
          notify(mode==='edit' ? 'Сохранено' : 'Gallery создан');
          mb.close();
          loadBlocks();
        }).catch(() => notify('Ошибка запроса gallery'));
      }
    });

    setTimeout(async () => {
      const box = document.getElementById('g_list');
      const prev = document.getElementById('g_prev');
      const colsSel = document.getElementById('g_cols');
      if (!box || !prev || !colsSel) return;

      const selectedMap = {};
      currentImages.forEach(it => { selectedMap[parseInt(it.fileId,10)] = (it.alt || ''); });

      try {
        const files = await getFilesForSite();
        if (!files.length) { box.innerHTML = '<div class="muted">Файлов нет (загрузите в “Файлы”)</div>'; return; }

        box.innerHTML = files.map(f => {
          const checked = selectedMap[f.id] !== undefined ? 'checked' : '';
          const altVal = selectedMap[f.id] !== undefined ? selectedMap[f.id] : '';
          return `
            <div class="row">
              <input type="checkbox" data-fid="${f.id}" ${checked}>
              <div style="flex:1;">
                <div><b>${BX.util.htmlspecialchars(f.name)}</b> <small>(id ${f.id})</small></div>
                <input class="input" style="margin-top:6px;" data-alt-for="${f.id}" placeholder="alt (опционально)" value="${BX.util.htmlspecialchars(altVal)}">
              </div>
            </div>
          `;
        }).join('');

        const renderPrev = () => {
          const cols = parseInt(colsSel.value || '3', 10);
          prev.style.gridTemplateColumns = galleryTemplate(cols);

          const checks = box.querySelectorAll('input[type="checkbox"][data-fid]');
          let html = '';
          checks.forEach(ch => {
            if (!ch.checked) return;
            const fid = parseInt(ch.getAttribute('data-fid'), 10);
            if (!fid) return;
            html += `<img src="${fileDownloadUrl(fid)}" alt="">`;
          });
          prev.innerHTML = html || '<div class="muted">Ничего не выбрано</div>';
        };

        box.addEventListener('change', renderPrev);
        colsSel.addEventListener('change', renderPrev);
        renderPrev();
      } catch (e) {
        box.innerHTML = '<div class="muted">Ошибка загрузки файлов</div>';
      }
    }, 0);
  }

  function addGalleryBlock() { openGalleryDialog('create', 0, null); }

  async function openCardDialog(mode, blockId, current) {
    const curTitle = current?.title || '';
    const curText = current?.text || '';
    const curImage = current?.imageFileId ? parseInt(current.imageFileId, 10) : 0;
    const curBtnText = current?.buttonText || '';
    const curBtnUrl = current?.buttonUrl || '';

    BX.UI.Dialogs.MessageBox.show({
      title: mode === 'edit' ? ('Редактировать Card #' + blockId) : 'Новый Card блок',
      message: `
        <div>
          <div class="field">
            <label>Заголовок</label>
            <input id="c_title" class="input" value="${BX.util.htmlspecialchars(curTitle)}">
          </div>
          <div class="field">
            <label>Текст</label>
            <textarea id="c_text" class="input" style="height:120px;">${BX.util.htmlspecialchars(curText)}</textarea>
          </div>

          <div class="field">
            <label>Картинка (из файлов сайта, опционально)</label>
            <select id="c_img" class="input"><option value="">Загрузка списка...</option></select>
          </div>
          <div id="c_img_prev" class="imgPrev" style="display:${curImage? 'block':'none'};">
            <img id="c_img_prev_img" src="${curImage ? fileDownloadUrl(curImage) : ''}" alt="">
          </div>

          <div class="field">
            <label>Текст кнопки (опционально)</label>
            <input id="c_btn_text" class="input" value="${BX.util.htmlspecialchars(curBtnText)}" placeholder="например: Подробнее">
          </div>
          <div class="field">
            <label>URL кнопки (опционально)</label>
            <input id="c_btn_url" class="input" value="${BX.util.htmlspecialchars(curBtnUrl)}" placeholder="https://... или /local/...">
          </div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function(mb) {
        const title = (document.getElementById('c_title')?.value || '').trim();
        const text = (document.getElementById('c_text')?.value || '');
        const imageFileId = parseInt(document.getElementById('c_img')?.value || '0', 10);
        const buttonText = (document.getElementById('c_btn_text')?.value || '').trim();
        const buttonUrl = (document.getElementById('c_btn_url')?.value || '').trim();

        if (!title) { notify('Введите заголовок'); return; }

        const payload = { title, text, imageFileId, buttonText, buttonUrl };
        const call = (mode === 'edit')
          ? api('block.update', Object.assign({ id: blockId }, payload))
          : api('block.create', Object.assign({ pageId, type:'card' }, payload));

        call.then(res => {
          if (!res || res.ok !== true) { notify('Не удалось сохранить card'); return; }
          notify(mode === 'edit' ? 'Сохранено' : 'Card создан');
          mb.close();
          loadBlocks();
        }).catch(() => notify('Ошибка запроса card'));
      }
    });

    setTimeout(async () => {
      const sel = document.getElementById('c_img');
      const prevWrap = document.getElementById('c_img_prev');
      const prevImg = document.getElementById('c_img_prev_img');
      if (!sel || !prevWrap || !prevImg) return;

      try {
        const files = await getFilesForSite();
        sel.innerHTML = '<option value="0">— без картинки —</option>' + files.map(f => {
          const s = (parseInt(f.id,10) === curImage) ? 'selected' : '';
          return `<option value="${f.id}" ${s}>${BX.util.htmlspecialchars(f.name)} (id ${f.id})</option>`;
        }).join('');

        const updatePrev = () => {
          const fid = parseInt(sel.value || '0', 10);
          if (!fid) { prevWrap.style.display = 'none'; prevImg.src = ''; return; }
          prevWrap.style.display = 'block';
          prevImg.src = fileDownloadUrl(fid);
        };
        sel.addEventListener('change', updatePrev);
        updatePrev();
      } catch (e) {
        sel.innerHTML = '<option value="0">Ошибка загрузки файлов</option>';
      }
    }, 0);
  }

  function addCardBlock() { openCardDialog('create', 0, null); }

  // ===== edit blocks =====

  function editTextBlock(id) {
    api('block.list', { pageId }).then(res => {
      if (!res || res.ok !== true) return;
      const blk = (res.blocks || []).find(x => parseInt(x.id,10) === id);
      const current = blk && blk.content ? (blk.content.text || '') : '';

      BX.UI.Dialogs.MessageBox.show({
        title: 'Редактировать Text #' + id,
        message: `<textarea id="edit_text" style="width:100%;height:160px;padding:8px;border:1px solid #d0d7de;border-radius:8px;">${BX.util.htmlspecialchars(current)}</textarea>`,
        buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
        onOk: function (mb) {
          const text = document.getElementById('edit_text')?.value ?? '';
          api('block.update', { id, text })
            .then(r => {
              if (!r || r.ok !== true) { notify('Не удалось сохранить'); return; }
              notify('Сохранено');
              mb.close();
              loadBlocks();
            })
            .catch(() => notify('Ошибка block.update'));
        }
      });
    });
  }

  function editImageBlock(id) {
    api('block.list', { pageId }).then(res => {
      if (!res || res.ok !== true) return;
      const blk = (res.blocks || []).find(x => parseInt(x.id,10) === id);
      const curFileId = blk && blk.content ? parseInt(blk.content.fileId || 0, 10) : 0;
      const curAlt = blk && blk.content ? (blk.content.alt || '') : '';

      BX.UI.Dialogs.MessageBox.show({
        title: 'Редактировать Image #' + id,
        message: `
          <div>
            <div class="field">
              <label>Файл</label>
              <select id="edit_img_file" class="input">
                <option value="">Загрузка списка...</option>
              </select>
            </div>
            <div class="field">
              <label>ALT</label>
              <input id="edit_img_alt" class="input" value="${BX.util.htmlspecialchars(curAlt)}" />
            </div>
            <div id="edit_img_preview" class="imgPrev" style="display:${curFileId ? 'block':'none'};">
              <img id="edit_img_preview_img" src="${curFileId ? fileDownloadUrl(curFileId) : ''}" alt="">
            </div>
          </div>
        `,
        buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
        onOk: function (mb) {
          const fileId = parseInt(document.getElementById('edit_img_file')?.value || '0', 10);
          const alt = (document.getElementById('edit_img_alt')?.value || '').trim();
          if (!fileId) { notify('Выбери файл'); return; }

          api('block.update', { id, fileId, alt })
            .then(r => {
              if (!r || r.ok !== true) { notify('Не удалось сохранить image-блок'); return; }
              notify('Сохранено');
              mb.close();
              loadBlocks();
            })
            .catch(() => notify('Ошибка block.update (image)'));
        }
      });

      setTimeout(async function () {
        const sel = document.getElementById('edit_img_file');
        if (!sel) return;

        try {
          const files = await getFilesForSite();
          if (!files.length) { sel.innerHTML = '<option value="">Файлов нет</option>'; return; }

          sel.innerHTML = '<option value="">— Выберите файл —</option>' + files.map(f => {
            const selected = (parseInt(f.id,10) === curFileId) ? 'selected' : '';
            return `<option value="${f.id}" ${selected}>${BX.util.htmlspecialchars(f.name)} (${f.id})</option>`;
          }).join('');

          sel.addEventListener('change', function () {
            const fid = parseInt(sel.value || '0', 10);
            const wrap = document.getElementById('edit_img_preview');
            const img = document.getElementById('edit_img_preview_img');
            if (!wrap || !img) return;
            if (!fid) { wrap.style.display = 'none'; img.src = ''; return; }
            wrap.style.display = 'block';
            img.src = fileDownloadUrl(fid);
          });
        } catch (e) {
          sel.innerHTML = '<option value="">Ошибка загрузки файлов</option>';
          notify('Не удалось получить список файлов');
        }
      }, 0);
    });
  }

  function editButtonBlock(id) {
    api('block.list', { pageId }).then(res => {
      if (!res || res.ok !== true) return;
      const blk = (res.blocks || []).find(x => parseInt(x.id,10) === id);

      const curText = blk && blk.content ? (blk.content.text || '') : '';
      const curUrl = blk && blk.content ? (blk.content.url || '') : '';
      const curVariant = blk && blk.content ? (blk.content.variant || 'primary') : 'primary';

      BX.UI.Dialogs.MessageBox.show({
        title: 'Редактировать Button #' + id,
        message: `
          <div>
            <div class="field">
              <label>Текст</label>
              <input id="edit_btn_text" class="input" value="${BX.util.htmlspecialchars(curText)}" />
            </div>
            <div class="field">
              <label>URL</label>
              <input id="edit_btn_url" class="input" value="${BX.util.htmlspecialchars(curUrl)}" />
            </div>
            <div class="field">
              <label>Вариант</label>
              <select id="edit_btn_variant" class="input">
                <option value="primary" ${curVariant === 'primary' ? 'selected' : ''}>primary</option>
                <option value="secondary" ${curVariant === 'secondary' ? 'selected' : ''}>secondary</option>
              </select>
            </div>
            <div class="muted" style="margin-top:10px;">Превью:</div>
            <a id="edit_btn_preview" class="${btnClass(curVariant)}" href="${BX.util.htmlspecialchars(curUrl)}" target="_blank" rel="noopener noreferrer">
              ${BX.util.htmlspecialchars(curText || 'Кнопка')}
            </a>
          </div>
        `,
        buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
        onOk: function (mb) {
          const text = (document.getElementById('edit_btn_text')?.value || '').trim();
          const url  = (document.getElementById('edit_btn_url')?.value || '').trim();
          const variant = (document.getElementById('edit_btn_variant')?.value || 'primary');

          if (!text) { notify('Введите текст'); return; }
          if (!url)  { notify('Введите URL'); return; }

          api('block.update', { id, text, url, variant })
            .then(r => {
              if (!r || r.ok !== true) { notify('Не удалось сохранить button-блок'); return; }
              notify('Сохранено');
              mb.close();
              loadBlocks();
            })
            .catch(() => notify('Ошибка block.update (button)'));
        }
      });

      setTimeout(() => {
        const t = document.getElementById('edit_btn_text');
        const u = document.getElementById('edit_btn_url');
        const v = document.getElementById('edit_btn_variant');
        const p = document.getElementById('edit_btn_preview');
        if (!t || !u || !v || !p) return;

        const update = () => {
          p.textContent = t.value || 'Кнопка';
          p.href = u.value || '#';
          p.className = btnClass(v.value);
        };

        t.addEventListener('input', update);
        u.addEventListener('input', update);
        v.addEventListener('change', update);
        update();
      }, 0);
    });
  }

  function editHeadingBlock(id) {
    api('block.list', { pageId }).then(res => {
      if (!res || res.ok !== true) return;
      const blk = (res.blocks || []).find(x => parseInt(x.id,10) === id);

      const curText = blk && blk.content ? (blk.content.text || '') : '';
      const curLevel = blk && blk.content ? (blk.content.level || 'h2') : 'h2';
      const curAlign = blk && blk.content ? (blk.content.align || 'left') : 'left';

      BX.UI.Dialogs.MessageBox.show({
        title: 'Редактировать Heading #' + id,
        message: `
          <div>
            <div class="field">
              <label>Текст</label>
              <input id="edit_h_text" class="input" value="${BX.util.htmlspecialchars(curText)}" />
            </div>
            <div class="field">
              <label>Уровень</label>
              <select id="edit_h_level" class="input">
                <option value="h1" ${curLevel === 'h1' ? 'selected' : ''}>h1</option>
                <option value="h2" ${curLevel === 'h2' ? 'selected' : ''}>h2</option>
                <option value="h3" ${curLevel === 'h3' ? 'selected' : ''}>h3</option>
              </select>
            </div>
            <div class="field">
              <label>Выравнивание</label>
              <select id="edit_h_align" class="input">
                <option value="left" ${curAlign === 'left' ? 'selected' : ''}>left</option>
                <option value="center" ${curAlign === 'center' ? 'selected' : ''}>center</option>
                <option value="right" ${curAlign === 'right' ? 'selected' : ''}>right</option>
              </select>
            </div>
            <div class="muted" style="margin-top:10px;">Превью:</div>
            <div id="edit_h_preview_wrap" class="headingPreview" style="text-align:${BX.util.htmlspecialchars(curAlign)};">
              <${headingTag(curLevel)} id="edit_h_preview">${BX.util.htmlspecialchars(curText || 'Заголовок')}</${headingTag(curLevel)}>
            </div>
          </div>
        `,
        buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
        onOk: function (mb) {
          const text = (document.getElementById('edit_h_text')?.value || '').trim();
          const level = (document.getElementById('edit_h_level')?.value || 'h2');
          const align = (document.getElementById('edit_h_align')?.value || 'left');

          if (!text) { notify('Введите текст'); return; }

          api('block.update', { id, text, level, align })
            .then(r => {
              if (!r || r.ok !== true) { notify('Не удалось сохранить heading'); return; }
              notify('Сохранено');
              mb.close();
              loadBlocks();
            })
            .catch(() => notify('Ошибка block.update (heading)'));
        }
      });

      setTimeout(() => {
        const t = document.getElementById('edit_h_text');
        const l = document.getElementById('edit_h_level');
        const a = document.getElementById('edit_h_align');
        const wrap = document.getElementById('edit_h_preview_wrap');
        if (!t || !l || !a || !wrap) return;

        const update = () => {
          const txt = t.value || 'Заголовок';
          const tag = headingTag(l.value);
          const al = headingAlign(a.value);
          wrap.style.textAlign = al;
          wrap.innerHTML = `<${tag} id="edit_h_preview">${BX.util.htmlspecialchars(txt)}</${tag}>`;
        };

        t.addEventListener('input', update);
        l.addEventListener('change', update);
        a.addEventListener('change', update);
        update();
      }, 0);
    });
  }

  function editCols2Block(id) {
    api('block.list', { pageId }).then(res => {
      if (!res || res.ok !== true) return;
      const blk = (res.blocks || []).find(x => parseInt(x.id,10) === id);

      const curRatio = blk && blk.content ? (blk.content.ratio || '50-50') : '50-50';
      const curLeft = blk && blk.content ? (blk.content.left || '') : '';
      const curRight = blk && blk.content ? (blk.content.right || '') : '';

      BX.UI.Dialogs.MessageBox.show({
        title: 'Редактировать Columns2 #' + id,
        message: `
          <div>
            <div class="field">
              <label>Соотношение</label>
              <select id="ec_ratio" class="input">
                <option value="50-50" ${curRatio==='50-50'?'selected':''}>50 / 50</option>
                <option value="33-67" ${curRatio==='33-67'?'selected':''}>33 / 67</option>
                <option value="67-33" ${curRatio==='67-33'?'selected':''}>67 / 33</option>
              </select>
            </div>
            <div class="field">
              <label>Левая колонка (текст)</label>
              <textarea id="ec_left" class="input" style="height:120px;">${BX.util.htmlspecialchars(curLeft)}</textarea>
            </div>
            <div class="field">
              <label>Правая колонка (текст)</label>
              <textarea id="ec_right" class="input" style="height:120px;">${BX.util.htmlspecialchars(curRight)}</textarea>
            </div>
          </div>
        `,
        buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
        onOk: function (mb) {
          const ratio = (document.getElementById('ec_ratio')?.value || '50-50');
          const left = (document.getElementById('ec_left')?.value || '');
          const right = (document.getElementById('ec_right')?.value || '');

          api('block.update', { id, ratio, left, right })
            .then(r => {
              if (!r || r.ok !== true) { notify('Не удалось сохранить columns2'); return; }
              notify('Сохранено');
              mb.close();
              loadBlocks();
            })
            .catch(() => notify('Ошибка block.update (columns2)'));
        }
      });
    });
  }

  function editSpacerBlock(id) {
    api('block.list', { pageId }).then(res => {
      if (!res || res.ok !== true) return;
      const blk = (res.blocks || []).find(x => parseInt(x.id,10) === id);
      const curH = blk && blk.content ? parseInt(blk.content.height || 40, 10) : 40;
      const curLine = blk && blk.content ? (blk.content.line === true || blk.content.line === 'true') : false;

      BX.UI.Dialogs.MessageBox.show({
        title: 'Редактировать Spacer #' + id,
        message: `
          <div>
            <div class="field">
              <label>Высота (10..200 px)</label>
              <input id="esp_h" class="input" type="number" min="10" max="200" value="${curH}" />
            </div>
            <div class="field">
              <label><input id="esp_line" type="checkbox" ${curLine ? 'checked':''} /> Рисовать линию</label>
            </div>
          </div>
        `,
        buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
        onOk: function(mb){
          const height = parseInt(document.getElementById('esp_h')?.value || String(curH), 10);
          const line = document.getElementById('esp_line')?.checked ? '1' : '0';

          api('block.update', { id, height, line })
            .then(r => {
              if (!r || r.ok !== true) { notify('Не удалось сохранить spacer'); return; }
              notify('Сохранено');
              mb.close();
              loadBlocks();
            })
            .catch(() => notify('Ошибка block.update (spacer)'));
        }
      });
    });
  }

  function editGalleryBlock(id) {
    api('block.list', { pageId }).then(res => {
      if (!res || res.ok !== true) return;
      const blk = (res.blocks || []).find(x => parseInt(x.id,10) === id);
      openGalleryDialog('edit', id, blk?.content || null);
    });
  }

  function editCardBlock(id) {
    api('block.list', { pageId }).then(res => {
      if (!res || res.ok !== true) return;
      const blk = (res.blocks || []).find(x => parseInt(x.id,10) === id);
      openCardDialog('edit', id, blk?.content || null);
    });
  }

  function openCardsJsonDialog(mode, blockId, currentContent) {
    const cur = currentContent && typeof currentContent === 'object' ? currentContent : { columns: 3, items: [] };
    const json = JSON.stringify(cur, null, 2);

    BX.UI.Dialogs.MessageBox.show({
        title: mode === 'edit' ? ('Редактировать Cards #' + blockId) : 'Новый Cards блок',
        message: `
        <div class="muted">Формат: { columns: 2|3|4, items: [ {title,text,imageFileId,buttonText,buttonUrl}, ... ] }</div>
        <textarea id="cards_json" class="input" style="height:260px; font-family:monospace;">${BX.util.htmlspecialchars(json)}</textarea>
        `,
        buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
        onOk: function(mb){
        let obj = null;
        try {
            obj = JSON.parse(document.getElementById('cards_json')?.value || '');
        } catch (e) {
            notify('JSON не валиден');
            return;
        }

        const columns = parseInt(obj.columns || 3, 10);
        if (![2,3,4].includes(columns)) { notify('columns должен быть 2/3/4'); return; }

        const items = Array.isArray(obj.items) ? obj.items : [];
        if (!items.length) { notify('items пустой'); return; }

        const payload = { columns, items: JSON.stringify(items) };

        const call = (mode === 'edit')
            ? api('block.update', Object.assign({ id: blockId }, payload))
            : api('block.create', Object.assign({ pageId, type:'cards' }, payload));

        call.then(res => {
            if (!res || res.ok !== true) { notify('Не удалось сохранить cards'); return; }
            notify(mode === 'edit' ? 'Сохранено' : 'Cards создан');
            mb.close(); loadBlocks();
        }).catch(() => notify('Ошибка запроса cards'));
        }
    });
    }

    function addCardsBlock() {
        openCardsBuilderDialog('create', 0, { columns: 3, items: [
            { title: 'Преимущество 1', text: 'Короткое описание' },
            { title: 'Преимущество 2', text: 'Короткое описание' },
            { title: 'Преимущество 3', text: 'Короткое описание' }
        ]});
    }

    function editCardsBlock(id) {
        api('block.list', { pageId }).then(res => {
            if (!res || res.ok !== true) return;
            const blk = (res.blocks || []).find(x => parseInt(x.id,10) === id);
            openCardsBuilderDialog('edit', id, blk?.content || null);
        }).catch(() => notify('Ошибка block.list'));
    }

  // ===== common click handlers =====

  document.addEventListener('click', function (e) {
    const mvBtn = e.target.closest('[data-move-block-id]');
    if (mvBtn) {
      const id = parseInt(mvBtn.getAttribute('data-move-block-id'), 10);
      const dir = mvBtn.getAttribute('data-move-dir');
      api('block.move', { id, dir })
        .then(r => {
          if (!r || r.ok !== true) { notify('Не удалось переместить блок'); return; }
          loadBlocks();
        })
        .catch(() => notify('Ошибка block.move'));
      return;
    }

    const dupBtn = e.target.closest('[data-dup-block-id]');
    if (dupBtn) {
      const id = parseInt(dupBtn.getAttribute('data-dup-block-id'), 10);

      api('block.duplicate', { id })
        .then(r => {
          if (!r || r.ok !== true) {
            notify('Не удалось дублировать блок');
            return;
          }
          notify('Блок продублирован');
          loadBlocks();
        })
        .catch(() => notify('Ошибка block.duplicate'));
      return;
    }

    const delBtn = e.target.closest('[data-del-block-id]');
    if (delBtn) {
      const id = parseInt(delBtn.getAttribute('data-del-block-id'), 10);
      BX.UI.Dialogs.MessageBox.show({
        title: 'Удалить блок #' + id + '?',
        message: 'Продолжить?',
        buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
        onOk: function (mb) {
          api('block.delete', { id })
            .then(r => {
              if (!r || r.ok !== true) { notify('Не удалось удалить блок'); return; }
              notify('Удалено');
              mb.close();
              loadBlocks();
            })
            .catch(() => notify('Ошибка block.delete'));
        }
      });
      return;
    }

    const et = e.target.closest('[data-edit-text-id]');
    if (et) { editTextBlock(parseInt(et.getAttribute('data-edit-text-id'), 10)); return; }

    const ei = e.target.closest('[data-edit-image-id]');
    if (ei) { editImageBlock(parseInt(ei.getAttribute('data-edit-image-id'), 10)); return; }

    const eb = e.target.closest('[data-edit-button-id]');
    if (eb) { editButtonBlock(parseInt(eb.getAttribute('data-edit-button-id'), 10)); return; }

    const eh = e.target.closest('[data-edit-heading-id]');
    if (eh) { editHeadingBlock(parseInt(eh.getAttribute('data-edit-heading-id'), 10)); return; }

    const ecol = e.target.closest('[data-edit-cols2-id]');
    if (ecol) { editCols2Block(parseInt(ecol.getAttribute('data-edit-cols2-id'), 10)); return; }

    const egal = e.target.closest('[data-edit-gallery-id]');
    if (egal) { editGalleryBlock(parseInt(egal.getAttribute('data-edit-gallery-id'), 10)); return; }

    const esp = e.target.closest('[data-edit-spacer-id]');
    if (esp) { editSpacerBlock(parseInt(esp.getAttribute('data-edit-spacer-id'), 10)); return; }

    const ecard = e.target.closest('[data-edit-card-id]');
    if (ecard) { editCardBlock(parseInt(ecard.getAttribute('data-edit-card-id'), 10)); return; }

    const ecards = e.target.closest('[data-edit-cards-id]');
    if (ecards) { editCardsBlock(parseInt(ecards.getAttribute('data-edit-cards-id'), 10)); return; }
  });

  // ===== buttons =====
  btnAddText.addEventListener('click', addTextBlock);
  btnAddImage.addEventListener('click', addImageBlock);
  btnAddButton.addEventListener('click', addButtonBlock);
  btnAddHeading.addEventListener('click', addHeadingBlock);
  btnAddCols2.addEventListener('click', addCols2Block);
  btnAddGallery.addEventListener('click', addGalleryBlock);
  btnAddSpacer.addEventListener('click', addSpacerBlock);
  btnAddCard.addEventListener('click', addCardBlock);
  btnAddCards.addEventListener('click', addCardsBlock);
  btnSections.addEventListener('click', openSectionsLibrary);
  btnSaveTemplate.addEventListener('click', saveTemplateFromPage);
  btnApplyTemplate.addEventListener('click', applyTemplateToPage);
  if (btnToggleStatus) btnToggleStatus.addEventListener('click', togglePageStatus);

  loadBlocks();
  loadPageMeta();
});
</script>
</body>
</html>