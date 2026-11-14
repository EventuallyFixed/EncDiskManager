<?php
require_once __DIR__ . '/../../lib/whole_disk_preview_identity.php';

function verify_preview_identity($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}
$disk = array(
    'name' => 'sdd', 'dev_id' => '8:48',
    'sysfs_path' => '/sys/devices/usb3/3-1/block/sdd',
    'size_sectors' => '30031250', 'serial' => 'TEST-USB',
    'partitions' => array('sdd1'),
    'partition_dev_ids' => array('sdd1' => '8:49'),
    'mounted' => false, 'has_holders' => false, 'has_slaves' => false,
    'read_only' => false
);
$inventory = array('complete' => true, 'disks' => array($disk));
verify_preview_identity(edm_whole_disk_preview_identity_matches($disk, $inventory),
    'Exact confirmed disk accepted for read-only preview');
foreach (array('name', 'dev_id', 'sysfs_path', 'size_sectors',
    'serial', 'partitions', 'partition_dev_ids') as $field) {
    $changed = $inventory;
    $changed['disks'][0][$field] = $field === 'partitions' ? array('sdd2')
        : ($field === 'partition_dev_ids' ? array('sdd1' => '8:50') : 'changed');
    verify_preview_identity(!edm_whole_disk_preview_identity_matches($disk, $changed),
        "Changed $field rejected");
}
$busy = $inventory;
$busy['disks'][0]['mounted'] = true;
verify_preview_identity(!edm_whole_disk_preview_identity_matches($disk, $busy),
    'Mounted disk rejected');
$incomplete = $inventory;
$incomplete['complete'] = false;
verify_preview_identity(!edm_whole_disk_preview_identity_matches($disk, $incomplete),
    'Incomplete inventory rejected');
verify_preview_identity(!edm_whole_disk_preview_identity_matches(array(), $inventory),
    'Missing confirmation identity rejected');
echo "PASS: preview binding rejects changed or unsafe disk identity\n";
