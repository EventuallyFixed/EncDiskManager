<?php
/**
 * Strict, side-effect-free cryptsetup argument planning for Unlock and Lock.
 * No shell, subprocess, key material, or filesystem mutation occurs here.
 * A plan is NOT permission to execute it.
 */
function edm_luks_command_plan($action, $state, $mapperName = null)
{
    if (!is_array($state) || !isset($state['uuid'], $state['name'], $state['dev_id'])
        || !is_string($state['uuid'])
        || !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $state['uuid'])
        || !is_string($state['name'])
        || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $state['name'])
        || !is_string($state['dev_id'])
        || !preg_match('/^[0-9]+:[0-9]+$/D', $state['dev_id'])) return null;

    if ($action === 'unlock') {
        if (!is_string($mapperName)
            || !preg_match('/^edm_[a-zA-Z0-9_]{1,48}$/D', $mapperName)
            || !isset($state['mapper_open']) || $state['mapper_open'] !== false) return null;
        return array(
            'action' => 'unlock',
            'argv' => array('/usr/sbin/cryptsetup', 'luksOpen',
                '/dev/' . $state['name'], $mapperName),
            'target_uuid' => strtolower($state['uuid']),
            'target_dev_id' => $state['dev_id'],
            'expected_mapper' => $mapperName
        );
    }

    if ($action === 'lock') {
        if ($mapperName !== null
            || !isset($state['mapper_open'], $state['mapper_name'],
                $state['mapper_device'], $state['mapper_dev_id'], $state['mapper_mounted'])
            || $state['mapper_open'] !== true || $state['mapper_mounted'] !== false
            || !is_string($state['mapper_name'])
            || !preg_match('/^edm_[a-zA-Z0-9_]{1,48}$/D', $state['mapper_name'])
            || !is_string($state['mapper_device'])
            || !preg_match('/^dm-[0-9]+$/D', $state['mapper_device'])
            || !is_string($state['mapper_dev_id'])
            || !preg_match('/^[0-9]+:[0-9]+$/D', $state['mapper_dev_id'])) return null;
        return array(
            'action' => 'lock',
            'argv' => array('/usr/sbin/cryptsetup', 'luksClose', $state['mapper_name']),
            'target_uuid' => strtolower($state['uuid']),
            'target_dev_id' => $state['dev_id'],
            'mapper_dev_id' => $state['mapper_dev_id']
        );
    }
    return null;
}
