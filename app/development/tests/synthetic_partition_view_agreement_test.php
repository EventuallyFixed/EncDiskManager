<?php
require_once __DIR__ . '/../../lib/synthetic_partition_view_agreement.php';
function assert_views($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$a = array(
    array('name' => 'sdb1', 'dev' => '8:17', 'partition_number' => 1),
    array('name' => 'sdb2', 'dev' => '8:18', 'partition_number' => 2)
);
$b = array_reverse($a);
assert_views(edm_synthetic_partition_views_agree($a, $b),
    'Agree on matching independent synthetic views regardless of order');
assert_views(edm_synthetic_partition_views_agree(array(), array()),
    'Agree on explicitly empty synthetic views without claiming kernel completeness');
assert_views(!edm_synthetic_partition_views_agree($a, array($a[0])),
    'Reject omitted partition in second view');
$c = $a; $c[1]['dev'] = '8:19';
assert_views(!edm_synthetic_partition_views_agree($a, $c),
    'Reject changed major minor identity');
$c = $a; $c[1]['partition_number'] = 3;
assert_views(!edm_synthetic_partition_views_agree($a, $c),
    'Reject changed partition number');
$c = $a; $c[1]['dev'] = '8:17';
assert_views(!edm_synthetic_partition_views_agree($c, $c),
    'Reject duplicate major minor within a view');
$c = $a; $c[1]['name'] = 'sdb1';
assert_views(!edm_synthetic_partition_views_agree($c, $c),
    'Reject duplicate partition names');
$c = $a; $c[1]['partition_number'] = 1;
assert_views(!edm_synthetic_partition_views_agree($c, $c),
    'Reject duplicate partition numbers');
$c = $a; $c[0]['dev'] = '08:17';
assert_views(!edm_synthetic_partition_views_agree($c, $c),
    'Reject noncanonical device identity');
$c = $a; $c[0]['name'] = '../sdb1';
assert_views(!edm_synthetic_partition_views_agree($c, $c),
    'Reject invalid partition name');

foreach (array(null, false, 'not-an-array', 7) as $invalid) {
    assert_views(!edm_synthetic_partition_views_agree($invalid, $a),
        'Reject non-array inventory');
}
foreach (array(
    array('name' => 'sdb1', 'dev' => '8:17'),
    array('name' => 'sdb1', 'dev' => '8:17', 'partition_number' => 1, 'extra' => true),
    array('dev' => '8:17', 'partition_number' => 1, 'name' => 'sdb1'),
    array('name' => 'sdb1', 'dev' => '8:17', 'partition_number' => '1'),
    array('name' => 'sdb1', 'dev' => '8:17', 'partition_number' => 0),
    array('name' => '', 'dev' => '8:17', 'partition_number' => 1),
    array('name' => 'sdb1', 'dev' => '8:017', 'partition_number' => 1),
    array('name' => 'sdb1', 'dev' => '-1:17', 'partition_number' => 1),
) as $invalidEntry) {
    assert_views(!edm_synthetic_partition_views_agree(array($invalidEntry), array($invalidEntry)),
        'Reject malformed or ambiguous partition entry');
}
assert_views(!edm_synthetic_partition_views_agree(array_fill(0, 257, $a[0]), array()),
    'Reject oversized inventory');
assert_views(!edm_synthetic_partition_views_agree(array('unexpected' => $a[0]), array('unexpected' => $a[0])),
    'Reject associative inventory index');
