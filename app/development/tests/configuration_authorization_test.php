<?php
require_once __DIR__ . '/../../lib/configuration_authorization.php';

function check_preflight($expected, $request, $registry, $label)
{
    $accepted = true;
    try {
        edm_configuration_registration_preflight($request, $registry);
    } catch (RuntimeException $exception) {
        $accepted = false;
    }
    if ($accepted !== $expected) {
        fwrite(STDERR, "FAIL: " . $label . "\n");
        exit(1);
    }
    echo "PASS: " . $label . "\n";
}
$one = '12345678-1234-1234-1234-123456789abc';
$two = '87654321-4321-4321-4321-cba987654321';
$registry = array('disks' => array(
    array('luks_uuid' => $one, 'name' => 'Backup', 'mount_folder' => 'backup'),
    array('luks_uuid' => $two, 'name' => 'Archive', 'mount_folder' => 'archive')
));
$edit = array('action' => 'edit', 'luks_uuid' => $one,
    'name' => 'Backup New', 'mount_folder' => 'backup_new');
$delete = array('action' => 'delete', 'luks_uuid' => $one);
check_preflight(true, $edit, $registry, 'Unique edit is accepted');
check_preflight(true, $delete, $registry, 'Existing registration deletion passes metadata preflight');
check_preflight(false, array_merge($edit, array('name' => 'Archive')), $registry,
    'Duplicate name rejected');
check_preflight(false, array_merge($edit, array('mount_folder' => 'archive')), $registry,
    'Duplicate mount folder rejected');
check_preflight(false, array_merge($edit, array('name' => 'ARCHIVE')), $registry,
    'Case-insensitive duplicate name rejected');
check_preflight(false, array('action' => 'delete', 'luks_uuid' => '00000000-0000-0000-0000-000000000000'),
    $registry, 'Missing registration rejected');
$duplicate = $registry;
$duplicate['disks'][] = $registry['disks'][0];
check_preflight(false, $delete, $duplicate, 'Ambiguous registration rejected');
