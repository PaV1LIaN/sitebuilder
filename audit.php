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

$isBitrixAdmin = $USER->IsAdmin();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SiteBuilder / Журнал действий</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/assets/admin/admin.css">
    <style>
        .sb-audit-filters{display:grid;grid-template-columns:repeat(6,minmax(150px,1fr));gap:10px;align-items:end}.sb-audit-table-wrap{overflow:auto}.sb-audit-table{width:100%;border-collapse:collapse;min-width:1050px}.sb-audit-table th,.sb-audit-table td{padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top;font-size:13px}.sb-audit-table th{background:#f8fafc;color:#475569;position:sticky;top:0}.sb-audit-status{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700}.sb-audit-status.success{color:#166534;background:#dcfce7}.sb-audit-status.error{color:#991b1b;background:#fee2e2}.sb-audit-details{min-height:280px;max-height:65vh;overflow:auto;white-space:pre-wrap;word-break:break-word}.sb-audit-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(340px,.7fr);gap:16px;align-items:start}.sb-audit-pager{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px}.sb-maintenance-meta{font-size:13px;color:#64748b;line-height:1.6;white-space:pre-line}@media(max-width:1200px){.sb-audit-filters{grid-template-columns:repeat(3,minmax(160px,1fr))}.sb-audit-grid{grid-template-columns:1fr}}@media(max-width:680px){.sb-audit-filters{grid-template-columns:1fr}}
    </style>
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-topbar">
        <div>
            <a class="sb-back-link" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/editor.php?siteId=<?= (int)$siteId ?>">← В редактор</a>
            <h1 class="sb-title">Журнал действий</h1>
            <p class="sb-subtitle">Изменения сайта #<?= (int)$siteId ?>, ошибки API и служебные операции</p>
        </div>
        <button class="sb-btn sb-btn-light" type="button" id="reloadBtn">Обновить</button>
    </div>

    <div id="message" class="sb-panel" style="display:none"></div>

    <section class="sb-panel">
        <h2 class="sb-panel-title">Фильтры</h2>
        <div class="sb-audit-filters">
            <label class="sb-field"><span>Действие</span><input class="sb-input" id="actionFilter" placeholder="page.save"></label>
            <label class="sb-field"><span>Тип объекта</span><input class="sb-input" id="entityTypeFilter" placeholder="page, block…"></label>
            <label class="sb-field"><span>ID пользователя</span><input class="sb-input" type="number" min="0" id="actorFilter"></label>
            <label class="sb-field"><span>Результат</span><select class="sb-select" id="outcomeFilter"><option value="">Все</option><option value="success">Успешно</option><option value="error">Ошибка</option></select></label>
            <label class="sb-field"><span>С даты</span><input class="sb-input" type="datetime-local" id="dateFromFilter"></label>
            <label class="sb-field"><span>По дату</span><input class="sb-input" type="datetime-local" id="dateToFilter"></label>
        </div>
        <div class="sb-actions" style="margin-top:12px"><button class="sb-btn sb-btn-primary" type="button" id="applyFilterBtn">Применить</button><button class="sb-btn sb-btn-light" type="button" id="resetFilterBtn">Сбросить</button></div>
    </section>

    <div class="sb-audit-grid">
        <section class="sb-panel">
            <div class="sb-audit-table-wrap">
                <table class="sb-audit-table">
                    <thead><tr><th>Время</th><th>Пользователь</th><th>Действие</th><th>Объект</th><th>Результат</th><th>IP</th><th></th></tr></thead>
                    <tbody id="auditRows"><tr><td colspan="7">Загрузка…</td></tr></tbody>
                </table>
            </div>
            <div class="sb-audit-pager"><button class="sb-btn sb-btn-light sb-btn-small" id="prevBtn" type="button">← Назад</button><span id="pagerText">—</span><button class="sb-btn sb-btn-light sb-btn-small" id="nextBtn" type="button">Вперёд →</button></div>
        </section>

        <aside>
            <section class="sb-panel">
                <h2 class="sb-panel-title">Подробности</h2>
                <div id="detailMeta" class="sb-subtitle">Выберите запись</div>
                <pre id="detailJson" class="sb-output sb-audit-details">—</pre>
            </section>

            <?php if ($isBitrixAdmin): ?>
                <section class="sb-panel">
                    <h2 class="sb-panel-title">Очистка истории</h2>
                    <div id="maintenanceMeta" class="sb-maintenance-meta">Загрузка состояния…</div>
                    <div class="sb-actions" style="margin-top:12px"><button class="sb-btn sb-btn-light" type="button" id="runMaintenanceBtn">Запустить сейчас</button></div>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>
<script>
(function(){
    'use strict';
    var BASE_PATH='<?= CUtil::JSEscape($basePath) ?>',API_URL=BASE_PATH+'/api.php',SITE_ID=<?= (int)$siteId ?>,IS_ADMIN=<?= $isBitrixAdmin ? 'true' : 'false' ?>;
    var state={offset:0,limit:100,total:0,users:{}};
    function sessid(){return window.BX&&typeof BX.bitrix_sessid==='function'?BX.bitrix_sessid():'<?= CUtil::JSEscape(bitrix_sessid()) ?>';}
    async function api(action,data){var r=await fetch(API_URL,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams(Object.assign({action:action,sessid:sessid()},data||{}))});var t=await r.text(),j;try{j=JSON.parse(t);}catch(e){throw {error:'BAD_JSON_RESPONSE',text:t};}if(!j||j.ok!==true)throw j||{error:'UNKNOWN_ERROR'};return j;}
    function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
    function msg(text,error){var n=document.getElementById('message');n.style.display='block';n.style.color=error?'#991b1b':'#166534';n.textContent=text;}
    function clearMsg(){document.getElementById('message').style.display='none';}
    function userName(id){var u=state.users&&state.users[id];return u?(u.name||u.login||('Пользователь #'+id)):(id?('Пользователь #'+id):'Система');}
    function filters(){return {siteId:SITE_ID,limit:state.limit,offset:state.offset,actionFilter:document.getElementById('actionFilter').value.trim(),entityType:document.getElementById('entityTypeFilter').value.trim(),actorUserId:Number(document.getElementById('actorFilter').value||0),outcome:document.getElementById('outcomeFilter').value,dateFrom:document.getElementById('dateFromFilter').value,dateTo:document.getElementById('dateToFilter').value};}
    async function load(){clearMsg();var r=await api('audit.list',filters());state.total=Number(r.total||0);state.users=r.users||{};var rows=Array.isArray(r.items)?r.items:[],node=document.getElementById('auditRows');if(!rows.length){node.innerHTML='<tr><td colspan="7">Записей не найдено</td></tr>';}else{node.innerHTML=rows.map(function(x){var entity=(x.entityType||'—')+(Number(x.entityId||0)>0?' #'+Number(x.entityId):'');return '<tr><td>'+esc(x.createdAt||'')+'</td><td>'+esc(userName(Number(x.actorUserId||0)))+'</td><td><strong>'+esc(x.action||'')+'</strong><br><small>'+esc(x.requestId||'')+'</small></td><td>'+esc(entity)+(Number(x.pageId||0)>0?'<br><small>страница #'+Number(x.pageId)+'</small>':'')+'</td><td><span class="sb-audit-status '+esc(x.outcome||'success')+'">'+esc(x.outcome||'success')+' · '+Number(x.httpStatus||200)+'</span></td><td>'+esc(x.clientIp||'—')+'</td><td><button class="sb-btn sb-btn-light sb-btn-small js-detail" data-id="'+Number(x.id)+'">Открыть</button></td></tr>';}).join('');}var from=state.total?state.offset+1:0,to=Math.min(state.offset+state.limit,state.total);document.getElementById('pagerText').textContent=from+'–'+to+' из '+state.total;document.getElementById('prevBtn').disabled=state.offset<=0;document.getElementById('nextBtn').disabled=state.offset+state.limit>=state.total;}
    async function openDetail(id){var r=await api('audit.get',{id:id});var x=r.item||{};document.getElementById('detailMeta').textContent='Запись #'+Number(x.id||0)+' · '+(x.action||'')+' · '+(x.createdAt||'');document.getElementById('detailJson').textContent=JSON.stringify(x,null,2);}
    async function loadMaintenance(){if(!IS_ADMIN)return;var r=await api('maintenance.status',{}),m=r.maintenance||{},c=m.config||{},last=m.lastResult||{};document.getElementById('maintenanceMeta').textContent='Последний запуск: '+(m.lastRunAt||'ещё не запускалась')+'\nПоследний успешный: '+(m.lastSuccessAt||'—')+'\nУдалено ревизий: '+Number(last.revisions||0)+', корзина: '+Number(last.recycleBin||0)+', журнал: '+Number(last.auditLog||0)+', задания: '+Number(last.externalJobs||0)+'\nСроки: ревизии '+Number(c.revision_retention_days||0)+' дн., корзина '+Number(c.recycle_bin_retention_days||0)+' дн., журнал '+Number(c.audit_log_retention_days||0)+' дн., успешные задания '+Number(c.outbox_succeeded_retention_days||0)+' дн., ошибки заданий '+Number(c.outbox_terminal_retention_days||0)+' дн.';}
    async function runMaintenance(){if(!confirm('Запустить очистку старых ревизий, корзины и журнала?'))return;var b=document.getElementById('runMaintenanceBtn');b.disabled=true;try{var r=await api('maintenance.run',{});msg('Очистка завершена: '+JSON.stringify(r.maintenance||{}),false);await loadMaintenance();await load();}catch(e){msg('Ошибка очистки: '+((e&&e.error)||'UNKNOWN_ERROR'),true);}finally{b.disabled=false;}}
    document.getElementById('reloadBtn').addEventListener('click',function(){load().catch(function(e){msg('Ошибка: '+((e&&e.error)||'UNKNOWN_ERROR'),true);});});
    document.getElementById('applyFilterBtn').addEventListener('click',function(){state.offset=0;load().catch(function(e){msg('Ошибка: '+((e&&e.error)||'UNKNOWN_ERROR'),true);});});
    document.getElementById('resetFilterBtn').addEventListener('click',function(){['actionFilter','entityTypeFilter','actorFilter','dateFromFilter','dateToFilter'].forEach(function(id){document.getElementById(id).value='';});document.getElementById('outcomeFilter').value='';state.offset=0;load().catch(function(e){msg('Ошибка: '+((e&&e.error)||'UNKNOWN_ERROR'),true);});});
    document.getElementById('prevBtn').addEventListener('click',function(){state.offset=Math.max(0,state.offset-state.limit);load();});document.getElementById('nextBtn').addEventListener('click',function(){state.offset+=state.limit;load();});
    document.addEventListener('click',function(e){var b=e.target.closest('.js-detail');if(b)openDetail(Number(b.getAttribute('data-id'))).catch(function(x){msg('Ошибка: '+((x&&x.error)||'UNKNOWN_ERROR'),true);});});
    if(IS_ADMIN)document.getElementById('runMaintenanceBtn').addEventListener('click',runMaintenance);
    Promise.all([load(),IS_ADMIN?loadMaintenance():Promise.resolve()]).catch(function(e){msg('Ошибка загрузки: '+((e&&e.error)||'UNKNOWN_ERROR'),true);});
})();
</script>
</body>
</html>
