<?php
require_once __DIR__ . '/../../lib/disk_operation_recovery.php';
function check_recovery($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "SKIP: Root-only recovery marker tests\n";
    exit(0);
}
$dir = sys_get_temp_dir() . '/edm-recovery-' . getmypid();
check_recovery(@mkdir($dir, 0700), 'Create private test directory');
$path = $dir . '/disk-operation-recovery-required';
try {
    check_recovery(edm_disk_operation_mark_recovery_required($dir) === true,
        'Create marker');
    $stat = lstat($path);
    check_recovery($stat !== false && $stat['uid'] === 0
        && ($stat['mode'] & 0777) === 0600, 'Marker is private and root owned');
    check_recovery(edm_disk_operation_mark_recovery_required($dir) === true,
        'Existing safe marker remains valid');
    unlink($path);
    symlink('/dev/null', $path);
    $blocked = false;
    try { edm_disk_operation_mark_recovery_required($dir); }
    catch (RuntimeException $e) { $blocked = true; }
    check_recovery($blocked, 'Symlink marker rejected');
} finally {
    @unlink($path);
    @rmdir($dir);
}
