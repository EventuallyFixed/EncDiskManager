<?php
require_once __DIR__ . '/../../lib/synthetic_partition_topology_agreement.php';
function check_partition_topology($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$leaf = array('children' => array(), 'holders' => array(), 'mounted' => false,
    'swap' => false, 'system' => false, 'busy' => false, 'raid' => false, 'mapper' => false);
$rootNode = $leaf;
$rootNode['children'] = array('sdb1', 'sdb2');
$graph = array('complete' => true, 'nodes' => array(
    'sdb' => $rootNode, 'sdb1' => $leaf, 'sdb2' => $leaf));
$parts = array(
    array('name' => 'sdb1', 'dev' => '8:17', 'partition_number' => 1),
    array('name' => 'sdb2', 'dev' => '8:18', 'partition_number' => 2));
$agree = function ($a, $b, $g, $rootDev = '8:16') {
    return edm_synthetic_partition_topology_agree($a, $b, $g, 'sdb', $rootDev);
};
check_partition_topology($agree($parts, array_reverse($parts), $graph),
    'Matching inventories and topology agree regardless of view order');
$changed = $graph;
$changed['nodes']['sdb']['children'] = array('sdb1');
unset($changed['nodes']['sdb2']);
check_partition_topology(!$agree($parts, $parts, $changed),
    'Reject graph missing a partition present in both views');
$changed = $graph;
$changed['nodes']['sdb']['children'][] = 'sdb3';
$changed['nodes']['sdb3'] = $leaf;
check_partition_topology(!$agree($parts, $parts, $changed),
    'Reject unexpected partition in topology');
$changed = $parts;
$changed[1]['dev'] = '8:19';
check_partition_topology(!$agree($parts, $changed, $graph),
    'Reject mismatched partition kernel identity');
$changed = $parts;
$changed[1]['dev'] = '8:17';
check_partition_topology(!$agree($changed, $changed, $graph),
    'Reject duplicate partition major minor');
$changed = $parts;
$changed[0]['dev'] = '8:16';
check_partition_topology(!$agree($changed, $changed, $graph),
    'Reject partition identity colliding with whole disk');
$changed = $parts;
$changed[0]['name'] = 'sdb';
check_partition_topology(!$agree($changed, $changed, $graph),
    'Reject partition name colliding with root');
$changed = $graph;
$changed['nodes']['sdb1']['children'][] = 'nested';
$changed['nodes']['nested'] = $leaf;
check_partition_topology(!$agree($parts, $parts, $changed),
    'Reject unexpected nested topology');
$empty = array('complete' => true, 'nodes' => array('sdb' => $leaf));
check_partition_topology($agree(array(), array(), $empty),
    'Explicit empty partition views agree with empty graph');
check_partition_topology(!$agree(array(), array(), $graph),
    'Empty views reject graph with partitions');

$associative = array('partition-one' => $parts[0], 'partition-two' => $parts[1]);
check_partition_topology(!$agree($associative, $associative, $graph),
    'Reject matching but associative partition inventories');
$sparse = array(0 => $parts[0], 2 => $parts[1]);
check_partition_topology(!$agree($sparse, $sparse, $graph),
    'Reject matching but sparse partition inventories');
$repeated = array($parts[0], $parts[0]);
check_partition_topology(!$agree($repeated, $repeated, $graph),
    'Reject matching but duplicate partition identities');
$malformed = $parts;
$malformed[0]['partition_number'] = '1';
check_partition_topology(!$agree($malformed, $malformed, $graph),
    'Reject matching but malformed partition numbers');
