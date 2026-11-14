<?php
require_once __DIR__ . '/../../lib/whole_usb_disk_stable_candidate.php';
function check_stable($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
$disk = array(
    'name' => 'sdb', 'dev_id' => '8:16',
    'sysfs_path' => '/sys/devices/pci0000:00/usb1/1-1/block/sdb',
    'size_bytes' => 1000000, 'serial' => 'USB-123',
    'partitions' => array('sdb1'), 'partition_dev_ids' => array('sdb1' => '8:17'),
    'mounted' => false, 'has_holders' => false, 'has_slaves' => false,
    'read_only' => false
);
$first = array('complete' => true, 'disks' => array($disk));
check_stable(edm_whole_usb_disk_stable_candidate($first, $first, 'sdb'),
    'Stable unmounted USB disk accepted as preliminary candidate');
foreach (array(
    array('dev_id', '8:32'),
    array('sysfs_path', '/sys/devices/pci0000:00/usb2/2-1/block/sdb'),
    array('size_bytes', 2000000),
    array('serial', 'USB-456'),
    array('partition_dev_ids', array('sdb1' => '8:18')),
    array('mounted', true),
    array('has_holders', true),
    array('has_slaves', true),
    array('read_only', true)
) as $change) {
    $changed = $first;
    $changed['disks'][0][$change[0]] = $change[1];
    check_stable(!edm_whole_usb_disk_stable_candidate($first, $changed, 'sdb'),
        'Changed identity or safety state rejected: ' . $change[0]);
}
$incomplete = $first;
$incomplete['complete'] = false;
check_stable(!edm_whole_usb_disk_stable_candidate($first, $incomplete, 'sdb'),
    'Incomplete second discovery rejected');
check_stable(!edm_whole_usb_disk_stable_candidate($first, $first, 'sdc'),
    'Different disk name rejected');
