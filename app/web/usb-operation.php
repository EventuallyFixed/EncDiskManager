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

// Composite operations resolve a registered UUID again before each step.
function edm_composite_device($device, $uuid)
{
    $inventory = edm_discover_usb_inventory();
    $matches = array();
    foreach ($inventory['devices'] as $disk) {
        if (!empty($disk['luks']) && isset($disk['uuid'])
            && strcasecmp($disk['uuid'], $uuid) === 0) $matches[] = $disk;
    }
    if (count($matches) !== 1 || $matches[0]['path'] !== $device) {
        throw new RuntimeException('Registered USB disk is missing or ambiguous.');
    }
    return array($inventory, $matches[0]);
}

function edm_composite_step($helper, $action, $device, $uuid, $mapper, $secret = null, $argument = null)
{
    list($inventory, $disk) = edm_composite_device($device, $uuid);
    if (!edm_usb_operation_request($action, $device, $mapper, $inventory)) {
        throw new RuntimeException('Disk state changed.');
    }
    if (edm_run_usb_helper($helper, $action, $device, $mapper, $secret, $argument) !== 0) {
        throw new RuntimeException('Disk operation failed.');
    }
}

// The original passphrase authorizes a new random keyslot; it is never saved.
function edm_enrol_disk_key($directory, $helper, $device, $entry, $passphrase)
{
    $uuid = $entry['luks_uuid'];
    edm_unlock_key_enrol($directory, $uuid, function ($file) use ($helper, $device, $uuid, $entry, $passphrase) {
        edm_composite_device($device, $uuid);
        return edm_run_usb_helper($helper, 'enrol', $device, $entry['mapper_name'], $passphrase, $file) === 0;
    });
    return edm_unlock_key_read($directory, $uuid);
}

