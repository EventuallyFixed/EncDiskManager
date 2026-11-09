<?php
require_once __DIR__ . '/trusted_operation_binary.php';
/**
 * Resolve a trusted, system-installed cryptsetup executable.
 * Canonical EX4100 module path only; no PATH lookup, symlinks or shell.
 * Resolution alone does not authorize execution.
 */
function edm_resolve_cryptsetup_binary($candidates = null)
{
    if ($candidates === null) {
        $candidates = array('/usr/local/modules/usr/sbin/cryptsetup');
    }
    if (!is_array($candidates) || !$candidates) return null;
    foreach ($candidates as $path) {
        if (!is_string($path) || $path !== '/usr/local/modules/usr/sbin/cryptsetup'
            || !edm_trusted_operation_binary($path)) continue;
        return $path;
    }
    return null;
}

/**
 * Replace only the fixed cryptsetup path in a previously verified plan.
 * The returned plan is still non-executable inspection data.
 */
function edm_bind_cryptsetup_binary($plan, $binary)
{
    if (!is_array($plan) || !isset($plan['action'], $plan['argv'])
        || !in_array($plan['action'], array('unlock', 'lock'), true)
        || !is_array($plan['argv']) || !isset($plan['argv'][0], $plan['argv'][1])
        || $plan['argv'][0] !== '/usr/local/modules/usr/sbin/cryptsetup'
        || !in_array($binary, array('/usr/local/modules/usr/sbin/cryptsetup'), true))
        return null;
    if (($plan['action'] === 'unlock' && $plan['argv'][1] !== 'luksOpen')
        || ($plan['action'] === 'lock' && $plan['argv'][1] !== 'luksClose'))
        return null;
    $bound = $plan;
    $bound['argv'][0] = $binary;
    return $bound;
}
