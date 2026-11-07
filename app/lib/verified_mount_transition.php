<?php
/**
 * Restricted Mount/Unmount coordinator. Deliberately no subprocess execution:
 * executor is supplied by future privileged helper after ownership checks.
 * Caller must hold shared disk-operation lock throughout.
 */
require_once __DIR__ . '/mount_command_plan.php';
require_once __DIR__ . '/mountpoint_safety.php';
require_once __DIR__ . '/mountpoint_identity.php';

function edm_verified_mount_transition($action, $before, $registration,
    $beforeMountinfo, $execute, $probeAfter, $readMountinfo)
{
    if (!is_callable($execute) || !is_callable($probeAfter)
        || !is_callable($readMountinfo)) return false;
    $plan = edm_mount_command_plan($action, $before, $registration);
    if ($plan === null || !edm_mountpoint_safety($plan['destination'],
        $beforeMountinfo, $plan['mapper_dev_id'], $action === 'unmount'))
        return false;
    $mountpointIdentity = edm_mountpoint_identity($plan['destination']);
    if ($mountpointIdentity === null) return false;
    try {
        // Re-read immediately before invoking the privileged executor.
        $freshMountinfo = call_user_func($readMountinfo);
        if ($freshMountinfo !== $beforeMountinfo
            || !edm_mountpoint_safety($plan['destination'], $freshMountinfo,
                $plan['mapper_dev_id'], $action === 'unmount')) return false;
        if (!edm_mountpoint_identity_matches($plan['destination'], $mountpointIdentity))
            return false;
        if (call_user_func($execute, $plan) !== true) return false;
        $after = call_user_func($probeAfter);
        $afterMountinfo = call_user_func($readMountinfo);
        if (!is_array($after) || !isset($after['uuid'], $after['name'],
            $after['dev_id'], $after['mapper_dev_id'], $after['mapper_name'],
            $after['mapper_open'], $after['mapper_mounted'])
            || $after['uuid'] !== $before['uuid']
            || $after['name'] !== $before['name']
            || $after['dev_id'] !== $before['dev_id']
            || $after['mapper_dev_id'] !== $before['mapper_dev_id']
            || $after['mapper_name'] !== $before['mapper_name']
            || $after['mapper_open'] !== true
            || $after['mapper_mounted'] !== ($action === 'mount'))
            return false;
        return edm_mountpoint_identity_matches($plan['destination'], $mountpointIdentity)
            && edm_mountpoint_safety($plan['destination'], $afterMountinfo,
            $plan['mapper_dev_id'], $action === 'mount');
    } catch (Throwable $e) {
        return false;
    }
}
