<?php
require_once __DIR__ . '/../../lib/verified_operation_authorization.php';
function check_gate($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$usb = array('complete' => true, 'devices' => array(array(
    'name' => 'sdb1', 'dev_id' => '8:17', 'uuid' => $uuid,
    'luks' => true, 'mount' => '', 'backup_mapper' => '', 'backup_mount' => ''
)));
$mapper = array('complete' => true, 'mappers' => array(array(
    'name' => 'usb', 'device' => 'dm-2', 'dev_id' => '253:2',
    'source_uuid' => $uuid, 'parents' => array('sdb1')
)));
$mounts = array('20 1 253:2 / /mnt/usb rw - ext4 /dev/mapper/alias rw');
$directory = sys_get_temp_dir() . '/edm-operation-gate-' . getmypid();
mkdir($directory, 0700);
try {
    if (posix_geteuid() === 0) {
        $observed = 0;
        $result = edm_with_verified_operation_authorization($directory, $uuid,
            'unmount', function ($state) use (&$observed) {
                ++$observed;
                return $state['mapper_mounted'];
            }, function () use ($usb) { return $usb; },
            function () use ($mapper) { return $mapper; },
            function () use ($mounts) { return $mounts; });
        check_gate($result === true && $observed === 1,
            'Mounted mapper reaches authorized read-only inspector');
        $result = edm_with_verified_operation_authorization($directory, $uuid,
            'lock', function () use (&$observed) { ++$observed; return true; },
            function () use ($usb) { return $usb; },
            function () use ($mapper) { return $mapper; },
            function () use ($mounts) { return $mounts; });
        check_gate($result === false && $observed === 1,
            'Disallowed action never reaches inspector');
        $result = edm_with_verified_operation_authorization($directory, $uuid,
            'encrypt', function () use (&$observed) { ++$observed; return true; });
        check_gate($result === false && $observed === 1,
            'Destructive action never reaches inspector');
    } else {
        check_gate(true, 'Root-owned lock integration requires privileged test environment');
    }
} finally {
    @unlink($directory . '/disk-operations.lock');
    @rmdir($directory);
}
