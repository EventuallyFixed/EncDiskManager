<?php
/**
 * Root-only registry edit/delete entrypoint. Never accept registry paths
 * from HTTP input; caller supplies the fixed trusted private data directory.
 * Metadata-only: deletion never erases or closes a disk.
 */
require_once __DIR__ . '/configuration_request.php';
require_once __DIR__ . '/registry_transactions.php';
require_once __DIR__ . '/live_registration_verifier.php';

function edm_verified_registration_mutation($dataDirectory, $input)
{
    if (!is_string($dataDirectory) || $dataDirectory === ''
        || !is_array($input)) return false;
    $request = edm_configuration_request($input);
    $registryPath = $dataDirectory . '/disks.json';
    $verifier = edm_authoritative_registration_verifier($request['action']);
    if ($request['action'] === 'edit') {
        return edm_registry_change_details($registryPath,
            $request['luks_uuid'], $request['name'], $request['mount_folder'],
            $verifier);
    }
    if ($request['action'] === 'delete') {
        // Metadata deletion intentionally does not retire any stored key.
        // Callers must block deletion while a keyfile exists until explicit
        // key retirement has been implemented and confirmed.
        $keyPath = $dataDirectory . '/keys/' .
            str_replace('-', '', $request['luks_uuid']) . '.key';
        if (is_link($keyPath) || file_exists($keyPath))
            throw new RuntimeException('Stored key must be retired before deletion.');
        return edm_registry_delete_registration($registryPath,
            $request['luks_uuid'], $verifier);
    }
    return false;
}
