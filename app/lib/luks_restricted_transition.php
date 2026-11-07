<?php
/**
 * Restricted internal Unlock/Lock runner, NOT an HTTP operation endpoint.
 *
 * Registration membership, mapper-name ownership, privilege isolation and
 * secret acquisition must be verified by the future trusted caller. Never
 * invoke directly from client-controlled input. False does not imply that
 * the underlying device was unchanged; do not automatically retry.
 */
require_once __DIR__ . '/locked_luks_transition.php';
require_once __DIR__ . '/luks_subprocess.php';
require_once __DIR__ . '/luks_secret_input.php';
require_once __DIR__ . '/luks_registry_authorization.php';

function edm_restricted_luks_transition($dataDirectory, $registryPath, $uuid, $action,
    $expectedMapper, $secret = null, $timeoutSeconds = 30)
{
    if (!in_array($action, array('unlock', 'lock'), true)
        || !is_int($timeoutSeconds) || $timeoutSeconds < 1 || $timeoutSeconds > 120)
        return false;
    if ($action === 'unlock' && (!is_string($secret)
        || $secret === '' || strlen($secret) > 4096)) return false;
    if ($action === 'lock' && ($secret !== null || $expectedMapper !== null))
        return false;
    if (!is_string($registryPath) || $registryPath !== $dataDirectory . '/disks.json')
        return false;
    return edm_with_locked_luks_transition($dataDirectory, $uuid, $action,
        $expectedMapper,
        function ($plan, $state) use ($action, $secret, $timeoutSeconds,
            $registryPath, $uuid, $expectedMapper) {
            // This callback runs under the shared disk-operation lock and
            // after the immediate authoritative identity re-probe.
            try {
                $registry = edm_registry_load($registryPath);
            } catch (Throwable $e) { return false; }
            if (!edm_luks_registry_authorization($registry, $uuid,
                $action, $state, $expectedMapper)) return false;
            if ($action === 'unlock') {
                $input = edm_luks_stdin_command($plan);
                return $input !== null
                    && edm_luks_subprocess($input['argv'], $secret, $timeoutSeconds);
            }
            return edm_luks_subprocess($plan['argv'], null, $timeoutSeconds);
        });
}
