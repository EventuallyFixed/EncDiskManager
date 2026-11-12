<?php
/**
 * Synthetic-only mount/swap dependency gate.
 * Input is an explicit fixture report, NOT live /proc or /sys.
 * Unknown, incomplete, or present dependencies always fail closed.
 */
function edm_fixture_mount_swap_clear($report, $devices)
{
    if (!is_array($report) || !is_array($devices)
        || count($devices) === 0 || count($devices) > 256) return false;
    if (array_keys($report) !== array('mounts', 'swaps')) return false;
    $seen = array();
    foreach ($devices as $device) {
        if (!is_string($device) || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $device)
            || isset($seen[$device])) return false;
        $seen[$device] = true;
    }
    foreach (array('mounts', 'swaps') as $source) {
        $item = $report[$source];
        if (!is_array($item) || array_keys($item) !== array('readable', 'complete', 'devices')
            || $item['readable'] !== true || $item['complete'] !== true
            || !is_array($item['devices'])) return false;
        foreach ($item['devices'] as $device) {
            if (!is_string($device) || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $device))
                return false;
            // Any dependency, including on an unrelated device, is conservatively
            // rejected: this fixture gate has no trusted ancestry resolver.
            return false;
        }
    }
    return true;
}
