<?php
require_once __DIR__ . '/../../lib/fixture_block_slaves.php';
function check_slaves($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$root = sys_get_temp_dir() . '/edm-fixture-slaves-' . bin2hex(random_bytes(8));
mkdir($root . '/class/block/sdb/slaves', 0700, true);
mkdir($root . '/class/block/sdb1/slaves', 0700, true);
try {
    check_slaves(edm_fixture_block_slaves_clear($root, array('sdb', 'sdb1')),
        'Accept explicitly empty synthetic slaves directories');
    file_put_contents($root . '/class/block/sdb1/slaves/dm-0', '');
    check_slaves(!edm_fixture_block_slaves_clear($root, array('sdb', 'sdb1')),
        'Reject slave on partition');
    unlink($root . '/class/block/sdb1/slaves/dm-0');
    rmdir($root . '/class/block/sdb1/slaves');
    check_slaves(!edm_fixture_block_slaves_clear($root, array('sdb1')),
        'Reject missing slaves directory');
    check_slaves(!edm_fixture_block_slaves_clear($root, array('sdb', 'sdb')),
        'Reject duplicate block names');
    check_slaves(!edm_fixture_block_slaves_clear($root, array('../sdb')),
        'Reject unsafe block name');
    check_slaves(!edm_fixture_block_slaves_clear($root, array('missing')),
        'Reject absent block entry');
    $outside = sys_get_temp_dir() . '/edm-outside-slaves-' . bin2hex(random_bytes(8));
    mkdir($outside . '/slaves', 0700, true);
    symlink($outside, $root . '/class/block/outside');
    check_slaves(!edm_fixture_block_slaves_clear($root, array('outside')),
        'Reject block symlink escaping fixture root');
    unlink($root . '/class/block/outside');
    rmdir($outside . '/slaves');
    rmdir($outside);
    mkdir($root . '/class/block/sdb1/actual-slaves', 0700);
    symlink($root . '/class/block/sdb1/actual-slaves', $root . '/class/block/sdb1/slaves');
    check_slaves(!edm_fixture_block_slaves_clear($root, array('sdb1')),
        'Reject symlinked slaves directory');
    unlink($root . '/class/block/sdb1/slaves');
    rmdir($root . '/class/block/sdb1/actual-slaves');
    check_slaves(!edm_fixture_block_slaves_clear($root, array()),
        'Reject empty block inventory');
} finally {
    @rmdir($root . '/class/block/sdb/slaves');
    @rmdir($root . '/class/block/sdb1/slaves');
    @rmdir($root . '/class/block/sdb');
    @rmdir($root . '/class/block/sdb1');
    @rmdir($root . '/class/block');
    @rmdir($root . '/class');
    @rmdir($root);
}
