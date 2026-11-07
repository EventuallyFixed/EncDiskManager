<?php
/**
 * Root-only Mount/Unmount coordinator. Not an HTTP endpoint.
 * A false result can follow a successful kernel operation if post-verification
 * fails; never automatically retry.
 */
require_once __DIR__ . '/verified_target_state.php';
require_once __DIR__ . '/verified_mount_transition.php';
require_once __DIR__ . '/mount_subprocess.php';

function edm_with_locked_mount_transition($dataDirectory, $registryPath,
    $uuid, $action, $timeoutSeconds = 30)
{
    if (!in_array($action, array('mount', 'unmount'), true)
        || !is_string($registryPath)
        || $registryPath !== $dataDirectory . '/disks.json'
        || !is_int($timeoutSeconds) || $timeoutSeconds < 1
        || $timeoutSeconds > 120) return false;
    return edm_with_disk_operation_lock($dataDirectory,
        function () use ($registryPath, $uuid, $action, $timeoutSeconds) {
            try {
                $registry = edm_registry_load($registryPath);
            } catch (Throwable $e) { return false; }
            $registration = null;
            foreach ($registry['disks'] as $disk) {
                if (strcasecmp($disk['luks_uuid'], $uuid) === 0) {
                    $registration = $disk;
                    break;
                }
            }
            if ($registration === null || $registration['enabled'] !== true)
                return false;
            $probe = function () use ($uuid) {
                return edm_verified_target_state_probe($uuid,
                    'edm_authoritative_usb_discovery',
                    'edm_discover_kernel_mappers',
                    function () {
                        $lines = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
                        return is_array($lines) ? $lines : null;
                    });
            };
            $readMounts = function () {
                $lines = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
                return is_array($lines) ? $lines : null;
            };
            $before = $probe();
            if ($before === null) return false;
            $mounts = $readMounts();
            if (!is_array($mounts)) return false;
            return edm_verified_mount_transition($action, $before,
                $registration, $mounts,
                function ($plan) use ($probe, $before, $timeoutSeconds) {
                    // Validate USB and mapper state immediately before exec.
                    if ($probe() !== $before) return false;
                    return edm_mount_subprocess($plan, $timeoutSeconds);
                }, $probe, $readMounts);
        });
}
