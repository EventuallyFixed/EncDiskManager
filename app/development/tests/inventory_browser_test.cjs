/* Browser-like regression tests of the actual inline inventory script.
 * Run: npm install --no-save jsdom@26 && node app/development/tests/inventory_browser_test.cjs
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');

const source = fs.readFileSync('app/web/index.php', 'utf8');
const scripts = [...source.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)];
const script = scripts[scripts.length - 1][1];
const uuid = '12345678-1234-1234-1234-123456789abc';
const registered = (connection, name = 'Backup') => ({
  key: 'registered:' + uuid, connection, registered: true, name,
  device: connection === 'connected' ? '/dev/sdb1' : '—',
  status: connection === 'connected' ? 'Encrypted — Locked' : 'Disconnected',
  type: connection === 'connected' ? 'crypto_LUKS' : '—',
  uuid, mount: '/mnt/backup', folder: 'backup'
});
const unregistered = {
  key: 'device:sdc1', connection: 'connected', registered: false,
  name: 'Temporary', device: '/dev/sdc1', status: 'Not identified as LUKS',
  type: 'ext4', uuid: '—', mount: '—', folder: ''
};
const html = `<!doctype html><html><body>
<div id="disk-inventory-panel"><table id="disk-inventory">
<thead><tr>${['Name','Device','Status','Type','UUID','Mount'].map((n,i)=>`<th><button class="sort-heading" data-column="${i}">${n}</button></th>`).join('')}<th>Actions</th></tr></thead>
<tbody></tbody></table></div>
<button class="inventory-tab" id="connected-tab" data-filter="connected" aria-selected="true"></button>
<button class="inventory-tab" id="disconnected-tab" data-filter="disconnected" aria-selected="false"></button>
<p id="inventory-empty-filter"></p>
<dialog id="disk-config-dialog"><span id="config-uuid"></span><input id="config-form-uuid">
<span id="config-status"></span><input id="config-name"><input id="config-folder">
<button id="close-disk-config"></button></dialog></body></html>`;

async function run() {
  const dom = new JSDOM(html, { url: 'https://nas.local/', pretendToBeVisual: true, runScripts: 'outside-only' });
  const { window } = dom;
  const { document } = window;
  const requests = [];
  window.fetch = () => new Promise((resolve, reject) => requests.push({ resolve, reject }));
  window.setInterval = fn => { window.pollTest = fn; };
  const dialog = document.querySelector('dialog');
  dialog.showModal = () => { dialog.open = true; };
  dialog.close = () => { dialog.open = false; };
  window.eval(script);
  const table = document.querySelector('#disk-inventory');
  const rows = () => [...table.tBodies[0].rows];
  const tick = async (items, fail = false) => {
    window.pollTest();
    assert.ok(requests.length, 'poll must start');
    const req = requests.shift();
    if (fail) req.reject(new Error('network')); else req.resolve({
      ok: true, json: async () => ({ rows: items })
    });
    for (let i = 0; i < 10; i++) await new Promise(resolve => setImmediate(resolve));
  };
  assert.equal(rows().length, 0, 'starts with empty table');
  await tick([registered('connected')]);
  assert.equal(rows().length, 1, 'connect inserts a row');
  const original = rows()[0];
  assert.equal(original.hidden, false);
  await tick([registered('disconnected')]);
  assert.strictEqual(rows()[0], original, 'disconnect preserves DOM row');
  assert.equal(original.hidden, true, 'connected tab hides disconnected row');
  document.querySelector('#disconnected-tab').click();
  assert.equal(original.hidden, false, 'disconnected tab shows row');
  await tick([registered('connected')]);
  assert.strictEqual(rows()[0], original, 'reconnect preserves DOM row');
  assert.equal(original.hidden, true, 'selected tab persists');
  document.querySelector('#connected-tab').click();
  await tick([registered('connected'), unregistered]);
  assert.equal(rows().length, 2, 'new unregistered row added');
  document.querySelectorAll('.sort-heading')[0].click();
  await tick([registered('connected', 'Zebra'), unregistered]);
  assert.equal(rows()[0].cells[0].textContent, 'Zebra', 'sort preference survives polling');
  const configure = rows().find(r => r.getAttribute('data-key') === registered('connected').key)
    .querySelector('.configure-disk');
  configure.click();
  document.querySelector('#config-name').value = 'Unsaved user edit';
  window.pollTest();
  assert.equal(requests.length, 0, 'dialog suspends new polling');
  assert.equal(document.querySelector('#config-name').value, 'Unsaved user edit');
  dialog.close();
  const beforeFailure = rows().slice();
  await tick([], true);
  assert.deepEqual(rows(), beforeFailure, 'network failure retains rows');
  assert.match(document.querySelector('#inventory-poll-status').textContent, /stale/);
  // A malformed snapshot must not clear the previously displayed rows.
  await tick([registered('connected'), registered('connected')]);
  assert.deepEqual(rows(), beforeFailure, 'duplicate keys preserve last valid snapshot');
  assert.match(document.querySelector('#inventory-poll-status').textContent, /stale/);
  await tick([registered('connected'), { ...unregistered, status: undefined }]);
  assert.deepEqual(rows(), beforeFailure, 'missing display field preserves existing rows');
  assert.match(document.querySelector('#inventory-poll-status').textContent, /stale/);
  // If a response arrives after a dialog opens, preserve user edits.
  window.pollTest();
  const inFlight = requests.shift();
  configure.click();
  document.querySelector('#config-name').value = 'Still editing';
  inFlight.resolve({ok: true, json: async () => ({rows: []})});
  for (let i = 0; i < 10; i++) await new Promise(resolve => setImmediate(resolve));
  assert.equal(document.querySelector('#config-name').value, 'Still editing');
  assert.equal(rows().length, 2, 'in-flight response does not replace rows during dialog');
  dialog.close();
  await tick([]);
  assert.equal(rows().length, 0, 'successful empty snapshot removes rows');
  console.log('PASS: empty start, connection transitions, row identity, tabs, sorting, dialog and failures');
}
run().catch(e => { console.error(e); process.exitCode = 1; });
