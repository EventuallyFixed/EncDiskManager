<?php
require_once __DIR__ . '/../../lib/fixture_partition_tree.php';
function assert_partition($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$root = sys_get_temp_dir() . '/edm-fixture-partitions-' . bin2hex(random_bytes(8));
mkdir($root . '/class/block', 0700, true);
mkdir($root . '/devices/usb1/sdb/sdb1', 0700, true);
mkdir($root . '/devices/usb1/sdb/sdb2', 0700, true);
symlink($root . '/devices/usb1/sdb', $root . '/class/block/sdb');
symlink($root . '/devices/usb1/sdb/sdb1', $root . '/class/block/sdb1');
symlink($root . '/devices/usb1/sdb/sdb2', $root . '/class/block/sdb2');
file_put_contents($root . '/devices/usb1/sdb/sdb1/partition', "1\n");
file_put_contents($root . '/devices/usb1/sdb/sdb2/partition', "2\n");
try {
    $tree = edm_fixture_partition_tree($root, 'sdb');
    assert_partition($tree['complete'] === true
        && $tree['nodes']['sdb']['children'] === array('sdb1', 'sdb2'),
        'Discover two synthetic disk partitions');
    unlink($root . '/devices/usb1/sdb/sdb2/partition');
    assert_partition(edm_fixture_partition_tree($root, 'sdb')['complete'] === false,
        'Reject missing partition marker beneath selected disk');
    file_put_contents($root . '/devices/usb1/sdb/sdb2/partition', "bad\n");
    assert_partition(edm_fixture_partition_tree($root, 'sdb')['complete'] === false,
        'Reject malformed partition metadata');
    unlink($root . '/class/block/sdb1');
    symlink($root . '/devices/usb1/sdb/absent', $root . '/class/block/sdb1');
    assert_partition(edm_fixture_partition_tree($root, 'sdb')['complete'] === false,
        'Reject unresolved block-device link');
    assert_partition(edm_fixture_partition_tree($root, '../sdb')['complete'] === false,
        'Reject unsafe disk name');
    // Restore the valid fixture before testing ambiguous relationships.
    unlink($root . '/class/block/sdb1');
    symlink($root . '/devices/usb1/sdb/sdb1', $root . '/class/block/sdb1');
    file_put_contents($root . '/devices/usb1/sdb/sdb2/partition', "1\n");
    assert_partition(edm_fixture_partition_tree($root, 'sdb')['complete'] === false,
        'Reject duplicate partition numbers');
    file_put_contents($root . '/devices/usb1/sdb/sdb2/partition', "2\n");
    symlink($root . '/devices/usb1/sdb/sdb1', $root . '/class/block/alias1');
    assert_partition(edm_fixture_partition_tree($root, 'sdb')['complete'] === false,
        'Reject duplicate canonical block-device paths');
    unlink($root . '/class/block/alias1');
    mkdir($root . '/devices/usb1/sdb/sdb1/nested', 0700);
    symlink($root . '/devices/usb1/sdb/sdb1/nested', $root . '/class/block/nested');
    assert_partition(edm_fixture_partition_tree($root, 'sdb')['complete'] === false,
        'Reject unexpected nested block-device relationship');
    unlink($root . '/class/block/nested');
    rmdir($root . '/devices/usb1/sdb/sdb1/nested');
    file_put_contents($root . '/devices/usb1/sdb/sdb2/partition', "9999999999999999999999999999999999\n");
    assert_partition(edm_fixture_partition_tree($root, 'sdb')['complete'] === false,
        'Reject overflowing partition number');
} finally {
    foreach (array('sdb','sdb1','sdb2') as $name) @unlink($root . '/class/block/' . $name);
    foreach (array('sdb1','sdb2') as $name) @unlink($root . '/devices/usb1/sdb/' . $name . '/partition');
    @rmdir($root . '/devices/usb1/sdb/sdb1');
    @rmdir($root . '/devices/usb1/sdb/sdb2');
    @rmdir($root . '/devices/usb1/sdb');
    @rmdir($root . '/devices/usb1');
    @rmdir($root . '/devices');
    @rmdir($root . '/class/block');
    @rmdir($root . '/class');
    @rmdir($root);
}
