<?php
require_once __DIR__ . '/../../lib/luks_registry_authorization.php';
function check_owner($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$mapper = edm_registry_mapper_name($uuid);
$registry = array('version' => 1, 'disks' => array(array(
    'luks_uuid' => $uuid, 'name' => 'Backup', 'mount_folder' => 'Backup',
    'mapper_name' => $mapper, 'enabled' => true
)));
$closed = array('uuid' => $uuid, 'name' => 'sdb1', 'dev_id' => '8:17',
    'direct_mounted' => false, 'mapper_open' => false,
    'mapper_name' => null, 'mapper_device' => null,
    'mapper_dev_id' => null, 'mapper_mounted' => null);
check_owner(edm_luks_registry_authorization($registry, $uuid, 'unlock',
    $closed, $mapper), 'Enabled registered mapper can be unlocked');
check_owner(!edm_luks_registry_authorization($registry, $uuid, 'unlock',
    $closed, 'edm_other'), 'Wrong mapper rejected');
$disabled = $registry;
$disabled['disks'][0]['enabled'] = false;
check_owner(!edm_luks_registry_authorization($disabled, $uuid, 'unlock',
    $closed, $mapper), 'Disabled registration rejected');
check_owner(!edm_luks_registry_authorization(array('version' => 1,
    'disks' => array()), $uuid, 'unlock', $closed, $mapper),
    'Unregistered target rejected');
$open = $closed;
$open['mapper_open'] = true;
$open['mapper_name'] = $mapper;
$open['mapper_device'] = 'dm-2';
$open['mapper_dev_id'] = '253:2';
$open['mapper_mounted'] = false;
check_owner(edm_luks_registry_authorization($registry, $uuid, 'lock',
    $open), 'Registered open unmounted mapper may be locked');
$open['mapper_name'] = 'edm_other';
check_owner(!edm_luks_registry_authorization($registry, $uuid, 'lock',
    $open), 'Foreign mapper cannot be closed');
