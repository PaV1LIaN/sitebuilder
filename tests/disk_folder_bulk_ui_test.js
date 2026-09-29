// Real PHP template + production JS/CSS; only the portal API is mocked.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const html = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'fixtures/disk_title_view.php')], {encoding:'utf8'});
(async () => {
  const browser = await chromium.launch({headless:true, executablePath:process.env.CHROMIUM_EXECUTABLE_PATH,
    args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu','--single-process','--no-zygote']});
  try {
    const page = await browser.newPage({viewport:{width:1440,height:1050}});
    const errors = [], saves = [];
    page.on('pageerror', error => errors.push(error.message));
    let mode = 'custom', revision = 'r1', conflict = false, requests = 0;
    const permissions = Object.fromEntries(['canView','canManageAccess','canEditSettings','canDownload'].map(key => [key,true]));
    const settings = () => ({title:'Документы отдела',rootFolderId:20,rootMode:'block',viewMode:'table',
      permissionMode:mode,showSearch:true,showBreadcrumbs:true});
    const user = id => ({id,name:'Сотрудник ' + id,login:'employee.' + id,email:'employee' + id + '@example.test'});
    await page.route('http://disk.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/') return route.fulfill({contentType:'text/html; charset=utf-8',body:`<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>${html}</body></html>`});
      const action = url.searchParams.get('action'), payload = route.request().postDataJSON();
      let data = {}, response;
      if (action === 'bootstrap') data = {siteId:1,pageId:2,blockId:5,rootFolderId:20,currentFolderId:20,settings:settings(),permissions};
      else if (action === 'list') data = {folder:{id:20,name:'Документы отдела'},breadcrumbs:[{id:20,name:'Документы отдела'}],items:[],permissions};
      else if (action === 'quota') data = {quota:{usedBytes:0,limitBytes:0}};
      else if (action === 'folderAccessList') data = {folderId:20,permissionMode:mode,items:[{userId:1,userName:'Сотрудник 1',role:'EDITOR'}],revision};
      else if (action === 'folderAccessResolveUsers') {
        requests++;
        data = {rows:payload.text.split('\n').map(query => {
          const id = Number(query);
          if (id) return {query,candidates:[user(id)],selectedId:id};
          if (query === 'Иванов Иван Иванович') return {query,candidates:[{...user(501),name:query},{...user(502),name:query}],selectedId:null};
          return {query,candidates:[],selectedId:null};
        })};
      } else if (action === 'folderAccessBulkSet') {
        saves.push(payload);
        if (conflict) response = {ok:false,error:'FOLDER_ACCESS_VERSION_CONFLICT',message:'Права папки изменились. Перечитайте текущие правила.'};
        else data = {count:payload.userIds.length,revision:revision = 'r' + (saves.length + 1)};
      } else throw new Error('Unexpected action: ' + action);
      await route.fulfill({contentType:'application/json',body:JSON.stringify(response || {ok:true,data})});
    });
    await page.goto('http://disk.test/');
    for (const file of ['styles.css','access.css']) await page.addStyleTag({content:fs.readFileSync(path.join(root,'components/disk',file),'utf8')});
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'components/disk/script.js'),'utf8')});
    await page.waitForFunction(() => document.querySelector('.sb-disk').__diskComponent.state.breadcrumbs.length);
    await page.locator('[data-action="folder-access"]').click();
    const modal = page.locator('[data-role="folder-access-modal"]');
    const input = modal.locator('[data-role="bulk-access-input"]');
    const apply = modal.locator('[data-action="bulk-access-apply"]');
    const rows = modal.locator('[data-role="bulk-access-rows"]');
    const status = modal.locator('[data-role="bulk-access-status"]');
    await input.fill(Array.from({length:100}, (_,i) => String(i+1)).join('\n') + '\n1\nИванов Иван Иванович\n<не найден>');
    await modal.locator('[data-action="bulk-access-resolve"]').click();
    await page.waitForFunction(() => document.querySelector('[data-role="bulk-access-status"]').textContent.includes('Поиск завершён'));
    assert.equal(requests,6,'chunked search for 103 rows');
    assert.equal(await apply.textContent(),'Применить выбранным (100)','duplicate ID counted once');
    assert.equal(await rows.locator('select').count(),1,'namesake requires manual choice');
    assert.equal(await rows.locator('[data-bulk-row="102"] input').isDisabled(),true,'missing person cannot be selected');
    assert.equal(await rows.locator('не').count(),0,'input rendered as text');
    await rows.locator('select').selectOption('502');
    assert.equal(await apply.textContent(),'Применить выбранным (101)');
    await rows.locator('[data-bulk-row="101"] input').uncheck();
    await modal.locator('[data-role="bulk-access-role"]').selectOption('DENY');
    await apply.click();
    await page.waitForFunction(() => document.querySelector('[data-role="bulk-access-status"]').textContent.includes('Права сохранены'));
    assert.deepEqual(saves[0].userIds,Array.from({length:100},(_,i)=>i+1));
    assert.equal(saves[0].folderId,20); assert.equal(saves[0].role,'DENY'); assert.equal(saves[0].expectedRevision,'r1');
    assert(await apply.isDisabled(),'successful batch is not accidentally repeated');
    // Conflict requires explicitly refreshing the rules; it never retries a write itself.
    await modal.locator('[data-action="bulk-access-toggle"]').click();
    conflict = true;
    await apply.click();
    await page.waitForFunction(() => document.querySelector('[data-role="bulk-access-status"]').textContent.includes('Права папки изменились'));
    assert(await apply.isDisabled());
    await modal.locator('[data-action="folder-access-refresh"]').click();
    await page.waitForFunction(() => !document.querySelector('[data-action="bulk-access-apply"]').disabled);
    conflict = false;
    await modal.locator('[data-role="bulk-access-role"]').selectOption('INHERIT');
    await apply.click();
    await page.waitForFunction(() => document.querySelector('[data-role="bulk-access-status"]').textContent.includes('Права сохранены'));
    assert.equal(saves.at(-1).role,'INHERIT');
    for (const width of [1440,768,390]) {
      await page.setViewportSize({width,height:1050});
      await page.emulateMedia({reducedMotion:'reduce'});
      const overflow = await modal.evaluate(el => {
        const dialog = el.querySelector('[role="dialog"]');
        return {viewport:document.documentElement.scrollWidth-innerWidth,dialog:dialog.getBoundingClientRect().width,inner:dialog.scrollWidth-dialog.clientWidth};
      });
      assert(overflow.viewport <= 1 && overflow.dialog <= width && overflow.inner <= 1, `No overflow at ${width}: ${JSON.stringify(overflow)}`);
      if (process.env.SCREENSHOT_DIR) {
        fs.mkdirSync(process.env.SCREENSHOT_DIR,{recursive:true});
        await modal.locator('[role="dialog"]').evaluate(el => { el.scrollTop = 0; });
        await rows.evaluate(el => { el.scrollTop = 0; });
        await page.screenshot({path:path.join(process.env.SCREENSHOT_DIR,`bulk-access-${width}.png`)});
      }
    }
    await modal.locator('[data-action="bulk-access-edit"]').click();
    await input.fill('42');
    assert.equal(await modal.locator('[data-role="bulk-access-review"]').isVisible(),false,'editing input clears previous matches');
    await modal.locator('[data-action="bulk-access-resolve"]').click();
    await page.waitForFunction(() => document.querySelector('[data-role="bulk-access-status"]').textContent.includes('Поиск завершён'));
    mode = 'inherit_site';
    await modal.locator('[data-action="folder-access-refresh"]').click();
    await modal.locator('[data-role="folder-access-warning"]').waitFor({state:'visible'});
    assert(await apply.isDisabled(),'wrong mode blocks bulk apply');
    assert(await modal.locator('[data-action="save-folder-access"]').isDisabled(),'wrong mode blocks individual save');
    await modal.locator('[data-action="close-folder-access"]').last().click();
    assert.equal(await modal.isVisible(),false,'modal closes');
    assert.deepEqual(errors,[]);
    console.log('PASS: real UI, 100 users, namesakes, deduplication, review, DENY/INHERIT, conflict, stale input, mode guard, 1440/768/390 and reduced motion.');
  } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});
