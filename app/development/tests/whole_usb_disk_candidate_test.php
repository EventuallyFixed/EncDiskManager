<?php
require_once __DIR__ . '/../../lib/whole_usb_disk_candidate.php';
function check_whole($condition, $label) {
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$disk = array(
    'name' => 'sdb', 'dev_id' => '8:16',
    'sysfs_path' => '/sys/devices/pci/usb1/1-1/block/sdb',
    'serial' => 'fixture-unique-001', 'transport' => 'usb',
    'size_bytes' => 16000000000, 'whole_disk' => true,
    'system_dependency' => false, 'busy' => false, 'mounted' => false,
    'has_holders' => false, 'topology_complete' => true,
    'signatures_complete' => true, 'read_only' => false
);
$snapshot = array('complete' => true, 'disks' => array($disk));
$bound = edm_whole_usb_disk_candidate($snapshot, 'sdb');
check_whole($bound === $disk, 'Synthetic USB whole disk passes');
check_whole(edm_whole_usb_disk_still_matches($bound, $snapshot), 'Matching fresh snapshot passes');
foreach (array(
    'whole_disk' => false, 'transport' => 'sata', 'system_dependency' => true,
    'busy' => true, 'mounted' => true, 'has_holders' => true,
    'topology_complete' => false, 'signatures_complete' => false,
    'read_only' => true, 'serial' => ''
) as $field => $value) {
    $bad = $disk;
    $bad[$field] = $value;
    check_whole(edm_whole_usb_disk_candidate(array('complete' => true,
        'disks' => array($bad)), 'sdb') === null, 'Reject unsafe ' . $field);
}
foreach (array('name' => 'sdc', 'dev_id' => '8:32',
    'sysfs_path' => '/sys/devices/pci/usb2/2-1/block/sdb',
    'serial' => 'different-serial', 'size_bytes' => 32000000000) as $field => $value) {
    $changed = $disk;
    $changed[$field] = $value;
    check_whole(!edm_whole_usb_disk_still_matches($bound,
        array('complete' => true, 'disks' => array($changed))),
        'Reject changed identity ' . $field);
}
check_whole(edm_whole_usb_disk_candidate(array('complete' => false,
    'disks' => array($disk)), 'sdb') === null, 'Incomplete snapshot rejected');
check_whole(edm_whole_usb_disk_candidate(array('complete' => true,
    'disks' => array($disk, $disk)), 'sdb') === null, 'Duplicate disk rejected');
$other = $disk;
$other['name'] = 'sdc';
$other['dev_id'] = '8:32';
check_whole(edm_whole_usb_disk_candidate(array('complete' => true,
    'disks' => array($disk, $other)), 'sdb') === null, 'Duplicate serial rejected');
check_whole(edm_whole_usb_disk_candidate($snapshot, 'sdc') === null, 'Unknown disk rejected');

foreach (array(
    array(), array('complete' => true), array('complete' => true, 'disks' => 'invalid'),
    array('complete' => 1, 'disks' => array($disk)),
    array('complete' => true, 'disks' => array('malformed'))
) as $malformed) {
    check_whole(edm_whole_usb_disk_candidate($malformed, 'sdb') === null,
        'Malformed snapshot rejected');
}
foreach (array(
    'name' => '../sdb', 'dev_id' => 'not-a-device',
    'sysfs_path' => 'relative/sysfs', 'serial' => '',
    'size_bytes' => 0, 'whole_disk' => 'yes',
    'system_dependency' => 0, 'busy' => null,
    'topology_complete' => 1, 'signatures_complete' => null
) as $field => $value) {
    $malformedDisk = $disk;
    $malformedDisk[$field] = $value;
    check_whole(edm_whole_usb_disk_candidate(array('complete' => true,
        'disks' => array($malformedDisk)), 'sdb') === null,
        'Malformed field rejected: ' . $field);
}
$duplicateName = $disk;
$duplicateName['dev_id'] = '8:32';
$duplicateName['serial'] = 'fixture-unique-002';
check_whole(edm_whole_usb_disk_candidate(array('complete' => true,
    'disks' => array($disk, $duplicateName)), 'sdb') === null,
    'Duplicate kernel name rejected');
$duplicateDevId = $disk;
$duplicateDevId['name'] = 'sdc';
$duplicateDevId['serial'] = 'fixture-unique-002';
check_whole(edm_whole_usb_disk_candidate(array('complete' => true,
    'disks' => array($disk, $duplicateDevId)), 'sdb') === null,
    'Duplicate major:minor rejected');

$disconnected = array('complete' => true, 'disks' => array());
check_whole(!edm_whole_usb_disk_still_matches($bound, $disconnected),
    'Unplugged USB disk invalidates bound identity');
$reconnected = $disk;
$reconnected['name'] = 'sdc';
$reconnected['dev_id'] = '8:32';
$reconnected['sysfs_path'] = '/sys/devices/pci/usb2/2-1/block/sdc';
check_whole(!edm_whole_usb_disk_still_matches($bound,
    array('complete' => true, 'disks' => array($reconnected))),
    'Reconnected disk with changed kernel identity rejected');
$replacement = $disk;
$replacement['serial'] = 'fixture-replacement';
check_whole(!edm_whole_usb_disk_still_matches($bound,
    array('complete' => true, 'disks' => array($replacement))),
    'Replacement disk reusing kernel name and device number rejected');
$changedSafety = $disk;
$changedSafety['mounted'] = true;
check_whole(!edm_whole_usb_disk_still_matches($bound,
    array('complete' => true, 'disks' => array($changedSafety))),
    'Newly mounted disk invalidates previous eligibility');
$changedSafety = $disk;
$changedSafety['has_holders'] = true;
check_whole(!edm_whole_usb_disk_still_matches($bound,
    array('complete' => true, 'disks' => array($changedSafety))),
    'New holder invalidates previous eligibility');
