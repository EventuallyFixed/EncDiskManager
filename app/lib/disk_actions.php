<?php
/**
 * Read-only action policy for the USB disk inventory.
 *
 * This policy controls presentation only. Privileged operations MUST
 * independently re-identify the target and verify its live state.
 */
function edm_disk_action_policy($isLuks, $hasOpenMapper, $isMounted, $stateKnown = true)
{
    $actions = array(
        'unlock' => false,
        'mount' => false,
        'unmount' => false,
        'lock' => false,
        'encrypt' => false
    );

    // Unknown state is never grounds to offer a destructive action.
    if (!$stateKnown) {
        return $actions;
    }

    if (!$isLuks) {
        // A mounted plain filesystem must be unmounted outside this
        // LUKS-only action set before encryption can be offered.
        $actions['encrypt'] = !$isMounted && !$hasOpenMapper;
    } elseif (!$hasOpenMapper) {
        // A locked LUKS device may be unlocked or re-encrypted.
        // Direct mounting of the still-locked device is not allowed.
        $actions['unlock'] = !$isMounted;
        $actions['encrypt'] = !$isMounted;
    } elseif ($isMounted) {
        $actions['unmount'] = true;
    } else {
        $actions['mount'] = true;
        $actions['lock'] = true;
    }

    return $actions;
}
