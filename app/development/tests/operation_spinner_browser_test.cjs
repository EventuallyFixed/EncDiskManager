/* Exercise the actual operation handler and row renderer with deferred requests. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync('app/web/index.php', 'utf8');
const renderer = source.slice(source.indexOf('    function updateRow(row, item) {'), source.indexOf('    function sortRows()'));
const errorRenderer = source.slice(source.indexOf('    function showOperationError('), source.indexOf('    function currentConnection()'));
const start = source.indexOf("    table.addEventListener('click', async function (event) {");
const end = source.indexOf('    // Show an open mapper', start);
assert.ok(start >= 0 && end > start && renderer.length > 0);
const uuid = '12345678-1234-1234-1234-123456789abc';
async function check(action, before, after, fail = false, manual = false, cancel = false) {
  const dom = new JSDOM('<input name="csrf_token" value="test"><p></p><table><tbody><tr><td>' +
    ['Unlock','Mount','Unmount','Lock'].map(x => '<button>' + x + '</button>').join('') +
    '</td>' + '<td></td>'.repeat(6) + '</tr></tbody></table>');
  const {document} = dom.window;
  const table = document.querySelector('table'), row = table.rows[0], notice = document.querySelector('p');
  let resolve, requests = 0, refreshes = 0, prompts = 0;
  const fetch = () => { requests++; return new Promise(r => { resolve = r; }); };
  const item = enabled => ({
    connection:'connected', registered:true, name:'Test', device:'/dev/sdb1', mapper:'edm_test',
    uuid, status:'Encrypted', type:'crypto_LUKS', mount:'/mnt/test', folder:'test',
    store_unlock_key:!manual, stored_key_available:!manual, can_configure:false,
    actions:Object.fromEntries(['unlock','mount','unmount','lock'].map(a => [a, enabled.includes(a)]))
  });
  const api = new Function('document','table','operationNotice','fetch','requestUnlockPassphrase','edmMarkUnplugUnsafe','refresh',
    'var operationBusy = false;\n' + renderer + '\n' + errorRenderer + '\n' + source.slice(start,end) +
    '\nfunction pollInventory(){ refresh(); }\nreturn {updateRow, busy:()=>operationBusy};'
  )(document,table,notice,fetch,async()=>{prompts++;return cancel?null:{passphrase:'test'};},()=>{},()=>{refreshes++;});
  const buttons = () => [...row.querySelectorAll('[data-operation]')];
  const states = enabled => buttons().forEach(b => assert.equal(b.disabled,!enabled.includes(b.dataset.operation),b.dataset.operation));
  const settle = () => new Promise(r => setImmediate(r));
  api.updateRow(row,item(before));
  states(before);
  const button = buttons().find(b => b.dataset.operation === action);
  const label = button.textContent;
  button.click();
  await settle();
  assert.equal(prompts,manual?1:0);
  if(cancel){assert.equal(requests,0);states(before);dom.window.close();return;}
  assert.equal(requests,1);
  assert.equal(api.busy(),true);
  assert.ok(button.classList.contains('operation-running'));
  assert.equal(button.getAttribute('aria-busy'),'true');
  states([]);
  buttons().forEach(b=>b.click());
  assert.equal(requests,1);
  resolve({ok:!fail,json:async()=>({ok:!fail,error:'test failure'})});
  await settle();
  assert.equal(api.busy(),false);
  assert.equal(refreshes,1);
  assert.equal(button.textContent,label);
  assert.equal(button.classList.contains('operation-running'),false);
  assert.equal(button.hasAttribute('aria-busy'),false);
  const error = row.cells[0].querySelector('.operation-error');
  assert.equal(error.textContent, fail ? 'test failure' : '');
  assert.equal(error.hidden, !fail);
  assert.equal(error.getAttribute('role'), 'alert');
  states([]);
  api.updateRow(row,item(after));
  states(after);
  assert.equal(error.hidden, !fail);
  assert.equal(row.querySelector('.configure-disk').disabled,true);
  dom.window.close();
}
(async()=>{
  await check('unlock',['unlock'],['mount','lock']);
  await check('unlock',['unlock'],['mount','lock'],false,true);
  await check('unlock',['unlock'],['unlock'],false,true,true);
  await check('mount',['mount','lock'],['unmount']);
  await check('unmount',['unmount'],['mount','lock']);
  await check('lock',['mount','lock'],['unlock']);
  await check('mount',['mount','lock'],['mount','lock'],true);
  console.log('PASS: operation spinner, busy controls, cancellation, errors and state-based restoration');
})().catch(e=>{console.error(e);process.exitCode=1;});
