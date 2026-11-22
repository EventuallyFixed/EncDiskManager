<?php
require_once __DIR__ . '/../../lib/registered_operation_inspection.php';
function check_registered_inspection($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$input = array('action' => 'unlock', 'luks_uuid' => $uuid);
$observed = 0;
$inspect = function () use (&$observed) { ++$observed; return true; };
check_registered_inspection(
    edm_with_registered_operation_inspection('/not/a/registry',
        array('action' => 'encrypt', 'luks_uuid' => $uuid), $inspect) === false
    && $observed === 0, 'Destructive request rejected before any inspection');
check_registered_inspection(
    edm_with_registered_operation_inspection('/not/a/registry',
        $input + array('device' => '/dev/sda'), $inspect) === false
    && $observed === 0, 'Client-supplied device path rejected');

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "SKIP: Root-only registry and disk lock integration\n";
    exit(0);
}
$dir = sys_get_temp_dir() . '/edm-registered-operation-' . getmypid();
check_registered_inspection(@mkdir($dir, 0700), 'Create root-only fixture');
$usb = array('complete' => true, 'devices' => array(array(
    'name' => 'sdb1', 'dev_id' => '8:17', 'uuid' => $uuid,
    'luks' => true, 'mount' => '', 'backup_mapper' => '', 'backup_mount' => ''
)));
$mappers = array('complete' => true, 'mappers' => array());
$mounts = array();
$discoverUsb = function () use ($usb) { return $usb; };
$discoverMappers = function () use ($mappers) { return $mappers; };
$readMounts = function () use ($mounts) { return $mounts; };
$registry = array('version' => 1, 'disks' => array(array(
    'luks_uuid' => $uuid, 'name' => 'Backup', 'mount_folder' => 'Backup',
    'mapper_name' => edm_registry_mapper_name($uuid), 'enabled' => true
)));
try {
    edm_registry_save($dir . '/disks.json', $registry);
    $result = edm_with_registered_operation_inspection($dir, $input,
        function ($request, $state) use (&$observed, $uuid) {
            ++$observed;
            return $request['luks_uuid'] === $uuid
                && $state['dev_id'] === '8:17';
        }, $discoverUsb, $discoverMappers, $readMounts);
    check_registered_inspection($result === true && $observed === 1,
        'Registered locked USB disk reaches inspector under lock');
    $registry['disks'][0]['enabled'] = false;
    edm_registry_save($dir . '/disks.json', $registry);
    $result = edm_with_registered_operation_inspection($dir, $input,
        $inspect, $discoverUsb, $discoverMappers, $readMounts);
    check_registered_inspection($result === false && $observed === 1,
        'Disabled registration cannot reach inspector');
    $registry['disks'] = array();
    edm_registry_save($dir . '/disks.json', $registry);
    $result = edm_with_registered_operation_inspection($dir, $input,
        $inspect, $discoverUsb, $discoverMappers, $readMounts);
    check_registered_inspection($result === false && $observed === 1,
        'Unregistered target cannot reach inspector');
} finally {
    foreach (array('disks.json', 'disks.json.lock', 'disk-operations.lock') as $name)
        @unlink($dir . '/' . $name);
    @rmdir($dir);
}
