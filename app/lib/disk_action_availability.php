<?php
/**
 * Display-only action availability derived from the existing USB discovery.
 * Privileged operations must recheck live device state independently.
 */
function edm_disk_available_actions($disk)
{
    $actions = array('unlock' => false, 'mount' => false,
        'unmount' => false, 'lock' => false, 'encrypt' => false);
    if (!is_array($disk) || empty($disk['path'])
        || !preg_match('~^/dev/[a-zA-Z0-9._+-]+$~D', $disk['path'])
        || empty($disk['luks'])) {
        return $actions;
    }

    // usb_discovery.php supplies these arrays for every USB partition.
    $mappers = isset($disk['mapper_names']) && is_array($disk['mapper_names'])
        ? $disk['mapper_names'] : array();
    $mounts = isset($disk['mapper_mounts']) && is_array($disk['mapper_mounts'])
        ? $disk['mapper_mounts'] : array();

    // An unexpected mapping topology must not enable controls.
    if (count($mappers) > 1 || count($mounts) > 1) return $actions;
    if (!empty($disk['mount'])) return $actions;

    $unlocked = count($mappers) === 1;
    $mounted = count($mounts) === 1;
    $actions['unlock'] = !$unlocked;
    $actions['mount'] = $unlocked && !$mounted;
    $actions['unmount'] = $unlocked && $mounted;
    $actions['lock'] = $unlocked && !$mounted;
    // Encryption belongs to the separately validated whole-disk action.
    return $actions;
}
