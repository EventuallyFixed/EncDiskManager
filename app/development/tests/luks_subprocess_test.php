<?php
require_once __DIR__ . '/../../lib/luks_subprocess.php';
function check_runner($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
check_runner(edm_luks_subprocess(array('/bin/true', 'luksClose', 'edm_backup')) === false,
    'Reject arbitrary executable');
check_runner(edm_luks_subprocess(array('/usr/sbin/cryptsetup', 'luksFormat',
    'edm_backup')) === false, 'Reject destructive cryptsetup command');
check_runner(edm_luks_subprocess(array('/usr/sbin/cryptsetup', 'luksClose',
    'edm_bad;id')) === false, 'Reject injected mapper name');
check_runner(edm_luks_subprocess(array('/usr/sbin/cryptsetup', 'luksClose',
    'edm_backup'), 'unexpected-secret') === false, 'Reject secret on Lock');
check_runner(edm_luks_subprocess(array('/usr/sbin/cryptsetup', 'luksOpen',
    '--key-file', '-', '/dev/sdb1', 'edm_backup'), null) === false,
    'Reject missing Unlock secret');
check_runner(edm_luks_subprocess(array('/usr/sbin/cryptsetup', 'luksOpen',
    '--key-file', '-', '/dev/sdb1', 'edm_backup'), str_repeat('x', 4097)) === false,
    'Reject oversized secret');
check_runner(edm_luks_subprocess(array('/usr/sbin/cryptsetup', 'luksClose',
    'edm_backup'), null, 0) === false, 'Reject invalid timeout');
