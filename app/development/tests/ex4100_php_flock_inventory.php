<?php
/**
 * Read-only NAS compatibility experiment using a temporary lock file.
 * No disk devices, application registry, or cryptsetup commands are accessed.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}
if (!function_exists('proc_open') || !function_exists('flock')) {
    fwrite(STDERR, "Required PHP process/lock functions unavailable\n");
    exit(2);
}
$dir = sys_get_temp_dir() . '/edm-flock-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) {
    throw new RuntimeException('Cannot create temporary directory');
}
$path = $dir . '/lock';
$handle = null;
try {
    $handle = fopen($path, 'x+');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Cannot acquire test lock');
    }
    $script = <<<'PHP'
$h = fopen($argv[1], 'c+');
if ($h === false) exit(3);
$ok = flock($h, LOCK_EX | LOCK_NB);
if ($ok) flock($h, LOCK_UN);
fclose($h);
exit($ok ? 0 : 9);
PHP;
    $command = escapeshellarg(PHP_BINARY) . ' -r ' .
        escapeshellarg($script) . ' ' . escapeshellarg($path);
    $run = static function () use ($command) {
        $pipes = array();
        $process = proc_open($command, array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
        ), $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot launch child');
        }
        foreach ($pipes as $pipe) fclose($pipe);
        return proc_close($process);
    };
    if ($run() !== 9) {
        throw new RuntimeException('Child did not reject held lock');
    }
    echo "PASS: PHP child cannot acquire parent-held flock\n";
    flock($handle, LOCK_UN);
    if ($run() !== 0) {
        throw new RuntimeException('Child could not acquire released lock');
    }
    echo "PASS: PHP child acquires released flock\n";
    echo "PHP: " . PHP_VERSION . "\n";
    echo "NOTE: temporary-file experiment only; no disk operations\n";
} finally {
    if (is_resource($handle)) fclose($handle);
    @unlink($path);
    @rmdir($dir);
}
