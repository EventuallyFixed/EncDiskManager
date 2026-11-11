<?php
/**
 * Pure synthetic dependency-graph verifier for whole-disk encryption planning.
 * No live sysfs access, no disk operations, and no authorization to execute.
 * Graph nodes represent the disk, partitions, and transitive consumers.
 */
function edm_whole_disk_topology_safe($graph, $root)
{
    if (!is_array($graph) || !isset($graph['complete'], $graph['nodes'])
        || $graph['complete'] !== true || !is_array($graph['nodes'])
        || !is_string($root) || $root === '' || !isset($graph['nodes'][$root])) return false;
    $nodes = $graph['nodes'];
    if (count($nodes) === 0 || count($nodes) > 256) return false;
    foreach ($nodes as $id => $node) {
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9._:+-]+$/D', $id)
            || !is_array($node)) return false;
        foreach (array('children', 'holders') as $field) {
            if (!isset($node[$field]) || !is_array($node[$field])) return false;
            foreach ($node[$field] as $link) {
                if (!is_string($link) || !isset($nodes[$link])) return false;
            }
        }
        foreach (array('mounted', 'swap', 'system', 'busy', 'raid', 'mapper') as $flag) {
            if (!isset($node[$flag]) || !is_bool($node[$flag])) return false;
        }
    }
    $visited = array();
    $pending = array($root);
    while ($pending) {
        $id = array_pop($pending);
        if (isset($visited[$id])) continue;
        $visited[$id] = true;
        $node = $nodes[$id];
        foreach (array('mounted', 'swap', 'system', 'busy', 'raid', 'mapper') as $flag) {
            if ($node[$flag]) return false;
        }
        // Any holder means a dependent consumer exists, even if idle.
        if (count($node['holders']) !== 0) return false;
        foreach ($node['children'] as $child) $pending[] = $child;
    }
    // Reject disconnected/extra nodes: incomplete or ambiguous scope.
    return count($visited) === count($nodes);
}
