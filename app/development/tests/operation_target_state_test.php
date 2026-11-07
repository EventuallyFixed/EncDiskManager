<?php
require_once __DIR__ . '/../../lib/operation_target_state.php';
function check_state($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$bound = array('uuid' => $uuid, 'name' => 'sdb1', 'dev_id' => '8:17');
$device = array('name' => 'sdb1', 'dev_id' => '8:17', 'uuid' => $uuid,
    'luks' => true, 'mount' => '', 'backup_mapper' => '', 'backup_mount' => '');
$devices = array('complete' => true, 'devices' => array($device));
$empty = array('complete' => true, 'mappers' => array());
$state = edm_operation_target_state($bound, $devices, $empty);
check_state($state !== null && !$state['mapper_open'] && !$state['direct_mounted'],
    'Locked unmounted target has known state');
$mapper = array('name' => 'edm_usb', 'device' => 'dm-2', 'dev_id' => '253:2', 'source_uuid' => $uuid,
    'parents' => array('sdb1'));
$open = edm_operation_target_state($bound, $devices,
    array('complete' => true, 'mappers' => array($mapper)),
    array('20 1 253:2 / /mnt/backup rw - ext4 /dev/mapper/alias rw'));
check_state($open !== null && $open['mapper_open'] && $open['mapper_device'] === 'dm-2' && $open['mapper_mounted'] === true,
    'Matching mapper source and parent accepted');
$wrong = $mapper;
$wrong['parents'] = array('sdc1');
check_state(edm_operation_target_state($bound, $devices,
    array('complete' => true, 'mappers' => array($wrong)), array()) === null,
    'Mapper claiming UUID with wrong parent rejected');
$replacement = $device;
$replacement['dev_id'] = '8:33';
check_state(edm_operation_target_state($bound,
    array('complete' => true, 'devices' => array($replacement)), $empty) === null,
    'Replaced device number rejected');
check_state(edm_operation_target_state($bound, $devices,
    array('complete' => false, 'mappers' => array())) === null,
    'Incomplete mapper discovery rejected');
$mounted = $device;
$mounted['mount'] = 'mounted';
$mountedState = edm_operation_target_state($bound,
    array('complete' => true, 'devices' => array($mounted)), $empty);
check_state($mountedState !== null && $mountedState['direct_mounted'],
    'Direct mount is reported');

$unmounted = edm_operation_target_state($bound, $devices,
    array('complete' => true, 'mappers' => array($mapper)), array());
check_state($unmounted !== null && $unmounted['mapper_mounted'] === false,
    'Open mapper without a mount is reported unmounted');
check_state(edm_operation_target_state($bound, $devices,
    array('complete' => true, 'mappers' => array($mapper))) === null,
    'Open mapper without mount evidence fails closed');
check_state(edm_operation_target_state($bound, $devices,
    array('complete' => true, 'mappers' => array($mapper)),
    array('invalid mountinfo')) === null,
    'Malformed mapper mount evidence fails closed');
