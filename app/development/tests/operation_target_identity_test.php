<?php
require_once __DIR__ . '/../../lib/operation_target_identity.php';
function check_target($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$device = array('name' => 'sdb1', 'dev_id' => '8:17', 'uuid' => $uuid, 'luks' => true);
$snapshot = array('complete' => true, 'devices' => array($device));
$bound = edm_operation_target_identity($uuid, $snapshot);
check_target($bound === array('uuid' => $uuid, 'name' => 'sdb1', 'dev_id' => '8:17'),
    'UUID binds to kernel partition identity');
check_target(edm_operation_target_still_matches($bound, $snapshot),
    'Unchanged identity passes fresh check');
$replaced = $device;
$replaced['dev_id'] = '8:33';
check_target(!edm_operation_target_still_matches($bound,
    array('complete' => true, 'devices' => array($replaced))),
    'Replaced kernel device number is rejected');
$renamed = $device;
$renamed['name'] = 'sdc1';
check_target(!edm_operation_target_still_matches($bound,
    array('complete' => true, 'devices' => array($renamed))),
    'Changed partition path identity is rejected');
check_target(edm_operation_target_identity($uuid,
    array('complete' => true, 'devices' => array($device, $device))) === null,
    'Duplicate device identity fails closed');
$duplicate = $device;
$duplicate['name'] = 'sdc1';
$duplicate['dev_id'] = '8:33';
check_target(edm_operation_target_identity($uuid,
    array('complete' => true, 'devices' => array($device, $duplicate))) === null,
    'Duplicate LUKS UUID fails closed');
check_target(edm_operation_target_identity($uuid,
    array('complete' => false, 'devices' => array($device))) === null,
    'Incomplete discovery fails closed');
check_target(edm_operation_target_identity($uuid,
    array('complete' => true, 'devices' => array())) === null,
    'Disconnected target cannot be bound');
