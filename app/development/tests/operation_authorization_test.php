<?php
require_once __DIR__ . '/../../lib/operation_authorization.php';
function check_authorization($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$base = array(
    'uuid' => '12345678-1234-1234-1234-123456789abc',
    'name' => 'sdb1', 'dev_id' => '8:17', 'direct_mounted' => false,
    'mapper_open' => false, 'mapper_name' => null,
    'mapper_device' => null, 'mapper_dev_id' => null, 'mapper_mounted' => null
);
check_authorization(edm_operation_authorization('unlock', $base), 'Locked target can unlock');
foreach (array('mount', 'unmount', 'lock', 'encrypt') as $action)
    check_authorization(!edm_operation_authorization($action, $base),
        'Locked target rejects ' . $action);
$open = $base;
$open['mapper_open'] = true;
$open['mapper_name'] = 'edm_usb';
$open['mapper_device'] = 'dm-2';
$open['mapper_dev_id'] = '253:2';
$open['mapper_mounted'] = false;
check_authorization(edm_operation_authorization('mount', $open)
    && edm_operation_authorization('lock', $open), 'Open unmounted mapper allows mount or lock');
check_authorization(!edm_operation_authorization('unlock', $open)
    && !edm_operation_authorization('unmount', $open),
    'Open unmounted mapper rejects unlock and unmount');
$mounted = $open;
$mounted['mapper_mounted'] = true;
check_authorization(edm_operation_authorization('unmount', $mounted),
    'Mounted mapper permits unmount');
foreach (array('unlock', 'mount', 'lock') as $action)
    check_authorization(!edm_operation_authorization($action, $mounted),
        'Mounted mapper rejects ' . $action);
$direct = $mounted;
$direct['direct_mounted'] = true;
check_authorization(!edm_operation_authorization('unmount', $direct),
    'Direct-mounted LUKS partition fails closed');
$unknown = $open;
unset($unknown['mapper_mounted']);
check_authorization(!edm_operation_authorization('lock', $unknown),
    'Unknown mount state fails closed');
$inconsistent = $base;
$inconsistent['mapper_mounted'] = false;
check_authorization(!edm_operation_authorization('unlock', $inconsistent),
    'Inconsistent closed mapper state fails closed');
