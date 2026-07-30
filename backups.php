<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/sitebuilder/lib/auth.php';
sitebuilder_require_auth();

global $APPLICATION, $USER;
$basePath = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__), '/');
$siteId = (int)($_GET['siteId'] ?? 0);
foreach ([__DIR__.'/lib/db.php',__DIR__.'/lib/json.php',__DIR__.'/lib/helpers.php',__DIR__.'/lib/access.php'] as $file) {
    require_once $file;
}
if ($siteId <= 0) {
    http_response_code(422);
    die('Не передан siteId.');
}
if (!$USER->IsAdmin()) {
    sb_require_content_manager($siteId);
}
$role = $USER->IsAdmin() ? 'OWNER' : (string)sb_get_role($siteId, 'U' . (int)$USER->GetID());
$canOwner = $USER->IsAdmin() || sb_role_rank($role) >= 4;
$returnUrl = $basePath . '/settings.php?siteId=' . $siteId;
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SiteBuilder / Резервные копии</title>
    <?php $APPLICATION->ShowHead(); ?>
    <link rel="stylesheet" href="<?=htmlspecialchars($basePath,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>/assets/admin/admin.css">
    <style>
        .backup-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(340px,.7fr);gap:16px}.backup-table{width:100%;border-collapse:collapse;min-width:920px}.backup-table th,.backup-table td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}.badge{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:800}.ready,.succeeded{background:#dcfce7;color:#166534}.creating,.running{background:#dbeafe;color:#1d4ed8}.corrupt,.failed{background:#fee2e2;color:#991b1b}.deleted{background:#e5e7eb;color:#374151}.integrity-list{display:grid;gap:8px}.integrity-item{padding:10px;border:1px solid #e5e7eb;border-radius:9px;cursor:pointer}.details{white-space:pre-wrap;word-break:break-word;max-height:48vh;overflow:auto}.create-row{display:flex;flex-wrap:wrap;gap:12px;align-items:end}.small{font-size:12px;color:#64748b}@media(max-width:1050px){.backup-grid{grid-template-columns:1fr}}
    </style>
</head>
<body class="sb-admin-body">
<div class="sb-page">
    <div class="sb-topbar">
        <div><a class="sb-back-link" href="<?=htmlspecialchars($returnUrl,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>">← Назад</a><h1 class="sb-title">Резервные копии и целостность</h1><p class="sb-subtitle">Сайт #<?=$siteId?> · восстановление всегда создаёт новый сайт</p></div>
        <div class="sb-actions"><button class="sb-btn sb-btn-light" id="integrityBtn">Проверить целостность</button><button class="sb-btn sb-btn-light" id="reloadBtn">Обновить</button></div>
    </div>

    <div id="message" class="sb-panel" style="display:none"></div>
    <section class="sb-panel">
        <h2 class="sb-panel-title">Создать резервную копию</h2>
        <div class="create-row">
            <?php if ($canOwner): ?>
                <label class="sb-field"><span>Права доступа</span><select class="sb-select" id="includeAccess"><option value="0">Не включать</option><option value="1">Включить роли и точечные права</option></select></label>
            <?php endif; ?>
            <button class="sb-btn sb-btn-primary" id="createBtn">Создать копию</button>
        </div>
        <p class="small">Файлы Битрикс.Диска, CFile-изображения оформления и внешние ID группы/папки в пакет не включаются. После восстановления worker создаст новые внешние ресурсы.</p>
        <?php if ($canOwner): ?>
            <hr style="border:0;border-top:1px solid #e5e7eb;margin:18px 0">
            <div class="create-row">
                <label class="sb-field"><span>Импорт ранее скачанного пакета</span><input class="sb-input" id="importFile" type="file" accept=".json,.gz,.json.gz,application/json,application/gzip"></label>
                <button class="sb-btn sb-btn-light" id="importBtn">Импортировать</button>
            </div>
            <p class="small">При импорте роли и точечные права отбрасываются: числовые ID пользователей и групп не переносимы между порталами.</p>
        <?php endif; ?>
    </section>

    <div class="backup-grid">
        <section class="sb-panel" style="overflow:auto">
            <table class="backup-table"><thead><tr><th>ID / дата</th><th>Состояние</th><th>Содержимое</th><th>Размер</th><th>Проверено</th><th>Действия</th></tr></thead><tbody id="backups"><tr><td colspan="6">Загрузка…</td></tr></tbody></table>
        </section>
        <aside>
            <section class="sb-panel"><h2 class="sb-panel-title">Проверки целостности</h2><div id="runs" class="integrity-list">Загрузка…</div></section>
            <section class="sb-panel"><h2 class="sb-panel-title">Детали</h2><pre id="detail" class="details">Выберите проверку</pre></section>
        </aside>
    </div>
</div>
<script src="/bitrix/js/main/core/core.js"></script>
<script>
(function(){'use strict';var BASE='<?=CUtil::JSEscape($basePath)?>',SITE=<?=$siteId?>,OWNER=<?=$canOwner?'true':'false'?>,API=BASE+'/api.php';
function sid(){return window.BX&&BX.bitrix_sessid?BX.bitrix_sessid():'<?=CUtil::JSEscape(bitrix_sessid())?>'}
async function api(action,data){var r=await fetch(API,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams(Object.assign({action:action,sessid:sid()},data||{}))}),t=await r.text(),j;try{j=JSON.parse(t)}catch(e){throw{error:'BAD_JSON_RESPONSE'}}if(!j||j.ok!==true)throw j||{error:'UNKNOWN_ERROR'};return j}
async function uploadBackup(file){var f=new FormData();f.append('action','backup.import');f.append('siteId',String(SITE));f.append('sessid',sid());f.append('backupFile',file);var r=await fetch(API,{method:'POST',credentials:'same-origin',body:f}),t=await r.text(),j;try{j=JSON.parse(t)}catch(e){throw{error:'BAD_JSON_RESPONSE'}}if(!j||j.ok!==true)throw j||{error:'UNKNOWN_ERROR'};return j}
function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]})}
function msg(t,e){var n=document.getElementById('message');n.style.display='block';n.style.color=e?'#991b1b':'#166534';n.textContent=t}
function bytes(n){n=Number(n||0);if(n<1024)return n+' Б';if(n<1048576)return(n/1024).toFixed(1)+' КБ';return(n/1048576).toFixed(1)+' МБ'}
async function loadBackups(){var r=await api('backup.list',{siteId:SITE,limit:100}),rows=r.items||[];document.getElementById('backups').innerHTML=rows.length?rows.map(function(x){var counts=(x.metadata&&x.metadata.counts)||{},download=x.status==='ready'?'<a class="sb-btn sb-btn-light sb-btn-small" href="'+BASE+'/backup_download.php?id='+Number(x.id)+'">Скачать</a>':'',ownerActions=OWNER?'<button class="sb-btn sb-btn-light sb-btn-small restore" data-id="'+Number(x.id)+'">Восстановить</button><button class="sb-btn sb-btn-light sb-btn-small delete" data-id="'+Number(x.id)+'">Удалить</button>':'';return '<tr><td><strong>#'+Number(x.id)+'</strong><br>'+esc(x.createdAt)+'</td><td><span class="badge '+esc(x.status)+'">'+esc(x.status)+'</span><br>'+(x.includeAccess?'с правами':'без прав')+'</td><td>Страниц: '+Number(counts.pages||0)+'<br>Блоков: '+Number(counts.blocks||0)+'<br>Секций: '+Number(counts.sections||0)+'</td><td>'+bytes(x.fileSize)+'</td><td>'+esc(x.verifiedAt||'—')+'</td><td><div class="sb-actions">'+download+'<button class="sb-btn sb-btn-light sb-btn-small verify" data-id="'+Number(x.id)+'">Проверить</button>'+ownerActions+'</div></td></tr>'}).join(''):'<tr><td colspan="6">Резервных копий ещё нет.</td></tr>'}
async function loadRuns(){var r=await api('integrity.list',{siteId:SITE,limit:40}),rows=r.items||[];document.getElementById('runs').innerHTML=rows.length?rows.map(function(x){return '<div class="integrity-item" data-id="'+Number(x.id)+'"><strong>#'+Number(x.id)+' · '+esc(x.status)+'</strong><br>'+esc(x.startedAt)+'<br>Ошибок: '+Number(x.errorsCount)+', предупреждений: '+Number(x.warningsCount)+'</div>'}).join(''):'Проверок ещё нет'}
async function load(){await Promise.all([loadBackups(),loadRuns()])}
async function restore(id){var name=prompt('Название нового сайта:','Восстановленная копия');if(name===null)return;var slug=prompt('Slug нового сайта (можно оставить пустым):','');if(slug===null)return;var section=prompt('ID раздела сайтов (0 — без раздела):','0');if(section===null)return;var restoreAccess=confirm('Восстановить роли ADMIN/EDITOR/VIEWER и точечные права, если они есть в пакете?');var r=await api('backup.restore',{backupId:id,siteName:name,slug:slug,sectionId:Number(section||0),restoreAccess:restoreAccess?1:0});msg('Создан новый сайт #'+Number((r.site||{}).id||0),false);return load()}
document.addEventListener('click',function(e){var b=e.target.closest('.verify');if(b)api('backup.verify',{backupId:Number(b.dataset.id)}).then(function(r){msg(r.valid?'Копия корректна':'Копия повреждена',!r.valid);return load()}).catch(function(x){msg(x.error||'Ошибка',true)});b=e.target.closest('.restore');if(b)restore(Number(b.dataset.id)).catch(function(x){msg(x.error||'Ошибка восстановления',true)});b=e.target.closest('.delete');if(b&&confirm('Удалить резервную копию?'))api('backup.delete',{backupId:Number(b.dataset.id)}).then(function(){msg('Копия удалена',false);return load()}).catch(function(x){msg(x.error||'Ошибка',true)});b=e.target.closest('.integrity-item');if(b)api('integrity.get',{runId:Number(b.dataset.id)}).then(function(r){document.getElementById('detail').textContent=JSON.stringify(r.run||{},null,2)}).catch(function(x){msg(x.error||'Ошибка',true)})});
document.getElementById('createBtn').onclick=function(){var include=document.getElementById('includeAccess');api('backup.create',{siteId:SITE,includeAccess:include?Number(include.value):0}).then(function(r){msg('Создана резервная копия #'+Number((r.backup||{}).id||0),false);return loadBackups()}).catch(function(x){msg(x.error||'Ошибка создания копии',true)})};
var importBtn=document.getElementById('importBtn');if(importBtn)importBtn.onclick=function(){var input=document.getElementById('importFile'),file=input&&input.files?input.files[0]:null;if(!file){msg('Выберите файл резервной копии.',true);return}importBtn.disabled=true;uploadBackup(file).then(function(r){msg('Импортирована резервная копия #'+Number((r.backup||{}).id||0)+'. Права из файла отброшены.',false);input.value='';return loadBackups()}).catch(function(x){msg(x.error||'Ошибка импорта',true)}).finally(function(){importBtn.disabled=false})};
document.getElementById('integrityBtn').onclick=function(){api('integrity.run',{siteId:SITE}).then(function(r){var x=r.run||{};msg('Проверка завершена: ошибок '+Number(x.errorsCount||0)+', предупреждений '+Number(x.warningsCount||0),Number(x.errorsCount||0)>0);return loadRuns()}).catch(function(x){msg(x.error||'Ошибка проверки',true)})};document.getElementById('reloadBtn').onclick=function(){load().catch(function(x){msg(x.error||'Ошибка загрузки',true)})};load().catch(function(x){msg(x.error||'Ошибка загрузки',true)});})();
</script>
</body></html>
