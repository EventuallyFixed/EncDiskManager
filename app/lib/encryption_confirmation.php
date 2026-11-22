<?php
/**
 * Validate the two independent confirmations for destructive encryption.
 * Pure policy only: NEVER formats, opens, or modifies a device.
 * Attempts are counted by the authenticated controller, not client input.
 */
function edm_encryption_confirmation($request, $expectedUuid, $attemptCount)
{
    if (!is_array($request) || !is_string($expectedUuid)
        || !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $expectedUuid)
        || !is_int($attemptCount) || $attemptCount < 0 || $attemptCount >= 3)
        return false;
    $keys = array_keys($request);
    sort($keys);
    if ($keys !== array('acknowledge_data_loss', 'confirmation', 'luks_uuid'))
        return false;
    if ($request['acknowledge_data_loss'] !== true
        || !is_string($request['luks_uuid'])
        || !hash_equals(strtolower($expectedUuid), strtolower($request['luks_uuid']))
        || !is_string($request['confirmation'])
        || !hash_equals('Are you sure? Your data will be permanently lost!',
            $request['confirmation'])) return false;
    return true;
}
