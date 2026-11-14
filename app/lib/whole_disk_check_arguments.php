<?php
/**
 * Prepare read-only helper arguments from a confirmed and freshly observed
 * disk identity. This never invokes the helper or authorizes an erase.
 */
require_once __DIR__ . '/whole_disk_identity.php';

function edm_whole_disk_check_arguments($confirmed, $current)
{
    if (!edm_whole_disk_identity_matches($confirmed, $current)) return false;
    $partitions = edm_whole_disk_partition_snapshot($current);
    if ($partitions === false) return false;
    if (!preg_match('~^/sys/devices/~', $current['sysfs_path'])) return false;

    return array(
        'check',
        '/dev/' . $current['name'],
        $current['dev_id'],
        $current['sysfs_path'],
        $current['size_sectors'],
        $partitions
    );
}
