<?php
require_once __DIR__ . '/../../lib/mapper_identity.php';

function check_mapper($actual, $expected, $label)
{
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: " . $label . "\n");
        exit(1);
    }
    echo "PASS: " . $label . "\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
check_mapper(edm_mapper_luks_uuid('CRYPT-LUKS1-12345678123412341234123456789abc-backup'),
    $uuid, 'LUKS1 crypt mapper UUID');
check_mapper(edm_mapper_luks_uuid('CRYPT-LUKS2-12345678123412341234123456789ABC-archive'),
    $uuid, 'LUKS2 crypt mapper UUID');
check_mapper(edm_mapper_luks_uuid('CRYPT-LUKS1-1234-backup'),
    null, 'Malformed crypt mapper UUID rejected');
check_mapper(edm_mapper_luks_uuid('LVM-abc123'),
    null, 'Non-crypt mapper identity rejected');
check_mapper(edm_mapper_luks_uuid(''),
    null, 'Empty mapper identity rejected');
check_mapper(edm_mapper_identities_complete(array(
    'CRYPT-LUKS1-12345678123412341234123456789abc-backup'
)), true, 'Known crypt mapper identities accepted');
check_mapper(edm_mapper_identities_complete(array('LVM-abc123')),
    false, 'Unknown mapper identities fail closed');
