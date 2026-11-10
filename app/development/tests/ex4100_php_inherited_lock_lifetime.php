<?php
/**
 * EX4100 temporary-file PHP-to-shell inherited flock lifetime test.
 * No disks, application paths, or privileged commands. CLI only.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
$dir = sys_get_temp_dir() . '/edm-lock-lifetime-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create temporary directory');
$path = $dir . '/disk-operations.lock';
$lock = null;
$child = null;
$pipes = array();
try {
    $lock = fopen($path, 'x+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Cannot acquire temporary lock');
    }
    // Child acknowledges FD 3 then waits for EOF on stdin.
    $shell = 'test -e /proc/self/fd/3 || exit 4; echo READY; IFS= read -r line || :';
    $child = proc_open('/bin/sh -c ' . escapeshellarg($shell), array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
        3 => $lock
    ), $pipes);
    if (!is_resource($child)) throw new RuntimeException('Cannot start child');
    if (trim(fgets($pipes[1])) !== 'READY') {
        throw new RuntimeException('Child did not acknowledge inherited descriptor');
    }
    echo "PASS: child received descriptor 3\n";
    // A separate PHP process opens the same path independently.
    $probeScript = '$h=fopen($argv[1],"c+"); if (!$h) exit(3); ' .
        '$ok=flock($h,LOCK_EX|LOCK_NB); if ($ok) flock($h,LOCK_UN); ' .
        'fclose($h); exit($ok?0:9);';
    $probe = static function () use ($probeScript, $path) {
        $cmd = escapeshellarg(PHP_BINARY) . ' -r ' .
            escapeshellarg($probeScript) . ' ' . escapeshellarg($path);
        $pp = array();
        $p = proc_open($cmd, array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
        ), $pp);
        if (!is_resource($p)) throw new RuntimeException('Probe launch failed');
        foreach ($pp as $stream) fclose($stream);
        return proc_close($p);
    };
    if ($probe() !== 9) throw new RuntimeException('Parent-held lock not enforced');
    echo "PASS: independent process blocked while parent holds lock\n";
    // Do NOT call flock(LOCK_UN): that would unlock the shared description.
    fclose($lock);
    $lock = null;
    if ($probe() !== 9) throw new RuntimeException('Child did not retain lock after parent close');
    echo "PASS: child retains lock after parent closes descriptor\n";
    fclose($pipes[0]);
    unset($pipes[0]);
    $exit = proc_close($child);
    $child = null;
    fclose($pipes[1]);
    fclose($pipes[2]);
    $pipes = array();
    if ($exit !== 0) throw new RuntimeException('Child failed');
    if ($probe() !== 0) throw new RuntimeException('Lock not released after child exit');
    echo "PASS: lock released after child exit\n";
    echo "PHP: " . PHP_VERSION . "\n";
    echo "NOTE: temporary-file lifetime test only; no disk operations\n";
} finally {
    if (is_resource($lock)) fclose($lock);
    foreach ($pipes as $stream) if (is_resource($stream)) fclose($stream);
    if (is_resource($child)) proc_terminate($child);
    if (is_resource($child)) proc_close($child);
    @unlink($path);
    @rmdir($dir);
}
