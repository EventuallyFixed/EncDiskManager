<?php
/*
 * Download a validated, encrypted-disk configuration and administrator backup.
 * The archive includes the administrator password hash, never plaintext
 * credentials or LUKS passphrases.
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/backup.php';

try {
    edm_auth_start_session();
    edm_auth_require_login();
} catch (Throwable $exception) {
    error_log('EncDiskManager backup authentication error: ' . $exception->getMessage());
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Authentication required.\n";
    exit;
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $archive = edm_backup_create_archive();
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="encdiskmanager-backup.tgz"');
    header('Content-Length: ' . strlen($archive));
    echo $archive;
} catch (Throwable $exception) {
    error_log('EncDiskManager backup creation failed: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Configuration backup is unavailable. Check the persistent data directory and permissions.\n";
}
