<?php
require_once __DIR__ . '/../../lib/locked_operation_target.php';
function check_locked_target($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$device = array('name' => 'sdb1', 'dev_id' => '8:17', 'uuid' => $uuid, 'luks' => true);
$snapshot = array('complete' => true, 'devices' => array($device));
$directory = sys_get_temp_dir() . '/edm-locked-target-' . getmypid();
mkdir($directory, 0700);
try {
    // The production lock requires root-owned files. The CI job may run as
    // a non-root user; tests must reject that environment, not weaken checks.
    if (posix_geteuid() === 0) {
        $calls = 0;
        $observed = null;
        $result = edm_with_verified_operation_target($directory, $uuid,
            function ($identity) use (&$observed) {
                $observed = $identity;
                return 'inspected';
            }, function () use (&$calls, $snapshot) {
                ++$calls;
                return $snapshot;
            });
        check_locked_target($result === 'inspected' && $calls === 2
            && $observed['dev_id'] === '8:17',
            'Verified target inspected after two matching probes under lock');

        $calls = 0;
        $inspected = false;
        $replaced = $device;
        $replaced['dev_id'] = '8:33';
        $result = edm_with_verified_operation_target($directory, $uuid,
            function () use (&$inspected) { $inspected = true; return true; },
            function () use (&$calls, $snapshot, $replaced) {
                ++$calls;
                return $calls === 1 ? $snapshot :
                    array('complete' => true, 'devices' => array($replaced));
            });
        check_locked_target($result === false && !$inspected && $calls === 2,
            'Changed kernel identity blocks inspection');

        $calls = 0;
        $result = edm_with_verified_operation_target($directory, $uuid,
            function () use (&$inspected) { $inspected = true; return true; },
            function () use (&$calls, $snapshot) {
                ++$calls;
                return $calls === 1 ? $snapshot :
                    array('complete' => false, 'devices' => array());
            });
        check_locked_target($result === false && !$inspected,
            'Incomplete second discovery blocks inspection');

        $calls = 0;
        $result = edm_with_verified_operation_target($directory, $uuid,
            function () use (&$inspected) { $inspected = true; return true; },
            function () use (&$calls) {
                ++$calls;
                return array('complete' => true, 'devices' => array());
            });
        check_locked_target($result === false && !$inspected && $calls === 1,
            'Disconnected UUID blocks inspection without second probe');
    } else {
        check_locked_target(true, 'Root-only lock integration deferred on non-root CI');
    }
} finally {
    @unlink($directory . '/disk-operations.lock');
    rmdir($directory);
}
