/* Regression: stored keys affect authentication, not disk action availability.
 * Run: npm install --no-save --no-package-lock jsdom@26
 *      node app/development/tests/stored_key_actions_browser_test.cjs
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');

const source = fs.readFileSync('app/web/index.php', 'utf8');
const start = source.indexOf('    function updateRow(row, item) {');
const end = source.indexOf('    function sortRows()', start);
assert.ok(start >= 0 && end > start, 'actual row renderer is available');
const dom = new JSDOM('<table><tbody><tr><td>' +
  '<button class="register-disk">Register</button>' +
  '<button class="configure-disk">Configure</button>' +
  ['Unlock', 'Mount', 'Unmount', 'Lock'].map(x => '<button>' + x + '</button>').join('') +
  '</td>' + '<td></td>'.repeat(6) + '</tr></tbody></table>');
const document = dom.window.document;
const row = document.querySelector('tr');
const render = new Function('document', 'operationBusy',
  source.slice(start, end) + '\nreturn updateRow;')(document, false);
const uuid = '12345678-1234-1234-1234-123456789abc';
function check(status, storeKey, keyAvailable, enabled) {
  const actions = Object.fromEntries(['unlock','mount','unmount','lock']
    .map(action => [action, enabled.includes(action)]));
  actions.encrypt = false;
  render(row, {
    key: 'registered:' + uuid, connection: 'connected', registered: true,
    name: 'SanDisk Ultra Fit', device: '/dev/sdd1', status,
    type: 'crypto_LUKS', uuid, mount: '/mnt/sandisk', folder: 'sandisk',
    mapper: status === 'Encrypted — Locked' ? '' : 'edm_test',
    store_unlock_key: storeKey, stored_key_available: keyAvailable,
    actions
  });
  const buttons = [...row.cells[0].querySelectorAll('button[data-operation]')];
  assert.equal(buttons.length, 4, 'four actions always rendered');
  for (const button of buttons) {
    assert.equal(button.hidden, false, button.dataset.operation + ' remains visible');
    assert.equal(button.disabled, !enabled.includes(button.dataset.operation),
      button.dataset.operation + ' eligibility in ' + status);
  }
}
check('Encrypted — Locked', true, true, ['unlock']);
check('Encrypted — Locked', true, false, ['unlock']);
check('Encrypted — Locked', false, false, ['unlock']);
check('Encrypted — Unlocked', true, true, ['mount', 'lock']);
check('Encrypted — Unlocked', false, false, ['mount', 'lock']);
check('Encrypted — Mounted', true, true, ['unmount']);
check('Encrypted — Mounted', false, false, ['unmount']);
assert.ok(source.includes("row.dataset.storeKey === '1' && row.dataset.keyAvailable === '1'"),
  'stored key skips the recovery passphrase on Unlock');
assert.ok(!source.includes('directMount'), 'locked stored-key disk never previews direct Mount');
console.log('PASS: stored and manual keys share locked, unlocked and mounted action states');
