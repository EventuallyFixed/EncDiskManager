<?php
require_once __DIR__ . '/../../lib/fixture_mount_swap.php';
function assert_fixture_mount_swap($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$empty = array(
    'mounts' => array('readable' => true, 'complete' => true, 'devices' => array()),
    'swaps' => array('readable' => true, 'complete' => true, 'devices' => array())
);
$devices = array('sdb', 'sdb1');
assert_fixture_mount_swap(edm_fixture_mount_swap_clear($empty, $devices),
    'Accept explicit complete empty synthetic mount and swap reports');
foreach (array('mounts', 'swaps') as $source) {
    $r = $empty; $r[$source]['devices'] = array('sdb1');
    assert_fixture_mount_swap(!edm_fixture_mount_swap_clear($r, $devices),
        'Reject dependency in ' . $source);
    $r = $empty; $r[$source]['devices'] = array('unrelated');
    assert_fixture_mount_swap(!edm_fixture_mount_swap_clear($r, $devices),
        'Reject unrelated dependency without ancestry evidence in ' . $source);
    $r = $empty; $r[$source]['readable'] = false;
    assert_fixture_mount_swap(!edm_fixture_mount_swap_clear($r, $devices),
        'Reject unreadable ' . $source);
    $r = $empty; $r[$source]['complete'] = false;
    assert_fixture_mount_swap(!edm_fixture_mount_swap_clear($r, $devices),
        'Reject incomplete ' . $source);
    $r = $empty; unset($r[$source]);
    assert_fixture_mount_swap(!edm_fixture_mount_swap_clear($r, $devices),
        'Reject missing ' . $source);
}
assert_fixture_mount_swap(!edm_fixture_mount_swap_clear($empty, array()),
    'Reject empty target inventory');
assert_fixture_mount_swap(!edm_fixture_mount_swap_clear($empty, array('sdb', 'sdb')),
    'Reject duplicate target inventory');
assert_fixture_mount_swap(!edm_fixture_mount_swap_clear($empty, array('../sdb')),
    'Reject invalid target identity');
