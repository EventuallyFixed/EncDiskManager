<?php
/**
 * Shared per-application coordination lock.
 *
 * Every future privileged disk operation must hold this same lock for its
 * entire check/action sequence. A metadata transaction takes it first, then
 * its registry lock. This alone does not protect against external processes
 * (shell commands, automounters, or cryptsetup outside EncDiskManager).
 */
require_once __DIR__ . '/registry.php';

function edm_with_disk_operation_lock($dataDirectory, $callback)
{
    if (!is_callable($callback)) {
        throw new RuntimeException('Invalid disk-operation callback.');
    }
    edm_registry_assert_directory($dataDirectory);
    $path = $dataDirectory . '/disk-operations.lock';
    if (is_link($path)) {
        throw new RuntimeException('Disk-operation lock must not be a symlink.');
    }

    $oldUmask = umask(0077);
    try {
        $handle = @fopen($path, 'c');
    } finally {
        umask($oldUmask);
    }
    if ($handle === false) {
        throw new RuntimeException('Disk-operation lock cannot be opened.');
    }
    try {
        $stat = fstat($handle);
        if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)
            || $stat['uid'] !== 0 || (($stat['mode'] & 0777) !== 0600)) {
            throw new RuntimeException('Disk-operation lock must be a root-owned regular file with mode 0600.');
        }
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Disk-operation lock cannot be acquired.');
        }
        // The path could have been replaced between the initial link check
        // and fopen(), or while waiting for the lock. Reject a different inode.
        clearstatcache(true, $path);
        $pathStat = @lstat($path);
        $lockedStat = @fstat($handle);
        if ($pathStat === false || $lockedStat === false
            || ($pathStat['mode'] & 0170000) !== 0100000
            || $pathStat['uid'] !== 0 || ($pathStat['mode'] & 0777) !== 0600
            || $pathStat['dev'] !== $lockedStat['dev']
            || $pathStat['ino'] !== $lockedStat['ino']) {
            throw new RuntimeException('Disk-operation lock path changed or is unsafe.');
        }
        return call_user_func($callback);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
