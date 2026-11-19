<?php
/**
 * UI action availability for an attached USB partition.
 *
 * This is presentation logic only. Privileged helpers must independently
 * validate the live device and operation immediately before acting.
 */
function edm_disk_available_actions($disk, $registered = false)
{
    $none = array('unlock' => false, 'mount' => false,
        'unmount' => false, 'lock' => false, 'encrypt' => false);
    if (!is_array($disk) || empty($disk['path']) || !empty($disk['ambiguous'])) {
        return $none;
    }

    // Never offer LUKS operations for a filesystem that is not LUKS.
    $encrypted = !empty($disk['luks']);
    $mounted = !empty($disk['mounted']);
    $unlocked = !empty($disk['unlocked']);
    $busy = !empty($disk['busy']);
    $readOnly = !empty($disk['read_only']);

    if (!$encrypted) {
        // Whole-disk encryption is offered from the parent-disk inventory,
        // not from a partition row. A partition must never be an erase target.
        return $none;
    }

    if ($busy || $readOnly) return $none;
    $none['unlock'] = !$unlocked && !$mounted;
    $none['mount'] = $unlocked && !$mounted;
    $none['unmount'] = $unlocked && $mounted;
    $none['lock'] = $unlocked && !$mounted;
    return $none;
}
