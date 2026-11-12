<?php
/**
 * Pure synthetic swap backing-identity check. No filesystem or kernel reads.
 * Caller supplies parsed swap records and an explicit, independent mapping
 * from each swap pathname to a nonempty list of canonical major:minor IDs.
 * Unknown, ambiguous, duplicate, or inconsistent mappings fail closed.
 * Agreement is fixture consistency, NOT proof of live source completeness.
 */
function edm_synthetic_swap_backing_unambiguous($swaps, $backing)
{
    if (!is_array($swaps) || !is_array($backing)
        || count($swaps) > 256 || count($backing) !== count($swaps)
        || array_keys($swaps) !== (count($swaps) ? range(0, count($swaps) - 1) : array()))
        return false;
    $seen = array();
    foreach ($swaps as $swap) {
        if (!is_array($swap) || array_keys($swap) !== array('path', 'type')
            || !is_string($swap['path']) || $swap['path'] === ''
            || $swap['path'][0] !== '/'
            || !in_array($swap['type'], array('partition', 'file'), true)
            || isset($seen[$swap['path']])
            || !array_key_exists($swap['path'], $backing)) return false;
        $seen[$swap['path']] = true;
        $ids = $backing[$swap['path']];
        // Multiple backing devices (e.g. layered/striped swap files) are
        // deliberately unsupported until a trusted ancestry graph exists.
        if (!is_array($ids) || count($ids) !== 1 || !isset($ids[0])
            || !is_string($ids[0])
            || !preg_match('/^(0|[1-9][0-9]*):(0|[1-9][0-9]*)$/D', $ids[0]))
            return false;
    }
    return true;
}
