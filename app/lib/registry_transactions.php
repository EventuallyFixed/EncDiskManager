<?php
/**
 * Registry mutations under an exclusive transaction lock.
 *
 * The lock is separate from registry.php's atomic-save lock so that a
 * read/modify/write sequence cannot lose concurrent changes. Never accept
 * arbitrary registry paths from web input.
 */
require_once __DIR__ . '/registry_operations.php';
require_once __DIR__ . '/disk_operation_lock.php';
require_once __DIR__ . '/configuration_authorization.php';

function edm_registry_transaction($registryPath, $operation)
{
    if (!is_string($registryPath) || basename($registryPath) !== 'disks.json') {
        throw new RuntimeException('Unexpected registry path.');
    }
    if (!is_callable($operation)) {
        throw new RuntimeException('Invalid registry operation.');
    }
    return edm_with_disk_operation_lock(dirname($registryPath), function () use ($registryPath, $operation) {
        return edm_registry_transaction_under_disk_lock($registryPath, $operation);
    });
}

function edm_registry_transaction_under_disk_lock($registryPath, $operation)
{
    edm_registry_assert_directory(dirname($registryPath));
    $lockPath = dirname($registryPath) . '/disks.transaction.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Transaction lock must not be a symlink.');
    }
    $oldUmask = umask(0077);
    try {
        $handle = @fopen($lockPath, 'c');
    } finally {
        umask($oldUmask);
    }
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
 * confirmed inactive by fresh privileged checks inside the registry lock.
 */
function edm_registry_change_details($registryPath, $uuid, $name, $mountFolder, $verifyInactive)
{
    if (!is_callable($verifyInactive)) {
        throw new RuntimeException('A live disk-state verifier is required.');
    }
    return edm_registry_transaction($registryPath, function ($current) use ($uuid, $name, $mountFolder, $verifyInactive) {
        // Recheck registration identity and uniqueness against the locked, fresh registry.
        edm_configuration_registration_preflight(array(
            'action' => 'edit', 'luks_uuid' => $uuid,
            'name' => $name, 'mount_folder' => $mountFolder
        ), $current);
        // Verify after acquiring the transaction lock, not before waiting.
        if (call_user_func($verifyInactive, $uuid) !== true) {
            throw new RuntimeException('Disk is not confirmed inactive.');
        }
        return edm_registry_update_disk($current, $uuid, $name, $mountFolder);
    });
}

/**
 * Removing a registration is metadata-only. Keyfile retirement is a separate
 * explicitly confirmed step and must never be silently skipped or assumed.
 * The verifier does not prevent external disk state changes; full coordination
 * with the privileged disk-operation lock is still required before enabling UI.
 */
function edm_registry_delete_registration($registryPath, $uuid, $verifyInactive)
{
    if (!is_callable($verifyInactive)) {
        throw new RuntimeException('A live disk-state verifier is required.');
    }
    return edm_registry_transaction($registryPath, function ($current) use ($uuid, $verifyInactive) {
        edm_configuration_registration_preflight(array(
            'action' => 'delete', 'luks_uuid' => $uuid
        ), $current);
        if (call_user_func($verifyInactive, $uuid) !== true) {
            throw new RuntimeException('Disk is not confirmed inactive.');
        }
        return edm_registry_remove_disk($current, $uuid);
    });
}
