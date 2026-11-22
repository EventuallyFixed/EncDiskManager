<?php
require_once __DIR__ . '/../../lib/luks_postcondition.php';
function check_post($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$closed = array('uuid' => '12345678-1234-1234-1234-123456789abc',
    'name' => 'sdb1', 'dev_id' => '8:17', 'direct_mounted' => false,
    'mapper_open' => false, 'mapper_name' => null, 'mapper_device' => null,
    'mapper_dev_id' => null, 'mapper_mounted' => null);
$open = $closed;
$open['mapper_open'] = true;
$open['mapper_name'] = 'edm_backup';
$open['mapper_device'] = 'dm-2';
$open['mapper_dev_id'] = '253:2';
$open['mapper_mounted'] = false;
check_post(edm_luks_postcondition('unlock', $closed, $open, 'edm_backup'),
    'Unlock requires expected new unmounted mapper');
check_post(edm_luks_postcondition('lock', $open, $closed),
    'Lock requires mapper disappearance');
check_post(!edm_luks_postcondition('unlock', $closed, $closed, 'edm_backup'),
    'Unlock without state transition rejected');
check_post(!edm_luks_postcondition('lock', $open, $open),
    'Lock without state transition rejected');
$wrong = $open;
$wrong['mapper_name'] = 'edm_other';
check_post(!edm_luks_postcondition('unlock', $closed, $wrong, 'edm_backup'),
    'Unexpected mapper name rejected');
$mounted = $open;
$mounted['mapper_mounted'] = true;
check_post(!edm_luks_postcondition('unlock', $closed, $mounted, 'edm_backup'),
    'Unexpected auto-mount rejected');
check_post(!edm_luks_postcondition('lock', $mounted, $closed),
    'Mounted mapper cannot be locked');
$replaced = $open;
$replaced['dev_id'] = '8:33';
check_post(!edm_luks_postcondition('unlock', $closed, $replaced, 'edm_backup'),
    'Replaced source device rejected');
$direct = $closed;
$direct['direct_mounted'] = true;
check_post(!edm_luks_postcondition('lock', $open, $direct),
    'Unexpected direct mount rejected');
check_post(!edm_luks_postcondition('encrypt', $closed, $open, 'edm_backup'),
    'Encryption is never authorized');
