<?php
/**
 * Pure verification of Unlock/Lock transitions against authoritative states.
 * The caller must obtain both states under the same disk-operation lock.
 * This does not execute cryptsetup or replace fresh kernel discovery.
 */
require_once __DIR__ . '/operation_authorization.php';

function edm_luks_postcondition($action, $before, $after, $expectedMapper = null)
{
    if (!in_array($action, array('unlock', 'lock'), true)
        || !edm_operation_authorization($action, $before)
        || !is_array($after)) return false;
    foreach (array('uuid', 'name', 'dev_id', 'direct_mounted', 'mapper_open',
        'mapper_name', 'mapper_device', 'mapper_dev_id', 'mapper_mounted') as $key) {
        if (!array_key_exists($key, $after)) return false;
    }
    if ($after['uuid'] !== $before['uuid']
        || $after['name'] !== $before['name']
        || $after['dev_id'] !== $before['dev_id']
        || $after['direct_mounted'] !== false) return false;

    if ($action === 'unlock') {
        if (!is_string($expectedMapper)
            || !preg_match('/^edm_[a-zA-Z0-9_]{1,48}$/D', $expectedMapper))
            return false;
        return $before['mapper_open'] === false
            && $after['mapper_open'] === true
            && $after['mapper_name'] === $expectedMapper
            && is_string($after['mapper_device'])
            && preg_match('/^dm-[0-9]+$/D', $after['mapper_device']) === 1
            && is_string($after['mapper_dev_id'])
            && preg_match('/^[0-9]+:[0-9]+$/D', $after['mapper_dev_id']) === 1
            && $after['mapper_mounted'] === false;
    }

    return $expectedMapper === null
        && $before['mapper_open'] === true
        && $after['mapper_open'] === false
        && $after['mapper_name'] === null
        && $after['mapper_device'] === null
        && $after['mapper_dev_id'] === null
        && $after['mapper_mounted'] === null;
}
