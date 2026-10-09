// node tests/disk_capacity_ui_test.js
// Run the real component in a VM, with DOM/network adapters (no Bitrix required).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../components/disk/script.js'), 'utf8')
  .replace('  function initDisks() {', '  globalThis.TestDiskComponent = DiskComponent;\n  function initDisks() {');

function node() {
  const classes = new Set();
  return { hidden: false, textContent: '', value: 0, attrs: {},
    classList: { add: (...v) => v.forEach(x => classes.add(x)), remove: (...v) => v.forEach(x => classes.delete(x)),
      toggle: (v, on) => on ? classes.add(v) : classes.delete(v), contains: v => classes.has(v) },
    setAttribute(k, v) { this.attrs[k] = v; }, getAttribute(k) { return this.attrs[k] || ''; },
    querySelector() { return null; }, querySelectorAll() { return []; } };
}

function fixture() {
  const calls = [], events = [], nodes = {};
  for (const role of ['disk-capacity', 'capacity-used', 'capacity-available', 'capacity-progress', 'upload-notice']) nodes[role] = node();
  const query = selector => nodes[(selector.match(/data-role="([^"]+)"/) || [])[1]] || null;
  const root = node(); root.dataset = {}; root.querySelector = query;
  nodes['disk-capacity'].querySelector = query;
  const env = { quota: { usedBytes: 90, limitBytes: 100, availableBytes: 10, hasAdditionalLimit: false },
    fits: true, reason: null, uploaded: 0, reloads: 0, shown: 0, finished: [], events, calls, nodes };
  env.reply = async action => {
    if (action === 'quota') return { ok: true, data: { quota: env.quota } };
    if (action === 'checkUpload') return { ok: true, data: { quota: env.quota, upload: {
      fits: env.fits, incomingBytes: env.incoming || 10, reason: env.reason, fileName: '<bad>.txt', maxFileSize: 5 } } };
    return { ok: true, data: {} };
  };
  class TestFormData { constructor() { this.entries = []; } append(...args) { this.entries.push(args); } }
  class TestXHR {
    constructor() { this.handlers = {}; this.upload = { addEventListener() {} }; }
    open() {}
    addEventListener(name, cb) { this.handlers[name] = cb; }
    send(form) {
      env.uploaded++; calls.push({ action: 'multipart', payload: form.entries });
      this.responseText = JSON.stringify(env.uploadResponse || { ok: true });
      queueMicrotask(() => this.handlers.load());
    }
  }
  const document = { readyState: 'loading', addEventListener() {}, dispatchEvent: event => events.push(event) };
  const sandbox = { document, window: {}, console: { error() {} }, FormData: TestFormData,
    XMLHttpRequest: TestXHR, CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    setTimeout, clearTimeout, alert: message => calls.push({ action: 'alert', message }),
    fetch: async (url, opts) => {
      const action = new URL(url, 'https://portal.example').searchParams.get('action');
      const payload = JSON.parse(opts.body); calls.push({ action, payload });
      const result = await env.reply(action, payload);
      return { text: async () => JSON.stringify(result) };
    } };
  vm.runInNewContext(source, sandbox);
  const component = env.component = new sandbox.TestDiskComponent(root);
  Object.assign(component.state, { siteId: 1, pageId: 2, blockId: 3, rootFolderId: 10,
    currentFolderId: 10, permissions: { canView: true, canUpload: true } });
  component.showUploadStatusModal = () => env.shown++;
  component.finishUploadStatusModal = (...args) => env.finished.push(args);
  component.updateUploadStatusModal = () => {};
  env.realLoadFolder = component.loadFolder;
  component.loadFolder = async () => { env.reloads++; await component.refreshQuota(); };
  return env;
}
const files = [{ name: 'a.txt', size: 6 }, { name: 'b.txt', size: 4 }];
const tests = [];
function test(name, fn) { tests.push([name, fn]); }
function deferred() { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; }

test('insufficient capacity stops the entire batch before multipart', async () => {
  const e = fixture(); e.fits = false; e.reason = 'DISK_QUOTA_EXCEEDED'; e.incoming = 11;
  await e.component.uploadFiles([{ name: 'a.txt', size: 6 }, { name: 'b.txt', size: 5 }]);
  assert.equal(e.uploaded, 0); assert.equal(e.shown, 0);
  assert.match(e.nodes['upload-notice'].textContent, /11 Б.*10 Б/);
  assert.equal(e.nodes['upload-notice'].hidden, false);
  assert.deepEqual(e.calls[0].payload.files, [{ name: 'a.txt', size: 6 }, { name: 'b.txt', size: 5 }]);
  assert.equal(e.component._uploadInProgress, false);
});
test('exact fit starts transfer only after preflight and refreshes usage', async () => {
  const e = fixture(); await e.component.uploadFiles(files);
  assert.deepEqual(e.calls.map(c => c.action), ['checkUpload', 'multipart', 'quota']);
  assert.equal(e.reloads, 1); assert.equal(e.uploaded, 1); assert.equal(e.finished[0][0], true);
  assert.equal(e.calls[1].payload.find(p => p[0] === 'currentFolderId')[1], 10);
  assert.equal(e.events[0].type, 'sb-disk-storage-changed');
});
test('replacement does not rename the old file when the new bytes do not fit', async () => {
  const e = fixture(); e.fits = false; e.reason = 'DISK_QUOTA_EXCEEDED';
  e.component.state.items = [{ id: 101, name: 'a.txt', entityType: 'file' }];
  e.component.askDuplicateUploadAction = async () => ({ action: 'replace' });
  await e.component.uploadFiles(files);
  assert.deepEqual(e.calls.map(c => c.action), ['checkUpload']);
  assert.equal(e.component.state.items[0].name, 'a.txt');
});
test('replacement saves history only after the full batch passes', async () => {
  const e = fixture(); e.component.state.items = [{ id: 101, name: 'a.txt', entityType: 'file' }];
  e.component.askDuplicateUploadAction = async () => ({ action: 'replace' });
  await e.component.uploadFiles(files);
  assert.deepEqual(e.calls.map(c => c.action), ['checkUpload', 'rename', 'multipart', 'quota']);
});
test('skipped duplicates are excluded from the capacity request', async () => {
  const e = fixture(); e.component.state.items = [{ id: 101, name: 'a.txt', entityType: 'file' }];
  e.component.askDuplicateUploadAction = async () => ({ action: 'cancel' });
  await e.component.uploadFiles(files);
  assert.deepEqual(e.calls[0].payload.files, [{ name: 'b.txt', size: 4 }]);
});
test('network failure leaves a visible warning and sends no file bytes', async () => {
  const e = fixture(); e.reply = async () => { throw new Error('offline'); };
  await e.component.uploadFiles(files);
  assert.equal(e.uploaded, 0); assert.equal(e.shown, 0);
  assert.match(e.nodes['upload-notice'].textContent, /Загрузка не начата/);
});
test('navigation during preflight cannot upload to a different folder', async () => {
  const e = fixture(), reply = e.reply;
  e.reply = async action => { e.component.state.currentFolderId = 11; return reply(action); };
  await e.component.uploadFiles(files);
  assert.equal(e.uploaded, 0);
  assert.match(e.nodes['upload-notice'].textContent, /папка изменилась/);
});
test('old usage responses cannot overwrite fresh preflight results', async () => {
  const e = fixture(), pending = deferred(), reply = e.reply;
  e.reply = action => action === 'quota' ? pending.promise : reply(action);
  const old = e.component.refreshQuota();
  await e.component.checkUploadCapacity(files, 10);
  pending.resolve({ ok: true, data: { quota: { usedBytes: 0, limitBytes: 100, availableBytes: 100 } } });
  await old;
  assert.equal(e.component.state.quota.usedBytes, 90);
});
test('usage errors do not claim an empty disk and retry recovers', async () => {
  const e = fixture(), reply = e.reply;
  e.reply = async () => ({ ok: false }); await e.component.refreshQuota();
  assert.equal(e.component.state.quota, null);
  assert.match(e.nodes['capacity-used'].textContent, /Не удалось определить/);
  e.reply = reply; await e.component.refreshQuota();
  assert.match(e.nodes['capacity-used'].textContent, /90 Б из 100 Б/);
});
test('meter handles unlimited, nearly full, overfull and nested capacity', async () => {
  const e = fixture(); await e.component.refreshQuota();
  assert.equal(e.nodes['capacity-progress'].value, 90);
  assert.equal(e.nodes['disk-capacity'].classList.contains('is-warning'), true);
  e.quota = { usedBytes: 110, limitBytes: 100, availableBytes: 0, hasAdditionalLimit: false };
  await e.component.refreshQuota();
  assert.equal(e.nodes['capacity-progress'].value, 100);
  assert.equal(e.nodes['disk-capacity'].classList.contains('is-full'), true);
  e.quota = { usedBytes: 90, limitBytes: 0, availableBytes: null, hasAdditionalLimit: false };
  await e.component.refreshQuota();
  assert.equal(e.nodes['capacity-progress'].hidden, true);
  assert.equal(e.nodes['capacity-available'].textContent, '');
  e.quota.availableBytes = 2; e.quota.hasAdditionalLimit = true;
  await e.component.refreshQuota();
  assert.match(e.nodes['capacity-available'].textContent, /2 Б с учётом/);
});
test('server rejection after a successful check still refreshes usage', async () => {
  const e = fixture(); e.uploadResponse = { ok: false, error: 'DISK_QUOTA_EXCEEDED', message: 'Место уже занято' };
  await e.component.uploadFiles(files);
  assert.equal(e.uploaded, 1); assert.equal(e.reloads, 1); assert.equal(e.finished[0][0], false);
  assert.match(e.nodes['upload-notice'].textContent, /Место уже занято/);
});
test('a second file selection cannot start a concurrent batch', async () => {
  const e = fixture(), pending = deferred(); e.reply = () => pending.promise;
  const first = e.component.uploadFiles(files);
  await e.component.uploadFiles(files);
  assert.equal(e.calls.length, 1);
  pending.resolve({ ok: false }); await first;
  assert.equal(e.uploaded, 0);
});
test('current file restrictions are shown as text before transfer', async () => {
  for (const reason of ['FILE_TOO_LARGE', 'EXTENSION_NOT_ALLOWED']) {
    const e = fixture(); e.fits = false; e.reason = reason;
    await e.component.uploadFiles(files);
    assert.equal(e.uploaded, 0); assert.match(e.nodes['upload-notice'].textContent, /<bad>.txt/);
  }
});
test('folder reload refreshes quota without blocking file listing', async () => {
  const e = fixture(), reply = e.reply;
  e.component.loadFolder = e.realLoadFolder;
  for (const method of ['setLoading', 'renderAll', 'applyPermissions', 'renderState']) e.component[method] = () => {};
  e.reply = async action => action === 'list' ? { ok: true, data: { items: [], breadcrumbs: [] } } : reply(action);
  await e.component.loadFolder(11);
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(e.calls.map(c => c.action), ['list', 'quota']);
  assert.equal(e.calls[1].payload.currentFolderId, 11);
  assert.equal(e.component.state.quota.usedBytes, 90);
});

(async () => {
  for (const [name, run] of tests) { await run(); console.log('PASS:', name); }
  console.log(tests.length + ' capacity UI scenarios passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
