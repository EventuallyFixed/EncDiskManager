<?php
/**
 * Compare two independent read-only discovery snapshots before considering
 * a whole-disk operation. This is NOT authorization to erase anything.
 */
require_once __DIR__ . '/whole_usb_disk_preflight.php';

function edm_whole_usb_disk_stable_candidate($first, $second, $name)
{
    if (!edm_whole_usb_disk_preflight($first, $name)
        || !edm_whole_usb_disk_preflight($second, $name)) return false;

    $identity = function ($inventory) use ($name) {
        foreach ($inventory['disks'] as $disk) {
            if ($disk['name'] !== $name) continue;
            $parts = $disk['partition_dev_ids'];
            ksort($parts);
            return array(
                'dev_id' => $disk['dev_id'],
                'sysfs_path' => $disk['sysfs_path'],
                'size_bytes' => (string) $disk['size_bytes'],
                'partitions' => $disk['partitions'],
                'partition_dev_ids' => $parts,
                'serial' => isset($disk['serial']) ? $disk['serial'] : null
            );
        }
        return null;
    };
    return $identity($first) === $identity($second);
}
