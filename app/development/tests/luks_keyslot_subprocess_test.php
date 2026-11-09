<?php
require_once __DIR__ . '/../../lib/luks_keyslot_plan.php';
require_once __DIR__ . '/../../lib/luks_keyslot_subprocess.php';
function keyslot_runner_check($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$p = edm_luks_keyslot_plan('verify-recovery', 'sdb1');
keyslot_runner_check(edm_luks_keyslot_subprocess($p, '') === false,
    'Empty recovery secret rejected before execution');
keyslot_runner_check(edm_luks_keyslot_subprocess($p, str_repeat('x', 4097)) === false,
    'Oversized recovery secret rejected');
keyslot_runner_check(edm_luks_keyslot_subprocess($p, 'secret', 0) === false,
    'Invalid timeout rejected');
$p['argv'][0] = '/bin/sh';
keyslot_runner_check(edm_luks_keyslot_subprocess($p, 'secret') === false,
    'Alternative executable rejected');
$p = edm_luks_keyslot_plan('add-key', 'sdb1');
keyslot_runner_check(edm_luks_keyslot_subprocess($p, 'secret') === false,
    'Unbound keyfile placeholder rejected');
$p['argv'][7] = '/tmp/key';
keyslot_runner_check(edm_luks_keyslot_subprocess($p, 'secret') === false,
    'Arbitrary keyfile path rejected');

$p = edm_luks_keyslot_plan('verify-recovery', 'sdb1');
$p['argv'][0] = '/usr/sbin/cryptsetup';
keyslot_runner_check(edm_luks_keyslot_subprocess($p, 'secret') === false,
    'Vendor symlink alias rejected before subprocess creation');
$p['argv'][0] = '/bin/mount';
keyslot_runner_check(edm_luks_keyslot_subprocess($p, 'secret') === false,
    'Different trusted binary rejected for keyslot operations');
$p = edm_luks_keyslot_plan('verify-recovery', 'sdb1');
$p['argv'][2] = '--help';
keyslot_runner_check(edm_luks_keyslot_subprocess($p, 'secret') === false,
    'Modified verification arguments rejected');
$p = edm_luks_keyslot_plan('add-key', 'sdb1');
$p['argv'][0] = '/usr/sbin/cryptsetup';
$p['argv'][7] = '/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager/keys/12345678123412341234123456789abc.key';
keyslot_runner_check(edm_luks_keyslot_subprocess($p, 'secret') === false,
    'Keyslot addition rejects symlink executable even with shaped key path');
