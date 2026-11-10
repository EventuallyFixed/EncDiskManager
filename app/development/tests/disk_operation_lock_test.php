<?php
require_once __DIR__ . '/../../lib/disk_operation_lock.php';
function check_disk_lock($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "SKIP: Root-only disk operation lock tests\n";
    exit(0);
}
$dir = sys_get_temp_dir() . '/edm-disk-lock-' . getmypid();
check_disk_lock(@mkdir($dir, 0700), 'Create private test directory');
try {
    $called = 0;
    $result = edm_with_disk_operation_lock($dir, function () use (&$called) {
        ++$called;
        return 'held';
    });
    check_disk_lock($result === 'held' && $called === 1,
        'Valid root-owned lock executes callback');
    $threw = false;
    try {
        edm_with_disk_operation_lock($dir, function () {
            throw new RuntimeException('Simulated operation failure');
        });
    } catch (RuntimeException $e) {
        $threw = $e->getMessage() === 'Simulated operation failure';
    }
    check_disk_lock($threw, 'Callback failure propagates');
    $afterFailure = edm_with_disk_operation_lock($dir, function () {
        return 'reacquired';
    });
    check_disk_lock($afterFailure === 'reacquired',
        'Shared lock can be reacquired after callback failure');
    $lock = $dir . '/disk-operations.lock';
    check_disk_lock(is_file($lock), 'Lock file created');
    unlink($lock);
    check_disk_lock(symlink('/dev/null', $lock), 'Create test symlink');
    $blocked = false;
    try {
        edm_with_disk_operation_lock($dir, function () use (&$called) { ++$called; });
    } catch (RuntimeException $e) { $blocked = true; }
    check_disk_lock($blocked && $called === 1,
        'Symlinked lock cannot reach callback');
} finally {
    @unlink($dir . '/disk-operations.lock');
    @rmdir($dir);
}
