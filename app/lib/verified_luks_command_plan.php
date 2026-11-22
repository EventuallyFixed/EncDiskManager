<?php
/**
 * Generate non-executable Unlock/Lock plans only within the verified
 * disk-operation lock and live-state authorization gate.
 *
 * Plans are ephemeral inspection results, not execution permissions. The
 * caller must not cache or execute them after releasing the lock.
 */
require_once __DIR__ . '/verified_operation_authorization.php';
require_once __DIR__ . '/luks_command_plan.php';

function edm_with_verified_luks_command_plan($dataDirectory, $uuid, $action,
    $inspect, $mapperName = null, $discoverUsb = null,
    $discoverMappers = null, $readMounts = null)
{
    if (!in_array($action, array('unlock', 'lock'), true)) return false;
    if (!is_callable($inspect)) {
        throw new RuntimeException('Invalid command plan inspection callback.');
    }
    if ($action === 'lock' && $mapperName !== null) return false;
    return edm_with_verified_operation_authorization($dataDirectory, $uuid, $action,
        function ($state) use ($action, $mapperName, $inspect) {
            $plan = edm_luks_command_plan($action, $state, $mapperName);
            if ($plan === null || $plan['target_uuid'] !== strtolower($state['uuid'])
                || $plan['target_dev_id'] !== $state['dev_id']) return false;
            return call_user_func($inspect, $plan, $state);
        }, $discoverUsb, $discoverMappers, $readMounts);
}
