<?php
require_once __DIR__ . '/../../lib/whole_disk_preview_selection.php';
function check_selection($actual, $expected, $label) {
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
$disk = array(
    'name' => 'sdb', 'dev_id' => '8:16',
    'sysfs_path' => '/sys/devices/usb1/1-1/block/sdb',
    'size_sectors' => '1000000', 'serial' => 'USB-123',
    'partitions' => array('sdb1'), 'partition_dev_ids' => array('sdb1' => '8:17'),
    'mounted' => false, 'has_holders' => false, 'has_slaves' => false,
    'read_only' => false
);
$first = array('complete' => true, 'disks' => array($disk));
check_selection(edm_whole_disk_preview_selection(array('disk' => '/dev/sdb'), $first),
    true, 'Selected USB disk passes read-only preview');
foreach (array('/dev/sda', '/dev/sdb1', '/dev/../sdb', '/dev/sdb;id', 'sdb', '', array('/dev/sdb')) as $bad) {
    check_selection(edm_whole_disk_preview_selection(array('disk' => $bad), $first),
        false, 'Invalid or absent disk rejected');
}
check_selection(edm_whole_disk_preview_selection(array(), $first),
    false, 'Missing disk rejected');
$changed = $first;
unset($changed['disks'][0]['dev_id']);
check_selection(edm_whole_disk_preview_selection(array('disk' => '/dev/sdb'), $changed),
    false, 'Invalid disk identity rejected');
$mounted = $first;
$mounted['disks'][0]['mounted'] = true;
check_selection(edm_whole_disk_preview_selection(array('disk' => '/dev/sdb'), $mounted),
    false, 'Mounted disk rejected');
$incomplete = $first;
$incomplete['complete'] = false;
check_selection(edm_whole_disk_preview_selection(array('disk' => '/dev/sdb'), $incomplete),
    false, 'Incomplete discovery rejected');
