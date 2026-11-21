<?php
/**
 * Group the existing partition inventory under physical USB disks.
 * Display-only: privileged operations must use their own fresh preflight.
 */
function edm_group_usb_inventory($partitionRows, $wholeInventory)
{
    if (!is_array($wholeInventory) || empty($wholeInventory['complete'])
        || !isset($wholeInventory['disks']) || !is_array($wholeInventory['disks'])) {
        return false;
    }

    $byPartition = array();
    foreach ($partitionRows as $row) {
        if (!is_array($row) || !array_key_exists('device', $row)) return false;
        if ($row['device'] === null) continue;
        $name = isset($row['device']['name']) ? $row['device']['name'] : '';
        if ($name === '' || isset($byPartition[$name])) return false;
        $byPartition[$name] = $row;
    }

    $result = array();
    $seen = array();
    $seenPartitions = array();
    foreach ($wholeInventory['disks'] as $disk) {
        if (!isset($disk['name'], $disk['partitions']) || !is_array($disk['partitions'])
            || isset($seen[$disk['name']])) return false;
        $seen[$disk['name']] = true;
        $parts = array();
        foreach ($disk['partitions'] as $name) {
            if (!is_string($name) || $name === '' || isset($seenPartitions[$name])) return false;
            $seenPartitions[$name] = true;
            if (isset($byPartition[$name])) {
                $parts[] = $byPartition[$name];
                unset($byPartition[$name]);
            }
        }
        $result[] = array('disk' => $disk, 'partitions' => $parts);
    }

    // A detected partition without a known parent means discovery is incomplete.
    // Never silently drop it or offer an erase action.
    if ($byPartition) return false;

    $disconnected = array();
    foreach ($partitionRows as $row) {
        if ($row['device'] === null) $disconnected[] = $row;
    }
    return array('connected' => $result, 'disconnected' => $disconnected);
}
