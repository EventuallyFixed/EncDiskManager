<?php
/**
 * EX4100 PHP proc_open descriptor-passing inventory.
 * Only a randomly named temporary lock file; no devices or application data.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
$dir = sys_get_temp_dir() . '/edm-fd-pass-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create temporary directory');
$path = $dir . '/disk-operations.lock';
$lock = null;
try {
    $lock = fopen($path, 'x+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Cannot lock temporary file');
    }
    // Descriptor 3 is explicitly mapped to the existing PHP stream.
    // This tests descriptor visibility, not persistence after parent exit.
    $cmd = '/bin/sh -c ' . escapeshellarg(
        'if [ -e /proc/self/fd/3 ]; then echo FD3_PRESENT; else echo FD3_ABSENT; fi'
    );
    $pipes = array();
    $proc = proc_open($cmd, array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
        3 => $lock
    ), $pipes);
    if (!is_resource($proc)) throw new RuntimeException('proc_open failed');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);
    if ($status !== 0) throw new RuntimeException('Child failed: ' . trim($errors));
    echo trim($output) . "\n";
    echo "PHP: " . PHP_VERSION . "\n";
    echo "NOTE: FD visibility only; lock lifetime and ownership remain unverified\n";
} finally {
    if (is_resource($lock)) fclose($lock);
    @unlink($path);
    @rmdir($dir);
}
