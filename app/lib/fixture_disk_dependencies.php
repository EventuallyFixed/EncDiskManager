<?php
require_once __DIR__ . '/fixture_partition_tree.php';
require_once __DIR__ . '/fixture_block_holders.php';
require_once __DIR__ . '/fixture_mount_swap.php';

/**
 * Synthetic-only combined disk dependency gate. No live kernel reads.
 * Success is fixture consistency, never permission to encrypt or erase.
 */
function edm_fixture_disk_dependencies_clear($root, $disk, $mountSwapReport)
{
    $tree = edm_fixture_partition_tree($root, $disk);
    if (!isset($tree['complete'], $tree['nodes'])
        || $tree['complete'] !== true || !is_array($tree['nodes'])) return false;
    $devices = array_keys($tree['nodes']);
    if (!edm_fixture_block_holders_clear($root, $devices)) return false;
    return edm_fixture_mount_swap_clear($mountSwapReport, $devices);
}
