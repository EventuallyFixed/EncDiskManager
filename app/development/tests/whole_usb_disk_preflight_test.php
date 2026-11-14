<?php
require_once __DIR__ . '/../../lib/whole_usb_disk_preflight.php';
$disk = array(
    'name' => 'sdb', 'dev_id' => '8:16',
    'sysfs_path' => '/sys/devices/usb1/block/sdb',
    'partitions' => array('sdb1'),
    'partition_dev_ids' => array('sdb1' => '8:17'),
    'size_sectors' => '1953125000',
    'mounted' => false, 'has_holders' => false,
    'has_slaves' => false, 'read_only' => false
);
$inventory = array('complete' => true, 'disks' => array($disk));
function check_preflight($value, $label) {
    if (!$value) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
check_preflight(edm_whole_usb_disk_preflight($inventory, 'sdb'),
    'Idle disk passes preliminary read-only preflight');
foreach (array('mounted', 'has_holders', 'has_slaves', 'read_only') as $field) {
    $blocked = $inventory;
    $blocked['disks'][0][$field] = true;
    check_preflight(!edm_whole_usb_disk_preflight($blocked, 'sdb'),
        'Preflight rejects ' . $field);
}
$blocked = $inventory;
$blocked['complete'] = false;
check_preflight(!edm_whole_usb_disk_preflight($blocked, 'sdb'),
    'Incomplete inventory rejected');
$blocked = $inventory;
unset($blocked['disks'][0]['partition_dev_ids']);
check_preflight(!edm_whole_usb_disk_preflight($blocked, 'sdb'),
    'Missing partition identity rejected');
$blocked = $inventory;
$blocked['disks'][] = $disk;
check_preflight(!edm_whole_usb_disk_preflight($blocked, 'sdb'),
    'Duplicate disk name rejected');

foreach (array('0', '00', '01', '', '-1', '1.5') as $badSize) {
    $blocked = $inventory;
    $blocked['disks'][0]['size_sectors'] = $badSize;
    check_preflight(!edm_whole_usb_disk_preflight($blocked, 'sdb'),
        'Invalid sector count rejected: ' . var_export($badSize, true));
}
