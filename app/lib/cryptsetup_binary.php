<?php
/**
 * Resolve a trusted, system-installed cryptsetup executable.
 * No PATH lookup, user-controlled override, symlinks or shell invocation.
 * Resolution alone does not authorize execution.
 */
function edm_resolve_cryptsetup_binary($candidates = null)
{
    if ($candidates === null) {
        $candidates = array('/usr/sbin/cryptsetup');
    }
    if (!is_array($candidates) || !$candidates) return null;
    foreach ($candidates as $path) {
        if (!is_string($path)
            || !in_array($path, array('/usr/sbin/cryptsetup'), true)
            || is_link($path) || !is_file($path) || !is_executable($path)) continue;
        $stat = @stat($path);
        if (!is_array($stat) || $stat['uid'] !== 0
            || ($stat['mode'] & 0022) !== 0
            || (($stat['mode'] & 0170000) !== 0100000)) continue;
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
        || $plan['argv'][0] !== '/usr/sbin/cryptsetup'
        || !in_array($binary, array('/usr/sbin/cryptsetup'), true))
        return null;
    if (($plan['action'] === 'unlock' && $plan['argv'][1] !== 'luksOpen')
        || ($plan['action'] === 'lock' && $plan['argv'][1] !== 'luksClose'))
        return null;
    $bound = $plan;
    $bound['argv'][0] = $binary;
    return $bound;
}
