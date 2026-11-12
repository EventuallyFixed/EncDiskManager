<?php
require_once __DIR__ . '/fixture_partition_tree.php';
require_once __DIR__ . '/fixture_block_holders.php';

/**
 * Synthetic fixture-only partition/holder inspection.
 * Does not assess mounts, swap, RAID, signatures or NAS system ancestry.
 * Never grants encryption authorization.
 */
function edm_fixture_partition_holders_clear($root, $disk)
{
    $tree = edm_fixture_partition_tree($root, $disk);
    if (!isset($tree['complete'], $tree['nodes'])
        || $tree['complete'] !== true || !is_array($tree['nodes'])) return false;
    return edm_fixture_block_holders_clear($root, array_keys($tree['nodes']));
}
