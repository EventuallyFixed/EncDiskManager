<?php
require_once __DIR__ . '/../../lib/inventory.php';
require_once __DIR__ . '/../../lib/inventory_payload.php';
function check_payload($condition, $label) {
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$registration = array('luks_uuid' => $uuid, 'name' => 'Backup',
    'mount_folder' => 'backup', 'mapper_name' => 'edm_backup');
$device = array('name' => 'sdb1', 'path' => '/dev/sdb1', 'type' => 'crypto_LUKS',
    'uuid' => $uuid, 'luks' => true, 'status' => 'Encrypted — Locked');
$disconnected = edm_inventory_payload(edm_inventory_rows(array($registration), array()));
check_payload(count($disconnected) === 1 && $disconnected[0]['connection'] === 'disconnected',
    'Registered disk remains visible when disconnected');
check_payload($disconnected[0]['key'] === 'registered:' . $uuid,
    'Disconnected registration uses UUID key');
$connected = edm_inventory_payload(edm_inventory_rows(array($registration), array($device)));
check_payload($connected[0]['key'] === $disconnected[0]['key']
    && $connected[0]['connection'] === 'connected',
    'Connection transition preserves row identity');
$unregistered = edm_inventory_payload(edm_inventory_rows(array(), array($device)));
check_payload($unregistered[0]['key'] === 'device:sdb1' && !$unregistered[0]['registered'],
    'Unregistered disk uses kernel device key');
check_payload(edm_inventory_payload(array()) === array(), 'Empty inventory is valid');
$failed = false;
try {
    $raw = edm_inventory_rows(array($registration), array($device));
    edm_inventory_payload(array_merge($raw, $raw));
} catch (RuntimeException $e) { $failed = true; }
check_payload($failed, 'Duplicate row keys are rejected');

check_payload($connected[0]['actions']['unlock'] === true
    && $connected[0]['actions']['mount'] === false,
    'Locked registered disk has advisory unlock eligibility');
check_payload(!array_filter($disconnected[0]['actions']),
    'Disconnected registration has no advisory actions');
check_payload(!array_filter($unregistered[0]['actions']),
    'Unregistered disk has no advisory actions');
$ambiguousRow = edm_inventory_rows(array($registration), array($device, $device));
$ambiguousPayload = edm_inventory_payload($ambiguousRow);
check_payload(!array_filter($ambiguousPayload[0]['actions']),
    'Ambiguous duplicate UUID has no advisory actions');

check_payload($connected[0]['actions']['encrypt'] === false,
    'Registered disk never previews destructive encryption');
$inconsistent = $device;
$inconsistent['status'] = 'Mounted';
$inconsistentRows = edm_inventory_payload(edm_inventory_rows(array($registration), array($inconsistent)));
check_payload(!array_filter($inconsistentRows[0]['actions']),
    'Contradictory LUKS type and status fails closed');
$unknown = $device;
$unknown['status'] = 'State unknown';
$unknownRows = edm_inventory_payload(edm_inventory_rows(array($registration), array($unknown)));
check_payload(!array_filter($unknownRows[0]['actions']),
    'Unknown live state never previews operations');
