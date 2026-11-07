<?php
require_once __DIR__ . '/../../lib/registration_snapshot_consistency.php';
function check_consistency($condition, $label) {
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$other = 'abcdefab-cdef-abcd-efab-cdefabcdefab';
$disk = array('name' => 'sdb1', 'uuid' => $uuid, 'luks' => true);
$mapper = array('name' => 'edm_backup', 'source_uuid' => $uuid, 'parents' => array('sdb1'));
check_consistency(edm_registration_snapshots_consistent(array($disk), array($mapper)),
    'Matching USB partition and mapper identity accepted');
check_consistency(edm_registration_snapshots_consistent(array($disk), array()) === true,
    'Locked LUKS partition without mapper accepted');
check_consistency(!edm_registration_snapshots_consistent(array($disk), array_merge(array($mapper), array($mapper))),
    'Duplicate mapper name rejected');
check_consistency(!edm_registration_snapshots_consistent(array($disk, $disk), array()),
    'Duplicate partition identity rejected');
$wrongUuid = $mapper;
$wrongUuid['source_uuid'] = $other;
check_consistency(!edm_registration_snapshots_consistent(array($disk), array($wrongUuid)),
    'Mapper with mismatched source UUID rejected');
$wrongParent = $mapper;
$wrongParent['parents'] = array('sdc1');
check_consistency(!edm_registration_snapshots_consistent(array($disk), array($wrongParent)),
    'Mapper claiming USB UUID without matching parent rejected');
check_consistency(edm_registration_snapshots_consistent(array(), array($mapper)),
    'Unrelated mapper without USB partition is not mistaken for a match');

$duplicateSource = $mapper;
$duplicateSource['name'] = 'another_mapper';
check_consistency(!edm_registration_snapshots_consistent(array($disk), array($mapper, $duplicateSource)),
    'Two mappers claiming same LUKS UUID are ambiguous');
$otherSource = $mapper;
$otherSource['name'] = 'another_mapper';
$otherSource['source_uuid'] = $other;
check_consistency(!edm_registration_snapshots_consistent(array($disk), array($mapper, $otherSource)),
    'Two mappers sharing USB parent with different UUIDs rejected');
$unrelated = $mapper;
$unrelated['name'] = 'unrelated_mapper';
$unrelated['source_uuid'] = $other;
$unrelated['parents'] = array('sdc1');
check_consistency(edm_registration_snapshots_consistent(array($disk), array($mapper, $unrelated)),
    'Distinct unrelated mapper does not block consistent snapshot');