function edm_unlock_with_stored_key($helper, $device, $uuid, $mapper, $passphrase)
{
    require_once __DIR__ . '/../lib/unlock_key.php';
    $directory = edm_auth_data_path();
    // The stored credential belongs to a registered, uniquely identified UUID.
    edm_unlock_key_path($directory, $uuid);
    $registry = edm_registry_load($directory . '/disks.json');
    $entry = null;
    foreach ($registry['disks'] as $candidate) {
        if (strcasecmp($candidate['luks_uuid'], $uuid) === 0) $entry = $candidate;
    }
    if ($entry === null || empty($entry['store_unlock_key'])
        || $mapper !== $entry['mapper_name']) {
        throw new RuntimeException('Stored unlock key is not enabled for this disk.');
    }
    list($inventory, $disk) = edm_composite_device($device, $uuid);
    if (count($disk['mapper_names']) !== 0
        || count($disk['mapper_mounts']) !== 0 || !empty($disk['mount'])) {
        throw new RuntimeException('Disk must be locked and unmounted.');
    }
    $stored = edm_unlock_key_read($directory, $uuid);
    if ($stored === null) {
        if ($passphrase === null) throw new RuntimeException('Recovery passphrase required.');
        // Restore a missing stored key only after verifying the recovery key.
        $stored = edm_enrol_disk_key($directory, $helper, $device, $entry, $passphrase);
    } elseif ($passphrase !== null) {
        throw new RuntimeException('Unexpected passphrase.');
    }
    // Unlock only: mounting remains a separate explicit user action.
    edm_composite_step($helper, 'unlock', $device, $uuid, $mapper, $stored);
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
    if (!in_array($action, array('unlock', 'mount', 'unmount', 'lock'), true)) {
        throw new RuntimeException('Unknown disk operation.');
    }
    if (!in_array($action, array('unlock', 'mount'), true) && isset($_POST['passphrase'])) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'Unexpected passphrase.'));
        exit;
    }
    $passphrase = isset($_POST['passphrase']) ? $_POST['passphrase'] : null;
    if ($passphrase !== null && (!is_string($passphrase)
        || $passphrase === '' || strlen($passphrase) > 4096)) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'Passphrase is required.'));
        exit;
    }

    // Saving is an explicit opt-in on a manual Unlock request only.
    $saveKey = isset($_POST['save_unlock_key']);
    if ($saveKey && ($action !== 'unlock' || $_POST['save_unlock_key'] !== '1')) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'Invalid unlock key request.'));
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
    require_once __DIR__ . '/../lib/unlock_key.php';
    // Use the current saved preference, not the browser's cached button state.
    $directory = edm_auth_data_path();
    edm_unlock_key_with_lock($directory, function () use ($directory, $helper, $action, $device, $mapper, $passphrase, $saveKey) {
        $inventory = edm_discover_usb_inventory();
        $registry = edm_registry_load($directory . '/disks.json');
        $entry = null;
        foreach ($inventory['devices'] as $disk) {
            if ($disk['path'] !== $device || empty($disk['luks'])) continue;
            foreach ($registry['disks'] as $candidate) {
                if (strcasecmp($disk['uuid'], $candidate['luks_uuid']) === 0) $entry = $candidate;
            }
        }
        $postedUuid = isset($_POST['luks_uuid']) ? $_POST['luks_uuid'] : null;
        if ($postedUuid !== null) {
            if (!is_string($postedUuid)) throw new RuntimeException('Invalid disk identity.');
            edm_composite_device($device, $postedUuid);
        }
        if ($entry !== null && !empty($entry['store_unlock_key']) && $action === 'unlock') {
            if ($saveKey || !is_string($postedUuid)
                || strcasecmp($postedUuid, $entry['luks_uuid']) !== 0) {
                throw new RuntimeException('Registered disk identity changed.');
            }
            edm_unlock_with_stored_key($helper, $device, $entry['luks_uuid'], $mapper, $passphrase);
            return;
        }
        if ($action !== 'unlock' && $passphrase !== null) {
            throw new RuntimeException('Unexpected passphrase.');
        }
        if (!edm_usb_operation_request($action, $device, $mapper, $inventory)) {
            throw new RuntimeException('Disk state or selection is invalid.');
        }
        if ($action === 'unlock' && $passphrase === null) {
            throw new RuntimeException('Passphrase required.');
        }
        if ($saveKey) {
            if ($entry === null || !is_string($postedUuid)
                || strcasecmp($postedUuid, $entry['luks_uuid']) !== 0
                || $mapper !== $entry['mapper_name']) {
                throw new RuntimeException('Register the disk before saving an unlock key.');
            }
            // A failed enrolment (including a wrong passphrase) leaves the
            // preference unchanged. Unlock with the generated credential.
            $key = edm_enrol_disk_key($directory, $helper, $device, $entry, $passphrase);
            edm_registry_set_key_preference($directory . '/disks.json', $entry['luks_uuid'], true);
            edm_composite_step($helper, 'unlock', $device, $entry['luks_uuid'], $mapper, $key);
            return;
        }
        $folder = $entry !== null ? $entry['mount_folder'] : $mapper;
        $exit = edm_run_usb_helper($helper, $action, $device, $mapper, $passphrase,
            $action === 'mount' ? $folder : null);
        if ($action === 'mount') {
            $errors = array(
                21 => 'A filesystem is already mounted at /mnt/' . $folder . '.',
                22 => 'Mount directory /mnt/' . $folder . ' is not empty.',
                23 => 'Mount path /mnt/' . $folder . ' is not a regular directory.'
            );
            if (isset($errors[$exit])) {
                throw new DomainException($errors[$exit]);
            }
        }
        if ($exit !== 0) throw new RuntimeException('Disk operation failed.');
    });
    echo json_encode(array('ok' => true));
} catch (DomainException $exception) {
    http_response_code(409);
    echo json_encode(array('ok' => false, 'error' => $exception->getMessage()));
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(array('ok' => false, 'error' => 'Operation unavailable.'));
}
