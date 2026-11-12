<?php
require_once __DIR__ . '/../../lib/synthetic_swap_backing_identity.php';
function check_swap_backing($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$swaps = array(array('path' => '/dev/sdb2', 'type' => 'partition'),
               array('path' => '/swapfile', 'type' => 'file'));
$mapping = array('/dev/sdb2' => array('8:18'), '/swapfile' => array('8:17'));
check_swap_backing(edm_synthetic_swap_backing_unambiguous($swaps, $mapping),
    'Accept explicit unique synthetic backing identities');
check_swap_backing(edm_synthetic_swap_backing_unambiguous(array(), array()),
    'Empty fixture is structurally valid but proves no completeness');
foreach (array(
    array('/dev/sdb2' => array('8:18')),
    array('/dev/sdb2' => array('8:18'), '/swapfile' => array()),
    array('/dev/sdb2' => array('8:18'), '/swapfile' => array('8:17', '8:18')),
    array('/dev/sdb2' => array('8:18'), '/swapfile' => array('08:17')),
    array('/dev/sdb2' => array('8:18'), '/swapfile' => array('8:17'), '/extra' => array('8:19')),
) as $invalid) {
    check_swap_backing(!edm_synthetic_swap_backing_unambiguous($swaps, $invalid),
        'Reject missing ambiguous malformed or extra synthetic backing');
}
check_swap_backing(!edm_synthetic_swap_backing_unambiguous(
    array($swaps[0], $swaps[0]), array('/dev/sdb2' => array('8:18'))),
    'Reject duplicate swap path');
check_swap_backing(!edm_synthetic_swap_backing_unambiguous(
    array(array('path' => '/swapfile', 'type' => 'unknown')),
    array('/swapfile' => array('8:17'))),
    'Reject unknown swap type');
