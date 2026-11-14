<?php
require_once __DIR__ . '/../../lib/whole_disk_preview_selection.php';
require_once __DIR__ . '/../../lib/whole_usb_disk_stable_candidate.php';

function verify_stable_confirmation($condition, $message) {
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
$first = array('complete' => true, 'disks' => array($disk));
$post = array('disk' => '/dev/sdd');
verify_stable_confirmation(
    edm_whole_disk_preview_selection($post, $first)
        && edm_whole_usb_disk_stable_candidate($first, $first, 'sdd'),
    'Unchanged idle USB disk accepted for preview'
);
foreach (array('dev_id', 'sysfs_path', 'size_sectors', 'serial',
    'partitions', 'partition_dev_ids', 'mounted', 'has_holders',
    'has_slaves', 'read_only') as $field) {
    $second = $first;
    if ($field === 'partitions') $second['disks'][0][$field] = array('sdd2');
    elseif ($field === 'partition_dev_ids') $second['disks'][0][$field] = array('sdd1' => '8:50');
    elseif (in_array($field, array('mounted', 'has_holders', 'has_slaves', 'read_only'), true))
        $second['disks'][0][$field] = true;
    else $second['disks'][0][$field] = 'changed';
    verify_stable_confirmation(
        !edm_whole_usb_disk_stable_candidate($first, $second, 'sdd'),
        "Changed $field rejected"
    );
}
$second = $first;
$second['complete'] = false;
verify_stable_confirmation(
    !edm_whole_usb_disk_stable_candidate($first, $second, 'sdd'),
    'Incomplete second snapshot rejected'
);
verify_stable_confirmation(
    !edm_whole_usb_disk_stable_candidate($first, $first, 'sde'),
    'Different selected disk rejected'
);
echo "PASS: stable confirmation preview rejects changed or busy disks\n";
