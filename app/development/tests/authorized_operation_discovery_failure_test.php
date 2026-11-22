<?php
/**
 * Simulated discovery failures for the composed non-executing gate.
 * Uses a temporary root-owned registry and never opens any disk.
 */
require_once __DIR__ . '/../../lib/authorized_operation_inspection.php';
function check_failure_gate($condition, $label) {
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "SKIP: Root required for private registry and operation lock fixtures\n";
    exit(0);
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$csrf = str_repeat('a', 64);
$request = array('action' => 'unlock', 'luks_uuid' => $uuid);
$directory = sys_get_temp_dir() . '/edm-discovery-failure-' . getmypid();
check_failure_gate(@mkdir($directory, 0700), 'Create private fixture');
$registry = array('version' => 1, 'disks' => array(array(
    'luks_uuid' => $uuid, 'name' => 'Backup', 'mount_folder' => 'Backup',
    'mapper_name' => edm_registry_mapper_name($uuid), 'enabled' => true
)));
$validUsb = array('complete' => true, 'devices' => array(array(
    'name' => 'sdb1', 'dev_id' => '8:17', 'uuid' => $uuid,
    'luks' => true, 'mount' => '', 'backup_mapper' => '', 'backup_mount' => ''
)));
$validMappers = array('complete' => true, 'mappers' => array());
try {
    edm_registry_save($directory . '/disks.json', $registry);
    $scenarios = array(
        'USB discovery unavailable' => array(null, $validMappers, array()),
        'USB discovery incomplete' => array(array('complete' => false,
            'devices' => $validUsb['devices']), $validMappers, array()),
        'Target disconnected' => array(array('complete' => true,
            'devices' => array()), $validMappers, array()),
        'Mapper discovery unavailable' => array($validUsb, null, array()),
        'Mount discovery unavailable' => array($validUsb, $validMappers, null)
    );
    foreach ($scenarios as $label => $snapshot) {
        $inspected = 0;
        $result = edm_with_authorized_operation_inspection($directory,
            'POST', true, true, $csrf, $csrf, $request,
            function () use (&$inspected) { ++$inspected; return true; },
            function () use ($snapshot) { return $snapshot[0]; },
            function () use ($snapshot) { return $snapshot[1]; },
            function () use ($snapshot) { return $snapshot[2]; });
        check_failure_gate($result === false && $inspected === 0,
            $label . ' fails closed before inspector');
    }
    $mountReads = 0;
    $inspected = 0;
    $result = edm_with_authorized_operation_inspection($directory,
        'POST', true, true, $csrf, $csrf, $request,
        function () use (&$inspected) { ++$inspected; return true; },
        function () use ($validUsb) { return $validUsb; },
        function () use ($validMappers) { return $validMappers; },
        function () use (&$mountReads) {
            ++$mountReads;
            return $mountReads === 1 ? array() : null;
        });
    check_failure_gate($result === false && $inspected === 0
        && $mountReads === 2,
        'Mount discovery fails closed on second verification pass');
    $probeCount = 0;
    $inspected = 0;
    $result = edm_with_authorized_operation_inspection($directory,
        'POST', true, true, $csrf, $csrf, $request,
        function () use (&$inspected) { ++$inspected; return true; },
        function () use (&$probeCount, $validUsb) {
            ++$probeCount;
            if ($probeCount === 2) {
                $changed = $validUsb;
                $changed['devices'][0]['dev_id'] = '8:33';
                return $changed;
            }
            return $validUsb;
        },
        function () use ($validMappers) { return $validMappers; },
        function () { return array(); });
    check_failure_gate($result === false && $inspected === 0
        && $probeCount === 2, 'USB identity changes between probes');
} finally {
    foreach (array('disks.json', 'disks.json.lock', 'disk-operations.lock') as $file)
        @unlink($directory . '/' . $file);
    @rmdir($directory);
}
