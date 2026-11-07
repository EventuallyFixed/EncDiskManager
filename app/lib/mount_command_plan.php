<?php
/**
 * Side-effect-free mount/unmount planning. Does not invoke mount or umount.
 * Mount destinations are registry-owned folders under /mnt only.
 */
require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/operation_authorization.php';

function edm_mount_command_plan($action, $state, $registration)
{
    if (!in_array($action, array('mount', 'unmount'), true)
        || !edm_operation_authorization($action, $state)
        || !is_array($registration)
        || !isset($registration['luks_uuid'], $registration['mapper_name'],
            $registration['mount_folder'], $registration['enabled'])
        || $registration['enabled'] !== true
        || strcasecmp($registration['luks_uuid'], $state['uuid']) !== 0
        || !is_string($registration['mount_folder'])
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,47}$/D',
            $registration['mount_folder'])
        || !is_string($registration['mapper_name'])
        || $registration['mapper_name'] !== edm_registry_mapper_name($state['uuid'])
        || $state['mapper_name'] !== $registration['mapper_name']
        || !is_string($state['mapper_dev_id'])
        || !preg_match('/^[0-9]+:[0-9]+$/D', $state['mapper_dev_id']))
        return null;

    $destination = '/mnt/' . $registration['mount_folder'];
    if ($action === 'mount') {
        return array('action' => 'mount',
            'argv' => array('/bin/mount', '-t', 'ext4', '-o', 'nosuid,nodev',
                '/dev/mapper/' . $registration['mapper_name'], $destination),
            'target_uuid' => strtolower($state['uuid']),
            'mapper_dev_id' => $state['mapper_dev_id'],
            'destination' => $destination);
    }
    return array('action' => 'unmount',
        'argv' => array('/bin/umount', $destination),
        'target_uuid' => strtolower($state['uuid']),
        'mapper_dev_id' => $state['mapper_dev_id'],
        'destination' => $destination);
}
