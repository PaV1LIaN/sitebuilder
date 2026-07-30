<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';

sitebuilder_require_auth();

global $APPLICATION, $USER;

$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$siteId = (int)($_GET['siteId'] ?? 0);

foreach ([
    __DIR__ . '/lib/db.php',
    __DIR__ . '/lib/json.php',
    __DIR__ . '/lib/response.php',
    __DIR__ . '/lib/helpers.php',
    __DIR__ . '/lib/access.php',
] as $libFile) {
    require_once $libFile;
}

if ($siteId <= 0) {
    http_response_code(422);
    die('Не передан siteId.');
}

if (!$USER->IsAdmin()) {
    sb_require_content_manager($siteId);
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SiteBuilder / Корзина</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    <style>
        .sb-trash-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px}
        .sb-trash-card{border:1px solid #e5e7eb;border-radius:14px;background:#fff;padding:16px}
        .sb-trash-title{margin:0 0 8px;font-size:17px}.sb-trash-meta{color:#64748b;font-size:13px;line-height:1.6}
    </style>
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-topbar">
        <div>
            <a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/editor.php?siteId=<?= (int)$siteId ?>">← В редактор</a>
            <h1 class="sb-title">Корзина страниц</h1>
            <p class="sb-subtitle">Удалённые ветки сайта #<?= (int)$siteId ?></p>
        </div>
        <button class="sb-btn sb-btn-light" type="button" id="reloadBtn">Обновить</button>
    </div>
    <div id="message" class="sb-panel" style="display:none"></div>
    <div id="trashList"><div class="sb-empty">Загрузка…</div></div>
</div>
<script>
(function(){
    'use strict';
    var BASE_PATH='<?= CUtil::JSEscape($basePath) ?>',API_URL=BASE_PATH+'/api.php',SITE_ID=<?= (int)$siteId ?>;
    function sessid(){return window.BX&&typeof BX.bitrix_sessid==='function'?BX.bitrix_sessid():'<?= CUtil::JSEscape(bitrix_sessid()) ?>';}
    async function api(action,data){var r=await fetch(API_URL,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams(Object.assign({action:action,sessid:sessid()},data||{}))});var t=await r.text(),j;try{j=JSON.parse(t);}catch(e){throw {error:'BAD_JSON_RESPONSE',text:t};}if(!j||j.ok!==true)throw j||{error:'UNKNOWN_ERROR'};return j;}
    function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
    function msg(text,error){var n=document.getElementById('message');n.style.display='block';n.style.color=error?'#991b1b':'#166534';n.textContent=text;}
    function clearMsg(){document.getElementById('message').style.display='none';}
    async function load(){clearMsg();var r=await api('trash.list',{siteId:SITE_ID});var items=Array.isArray(r.items)?r.items:[],node=document.getElementById('trashList');if(!items.length){node.innerHTML='<div class="sb-empty">Корзина пуста</div>';return;}node.innerHTML='<div class="sb-trash-grid">'+items.map(function(x){return '<article class="sb-trash-card"><h2 class="sb-trash-title">'+esc(x.title||('Страница #'+x.rootEntityId))+'</h2><div class="sb-trash-meta">Запись #'+Number(x.id)+'<br>Корневая страница #'+Number(x.rootEntityId)+'<br>Удалено: '+esc(x.deletedAt||'')+'</div><div class="sb-actions" style="margin-top:14px"><button class="sb-btn sb-btn-primary sb-btn-small js-restore" data-id="'+Number(x.id)+'">Восстановить</button><button class="sb-btn sb-btn-light sb-btn-small js-details" data-id="'+Number(x.id)+'">Состав</button><button class="sb-btn sb-btn-danger sb-btn-small js-purge" data-id="'+Number(x.id)+'">Удалить навсегда</button></div></article>';}).join('')+'</div>';}
    async function details(id){var r=await api('trash.get',{id:id}),s=(r.item&&r.item.snapshot)||{};alert('Страниц: '+((s.pages||[]).length)+'\nБлоков: '+((s.blocks||[]).length)+'\nСекций: '+((s.sections||[]).length)+'\nЗаписей прав: '+((s.pageAccess||[]).length));}
    async function restore(id){if(!confirm('Восстановить страницу, дочерние страницы, блоки, секции и права?'))return;try{var r=await api('trash.restore',{id:id}),root=Number((r.result&&r.result.rootPageId)||0);msg('Ветка восстановлена.',false);await load();if(root>0&&confirm('Открыть восстановленную страницу в редакторе?'))location.href=BASE_PATH+'/editor.php?siteId='+SITE_ID+'&pageId='+root;}catch(e){msg('Не удалось восстановить: '+((e&&e.error)||'UNKNOWN_ERROR'),true);}}
    async function purge(id){if(!confirm('Удалить снимок без возможности восстановления?'))return;try{await api('trash.purge',{id:id});msg('Снимок удалён.',false);await load();}catch(e){msg('Не удалось удалить: '+((e&&e.error)||'UNKNOWN_ERROR'),true);}}
    document.getElementById('reloadBtn').addEventListener('click',function(){load().catch(function(e){msg('Ошибка: '+((e&&e.error)||'UNKNOWN_ERROR'),true);});});
    document.addEventListener('click',function(e){var b=e.target.closest('[data-id]');if(!b)return;var id=Number(b.getAttribute('data-id'));if(b.classList.contains('js-details'))details(id).catch(function(x){msg('Ошибка: '+((x&&x.error)||'UNKNOWN_ERROR'),true);});else if(b.classList.contains('js-restore'))restore(id);else if(b.classList.contains('js-purge'))purge(id);});
    load().catch(function(e){msg('Ошибка: '+((e&&e.error)||'UNKNOWN_ERROR'),true);});
})();
</script>
</body>
</html>
