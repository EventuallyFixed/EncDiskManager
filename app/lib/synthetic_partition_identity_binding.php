<?php
/**
 * Pure synthetic partition identity binding; no kernel reads or authorization.
 * $nodes is an associative map keyed by canonical device name. Each value
 * contains exactly dev, parent, partition_number. Root parent and number
 * must be null; partitions must name the root and have positive numbers.
 * This verifies consistency of caller-supplied evidence, not completeness.
 */
function edm_synthetic_partition_identity_bound($nodes, $root, $rootDev, $partitions)
{
    if (!is_array($nodes) || !is_array($partitions)
        || count($nodes) < 1 || count($nodes) > 256
        || !is_string($root) || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $root)
        || !is_string($rootDev)
        || !preg_match('/^(0|[1-9][0-9]*):(0|[1-9][0-9]*)$/D', $rootDev)
        || !array_key_exists($root, $nodes)
        || array_keys($partitions) !== (count($partitions) ? range(0, count($partitions)-1) : array())
        || count($nodes) !== count($partitions) + 1) return false;
    $expected = array();
    foreach ($partitions as $entry) {
        if (!is_array($entry) || array_keys($entry) !== array('name','dev','partition_number')
            || !is_string($entry['name'])
            || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $entry['name'])
            || $entry['name'] === $root || isset($expected[$entry['name']])
            || !is_string($entry['dev'])
            || !preg_match('/^(0|[1-9][0-9]*):(0|[1-9][0-9]*)$/D', $entry['dev'])
            || !is_int($entry['partition_number']) || $entry['partition_number'] < 1)
            return false;
        $expected[$entry['name']] = $entry;
    }
    $devs = array(); $numbers = array();
    foreach ($nodes as $name => $node) {
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)
            || !is_array($node) || array_keys($node) !== array('dev','parent','partition_number')
            || !is_string($node['dev'])
            || !preg_match('/^(0|[1-9][0-9]*):(0|[1-9][0-9]*)$/D', $node['dev'])
            || isset($devs[$node['dev']])) return false;
        $devs[$node['dev']] = true;
        if ($name === $root) {
            if ($node['dev'] !== $rootDev || $node['parent'] !== null
                || $node['partition_number'] !== null) return false;
        } else {
            if (!isset($expected[$name]) || $node['parent'] !== $root
                || $node['dev'] !== $expected[$name]['dev']
                || $node['partition_number'] !== $expected[$name]['partition_number']
                || isset($numbers[$node['partition_number']])) return false;
            $numbers[$node['partition_number']] = true;
        }
    }
    return true;
}
