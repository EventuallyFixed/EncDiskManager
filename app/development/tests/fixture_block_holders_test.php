<?php
require_once __DIR__ . '/../../lib/fixture_block_holders.php';
function check_holders($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$root = sys_get_temp_dir() . '/edm-fixture-holders-' . bin2hex(random_bytes(8));
mkdir($root . '/class/block/sdb/holders', 0700, true);
mkdir($root . '/class/block/sdb1/holders', 0700, true);
try {
    check_holders(edm_fixture_block_holders_clear($root, array('sdb', 'sdb1')),
        'Accept explicit empty synthetic holder directories');
    file_put_contents($root . '/class/block/sdb1/holders/dm-0', '');
    check_holders(!edm_fixture_block_holders_clear($root, array('sdb', 'sdb1')),
        'Reject holder on partition');
    unlink($root . '/class/block/sdb1/holders/dm-0');
    rmdir($root . '/class/block/sdb1/holders');
    check_holders(!edm_fixture_block_holders_clear($root, array('sdb', 'sdb1')),
        'Reject missing partition holder directory');
    check_holders(!edm_fixture_block_holders_clear($root, array('sdb', 'sdb')),
        'Reject duplicate block identity');
    check_holders(!edm_fixture_block_holders_clear($root, array('sdb', '../sdb')),
        'Reject unsafe block identity');
    check_holders(!edm_fixture_block_holders_clear($root, array('sdb', 'missing')),
        'Reject missing block entry');
    // An external block directory must not be accepted even if holders is empty.
    $outside = sys_get_temp_dir() . '/edm-outside-holders-' . bin2hex(random_bytes(8));
    mkdir($outside . '/holders', 0700, true);
    symlink($outside, $root . '/class/block/outside');
    check_holders(!edm_fixture_block_holders_clear($root, array('outside')),
        'Reject block symlink escaping fixture root');
    unlink($root . '/class/block/outside');
    rmdir($outside . '/holders');
    rmdir($outside);
    // A holder directory symlink must not masquerade as an empty directory.
    mkdir($root . '/class/block/sdb1/actual-holders', 0700);
    symlink($root . '/class/block/sdb1/actual-holders', $root . '/class/block/sdb1/holders');
    check_holders(!edm_fixture_block_holders_clear($root, array('sdb1')),
        'Reject symlinked holder directory');
    unlink($root . '/class/block/sdb1/holders');
    rmdir($root . '/class/block/sdb1/actual-holders');
    check_holders(!edm_fixture_block_holders_clear($root, array()),
        'Reject empty block inventory');
} finally {
    @rmdir($root . '/class/block/sdb/holders');
    @rmdir($root . '/class/block/sdb1/holders');
    @rmdir($root . '/class/block/sdb');
    @rmdir($root . '/class/block/sdb1');
    @rmdir($root . '/class/block');
    @rmdir($root . '/class');
    @rmdir($root);
}
