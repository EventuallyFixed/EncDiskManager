<?php
require_once __DIR__ . '/../../lib/mapper_mount_state.php';
function check_mapper_mount($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$mapper = array('dev_id' => '253:2');
$lines = array(
    '20 1 8:17 / /mnt/other rw - ext4 /dev/sdb1 rw',
    '21 1 253:2 / /mnt/usb rw - ext4 /dev/disk/by-uuid/alias rw'
);
check_mapper_mount(edm_mapper_mount_state($mapper, $lines) === true,
    'Mapper mount detected despite aliased source path');
check_mapper_mount(edm_mapper_mount_state($mapper, array($lines[0])) === false,
    'Unrelated mount does not mark mapper mounted');
check_mapper_mount(edm_mapper_mount_state($mapper, array('malformed')) === null,
    'Malformed mountinfo fails closed');
check_mapper_mount(edm_mapper_mount_state(array('dev_id' => 'invalid'), $lines) === null,
    'Invalid mapper identity fails closed');
$path = sys_get_temp_dir() . '/edm-mountinfo-' . getmypid();
try {
    file_put_contents($path, implode("\n", $lines) . "\n");
    check_mapper_mount(edm_read_mapper_mount_state($mapper, $path) === true,
        'Stable mountinfo double-read accepted');
    unlink($path);
    check_mapper_mount(edm_read_mapper_mount_state($mapper, $path) === null,
        'Missing mountinfo fails closed');
} finally { @unlink($path); }
