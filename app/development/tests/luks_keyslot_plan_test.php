<?php
require_once __DIR__ . '/../../lib/luks_keyslot_plan.php';
function keyslot_assert($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
$p = edm_luks_keyslot_plan('verify-recovery', 'sdb1');
keyslot_assert(is_array($p) && $p['stdin'] === 'secret',
    'Recovery verification uses secret stdin');
keyslot_assert($p['argv'][0] === '/usr/sbin/cryptsetup'
    && in_array('--test-passphrase', $p['argv'], true)
    && in_array('luks1', $p['argv'], true),
    'Fixed LUKS1 verification argv');
$add = edm_luks_keyslot_plan('add-key', 'sdb1');
keyslot_assert(is_array($add) && $add['stdin'] === 'recovery-secret'
    && end($add['argv']) === '__PRIVATE_NEW_KEY_PATH__',
    'Keyslot plan requires separately verified private new-key path');
keyslot_assert(edm_luks_keyslot_plan('verify-recovery', '../sda') === null,
    'Traversal rejected');
keyslot_assert(edm_luks_keyslot_plan('verify-recovery', 'sda') === null,
    'Whole disk rejected');
keyslot_assert(edm_luks_keyslot_plan('verify-recovery', 'sdb1;id') === null,
    'Shell injection rejected');
