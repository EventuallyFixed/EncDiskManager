<?php
require_once __DIR__ . '/../../lib/synthetic_dependency_snapshot_stability.php';
function assert_stability($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$sources = array();
foreach (array('sysfs_partitions', 'sysfs_holders', 'sysfs_slaves',
    'mountinfo', 'swaps', 'system_dependencies') as $source) {
    $sources[$source] = array('readable' => true, 'complete' => true);
}
$node = array('children' => array('sdb1'), 'holders' => array(),
    'mounted' => false, 'swap' => false, 'system' => false,
    'busy' => false, 'raid' => false, 'mapper' => false);
$partition = $node;
$partition['children'] = array();
$original = array('sources' => $sources,
    'graph' => array('complete' => true,
        'nodes' => array('sdb' => $node, 'sdb1' => $partition)),
    'identity' => array('name' => 'sdb', 'dev_id' => '8:16',
        'sysfs_path' => '/synthetic/usb1/sdb', 'serial' => 'SERIAL-A',
        'size_bytes' => 1048576));
assert_stability(edm_synthetic_dependency_snapshots_stable($original, $original, 'sdb'),
    'Identical complete synthetic snapshots accepted');
foreach (array('name', 'dev_id', 'sysfs_path', 'serial', 'size_bytes') as $key) {
    $changed = $original;
    $changed['identity'][$key] = $key === 'size_bytes' ? 2097152 : 'changed';
    assert_stability(!edm_synthetic_dependency_snapshots_stable($original, $changed, 'sdb'),
        'Reject changed identity ' . $key);
}
$changed = $original;
$changed['graph']['nodes']['sdb1']['mounted'] = true;
assert_stability(!edm_synthetic_dependency_snapshots_stable($original, $changed, 'sdb'),
    'Reject newly mounted partition');
$changed = $original;
$changed['graph']['nodes']['sdb1']['holders'][] = 'dm-0';
$changed['graph']['nodes']['dm-0'] = $partition;
assert_stability(!edm_synthetic_dependency_snapshots_stable($original, $changed, 'sdb'),
    'Reject newly acquired holder');
$changed = $original;
unset($changed['graph']['nodes']['sdb1']);
$changed['graph']['nodes']['sdb']['children'] = array();
assert_stability(!edm_synthetic_dependency_snapshots_stable($original, $changed, 'sdb'),
    'Reject changed partition inventory');
$changed = $original;
$changed['sources']['mountinfo']['readable'] = false;
assert_stability(!edm_synthetic_dependency_snapshots_stable($original, $changed, 'sdb'),
    'Reject unreadable second mountinfo probe');
assert_stability(!edm_synthetic_dependency_snapshots_stable($original, null, 'sdb'),
    'Reject missing second snapshot');

// A device disappearing or being replaced between observations must not
// be interpreted as a stable safe disk.
$changed = $original;
unset($changed['identity']);
assert_stability(!edm_synthetic_dependency_snapshots_stable($original, $changed, 'sdb'),
    'Reject disappeared device identity');
$changed = $original;
unset($changed['graph']['nodes']['sdb']);
assert_stability(!edm_synthetic_dependency_snapshots_stable($original, $changed, 'sdb'),
    'Reject disappeared root block node');
$changed = $original;
$changed['identity']['serial'] = 'SERIAL-REPLACEMENT';
$changed['identity']['sysfs_path'] = '/synthetic/usb2/sdb';
assert_stability(!edm_synthetic_dependency_snapshots_stable($original, $changed, 'sdb'),
    'Reject reconnect at same device name with replaced physical identity');
$changed = $original;
$changed['identity']['dev_id'] = '8:32';
assert_stability(!edm_synthetic_dependency_snapshots_stable($original, $changed, 'sdb'),
    'Reject changed major minor after reconnect');
$changed = $original;
$changed['sources']['sysfs_partitions']['complete'] = false;
assert_stability(!edm_synthetic_dependency_snapshots_stable($original, $changed, 'sdb'),
    'Reject incomplete partition observation after reconnect');
$changed = $original;
$changed['graph']['nodes']['sdb']['children'][] = 'sdb2';
$changed['graph']['nodes']['sdb2'] = $partition;
assert_stability(!edm_synthetic_dependency_snapshots_stable($original, $changed, 'sdb'),
    'Reject new partition after first observation');
