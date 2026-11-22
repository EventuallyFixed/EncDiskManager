<?php
/**
 * Non-executing preflight for Unlock/Lock.
 * Re-check the cryptsetup binary and reject any plan which differs from the
 * currently verified target. This does not eliminate external TOCTOU races.
 */
require_once __DIR__ . '/cryptsetup_binary.php';
require_once __DIR__ . '/operation_authorization.php';
require_once __DIR__ . '/luks_command_plan.php';

function edm_luks_execution_preflight($action, $state, $mapperName = null,
    $binaryResolver = null)
{
    if (!in_array($action, array('unlock', 'lock'), true)
        || !edm_operation_authorization($action, $state)) return null;
    if ($binaryResolver === null) $binaryResolver = 'edm_resolve_cryptsetup_binary';
    if (!is_callable($binaryResolver)) return null;
    try {
        $binary = call_user_func($binaryResolver);
        if ($binary !== '/usr/local/modules/usr/sbin/cryptsetup') return null;
        $plan = edm_luks_command_plan($action, $state, $mapperName);
        if ($plan === null) return null;
        $bound = edm_bind_cryptsetup_binary($plan, $binary);
        if ($bound === null || $bound['target_uuid'] !== strtolower($state['uuid'])
            || $bound['target_dev_id'] !== $state['dev_id']) return null;
        return $bound;
    } catch (Throwable $e) {
        return null;
    }
}
