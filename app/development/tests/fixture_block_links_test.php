<?php
require_once __DIR__ . '/../../lib/fixture_block_links.php';
function check_links($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$root = sys_get_temp_dir() . '/edm-fixture-links-' . bin2hex(random_bytes(8));
foreach (array('sdb', 'sdb1') as $name) {
    mkdir($root . '/class/block/' . $name . '/holders', 0700, true);
    mkdir($root . '/class/block/' . $name . '/slaves', 0700, true);
}
$names = array('sdb', 'sdb1');
try {
    check_links(edm_fixture_block_links_clear($root, $names),
        'Accept explicitly empty holders and slaves');
    file_put_contents($root . '/class/block/sdb1/holders/dm-0', '');
    check_links(!edm_fixture_block_links_clear($root, $names),
        'Reject partition holder despite empty slaves');
    unlink($root . '/class/block/sdb1/holders/dm-0');
    file_put_contents($root . '/class/block/sdb/slaves/sdc', '');
    check_links(!edm_fixture_block_links_clear($root, $names),
        'Reject disk slave despite empty holders');
    unlink($root . '/class/block/sdb/slaves/sdc');
    rmdir($root . '/class/block/sdb1/slaves');
    check_links(!edm_fixture_block_links_clear($root, $names),
        'Reject missing partition slaves source');
    mkdir($root . '/class/block/sdb1/slaves');
    rmdir($root . '/class/block/sdb/holders');
    check_links(!edm_fixture_block_links_clear($root, $names),
        'Reject missing disk holders source');
    mkdir($root . '/class/block/sdb/holders');
    check_links(!edm_fixture_block_links_clear($root, array('sdb', 'sdb')),
        'Reject duplicate supplied node');
    check_links(!edm_fixture_block_links_clear($root, array('sdb', 'missing')),
        'Reject unknown supplied node');
} finally {
    foreach ($names as $name) {
        @rmdir($root . '/class/block/' . $name . '/holders');
        @rmdir($root . '/class/block/' . $name . '/slaves');
        @rmdir($root . '/class/block/' . $name);
    }
    @rmdir($root . '/class/block');
    @rmdir($root . '/class');
    @rmdir($root);
}
