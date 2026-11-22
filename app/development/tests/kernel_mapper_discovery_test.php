<?php
require_once __DIR__ . '/../../lib/kernel_mapper_discovery.php';

function check_kernel($condition, $label)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
function make_dm($root, $device, $uuid, $name, $slave)
{
    mkdir($root . '/' . $device . '/dm', 0700, true);
    mkdir($root . '/' . $device . '/slaves', 0700, true);
    file_put_contents($root . '/' . $device . '/dev', '253:' . substr($device, 3));
    file_put_contents($root . '/' . $device . '/dm/uuid', $uuid);
    file_put_contents($root . '/' . $device . '/dm/name', $name);
    file_put_contents($root . '/' . $device . '/slaves/' . $slave, '');
}
$root = sys_get_temp_dir() . '/edm-mapper-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    $empty = edm_discover_kernel_mappers($root);
    check_kernel($empty['complete'] === true && count($empty['mappers']) === 0,
        'Empty mapper list is complete');
    make_dm($root, 'dm-0', 'CRYPT-LUKS1-12345678123412341234123456789abc-backup', 'backup', 'sdb1');
    $result = edm_discover_kernel_mappers($root);
    check_kernel($result['complete'] === true && count($result['mappers']) === 1,
        'Known LUKS mapper is discovered');
    check_kernel($result['mappers'][0]['source_uuid'] === '12345678-1234-1234-1234-123456789abc',
        'Source UUID comes from kernel mapper identity');
    check_kernel($result['mappers'][0]['dev_id'] === '253:0',
        'Mapper kernel major-minor identity recorded');
    check_kernel($result['mappers'][0]['parents'] === array('sdb1'),
        'Underlying block device is recorded');
    file_put_contents($root . '/dm-0/dev', '253:7');
    check_kernel(edm_discover_kernel_mappers($root)['mappers'][0]['dev_id'] === '253:7',
        'Changed mapper device number is observed on a fresh probe');
    file_put_contents($root . '/dm-0/dev', '253:0');
    make_dm($root, 'dm-1', 'CRYPT-LUKS1-abcdefabcdefabcdefabcdefabcdefab-backup', 'backup', 'sdc1');
    $duplicate = edm_discover_kernel_mappers($root);
    check_kernel($duplicate['complete'] === false,
        'Duplicate mapper names invalidate discovery');
    file_put_contents($root . '/dm-1/dm/name', 'other');
    file_put_contents($root . '/dm-1/dm/uuid', 'LVM-unknown');
    $result = edm_discover_kernel_mappers($root);
    check_kernel($result['complete'] === false,
        'Unknown mapper type fails closed');
} finally {
    foreach (array('dm-0', 'dm-1') as $dm) {
        foreach (array('dev', 'dm/uuid', 'dm/name', 'slaves/sdb1', 'slaves/sdc1') as $file) {
            @unlink($root . '/' . $dm . '/' . $file);
        }
        @rmdir($root . '/' . $dm . '/dm');
        @rmdir($root . '/' . $dm . '/slaves');
        @rmdir($root . '/' . $dm);
    }
    @rmdir($root);
}
