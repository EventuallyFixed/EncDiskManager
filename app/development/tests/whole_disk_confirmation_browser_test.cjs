/* Browser regression for the non-destructive whole-disk confirmation preview. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync('app/web/index.php', 'utf8');
const scripts = [...source.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)];
const script = scripts[scripts.length - 1][1];
const html = `<!doctype html><html><body>
<input name="csrf_token" value="${'a'.repeat(64)}">
<button class="whole-disk-preview-button" data-disk="/dev/sdb"></button>
<button class="whole-disk-preview-button" data-disk="/dev/sdc"></button>
<dialog id="whole-disk-erase-preview">
<strong id="whole-disk-preview-name"></strong>
<input id="whole-disk-erase-ack" type="checkbox">
<input id="whole-disk-erase-phrase">
<button id="whole-disk-preview-confirm" disabled></button>
<p id="whole-disk-preview-status"></p>
</dialog></body></html>`;
async function run() {
  const dom = new JSDOM(html, {url: 'https://nas.local/', runScripts: 'outside-only'});
  const {window} = dom;
  const document = window.document;
  const dialog = document.querySelector('dialog');
  dialog.showModal = () => { dialog.open = true; };
  const pending = [];
  window.fetch = (url, options) => new Promise((resolve, reject) => pending.push({url, options, resolve, reject}));
  // Only the standalone whole-disk preview IIFE is needed for this test.
  const start = script.indexOf('(function () {');
  const end = script.indexOf('}());', start) + 5;
  assert.ok(start >= 0 && end > start);
  window.eval(script.slice(start, end));
  const buttons = document.querySelectorAll('.whole-disk-preview-button');
  const ack = document.querySelector('#whole-disk-erase-ack');
  const phrase = document.querySelector('#whole-disk-erase-phrase');
  const confirm = document.querySelector('#whole-disk-preview-confirm');
  const status = document.querySelector('#whole-disk-preview-status');
  const flush = async () => { for (let i = 0; i < 8; i++) await new Promise(resolve => setImmediate(resolve)); };
  buttons[0].click();
  assert.equal(document.querySelector('#whole-disk-preview-name').textContent, '/dev/sdb');
  assert.equal(confirm.disabled, true);
  ack.checked = true; ack.dispatchEvent(new window.Event('change'));
  phrase.value = 'wrong'; phrase.dispatchEvent(new window.Event('input'));
  assert.equal(confirm.disabled, true, 'incorrect phrase prevents submission');
  phrase.value = 'ERASE ALL DATA'; phrase.dispatchEvent(new window.Event('input'));
  assert.equal(confirm.disabled, false);
  confirm.click();
  assert.equal(pending.length, 1);
  assert.equal(pending[0].url, 'whole-disk-confirmation-preview.php');
  assert.equal(pending[0].options.method, 'POST');
  const posted = new URLSearchParams(pending[0].options.body);
  assert.equal(posted.get('warning_accepted'), 'yes');
  assert.equal(posted.get('confirmation_phrase'), 'ERASE ALL DATA');
  assert.equal(posted.get('csrf_token'), 'a'.repeat(64));
  pending.shift().resolve({ok: true, json: async () => ({confirmed: true, execution_enabled: false})});
  await flush();
  assert.match(status.textContent, /Server validated/);
  buttons[1].click();
  assert.equal(document.querySelector('#whole-disk-preview-name').textContent, '/dev/sdc');
  assert.equal(ack.checked, false, 'acknowledgement reset');
  assert.equal(phrase.value, '', 'phrase reset');
  assert.equal(confirm.disabled, true, 'button reset');
  ack.checked = true; ack.dispatchEvent(new window.Event('change'));
  phrase.value = 'ERASE ALL DATA'; phrase.dispatchEvent(new window.Event('input'));
  confirm.click();
  pending.shift().resolve({ok: false});
  await flush();
  assert.match(status.textContent, /could not be validated/);
  assert.equal(confirm.disabled, false, 'can retry after rejection');
  confirm.click();
  pending.shift().resolve({ok: true, json: async () => ({confirmed: true, execution_enabled: true})});
  await flush();
  assert.match(status.textContent, /could not be validated/, 'execution-enabled response rejected');
  console.log('PASS: whole disk confirmation preview reset, validation, rejection and no execution');
}
run().catch(error => { console.error(error); process.exitCode = 1; });
