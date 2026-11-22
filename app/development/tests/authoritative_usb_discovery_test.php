<?php
require_once __DIR__ . '/../../lib/authoritative_usb_discovery.php';
function check_auth_usb($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$root = sys_get_temp_dir() . '/edm-usb-discovery-' . getmypid();
$sys = $root . '/block';
$usb = $root . '/usb1/device';
mkdir($usb, 0700, true);
mkdir($sys, 0700, true);
mkdir($usb . '/sdb1', 0700);
file_put_contents($usb . '/sdb1/partition', '1');
file_put_contents($usb . '/sdb1/dev', '240:99');
symlink($usb . '/sdb1', $sys . '/sdb1');
$uuid = '12345678-1234-1234-1234-123456789abc';
try {
    $snapshot = edm_authoritative_usb_discovery($sys, function ($name) use ($uuid) {
        return array('uuid' => $uuid, 'type' => 'crypto_LUKS');
    });
    check_auth_usb($snapshot['complete'] === true && count($snapshot['devices']) === 1,
        'USB partition identified from sysfs');
    check_auth_usb($snapshot['devices'][0]['uuid'] === $uuid
        && $snapshot['devices'][0]['luks'] === true, 'LUKS identity retained');
    unlink($usb . '/sdb1/dev');
    $noDevId = edm_authoritative_usb_discovery($sys, function () use ($uuid) {
        return array('uuid' => $uuid, 'type' => 'crypto_LUKS');
    });
    check_auth_usb($noDevId['complete'] === false,
        'Missing kernel major-minor identity fails closed');
    file_put_contents($usb . '/sdb1/dev', '240:99');
    $failure = edm_authoritative_usb_discovery($sys, function () { return null; });
    check_auth_usb($failure['complete'] === false && !$failure['devices'],
        'Unresolved blkid identity fails closed');
    $invalid = edm_authoritative_usb_discovery($sys, function () {
        return array('uuid' => 'not-a-luks-uuid', 'type' => 'crypto_LUKS');
    });
    check_auth_usb($invalid['complete'] === false, 'Invalid LUKS UUID fails closed');
    $renumbered = edm_authoritative_usb_discovery($sys, function () use ($usb, $uuid) {
        file_put_contents($usb . '/sdb1/dev', '240:100');
        return array('uuid' => $uuid, 'type' => 'crypto_LUKS');
    });
    check_auth_usb($renumbered['complete'] === false,
        'Kernel device number changing during probe fails closed');
    file_put_contents($usb . '/sdb1/dev', '240:99');
    $changed = edm_authoritative_usb_discovery($sys, function () use ($sys) {
        unlink($sys . '/sdb1');
        return array('uuid' => '12345678-1234-1234-1234-123456789abc',
            'type' => 'crypto_LUKS');
    });
    check_auth_usb($changed['complete'] === false,
        'Partition disappearing during identity probe fails closed');
    symlink($usb . '/sdb1', $sys . '/sdb1');
    check_auth_usb(edm_authoritative_usb_discovery($root . '/missing')['complete'] === false,
        'Missing sysfs fails closed');
} finally {
    unlink($sys . '/sdb1');
    unlink($usb . '/sdb1/partition');
    unlink($usb . '/sdb1/dev');
    rmdir($usb . '/sdb1');
    rmdir($usb);
    rmdir($root . '/usb1');
    rmdir($sys);
    rmdir($root);
}
