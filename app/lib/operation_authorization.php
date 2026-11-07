<?php
/**
 * Conservative authorization decision for non-destructive LUKS operations.
 * This is a pure policy function, NOT an executor. Caller must independently
 * verify registered UUID, kernel identity, mount state and hold the disk lock.
 */
function edm_operation_authorization($action, $state)
{
    if (!in_array($action, array('unlock', 'mount', 'unmount', 'lock'), true)
        || !is_array($state)) return false;
    foreach (array('uuid', 'name', 'dev_id', 'direct_mounted', 'mapper_open',
        'mapper_name', 'mapper_device', 'mapper_dev_id', 'mapper_mounted') as $key) {
        if (!array_key_exists($key, $state)) return false;
    }
    if (!is_string($state['uuid'])
        || !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $state['uuid'])
        || !is_string($state['name'])
        || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $state['name'])
        || !is_string($state['dev_id'])
        || !preg_match('/^[0-9]+:[0-9]+$/D', $state['dev_id'])
        || !is_bool($state['direct_mounted']) || !is_bool($state['mapper_open'])
        || $state['direct_mounted']) return false;

    if (!$state['mapper_open']) {
        if ($state['mapper_name'] !== null || $state['mapper_device'] !== null
            || $state['mapper_dev_id'] !== null || $state['mapper_mounted'] !== null)
            return false;
        // Mount of a locked device needs a separate unlock-and-mount workflow.
        return $action === 'unlock';
    }
    if (!is_string($state['mapper_name']) || $state['mapper_name'] === ''
        || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $state['mapper_name'])
        || !is_string($state['mapper_device'])
        || !preg_match('/^dm-[0-9]+$/D', $state['mapper_device'])
        || !is_string($state['mapper_dev_id'])
        || !preg_match('/^[0-9]+:[0-9]+$/D', $state['mapper_dev_id'])
        || !is_bool($state['mapper_mounted'])) return false;

    if ($state['mapper_mounted']) return $action === 'unmount';
    return $action === 'mount' || $action === 'lock';
}
