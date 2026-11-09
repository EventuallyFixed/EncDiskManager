<?php
require_once __DIR__ . '/../../lib/authorized_operation_inspection.php';
function check_composed_gate($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$csrf = str_repeat('a', 64);
$request = array('action' => 'unlock', 'luks_uuid' => $uuid);
$inspected = 0;
$inspector = function () use (&$inspected) { ++$inspected; return true; };
$call = function ($method, $https, $authenticated, $expected, $posted, $input)
    use ($request, $inspector) {
    return edm_with_authorized_operation_inspection('/nonexistent/registry',
        $method, $https, $authenticated, $expected, $posted, $input,
        $inspector);
};
check_composed_gate($call('GET', true, true, $csrf, $csrf, $request) === false
    && $inspected === 0, 'GET rejected before registry inspection');
check_composed_gate($call('POST', false, true, $csrf, $csrf, $request) === false
    && $inspected === 0, 'HTTP rejected before registry inspection');
check_composed_gate($call('POST', true, false, $csrf, $csrf, $request) === false
    && $inspected === 0, 'Unauthenticated request rejected');
check_composed_gate($call('POST', true, true, $csrf, str_repeat('b', 64),
    $request) === false && $inspected === 0, 'Invalid CSRF rejected');
check_composed_gate($call('POST', true, true, $csrf, $csrf,
    $request + array('device' => '/dev/sda')) === false && $inspected === 0,
    'Extra client device path rejected');
check_composed_gate($call('POST', true, true, $csrf, $csrf,
    array('action' => 'encrypt', 'luks_uuid' => $uuid)) === false
    && $inspected === 0, 'Destructive action rejected');

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "SKIP: Root-only composed registry integration\n";
    exit(0);
}
$dir = sys_get_temp_dir() . '/edm-composed-operation-' . getmypid();
check_composed_gate(@mkdir($dir, 0700), 'Create private registry fixture');
$usb = array('complete' => true, 'devices' => array(array(
    'name' => 'sdb1', 'dev_id' => '8:17', 'uuid' => $uuid,
    'luks' => true, 'mount' => '', 'backup_mapper' => '', 'backup_mount' => ''
)));
$mappers = array('complete' => true, 'mappers' => array());
$registry = array('version' => 1, 'disks' => array(array(
    'luks_uuid' => $uuid, 'name' => 'Backup', 'mount_folder' => 'Backup',
    'mapper_name' => edm_registry_mapper_name($uuid), 'enabled' => true
)));
try {
    edm_registry_save($dir . '/disks.json', $registry);
    $result = edm_with_authorized_operation_inspection($dir,
        'POST', true, true, $csrf, $csrf, $request,
        function ($parsed, $state) use (&$inspected, $uuid) {
            ++$inspected;
            return $parsed['luks_uuid'] === $uuid
                && $state['dev_id'] === '8:17';
        }, function () use ($usb) { return $usb; },
        function () use ($mappers) { return $mappers; },
        function () { return array(); });
    check_composed_gate($result === true && $inspected === 1,
        'Authorized registered target reaches read-only inspector');
    $registry['disks'][0]['enabled'] = false;
    edm_registry_save($dir . '/disks.json', $registry);
    $result = edm_with_authorized_operation_inspection($dir,
        'POST', true, true, $csrf, $csrf, $request, $inspector,
        function () use ($usb) { return $usb; },
        function () use ($mappers) { return $mappers; },
        function () { return array(); });
    check_composed_gate($result === false && $inspected === 1,
        'Disabled registration cannot reach inspector');
} finally {
    foreach (array('disks.json', 'disks.json.lock', 'disk-operations.lock') as $name)
        @unlink($dir . '/' . $name);
    @rmdir($dir);
}
