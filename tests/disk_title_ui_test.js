// Real PHP template and production JS; only API responses are fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const html = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'fixtures/disk_title_view.php')], {encoding:'utf8'});
assert(!/Fatal error|Warning:/.test(html), 'PHP fixture renders without warnings');
(async () => {
    const browser = await chromium.launch({headless:true, executablePath:process.env.CHROMIUM_EXECUTABLE_PATH,
        args:['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--single-process', '--no-zygote']});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let portalName = 'Документы с портала';
        let failSync = false;
        let version = 7;
        const saves = [];
        const permissions = Object.fromEntries(['canView','canUpload','canCreateFolder','canRename',
            'canDelete','canDownload','canManageAccess','canEditSettings'].map(key => [key,true]));
        const settings = () => ({title:portalName, rootMode:'block', rootFolderId:20, viewMode:'table',
            maxFileSize:52428800, maxDiskSize:0, showSearch:true, showBreadcrumbs:true, allowUpload:true,
            allowCreateFolder:true, allowRename:true, allowDownload:true, useSiteRootFallback:true});
        await page.route('http://disk.test/**', async route => {
            const url = new URL(route.request().url());
            if (url.pathname === '/') return route.fulfill({contentType:'text/html', body:`<!doctype html><html><body>${html}</body></html>`});
            const action = url.searchParams.get('action');
            const payload = route.request().postDataJSON();
            let data = {};
            if (action === 'bootstrap') data = {siteId:1,pageId:2,blockId:5,rootFolderId:20,currentFolderId:20,blockVersion:version,settings:settings(),permissions};
            else if (action === 'list') data = {root:{id:20,name:portalName},folder:{id:20,name:portalName},
                breadcrumbs:[{id:1,name:'Общий диск'},{id:10,name:'Сайт'},{id:20,name:portalName}],items:[],permissions};
            else if (action === 'quota') data = {quota:{usedBytes:0,limitBytes:0,availableBytes:null}};
            else if (action === 'getSettings') data = {settings:settings(),blockVersion:version,root:{id:20,name:portalName}};
            else if (action === 'getRootOptions') data = {siteRootFolderId:10,blockRootFolderId:20,blockVersion:version};
            else if (action === 'resolveRoot') data = {rootFolderId:20,source:'block'};
            else if (action === 'saveSettings') {
                saves.push(payload);
                if (!failSync) portalName = payload.settings.title.trim();
                data = {settings:settings(),blockVersion:++version,titleSync:{id:1,status:failSync?'retry':'succeeded',error:failSync?'DISK_RENAME_FAILED':''}};
            } else throw new Error(`Unexpected action: ${action}`);
            return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data})});
        });
        await page.goto('http://disk.test/');
        await page.addStyleTag({content:fs.readFileSync(path.join(root,'components/disk/styles.css'),'utf8')});
        await page.addScriptTag({content:fs.readFileSync(path.join(root,'components/disk/script.js'),'utf8')});
        const title = page.locator('.sb-disk__title');
        await page.waitForFunction(() => document.querySelector('.sb-disk__title').textContent === 'Документы с портала');
        assert.equal(await page.locator('.sb-disk__crumb').last().textContent(), portalName);
        portalName = '<Архив & документы>';
        await page.locator('[data-action="refresh"]').click();
        await page.waitForFunction(() => document.querySelector('.sb-disk__title').textContent === '<Архив & документы>');
        assert.equal(await title.locator('*').count(), 0, 'portal title is rendered as text');
        assert.equal(await page.locator('.sb-disk__crumb').count(), 1, 'breadcrumbs start at bound root');
        assert.equal(await page.locator('.sb-disk__crumb').textContent(), portalName);
        await page.locator('[data-action="settings"]').click();
        const modal = page.locator('[data-role="settings-modal"]');
        await modal.waitFor({state:'visible'});
        assert.equal(await modal.locator('[name="title"]').inputValue(), portalName);
        await modal.locator('[name="title"]').fill('Отчёты 2026');
        await modal.locator('[data-action="save-settings"]').click();
        await modal.waitFor({state:'hidden'});
        assert.equal(saves[0].rootRevision.name, '<Архив & документы>', 'save includes the native name observed when opening');
        assert.equal(await title.textContent(), 'Отчёты 2026');
        failSync = true;
        await page.locator('[data-action="settings"]').click();
        await modal.waitFor({state:'visible'});
        await modal.locator('[name="title"]').fill('Занятое имя');
        await modal.locator('[data-action="save-settings"]').click();
        await page.waitForFunction(() => document.body.textContent.includes('синхронизация будет повторена'));
        assert(await modal.isVisible(), 'failed native rename keeps its status visible');
        assert.equal(await title.textContent(), 'Отчёты 2026', 'failure shows the real native name');
        assert.deepEqual(errors, []);
        console.log('PASS: portal refresh, title/breadcrumb consistency, escaped names, settings save and pending rename feedback.');
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});
