<?php
require_once __DIR__ . '/../../lib/registration_snapshot_consistency.php';
function check_consistency($condition, $label) {
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$other = 'abcdefab-cdef-abcd-efab-cdefabcdefab';
$disk = array('name' => 'sdb1', 'uuid' => $uuid, 'luks' => true);
$mapper = array('name' => 'edm_backup', 'device' => 'dm-0', 'source_uuid' => $uuid, 'parents' => array('sdb1'));
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
$duplicateSource['device'] = 'dm-1';
check_consistency(!edm_registration_snapshots_consistent(array($disk), array($mapper, $duplicateSource)),
    'Two mappers claiming same LUKS UUID are ambiguous');
$otherSource = $mapper;
$otherSource['name'] = 'another_mapper';
$otherSource['device'] = 'dm-1';
$otherSource['source_uuid'] = $other;
check_consistency(!edm_registration_snapshots_consistent(array($disk), array($mapper, $otherSource)),
    'Two mappers sharing USB parent with different UUIDs rejected');
$unrelated = $mapper;
$unrelated['name'] = 'unrelated_mapper';
$unrelated['device'] = 'dm-1';
$unrelated['source_uuid'] = $other;
$unrelated['parents'] = array('sdc1');
check_consistency(edm_registration_snapshots_consistent(array($disk), array($mapper, $unrelated)),
    'Distinct unrelated mapper does not block consistent snapshot');

$nested = array('name' => 'outer', 'device' => 'dm-1',
    'source_uuid' => $other, 'parents' => array('dm-0'));
check_consistency(!edm_registration_snapshots_consistent(array($disk), array($mapper, $nested)),
    'Nested mapper above USB LUKS partition fails closed');
$missingIntermediate = $nested;
$missingIntermediate['parents'] = array('dm-9');
check_consistency(!edm_registration_snapshots_consistent(array($disk), array($mapper, $missingIntermediate)),
    'Missing intermediate mapper fails closed');

$cycleA = array('name' => 'cycle_a', 'device' => 'dm-2',
    'source_uuid' => '11111111-1111-1111-1111-111111111111', 'parents' => array('dm-3'));
$cycleB = array('name' => 'cycle_b', 'device' => 'dm-3',
    'source_uuid' => '22222222-2222-2222-2222-222222222222', 'parents' => array('dm-2'));
check_consistency(!edm_registration_snapshots_consistent(array(), array($cycleA, $cycleB)),
    'Cyclic mapper ancestry rejected even without a USB partition');
$selfCycle = $cycleA;
$selfCycle['parents'] = array('dm-2');
check_consistency(!edm_registration_snapshots_consistent(array(), array($selfCycle)),
    'Self-referential mapper ancestry rejected');
