<?php
require_once __DIR__ . '/../../lib/synthetic_whole_disk_dependency_check.php';
function assert_combined($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$report = array();
foreach (array('sysfs_partitions', 'sysfs_holders', 'sysfs_slaves',
    'mountinfo', 'swaps', 'system_dependencies') as $source) {
    $report[$source] = array('readable' => true, 'complete' => true);
}
$node = array('children' => array(), 'holders' => array(),
    'mounted' => false, 'swap' => false, 'system' => false,
    'busy' => false, 'raid' => false, 'mapper' => false);
$graph = array('complete' => true, 'nodes' => array('sdb' => $node));
assert_combined(edm_synthetic_whole_disk_dependency_check($report, $graph, 'sdb'),
    'Complete source statuses and safe synthetic topology accepted');
$bad = $report;
$bad['sysfs_holders']['readable'] = false;
assert_combined(!edm_synthetic_whole_disk_dependency_check($bad, $graph, 'sdb'),
    'Safe graph rejected when holders source unreadable');
$bad = $report;
unset($bad['mountinfo']);
assert_combined(!edm_synthetic_whole_disk_dependency_check($bad, $graph, 'sdb'),
    'Safe graph rejected when mountinfo status missing');
$badGraph = $graph;
$badGraph['nodes']['sdb']['swap'] = true;
assert_combined(!edm_synthetic_whole_disk_dependency_check($report, $badGraph, 'sdb'),
    'Complete source statuses do not override active swap');
$badGraph = $graph;
$badGraph['complete'] = false;
assert_combined(!edm_synthetic_whole_disk_dependency_check($report, $badGraph, 'sdb'),
    'Complete source statuses do not override incomplete graph');
assert_combined(!edm_synthetic_whole_disk_dependency_check($report, $graph, 'sdc'),
    'Unknown target rejected');
