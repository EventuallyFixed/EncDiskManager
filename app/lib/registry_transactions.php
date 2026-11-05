<?php
/**
 * Registry mutations under an exclusive transaction lock.
 *
 * The lock is separate from registry.php's atomic-save lock so that a
 * read/modify/write sequence cannot lose concurrent changes. Never accept
 * arbitrary registry paths from web input.
 */
require_once __DIR__ . '/registry_operations.php';

function edm_registry_transaction($registryPath, $operation)
{
    if (!is_string($registryPath) || basename($registryPath) !== 'disks.json') {
        throw new RuntimeException('Unexpected registry path.');
    }
    if (!is_callable($operation)) {
        throw new RuntimeException('Invalid registry operation.');
    }
    edm_registry_assert_directory(dirname($registryPath));
    $lockPath = dirname($registryPath) . '/disks.transaction.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Transaction lock must not be a symlink.');
    }
    $handle = @fopen($lockPath, 'c');
    if ($handle === false) {
        throw new RuntimeException('Cannot open registry transaction lock.');
    }
    try {
        $stat = fstat($handle);
        if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)
            || $stat['uid'] !== 0 || (($stat['mode'] & 0777) !== 0600)) {
            throw new RuntimeException('Registry transaction lock must be root-owned mode 0600.');
        }
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot lock registry transaction.');
        }
        // Reload while locked. The callback must only transform metadata.
        $current = edm_registry_load($registryPath);
        $next = call_user_func($operation, $current);
        edm_registry_validate($next);
        if ($next !== $current) {
            edm_registry_save($registryPath, $next);
        }
        return $next;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Only rename and change the nominated mount directory when the disk is
 * confirmed inactive by the caller's live privileged checks.
 */
function edm_registry_change_details($registryPath, $uuid, $name, $mountFolder, $isInactive)
{
    if ($isInactive !== true) {
        throw new RuntimeException('Disk must be verified inactive before changing its mount directory.');
    }
    return edm_registry_transaction($registryPath, function ($current) use ($uuid, $name, $mountFolder) {
        return edm_registry_update_disk($current, $uuid, $name, $mountFolder);
    });
}

/**
 * Removing a registration is metadata-only. Keyfile retirement is a separate
 * explicitly confirmed step and must never be silently skipped or assumed.
 */
function edm_registry_delete_registration($registryPath, $uuid, $isInactive)
{
    if ($isInactive !== true) {
        throw new RuntimeException('Disk must be verified inactive before deleting registration.');
    }
    return edm_registry_transaction($registryPath, function ($current) use ($uuid) {
        return edm_registry_remove_disk($current, $uuid);
    });
}
