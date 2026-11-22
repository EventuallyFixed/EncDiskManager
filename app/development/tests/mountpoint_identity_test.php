<?php
require_once __DIR__ . '/../../lib/mountpoint_identity.php';
function check_mountpoint_identity($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
check_mountpoint_identity(edm_mountpoint_identity('/etc') === null,
    'Outside /mnt rejected');
check_mountpoint_identity(edm_mountpoint_identity('/mnt/../etc') === null,
    'Traversal rejected');
check_mountpoint_identity(edm_mountpoint_identity('/mnt/EDM_NONEXISTENT_TEST') === null,
    'Missing mountpoint rejected');
check_mountpoint_identity(!edm_mountpoint_identity_matches('/mnt/EDM_NONEXISTENT_TEST',
    array('leaf_ino' => 1)), 'Forged inode rejected');
