<?php
/**
 * Coordinate a single verified LUKS transition within the caller's lock.
 *
 * This is a fail-closed orchestration primitive, NOT a web endpoint. Callers
 * must hold the shared disk-operation lock, supply authoritative fresh-state
 * probes and a restricted runner. No secret values are accepted here.
 */
require_once __DIR__ . '/luks_execution_preflight.php';
require_once __DIR__ . '/luks_postcondition.php';

function edm_luks_verified_transition($action, $before, $expectedMapper,
    $execute, $probeAfter, $resolveBinary = null)
{
    if (!in_array($action, array('unlock', 'lock'), true)
        || !is_callable($execute) || !is_callable($probeAfter)) return false;
    if ($action === 'lock' && $expectedMapper !== null) return false;
    $plan = edm_luks_execution_preflight($action, $before, $expectedMapper,
        $resolveBinary);
    if ($plan === null) return false;
    try {
        // Execution callback is responsible for immediate identity checks,
        // secret delivery and calling only the restricted subprocess runner.
        $executed = call_user_func($execute, $plan, $before);
        if ($executed !== true) return false;
        $after = call_user_func($probeAfter);
        if (!edm_luks_postcondition($action, $before, $after, $expectedMapper))
            return false;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
