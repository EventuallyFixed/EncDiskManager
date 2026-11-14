<?php
require_once __DIR__ . '/../../lib/whole_usb_disk_discovery.php';

function check_discovery($condition, $label) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\\n");
        exit(1);
    }
    echo "PASS: $label\\n";
}

$base = sys_get_temp_dir() . '/edm-whole-usb-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
mkdir($base . '/block', 0700);
file_put_contents($base . '/mounts', '');
try {
    $result = edm_discover_whole_usb_disks($base . '/block', $base . '/mounts');
    check_discovery($result === array('complete' => true, 'disks' => array()),
        'Empty block inventory is read-only and complete');
    $missing = edm_discover_whole_usb_disks($base . '/missing', $base . '/mounts');
    check_discovery($missing['complete'] === false,
        'Unavailable sysfs inventory fails closed');
    $missing = edm_discover_whole_usb_disks($base . '/block', $base . '/missing');
    check_discovery($missing['complete'] === false,
        'Unavailable mount inventory fails closed');
    // A synthetic sysfs USB parent with one partition and a mount.
    $usb = $base . '/usb1/1-1/block/sdb';
    mkdir($base . '/usb1', 0700);
    mkdir($base . '/usb1/1-1', 0700);
    mkdir($base . '/usb1/1-1/block', 0700);
    file_put_contents($base . '/usb1/1-1/serial', 'TEST-USB-001');
    mkdir($usb, 0700);
    mkdir($usb . '/holders', 0700);
    file_put_contents($usb . '/dev', '8:16');
    file_put_contents($usb . '/size', '2048');
    file_put_contents($usb . '/ro', '0');
    mkdir($usb . '/sdb1', 0700);
    file_put_contents($usb . '/sdb1/partition', '1');
    mkdir($usb . '/sdb1/holders', 0700);
    symlink($usb, $base . '/block/sdb');
    file_put_contents($base . '/mounts', "/dev/sdb1 /mnt/test ext4 rw 0 0\\n");
    $result = edm_discover_whole_usb_disks($base . '/block', $base . '/mounts');
    check_discovery(count($result['disks']) === 1
        && $result['disks'][0]['name'] === 'sdb'
        && $result['disks'][0]['partitions'] === array('sdb1')
        && $result['disks'][0]['serial'] === 'TEST-USB-001'
        && $result['disks'][0]['dev_id'] === '8:16'
        && $result['disks'][0]['size_bytes'] === 1048576
        && $result['disks'][0]['read_only'] === false
        && $result['disks'][0]['mounted'] === true
        && $result['disks'][0]['encryption_eligible'] === false,
        'USB parent discovered but mounted partition cannot be encrypted');
    file_put_contents($base . '/mounts', '');
    file_put_contents($usb . '/sdb1/holders/dm-0', '');
    $result = edm_discover_whole_usb_disks($base . '/block', $base . '/mounts');
    check_discovery($result['disks'][0]['has_holders'] === true
        && $result['disks'][0]['encryption_eligible'] === false,
        'Partition holder is detected without enabling encryption');
    unlink($usb . '/sdb1/holders/dm-0');
    unlink($base . '/block/sdb');
    unlink($usb . '/sdb1/partition');
    rmdir($usb . '/sdb1/holders');
    rmdir($usb . '/sdb1');
    rmdir($usb . '/holders');
    unlink($usb . '/dev');
    unlink($usb . '/size');
    unlink($usb . '/ro');
    rmdir($usb);
    rmdir($base . '/usb1/1-1/block');
    unlink($base . '/usb1/1-1/serial');
    rmdir($base . '/usb1/1-1');
    rmdir($base . '/usb1');
    mkdir($base . '/block/sda', 0700);
    $result = edm_discover_whole_usb_disks($base . '/block', $base . '/mounts');
    check_discovery($result['disks'] === array(),
        'Non-USB device is not offered as a whole USB disk');
} finally {
    @rmdir($base . '/block/sda');
    @unlink($base . '/mounts');
    @rmdir($base . '/block');
    @rmdir($base);
}
