<?php
/**
 * Strict, non-executing LUKS1 keyslot command plans.
 *
 * Both secrets must be supplied via protected stdin pipes by a future
 * privileged executor; never place secrets or keyfile paths in argv.
 * These plans alone do not authorize changing any keyslot.
 */
function edm_luks_keyslot_plan($action, $deviceName, $mapperName = null)
{
    if (!in_array($action, array('verify-recovery', 'verify-new-key', 'add-key'), true)
        || !is_string($deviceName)
        || !preg_match('/^(sd[a-z]+[0-9]+|usb[a-zA-Z0-9._-]+)$/D', $deviceName))
        return null;
    $device = '/dev/' . $deviceName;
    if ($action === 'verify-recovery' || $action === 'verify-new-key') {
        return array(
            'action' => $action,
            'argv' => array('/usr/sbin/cryptsetup', 'open', '--test-passphrase',
                '--type', 'luks1', '--key-file', '-', $device),
            'stdin' => 'secret',
            'requires_verified_usb_identity' => true
        );
    }
    // luksAddKey needs two independent secret channels. A single pipe
    // cannot safely distinguish arbitrary binary keys, so reject until
    // an fd-based, audited executor is implemented.
    return null;
}
