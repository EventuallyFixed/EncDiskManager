<?php
require_once __DIR__ . '/../../lib/fixture_disk_dependencies.php';
function assert_fixture_dependencies($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$root = sys_get_temp_dir() . '/edm-fixture-deps-' . bin2hex(random_bytes(8));
mkdir($root . '/class/block', 0700, true);
mkdir($root . '/devices/usb1/sdb/sdb1/holders', 0700, true);
mkdir($root . '/devices/usb1/sdb/sdb1/slaves', 0700, true);
mkdir($root . '/devices/usb1/sdb/holders', 0700, true);
mkdir($root . '/devices/usb1/sdb/slaves', 0700, true);
symlink($root . '/devices/usb1/sdb', $root . '/class/block/sdb');
symlink($root . '/devices/usb1/sdb/sdb1', $root . '/class/block/sdb1');
file_put_contents($root . '/devices/usb1/sdb/sdb1/partition', "1\n");
$report = array(
    'mounts' => array('readable' => true, 'complete' => true, 'devices' => array()),
    'swaps' => array('readable' => true, 'complete' => true, 'devices' => array())
);
try {
    assert_fixture_dependencies(edm_fixture_disk_dependencies_clear($root, 'sdb', $report),
        'Complete synthetic partition holder mount and swap evidence');
    $r = $report; $r['mounts']['devices'][] = 'sdb1';
    assert_fixture_dependencies(!edm_fixture_disk_dependencies_clear($root, 'sdb', $r),
        'Reject mounted synthetic partition');
    $r = $report; $r['swaps']['devices'][] = 'sdb1';
    assert_fixture_dependencies(!edm_fixture_disk_dependencies_clear($root, 'sdb', $r),
        'Reject synthetic swap partition');
    $r = $report; $r['mounts']['complete'] = false;
    assert_fixture_dependencies(!edm_fixture_disk_dependencies_clear($root, 'sdb', $r),
        'Reject incomplete mount evidence');
    $r = $report; unset($r['swaps']);
    assert_fixture_dependencies(!edm_fixture_disk_dependencies_clear($root, 'sdb', $r),
        'Reject absent swap evidence');
    file_put_contents($root . '/devices/usb1/sdb/holders/dm-0', '');
    assert_fixture_dependencies(!edm_fixture_disk_dependencies_clear($root, 'sdb', $report),
        'Reject disk holder despite empty mount and swap reports');
    unlink($root . '/devices/usb1/sdb/holders/dm-0');
    file_put_contents($root . '/devices/usb1/sdb/sdb1/slaves/dm-1', '');
    assert_fixture_dependencies(!edm_fixture_disk_dependencies_clear($root, 'sdb', $report),
        'Reject partition slave despite empty mount swap and holders');
    unlink($root . '/devices/usb1/sdb/sdb1/slaves/dm-1');
    rmdir($root . '/devices/usb1/sdb/slaves');
    assert_fixture_dependencies(!edm_fixture_disk_dependencies_clear($root, 'sdb', $report),
        'Reject missing disk slaves directory');
    mkdir($root . '/devices/usb1/sdb/slaves', 0700);
    unlink($root . '/devices/usb1/sdb/sdb1/partition');
    assert_fixture_dependencies(!edm_fixture_disk_dependencies_clear($root, 'sdb', $report),
        'Reject incomplete partition tree despite empty dependency reports');
} finally {
    @unlink($root . '/class/block/sdb');
    @unlink($root . '/class/block/sdb1');
    @unlink($root . '/devices/usb1/sdb/sdb1/partition');
    @rmdir($root . '/devices/usb1/sdb/sdb1/holders');
    @rmdir($root . '/devices/usb1/sdb/sdb1/slaves');
    @rmdir($root . '/devices/usb1/sdb/holders');
    @rmdir($root . '/devices/usb1/sdb/slaves');
    @rmdir($root . '/devices/usb1/sdb/sdb1');
    @rmdir($root . '/devices/usb1/sdb');
    @rmdir($root . '/devices/usb1');
    @rmdir($root . '/devices');
    @rmdir($root . '/class/block');
    @rmdir($root . '/class');
    @rmdir($root);
}
