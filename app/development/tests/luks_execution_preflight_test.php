<?php
require_once __DIR__ . '/../../lib/luks_execution_preflight.php';
function check_preflight($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$closed = array('uuid' => $uuid, 'name' => 'sdb1', 'dev_id' => '8:17',
    'direct_mounted' => false, 'mapper_open' => false,
    'mapper_name' => null, 'mapper_device' => null,
    'mapper_dev_id' => null, 'mapper_mounted' => null);
$trusted = function () { return '/usr/local/modules/usr/sbin/cryptsetup'; };
$unlock = edm_luks_execution_preflight('unlock', $closed, 'edm_backup', $trusted);
check_preflight($unlock !== null && $unlock['argv'][2] === '/dev/sdb1',
    'Valid closed LUKS target produces a non-executing plan');
check_preflight(edm_luks_execution_preflight('unlock', $closed, 'edm_backup',
    function () { return '/sbin/cryptsetup'; }) === null,
    'Fallback cryptsetup path rejected');
check_preflight(edm_luks_execution_preflight('unlock', $closed, 'edm_backup',
    function () { throw new RuntimeException('missing binary'); }) === null,
    'Binary resolution failure fails closed');
$open = $closed;
$open['mapper_open'] = true;
$open['mapper_name'] = 'edm_backup';
$open['mapper_device'] = 'dm-2';
$open['mapper_dev_id'] = '253:2';
$open['mapper_mounted'] = false;
$lock = edm_luks_execution_preflight('lock', $open, null, $trusted);
check_preflight($lock !== null && $lock['argv'][1] === 'luksClose',
    'Valid open unmounted mapper produces Lock plan');
$open['mapper_mounted'] = true;
check_preflight(edm_luks_execution_preflight('lock', $open, null, $trusted) === null,
    'Mounted mapper cannot be closed');
check_preflight(edm_luks_execution_preflight('encrypt', $closed, null, $trusted) === null,
    'Encryption not supported by preflight');
