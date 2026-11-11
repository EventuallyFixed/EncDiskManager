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
