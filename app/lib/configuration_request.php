<?php
/**
 * Validate disk configuration form data only.
 * This function does not authenticate or authorise changes, check live disk
 * state, or write registry files.
 */
function edm_configuration_request($input)
{
    if (!is_array($input)) {
        throw new RuntimeException('Invalid configuration request.');
    }

    $allowed = array('action', 'luks_uuid', 'name', 'mount_folder', 'confirmation');
    foreach (array_keys($input) as $key) {
        if (!in_array($key, $allowed, true)) {
            throw new RuntimeException('Unexpected configuration field.');
        }
    }

    $action = isset($input['action']) ? $input['action'] : null;
    $uuid = isset($input['luks_uuid']) ? $input['luks_uuid'] : null;
    if (!is_string($uuid)
        || !preg_match('/^[a-fA-F0-9]{8}(-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}$/', $uuid)) {
        throw new RuntimeException('Invalid LUKS UUID.');
    }
    $uuid = strtolower($uuid);

    if ($action === 'edit') {
        $name = isset($input['name']) ? $input['name'] : null;
        $folder = isset($input['mount_folder']) ? $input['mount_folder'] : null;
        if (!is_string($name) || trim($name) === '' || strlen($name) > 160
            || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new RuntimeException('Invalid disk display name.');
        }
        if (!is_string($folder)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,47}$/', $folder)) {
            throw new RuntimeException('Invalid mount folder.');
        }
        if (array_key_exists('confirmation', $input)) {
            throw new RuntimeException('Unexpected deletion confirmation.');
        }
        return array(
            'action' => 'edit',
            'luks_uuid' => $uuid,
            'name' => $name,
            'mount_folder' => $folder
        );
    }

    if ($action === 'delete') {
        if (array_key_exists('name', $input) || array_key_exists('mount_folder', $input)) {
            throw new RuntimeException('Unexpected deletion fields.');
        }
        $confirmation = isset($input['confirmation']) ? $input['confirmation'] : null;
        if (!is_string($confirmation) || !hash_equals('DELETE ' . $uuid, $confirmation)) {
            throw new RuntimeException('Deletion confirmation must include the exact LUKS UUID.');
        }
        return array('action' => 'delete', 'luks_uuid' => $uuid);
    }

    throw new RuntimeException('Unsupported configuration action.');
}
