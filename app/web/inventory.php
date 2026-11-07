<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/inventory.php';
require_once __DIR__ . '/../lib/usb_discovery.php';
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
    $result = array();
    foreach ($rows as $row) {
        $device = $row['device'];
        $result[] = array(
            'key' => $row['registered'] ? 'registered:' . strtolower($row['luks_uuid'])
                : 'device:' . $device['name'],
            'connection' => $device === null ? 'disconnected' : 'connected',
            'registered' => (bool)$row['registered'],
            'name' => $row['name'],
            'device' => $device === null ? '—' : $device['path'],
            'status' => $row['status'],
            'type' => $device === null ? '—' : ($device['type'] ?: 'Unknown'),
            'uuid' => $row['luks_uuid'] ?: '—',
            'mount' => $row['registered'] ? '/mnt/' . $row['mount_folder'] : '—',
            'folder' => $row['registered'] ? $row['mount_folder'] : ''
        );
    }
    echo json_encode(array('rows' => $result), JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('EncDiskManager inventory poll failed: ' . $exception->getMessage());
    if (http_response_code() < 400) http_response_code(503);
    echo json_encode(array('error' => 'Inventory temporarily unavailable.'));
}
