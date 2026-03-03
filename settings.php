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
  <title>Настройки сайта</title>
  <?php $APPLICATION->ShowHead(); ?>
  <style>
    body{margin:0;font-family:Arial,sans-serif;background:#f6f7f8;color:#111;}
    .wrap{max-width:1100px;margin:0 auto;padding:22px;}
    .top{
      background:#fff;border:1px solid #e5e7ea;border-radius:16px;
      padding:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;
    }
    .title{font-weight:800;font-size:18px;}
    .muted{color:#6a737f;font-size:12px;}
    code{background:#f3f4f6;padding:2px 6px;border-radius:6px;}
    .sp{flex:1;}
    .card{
      margin-top:14px;background:#fff;border:1px solid #e5e7ea;border-radius:16px;
      padding:16px;
    }
    .grid{display:grid;gap:12px;margin-top:12px;}
    @media(min-width:860px){.grid{grid-template-columns:1fr 1fr;}}
    .field label{display:block;font-size:12px;color:#6a737f;margin-bottom:4px;}
    .input{
      width:100%;padding:9px 10px;border:1px solid #d0d7de;border-radius:10px;
      box-sizing:border-box;background:#fff;
    }
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
    .logoPrev{
      width:44px;height:44px;border-radius:12px;border:1px solid #e5e7ea;background:#fafafa;
      display:flex;align-items:center;justify-content:center;overflow:hidden;
    }
    .logoPrev img{width:100%;height:100%;object-fit:cover;display:block;}
    .hint{margin-top:8px;font-size:12px;color:#6a737f;}
    .sep{height:1px;background:#eef0f2;margin:14px 0;}
  </style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <a class="ui-btn ui-btn-light ui-btn-xs" href="/local/sitebuilder/index.php">← Назад</a>
    <div>
      <div class="title">Настройки сайта</div>
      <div class="muted">siteId: <code><?= (int)$siteId ?></code></div>
    </div>
    <div class="sp"></div>
    <a class="ui-btn ui-btn-light ui-btn-xs" id="btnOpenMenu" href="/local/sitebuilder/menu.php?siteId=<?= (int)$siteId ?>">Меню</a>
    <a class="ui-btn ui-btn-light ui-btn-xs" id="btnOpenFiles" href="/local/sitebuilder/files.php?siteId=<?= (int)$siteId ?>" target="_blank">Файлы</a>
    <button class="ui-btn ui-btn-primary" id="btnSave">Сохранить</button>
  </div>

  <div class="card">
    <div class="muted">Тут настраиваем внешний вид и базовые параметры сайта. Сохранение доступно ADMIN/OWNER.</div>

    <div class="grid">
      <div class="field">
        <label>Название сайта</label>
        <input class="input" id="f_name" placeholder="Например: Лаборатория" />
      </div>

      <div class="field">
        <label>Slug (если пусто — пересчитаем)</label>
        <input class="input" id="f_slug" placeholder="lab" />
      </div>

      <div class="field">
        <label>Ширина контейнера (900–1600)</label>
        <div class="row">
          <input class="input" style="flex:1;min-width:180px;" type="range" min="900" max="1600" step="10" id="f_containerRange">
          <input class="input" style="width:140px;" type="number" min="900" max="1600" step="10" id="f_containerWidth">
        </div>
        <div class="hint">Применяется в <code>view.php</code> как <code>--sb-container</code>.</div>
      </div>

      <div class="field">
        <label>Accent (цвет)</label>
        <div class="row">
          <input class="input" style="width:90px;padding:0;height:40px;" type="color" id="f_accentColor" value="#2563eb">
          <input class="input" style="flex:1;min-width:180px;" id="f_accentText" placeholder="#2563eb">
        </div>
        <div class="hint">Формат строго <code>#RRGGBB</code>.</div>
      </div>
    </div>

    <div class="sep"></div>

    <div class="grid">
      <div class="field">
        <label>Логотип (файл из папки сайта)</label>
        <div class="row">
          <div class="logoPrev" id="logoPrev">SB</div>
          <select class="input" id="f_logoFile">
            <option value="0">— Без логотипа —</option>
          </select>
        </div>
        <div class="hint">Файлы берём из <code>files.php</code> (Disk папка сайта).</div>
      </div>

      <div class="field">
        <label>Домашняя страница</label>
        <select class="input" id="f_homePage">
          <option value="0">— Не задана —</option>
        </select>
        <div class="hint">Если задано — дальше можно будет сделать <code>/public.php?siteId=...</code> с редиректом на home.</div>
      </div>

      <div class="field">
        <label>Верхнее меню</label>
        <select class="input" id="f_topMenu">
          <option value="0">— Авто (первое) —</option>
        </select>
        <div class="hint">То же самое, что кнопка “Сделать верхним” в <code>menu.php</code>.</div>
      </div>
    </div>

  </div>

</div>

<script>
BX.ready(function(){
  const siteId = <?= (int)$siteId ?>;

  const $ = (id)=>document.getElementById(id);
  const btnSave = $('btnSave');

  function notify(msg){ BX.UI.Notification.Center.notify({content: msg}); }

  function api(action, data) {
    return new Promise((resolve) => {
        BX.ajax({
        url: '/local/sitebuilder/api.php',
        method: 'POST',
        dataType: 'json',
        data: Object.assign(
            { action, siteId, sessid: BX.bitrix_sessid() }, // <-- КРИТИЧНО: siteId всегда уходит
            data || {}
        ),
        onsuccess: (res) => resolve(res),
        onfailure: (xhr) => {
            const status = xhr?.status || 0;
            const raw = xhr?.responseText ? String(xhr.responseText) : '';
            let parsed = null;
            try { parsed = JSON.parse(raw); } catch (e) {}

            resolve(Object.assign(
            { ok: false, error: 'HTTP_ERROR', status, raw },
            (parsed && typeof parsed === 'object') ? parsed : {}
            ));
        }
        });
    });
  }

  function setLogoPreview(fileId){
    const prev = $('logoPrev');
    if (!prev) return;

    const fid = parseInt(fileId||0,10)||0;
    if (fid <= 0){
      prev.innerHTML = 'SB';
      return;
    }

    const img = document.createElement('img');
    img.src = '/local/sitebuilder/download.php?siteId=' + siteId + '&fileId=' + fid;
    img.alt = 'logo';
    prev.innerHTML = '';
    prev.appendChild(img);
  }

  function fillSelect(selectEl, items, getValue, getLabel, firstOptionKept=true){
    if (!selectEl) return;
    const keep = firstOptionKept ? selectEl.querySelector('option[value="0"]') : null;
    selectEl.innerHTML = '';
    if (keep) selectEl.appendChild(keep);

    items.forEach(it=>{
      const opt = document.createElement('option');
      opt.value = String(getValue(it));
      opt.textContent = getLabel(it);
      selectEl.appendChild(opt);
    });
  }

  async function load(){
    if (!siteId){
      notify('siteId не задан');
      return;
    }

    try{
      const [sres, pres, mres, fres] = await Promise.all([
        api('site.get'),
        api('page.list'),
        api('menu.list'),
        api('file.list')
      ]);

      if (!sres || sres.ok !== true){
        console.log('site.get debug:', sres);
        notify(`site.get: ${sres.error || 'UNKNOWN'}${sres.status ? ' (HTTP '+sres.status+')' : ''}${sres.role ? ' role='+sres.role : ''}`);
        return;
      }
      if (!pres || pres.ok !== true){ notify('page.list: нет доступа'); return; }
      if (!mres || mres.ok !== true){ notify('menu.list: нет доступа'); return; }
      if (!fres || fres.ok !== true){ notify('file.list: нет доступа'); return; }

      const site = sres.site || {};
      const settings = (site.settings && typeof site.settings === 'object') ? site.settings : {};

      $('f_name').value = site.name || '';
      $('f_slug').value = site.slug || '';

      const w = parseInt(settings.containerWidth || 1100,10) || 1100;
      $('f_containerWidth').value = String(w);
      $('f_containerRange').value = String(w);

      const accent = (settings.accent || '#2563eb').toString();
      $('f_accentText').value = accent;
      $('f_accentColor').value = accent;

      const logoFileId = parseInt(settings.logoFileId||0,10)||0;

      // pages
      const pages = pres.pages || [];
      fillSelect($('f_homePage'), pages, x=>x.id, x=>`#${x.id} ${x.title} (${x.slug})`);
      $('f_homePage').value = String(parseInt(site.homePageId||0,10)||0);

      // menus
      const menus = mres.menus || [];
      fillSelect($('f_topMenu'), menus, x=>x.id, x=>`#${x.id} ${x.name}`);
      $('f_topMenu').value = String(parseInt(site.topMenuId||0,10)||0);

      // files for logo
      const files = fres.files || [];
      fillSelect($('f_logoFile'), files, x=>x.id, x=>`#${x.id} ${x.name}`);
      $('f_logoFile').value = String(logoFileId);
      setLogoPreview(logoFileId);

    } catch(e){
      notify('Ошибка загрузки настроек');
    }
  }

  function bind(){
    // width sync
    $('f_containerRange').addEventListener('input', ()=>{
      $('f_containerWidth').value = $('f_containerRange').value;
    });
    $('f_containerWidth').addEventListener('input', ()=>{
      $('f_containerRange').value = $('f_containerWidth').value;
    });

    // accent sync
    $('f_accentColor').addEventListener('input', ()=>{
      $('f_accentText').value = $('f_accentColor').value;
    });
    $('f_accentText').addEventListener('input', ()=>{
      const v = $('f_accentText').value.trim();
      if (/^#[0-9a-fA-F]{6}$/.test(v)) $('f_accentColor').value = v;
    });

    $('f_logoFile').addEventListener('change', ()=>{
      setLogoPreview($('f_logoFile').value);
    });

    btnSave.addEventListener('click', async ()=>{
      const payload = {
        name: $('f_name').value.trim(),
        slug: $('f_slug').value.trim(),
        containerWidth: parseInt($('f_containerWidth').value||'1100',10) || 1100,
        accent: $('f_accentText').value.trim(),
        logoFileId: parseInt($('f_logoFile').value||'0',10) || 0,
        homePageId: parseInt($('f_homePage').value||'0',10) || 0,
        topMenuId: parseInt($('f_topMenu').value||'0',10) || 0
      };

      try{
        const r = await api('site.update', payload);

        if (!r || r.ok !== true) {
            const msg =
            'site.update failed' +
            (r?.status ? ` (HTTP ${r.status})` : '') +
            `: ${r?.error || 'UNKNOWN'}` +
            (r?.message ? ` — ${r.message}` : '') +
            (r?.raw ? `\n\n${String(r.raw).slice(0, 500)}` : '');

            notify(msg.replace(/\n/g, '<br>')); // если notify умеет html, иначе убери replace
            console.log('site.update debug:', r);
            return;
        }

        notify('Сохранено');
      }catch(e){
        notify('Ошибка site.update');
      }
    });
  }

  bind();
  load();
});
</script>
</body>
</html>