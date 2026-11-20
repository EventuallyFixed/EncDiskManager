<?php
/**
 * Whole-disk formatting endpoint. Deliberately gated off until EX4100
 * dependency checks and end-to-end testing have been completed.
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/whole_disk_format_request.php';
require_once __DIR__ . '/../lib/whole_usb_disk_discovery.php';
require_once __DIR__ . '/../lib/whole_usb_disk_stable_candidate.php';
require_once __DIR__ . '/../lib/whole_disk_read_only_check.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    edm_auth_start_session();
    $disk = edm_whole_disk_format_request(
        isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
        edm_auth_is_https(), edm_auth_is_authenticated(),
        isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : null,
        $_POST
    );
    if ($disk === false) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'Invalid or unauthorized format request.'));
        exit;
    }

    // This must remain false until the entire destructive workflow is
    // verified on the target NAS and intentionally enabled in a later commit.
    $formatExecutionEnabled = false;
    if (!$formatExecutionEnabled) {
        http_response_code(503);
        echo json_encode(array('ok' => false,
            'error' => 'Whole-disk encryption is not enabled in this build.'));
        exit;
    }

    $first = edm_discover_whole_usb_disks();
    $second = edm_discover_whole_usb_disks();
    $arguments = edm_whole_usb_disk_stable_check_arguments(
        $first, $second, substr($disk, 5));
    if ($arguments === false || !edm_whole_disk_read_only_check($arguments)) {
        http_response_code(409);
        echo json_encode(array('ok' => false, 'error' => 'USB disk state changed.'));
        exit;
    }

    $helper = realpath(__DIR__ . '/../bin/encdiskmanager-whole-disk-format');
    if ($helper === false || !is_file($helper) || is_link($helper)) {
        throw new RuntimeException('Formatting helper unavailable.');
    }
    $stat = stat($helper);
    if ($stat === false || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0) {
        throw new RuntimeException('Formatting helper permissions are unsafe.');
    }
    $command = '/bin/sh ' . escapeshellarg($helper);
    // The read-only check arguments include the literal 'check' subcommand.
    foreach (array_slice($arguments, 1) as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $process = proc_open($command, array(
        0 => array('pipe', 'r'),
        // The formatter reports only a final status; discard subprocess
        // output rather than risking a pipe deadlock during a long format.
        1 => array('file', '/dev/null', 'w'),
        2 => array('file', '/dev/null', 'w')
    ), $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start formatter.');
    // fwrite may write fewer bytes than requested; never format with a
    // truncated passphrase. Keep the secret out of command arguments.
    $passphrase = $_POST['passphrase'];
    $length = strlen($passphrase);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($pipes[0], substr($passphrase, $offset));
        if ($written === false || $written === 0) {
            fclose($pipes[0]);
            proc_close($process);
            throw new RuntimeException('Could not deliver the complete passphrase.');
        }
        $offset += $written;
    }
    fclose($pipes[0]);
    unset($passphrase);
    $exit = proc_close($process);
    if ($exit !== 0) {
        http_response_code(409);
        echo json_encode(array('ok' => false,
            'error' => 'Formatting failed. Inspect disk state before retrying.'));
        exit;
    }
    // Formatting creates a single LUKS partition and mounts its mapping.
    // Re-discover the actual mount rather than trusting a constructed path.
    require_once __DIR__ . '/../lib/usb_discovery.php';
    $partition = $disk . '1';
    $mountpoint = null;
    // Formatting has already succeeded. Inventory failures must not turn
    // that irreversible success into a misleading "format failed" response.
    try {
        $inventory = edm_discover_usb_inventory();
        foreach ($inventory['devices'] as $device) {
            if (!isset($device['path']) || $device['path'] !== $partition) continue;
            $mounts = isset($device['mapper_mounts']) ? $device['mapper_mounts'] : array();
            if (count($mounts) === 1 && is_string($mounts[0]) &&
                strpos($mounts[0], '/mnt/') === 0) {
                $mountpoint = $mounts[0];
            }
            break;
        }
    } catch (Throwable $discoveryError) {
        // The user must check the disk table for the current mount state.
    }
    echo json_encode(array('ok' => true, 'mountpoint' => $mountpoint,
        'warning' => $mountpoint === null
            ? 'Encryption completed. Check the disk table for its current mount state.'
            : 'Volume is unlocked and mounted at ' . $mountpoint .
                '. Use Unmount and Lock in the disk table when finished.'));
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(array('ok' => false, 'error' => 'Formatting unavailable.'));
}
