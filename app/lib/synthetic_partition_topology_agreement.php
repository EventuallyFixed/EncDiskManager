<?php
require_once __DIR__ . '/synthetic_partition_view_agreement.php';
require_once __DIR__ . '/whole_disk_topology.php';

/**
 * Cross-check two synthetic partition inventories against a synthetic graph.
 * Does not read the kernel, prove completeness, or authorize disk operations.
 */
function edm_synthetic_partition_topology_agree($left, $right, $graph, $root, $rootDev)
{
    if (!edm_synthetic_partition_views_agree($left, $right)
        || !edm_whole_disk_topology_safe($graph, $root)
        || !is_string($rootDev)
        || !preg_match('/^(0|[1-9][0-9]*):(0|[1-9][0-9]*)$/D', $rootDev))
        return false;

    $expected = array();
    foreach ($left as $entry) {
        if ($entry['name'] === $root || $entry['dev'] === $rootDev)
            return false;
        $expected[$entry['name']] = true;
    }
    $children = $graph['nodes'][$root]['children'];
    if (count($children) !== count($expected)) return false;
    foreach ($children as $name) {
        if (!isset($expected[$name]) || !empty($graph['nodes'][$name]['children']))
            return false;
    }
    // The topology validator already rejects disconnected nodes and holders.
    return count($graph['nodes']) === count($expected) + 1;
}
