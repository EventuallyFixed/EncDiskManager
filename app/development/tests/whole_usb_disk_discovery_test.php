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
