<?php
require_once __DIR__ . '/synthetic_partition_topology_agreement.php';
require_once __DIR__ . '/synthetic_partition_identity_binding.php';

/**
 * Pure synthetic agreement across partition inventories, dependency topology,
 * and per-node parent/device-number binding. No live kernel reads and no
 * authorization to modify disks. All inputs are caller-supplied fixtures.
 */
function edm_synthetic_partition_bound_topology_agree(
    $left, $right, $graph, $identityNodes, $root, $rootDev
) {
    if (!edm_synthetic_partition_topology_agree(
        $left, $right, $graph, $root, $rootDev
    )) return false;
    if (!edm_synthetic_partition_identity_bound(
        $identityNodes, $root, $rootDev, $left
    )) return false;
    if (count($graph['nodes']) !== count($identityNodes)) return false;
    foreach ($graph['nodes'] as $name => $node) {
        if (!array_key_exists($name, $identityNodes)) return false;
        if ($name === $root) {
            if ($identityNodes[$name]['parent'] !== null) return false;
            continue;
        }
        if ($identityNodes[$name]['parent'] !== $root) return false;
        if (!in_array($name, $graph['nodes'][$root]['children'], true))
            return false;
    }
    return true;
}
