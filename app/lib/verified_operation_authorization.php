<?php
/**
 * Read-only authorization gate for non-destructive LUKS operations.
 *
 * Authorization is checked only after double-probing USB, mapper and mount
 * state while holding the shared disk-operation lock. This does NOT execute
 * operations, authorize encryption, or guarantee external state stays fixed.
 */
require_once __DIR__ . '/verified_target_state.php';
require_once __DIR__ . '/operation_authorization.php';

function edm_with_verified_operation_authorization($dataDirectory, $uuid, $action,
    $inspect, $discoverUsb = null, $discoverMappers = null, $readMounts = null)
{
    if (!in_array($action, array('unlock', 'mount', 'unmount', 'lock'), true)) {
        return false;
    }
    if (!is_callable($inspect)) {
        throw new RuntimeException('Invalid authorized inspection callback.');
    }
    return edm_with_verified_target_state($dataDirectory, $uuid,
        function ($state) use ($action, $inspect) {
            if (!edm_operation_authorization($action, $state)) return false;
            return call_user_func($inspect, $state);
        }, $discoverUsb, $discoverMappers, $readMounts);
}
