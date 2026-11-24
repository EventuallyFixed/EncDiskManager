/* Test the actual configuration dialog and its state-dependent inputs. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync('app/web/index.php', 'utf8');
const html = source.match(/<dialog id="disk-config-dialog"[\s\S]*?<\/dialog>/)[0];
const start = source.indexOf("    var dialog = document.getElementById('disk-config-dialog');");
const end = source.indexOf("    var table = document.getElementById('disk-inventory');", start);
assert.ok(start >= 0 && end > start);
const dom = new JSDOM('<table id="disk-inventory"><tr><td><button class="configure-disk">Configure</button><button class="register-disk">Register</button></td></tr></table>' + html, {runScripts: 'outside-only'});
const {window} = dom;
const doc = window.document;
const dialog = doc.querySelector('dialog');
dialog.showModal = () => { dialog.open = true; };
dialog.close = () => { dialog.open = false; };
window.eval(source.slice(start, end));
const button = doc.querySelector('.configure-disk');
const form = doc.querySelector('#disk-config-form');
const uuid = '12345678-1234-1234-1234-123456789abc';
Object.assign(button.dataset, {uuid, name: 'Backup', folder: 'backup', storeKey: '1', keyAvailable: '1'});
for (const [status, canEdit] of [['Disconnected', true], ['Encrypted — Locked', true],
    ['Encrypted — Unlocked', true], ['Encrypted — Mounted', false], ['State unknown', false]]) {
    Object.assign(button.dataset, {status, canConfigure: canEdit ? '1' : '0'});
    button.disabled = !canEdit;
    button.click();
    assert.equal(dialog.open, canEdit, status);
    if (!canEdit) continue;
    assert.equal(doc.querySelector('#config-name').readOnly, false, status);
    assert.equal(doc.querySelector('#config-folder').readOnly, false, status);
    assert.equal(form.elements.config_action.value, 'edit');
    assert.equal(new window.FormData(form).has('remove_unlock_key'), false, 'ordinary Save preserves key');
    dialog.close();
}
let submitted = false;
form.submit = () => { submitted = true; };
window.confirm = () => true;
button.disabled = false;
button.dataset.canConfigure = '1';
button.click();
doc.querySelector('#config-remove-key').click();
assert.equal(submitted, true);
assert.equal(form.elements.config_action.value, 'key_preference');
assert.equal(new window.FormData(form).get('remove_unlock_key'), '1');
dialog.close();
button.click();
assert.equal(new window.FormData(form).has('remove_unlock_key'), false, 'reopening resets key removal');
const register = doc.querySelector('.register-disk');
Object.assign(register.dataset, {uuid, name: 'New disk', status: 'Encrypted — Locked'});
register.click();
assert.equal(form.elements.config_action.value, 'register');
assert.equal(doc.querySelector('#config-folder').readOnly, false);
assert.equal(doc.querySelector('#config-remove-key').disabled, true);
console.log('PASS: configuration fields, mounted restrictions, key removal and registration');

const renderStart = source.indexOf('    function updateRow(row, item) {');
const renderEnd = source.indexOf('    function sortRows()', renderStart);
const render = new Function('document', 'operationBusy', source.slice(renderStart, renderEnd) + '\nreturn updateRow;')(doc, false);
const row = doc.querySelector('tr');
for (let n = 0; n < 6; n++) row.appendChild(doc.createElement('td'));
for (const action of ['Unlock','Mount','Unmount','Lock']) {
    const control = doc.createElement('button');
    control.textContent = action;
    row.cells[0].appendChild(control);
}
dialog.close();
for (const [status, canConfigure] of [['Encrypted — Locked', true], ['Encrypted — Locked', false], ['Encrypted — Mounted', false], ['Encrypted — Unlocked', true], ['Disconnected', true]]) {
    render(row, {connection: status === 'Disconnected' ? 'disconnected' : 'connected',
        registered: true, name: 'Backup', device: '/dev/sdb1', status, type: 'crypto_LUKS',
        uuid, mount: '/mnt/backup', folder: 'backup', mapper: '', can_configure: canConfigure,
        actions: {unlock:false,mount:false,unmount:false,lock:false,encrypt:false}});
    assert.equal(button.disabled, !canConfigure, 'actual row renderer follows whole-disk configuration availability');
    button.click();
    assert.equal(dialog.open, canConfigure, 'disabled Configure cannot open dialog');
    dialog.close();
}
console.log('PASS: Configure follows whole-disk mount state and re-enables after unmount');
