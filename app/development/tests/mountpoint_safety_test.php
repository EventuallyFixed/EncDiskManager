<?php
require_once __DIR__ . '/../../lib/mountpoint_safety.php';
function check_mount_safety($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$mounts = array('20 1 253:2 / /mnt/Backup rw - ext4 /dev/mapper/edm_backup rw');
check_mount_safety(!edm_mountpoint_safety('/etc', $mounts, '253:2', true),
    'Mount outside /mnt rejected');
check_mount_safety(!edm_mountpoint_safety('/mnt/../etc', $mounts, '253:2', true),
    'Mount traversal rejected');
check_mount_safety(!edm_mountpoint_safety('/mnt/Backup', $mounts, '253:3', true),
    'Unexpected kernel mapper ID rejected');
check_mount_safety(!edm_mountpoint_safety('/mnt/Backup', array('malformed'),
    '253:2', true), 'Malformed mountinfo rejected');
check_mount_safety(!edm_mountpoint_safety('/mnt/Backup',
    array_merge($mounts, $mounts), '253:2', true),
    'Duplicate mountpoints rejected');
check_mount_safety(!edm_mountpoint_safety('/mnt/Backup', $mounts,
    '253:2', false), 'Already mounted destination rejected');
check_mount_safety(!edm_mountpoint_safety('/mnt/Backup',
    array('20 1 253:2 / /mnt/Backup rw - xfs /dev/mapper/alias rw'),
    '253:2', true), 'Unexpected filesystem rejected');
check_mount_safety(!edm_mountpoint_safety('/mnt/NoSuchEDMDirectory',
    array(), '253:2', false), 'Missing mount directory rejected');
