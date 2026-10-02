// Production PHP template and scripts; portal responses are fixtures.
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
    const page = await browser.newPage({viewport:{width:1440,height:1000}});
    const errors = [], saves = [];
    page.on('pageerror', error => errors.push(error.message));
    let required = false, mode = 'inherit_site', admin = true, listRequests = 0, quotaRequests = 0;
    const permissions = () => ({canBrowse:true,canView:admin,canUpload:admin,canDownload:admin,canManageAccess:admin,canEditSettings:admin});
    const settings = () => ({title:'Документы отдела',rootFolderId:20,rootMode:'block',viewMode:'table',
      permissionMode:mode,requireFolderAccess:required,maxFileSize:52428800,showSearch:true,showBreadcrumbs:true});
    await page.route('http://disk.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/') return route.fulfill({contentType:'text/html; charset=utf-8',body:`<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>${html}</body></html>`});
      const action = url.searchParams.get('action'), payload = route.request().postDataJSON();
      let data = {};
      if (action === 'bootstrap') data = {siteId:1,pageId:2,blockId:5,rootFolderId:20,currentFolderId:20,blockVersion:7,settings:settings(),permissions:permissions()};
      else if (action === 'list') { listRequests++; data = {folder:{id:20,name:'Документы отдела'},breadcrumbs:[{id:20,name:'Документы отдела'}],items:[],permissions:permissions()}; }
      else if (action === 'quota') { quotaRequests++; data = {quota:{usedBytes:0,limitBytes:0}}; }
      else if (action === 'getSettings') data = {settings:settings(),blockVersion:7,root:{id:20,name:'Документы отдела'}};
      else if (action === 'getRootOptions') data = {siteRootFolderId:10,blockRootFolderId:20,blockVersion:7};
      else if (action === 'resolveRoot') data = {rootFolderId:20,source:'block'};
      else if (action === 'saveSettings') {
        saves.push(payload);
        required = payload.settings.requireFolderAccess;
        mode = payload.settings.permissionMode;
        data = {settings:settings(),blockVersion:8};
      } else throw new Error('Unexpected action: ' + action);
      await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data})});
    });
    async function load() {
      await page.goto('http://disk.test/');
      for (const file of ['styles.css','access.css']) await page.addStyleTag({content:fs.readFileSync(path.join(root,'components/disk',file),'utf8')});
      await page.addScriptTag({content:fs.readFileSync(path.join(root,'components/disk/script.js'),'utf8')});
      await page.waitForFunction(() => document.querySelector('.sb-disk').__diskComponent.state.breadcrumbs.length);
    }
    async function openSettings() {
      await page.locator('[data-action="settings"]').click();
      await page.locator('[data-role="settings-modal"]').waitFor({state:'visible'});
      await page.locator('[data-settings-tab="access"]').click();
    }
    await load();
    await openSettings();
    const modal = page.locator('[data-role="settings-modal"]');
    const flag = modal.locator('[name="requireFolderAccess"]');
    const select = modal.locator('[name="permissionMode"]');
    assert.equal(await flag.isChecked(), false);
    await flag.check();
    assert.equal(await select.inputValue(), 'custom', 'flag activates folder rules');
    for (const width of [1440,768,390]) {
      await page.setViewportSize({width,height:1000});
      await page.emulateMedia({reducedMotion:'reduce'});
      await flag.scrollIntoViewIfNeeded();
      const overflow = await modal.evaluate(el => {
        const dialog = el.querySelector('[role="dialog"]');
        return {page:document.documentElement.scrollWidth-innerWidth,dialog:dialog.scrollWidth-dialog.clientWidth};
      });
      assert(overflow.page <= 1 && overflow.dialog <= 1, JSON.stringify({width,overflow}));
      assert(await flag.isVisible(), `flag visible at ${width}`);
      if (process.env.SCREENSHOT_DIR) {
        fs.mkdirSync(process.env.SCREENSHOT_DIR,{recursive:true});
        await page.screenshot({path:path.join(process.env.SCREENSHOT_DIR,`folder-policy-${width}.png`)});
      }
    }
    await modal.locator('[data-action="save-settings"]').click();
    await modal.waitFor({state:'hidden'});
    assert.equal(saves[0].settings.requireFolderAccess,true);
    assert.equal(saves[0].expectedVersion,7);
    await openSettings();
    assert(await flag.isChecked(), 'saved value restored');
    await select.selectOption('bitrix_disk');
    assert(await flag.isChecked(), 'native mode also requires folder rights');
    await flag.uncheck();
    assert.equal(await select.inputValue(),'inherit_site');
    await modal.locator('[data-action="save-settings"]').click();
    await modal.waitFor({state:'hidden'});
    assert.equal(saves[1].settings.requireFolderAccess,false);
    await openSettings();
    assert.equal(await flag.isChecked(),false, 'zero survives reopen');

    // Restricted user reaches the root catalogue without access to root files/quota.
    admin = false; required = true; mode = 'custom';
    const listsBefore = listRequests, quotaBefore = quotaRequests;
    await load();
    assert(listRequests > listsBefore, 'navigation-only bootstrap loads permitted folders');
    assert.equal(quotaRequests,quotaBefore, 'navigation is not permission to read root usage');
    assert(await page.locator('[data-state="empty"]').isVisible());
    assert.equal(await page.locator('[data-state="no-access"]').isVisible(),false);
    assert.equal(await page.locator('[data-action="upload"]').isVisible(),false);

    // Existing page editor round-trips the same flag through its real form helpers.
    const editor = fs.readFileSync(path.join(root,'editor.php'),'utf8');
    const editorForm = editor.slice(editor.indexOf('<div id="diskBlockForm"'),editor.indexOf('<div id="blockJsonFields"'));
    const editorPage = await browser.newPage();
    await editorPage.setContent(editorForm);
    await editorPage.addScriptTag({content:fs.readFileSync(path.join(root,'assets/admin/editor/30-blocks.js'),'utf8')});
    await editorPage.evaluate(() => {
      window.getInputValue = id => document.getElementById(id)?.value || '';
      window.getChecked = id => !!document.getElementById(id)?.checked;
      fillDiskForm({requireFolderAccess:true,permissionMode:'custom'});
    });
    assert(await editorPage.locator('#diskRequireFolderAccessInput').isChecked());
    await editorPage.locator('#diskRequireFolderAccessInput').uncheck();
    const props = await editorPage.evaluate(() => collectDiskBlockProps({props:{rootFolderId:20}}));
    assert.equal(props.requireFolderAccess,false);
    assert.equal(props.permissionMode,'inherit_site');
    assert.equal(props.rootFolderId,20);
    await editorPage.close();
    assert.deepEqual(errors,[]);
    console.log('PASS: flag on/off save and reopen, native mode sync, page editor, root navigation without file access, 1440/768/390.');
  } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});
