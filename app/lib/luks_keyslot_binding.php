<?php
/**
 * Bind a prevalidated keyslot-addition plan to a private keyfile.
 * This is pure command construction, not permission to execute.
 */
require_once __DIR__ . '/luks_keyslot_plan.php';
require_once __DIR__ . '/stored_key_policy.php';

function edm_luks_keyslot_bind_private_key($plan, $dataDirectory, $uuid)
{
    $policy = edm_stored_key_policy($dataDirectory, $uuid, false, false);
    if ($policy === null || !is_array($plan)
        || !isset($plan['action'], $plan['argv'], $plan['stdin'])
        || $plan['action'] !== 'add-key'
        || $plan['stdin'] !== 'recovery-secret'
        || !is_array($plan['argv']) || count($plan['argv']) !== 8
        || $plan['argv'][0] !== '/usr/sbin/cryptsetup'
        || $plan['argv'][1] !== 'luksAddKey'
        || $plan['argv'][2] !== '--type'
        || $plan['argv'][3] !== 'luks1'
        || $plan['argv'][4] !== '--key-file'
        || $plan['argv'][5] !== '-'
        || !is_string($plan['argv'][6])
        || !preg_match('#^/dev/[a-zA-Z0-9._+-]+$#D', $plan['argv'][6])
        || $plan['argv'][7] !== '__PRIVATE_NEW_KEY_PATH__')
        return null;
    $bound = $plan;
    $bound['argv'][7] = $policy['key_path'];
    return $bound;
}
