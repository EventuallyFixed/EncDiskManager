<?php
/**
 * Persistent fail-closed recovery marker. Does not execute disk operations.
 * Caller MUST hold disk-operations.lock while writing this marker.
 * Marker clearance is deliberately not implemented.
 */
require_once __DIR__ . '/registry.php';

function edm_disk_operation_mark_recovery_required($dataDirectory)
{
    edm_registry_assert_directory($dataDirectory);
    $path = $dataDirectory . '/disk-operation-recovery-required';
    clearstatcache(true, $path);
    if (is_link($path)) {
        throw new RuntimeException('Recovery marker must not be a symlink.');
    }
    if (file_exists($path)) {
        edm_registry_assert_file($path, 'Disk-operation recovery marker');
        return true;
    }
    // Exclusive creation prevents replacing an existing file or following a
    // concurrently introduced symlink. Private root-owned directory required.
    $oldUmask = umask(0077);
    try {
        $handle = @fopen($path, 'x');
    } finally {
        umask($oldUmask);
    }
    if ($handle === false) {
        throw new RuntimeException('Recovery marker could not be created.');
    }
    try {
        $stat = @fstat($handle);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000
            || $stat['uid'] !== 0 || ($stat['mode'] & 0777) !== 0600) {
            throw new RuntimeException('Recovery marker metadata is unsafe.');
        }
        $message = "recovery-required\n";
        if (@fwrite($handle, $message) !== strlen($message)
            || !@fflush($handle)) {
            throw new RuntimeException('Recovery marker could not be written.');
        }
        // PHP 7.4 lacks a portable fsync primitive. This function does not
        // claim power-loss durability; release remains blocked pending
        // hardware-supported durable persistence and recovery validation.
        clearstatcache(true, $path);
        $onDisk = @lstat($path);
        if ($onDisk === false || $onDisk['dev'] !== $stat['dev']
            || $onDisk['ino'] !== $stat['ino']) {
            throw new RuntimeException('Recovery marker path changed.');
        }
        return true;
    } finally {
        fclose($handle);
    }
}
