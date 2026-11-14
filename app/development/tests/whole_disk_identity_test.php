<?php
require_once __DIR__ . '/../../lib/whole_disk_identity.php';
function verify($value, $expected, $message) {
    if ($value !== $expected) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "PASS: $message\n";
}
$disk = array(
    'name' => 'sdb', 'dev_id' => '8:16',
    'sysfs_path' => '/sys/devices/usb1/1-1/block/sdb',
    'size_sectors' => '1000000', 'serial' => 'USB-123',
    'partitions' => array('sdb1')
);
verify(edm_whole_disk_identity_matches($disk, $disk), true, 'Matching identity');
foreach (array('name', 'dev_id', 'sysfs_path', 'size_sectors', 'serial', 'partitions') as $field) {
    $changed = $disk;
    $changed[$field] = $field === 'partitions' ? array('sdb2') : 'changed';
    verify(edm_whole_disk_identity_matches($disk, $changed), false, "Changed $field rejected");
    $missing = $disk;
    unset($missing[$field]);
    verify(edm_whole_disk_identity_matches($disk, $missing), false, "Missing $field rejected");
}
foreach (array('../sdb', 'sdb1', 'nvme0n1', '/dev/sdb') as $bad) {
    $changed = $disk; $changed['name'] = $bad;
    verify(edm_whole_disk_identity_matches($changed, $changed), false, 'Invalid device name rejected');
}
$changed = $disk; $changed['size_sectors'] = '0';
verify(edm_whole_disk_identity_matches($changed, $changed), false, 'Zero capacity rejected');
$changed = $disk; $changed['size_sectors'] = '30031250';
verify(edm_whole_disk_identity_matches($disk, $changed), false, 'Different exact sector count rejected');
verify(edm_whole_disk_identity_matches(null, $disk), false, 'Invalid identity rejected');
