<?php
/**
 * Compare two read-only discovery snapshots. This does not authorize erasure.
 */
require_once __DIR__ . '/whole_usb_disk_preflight.php';
require_once __DIR__ . '/whole_disk_identity.php';
require_once __DIR__ . '/whole_disk_check_arguments.php';

function edm_whole_usb_disk_stable_candidate($first, $second, $name)
{
    if (!edm_whole_usb_disk_preflight($first, $name)
        || !edm_whole_usb_disk_preflight($second, $name)) return false;

    $a = null;
    $b = null;
    foreach ($first['disks'] as $disk) {
        if ($disk['name'] === $name) $a = $disk;
    }
    foreach ($second['disks'] as $disk) {
        if ($disk['name'] === $name) $b = $disk;
    }
    return edm_whole_disk_identity_matches($a, $b);
}

/**
 * Build the existing read-only helper's arguments only after both inventory
 * snapshots agree. No privileged command is run here.
 */
function edm_whole_usb_disk_stable_check_arguments($first, $second, $name)
{
    if (!edm_whole_usb_disk_stable_candidate($first, $second, $name)) return false;
    foreach ($second['disks'] as $current) {
        if ($current['name'] !== $name) continue;
        foreach ($first['disks'] as $confirmed) {
            if ($confirmed['name'] === $name) {
                return edm_whole_disk_check_arguments($confirmed, $current);
            }
        }
    }
    return false;
}

/**
 * Removal advice uses the whole disk, including all partition holders.
 * Read-only media may be unplugged too; erase eligibility is separate.
 */
function edm_whole_usb_disk_safe_to_unplug($first, $second, $name)
{
    $matches = array();
    foreach (array($first, $second) as $snapshot) {
        if (empty($snapshot['complete']) || !isset($snapshot['disks'])) return false;
        $found = array();
        foreach ($snapshot['disks'] as $disk) {
            if ($disk['name'] === $name) $found[] = $disk;
        }
        if (count($found) !== 1) return false;
        foreach (array('mounted', 'has_holders', 'has_slaves') as $field) {
            if (!isset($found[0][$field]) || $found[0][$field] !== false) return false;
        }
        $matches[] = $found[0];
    }
    return edm_whole_disk_identity_matches($matches[0], $matches[1]);
}
