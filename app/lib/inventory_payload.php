<?php
/**
 * Convert the registry-first inventory into a stable, JSON-safe row contract.
 * This function is pure and can be tested without a NAS or HTTP session.
 */
require_once __DIR__ . '/disk_actions.php';

/**
 * Advisory policy only. Never authorize an operation using this snapshot.
 */
function edm_inventory_action_preview($row)
{
    $none = array('unlock' => false, 'mount' => false,
        'unmount' => false, 'lock' => false, 'encrypt' => false);
    if (!empty($row['ambiguous']) || empty($row['device']) ||
        !is_array($row['device']) || empty($row['registered'])) {
        return $none;
    }
    $device = $row['device'];
    if (!isset($device['luks'], $device['status']) ||
        !is_bool($device['luks'])) {
        return $none;
    }
    $status = $device['status'];
    $known = in_array($status, array(
        'Encrypted — Locked', 'Encrypted — Unlocked',
        'Encrypted — Mounted', 'Mounted', 'Not identified as LUKS'
    ), true);
    if (!$known) return $none;
    // A status string must agree with the independently discovered LUKS type.
    // Never present encryption as eligible for an existing registration.
    if ($device['luks'] !== (strpos($status, 'Encrypted — ') === 0)) {
        return $none;
    }
    $open = in_array($status, array('Encrypted — Unlocked', 'Encrypted — Mounted'), true);
    $mounted = in_array($status, array('Encrypted — Mounted', 'Mounted'), true);
    $actions = edm_disk_action_policy($device['luks'], $open, $mounted, true);
    $actions['encrypt'] = false;
    return $actions;
}

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
            'folder' => $registered ? (string)$row['mount_folder'] : '',
            'actions' => edm_inventory_action_preview($row)
        );
    }
    return $result;
}
