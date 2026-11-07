<?php
require_once __DIR__ . '/../../lib/verified_luks_command_plan.php';
function check_luks_gate($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$usb = array('complete' => true, 'devices' => array(array(
    'name' => 'sdb1', 'dev_id' => '8:17', 'uuid' => $uuid,
    'luks' => true, 'mount' => '', 'backup_mapper' => '', 'backup_mount' => ''
)));
$closed = array('complete' => true, 'mappers' => array());
$open = array('complete' => true, 'mappers' => array(array(
    'name' => 'edm_backup', 'device' => 'dm-2', 'dev_id' => '253:2',
    'source_uuid' => $uuid, 'parents' => array('sdb1')
)));
$mounts = array('20 1 8:17 / /other rw - ext4 /dev/other rw');
$dir = sys_get_temp_dir() . '/edm-luks-plan-' . getmypid();
mkdir($dir, 0700);
try {
    check_luks_gate(edm_with_verified_luks_command_plan($dir, $uuid, 'encrypt',
        function () { return true; }) === false, 'Encryption cannot enter command gate');
    if (posix_geteuid() === 0) {
        $calls = 0;
        $unlock = edm_with_verified_luks_command_plan($dir, $uuid, 'unlock',
            function ($plan, $state) use (&$calls) {
                ++$calls;
                return $plan['argv'][1] === 'luksOpen'
                    && $plan['target_dev_id'] === $state['dev_id'];
            }, 'edm_backup', function () use ($usb) { return $usb; },
            function () use ($closed) { return $closed; },
            function () use ($mounts) { return $mounts; });
        check_luks_gate($unlock === true && $calls === 1,
            'Authorized locked target produces an Unlock plan');
        $denied = edm_with_verified_luks_command_plan($dir, $uuid, 'lock',
            function () use (&$calls) { ++$calls; return true; }, null,
            function () use ($usb) { return $usb; },
            function () use ($closed) { return $closed; },
            function () use ($mounts) { return $mounts; });
        check_luks_gate($denied === false && $calls === 1,
            'Lock on closed mapper never produces a plan');
        $lock = edm_with_verified_luks_command_plan($dir, $uuid, 'lock',
            function ($plan) use (&$calls) {
                ++$calls;
                return $plan['argv'][1] === 'luksClose'
                    && $plan['mapper_dev_id'] === '253:2';
            }, null, function () use ($usb) { return $usb; },
            function () use ($open) { return $open; },
            function () use ($mounts) { return $mounts; });
        check_luks_gate($lock === true && $calls === 2,
            'Authorized open unmounted mapper produces a Lock plan');
    } else {
        check_luks_gate(true, 'Root-owned lock integration skipped without root');
    }
} finally {
    @unlink($dir . '/disk-operations.lock');
    @rmdir($dir);
}
