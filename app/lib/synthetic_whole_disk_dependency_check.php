<?php
require_once __DIR__ . '/dependency_source_completeness.php';
require_once __DIR__ . '/whole_disk_topology.php';

/**
 * Synthetic-only planning gate: validates caller-supplied source statuses
 * and a dependency graph. Not trusted live discovery or authorization.
 */
function edm_synthetic_whole_disk_dependency_check($report, $graph, $root)
{
    return edm_dependency_sources_complete($report)
        && edm_whole_disk_topology_safe($graph, $root);
}
