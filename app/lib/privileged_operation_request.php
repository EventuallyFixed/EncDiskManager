<?php
/**
 * Parse an untrusted request for a future privileged disk-operation boundary.
 * This is validation only: no authentication, registry access or execution.
 * Never accept device paths, mapper names, mountpoints or command arguments.
 */
function edm_privileged_operation_request($input)
{
    if (!is_array($input) || count($input) !== 2
        || !array_key_exists('action', $input)
        || !array_key_exists('luks_uuid', $input)
        || !is_string($input['action'])
        || !is_string($input['luks_uuid'])
        || !in_array($input['action'], array('unlock', 'mount', 'unmount', 'lock'), true)
        || !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',
            $input['luks_uuid'])) return null;
    return array('action' => $input['action'],
        'luks_uuid' => strtolower($input['luks_uuid']));
}
