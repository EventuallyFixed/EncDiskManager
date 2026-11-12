<?php
/**
 * Synthetic-only dependency-source completeness gate.
 * A caller-supplied report is never evidence of actual kernel state.
 */
function edm_dependency_sources_complete($report)
{
    if (!is_array($report) || count($report) !== 6) return false;
    $required = array('sysfs_partitions', 'sysfs_holders', 'sysfs_slaves',
        'mountinfo', 'swaps', 'system_dependencies');
    foreach ($required as $source) {
        if (!array_key_exists($source, $report)
            || !is_array($report[$source])
            || count($report[$source]) !== 2
            || !array_key_exists('readable', $report[$source])
            || !array_key_exists('complete', $report[$source])
            || $report[$source]['readable'] !== true
            || $report[$source]['complete'] !== true) return false;
    }
    return true;
}
