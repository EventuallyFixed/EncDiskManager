<?php
/**
 * Read-only coordinated USB, mapper and mount verification.
 * This is not an operation executor or authorization.
 */
require_once __DIR__ . '/disk_operation_lock.php';
require_once __DIR__ . '/operation_target_identity.php';
require_once __DIR__ . '/operation_target_state.php';
require_once __DIR__ . '/authoritative_usb_discovery.php';
require_once __DIR__ . '/kernel_mapper_discovery.php';

function edm_verified_mountinfo_snapshot($lines)
{
    if (!is_array($lines)) return false;
    foreach ($lines as $line) {
        if (!is_string($line) || $line === '' || trim($line) !== $line) return false;
        $fields = explode(' ', $line);
        $separator = array_search('-', $fields, true);
        // mountinfo has six fixed fields, zero or more optional fields,
        // then a separator and at least three filesystem-specific fields.
        if ($separator === false || $separator < 6
            || count($fields) !== $separator + 4
            || !ctype_digit($fields[0]) || !ctype_digit($fields[1])
            || !preg_match('/^[0-9]+:[0-9]+$/D', $fields[2])) return false;
        foreach ($fields as $field) {
            if ($field === '') return false;
        }
    }
    return true;
}

function edm_verified_target_state_probe($uuid, $discoverUsb, $discoverMappers, $readMounts)
{
    if (!is_callable($discoverUsb) || !is_callable($discoverMappers)
        || !is_callable($readMounts)) return null;
    try {
        $firstUsb = call_user_func($discoverUsb);
        $bound = edm_operation_target_identity($uuid, $firstUsb);
        if ($bound === null) return null;
        $firstMappers = call_user_func($discoverMappers);
        $firstMounts = call_user_func($readMounts);
        // A missing or malformed mountinfo snapshot must not be interpreted
        // as "nothing mounted", even if no mapper appears in this snapshot.
        if (!edm_verified_mountinfo_snapshot($firstMounts)) return null;
        $first = edm_operation_target_state($bound, $firstUsb, $firstMappers, $firstMounts);
        if ($first === null) return null;
        $secondUsb = call_user_func($discoverUsb);
        if (!edm_operation_target_still_matches($bound, $secondUsb)) return null;
        $secondMappers = call_user_func($discoverMappers);
        $secondMounts = call_user_func($readMounts);
        if (!edm_verified_mountinfo_snapshot($secondMounts)) return null;
        $second = edm_operation_target_state($bound, $secondUsb, $secondMappers, $secondMounts);
        if ($second === null || $first !== $second
            || $firstMappers !== $secondMappers || $firstMounts !== $secondMounts) return null;
        return $second;
    } catch (Throwable $e) {
        return null;
    }
}

function edm_with_verified_target_state($dataDirectory, $uuid, $inspect,
    $discoverUsb = null, $discoverMappers = null, $readMounts = null)
{
    if (!is_callable($inspect)) throw new RuntimeException('Invalid inspection callback.');
    if ($discoverUsb === null) $discoverUsb = 'edm_authoritative_usb_discovery';
    if ($discoverMappers === null) $discoverMappers = 'edm_discover_kernel_mappers';
    if ($readMounts === null) {
        $readMounts = function () {
            $lines = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
            return is_array($lines) ? $lines : null;
        };
    }
    return edm_with_disk_operation_lock($dataDirectory,
        function () use ($uuid, $inspect, $discoverUsb, $discoverMappers, $readMounts) {
            $state = edm_verified_target_state_probe($uuid, $discoverUsb, $discoverMappers, $readMounts);
            if ($state === null) return false;
            try { return call_user_func($inspect, $state); }
            catch (Throwable $e) { return false; }
        });
}
