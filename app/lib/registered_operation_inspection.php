<?php
/**
 * Non-executing operation inspection with registry ownership and live-state
 * checks under the same disk lock. Not an HTTP endpoint or executor.
 * Caller must separately enforce authentication, HTTPS and CSRF.
 */
require_once __DIR__ . '/privileged_operation_request.php';
require_once __DIR__ . '/verified_target_state.php';
require_once __DIR__ . '/luks_registry_authorization.php';

function edm_with_registered_operation_inspection($dataDirectory, $input, $inspect,
    $discoverUsb = null, $discoverMappers = null, $readMounts = null)
{
    $request = edm_privileged_operation_request($input);
    if ($request === null || !is_callable($inspect)
        || !is_string($dataDirectory) || $dataDirectory === '') return false;

    return edm_with_verified_target_state($dataDirectory, $request['luks_uuid'],
        function ($state) use ($dataDirectory, $request, $inspect) {
            // This callback runs inside the shared disk-operation lock.
            $registry = edm_registry_load($dataDirectory . '/disks.json');
            $expectedMapper = edm_registry_mapper_name($request['luks_uuid']);
            $requestedMapper = $request['action'] === 'unlock'
                ? $expectedMapper : null;
            if (!edm_luks_registry_authorization($registry,
                $request['luks_uuid'], $request['action'], $state,
                $requestedMapper)) return false;
            return call_user_func($inspect, $request, $state);
        }, $discoverUsb, $discoverMappers, $readMounts);
}
