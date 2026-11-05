<?php
require_once __DIR__ . '/../../lib/configuration_request.php';

function check($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
    echo 'PASS: ' . $message . "\n";
}
function rejects($input, $message)
{
    try {
        edm_configuration_request($input);
    } catch (RuntimeException $exception) {
        check(true, $message);
        return;
    }
    check(false, $message);
}
$uuid = '12345678-1234-1234-1234-123456789ABC';
$edit = array('action' => 'edit', 'luks_uuid' => $uuid, 'name' => 'Backup Disk', 'mount_folder' => 'backup_2');
$result = edm_configuration_request($edit);
check($result['luks_uuid'] === strtolower($uuid) && $result['mount_folder'] === 'backup_2', 'Valid edit and UUID normalisation');
rejects(array_merge($edit, array('name' => '')), 'Empty name rejected');
rejects(array_merge($edit, array('name' => str_repeat('a', 161))), 'Overlong name rejected');
rejects(array_merge($edit, array('name' => "Bad\nName")), 'Control characters rejected');
rejects(array_merge($edit, array('mount_folder' => '../etc')), 'Path traversal rejected');
rejects(array_merge($edit, array('mount_folder' => str_repeat('a', 49))), 'Overlong mount folder rejected');
rejects(array_merge($edit, array('unexpected' => 'value')), 'Unexpected field rejected');
$delete = array('action' => 'delete', 'luks_uuid' => $uuid, 'confirmation' => 'DELETE ' . strtolower($uuid));
check(edm_configuration_request($delete)['action'] === 'delete', 'Exact UUID-specific deletion confirmation accepted');
rejects(array_merge($delete, array('confirmation' => 'DELETE')), 'Generic deletion confirmation rejected');
rejects(array_merge($delete, array('name' => 'Other')), 'Edit fields rejected on deletion');
rejects(array_merge($edit, array('confirmation' => 'DELETE ' . strtolower($uuid))), 'Deletion confirmation rejected on edit');
rejects(array_merge($edit, array('luks_uuid' => 'invalid')), 'Malformed UUID rejected');
