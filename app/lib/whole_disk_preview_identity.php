<?php
/**
 * Read-only confirmation binding: compare a displayed identity with the
 * current inventory. This does not authorize a privileged operation.
 */
require_once __DIR__ . '/whole_disk_identity.php';
require_once __DIR__ . '/whole_usb_disk_preflight.php';

function edm_whole_disk_preview_identity_matches($confirmed, $inventory)
{
    if (!is_array($confirmed) || !isset($confirmed['name'])
        || !is_string($confirmed['name'])
        || !edm_whole_usb_disk_preflight($inventory, $confirmed['name'])) {
        return false;
    }
    foreach ($inventory['disks'] as $current) {
        if (isset($current['name']) && $current['name'] === $confirmed['name']) {
            return edm_whole_disk_identity_matches($confirmed, $current)
                && edm_whole_disk_partition_snapshot($current) !== false;
        }
    }
    return false;
}
