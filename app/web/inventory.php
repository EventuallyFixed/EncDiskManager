<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/inventory.php';
require_once __DIR__ . '/../lib/inventory_payload.php';
require_once __DIR__ . '/../lib/usb_discovery.php';
require_once __DIR__ . '/../lib/whole_usb_disk_discovery.php';
require_once __DIR__ . '/../lib/whole_usb_disk_stable_candidate.php';
require_once __DIR__ . '/../lib/whole_usb_inventory_group.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
try {
    edm_auth_start_session();
    edm_auth_require_login();
    if (!edm_auth_is_https()) {
        http_response_code(403);
        throw new RuntimeException('HTTPS is required.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        throw new RuntimeException('Only GET is supported.');
    }
    $snapshot = edm_discover_usb_inventory();
    if (!is_array($snapshot['sysEntries'])) {
        throw new RuntimeException('Block device discovery failed.');
    }
    $registry = edm_registry_load(edm_auth_data_path() . '/disks.json');
    $rows = edm_inventory_rows($registry['disks'], $snapshot['devices']);
    $result = edm_inventory_payload($rows);
    $whole = edm_discover_whole_usb_disks();
    $grouped = edm_group_usb_inventory($rows, $whole);
    $wholeRows = array();
    $partitionParents = array();
    if ($grouped !== false) {
        $second = edm_discover_whole_usb_disks();
        foreach ($grouped['connected'] as $group) {
            $disk = $group['disk'];
            foreach ($group['partitions'] as $partitionRow) {
                $partitionParents[$partitionRow['device']['name']] = $disk['name'];
            }
            $ready = edm_whole_usb_disk_stable_candidate($whole, $second, $disk['name']) !== false;
            $wholeRows[] = array(
                'name' => $disk['name'],
                'path' => '/dev/' . $disk['name'],
                'size_bytes' => $disk['size_bytes'],
                'partitions' => $disk['partitions'],
                'mounted' => $disk['mounted'],
                'busy' => $disk['mounted'] || $disk['has_holders'] || $disk['has_slaves'],
                'read_only' => $disk['read_only'],
                'can_encrypt' => $ready
            );
        }
    }
    // Preserve the original row contract while identifying its parent disk.
    foreach ($result as &$item) {
        $deviceName = substr($item['device'], 5);
        $item['parent_disk'] = $item['connection'] === 'connected'
            && isset($partitionParents[$deviceName])
            ? $partitionParents[$deviceName] : '';
    }
    unset($item);
    echo json_encode(array('rows' => $result,
        'whole_disks' => $wholeRows,
        'whole_disks_complete' => $grouped !== false), JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('EncDiskManager inventory poll failed: ' . $exception->getMessage());
    if (http_response_code() < 400) http_response_code(503);
    echo json_encode(array('error' => 'Inventory temporarily unavailable.'));
}
