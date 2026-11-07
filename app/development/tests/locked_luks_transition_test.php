<?php
require_once __DIR__ . '/../../lib/locked_luks_transition.php';
function check_locked_transition($ok, $label) {
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
$dir = sys_get_temp_dir() . '/edm-locked-transition-' . getmypid();
mkdir($dir, 0700);
try {
    check_locked_transition(!edm_with_locked_luks_transition($dir, $uuid,
        'encrypt', null, function () { return true; }),
        'Destructive action rejected before locking');
    if (posix_geteuid() === 0) {
        $opened = false;
        $calls = 0;
        $discoverMappers = function () use (&$opened, $closed, $open) {
            return $opened ? $open : $closed;
        };
        $result = edm_with_locked_luks_transition($dir, $uuid, 'unlock',
            'edm_backup', function ($plan) use (&$opened, &$calls) {
                ++$calls;
                $opened = true;
                return $plan['argv'][1] === 'luksOpen';
            }, function () use ($usb) { return $usb; }, $discoverMappers,
            function () { return array(); },
            function () { return '/usr/sbin/cryptsetup'; });
        check_locked_transition($result === true && $calls === 1,
            'Locked verified transition probes before and after Unlock');
        $result = edm_with_locked_luks_transition($dir, $uuid, 'unlock',
            'edm_backup', function () use (&$calls) { ++$calls; return true; },
            function () use ($usb) { return $usb; }, $discoverMappers,
            function () { return array(); },
            function () { return '/usr/sbin/cryptsetup'; });
        check_locked_transition($result === false && $calls === 1,
            'Already-open mapper cannot be unlocked again');
        $result = edm_with_locked_luks_transition($dir, $uuid, 'lock', null,
            function ($plan) use (&$opened, &$calls) {
                ++$calls;
                $opened = false;
                return $plan['argv'][1] === 'luksClose';
            }, function () use ($usb) { return $usb; }, $discoverMappers,
            function () { return array(); },
            function () { return '/usr/sbin/cryptsetup'; });
        check_locked_transition($result === true && $calls === 2,
            'Locked verified transition probes before and after Lock');
    } else {
        check_locked_transition(true, 'Root-owned lock integration skipped without root');
    }
} finally {
    @unlink($dir . '/disk-operations.lock');
    @rmdir($dir);
}
