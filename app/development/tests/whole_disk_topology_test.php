<?php
require_once __DIR__ . '/../../lib/whole_disk_topology.php';
function check_topology($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
function clean_node($children = array()) {
    return array('children' => $children, 'holders' => array(),
        'mounted' => false, 'swap' => false, 'system' => false,
        'busy' => false, 'raid' => false, 'mapper' => false);
}
$graph = array('complete' => true, 'nodes' => array(
    'sdb' => clean_node(array('sdb1', 'sdb2')),
    'sdb1' => clean_node(), 'sdb2' => clean_node()
));
check_topology(edm_whole_disk_topology_safe($graph, 'sdb'),
    'Complete idle disk with partitions accepted by synthetic topology verifier');
foreach (array('mounted', 'swap', 'system', 'busy', 'raid', 'mapper') as $flag) {
    foreach (array('sdb', 'sdb1') as $id) {
        $bad = $graph;
        $bad['nodes'][$id][$flag] = true;
        check_topology(!edm_whole_disk_topology_safe($bad, 'sdb'),
            'Reject ' . $flag . ' on ' . $id);
    }
}
$holder = $graph;
$holder['nodes']['sdb1']['holders'] = array('dm-0');
$holder['nodes']['dm-0'] = clean_node();
check_topology(!edm_whole_disk_topology_safe($holder, 'sdb'),
    'Reject partition with device mapper holder');
$missing = $graph;
$missing['nodes']['sdb']['children'][] = 'missing';
check_topology(!edm_whole_disk_topology_safe($missing, 'sdb'),
    'Reject unresolved partition link');
$extra = $graph;
$extra['nodes']['sdc'] = clean_node();
check_topology(!edm_whole_disk_topology_safe($extra, 'sdb'),
    'Reject ambiguous disconnected graph node');
$partial = $graph;
$partial['complete'] = false;
check_topology(!edm_whole_disk_topology_safe($partial, 'sdb'),
    'Reject incomplete graph');
$invalid = $graph;
unset($invalid['nodes']['sdb1']['swap']);
check_topology(!edm_whole_disk_topology_safe($invalid, 'sdb'),
    'Reject missing dependency state');
