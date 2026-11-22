<?php
require_once __DIR__ . '/../../lib/whole_disk_check_arguments.php';

function check($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}
$disk = array(
    'name' => 'sdd',
    'dev_id' => '8:48',
    'sysfs_path' => '/sys/devices/platform/usb3/3-1/block/sdd',
    'size_sectors' => '30031250',
    'serial' => 'TEST-USB',
    'partitions' => array('sdd1'),
    'partition_dev_ids' => array('sdd1' => '8:49')
);
check(edm_whole_disk_check_arguments($disk, $disk) === array(
    'check', '/dev/sdd', '8:48', $disk['sysfs_path'], '30031250', 'sdd1=8:49'
), 'Matching identity produces exact helper arguments');

foreach (array('name', 'dev_id', 'sysfs_path', 'size_sectors',
    'serial', 'partitions', 'partition_dev_ids') as $field) {
    $current = $disk;
    $current[$field] = $field === 'partitions' ? array('sdd2')
        : ($field === 'partition_dev_ids' ? array('sdd1' => '8:50') : 'changed');
    check(edm_whole_disk_check_arguments($disk, $current) === false,
        "Changed $field is rejected");
}
$invalid = $disk;
$invalid['partition_dev_ids']['sdd1'] = 'bad';
check(edm_whole_disk_check_arguments($invalid, $invalid) === false,
    'Malformed partition ID is rejected');
$invalid = $disk;
$invalid['sysfs_path'] = '/tmp/fake';
check(edm_whole_disk_check_arguments($invalid, $invalid) === false,
    'Non-sysfs path is rejected');
$empty = $disk;
$empty['partitions'] = array();
$empty['partition_dev_ids'] = array();
$args = edm_whole_disk_check_arguments($empty, $empty);
check(is_array($args) && $args[5] === '', 'Disk without partitions uses empty snapshot');
echo "PASS: read-only helper arguments require matching, valid disk identity\n";
