<?php
/**
 * Build a display-only inventory from registered disks and detected USB partitions.
 * Never use this view as authority for privileged disk operations.
 */
function edm_inventory_rows($registeredDisks, $detectedDevices)
{
    $rows = array();
    $matched = array();
    $ambiguous = array();
    foreach ($registeredDisks as $registration) {
        $uuid = strtolower($registration['luks_uuid']);
        $matches = array();
        foreach ($detectedDevices as $index => $device) {
            if (!empty($device['luks'])
                && isset($device['uuid'])
                && strtolower($device['uuid']) === $uuid) {
                $matches[] = $index;
            }
        }
        // Multiple devices reporting the same UUID are ambiguous: fail closed.
        if (count($matches) > 1) {
            foreach ($matches as $index) {
                $ambiguous[$index] = true;
            }
        }
        $attached = count($matches) === 1;
        if ($attached) {
            $matched[$matches[0]] = true;
        }
        $device = $attached ? $detectedDevices[$matches[0]] : null;
        $rows[] = array(
            'registered' => true,
            'name' => $registration['name'],
            'luks_uuid' => $registration['luks_uuid'],
            'mount_folder' => $registration['mount_folder'],
            'mapper_name' => $registration['mapper_name'],
            'attached' => $attached,
            'ambiguous' => count($matches) > 1,
            'device' => $device,
            'status' => $attached
                ? (isset($device['status']) ? $device['status'] : 'State unknown')
                : (count($matches) > 1 ? 'Ambiguous device identity' : 'Disconnected')
        );
    }
    foreach ($detectedDevices as $index => $device) {
        if (isset($matched[$index]) || isset($ambiguous[$index])) {
            continue;
        }
        $rows[] = array(
            'registered' => false,
            'name' => isset($device['label']) && $device['label'] !== ''
                ? $device['label'] : $device['name'],
            'luks_uuid' => !empty($device['luks']) ? $device['uuid'] : '',
            'mount_folder' => '',
            'mapper_name' => '',
            'attached' => true,
            'ambiguous' => false,
            'device' => $device,
            'status' => isset($device['status']) ? $device['status'] : 'State unknown'
        );
    }
    usort($rows, function ($a, $b) {
        $cmp = strcasecmp($a['name'], $b['name']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp($a['luks_uuid'], $b['luks_uuid']);
    });
    return $rows;
}
