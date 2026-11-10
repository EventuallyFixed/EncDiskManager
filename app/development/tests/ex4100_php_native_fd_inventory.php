<?php
/**
 * EX4100 read-only PHP-to-native descriptor inheritance inventory.
 * Uses only a private temporary lock file and /bin/sh. No NAS app data or disks.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('proc_open') || !function_exists('flock')) {
    fwrite(STDERR, "Required PHP functions unavailable\n"); exit(2);
}
$dir = sys_get_temp_dir() . '/edm-native-fd-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create temp directory');
$path = $dir . '/disk-operations.lock';
$lock = null;
try {
    $lock = fopen($path, 'x+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Cannot acquire temporary lock');
    }
    // An independently opened descriptor must be unable to acquire the lock.
    // This tests subprocess contention, not unproven FD inheritance.
    $command = '/bin/sh -c ' . escapeshellarg(
        'if command -v flock >/dev/null 2>&1; then ' .
        'flock -n "$1" -c true; exit $?; ' .
        'else exit 77; fi'
    ) . ' sh ' . escapeshellarg($path);
    $pipes = array();
    $proc = proc_open($command, array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w')
    ), $pipes);
    if (!is_resource($proc)) throw new RuntimeException('Cannot launch shell');
    foreach ($pipes as $pipe) fclose($pipe);
    $status = proc_close($proc);
    if ($status === 77) {
        echo "SKIP: flock command unavailable; PHP contention test already covers locking\n";
    } elseif ($status !== 0) {
        echo "PASS: independent native shell cannot acquire PHP-held lock\n";
    } else {
        throw new RuntimeException('Native shell acquired PHP-held lock unexpectedly');
    }
    echo "PHP: " . PHP_VERSION . "\n";
    echo "NOTE: no descriptor inheritance proven; temporary-file test only\n";
} finally {
    if (is_resource($lock)) fclose($lock);
    @unlink($path);
    @rmdir($dir);
}
