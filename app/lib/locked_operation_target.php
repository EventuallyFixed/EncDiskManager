<?php
/**
 * Coordinate read-only target identification with registry transactions.
 * This is deliberately NOT an executor or disk-operation authorization.
 */
require_once __DIR__ . '/disk_operation_lock.php';
require_once __DIR__ . '/operation_target_identity.php';
require_once __DIR__ . '/authoritative_usb_discovery.php';

function edm_with_verified_operation_target($dataDirectory, $uuid, $inspect,
    $discover = null)
{
    if (!is_callable($inspect)) {
        throw new RuntimeException('Invalid target inspection callback.');
    }
    if ($discover === null) $discover = 'edm_authoritative_usb_discovery';
    if (!is_callable($discover)) {
        throw new RuntimeException('Invalid authoritative discovery callback.');
    }
    return edm_with_disk_operation_lock($dataDirectory, function () use ($uuid, $inspect, $discover) {
        try {
            $first = call_user_func($discover);
            $target = edm_operation_target_identity($uuid, $first);
            if ($target === null) return false;
            // A second independent probe must agree before returning identity
            // to the read-only inspector. Do not pass a client-supplied path.
            $second = call_user_func($discover);
            if (!edm_operation_target_still_matches($target, $second)) return false;
            return call_user_func($inspect, $target);
        } catch (Throwable $e) {
            return false;
        }
    });
}
