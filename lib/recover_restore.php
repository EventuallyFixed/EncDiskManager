<?php
/*
 * Recover interrupted Encrypted Disk Manager configuration restores.
 * Invoked by start.sh before the web application is enabled.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/backup.php';

try {
    $count = edm_backup_recover_interrupted_restores();
    if ($count > 0) {
        fwrite(STDOUT, "Recovered {$count} interrupted restore transaction(s).\n");
    }
} catch (Throwable $exception) {
    error_log('EncDiskManager: interrupted restore recovery failed: ' . $exception->getMessage());
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
