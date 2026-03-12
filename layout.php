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
  <title>Layout сайта</title>
  <?php $APPLICATION->ShowHead(); ?>
  <style>
    body { font-family: Arial, sans-serif; margin:0; background:#f6f7f8; color:#111; }
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
    .content { padding:18px; }
    .card { background:#fff; border:1px solid #e5e7ea; border-radius:14px; padding:16px; }
    .muted { color:#6a737f; }
    .row { display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .btns { display:flex; gap:6px; flex-wrap:wrap; }
    .field { margin-top:12px; }
    .field label { display:block; font-size:12px; color:#6a737f; margin-bottom:4px; }
    .input, select, textarea {
      width:100%;
      padding:8px;
      border:1px solid #d0d7de;
      border-radius:8px;
      box-sizing:border-box;
      background:#fff;
    }
    code { background:#f3f4f6; padding:2px 6px; border-radius:6px; }
    pre { white-space:pre-wrap; margin:10px 0 0; background:#f9fafb; border:1px solid #eee; border-radius:8px; padding:10px; }

    .zones {
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      margin-top:12px;
    }

    .zoneBtn.active {
      background:#2563eb !important;
      border-color:#2563eb !important;
      color:#fff !important;
    }

    .layoutGrid {
      display:grid;
      grid-template-columns: 360px 1fr;
      gap:16px;
      align-items:start;
      margin-top:16px;
    }
    @media (max-width: 980px) {
      .layoutGrid { grid-template-columns: 1fr; }
    }

    .block {
      border:1px solid #e5e7ea;
      border-radius:14px;
      padding:12px;
      margin-top:12px;
      background:#fff;
      box-shadow:0 1px 2px rgba(0,0,0,.03);
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

    .imgPrev {
      margin-top:10px;
      max-width:420px;
      border:1px solid #eee;
      border-radius:10px;
      overflow:hidden;
      background:#fafafa;
    }
    .imgPrev img { display:block; width:100%; height:auto; }

    .headingPreview {
      margin-top:10px;
      border:1px dashed #e5e7ea;
      border-radius:10px;
      padding:10px;
    }
    .headingPreview h1, .headingPreview h2, .headingPreview h3 { margin:0; }

    .colsPreview {
      margin-top:10px;
      border:1px dashed #e5e7ea;
      border-radius:10px;
      padding:10px;
      display:grid;
      gap:10px;
    }
    .colsPreview .cell {
      background:#fafafa;
      border:1px solid #eee;
      border-radius:10px;
      padding:10px;
      min-height:48px;
    }
    .colsPreview pre { margin:0; background:transparent; border:none; padding:0; }

    .galPrev { margin-top:10px; display:grid; gap:10px; }
    .galPrev img {
      width:100%;
      height:auto;
      display:block;
      border-radius:10px;
      border:1px solid #eee;
      background:#fafafa;
    }

    .cardsBuilder { margin-top:10px; }
    .cardsBuilder .item { border:1px solid #e5e7ea; border-radius:12px; padding:10px; margin-top:10px; background:#fff; }
    .cardsBuilder .itemHead { display:flex; gap:8px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .cardsBuilder .miniBtns { display:flex; gap:6px; flex-wrap:wrap; }
    .cardsBuilder .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    @media (max-width:720px){ .cardsBuilder .grid2 { grid-template-columns:1fr; } }

    .block[data-type="text"] .blockTypeBadge { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
    .block[data-type="image"] .blockTypeBadge { background:#f5f3ff; color:#6d28d9; border-color:#ddd6fe; }
    .block[data-type="button"] .blockTypeBadge { background:#ecfeff; color:#0f766e; border-color:#a5f3fc; }
    .block[data-type="heading"] .blockTypeBadge { background:#fef3c7; color:#92400e; border-color:#fcd34d; }
    .block[data-type="columns2"] .blockTypeBadge { background:#f3f4f6; color:#374151; border-color:#d1d5db; }
    .block[data-type="gallery"] .blockTypeBadge { background:#fdf2f8; color:#be185d; border-color:#fbcfe8; }
    .block[data-type="spacer"] .blockTypeBadge { background:#f8fafc; color:#475569; border-color:#cbd5e1; }
    .block[data-type="card"] .blockTypeBadge { background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }
    .block[data-type="cards"] .blockTypeBadge { background:#ecfccb; color:#3f6212; border-color:#bef264; }
  </style>
</head>
<body>
  <div class="top">
    <a href="/local/sitebuilder/index.php">← Назад</a>
    <div class="muted">Layout сайта</div>
    <div class="muted">|</div>
    <div><b>siteId:</b> <code><?= (int)$siteId ?></code></div>
  </div>

  <div class="content">
    <div class="layoutGrid">
      <div class="card">
        <div class="row">
          <div><b>Настройки layout</b></div>
        </div>

        <div class="field">
          <label><input type="checkbox" id="showHeader"> Показывать header</label>
        </div>
        <div class="field">
          <label><input type="checkbox" id="showFooter"> Показывать footer</label>
        </div>
        <div class="field">
          <label><input type="checkbox" id="showLeft"> Показывать left</label>
        </div>
        <div class="field">
          <label><input type="checkbox" id="showRight"> Показывать right</label>
        </div>
        <div class="field">
          <label>Ширина left</label>
          <input class="input" type="number" id="leftWidth" min="160" max="500" value="260">
        </div>
        <div class="field">
        <label>Режим left</label>
            <select id="leftMode" class="input">
                <option value="blocks">Обычные блоки</option>
                <option value="menu">Меню сайта</option>
            </select>
        </div>
        <div class="field">
          <label>Ширина right</label>
          <input class="input" type="number" id="rightWidth" min="160" max="500" value="260">
        </div>

        <div class="btns" style="margin-top:14px;">
          <button class="ui-btn ui-btn-primary" id="btnSaveLayoutSettings">Сохранить настройки</button>
        </div>

        <div class="zones">
          <button class="ui-btn ui-btn-light zoneBtn active" data-zone="header">Header</button>
          <button class="ui-btn ui-btn-light zoneBtn" data-zone="footer">Footer</button>
          <button class="ui-btn ui-btn-light zoneBtn" data-zone="left">Left</button>
          <button class="ui-btn ui-btn-light zoneBtn" data-zone="right">Right</button>
        </div>

        <div class="muted" style="margin-top:12px;">
          Выбрана зона: <b id="zoneLabel">header</b>
        </div>

        <div class="btns" style="margin-top:12px;">
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

      <div class="card">
        <div class="row">
          <div><b>Блоки зоны</b></div>
          <div class="muted" id="blocksMeta"></div>
        </div>
        <div id="blocksBox" style="margin-top:12px;"></div>
      </div>
    </div>
  </div>

<script>
BX.ready(function () {
  const siteId = <?= (int)$siteId ?>;
  const blocksBox = document.getElementById('blocksBox');
  const zoneLabel = document.getElementById('zoneLabel');
  const blocksMeta = document.getElementById('blocksMeta');

  let currentZone = 'header';
  let layoutSettings = {
    showHeader: true,
    showFooter: true,
    showLeft: false,
    showRight: false,
    leftWidth: 260,
    rightWidth: 260
  };

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
    return (variant === 'secondary') ? 'ui-btn-light-border' : 'ui-btn-primary';
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

  function fillSettingsForm() {
    
    document.getElementById('showHeader').checked = !!layoutSettings.showHeader;
    document.getElementById('showFooter').checked = !!layoutSettings.showFooter;
    document.getElementById('showLeft').checked = !!layoutSettings.showLeft;
    document.getElementById('leftMode').value = layoutSettings.leftMode || 'blocks';
    document.getElementById('showRight').checked = !!layoutSettings.showRight;
    document.getElementById('leftWidth').value = parseInt(layoutSettings.leftWidth || 260, 10);
    document.getElementById('rightWidth').value = parseInt(layoutSettings.rightWidth || 260, 10);
  }

  function buildBlockShell(id, type, sort, bodyHtml, buttonsHtml, extraMetaHtml = '') {
    return `
      <div class="block" data-type="${BX.util.htmlspecialchars(type)}" data-block-id="${id}">
        <div class="blockHeader">
          <div class="blockLeft">
            <div class="blockTitleRow">
              <b>#${id}</b>
              <span class="blockTypeBadge">${BX.util.htmlspecialchars(type)}</span>
            </div>
            <div class="blockMeta">
              <span>sort: ${sort}</span>
              ${extraMetaHtml}
            </div>
          </div>
          <div class="btns">
            ${buttonsHtml}
          </div>
        </div>
        <div style="margin-top:10px;">
          ${bodyHtml}
        </div>
      </div>
    `;
  }

  function renderBlocks(blocks) {
    blocksMeta.textContent = `Зона: ${currentZone} • блоков: ${blocks.length}`;

    if (!blocks.length) {
      blocksBox.innerHTML = '<div class="muted">В этой зоне блоков пока нет.</div>';
      return;
    }

    blocksBox.innerHTML = blocks.map(b => {
      const type = b.type || '';
      const sort = b.sort;
      const id = b.id;

      const commonBtns = `
        <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="up">↑</button>
        <button class="ui-btn ui-btn-light ui-btn-xs" data-move-block-id="${id}" data-move-dir="down">↓</button>
      `;

      if (type === 'text') {
        const text = (b.content && typeof b.content.text === 'string') ? b.content.text : '';
        return buildBlockShell(
          id, type, sort,
          `<pre>${BX.util.htmlspecialchars(text)}</pre>`,
          `${commonBtns}
           <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-text-id="${id}">Редактировать</button>
           <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>`
        );
      }

      if (type === 'image') {
        const fileId = b.content && b.content.fileId ? parseInt(b.content.fileId, 10) : 0;
        const alt = b.content && typeof b.content.alt === 'string' ? b.content.alt : '';
        const img = fileId
          ? `<div class="imgPrev"><img src="${fileDownloadUrl(fileId)}" alt="${BX.util.htmlspecialchars(alt)}"></div>`
          : '<div class="muted">Файл не выбран</div>';

        return buildBlockShell(
          id, type, sort,
          `<div class="muted">alt: ${BX.util.htmlspecialchars(alt)}</div>${img}`,
          `${commonBtns}
           <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-image-id="${id}">Редактировать</button>
           <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>`,
          `<span>fileId: ${fileId || '-'}</span>`
        );
      }

      if (type === 'button') {
        const text = (b.content && typeof b.content.text === 'string') ? b.content.text : '';
        const url = (b.content && typeof b.content.url === 'string') ? b.content.url : '';
        const variant = (b.content && typeof b.content.variant === 'string') ? b.content.variant : 'primary';

        return buildBlockShell(
          id, type, sort,
          `<div class="muted">url: ${BX.util.htmlspecialchars(url)}</div>
           <a class="ui-btn ${variant === 'secondary' ? 'ui-btn-light-border' : 'ui-btn-primary'}" href="${BX.util.htmlspecialchars(url)}" target="_blank" rel="noopener noreferrer">${BX.util.htmlspecialchars(text)}</a>`,
          `${commonBtns}
           <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-button-id="${id}">Редактировать</button>
           <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>`,
          `<span>variant: ${BX.util.htmlspecialchars(variant)}</span>`
        );
      }

      if (type === 'heading') {
        const text = (b.content && typeof b.content.text === 'string') ? b.content.text : '';
        const level = (b.content && typeof b.content.level === 'string') ? b.content.level : 'h2';
        const align = (b.content && typeof b.content.align === 'string') ? b.content.align : 'left';
        const tag = headingTag(level);
        const al = headingAlign(align);

        return buildBlockShell(
          id, type, sort,
          `<div class="headingPreview" style="text-align:${BX.util.htmlspecialchars(al)};">
             <${tag}>${BX.util.htmlspecialchars(text)}</${tag}>
           </div>`,
          `${commonBtns}
           <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-heading-id="${id}">Редактировать</button>
           <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>`,
          `<span>${BX.util.htmlspecialchars(tag)}</span><span>${BX.util.htmlspecialchars(al)}</span>`
        );
      }

      if (type === 'columns2') {
        const left = (b.content && typeof b.content.left === 'string') ? b.content.left : '';
        const right = (b.content && typeof b.content.right === 'string') ? b.content.right : '';
        const ratio = (b.content && typeof b.content.ratio === 'string') ? b.content.ratio : '50-50';
        const tpl = colsGridTemplate(ratio);

        return buildBlockShell(
          id, type, sort,
          `<div class="colsPreview" style="grid-template-columns:${tpl};">
             <div class="cell"><pre>${BX.util.htmlspecialchars(left)}</pre></div>
             <div class="cell"><pre>${BX.util.htmlspecialchars(right)}</pre></div>
           </div>`,
          `${commonBtns}
           <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-cols2-id="${id}">Редактировать</button>
           <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>`,
          `<span>ratio: ${BX.util.htmlspecialchars(ratio)}</span>`
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

        return buildBlockShell(
          id, type, sort,
          `<div class="galPrev" style="grid-template-columns:${tpl};">${prev}</div>`,
          `${commonBtns}
           <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-gallery-id="${id}">Редактировать</button>
           <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>`,
          `<span>cols: ${columns}</span><span>images: ${imgs.length}</span>`
        );
      }

      if (type === 'spacer') {
        const height = (b.content && b.content.height) ? parseInt(b.content.height, 10) : 40;
        const line = (b.content && (b.content.line === true || b.content.line === 'true')) ? true : false;

        return buildBlockShell(
          id, type, sort,
          `<div style="border:1px dashed #e5e7ea; border-radius:10px; padding:10px;">
             <div style="height:${height}px; position:relative; background:#fafafa; border-radius:10px;">
               ${line ? '<div style="position:absolute; left:0; right:0; top:50%; height:1px; background:#e5e7ea;"></div>' : ''}
             </div>
           </div>`,
          `${commonBtns}
           <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-spacer-id="${id}">Редактировать</button>
           <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>`,
          `<span>${height}px</span><span>line: ${line ? 'yes' : 'no'}</span>`
        );
      }

      if (type === 'card') {
        const title = (b.content && typeof b.content.title === 'string') ? b.content.title : '';
        const text = (b.content && typeof b.content.text === 'string') ? b.content.text : '';
        const imageFileId = (b.content && b.content.imageFileId) ? parseInt(b.content.imageFileId, 10) : 0;
        const buttonText = (b.content && typeof b.content.buttonText === 'string') ? b.content.buttonText : '';
        const buttonUrl = (b.content && typeof b.content.buttonUrl === 'string') ? b.content.buttonUrl : '';

        const img = imageFileId ? `<div class="imgPrev"><img src="${fileDownloadUrl(imageFileId)}" alt=""></div>` : '';

        return buildBlockShell(
          id, type, sort,
          `<div style="font-weight:700;">${BX.util.htmlspecialchars(title)}</div>
           <div class="muted" style="margin-top:6px; white-space:pre-wrap;">${BX.util.htmlspecialchars(text)}</div>
           ${img}
           ${buttonUrl ? `<a class="ui-btn ui-btn-light-border" href="${BX.util.htmlspecialchars(buttonUrl)}" target="_blank" rel="noopener noreferrer">${BX.util.htmlspecialchars(buttonText || 'Открыть')}</a>` : ''}`,
          `${commonBtns}
           <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-card-id="${id}">Редактировать</button>
           <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>`
        );
      }

      if (type === 'cards') {
        const columns = (b.content && b.content.columns) ? parseInt(b.content.columns, 10) : 3;
        const items = (b.content && Array.isArray(b.content.items)) ? b.content.items : [];

        return buildBlockShell(
          id, type, sort,
          `<pre>${BX.util.htmlspecialchars(JSON.stringify({columns, items}, null, 2))}</pre>`,
          `${commonBtns}
           <button class="ui-btn ui-btn-light ui-btn-xs" data-edit-cards-id="${id}">Редактировать</button>
           <button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>`,
          `<span>cols: ${columns}</span><span>items: ${items.length}</span>`
        );
      }

      return buildBlockShell(
        id, type, sort,
        `<div class="muted">Неизвестный тип: ${BX.util.htmlspecialchars(type)}</div>`,
        `<button class="ui-btn ui-btn-danger ui-btn-xs" data-del-block-id="${id}">Удалить</button>`
      );
    }).join('');
  }

  async function loadLayout() {
    try {
      const res = await api('layout.get', { siteId });
      if (!res || res.ok !== true) {
        notify('Не удалось загрузить layout');
        return;
      }
      layoutSettings = res.layout || layoutSettings;
      fillSettingsForm();
      loadZoneBlocks();
    } catch (e) {
      notify('Ошибка layout.get');
    }
  }

  async function loadZoneBlocks() {
    zoneLabel.textContent = currentZone;
    try {
      const res = await api('layout.block.list', { siteId, zone: currentZone });
      if (!res || res.ok !== true) {
        notify('Не удалось загрузить блоки зоны');
        return;
      }
      renderBlocks(res.blocks || []);
    } catch (e) {
      notify('Ошибка layout.block.list');
    }
  }

  async function saveLayoutSettings() {
    try {
      const res = await api('layout.updateSettings', {
        siteId,
        showHeader: document.getElementById('showHeader').checked ? '1' : '0',
        showFooter: document.getElementById('showFooter').checked ? '1' : '0',
        showLeft: document.getElementById('showLeft').checked ? '1' : '0',
        showRight: document.getElementById('showRight').checked ? '1' : '0',
        leftWidth: parseInt(document.getElementById('leftWidth').value || '260', 10),
        rightWidth: parseInt(document.getElementById('rightWidth').value || '260', 10),
        leftMode: document.getElementById('leftMode').value || 'blocks',
      });
      if (!res || res.ok !== true) {
        notify('Не удалось сохранить настройки');
        return;
      }
      notify('Настройки layout сохранены');
    } catch (e) {
      notify('Ошибка layout.updateSettings');
    }
  }

  function switchZone(zone) {
    currentZone = zone;
    document.querySelectorAll('.zoneBtn').forEach(btn => {
      btn.classList.toggle('active', btn.getAttribute('data-zone') === zone);
    });
    loadZoneBlocks();
  }

  function openTextDialog(mode, block) {
    const current = block?.content?.text || '';
    BX.UI.Dialogs.MessageBox.show({
      title: mode === 'edit' ? 'Редактировать Text' : 'Новый Text',
      message: `<textarea id="lt_text" class="input" style="height:160px;">${BX.util.htmlspecialchars(current)}</textarea>`,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function(mb){
        const text = document.getElementById('lt_text')?.value || '';
        const payload = mode === 'edit'
          ? { siteId, zone: currentZone, id: block.id, text }
          : { siteId, zone: currentZone, type: 'text', text };

        api(mode === 'edit' ? 'layout.block.update' : 'layout.block.create', payload).then(r => {
          if (!r || r.ok !== true) { notify('Не удалось сохранить text'); return; }
          notify('Сохранено');
          mb.close();
          loadZoneBlocks();
        }).catch(() => notify('Ошибка text'));
      }
    });
  }

  async function openImageDialog(mode, block) {
    const curFileId = block?.content?.fileId ? parseInt(block.content.fileId, 10) : 0;
    const curAlt = block?.content?.alt || '';

    BX.UI.Dialogs.MessageBox.show({
      title: mode === 'edit' ? 'Редактировать Image' : 'Новый Image',
      message: `
        <div>
          <div class="field">
            <label>Файл</label>
            <select id="li_file" class="input"><option value="">Загрузка...</option></select>
          </div>
          <div class="field">
            <label>ALT</label>
            <input id="li_alt" class="input" value="${BX.util.htmlspecialchars(curAlt)}" />
          </div>
          <div id="li_prev" class="imgPrev" style="display:${curFileId ? 'block':'none'};">
            <img id="li_prev_img" src="${curFileId ? fileDownloadUrl(curFileId) : ''}" alt="">
          </div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function(mb){
        const fileId = parseInt(document.getElementById('li_file')?.value || '0', 10);
        const alt = (document.getElementById('li_alt')?.value || '').trim();
        if (!fileId) { notify('Выбери файл'); return; }

        const payload = mode === 'edit'
          ? { siteId, zone: currentZone, id: block.id, fileId, alt }
          : { siteId, zone: currentZone, type: 'image', fileId, alt };

        api(mode === 'edit' ? 'layout.block.update' : 'layout.block.create', payload).then(r => {
          if (!r || r.ok !== true) { notify('Не удалось сохранить image'); return; }
          notify('Сохранено');
          mb.close();
          loadZoneBlocks();
        }).catch(() => notify('Ошибка image'));
      }
    });

    setTimeout(async () => {
      const sel = document.getElementById('li_file');
      const wrap = document.getElementById('li_prev');
      const img = document.getElementById('li_prev_img');
      if (!sel || !wrap || !img) return;

      try {
        const files = await getFilesForSite();
        sel.innerHTML = '<option value="">— Выберите файл —</option>' + files.map(f => {
          const s = parseInt(f.id, 10) === curFileId ? 'selected' : '';
          return `<option value="${f.id}" ${s}>${BX.util.htmlspecialchars(f.name)} (${f.id})</option>`;
        }).join('');

        sel.addEventListener('change', function(){
          const fid = parseInt(sel.value || '0', 10);
          if (!fid) { wrap.style.display = 'none'; img.src = ''; return; }
          wrap.style.display = 'block';
          img.src = fileDownloadUrl(fid);
        });
      } catch (e) {
        sel.innerHTML = '<option value="">Ошибка загрузки файлов</option>';
      }
    }, 0);
  }

  function openButtonDialog(mode, block) {
    const curText = block?.content?.text || '';
    const curUrl = block?.content?.url || '';
    const curVariant = block?.content?.variant || 'primary';

    BX.UI.Dialogs.MessageBox.show({
      title: mode === 'edit' ? 'Редактировать Button' : 'Новый Button',
      message: `
        <div>
          <div class="field">
            <label>Текст</label>
            <input id="lb_text" class="input" value="${BX.util.htmlspecialchars(curText)}" />
          </div>
          <div class="field">
            <label>URL</label>
            <input id="lb_url" class="input" value="${BX.util.htmlspecialchars(curUrl)}" />
          </div>
          <div class="field">
            <label>Вариант</label>
            <select id="lb_variant" class="input">
              <option value="primary" ${curVariant === 'primary' ? 'selected' : ''}>primary</option>
              <option value="secondary" ${curVariant === 'secondary' ? 'selected' : ''}>secondary</option>
            </select>
          </div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function(mb){
        const text = (document.getElementById('lb_text')?.value || '').trim();
        const url = (document.getElementById('lb_url')?.value || '').trim();
        const variant = document.getElementById('lb_variant')?.value || 'primary';

        if (!text) { notify('Введите текст'); return; }
        if (!url) { notify('Введите URL'); return; }

        const payload = mode === 'edit'
          ? { siteId, zone: currentZone, id: block.id, text, url, variant }
          : { siteId, zone: currentZone, type: 'button', text, url, variant };

        api(mode === 'edit' ? 'layout.block.update' : 'layout.block.create', payload).then(r => {
          if (!r || r.ok !== true) { notify('Не удалось сохранить button'); return; }
          notify('Сохранено');
          mb.close();
          loadZoneBlocks();
        }).catch(() => notify('Ошибка button'));
      }
    });
  }

  function openHeadingDialog(mode, block) {
    const curText = block?.content?.text || '';
    const curLevel = block?.content?.level || 'h2';
    const curAlign = block?.content?.align || 'left';

    BX.UI.Dialogs.MessageBox.show({
      title: mode === 'edit' ? 'Редактировать Heading' : 'Новый Heading',
      message: `
        <div>
          <div class="field">
            <label>Текст</label>
            <input id="lh_text" class="input" value="${BX.util.htmlspecialchars(curText)}" />
          </div>
          <div class="field">
            <label>Уровень</label>
            <select id="lh_level" class="input">
              <option value="h1" ${curLevel === 'h1' ? 'selected' : ''}>h1</option>
              <option value="h2" ${curLevel === 'h2' ? 'selected' : ''}>h2</option>
              <option value="h3" ${curLevel === 'h3' ? 'selected' : ''}>h3</option>
            </select>
          </div>
          <div class="field">
            <label>Выравнивание</label>
            <select id="lh_align" class="input">
              <option value="left" ${curAlign === 'left' ? 'selected' : ''}>left</option>
              <option value="center" ${curAlign === 'center' ? 'selected' : ''}>center</option>
              <option value="right" ${curAlign === 'right' ? 'selected' : ''}>right</option>
            </select>
          </div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function(mb){
        const text = (document.getElementById('lh_text')?.value || '').trim();
        const level = document.getElementById('lh_level')?.value || 'h2';
        const align = document.getElementById('lh_align')?.value || 'left';
        if (!text) { notify('Введите текст'); return; }

        const payload = mode === 'edit'
          ? { siteId, zone: currentZone, id: block.id, text, level, align }
          : { siteId, zone: currentZone, type: 'heading', text, level, align };

        api(mode === 'edit' ? 'layout.block.update' : 'layout.block.create', payload).then(r => {
          if (!r || r.ok !== true) { notify('Не удалось сохранить heading'); return; }
          notify('Сохранено');
          mb.close();
          loadZoneBlocks();
        }).catch(() => notify('Ошибка heading'));
      }
    });
  }

  function openCols2Dialog(mode, block) {
    const curRatio = block?.content?.ratio || '50-50';
    const curLeft = block?.content?.left || '';
    const curRight = block?.content?.right || '';

    BX.UI.Dialogs.MessageBox.show({
      title: mode === 'edit' ? 'Редактировать Columns2' : 'Новый Columns2',
      message: `
        <div>
          <div class="field">
            <label>Соотношение</label>
            <select id="lc_ratio" class="input">
              <option value="50-50" ${curRatio === '50-50' ? 'selected' : ''}>50 / 50</option>
              <option value="33-67" ${curRatio === '33-67' ? 'selected' : ''}>33 / 67</option>
              <option value="67-33" ${curRatio === '67-33' ? 'selected' : ''}>67 / 33</option>
            </select>
          </div>
          <div class="field">
            <label>Левая колонка</label>
            <textarea id="lc_left" class="input" style="height:120px;">${BX.util.htmlspecialchars(curLeft)}</textarea>
          </div>
          <div class="field">
            <label>Правая колонка</label>
            <textarea id="lc_right" class="input" style="height:120px;">${BX.util.htmlspecialchars(curRight)}</textarea>
          </div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function(mb){
        const ratio = document.getElementById('lc_ratio')?.value || '50-50';
        const left = document.getElementById('lc_left')?.value || '';
        const right = document.getElementById('lc_right')?.value || '';

        const payload = mode === 'edit'
          ? { siteId, zone: currentZone, id: block.id, ratio, left, right }
          : { siteId, zone: currentZone, type: 'columns2', ratio, left, right };

        api(mode === 'edit' ? 'layout.block.update' : 'layout.block.create', payload).then(r => {
          if (!r || r.ok !== true) { notify('Не удалось сохранить columns2'); return; }
          notify('Сохранено');
          mb.close();
          loadZoneBlocks();
        }).catch(() => notify('Ошибка columns2'));
      }
    });
  }

  async function openGalleryDialog(mode, block) {
    const curCols = block?.content?.columns ? parseInt(block.content.columns, 10) : 3;
    const curImages = Array.isArray(block?.content?.images) ? block.content.images : [];

    BX.UI.Dialogs.MessageBox.show({
      title: mode === 'edit' ? 'Редактировать Gallery' : 'Новый Gallery',
      message: `
        <div>
          <div class="field">
            <label>Колонки</label>
            <select id="lg_cols" class="input">
              <option value="2" ${curCols===2?'selected':''}>2</option>
              <option value="3" ${curCols===3?'selected':''}>3</option>
              <option value="4" ${curCols===4?'selected':''}>4</option>
            </select>
          </div>
          <div id="lg_list" style="margin-top:12px;max-height:260px;overflow:auto;border:1px solid #e5e7ea;border-radius:10px;padding:10px;background:#fff;">Загрузка...</div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function(mb){
        const cols = parseInt(document.getElementById('lg_cols')?.value || '3', 10);
        const box = document.getElementById('lg_list');
        if (!box) return;

        const selected = [];
        box.querySelectorAll('input[type="checkbox"][data-fid]').forEach(ch => {
          if (!ch.checked) return;
          const fid = parseInt(ch.getAttribute('data-fid'), 10);
          const alt = (box.querySelector(`input[data-alt-for="${fid}"]`)?.value || '').trim();
          if (fid) selected.push({ fileId: fid, alt });
        });

        if (!selected.length) { notify('Выбери хотя бы 1 файл'); return; }

        const payload = mode === 'edit'
          ? { siteId, zone: currentZone, id: block.id, columns: cols, images: JSON.stringify(selected) }
          : { siteId, zone: currentZone, type: 'gallery', columns: cols, images: JSON.stringify(selected) };

        api(mode === 'edit' ? 'layout.block.update' : 'layout.block.create', payload).then(r => {
          if (!r || r.ok !== true) { notify('Не удалось сохранить gallery'); return; }
          notify('Сохранено');
          mb.close();
          loadZoneBlocks();
        }).catch(() => notify('Ошибка gallery'));
      }
    });

    setTimeout(async () => {
      const box = document.getElementById('lg_list');
      if (!box) return;

      const selectedMap = {};
      curImages.forEach(it => { selectedMap[parseInt(it.fileId,10)] = (it.alt || ''); });

      try {
        const files = await getFilesForSite();
        box.innerHTML = files.map(f => {
          const checked = selectedMap[f.id] !== undefined ? 'checked' : '';
          const altVal = selectedMap[f.id] !== undefined ? selectedMap[f.id] : '';
          return `
            <div class="row" style="justify-content:flex-start;margin:6px 0;">
              <input type="checkbox" data-fid="${f.id}" ${checked}>
              <div style="flex:1;">
                <div><b>${BX.util.htmlspecialchars(f.name)}</b> <span class="muted">(id ${f.id})</span></div>
                <input class="input" style="margin-top:6px;" data-alt-for="${f.id}" value="${BX.util.htmlspecialchars(altVal)}" placeholder="alt">
              </div>
            </div>
          `;
        }).join('');
      } catch (e) {
        box.innerHTML = '<div class="muted">Ошибка загрузки файлов</div>';
      }
    }, 0);
  }

  function openSpacerDialog(mode, block) {
    const curH = block?.content?.height ? parseInt(block.content.height, 10) : 40;
    const curLine = block?.content?.line === true || block?.content?.line === 'true';

    BX.UI.Dialogs.MessageBox.show({
      title: mode === 'edit' ? 'Редактировать Spacer' : 'Новый Spacer',
      message: `
        <div>
          <div class="field">
            <label>Высота</label>
            <input id="ls_h" class="input" type="number" min="10" max="200" value="${curH}">
          </div>
          <div class="field">
            <label><input id="ls_line" type="checkbox" ${curLine ? 'checked' : ''}> Рисовать линию</label>
          </div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function(mb){
        const height = parseInt(document.getElementById('ls_h')?.value || '40', 10);
        const line = document.getElementById('ls_line')?.checked ? '1' : '0';

        const payload = mode === 'edit'
          ? { siteId, zone: currentZone, id: block.id, height, line }
          : { siteId, zone: currentZone, type: 'spacer', height, line };

        api(mode === 'edit' ? 'layout.block.update' : 'layout.block.create', payload).then(r => {
          if (!r || r.ok !== true) { notify('Не удалось сохранить spacer'); return; }
          notify('Сохранено');
          mb.close();
          loadZoneBlocks();
        }).catch(() => notify('Ошибка spacer'));
      }
    });
  }

  async function openCardDialog(mode, block) {
    const curTitle = block?.content?.title || '';
    const curText = block?.content?.text || '';
    const curImage = block?.content?.imageFileId ? parseInt(block.content.imageFileId, 10) : 0;
    const curBtnText = block?.content?.buttonText || '';
    const curBtnUrl = block?.content?.buttonUrl || '';

    BX.UI.Dialogs.MessageBox.show({
      title: mode === 'edit' ? 'Редактировать Card' : 'Новый Card',
      message: `
        <div>
          <div class="field">
            <label>Заголовок</label>
            <input id="lcd_title" class="input" value="${BX.util.htmlspecialchars(curTitle)}">
          </div>
          <div class="field">
            <label>Текст</label>
            <textarea id="lcd_text" class="input" style="height:120px;">${BX.util.htmlspecialchars(curText)}</textarea>
          </div>
          <div class="field">
            <label>Картинка</label>
            <select id="lcd_img" class="input"><option value="">Загрузка...</option></select>
          </div>
          <div id="lcd_prev" class="imgPrev" style="display:${curImage ? 'block':'none'};">
            <img id="lcd_prev_img" src="${curImage ? fileDownloadUrl(curImage) : ''}" alt="">
          </div>
          <div class="field">
            <label>Текст кнопки</label>
            <input id="lcd_btn_text" class="input" value="${BX.util.htmlspecialchars(curBtnText)}">
          </div>
          <div class="field">
            <label>URL кнопки</label>
            <input id="lcd_btn_url" class="input" value="${BX.util.htmlspecialchars(curBtnUrl)}">
          </div>
        </div>
      `,
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function(mb){
        const title = (document.getElementById('lcd_title')?.value || '').trim();
        const text = document.getElementById('lcd_text')?.value || '';
        const imageFileId = parseInt(document.getElementById('lcd_img')?.value || '0', 10);
        const buttonText = (document.getElementById('lcd_btn_text')?.value || '').trim();
        const buttonUrl = (document.getElementById('lcd_btn_url')?.value || '').trim();

        if (!title) { notify('Введите заголовок'); return; }

        const payload = mode === 'edit'
          ? { siteId, zone: currentZone, id: block.id, title, text, imageFileId, buttonText, buttonUrl }
          : { siteId, zone: currentZone, type: 'card', title, text, imageFileId, buttonText, buttonUrl };

        api(mode === 'edit' ? 'layout.block.update' : 'layout.block.create', payload).then(r => {
          if (!r || r.ok !== true) { notify('Не удалось сохранить card'); return; }
          notify('Сохранено');
          mb.close();
          loadZoneBlocks();
        }).catch(() => notify('Ошибка card'));
      }
    });

    setTimeout(async () => {
      const sel = document.getElementById('lcd_img');
      const wrap = document.getElementById('lcd_prev');
      const img = document.getElementById('lcd_prev_img');
      if (!sel || !wrap || !img) return;

      try {
        const files = await getFilesForSite();
        sel.innerHTML = '<option value="0">— без картинки —</option>' + files.map(f => {
          const s = parseInt(f.id,10) === curImage ? 'selected' : '';
          return `<option value="${f.id}" ${s}>${BX.util.htmlspecialchars(f.name)} (id ${f.id})</option>`;
        }).join('');

        sel.addEventListener('change', function(){
          const fid = parseInt(sel.value || '0', 10);
          if (!fid) { wrap.style.display = 'none'; img.src = ''; return; }
          wrap.style.display = 'block';
          img.src = fileDownloadUrl(fid);
        });
      } catch (e) {
        sel.innerHTML = '<option value="0">Ошибка загрузки файлов</option>';
      }
    }, 0);
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
                <label>Текст кнопки</label>
                <input class="input" data-card-btntext="${idx}" value="${btnText}">
              </div>
              <div class="field">
                <label>URL кнопки</label>
                <input class="input" data-card-btnurl="${idx}" value="${btnUrl}">
              </div>
            </div>
          </div>
        </div>
      `;
    }).join('');
  }

  async function openCardsDialog(mode, block) {
    let cols = block?.content?.columns ? parseInt(block.content.columns, 10) : 3;
    if (![2,3,4].includes(cols)) cols = 3;

    let items = Array.isArray(block?.content?.items) ? block.content.items.map(cardsNormalizeItem) : [];
    if (!items.length) items = [
      cardsNormalizeItem({title:'Преимущество 1', text:'Короткое описание'}),
      cardsNormalizeItem({title:'Преимущество 2', text:'Короткое описание'}),
      cardsNormalizeItem({title:'Преимущество 3', text:'Короткое описание'}),
    ];

    let files = [];
    try { files = await getFilesForSite(); } catch (e) { files = []; }

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
      </div>
    `;

    BX.UI.Dialogs.MessageBox.show({
      title: mode === 'edit' ? 'Редактировать Cards' : 'Новый Cards',
      message: render(),
      buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
      onOk: function(mb){
        cols = parseInt(document.getElementById('cb_cols')?.value || '3', 10);
        if (![2,3,4].includes(cols)) { notify('columns должен быть 2/3/4'); return; }

        const collected = items.map((_, idx) => {
          const title = (document.querySelector(`[data-card-title="${idx}"]`)?.value || '').trim();
          const text = document.querySelector(`[data-card-text="${idx}"]`)?.value || '';
          const imageFileId = parseInt(document.querySelector(`[data-card-img="${idx}"]`)?.value || '0', 10) || 0;
          const buttonText = (document.querySelector(`[data-card-btntext="${idx}"]`)?.value || '').trim();
          const buttonUrl = (document.querySelector(`[data-card-btnurl="${idx}"]`)?.value || '').trim();
          return { title, text, imageFileId, buttonText, buttonUrl };
        }).filter(x => x.title !== '');

        if (!collected.length) { notify('Добавь хотя бы 1 карточку'); return; }

        const payload = mode === 'edit'
          ? { siteId, zone: currentZone, id: block.id, columns: cols, items: JSON.stringify(collected) }
          : { siteId, zone: currentZone, type: 'cards', columns: cols, items: JSON.stringify(collected) };

        api(mode === 'edit' ? 'layout.block.update' : 'layout.block.create', payload).then(r => {
          if (!r || r.ok !== true) { notify('Не удалось сохранить cards'); return; }
          notify('Сохранено');
          mb.close();
          loadZoneBlocks();
        }).catch(() => notify('Ошибка cards'));
      }
    });

    setTimeout(() => {
      const root = document.querySelector('.cardsBuilder');
      if (!root) return;

      const snapshot = () => {
        items = items.map((it, idx) => ({
          title: document.querySelector(`[data-card-title="${idx}"]`)?.value || it.title || '',
          text: document.querySelector(`[data-card-text="${idx}"]`)?.value || it.text || '',
          imageFileId: parseInt(document.querySelector(`[data-card-img="${idx}"]`)?.value || it.imageFileId || 0, 10) || 0,
          buttonText: document.querySelector(`[data-card-btntext="${idx}"]`)?.value || it.buttonText || '',
          buttonUrl: document.querySelector(`[data-card-btnurl="${idx}"]`)?.value || it.buttonUrl || '',
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
          btn.onclick = () => {
            snapshot();
            const i = parseInt(btn.getAttribute('data-card-up'), 10);
            if (i > 0) { [items[i-1], items[i]] = [items[i], items[i-1]]; rerender(); }
          };
        });

        root.querySelectorAll('[data-card-down]').forEach(btn => {
          btn.onclick = () => {
            snapshot();
            const i = parseInt(btn.getAttribute('data-card-down'), 10);
            if (i < items.length - 1) { [items[i+1], items[i]] = [items[i], items[i+1]]; rerender(); }
          };
        });

        root.querySelectorAll('[data-card-del]').forEach(btn => {
          btn.onclick = () => {
            snapshot();
            const i = parseInt(btn.getAttribute('data-card-del'), 10);
            items.splice(i, 1);
            if (!items.length) items.push(cardsNormalizeItem({ title: 'Новая карточка' }));
            rerender();
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

  async function editBlockByType(id) {
    try {
      const res = await api('layout.block.list', { siteId, zone: currentZone });
      if (!res || res.ok !== true) return;
      const block = (res.blocks || []).find(x => parseInt(x.id, 10) === id);
      if (!block) return;

      switch (block.type) {
        case 'text': openTextDialog('edit', block); break;
        case 'image': openImageDialog('edit', block); break;
        case 'button': openButtonDialog('edit', block); break;
        case 'heading': openHeadingDialog('edit', block); break;
        case 'columns2': openCols2Dialog('edit', block); break;
        case 'gallery': openGalleryDialog('edit', block); break;
        case 'spacer': openSpacerDialog('edit', block); break;
        case 'card': openCardDialog('edit', block); break;
        case 'cards': openCardsDialog('edit', block); break;
        default: notify('Редактирование этого типа пока не поддержано');
      }
    } catch (e) {
      notify('Ошибка загрузки блока');
    }
  }

  document.addEventListener('click', function(e) {
    const z = e.target.closest('.zoneBtn');
    if (z) {
      switchZone(z.getAttribute('data-zone'));
      return;
    }

    const mv = e.target.closest('[data-move-block-id]');
    if (mv) {
      const id = parseInt(mv.getAttribute('data-move-block-id'), 10);
      const dir = mv.getAttribute('data-move-dir');
      api('layout.block.move', { siteId, zone: currentZone, id, dir }).then(r => {
        if (!r || r.ok !== true) { notify('Не удалось переместить'); return; }
        loadZoneBlocks();
      }).catch(() => notify('Ошибка layout.block.move'));
      return;
    }

    const del = e.target.closest('[data-del-block-id]');
    if (del) {
      const id = parseInt(del.getAttribute('data-del-block-id'), 10);
      BX.UI.Dialogs.MessageBox.show({
        title: 'Удалить блок #' + id + '?',
        message: 'Продолжить?',
        buttons: BX.UI.Dialogs.MessageBoxButtons.OK_CANCEL,
        onOk: function(mb){
          api('layout.block.delete', { siteId, zone: currentZone, id }).then(r => {
            if (!r || r.ok !== true) { notify('Не удалось удалить'); return; }
            notify('Удалено');
            mb.close();
            loadZoneBlocks();
          }).catch(() => notify('Ошибка layout.block.delete'));
        }
      });
      return;
    }

    const editSelectors = [
      '[data-edit-text-id]',
      '[data-edit-image-id]',
      '[data-edit-button-id]',
      '[data-edit-heading-id]',
      '[data-edit-cols2-id]',
      '[data-edit-gallery-id]',
      '[data-edit-spacer-id]',
      '[data-edit-card-id]',
      '[data-edit-cards-id]',
    ];

    for (const selector of editSelectors) {
      const btn = e.target.closest(selector);
      if (btn) {
        const attr = Object.keys(btn.dataset).find(k => k.startsWith('edit'));
        const id = parseInt(btn.dataset[attr], 10);
        editBlockByType(id);
        return;
      }
    }
  });

  document.getElementById('btnSaveLayoutSettings').addEventListener('click', saveLayoutSettings);

  document.getElementById('btnAddText').addEventListener('click', () => openTextDialog('create'));
  document.getElementById('btnAddImage').addEventListener('click', () => openImageDialog('create'));
  document.getElementById('btnAddButton').addEventListener('click', () => openButtonDialog('create'));
  document.getElementById('btnAddHeading').addEventListener('click', () => openHeadingDialog('create'));
  document.getElementById('btnAddCols2').addEventListener('click', () => openCols2Dialog('create'));
  document.getElementById('btnAddGallery').addEventListener('click', () => openGalleryDialog('create'));
  document.getElementById('btnAddSpacer').addEventListener('click', () => openSpacerDialog('create'));
  document.getElementById('btnAddCard').addEventListener('click', () => openCardDialog('create'));
  document.getElementById('btnAddCards').addEventListener('click', () => openCardsDialog('create'));

  loadLayout();
});
</script>
</body>
</html>