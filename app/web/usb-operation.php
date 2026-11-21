<?php
/**
 * Authenticated USB LUKS operations endpoint.
 * A fresh inventory is checked before invoking the root-owned helper.
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/privileged_web_authorization.php';
require_once __DIR__ . '/../lib/usb_operation_request.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Run each operation through the existing privileged helper. Secrets use stdin only.
function edm_run_usb_helper($helper, $action, $device, $mapper, $passphrase = null, $argument = null)
{
    $command = '/bin/sh ' . escapeshellarg($helper) . ' '
        . escapeshellarg($action) . ' ' . escapeshellarg($device)
        . ' ' . escapeshellarg($mapper);
    if ($argument !== null) $command .= ' ' . escapeshellarg($argument);
    // The endpoint does not return helper output. Discard it instead of
    // risking a blocked child while PHP waits on the other output pipe.
    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('file', '/dev/null', 'w'),
        2 => array('file', '/dev/null', 'w')
    );
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start operation.');
    try {
        if ($passphrase !== null) {
            // A pipe write can be short; cryptsetup must receive the entire key.
            $length = strlen($passphrase);
            $written = 0;
            while ($written < $length) {
                $count = fwrite($pipes[0], substr($passphrase, $written));
                if ($count === false || $count === 0) {
                    throw new RuntimeException('Could not send unlock passphrase.');
                }
                $written += $count;
            }
        }
        fclose($pipes[0]);
        $exit = proc_close($process);
    } finally {
        unset($passphrase);
        if (is_resource($pipes[0])) fclose($pipes[0]);
        if (is_resource($process)) proc_close($process);
    }
    return $exit;
}

try {
    edm_auth_start_session();
    $authorized = edm_privileged_web_request_authorized(
        isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
        edm_auth_is_https(), edm_auth_is_authenticated(),
        isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : null,
        isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null
    );
    if (!$authorized) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'Unauthorized request.'));
        exit;
    }

    $action = isset($_POST['action']) ? $_POST['action'] : null;
    $device = isset($_POST['device']) ? $_POST['device'] : null;
    $mapper = isset($_POST['mapper']) ? $_POST['mapper'] : null;
    $inventory = edm_discover_usb_inventory();
    if (!edm_usb_operation_request($action, $device, $mapper, $inventory)) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'Disk state or selection is invalid.'));
        exit;
    }

    // Only unlock may carry a secret; reject unexpected passphrase submissions.
    if ($action !== 'unlock' && isset($_POST['passphrase'])) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'Unexpected passphrase.'));
        exit;
    }
    $passphrase = isset($_POST['passphrase']) ? $_POST['passphrase'] : null;
    if ($action === 'unlock' && (!is_string($passphrase)
        || $passphrase === '' || strlen($passphrase) > 4096)) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'Passphrase is required.'));
        exit;
    }

    $helper = realpath(__DIR__ . '/../bin/encdiskmanager-usb-operations');
    if ($helper === false || !is_file($helper) || is_link($helper)) {
        throw new RuntimeException('Helper unavailable.');
    }
    $helperStat = stat($helper);
    if ($helperStat === false || $helperStat['uid'] !== 0
        || ($helperStat['mode'] & 0022) !== 0) {
        throw new RuntimeException('Helper permissions are unsafe.');
    }
    $folder = $mapper;
    if ($action === 'mount') {
        require_once __DIR__ . '/../lib/registry.php';
        $registry = edm_registry_load(edm_auth_data_path() . '/disks.json');
        foreach ($registry['disks'] as $entry) {
            foreach ($inventory['devices'] as $disk) {
                if ($disk['path'] === $device && !empty($disk['luks'])
                    && strcasecmp($disk['uuid'], $entry['luks_uuid']) === 0) {
                    $folder = $entry['mount_folder'];
                }
            }
        }
    }
    $exit = edm_run_usb_helper($helper, $action, $device, $mapper, $passphrase,
        $action === 'mount' ? $folder : null);
    if ($exit !== 0) {
        http_response_code(409);
        echo json_encode(array('ok' => false, 'error' => 'Operation failed; refresh disk state.'));
        exit;
    }
    echo json_encode(array('ok' => true));
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(array('ok' => false, 'error' => 'Operation unavailable.'));
}
