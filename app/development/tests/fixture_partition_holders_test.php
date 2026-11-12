<?php
require_once __DIR__ . '/../../lib/fixture_partition_holders.php';
function assert_fixture_combined($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$root = sys_get_temp_dir() . '/edm-fixture-combined-' . bin2hex(random_bytes(8));
mkdir($root . '/class/block', 0700, true);
mkdir($root . '/devices/usb1/sdb/sdb1/holders', 0700, true);
mkdir($root . '/devices/usb1/sdb/holders', 0700, true);
symlink($root . '/devices/usb1/sdb', $root . '/class/block/sdb');
symlink($root . '/devices/usb1/sdb/sdb1', $root . '/class/block/sdb1');
file_put_contents($root . '/devices/usb1/sdb/sdb1/partition', "1\n");
try {
    assert_fixture_combined(edm_fixture_partition_holders_clear($root, 'sdb'),
        'Complete synthetic partition inventory and empty holders');
    file_put_contents($root . '/devices/usb1/sdb/sdb1/holders/dm-0', '');
    assert_fixture_combined(!edm_fixture_partition_holders_clear($root, 'sdb'),
        'Reject partition with holder');
    unlink($root . '/devices/usb1/sdb/sdb1/holders/dm-0');
    rmdir($root . '/devices/usb1/sdb/sdb1/holders');
    assert_fixture_combined(!edm_fixture_partition_holders_clear($root, 'sdb'),
        'Reject missing partition holder directory');
    mkdir($root . '/devices/usb1/sdb/sdb1/holders');
    unlink($root . '/devices/usb1/sdb/sdb1/partition');
    assert_fixture_combined(!edm_fixture_partition_holders_clear($root, 'sdb'),
        'Reject incomplete partition metadata even with empty holders');
} finally {
    @unlink($root . '/class/block/sdb');
    @unlink($root . '/class/block/sdb1');
    @unlink($root . '/devices/usb1/sdb/sdb1/partition');
    @rmdir($root . '/devices/usb1/sdb/sdb1/holders');
    @rmdir($root . '/devices/usb1/sdb/holders');
    @rmdir($root . '/devices/usb1/sdb/sdb1');
    @rmdir($root . '/devices/usb1/sdb');
    @rmdir($root . '/devices/usb1');
    @rmdir($root . '/devices');
    @rmdir($root . '/class/block');
    @rmdir($root . '/class');
    @rmdir($root);
}
