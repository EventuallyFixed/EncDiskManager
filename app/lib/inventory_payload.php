<?php
/**
 * Convert the registry-first inventory into a stable, JSON-safe row contract.
 * This function is pure and can be tested without a NAS or HTTP session.
 */
function edm_inventory_payload($rows)
{
    if (!is_array($rows)) {
        throw new RuntimeException('Invalid inventory rows.');
    }
    $result = array();
    $keys = array();
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['registered'], $row['name'], $row['status'])
            || !array_key_exists('device', $row)) {
            throw new RuntimeException('Incomplete inventory row.');
        }
        $device = $row['device'];
        $registered = $row['registered'] === true;
        if (!$registered && (!is_array($device) || empty($device['name']))) {
            throw new RuntimeException('Unregistered disk has no device identity.');
        }
        $uuid = $registered ? strtolower((string)$row['luks_uuid']) : '';
        if ($registered && !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $uuid)) {
            throw new RuntimeException('Invalid registered disk UUID.');
        }
        $key = $registered ? 'registered:' . $uuid : 'device:' . $device['name'];
        if (isset($keys[$key])) {
            throw new RuntimeException('Duplicate inventory row identity.');
        }
        $keys[$key] = true;
        $result[] = array(
            'key' => $key,
            'connection' => $device === null ? 'disconnected' : 'connected',
            'registered' => $registered,
            'name' => (string)$row['name'],
            'device' => $device === null ? '—' : (string)$device['path'],
            'status' => (string)$row['status'],
            'type' => $device === null ? '—' : ((string)$device['type'] ?: 'Unknown'),
            'uuid' => $registered ? $uuid : ((string)$row['luks_uuid'] ?: '—'),
            'mount' => $registered ? '/mnt/' . $row['mount_folder'] : '—',
            'folder' => $registered ? (string)$row['mount_folder'] : ''
        );
    }
    return $result;
}
