<?php
require_once __DIR__ . '/../../lib/configuration_request.php';
require_once __DIR__ . '/../../lib/configuration_authorization.php';
require_once __DIR__ . '/../../lib/registry.php';

function check($ok, $label) {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: $label\n";
}
function rejects($callback) {
    try { $callback(); } catch (RuntimeException $e) { return true; }
    return false;
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$other = '87654321-4321-4321-4321-cba987654321';
$entry = array('luks_uuid' => $uuid, 'name' => 'Backup', 'mount_folder' => 'backup',
    'mapper_name' => edm_registry_mapper_name($uuid), 'enabled' => true, 'store_unlock_key' => true);
$registry = array('version' => 1, 'disks' => array($entry, array(
    'luks_uuid' => $other, 'name' => 'Archive', 'mount_folder' => 'archive',
    'mapper_name' => edm_registry_mapper_name($other), 'enabled' => true)));
$edit = edm_configuration_request(array('action' => 'edit', 'luks_uuid' => $uuid,
    'name' => 'New backup', 'mount_folder' => 'backup_new'));
$next = edm_configuration_edit_registry($edit, $registry, function () { return true; });
$expected = $entry;
$expected['name'] = 'New backup';
$expected['mount_folder'] = 'backup_new';
check($next['disks'][0] === $expected && $next['disks'][1] === $registry['disks'][1],
    'Only name and folder change; UUID, mapper, key preference and other disk preserved');
check(rejects(function () use ($edit, $registry) {
    edm_configuration_edit_registry($edit, $registry, function () { return false; });
}), 'Mounted or unknown state rejects folder edit');
$rename = array_merge($edit, array('mount_folder' => 'backup'));
check(rejects(function () use ($rename, $registry) {
    edm_configuration_edit_registry($rename, $registry, function () { return false; });
}), 'Name-only edit rejected while mounted');
$next = edm_configuration_edit_registry($rename, $registry, function ($uuid, $folder) {
    return $folder === null;
});
check($next['disks'][0]['name'] === 'New backup' && $next['disks'][0]['mount_folder'] === 'backup',
    'Unmounted name-only edit does not require a new directory');
foreach (array(array('mount_folder' => 'ARCHIVE'), array('name' => 'Archive')) as $duplicate) {
    check(rejects(function () use ($edit, $registry, $duplicate) {
        edm_configuration_edit_registry(array_merge($edit, $duplicate), $registry, function () { return true; });
    }), 'Duplicate registered name or folder rejected');
}
check(rejects(function () use ($edit) {
    edm_configuration_request(array_merge($edit, array('mount_folder' => '../HD')));
}), 'Unsafe folder rejected');
check(rejects(function () use ($edit) {
    edm_configuration_request(array_merge($edit, array('mount_folder' => "backup\n")));
}), 'Trailing newline in mount folder rejected');
$device = array('name' => 'sdb1', 'dev_id' => '8:17', 'uuid' => $uuid, 'luks' => true, 'mount' => '');
$mapper = array('name' => 'edm_test', 'device' => 'dm-0', 'dev_id' => '253:0',
    'source_uuid' => $uuid, 'parents' => array('sdb1'));
function allowed($uuid, $devices, $mappers, $mounts, $complete = true) {
    $whole = array('complete' => $complete, 'disks' => array(array(
        'name' => 'sdb', 'dev_id' => '8:16', 'mounted' => false,
        'partitions' => array('sdb1', 'sdb2'), 'partition_dev_ids' => array('sdb1' => '8:17', 'sdb2' => '8:18'))));
    return edm_configuration_mount_folder_edit_allowed($uuid,
        array('complete' => $complete, 'devices' => $devices),
        array('complete' => $complete, 'mappers' => $mappers), $mounts, $whole);
}
check(allowed($uuid, array(), array(), array()), 'Disconnected edit allowed');
check(allowed($uuid, array($device), array(), array()), 'Locked edit allowed');
check(allowed($uuid, array($device), array($mapper), array()), 'Unlocked unmounted edit allowed');
check(!allowed($uuid, array($device), array($mapper), array('1 0 253:0 / /mnt/backup rw - ext4 /dev/dm-0 rw')),
    'Mounted mapper rejected by kernel major:minor even through alias');
check(!allowed($uuid, array($device), array(), array('1 0 8:17 / /mnt/backup rw - ext4 /dev/sdb1 rw')),
    'Direct partition mount rejected');
check(!allowed($uuid, array(), array($mapper), array()), 'Orphan mapper is not disconnected');
check(!allowed($uuid, array(), array(), array(), false), 'Incomplete discovery rejected');
check(!allowed($uuid, array($device, array_merge($device, array('name' => 'sdc1'))), array(), array()),
    'Duplicate UUID rejected');

$sibling = array('name' => 'sdb2', 'dev_id' => '8:18', 'uuid' => $other, 'luks' => true, 'mount' => '');
$siblingMapper = array('name' => 'edm_sibling', 'device' => 'dm-1', 'dev_id' => '253:1',
    'source_uuid' => $other, 'parents' => array('sdb2'));
check(!allowed($uuid, array($device, $sibling), array(), array('1 0 8:18 / /mnt/other rw - ext4 /dev/sdb2 rw')),
    'Mounted sibling partition blocks configuration');
check(!allowed($uuid, array($device, $sibling), array($siblingMapper), array('1 0 253:1 / /mnt/other rw - ext4 /dev/dm-1 rw')),
    'Mounted encrypted sibling blocks configuration');
check(allowed($uuid, array($device, $sibling), array($siblingMapper), array()),
    'Unlocked but unmounted sibling permits configuration');
$display = array_merge($device, array('mapper_names' => array(), 'mapper_mounts' => array()));
$displaySibling = array_merge($sibling, array('mapper_names' => array('edm_sibling'), 'mapper_mounts' => array('/mnt/other')));
$whole = array('complete' => true, 'disks' => array(array('name' => 'sdb', 'partitions' => array('sdb1','sdb2'), 'mounted' => false)));
check(!edm_configuration_available($uuid, array($display, $displaySibling), $whole), 'UI blocks mounted encrypted sibling');
$displaySibling['mapper_mounts'] = array();
check(edm_configuration_available($uuid, array($display, $displaySibling), $whole), 'UI enables after sibling unmount');
$whole['disks'][0]['mounted'] = true;
check(!edm_configuration_available($uuid, array($display, $displaySibling), $whole), 'UI blocks direct sibling mount');
check(edm_configuration_available($uuid, array(), $whole), 'Disconnected registration remains configurable');
$whole['complete'] = false;
check(!edm_configuration_available($uuid, array($display), $whole), 'UI disables on incomplete discovery');
