<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';

sitebuilder_require_auth();

global $APPLICATION, $USER;

$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$siteId = (int)($_GET['siteId'] ?? 0);
$entityType = strtolower(trim((string)($_GET['entityType'] ?? 'site')));
$entityId = (int)($_GET['entityId'] ?? 0);
$allowedTypes = ['site', 'menu', 'layout'];

foreach ([
    __DIR__ . '/lib/db.php',
    __DIR__ . '/lib/json.php',
    __DIR__ . '/lib/response.php',
    __DIR__ . '/lib/helpers.php',
    __DIR__ . '/lib/access.php',
] as $libFile) {
    require_once $libFile;
}

if ($siteId <= 0 || $entityId <= 0 || !in_array($entityType, $allowedTypes, true)) {
    http_response_code(422);
    die('Некорректные параметры истории.');
}

if (!$USER->IsAdmin()) {
    sb_require_content_manager($siteId);
}

$typeTitles = [
    'site' => 'Настройки сайта',
    'menu' => 'Меню',
    'layout' => 'Layout',
];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SiteBuilder / История</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    <style>
        .sb-history-grid{display:grid;grid-template-columns:minmax(420px,1fr) minmax(360px,.9fr);gap:16px;align-items:start}
        .sb-history-list{display:flex;flex-direction:column;gap:10px}
        .sb-history-row{border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;display:flex;justify-content:space-between;gap:14px;align-items:flex-start}
        .sb-history-row.is-selected{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
        .sb-history-json{min-height:520px;max-height:70vh;overflow:auto;white-space:pre-wrap;word-break:break-word}
        .sb-history-meta{font-size:12px;color:#64748b;line-height:1.6}
        @media(max-width:980px){.sb-history-grid{grid-template-columns:1fr}}
    </style>
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-topbar">
        <div>
            <a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/editor.php?siteId=<?= (int)$siteId ?>">← В редактор</a>
            <h1 class="sb-title">История: <?= htmlspecialchars($typeTitles[$entityType], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <p class="sb-subtitle">siteId = <?= (int)$siteId ?> · <?= htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8') ?> #<?= (int)$entityId ?> · текущая версия <span id="currentVersion">—</span></p>
        </div>
        <div class="sb-actions">
            <button class="sb-btn sb-btn-light" type="button" id="reloadBtn">Обновить</button>
        </div>
    </div>

    <div id="message" class="sb-panel" style="display:none"></div>

    <div class="sb-history-grid">
        <section class="sb-panel">
            <h2 class="sb-panel-title">Ревизии</h2>
            <div id="historyList" class="sb-history-list"><div class="sb-empty">Загрузка…</div></div>
        </section>
        <section class="sb-panel">
            <div class="sb-toolbar" style="justify-content:space-between;margin-bottom:12px">
                <h2 class="sb-panel-title" style="margin:0">Снимок</h2>
                <button class="sb-btn sb-btn-primary" type="button" id="restoreBtn" disabled>Восстановить эту версию</button>
            </div>
            <div id="revisionMeta" class="sb-history-meta">Выберите ревизию.</div>
            <pre id="snapshot" class="sb-output sb-history-json">—</pre>
        </section>
    </div>
</div>
<script>
(function(){
    'use strict';
    var BASE_PATH = '<?= CUtil::JSEscape($basePath) ?>';
    var API_URL = BASE_PATH + '/api.php';
    var SITE_ID = <?= (int)$siteId ?>;
    var ENTITY_TYPE = '<?= CUtil::JSEscape($entityType) ?>';
    var ENTITY_ID = <?= (int)$entityId ?>;
    var state = {items:[], currentVersion:0, selectedRevision:null};

    function sessid(){return window.BX&&typeof BX.bitrix_sessid==='function'?BX.bitrix_sessid():'<?= CUtil::JSEscape(bitrix_sessid()) ?>';}
    async function api(action,data){
        var response=await fetch(API_URL,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams(Object.assign({action:action,sessid:sessid()},data||{}))});
        var text=await response.text(),json;
        try{json=JSON.parse(text);}catch(e){throw {error:'BAD_JSON_RESPONSE',status:response.status,text:text};}
        if(!json||json.ok!==true)throw json||{error:'UNKNOWN_ERROR'};
        return json;
    }
    function esc(value){return String(value==null?'':value).replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];});}
    function message(text,error){var n=document.getElementById('message');n.style.display='block';n.textContent=text;n.style.color=error?'#991b1b':'#166534';}
    function clearMessage(){document.getElementById('message').style.display='none';}
    function userName(id,users){var u=users&&users[id];return u?(u.name||u.login||('Пользователь #'+id)):(id?('Пользователь #'+id):'Система');}

    async function loadCurrent(){
        var version=1;
        if(ENTITY_TYPE==='site'){
            var r=await api('site.get',{siteId:SITE_ID});version=Number((r.site&&r.site.version)||1);
        }else if(ENTITY_TYPE==='menu'){
            var m=await api('menu.list',{siteId:SITE_ID});var item=(m.menus||[]).find(function(x){return Number(x.id)===ENTITY_ID;});if(!item)throw {error:'MENU_NOT_FOUND'};version=Number(item.version||1);
        }else{
            var l=await api('layout.get',{siteId:SITE_ID});version=Number((l.layout&&l.layout.version)||1);
        }
        state.currentVersion=version;document.getElementById('currentVersion').textContent=String(version);
    }

    async function loadHistory(){
        var r=await api('history.list',{entityType:ENTITY_TYPE,entityId:ENTITY_ID,limit:100});
        state.items=Array.isArray(r.items)?r.items:[];
        var list=document.getElementById('historyList');
        if(!state.items.length){list.innerHTML='<div class="sb-empty">История пока пуста</div>';return;}
        list.innerHTML=state.items.map(function(item){
            var selected=state.selectedRevision&&Number(state.selectedRevision.id)===Number(item.id)?' is-selected':'';
            return '<div class="sb-history-row'+selected+'"><div><strong>Версия '+Number(item.version||1)+'</strong><div class="sb-history-meta">'+esc(item.operation||'update')+' · '+esc(item.createdAt||'')+'<br>'+esc(userName(Number(item.createdBy||0),r.users||{}))+'</div></div><button class="sb-btn sb-btn-light sb-btn-small js-open" data-id="'+Number(item.id)+'">Открыть</button></div>';
        }).join('');
    }

    async function openRevision(id){
        clearMessage();var r=await api('history.get',{revisionId:id});state.selectedRevision=r.revision||null;
        var rev=state.selectedRevision;if(!rev)return;
        document.getElementById('revisionMeta').textContent='Ревизия #'+rev.id+' · версия '+rev.version+' · '+(rev.operation||'update')+' · '+(rev.createdAt||'');
        document.getElementById('snapshot').textContent=JSON.stringify(rev.snapshot||{},null,2);
        document.getElementById('restoreBtn').disabled=String(rev.operation||'')==='delete';
        await loadHistory();
    }

    async function restoreSelected(){
        if(!state.selectedRevision)return;
        if(!confirm('Восстановить выбранный снимок как новую версию?'))return;
        clearMessage();
        try{
            var r=await api('history.restore',{revisionId:Number(state.selectedRevision.id),expectedVersion:Number(state.currentVersion)});
            state.currentVersion=Number((r.entity&&r.entity.version)||state.currentVersion+1);
            document.getElementById('currentVersion').textContent=String(state.currentVersion);
            message('Версия восстановлена. Создана новая ревизия.',false);
            await loadHistory();
        }catch(e){
            if(e&&e.error==='VERSION_CONFLICT')await loadCurrent();
            message('Не удалось восстановить: '+((e&&e.error)||'UNKNOWN_ERROR'),true);
        }
    }

    async function loadAll(){clearMessage();state.selectedRevision=null;document.getElementById('restoreBtn').disabled=true;document.getElementById('snapshot').textContent='—';await loadCurrent();await loadHistory();}
    document.getElementById('reloadBtn').addEventListener('click',function(){loadAll().catch(function(e){message('Ошибка загрузки: '+((e&&e.error)||'UNKNOWN_ERROR'),true);});});
    document.getElementById('restoreBtn').addEventListener('click',restoreSelected);
    document.addEventListener('click',function(e){var b=e.target.closest('.js-open');if(b)openRevision(Number(b.getAttribute('data-id'))).catch(function(err){message('Ошибка: '+((err&&err.error)||'UNKNOWN_ERROR'),true);});});
    loadAll().catch(function(e){message('Ошибка загрузки: '+((e&&e.error)||'UNKNOWN_ERROR'),true);});
})();
</script>
</body>
</html>
