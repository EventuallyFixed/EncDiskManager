<?php
/**
 * Fail-closed registry membership and mapper ownership gate.
 * Registry must be loaded from trusted root-owned storage while holding
 * the shared disk-operation lock. No browser-supplied registration is trusted.
 */
require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/operation_authorization.php';

function edm_luks_registry_authorization($registry, $uuid, $action, $state,
    $requestedMapper = null)
{
    try {
        edm_registry_validate($registry);
    } catch (Throwable $e) {
        return false;
    }
    if (!is_string($uuid) || !is_array($state)
        || !isset($state['uuid']) || !is_string($state['uuid'])
        || strcasecmp($uuid, $state['uuid']) !== 0
        || !edm_operation_authorization($action, $state)) return false;

    $matches = array();
    foreach ($registry['disks'] as $disk) {
        if (strcasecmp($disk['luks_uuid'], $uuid) === 0) $matches[] = $disk;
    }
    if (count($matches) !== 1 || $matches[0]['enabled'] !== true) return false;
    $mapper = $matches[0]['mapper_name'];
    if ($action === 'unlock') {
        return is_string($requestedMapper) && $requestedMapper === $mapper;
    }
    if ($requestedMapper !== null) return false;
    return $state['mapper_open'] === true
        && $state['mapper_name'] === $mapper;
}
