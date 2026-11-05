<?php
require_once __DIR__ . '/../../lib/registry_operations.php';
require_once __DIR__ . '/../../lib/inventory.php';

function check($condition, $description) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $description . "\n");
        exit(1);
    }
    echo "PASS: " . $description . "\n";
}
function rejects($fn, $description) {
    try {
        $fn();
    } catch (RuntimeException $exception) {
        check(true, $description);
        return;
    }
    check(false, $description);
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$entry = array(
    'luks_uuid' => $uuid,
    'name' => 'Archive',
    'mount_folder' => 'archive',
    'mapper_name' => edm_registry_mapper_name($uuid),
    'enabled' => true
);
$empty = edm_registry_empty();
$one = edm_registry_add_disk($empty, $entry);
check(count($one['disks']) === 1 && count($empty['disks']) === 0, 'Add does not mutate input');
rejects(function () use ($one, $entry) {
    edm_registry_add_disk($one, $entry);
}, 'Duplicate UUID rejected');
$updated = edm_registry_update_disk($one, strtoupper($uuid), 'Media', 'media');
check($updated['disks'][0]['name'] === 'Media' && $one['disks'][0]['name'] === 'Archive', 'Edit preserves original');
rejects(function () use ($one) {
    edm_registry_update_disk($one, '00000000-0000-0000-0000-000000000000', 'Other', 'other');
}, 'Edit missing UUID rejected');
$removed = edm_registry_remove_disk($one, strtoupper($uuid));
check(count($removed['disks']) === 0 && count($one['disks']) === 1, 'Remove does not mutate input');
rejects(function () use ($empty, $uuid) {
    edm_registry_remove_disk($empty, $uuid);
}, 'Remove missing UUID rejected');
$rows = edm_inventory_rows($one['disks'], array());
check(count($rows) === 1 && !$rows[0]['attached'] && $rows[0]['status'] === 'Disconnected', 'Disconnected registration remains visible');
$device = array('name' => 'sdb1', 'path' => '/dev/sdb1', 'uuid' => $uuid, 'luks' => true, 'status' => 'Encrypted — Locked');
$rows = edm_inventory_rows($one['disks'], array($device));
check(count($rows) === 1 && $rows[0]['attached'], 'Matching attached disk merged into registration');
$rows = edm_inventory_rows($one['disks'], array($device, $device));
check(count($rows) === 3 && !$rows[0]['attached'] && $rows[0]['ambiguous'], 'Duplicate UUID fails closed');
