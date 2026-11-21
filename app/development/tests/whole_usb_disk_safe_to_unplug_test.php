<?php
require_once __DIR__ . '/../../lib/whole_usb_disk_stable_candidate.php';

$disk = array(
    'name' => 'sdd', 'dev_id' => '8:48', 'sysfs_path' => '/sys/devices/usb1/block/sdd',
    'serial' => '', 'size_sectors' => '30000000',
    'partitions' => array('sdd1'), 'partition_dev_ids' => array('sdd1' => '8:49'),
    'mounted' => false, 'has_holders' => false, 'has_slaves' => false, 'read_only' => false
);
$snapshot = function ($changes = array()) use ($disk) {
    return array('complete' => true, 'disks' => array(array_merge($disk, $changes)));
};
$idle = $snapshot();
$cases = array(
    array('locked / idle', $idle, $idle, true),
    array('unlocked but unmounted', $idle, $snapshot(array('has_holders' => true)), false),
    array('mounted partition', $idle, $snapshot(array('mounted' => true)), false),
    array('dependent block device', $idle, $snapshot(array('has_slaves' => true)), false),
    array('just transitioned from busy', $snapshot(array('has_holders' => true)), $idle, false),
    array('read-only idle', $snapshot(array('read_only' => true)), $snapshot(array('read_only' => true)), true),
    array('incomplete discovery', $idle, array('complete' => false, 'disks' => array()), false),
    array('disconnected', $idle, array('complete' => true, 'disks' => array()), false),
    array('changed identity', $idle, $snapshot(array('dev_id' => '8:64')), false)
);
foreach ($cases as $case) {
    list($description, $first, $second, $expected) = $case;
    if (edm_whole_usb_disk_safe_to_unplug($first, $second, 'sdd') !== $expected) {
        fwrite(STDERR, "FAIL: " . $description . PHP_EOL);
        exit(1);
    }
    echo "PASS: " . $description . PHP_EOL;
}
