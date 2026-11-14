<?php
/**
 * Compare two read-only discovery snapshots. This does not authorize erasure.
 */
require_once __DIR__ . '/whole_usb_disk_preflight.php';
require_once __DIR__ . '/whole_disk_identity.php';

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
