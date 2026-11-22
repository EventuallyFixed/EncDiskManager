<?php
/**
 * Live, fail-closed registration checks for registry transactions.
 *
 * Must be invoked from inside edm_registry_transaction(), after obtaining
 * the shared disk-operation lock. The supplied device discovery callback
 * must enumerate ALL relevant block partitions and return
 * array('complete' => true, 'devices' => [...]). UI inventory snapshots
 * are not acceptable. This helper performs no writes.
 */
require_once __DIR__ . '/kernel_mapper_discovery.php';
require_once __DIR__ . '/registration_deletion_policy.php';
require_once __DIR__ . '/disk_inactivity.php';
require_once __DIR__ . '/authoritative_usb_discovery.php';
require_once __DIR__ . '/registration_snapshot_consistency.php';

function edm_live_registration_verifier($action, $discoverDevices, $discoverMappers = null)
{
    if (!in_array($action, array('edit', 'delete'), true)
        || !is_callable($discoverDevices)) {
        throw new RuntimeException('Invalid live registration verifier.');
    }
    if ($discoverMappers === null) {
        $discoverMappers = 'edm_discover_kernel_mappers';
    }
    if (!is_callable($discoverMappers)) {
        throw new RuntimeException('Invalid mapper discovery callback.');
    }
    return function ($uuid) use ($action, $discoverDevices, $discoverMappers) {
        try {
            $deviceSnapshot = call_user_func($discoverDevices);
            $mapperSnapshot = call_user_func($discoverMappers);
            if (!is_array($deviceSnapshot) || !is_array($mapperSnapshot)
                || !isset($deviceSnapshot['complete'], $deviceSnapshot['devices'])
                || !isset($mapperSnapshot['complete'], $mapperSnapshot['mappers'])
                || $deviceSnapshot['complete'] !== true
                || $mapperSnapshot['complete'] !== true
                || !is_array($deviceSnapshot['devices'])
                || !is_array($mapperSnapshot['mappers'])
                || !edm_registration_snapshots_consistent(
                    $deviceSnapshot['devices'], $mapperSnapshot['mappers'])) {
                return false;
            }
            if ($action === 'delete') {
                return edm_registration_deletion_allowed(
                    $uuid, $deviceSnapshot['devices'], $mapperSnapshot['mappers'], true
                );
            }
            // Edit must remain stricter than delete until disconnected edits
            // receive a separate, reviewed authorisation policy.
            return edm_disk_registration_inactive(
                $uuid, $deviceSnapshot['devices'], $mapperSnapshot['mappers']
            );
        } catch (Throwable $exception) {
            return false;
        }
    };
}

/**
 * Default callback wiring for read-only verification (not operation authorization).
 * Call the returned verifier only while holding the shared disk-operation lock.\n * Further mount-alias and mapper ancestry checks are needed before mutations.
 * No UI snapshot or client-provided device path is accepted.
 */
function edm_authoritative_registration_verifier($action)
{
    return edm_live_registration_verifier(
        $action, 'edm_authoritative_usb_discovery', 'edm_discover_kernel_mappers'
    );
}
