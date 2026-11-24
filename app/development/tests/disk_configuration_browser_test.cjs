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
    Object.assign(button.dataset, {status, canDelete: canEdit ? '1' : '0'});
    button.click();
    assert.equal(doc.querySelector('#config-name').readOnly, false, status);
    assert.equal(doc.querySelector('#config-folder').readOnly, !canEdit, status);
    assert.equal(form.elements.config_action.value, 'edit');
    assert.equal(new window.FormData(form).has('remove_unlock_key'), false, 'ordinary Save preserves key');
    dialog.close();
}
let submitted = false;
form.submit = () => { submitted = true; };
window.confirm = () => true;
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
