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

    .top {
      position: sticky;
      top: 0;
      z-index: 50;
      background: rgba(255,255,255,.94);
      backdrop-filter: blur(10px);
      border-bottom:1px solid #e5e7ea;
      padding:12px 16px;
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
    }

    .topActions {
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      align-items:center;
    }

    .toolbarSearch {
      min-width:220px;
      flex:1;
      max-width:360px;
    }

    .content { padding: 18px; }
    .card { background:#fff; border:1px solid #e5e7ea; border-radius:12px; padding:16px; }
    .muted { color:#6a737f; }
    a { color:#0b57d0; text-decoration:none; }
    a:hover { text-decoration:underline; }
    code { background:#f3f4f6; padding:2px 6px; border-radius:6px; }
    pre { white-space:pre-wrap; margin:10px 0 0; background:#f9fafb; border:1px solid #eee; border-radius:8px; padding:10px; }

    .row { display:flex; gap:8px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .btns { display:flex; gap:6px; flex-wrap:wrap; }

    .field { margin-top:10px; }
    .field label { display:block; font-size:12px; color:#6a737f; margin-bottom:4px; }
    .input, select, textarea { width:100%; padding:8px; border:1px solid #d0d7de; border-radius:8px; box-sizing:border-box; }

    .imgPrev { margin-top:10px; max-width:420px; border:1px solid #eee; border-radius:10px; overflow:hidden; background:#fafafa; }
    .imgPrev img { display:block; width:100%; height:auto; }

    .btnPreview { margin-top:10px; display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid #e5e7ea; text-decoration:none; }
    .btnPrimary { background:#2563eb; color:#fff; border-color:#2563eb; }
    .btnSecondary { background:#fff; color:#111; }

    .headingPreview { margin-top:10px; border:1px dashed #e5e7ea; border-radius:10px; padding:10px; }
    .headingPreview h1, .headingPreview h2, .headingPreview h3 { margin:0; }

    .colsPreview { margin-top:10px; border:1px dashed #e5e7ea; border-radius:10px; padding:10px; display:grid; gap:10px; }
    .colsPreview .cell { background:#fafafa; border:1px solid #eee; border-radius:10px; padding:10px; min-height:48px; }
    .colsPreview pre { margin:0; background:transparent; border:none; padding:0; }

    .galPrev { margin-top:10px; display:grid; gap:10px; }
    .galPrev img { width:100%; height:auto; display:block; border-radius:10px; border:1px solid #eee; background:#fafafa; }
    .galPick { margin-top:10px; max-height:260px; overflow:auto; border:1px solid #e5e7ea; border-radius:10px; padding:10px; background:#fff; }
    .galPick .row { display:flex; justify-content:flex-start; gap:10px; align-items:center; margin:6px 0; }
    .galPick small { color:#6a737f; }

    .cardsBuilder { margin-top:10px; }
    .cardsBuilder .item { border:1px solid #e5e7ea; border-radius:12px; padding:10px; margin-top:10px; background:#fff; }
    .cardsBuilder .itemHead { display:flex; gap:8px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .cardsBuilder .miniBtns { display:flex; gap:6px; flex-wrap:wrap; }
    .cardsBuilder .grid2 { display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
    @media (max-width: 720px){ .cardsBuilder .grid2 { grid-template-columns: 1fr; } }

    .secGrid{display:grid;gap:12px;margin-top:12px;}
    @media (min-width: 820px){ .secGrid{grid-template-columns: 1fr 1fr;} }
    .secCard{border:1px solid #e5e7ea;border-radius:14px;background:#fff;padding:12px;}
    .secTitle{font-weight:800;}
    .secMeta{color:#6a737f;font-size:12px;margin-top:4px;}
    .secBtns{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;}
    .secSearch{margin-top:10px;}

    .block {
      border:1px solid #e5e7ea;
      border-radius:14px;
      padding:12px;
      margin-top:12px;
      background:#fff;
      box-shadow: 0 1px 2px rgba(0,0,0,.03);
    }

    .blockHeader {
      display:flex;
      gap:10px;
      align-items:flex-start;
      justify-content:space-between;
      flex-wrap:wrap;
    }

    .blockLeft {
      display:flex;
      flex-direction:column;
      gap:6px;
      min-width:220px;
    }

    .blockTitleRow {
      display:flex;
      gap:8px;
      align-items:center;
      flex-wrap:wrap;
    }

    .blockTypeBadge {
      display:inline-flex;
      align-items:center;
      padding:3px 8px;
      border-radius:999px;
      font-size:11px;
      font-weight:700;
      border:1px solid #e5e7ea;
      background:#f8fafc;
      color:#334155;
    }

    .blockMeta {
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      color:#6a737f;
      font-size:12px;
    }

    .blockBody {
      margin-top:10px;
    }

    .blockCollapsed .blockBody {
      display:none;
    }

    .dragHandle {
      cursor: grab;
      user-select: none;
      font-weight: 700;
      color:#64748b;
      padding:2px 6px;
      border-radius:8px;
      border:1px solid #e5e7ea;
      background:#f8fafc;
    }

    .dragHandle:active {
      cursor: grabbing;
    }

    .block.dragging {
      opacity: .55;
      border-color:#93c5fd;
    }

    .block[data-type="text"] .blockTypeBadge { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
    .block[data-type="image"] .blockTypeBadge { background:#f5f3ff; color:#6d28d9; border-color:#ddd6fe; }
    .block[data-type="button"] .blockTypeBadge { background:#ecfeff; color:#0f766e; border-color:#a5f3fc; }
    .block[data-type="heading"] .blockTypeBadge { background:#fef3c7; color:#92400e; border-color:#fcd34d; }
    .block[data-type="columns2"] .blockTypeBadge { background:#f3f4f6; color:#374151; border-color:#d1d5db; }
    .block[data-type="gallery"] .blockTypeBadge { background:#fdf2f8; color:#be185d; border-color:#fbcfe8; }
    .block[data-type="spacer"] .blockTypeBadge { background:#f8fafc; color:#475569; border-color:#cbd5e1; }
    .block[data-type="card"] .blockTypeBadge { background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }
    .block[data-type="cards"] .blockTypeBadge { background:#ecfccb; color:#3f6212; border-color:#bef264; }

    .blockSection{
      border:1px solid #cbd5e1;
      background:linear-gradient(180deg,#f8fafc 0%, #f1f5f9 100%);
    }

    .blockSectionBadge{
      display:inline-flex;
      align-items:center;
      padding:3px 8px;
      border-radius:999px;
      font-size:11px;
      font-weight:700;
      background:#e2e8f0;
      color:#334155;
      border:1px solid #cbd5e1;
    }

    .blockSectionGrid{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
      gap:8px;
      margin-top:10px;
    }

    .blockSectionItem{
      background:#fff;
      border:1px solid #e2e8f0;
      border-radius:10px;
      padding:8px 10px;
    }

    .blockSectionLabel{
      font-size:11px;
      color:#64748b;
      margin-bottom:4px;
    }

    .blockSectionValue{
      font-size:13px;
      font-weight:600;
      color:#0f172a;
      word-break:break-word;
    }

    .blockInSection{
      margin-left:18px;
      border-left:2px solid #dbeafe;
    }

    .blockInSection .row{
      position:relative;
    }

    .blockInSectionTag{
      display:inline-flex;
      align-items:center;
      padding:2px 8px;
      border-radius:999px;
      font-size:11px;
      font-weight:600;
      background:#eff6ff;
      color:#1d4ed8;
      border:1px solid #bfdbfe;
      margin-left:8px;
    }

    .blockSectionDivider{
      margin-top:8px;
      margin-bottom:4px;
      font-size:11px;
      color:#64748b;
      text-transform:uppercase;
      letter-spacing:.04em;
    }
  </style>
</head>
<body>
  <div class="top">
    <a href="/local/sitebuilder/index.php">← Назад</a>
    <div class="muted">Редактор блоков</div>
    <div class="muted">|</div>
    <div><b>siteId:</b> <code><?= (int)$siteId ?></code></div>
    <div><b>pageId:</b> <code><?= (int)$pageId ?></code></div>

    <div style="flex:1;"></div>

    <div class="toolbarSearch">
      <input class="input" id="blockSearch" placeholder="Поиск по типу блока: text, image, card..." />
    </div>

    <div class="topActions">
      <a class="ui-btn ui-btn-light" target="_blank"
         href="/local/sitebuilder/view.php?siteId=<?= (int)$siteId ?>&pageId=<?= (int)$pageId ?>">Открыть просмотр</a>
      <a class="ui-btn ui-btn-light" href="javascript:void(0)" id="btnSaveTemplate">Сохранить как шаблон</a>
      <a class="ui-btn ui-btn-light" href="javascript:void(0)" id="btnApplyTemplate">Вставить шаблон</a>
      <a class="ui-btn ui-btn-light" href="javascript:void(0)" id="btnSections">Каталог секций</a>
      <a class="ui-btn ui-btn-light" target="_blank"
         href="/local/sitebuilder/files.php?siteId=<?= (int)$siteId ?>">Файлы</a>
    </div>
  </div>

  <div class="content">
    <div class="card">
      <div class="row">
        <div class="muted">
          Блоки: <b>Section</b>, <b>Text</b>, <b>Image</b>, <b>Button</b>, <b>Heading</b>, <b>Columns2</b>, <b>Gallery</b>, <b>Spacer</b>, <b>Card</b>, <b>Cards</b>.
        </div>
        <div class="btns">
          <button class="ui-btn ui-btn-primary" id="btnAddSection">+ Section</button>
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
  const blockSearch = document.getElementById('blockSearch');
  const collapsedBlocks = new Set();

  const btnAddSection = document.getElementById('btnAddSection');
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

  function buildBlockShell(id, type, sort, bodyHtml, buttonsHtml, extraMetaHtml = '', extraClass = '', sectionMarkHtml = '') {
    const isCollapsed = collapsedBlocks.has(id);
    return `
      <div class="block ${isCollapsed ? 'blockCollapsed' : ''} ${extraClass}" data-type="${BX.util.htmlspecialchars(type)}" data-block-id="${id}">
        <div class="blockHeader">
          <div class="blockLeft">
            <div class="blockTitleRow">
              <span class="dragHandle" data-drag-handle="${id}" title="Перетащить">⋮⋮</span>
              <b>#${id}</b>
              <span class="blockTypeBadge">${BX.util.htmlspecialchars(type)}</span>
            </div>
            <div class="blockMeta">
              <span>sort: ${sort}</span>
              ${extraMetaHtml}
            </div>
          </div>
          <div class="btns">
            <button class="ui-btn ui-btn-light ui-btn-xs" data-toggle-block="${id}">${isCollapsed ? 'Развернуть' : 'Свернуть'}</button>
            ${buttonsHtml}
          </div>
        </div>
        <div class="blockBody">
          ${sectionMarkHtml}
          ${bodyHtml}
        </div>
      </div>
    `;
  }

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

  function quickAddHeadingAfterSection(sectionId) {
    createBlockAfterSection(sectionId, 'heading', {
      text: 'Новый заголовок',
      level: 'h2',
      align: 'left'
    });
  }

  function quickAddTextAfterSection(sectionId) {
    createBlockAfterSection(sectionId, 'text', {
      text: 'Новый текст'
    });
  }

  function quickAddButtonAfterSection(sectionId) {
    createBlockAfterSection(sectionId, 'button', {
      text: 'Кнопка',
      url: '/',
      variant: 'primary'
    });
  }

  function quickAddCardsAfterSection(sectionId) {
    createBlockAfterSection(sectionId, 'cards', {
      columns: 3,
      items: JSON.stringify([
        { title: 'Карточка 1', text: 'Описание 1', imageFileId: 0, buttonText: '', buttonUrl: '' },
        { title: 'Карточка 2', text: 'Описание 2', imageFileId: 0, buttonText: '', buttonUrl: '' },
        { title: 'Карточка 3', text: 'Описание 3', imageFileId: 0, buttonText: '', buttonUrl: '' }
      ])
    });
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

  function renderBlocks(blocks) {
    const q = (blockSearch?.value || '').trim().toLowerCase();
    const filteredBlocks = (blocks || []).filter(b => {
      if (!q) return true;
      const type = String(b.type || '').toLowerCase();
      return type.includes(q) || String(b.id || '').includes(q);
    });

    if (!filteredBlocks.length) {
      blocksBox.innerHTML = '<div class="muted">Ничего не найдено.</div>';
      return;
    }
    
    let currentSectionId = null;

    function wrapBlockMeta() {
      if (!currentSectionId) {
        return {
          extraClass: '',
          sectionMarkHtml: ''
        };
      }

      return {
        extraClass: 'blockInSection',
        sectionMarkHtml: `<div class="blockSectionDivider">inside section #${currentSectionId}</div>`
      };
    }

    blocksBox.innerHTML = filteredBlocks.map(b => {
      const type = b.type || '';
      const sort = b.sort;
      const id = b.id;

      const commonBtns = `
        <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="up">↑</button>
        <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="down">↓</button>
      `;


      if (type === 'section') {
        currentSectionId = id;

        const c = (b.content && typeof b.content === 'object') ? b.content : {};
        const boxed = !!c.boxed;
        const background = c.background || '#FFFFFF';
        const paddingTop = parseInt(c.paddingTop || 32, 10);
        const paddingBottom = parseInt(c.paddingBottom || 32, 10);
        const border = !!c.border;
        const radius = parseInt(c.radius || 0, 10);

        return buildBlockShell(
          id,
          type,
          sort,
          `
            <div class="blockSectionGrid">
              <div class="blockSectionItem">
                <div class="blockSectionLabel">Контейнер</div>
                <div class="blockSectionValue">${boxed ? 'Boxed' : 'Full width'}</div>
              </div>

              <div class="blockSectionItem">
                <div class="blockSectionLabel">Фон</div>
                <div class="blockSectionValue">
                  <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:${BX.util.htmlspecialchars(background)};border:1px solid #cbd5e1;vertical-align:-1px;margin-right:6px;"></span>
                  ${BX.util.htmlspecialchars(background)}
                </div>
              </div>

              <div class="blockSectionItem">
                <div class="blockSectionLabel">Отступы</div>
                <div class="blockSectionValue">top ${paddingTop}px / bottom ${paddingBottom}px</div>
              </div>

              <div class="blockSectionItem">
                <div class="blockSectionLabel">Граница</div>
                <div class="blockSectionValue">${border ? 'Да' : 'Нет'}</div>
              </div>

              <div class="blockSectionItem">
                <div class="blockSectionLabel">Скругление</div>
                <div class="blockSectionValue">${radius}px</div>
              </div>
            </div>

            <div class="btns" style="margin-top:10px;">
              <button class="ui-btn ui-btn-success ui-btn-xs" data-add-heading-after-section-id="${id}">+ Heading</button>
              <button class="ui-btn ui-btn-success ui-btn-xs" data-add-text-after-section-id="${id}">+ Text</button>
              <button class="ui-btn ui-btn-success ui-btn-xs" data-add-button-after-section-id="${id}">+ Button</button>
              <button class="ui-btn ui-btn-success ui-btn-xs" data-add-cards-after-section-id="${id}">+ Cards</button>
            </div>
          `,
          `
            ${commonBtns}
            <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-section-id="${id}">Редактировать</button>
            <button class="ui-btn ui-btn-light ui-btn-xs" data-dup-block-id="${id}">Дублировать</button>
            <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
          `,
          '',
          'blockSection'
        );
      }


      if (type === 'text') {
        const text = (b.content && typeof b.content.text === 'string') ? b.content.text : '';

        const wrap = wrapBlockMeta();
        return buildBlockShell(
          id, type, sort,
          `<pre>${BX.util.htmlspecialchars(text)}</pre>`,
          `
            ${commonBtns}
            <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-text-id="${id}">Редактировать</button>
            <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
          `,
          '',
          wrap.extraClass,
          wrap.sectionMarkHtml
        );
      }

      if (type === 'image') {
        const fileId = b.content && b.content.fileId ? parseInt(b.content.fileId, 10) : 0;
        const alt = b.content && typeof b.content.alt === 'string' ? b.content.alt : '';
        const img = fileId
          ? `<div class="imgPrev"><img src="${fileDownloadUrl(fileId)}" alt="${BX.util.htmlspecialchars(alt)}"></div>`
          : '<div class="muted" style="margin-top:10px;">Файл не выбран</div>';

        const wrap = wrapBlockMeta();
        return buildBlockShell(
          id, type, sort,
          `<div class="muted">alt: ${BX.util.htmlspecialchars(alt)}</div>${img}`,
          `
            ${commonBtns}
            <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-image-id="${id}">Редактировать</button>
            <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
          `,
          `<span>fileId: ${fileId || '-'}</span>`,
          '',
          wrap.extraClass,
          wrap.sectionMarkHtml
        );
      }

      if (type === 'button') {
        const text = (b.content && typeof b.content.text === 'string') ? b.content.text : '';
        const url = (b.content && typeof b.content.url === 'string') ? b.content.url : '';
        const variant = (b.content && typeof b.content.variant === 'string') ? b.content.variant : 'primary';

        const wrap = wrapBlockMeta();
        return buildBlockShell(
          id, type, sort,
          `
            <div class="muted">url: ${BX.util.htmlspecialchars(url)}</div>
            <a class="${btnClass(variant)}" href="${BX.util.htmlspecialchars(url)}" target="_blank" rel="noopener noreferrer">
              ${BX.util.htmlspecialchars(text)}
            </a>
          `,
          `
            ${commonBtns}
            <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-button-id="${id}">Редактировать</button>
            <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
          `,
          `<span>variant: ${BX.util.htmlspecialchars(variant)}</span>`,
          '',
          wrap.extraClass,
          wrap.sectionMarkHtml
        );
      }

      if (type === 'heading') {
        const text = (b.content && typeof b.content.text === 'string') ? b.content.text : '';
        const level = (b.content && typeof b.content.level === 'string') ? b.content.level : 'h2';
        const align = (b.content && typeof b.content.align === 'string') ? b.content.align : 'left';
        const tag = headingTag(level);
        const al = headingAlign(align);

        const wrap = wrapBlockMeta();
        return buildBlockShell(
          id, type, sort,
          `
            <div class="headingPreview" style="text-align:${BX.util.htmlspecialchars(al)};">
              <${tag}>${BX.util.htmlspecialchars(text)}</${tag}>
            </div>
          `,
          `
            ${commonBtns}
            <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-heading-id="${id}">Редактировать</button>
            <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
          `,
          `<span>${BX.util.htmlspecialchars(tag)}</span><span>${BX.util.htmlspecialchars(al)}</span>`,
          '',
          wrap.extraClass,
          wrap.sectionMarkHtml
        );
      }

      if (type === 'columns2') {
        const left = (b.content && typeof b.content.left === 'string') ? b.content.left : '';
        const right = (b.content && typeof b.content.right === 'string') ? b.content.right : '';
        const ratio = (b.content && typeof b.content.ratio === 'string') ? b.content.ratio : '50-50';
        const tpl = colsGridTemplate(ratio);

        const wrap = wrapBlockMeta();
        return buildBlockShell(
          id, type, sort,
          `
            <div class="colsPreview" style="grid-template-columns:${tpl};">
              <div class="cell"><pre>${BX.util.htmlspecialchars(left)}</pre></div>
              <div class="cell"><pre>${BX.util.htmlspecialchars(right)}</pre></div>
            </div>
          `,
          `
            ${commonBtns}
            <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-cols2-id="${id}">Редактировать</button>
            <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
          `,
          `<span>ratio: ${BX.util.htmlspecialchars(ratio)}</span>`,
          '',
          wrap.extraClass,
          wrap.sectionMarkHtml
        );
      }

      if (type === 'gallery') {
        const columns = (b.content && b.content.columns) ? parseInt(b.content.columns, 10) : 3;
        const imgs = (b.content && Array.isArray(b.content.images)) ? b.content.images : [];
        const tpl = galleryTemplate(columns);

        const prev = imgs.map(it => {
          const fid = parseInt(it.fileId || 0, 10);
          if (!fid) return '';
          return `<img src="${fileDownloadUrl(fid)}" alt="${BX.util.htmlspecialchars(it.alt || '')}">`;
        }).join('');

        const wrap = wrapBlockMeta();
        return buildBlockShell(
          id, type, sort,
          `<div class="galPrev" style="grid-template-columns:${tpl};">${prev}</div>`,
          `
            ${commonBtns}
            <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-gallery-id="${id}">Редактировать</button>
            <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
          `,
          `<span>cols: ${columns}</span><span>images: ${imgs.length}</span>`,
          '',
          wrap.extraClass,
          wrap.sectionMarkHtml
        );
      }

      if (type === 'spacer') {
        const height = (b.content && b.content.height) ? parseInt(b.content.height, 10) : 40;
        const line = (b.content && (b.content.line === true || b.content.line === 'true')) ? true : false;

        const wrap = wrapBlockMeta();
        return buildBlockShell(
          id, type, sort,
          `
            <div style="margin-top:10px; border:1px dashed #e5e7ea; border-radius:10px; padding:10px;">
              <div style="height:${height}px; position:relative; background:#fafafa; border-radius:10px;">
                ${line ? '<div style="position:absolute; left:0; right:0; top:50%; height:1px; background:#e5e7ea;"></div>' : ''}
              </div>
            </div>
          `,
          `
            ${commonBtns}
            <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-spacer-id="${id}">Редактировать</button>
            <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
          `,
          `<span>${height}px</span><span>line: ${line ? 'yes' : 'no'}</span>`,
          '',
          wrap.extraClass,
          wrap.sectionMarkHtml
        );
      }

      if (type === 'card') {
        const title = (b.content && typeof b.content.title === 'string') ? b.content.title : '';
        const text = (b.content && typeof b.content.text === 'string') ? b.content.text : '';
        const imageFileId = (b.content && b.content.imageFileId) ? parseInt(b.content.imageFileId, 10) : 0;
        const buttonText = (b.content && typeof b.content.buttonText === 'string') ? b.content.buttonText : '';
        const buttonUrl = (b.content && typeof b.content.buttonUrl === 'string') ? b.content.buttonUrl : '';

        const img = imageFileId ? `<div class="imgPrev"><img src="${fileDownloadUrl(imageFileId)}" alt=""></div>` : '';

        const wrap = wrapBlockMeta();
        return buildBlockShell(
          id, type, sort,
          `
            <div style="font-weight:700;">${BX.util.htmlspecialchars(title)}</div>
            <div class="muted" style="margin-top:6px; white-space:pre-wrap;">${BX.util.htmlspecialchars(text)}</div>
            ${img}
            ${buttonUrl ? `<a class="${btnClass('secondary')}" href="${BX.util.htmlspecialchars(buttonUrl)}" target="_blank" rel="noopener noreferrer">${BX.util.htmlspecialchars(buttonText || 'Открыть')}</a>` : ''}
          `,
          `
            ${commonBtns}
            <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-card-id="${id}">Редактировать</button>
            <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
          `,
          '',
          wrap.extraClass,
          wrap.sectionMarkHtml
        );
      }

      if (type === 'cards') {
        const columns = (b.content && b.content.columns) ? parseInt(b.content.columns, 10) : 3;
        const items = (b.content && Array.isArray(b.content.items)) ? b.content.items : [];

        const wrap = wrapBlockMeta();
        return buildBlockShell(
          id, type, sort,
          `<pre>${BX.util.htmlspecialchars(JSON.stringify({columns, items}, null, 2))}</pre>`,
          `
            ${commonBtns}
            <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-cards-id="${id}">Редактировать</button>
            <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>
          `,
          `<span>cols: ${columns}</span><span>items: ${items.length}</span>`,
          '',
          wrap.extraClass,
          wrap.sectionMarkHtml
        );
      }

      const wrap = wrapBlockMeta();
      return buildBlockShell(
        id, type, sort,
        `<div class="muted">Неизвестный тип: ${BX.util.htmlspecialchars(type)}</div>`,
        `<button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>`,
        '',
        wrap.extraClass,
        wrap.sectionMarkHtml
      );
    }).join('');
  }

  function saveBlockOrder() {
    const ids = Array.from(blocksBox.querySelectorAll('.block[data-block-id]'))
      .map(el => parseInt(el.getAttribute('data-block-id'), 10))
      .filter(Boolean);

    return api('block.reorder', {
      pageId,
      order: JSON.stringify(ids)
    });
  }

  function initBlockDnD() {
    if (!blocksBox) return;

    let draggedEl = null;
    let dragAllowed = false;

    blocksBox.querySelectorAll('.block[data-block-id]').forEach(block => {
        block.setAttribute('draggable', 'false');

        const handle = block.querySelector('[data-drag-handle]');
        if (handle) {
        handle.addEventListener('mousedown', () => {
            dragAllowed = true;
            block.setAttribute('draggable', 'true');
        });

        handle.addEventListener('mouseup', () => {
            setTimeout(() => {
            block.setAttribute('draggable', 'false');
            dragAllowed = false;
            }, 0);
        });
        }

        block.addEventListener('dragstart', (e) => {
        if (!dragAllowed) {
            e.preventDefault();
            return;
        }

        draggedEl = block;
        block.classList.add('dragging');

        try {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', block.getAttribute('data-block-id') || '');
        } catch (err) {}
        });

        block.addEventListener('dragend', () => {
        if (draggedEl) draggedEl.classList.remove('dragging');
        draggedEl = null;
        dragAllowed = false;
        block.setAttribute('draggable', 'false');
        });

        block.addEventListener('dragover', (e) => {
        if (!draggedEl || draggedEl === block) return;
        e.preventDefault();

        const rect = block.getBoundingClientRect();
        const middle = rect.top + rect.height / 2;
        const after = e.clientY > middle;

        if (after) {
            if (block.nextElementSibling !== draggedEl) {
            block.parentNode.insertBefore(draggedEl, block.nextElementSibling);
            }
        } else {
            if (block.previousElementSibling !== draggedEl) {
            block.parentNode.insertBefore(draggedEl, block);
            }
        }
        });

        block.addEventListener('drop', async (e) => {
        if (!draggedEl) return;
        e.preventDefault();

        try {
            const r = await saveBlockOrder();
            if (!r || r.ok !== true) {
            notify('Не удалось сохранить порядок');
            loadBlocks();
            return;
            }
            notify('Порядок сохранён');
            loadBlocks();
        } catch (err) {
            notify('Ошибка block.reorder');
            loadBlocks();
        }
        });
    });
  }


  function loadBlocks() {
    api('block.list', { pageId }).then(res => {
      if (!res || res.ok !== true) {
        notify('Не удалось загрузить блоки');
        return;
      }
      renderBlocks(res.blocks);
      initBlockDnD();
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
        notify('Каталог секций: не найден контейнер');
        return;
      }

      const rerender = (q) => {
        root.outerHTML = render(q);
        const newRoot = document.getElementById(containerId);
        if (!newRoot) return;
        bind(newRoot);
      };

      const bind = (r) => {
        const q = document.getElementById(containerId + '_q');
        if (q) q.oninput = () => rerender(q.value);

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

  const SECTION_PRESETS = {
    default: {
        boxed: true,
        background: '#FFFFFF',
        paddingTop: 32,
        paddingBottom: 32,
        border: false,
        radius: 0
    },
    hero: {
        boxed: true,
        background: '#F8FAFC',
        paddingTop: 72,
        paddingBottom: 72,
        border: false,
        radius: 0
    },
    light: {
        boxed: true,
        background: '#F9FAFB',
        paddingTop: 40,
        paddingBottom: 40,
        border: false,
        radius: 0
    },
    accent: {
        boxed: false,
        background: '#EEF2FF',
        paddingTop: 56,
        paddingBottom: 56,
        border: false,
        radius: 0
    },
    card: {
        boxed: true,
        background: '#FFFFFF',
        paddingTop: 32,
        paddingBottom: 32,
        border: true,
        radius: 16
    }
    };

    function sectionPresetOptions(selected = 'default') {
        return `
            <option value="default" ${selected === 'default' ? 'selected' : ''}>Default</option>
            <option value="hero" ${selected === 'hero' ? 'selected' : ''}>Hero</option>
            <option value="light" ${selected === 'light' ? 'selected' : ''}>Light</option>
            <option value="accent" ${selected === 'accent' ? 'selected' : ''}>Accent</option>
            <option value="card" ${selected === 'card' ? 'selected' : ''}>Card</option>
        `;
    }

    function applySectionPresetToForm(presetKey, suffix = '') {
        const preset = SECTION_PRESETS[presetKey] || SECTION_PRESETS.default;

        const boxedEl = document.getElementById('sec_boxed' + suffix);
        const bgEl = document.getElementById('sec_bg' + suffix);
        const ptEl = document.getElementById('sec_pt' + suffix);
        const pbEl = document.getElementById('sec_pb' + suffix);
        const borderEl = document.getElementById('sec_border' + suffix);
        const radiusEl = document.getElementById('sec_radius' + suffix);

        if (boxedEl) boxedEl.checked = !!preset.boxed;
        if (bgEl) bgEl.value = preset.background;
        if (ptEl) ptEl.value = preset.paddingTop;
        if (pbEl) pbEl.value = preset.paddingBottom;
        if (borderEl) borderEl.checked = !!preset.border;
        if (radiusEl) radiusEl.value = preset.radius;
    }


    function addSectionBlock() {
        const mb = BX.UI.Dialogs.MessageBox.show({
            title: 'Новая Section',
            message: `
            <div>
                <div class="field">
                <label>Пресет</label>
                <select id="sec_preset" class="input">
                    ${sectionPresetOptions('default')}
                </select>
                </div>

                <div class="field">
                <label><input id="sec_boxed" type="checkbox" checked> Ограничить по контейнеру</label>
                </div>

                <div class="field">
                <label>Цвет фона</label>
                <input id="sec_bg" class="input" value="#FFFFFF" placeholder="#FFFFFF" />
                </div>

                <div class="field">
                <label>Отступ сверху (0..200)</label>
                <input id="sec_pt" class="input" type="number" min="0" max="200" value="32" />
                </div>

                <div class="field">
                <label>Отступ снизу (0..200)</label>
                <input id="sec_pb" class="input" type="number" min="0" max="200" value="32" />
                </div>

                <div class="field">
                <label><input id="sec_border" type="checkbox"> Показать рамку</label>
                </div>

                <div class="field">
                <label>Скругление (0..40)</label>
                <input id="sec_radius" class="input" type="number" min="0" max="40" value="0" />
                </div>
            </div>
            `,
            buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
            onOk: function (mbox) {
            const boxed = document.getElementById('sec_boxed')?.checked ? '1' : '0';
            const background = (document.getElementById('sec_bg')?.value || '#FFFFFF').trim();
            const paddingTop = parseInt(document.getElementById('sec_pt')?.value || '32', 10);
            const paddingBottom = parseInt(document.getElementById('sec_pb')?.value || '32', 10);
            const border = document.getElementById('sec_border')?.checked ? '1' : '0';
            const radius = parseInt(document.getElementById('sec_radius')?.value || '0', 10);

            api('block.create', {
                pageId,
                type: 'section',
                boxed,
                background,
                paddingTop,
                paddingBottom,
                border,
                radius
            })
                .then(res => {
                if (!res || res.ok !== true) {
                    notify('Не удалось создать section');
                    return;
                }
                notify('Section создана');
                mbox.close();
                loadBlocks();
                })
                .catch(() => notify('Ошибка block.create (section)'));
            }
        });

        setTimeout(() => {
            const presetEl = document.getElementById('sec_preset');
            if (presetEl) {
            presetEl.addEventListener('change', () => {
                applySectionPresetToForm(presetEl.value);
            });

            applySectionPresetToForm('default');
            }
        }, 0);
      }

      async function createBlockAfterSection(sectionId, type, payload = {}) {
        const listRes = await api('block.list', { pageId });
        if (!listRes || listRes.ok !== true) {
          notify('Не удалось загрузить блоки страницы');
          return;
        }

        const blocks = Array.isArray(listRes.blocks) ? listRes.blocks.slice() : [];
        const sectionIndex = blocks.findIndex(b => parseInt(b.id, 10) === parseInt(sectionId, 10));

        if (sectionIndex < 0) {
          notify('Section не найдена');
          return;
        }

        const sectionSort = parseInt(blocks[sectionIndex].sort || 0, 10);

        let insertSort = sectionSort + 10;
        for (let i = sectionIndex + 1; i < blocks.length; i++) {
          const next = blocks[i];
          if ((next.type || '') === 'section') {
            insertSort = parseInt(next.sort || insertSort, 10) - 1;
            break;
          }
          insertSort = Math.max(insertSort, parseInt(next.sort || 0, 10) + 10);
        }

        const createRes = await api('block.create', {
          pageId,
          type,
          ...payload
        });

        if (!createRes || createRes.ok !== true || !createRes.block) {
          notify('Не удалось создать блок');
          return;
        }

        const newBlockId = parseInt(createRes.block.id, 10);

        const refreshRes = await api('block.list', { pageId });
        if (!refreshRes || refreshRes.ok !== true) {
          notify('Блок создан, но не удалось обновить порядок');
          loadBlocks();
          return;
        }

        const freshBlocks = Array.isArray(refreshRes.blocks) ? refreshRes.blocks.slice() : [];
        const moved = freshBlocks.find(b => parseInt(b.id, 10) === newBlockId);
        if (!moved) {
          loadBlocks();
          return;
        }

        const desiredOrder = freshBlocks
          .sort((a, b) => parseInt(a.sort || 0, 10) - parseInt(b.sort || 0, 10))
          .filter(b => parseInt(b.id, 10) !== newBlockId);

        let targetIndex = desiredOrder.findIndex(b => parseInt(b.sort || 0, 10) > insertSort);
        if (targetIndex < 0) targetIndex = desiredOrder.length;

        desiredOrder.splice(targetIndex, 0, moved);

        const order = desiredOrder.map(b => parseInt(b.id, 10));

        const reorderRes = await api('block.reorder', {
          pageId,
          order: JSON.stringify(order)
        });

        if (!reorderRes || reorderRes.ok !== true) {
          notify('Блок создан, но не удалось поставить после section');
          loadBlocks();
          return;
        }

        notify('Блок добавлен');
        loadBlocks();
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

  function editSectionBlock(id) {
    api('block.list', { pageId }).then(res => {
      if (!res || res.ok !== true) return;
      const blk = (res.blocks || []).find(x => parseInt(x.id, 10) === id);
      const cur = (blk && blk.content && typeof blk.content === 'object') ? blk.content : {};

      BX.UI.Dialogs.MessageBox.show({
        title: 'Редактировать Section #' + id,
        message: `
          <div>
            <div class="field">
                <label>Пресет</label>
                <select id="sec_preset_e" class="input">
                    ${sectionPresetOptions('default')}
                </select>
            </div>
            <div class="field">
              <label><input id="sec_boxed_e" type="checkbox" ${cur.boxed ? 'checked' : ''}> Boxed контейнер</label>
            </div>

            <div class="field">
              <label>Цвет фона</label>
              <input id="sec_bg_e" class="input" value="${BX.util.htmlspecialchars(cur.background || '#FFFFFF')}" />
            </div>

            <div class="field">
              <label>Отступ сверху (0..200)</label>
              <input id="sec_pt_e" class="input" type="number" min="0" max="200" value="${parseInt(cur.paddingTop || 32, 10)}" />
            </div>

            <div class="field">
              <label>Отступ снизу (0..200)</label>
              <input id="sec_pb_e" class="input" type="number" min="0" max="200" value="${parseInt(cur.paddingBottom || 32, 10)}" />
            </div>

            <div class="field">
              <label><input id="sec_border_e" type="checkbox" ${cur.border ? 'checked' : ''}> Граница</label>
            </div>

            <div class="field">
              <label>Скругление (0..40)</label>
              <input id="sec_radius_e" class="input" type="number" min="0" max="40" value="${parseInt(cur.radius || 0, 10)}" />
            </div>
          </div>
        `,
        buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
        onOk: function (mb) {
          const boxed = document.getElementById('sec_boxed_e')?.checked ? '1' : '0';
          const background = (document.getElementById('sec_bg_e')?.value || '#FFFFFF').trim();
          const paddingTop = parseInt(document.getElementById('sec_pt_e')?.value || '32', 10);
          const paddingBottom = parseInt(document.getElementById('sec_pb_e')?.value || '32', 10);
          const border = document.getElementById('sec_border_e')?.checked ? '1' : '0';
          const radius = parseInt(document.getElementById('sec_radius_e')?.value || '0', 10);

          api('block.update', {
            id,
            boxed,
            background,
            paddingTop,
            paddingBottom,
            border,
            radius
          })
            .then(r => {
              if (!r || r.ok !== true) {
                notify('Не удалось сохранить section');
                return;
              }
              notify('Section сохранена');
              mb.close();
              loadBlocks();
            })
            .catch(() => notify('Ошибка block.update (section)'));
        }
      });
      setTimeout(() => {
        const presetEl = document.getElementById('sec_preset_e');
        if (presetEl) {
            presetEl.addEventListener('change', () => {
            applySectionPresetToForm(presetEl.value, '_e');
            });
        }
        }, 0);
    });
  }

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

  document.addEventListener('click', function (e) {
    const tg = e.target.closest('[data-toggle-block]');
    if (tg) {
      const id = parseInt(tg.getAttribute('data-toggle-block'), 10);
      const box = document.querySelector(`.block[data-block-id="${id}"]`);
      if (!box) return;

      if (collapsedBlocks.has(id)) {
        collapsedBlocks.delete(id);
        box.classList.remove('blockCollapsed');
        tg.textContent = 'Свернуть';
      } else {
        collapsedBlocks.add(id);
        box.classList.add('blockCollapsed');
        tg.textContent = 'Развернуть';
      }
      return;
    }

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

    const addHeadingAfterSectionBtn = e.target.closest('[data-add-heading-after-section-id]');
    if (addHeadingAfterSectionBtn) {
      quickAddHeadingAfterSection(parseInt(addHeadingAfterSectionBtn.getAttribute('data-add-heading-after-section-id'), 10));
      return;
    }

    const addTextAfterSectionBtn = e.target.closest('[data-add-text-after-section-id]');
    if (addTextAfterSectionBtn) {
      quickAddTextAfterSection(parseInt(addTextAfterSectionBtn.getAttribute('data-add-text-after-section-id'), 10));
      return;
    }

    const addButtonAfterSectionBtn = e.target.closest('[data-add-button-after-section-id]');
    if (addButtonAfterSectionBtn) {
      quickAddButtonAfterSection(parseInt(addButtonAfterSectionBtn.getAttribute('data-add-button-after-section-id'), 10));
      return;
    }

    const addCardsAfterSectionBtn = e.target.closest('[data-add-cards-after-section-id]');
    if (addCardsAfterSectionBtn) {
      quickAddCardsAfterSection(parseInt(addCardsAfterSectionBtn.getAttribute('data-add-cards-after-section-id'), 10));
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

    const editSectionBtn = e.target.closest('[data-edit-section-id]');
    if (editSectionBtn) {
      editSectionBlock(parseInt(editSectionBtn.getAttribute('data-edit-section-id'), 10));
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

  btnAddSection?.addEventListener('click', addSectionBlock);
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

  if (blockSearch) {
    blockSearch.addEventListener('input', loadBlocks);
  }

  loadBlocks();
});
</script>
</body>
</html>