<?php
require_once __DIR__ . '/../../lib/stored_key_file.php';
function check_key_file($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$rejected = false;
try { edm_stored_key_create('/tmp', $uuid, false); }
catch (Throwable $e) { $rejected = true; }
check_key_file($rejected, 'Recovery verification required');
$rejected = false;
try { edm_stored_key_create('/tmp', '../bad', true); }
catch (Throwable $e) { $rejected = true; }
check_key_file($rejected, 'Unsafe UUID rejected');
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    $rejected = false;
    try { edm_stored_key_create('/tmp', $uuid, true); }
    catch (Throwable $e) { $rejected = true; }
    check_key_file($rejected, 'Non-root key creation rejected');
}
