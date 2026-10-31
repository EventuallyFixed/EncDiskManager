<?php
/*
 * Download a validated EncDiskManager registry backup.
 * The JSON contains disk identifiers and mount settings only; no passphrases.
 */
require_once __DIR__ . '/../lib/registry.php';

try {
    $scriptPath = realpath(__FILE__);
    $appPath = ($scriptPath === false) ? false : realpath(dirname($scriptPath) . '/..');
    if ($appPath === false || !is_dir($appPath)) {
        throw new RuntimeException('Application path could not be resolved.');
    }

    $configFile = $appPath . '/encdiskmanager.conf';
    if (is_link($configFile) || !is_file($configFile) || !is_readable($configFile)) {
        throw new RuntimeException('Application configuration is missing or inaccessible.');
    }
    $configuredPath = trim((string)file_get_contents($configFile));
    if ($configuredPath !== $appPath) {
        throw new RuntimeException('Application configuration does not match its installation path.');
    }

    $registryPath = $appPath . '/data/disks.json';
    $registry = edm_registry_load($registryPath);
    $payload = edm_registry_encode($registry);

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="encdiskmanager-config.json"');
    header('Content-Length: ' . strlen($payload));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $payload;
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo "Configuration backup is unavailable. Check the EncDiskManager installation and registry permissions.\\n";
}
