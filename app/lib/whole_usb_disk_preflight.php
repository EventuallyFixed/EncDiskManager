<?php
/**
 * Preliminary, read-only whole-disk safety check.
 * This does not authorize encryption. A privileged operation must still
 * independently revalidate the device and its complete dependencies.
 */
function edm_whole_usb_disk_preflight($inventory, $name)
{
    if (!is_array($inventory) || !isset($inventory['complete'], $inventory['disks'])
        || $inventory['complete'] !== true || !is_array($inventory['disks'])
        || !is_string($name)) return false;
    $matches = 0;
    foreach ($inventory['disks'] as $disk) {
        if (!is_array($disk) || !isset($disk['name']) || !is_string($disk['name'])) {
            return false;
        }
        if ($disk['name'] !== $name) continue;
        $matches++;
        foreach (array('mounted', 'has_holders', 'has_slaves', 'read_only') as $field) {
            if (!isset($disk[$field]) || !is_bool($disk[$field]) || $disk[$field]) {
                return false;
            }
        }
        if (!isset($disk['dev_id'], $disk['sysfs_path'], $disk['partitions'],
            $disk['partition_dev_ids'], $disk['size_bytes'])
            || !is_string($disk['dev_id'])
            || !preg_match('/^[0-9]+:[0-9]+$/D', $disk['dev_id'])
            || !is_string($disk['sysfs_path']) || $disk['sysfs_path'] === ''
            || !is_array($disk['partitions'])
            || !is_array($disk['partition_dev_ids'])
            || !is_numeric($disk['size_bytes']) || $disk['size_bytes'] <= 0) {
            return false;
        }
        foreach ($disk['partitions'] as $partition) {
            if (!is_string($partition)
                || !isset($disk['partition_dev_ids'][$partition])
                || !preg_match('/^[0-9]+:[0-9]+$/D',
                    $disk['partition_dev_ids'][$partition])) return false;
        }
    }
    return $matches === 1;
}
