<?php
/**
 * Pure, read-only validator for synthetic whole-USB-disk snapshots.
 * This does not discover live devices, authorize operations or execute commands.
 */
function edm_whole_usb_disk_candidate($snapshot, $name)
{
    if (!is_array($snapshot) || !isset($snapshot['complete'], $snapshot['disks'])
        || $snapshot['complete'] !== true || !is_array($snapshot['disks'])
        || !is_string($name) || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)) return null;
    $seenNames = array();
    $seenDevIds = array();
    $seenSerials = array();
    $found = null;
    foreach ($snapshot['disks'] as $disk) {
        if (!is_array($disk)) return null;
        foreach (array('name', 'dev_id', 'sysfs_path', 'serial', 'transport') as $key) {
            if (!isset($disk[$key]) || !is_string($disk[$key])) return null;
        }
        foreach (array('whole_disk', 'system_dependency', 'busy', 'mounted', 'has_holders',
            'topology_complete', 'signatures_complete', 'read_only') as $key) {
            if (!isset($disk[$key]) || !is_bool($disk[$key])) return null;
        }
        if (!isset($disk['size_bytes']) || !is_int($disk['size_bytes'])
            || $disk['size_bytes'] <= 0
            || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $disk['name'])
            || !preg_match('/^[0-9]+:[0-9]+$/D', $disk['dev_id'])
            || $disk['sysfs_path'] === '' || $disk['sysfs_path'][0] !== '/'
            || $disk['serial'] === ''
            || isset($seenNames[$disk['name']]) || isset($seenDevIds[$disk['dev_id']])
            || isset($seenSerials[$disk['serial']])) return null;
        $seenNames[$disk['name']] = true;
        $seenDevIds[$disk['dev_id']] = true;
        $seenSerials[$disk['serial']] = true;
        if ($disk['name'] !== $name) continue;
        if (!$disk['whole_disk'] || $disk['transport'] !== 'usb'
            || $disk['system_dependency'] || $disk['busy'] || $disk['mounted']
            || $disk['has_holders'] || !$disk['topology_complete']
            || !$disk['signatures_complete'] || $disk['read_only']) return null;
        $found = $disk;
    }
    return $found;
}
function edm_whole_usb_disk_still_matches($bound, $freshSnapshot)
{
    if (!is_array($bound) || !isset($bound['name'])) return false;
    $fresh = edm_whole_usb_disk_candidate($freshSnapshot, $bound['name']);
    return $fresh !== null && $fresh === $bound;
}

/**
 * Opaque inventory identifier for a validated whole USB disk.
 * This is not authorization: callers must rediscover and recheck the disk
 * immediately before any destructive operation.
 */
function edm_whole_usb_disk_identifier($disk)
{
    if (!is_array($disk)) return null;
    foreach (array('name', 'dev_id', 'sysfs_path', 'serial') as $field) {
        if (!isset($disk[$field]) || !is_string($disk[$field])
            || $disk[$field] === '') return null;
    }
    if (!isset($disk['size_bytes']) || !is_int($disk['size_bytes'])
        || $disk['size_bytes'] <= 0) return null;
    return hash('sha256', json_encode(array(
        $disk['name'], $disk['dev_id'], $disk['sysfs_path'],
        $disk['serial'], $disk['size_bytes']
    )));
}

/**
 * Resolve a client-supplied opaque identifier only against a fresh,
 * complete server-side snapshot. Never accept a device path from the client.
 */
function edm_whole_usb_disk_by_identifier($snapshot, $identifier)
{
    if (!is_string($identifier)
        || !preg_match('/^[a-f0-9]{64}$/D', $identifier)
        || !is_array($snapshot) || !isset($snapshot['complete'], $snapshot['disks'])
        || $snapshot['complete'] !== true || !is_array($snapshot['disks'])) return null;
    $found = null;
    foreach ($snapshot['disks'] as $disk) {
        if (!is_array($disk) || !isset($disk['name'])
            || !is_string($disk['name'])) return null;
        $candidate = edm_whole_usb_disk_candidate($snapshot, $disk['name']);
        if ($candidate === null) continue;
        $id = edm_whole_usb_disk_identifier($candidate);
        if ($id !== null && hash_equals($id, $identifier)) {
            if ($found !== null) return null;
            $found = $candidate;
        }
    }
    return $found;
}
