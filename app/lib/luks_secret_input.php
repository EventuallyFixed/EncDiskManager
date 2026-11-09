<?php
/**
 * Side-effect-free construction of cryptsetup stdin secret-delivery arguments.
 *
 * The caller must launch argv directly (no shell), pipe the passphrase to
 * stdin, close the pipe, and clear transient memory where possible. Never
 * place the secret in argv, environment variables, logs, or registry JSON.
 * This module does not execute commands or receive secret values.
 */
function edm_luks_stdin_command($plan)
{
    if (!is_array($plan) || !isset($plan['action'], $plan['argv'])
        || $plan['action'] !== 'unlock' || !is_array($plan['argv'])
        || count($plan['argv']) !== 4
        || $plan['argv'][0] !== '/usr/local/modules/usr/sbin/cryptsetup'
        || $plan['argv'][1] !== 'luksOpen'
        || !is_string($plan['argv'][2])
        || !preg_match('#^/dev/[a-zA-Z0-9._+-]+$#D', $plan['argv'][2])
        || !is_string($plan['argv'][3])
        || !preg_match('/^edm_[a-zA-Z0-9_]{1,48}$/D', $plan['argv'][3]))
        return null;
    return array(
        'argv' => array('/usr/local/modules/usr/sbin/cryptsetup', 'luksOpen',
            '--key-file', '-', $plan['argv'][2], $plan['argv'][3]),
        'secret_channel' => 'stdin',
        'secret_in_argv' => false,
        'shell' => false
    );
}
