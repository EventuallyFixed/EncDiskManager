<?php
/**
 * Strict, non-executing LUKS1 keyslot command plans.
 *
 * Recovery secrets must arrive via protected stdin; the new key may be
 * supplied by a validated private root-owned keyfile. Never place secret
 * bytes in argv.
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
            'argv' => array('/usr/local/modules/usr/sbin/cryptsetup', 'open', '--test-passphrase',
                '--type', 'luks1', '--key-file', '-', $device),
            'stdin' => 'secret',
            'requires_verified_usb_identity' => true
        );
    }
    // The new 64-byte key is supplied as an existing private root-owned
    // keyfile, while the recovery passphrase arrives on stdin. Never put
    // secret bytes into argv. This plan must be authorized under disk lock.
    return array(
        'action' => 'add-key',
        'argv' => array('/usr/local/modules/usr/sbin/cryptsetup', 'luksAddKey',
            '--type', 'luks1', '--key-file', '-', $device, '__PRIVATE_NEW_KEY_PATH__'),
        'stdin' => 'recovery-secret',
        'new_key_file_required' => true,
        'requires_verified_usb_identity' => true
    );
}
