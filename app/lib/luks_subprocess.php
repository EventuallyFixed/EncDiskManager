<?php
/**
 * Restricted cryptsetup subprocess runner. Not wired to any web endpoint.
 * The caller must hold the shared disk-operation lock and independently
 * revalidate identity before and after this call.
 *
 * Never log argv, passphrases, stdout or stderr. Return status only.
 */
function edm_luks_subprocess($argv, $secret = null, $timeoutSeconds = 30)
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0)
        return false;
    if (!is_array($argv) || !in_array(count($argv), array(3, 6), true)
        || !is_int($timeoutSeconds) || $timeoutSeconds < 1 || $timeoutSeconds > 120)
        return false;
    $unlock = count($argv) === 6
        && $argv[0] === '/usr/sbin/cryptsetup'
        && $argv[1] === 'luksOpen'
        && $argv[2] === '--key-file'
        && $argv[3] === '-'
        && is_string($argv[4])
        && preg_match('#^/dev/[a-zA-Z0-9._+-]+$#D', $argv[4])
        && is_string($argv[5])
        && preg_match('/^edm_[a-zA-Z0-9_]{1,48}$/D', $argv[5]);
    $lock = count($argv) === 3
        && $argv[0] === '/usr/sbin/cryptsetup'
        && $argv[1] === 'luksClose'
        && is_string($argv[2])
        && preg_match('/^edm_[a-zA-Z0-9_]{1,48}$/D', $argv[2]);
    if (!$unlock && !$lock) return false;
    if ($unlock && (!is_string($secret) || $secret === '' || strlen($secret) > 4096))
        return false;
    if ($lock && $secret !== null) return false;
    if (!function_exists('proc_open') || !function_exists('proc_get_status'))
        return false;

    $pipes = array();
    $process = @proc_open($argv, array(
        0 => array('pipe', 'r'),
        1 => array('file', '/dev/null', 'w'),
        2 => array('file', '/dev/null', 'w')
    ), $pipes, null, array('PATH' => '/usr/sbin:/usr/bin:/sbin:/bin'),
        array('bypass_shell' => true));
    if (!is_resource($process)) return false;
    $ok = true;
    $exitCode = null;
    try {
        if ($unlock) {
            // Binary key material is passed verbatim, with no implicit newline.
            $offset = 0;
            $length = strlen($secret);
            stream_set_blocking($pipes[0], false);
            $deadline = microtime(true) + $timeoutSeconds;
            while ($offset < $length) {
                if (microtime(true) >= $deadline) { $ok = false; break; }
                $written = @fwrite($pipes[0], substr($secret, $offset, 8192));
                if ($written === false) { $ok = false; break; }
                if ($written === 0) { usleep(20000); continue; }
                $offset += $written;
            }
        } else {
            $deadline = microtime(true) + $timeoutSeconds;
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        while ($ok) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $ok = false;
                @proc_terminate($process);
                break;
            }
            usleep(20000);
        }
    } finally {
        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        if (!$ok) {
            // Kill and reap: a timed-out cryptsetup must not survive after
            // the caller releases the disk-operation lock.
            @proc_terminate($process, 9);
            $reapDeadline = microtime(true) + 2.0;
            do {
                $status = @proc_get_status($process);
                if (!is_array($status) || !$status['running']) break;
                usleep(20000);
            } while (microtime(true) < $reapDeadline);
        }
        @proc_close($process);
    }
    return $ok && $exitCode === 0;
}
