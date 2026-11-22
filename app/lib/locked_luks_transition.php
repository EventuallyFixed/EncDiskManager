<?php
/**
 * Isolated Unlock/Lock transition under the shared operation lock.
 * NOT connected to HTTP, registry mutation or the app UI.
 *
 * The invoking privileged layer must independently establish registration,
 * supply secrets securely and restrict the callback to the approved runner.
 * A false result after execution may mean the disk changed but verification
 * failed; never retry automatically.
 */
require_once __DIR__ . '/verified_target_state.php';
require_once __DIR__ . '/luks_verified_transition.php';

function edm_with_locked_luks_transition($dataDirectory, $uuid, $action,
    $expectedMapper, $execute, $discoverUsb = null,
    $discoverMappers = null, $readMounts = null, $resolveBinary = null)
{
    if (!in_array($action, array('unlock', 'lock'), true)
        || !is_callable($execute)) return false;
    if ($discoverUsb === null) $discoverUsb = 'edm_authoritative_usb_discovery';
    if ($discoverMappers === null) $discoverMappers = 'edm_discover_kernel_mappers';
    if ($readMounts === null) {
        $readMounts = function () {
            $lines = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
            return is_array($lines) ? $lines : null;
        };
    }
    return edm_with_disk_operation_lock($dataDirectory,
        function () use ($uuid, $action, $expectedMapper, $execute,
            $discoverUsb, $discoverMappers, $readMounts, $resolveBinary) {
            $before = edm_verified_target_state_probe($uuid, $discoverUsb,
                $discoverMappers, $readMounts);
            if ($before === null) return false;
            return edm_luks_verified_transition($action, $before, $expectedMapper,
                function ($plan, $state) use ($execute, $uuid,
                    $discoverUsb, $discoverMappers, $readMounts) {
                    // Immediately re-probe before invoking the privileged
                    // callback, still holding the same operation lock.
                    $fresh = edm_verified_target_state_probe($uuid,
                        $discoverUsb, $discoverMappers, $readMounts);
                    if ($fresh === null || $fresh !== $state) return false;
                    return call_user_func($execute, $plan, $state);
                },
                function () use ($uuid, $discoverUsb, $discoverMappers, $readMounts) {
                    return edm_verified_target_state_probe($uuid,
                        $discoverUsb, $discoverMappers, $readMounts);
                }, $resolveBinary);
        });
}
