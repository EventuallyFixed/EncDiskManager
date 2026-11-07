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
