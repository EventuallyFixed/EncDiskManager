<?php
require_once __DIR__ . '/../../lib/whole_usb_disk_fixture_discovery.php';
require_once __DIR__ . '/../../lib/whole_usb_disk_candidate.php';
function fixture_check($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
$root = sys_get_temp_dir() . '/edm-usb-fixture-' . bin2hex(random_bytes(8));
$disk = $root . '/devices/pci/usb1/1-1/block/sdb';
$partition = $disk . '/sdb1';
try {
    foreach (array($root . '/class/block', $disk . '/device', $partition) as $dir) {
        if (!mkdir($dir, 0700, true)) throw new RuntimeException('Fixture mkdir failed');
    }
    file_put_contents($disk . '/dev', "8:16\n");
    file_put_contents($disk . '/size', "32768\n");
    file_put_contents($disk . '/device/serial', "fixture-serial\n");
    file_put_contents($partition . '/partition', "1\n");
    symlink($disk, $root . '/class/block/sdb');
    symlink($partition, $root . '/class/block/sdb1');
    $snapshot = edm_whole_usb_disk_fixture_snapshot($root);
    fixture_check($snapshot['complete'] === true && count($snapshot['disks']) === 1,
        'Whole USB disk included, partition excluded');
    fixture_check($snapshot['disks'][0]['name'] === 'sdb'
        && $snapshot['disks'][0]['size_bytes'] === 16777216,
        'Fixture sysfs identity and size parsed');
    fixture_check(edm_whole_usb_disk_candidate($snapshot, 'sdb') === null,
        'Unverified fixture disk is never eligible');
    // A non-USB internal disk must not be presented as an encryption target.
    $internal = $root . '/devices/pci/ata1/block/sda';
    if (!mkdir($internal . '/device', 0700, true)) throw new RuntimeException('Internal fixture mkdir failed');
    file_put_contents($internal . '/dev', "8:0\n");
    file_put_contents($internal . '/size', "65536\n");
    file_put_contents($internal . '/device/serial', "internal-serial\n");
    symlink($internal, $root . '/class/block/sda');
    $withInternal = edm_whole_usb_disk_fixture_snapshot($root);
    fixture_check($withInternal['complete'] === true && count($withInternal['disks']) === 1,
        'Internal disk excluded from USB candidates');
    // An additional USB disk must be represented separately.
    $second = $root . '/devices/pci/usb2/2-1/block/sdc';
    if (!mkdir($second . '/device', 0700, true)) throw new RuntimeException('Second USB fixture mkdir failed');
    file_put_contents($second . '/dev', "8:32\n");
    file_put_contents($second . '/size', "131072\n");
    file_put_contents($second . '/device/serial', "fixture-second-serial\n");
    symlink($second, $root . '/class/block/sdc');
    $multiple = edm_whole_usb_disk_fixture_snapshot($root);
    fixture_check($multiple['complete'] === true && count($multiple['disks']) === 2,
        'Multiple USB disks enumerated separately');
    file_put_contents($second . '/dev', "invalid\n");
    fixture_check(edm_whole_usb_disk_fixture_snapshot($root)['complete'] === false,
        'Malformed second USB disk invalidates entire snapshot');
    unlink($root . '/class/block/sdc');
    unlink($second . '/dev');
    unlink($second . '/size');
    unlink($second . '/device/serial');
    rmdir($second . '/device');
    rmdir($second);
    rmdir(dirname($second));
    rmdir(dirname(dirname($second)));
    unlink($root . '/class/block/sda');
    unlink($internal . '/dev');
    unlink($internal . '/size');
    unlink($internal . '/device/serial');
    rmdir($internal . '/device');
    rmdir($internal);
    rmdir(dirname($internal));
    rmdir(dirname(dirname($internal)));
    unlink($disk . '/device/serial');
    fixture_check(edm_whole_usb_disk_fixture_snapshot($root)['complete'] === false,
        'Missing required metadata fails closed');
} finally {
    foreach (array($root . '/class/block/sdb1', $root . '/class/block/sdb',
        $partition . '/partition', $disk . '/dev', $disk . '/size',
        $disk . '/device/serial') as $file) {
        if (is_link($file) || is_file($file)) unlink($file);
    }
    foreach (array($partition, $disk . '/device', $disk, dirname($disk),
        dirname(dirname($disk)), dirname(dirname(dirname($disk))),
        $root . '/class/block', $root . '/class', $root . '/devices', $root) as $dir) {
        if (is_dir($dir)) @rmdir($dir);
    }
}
