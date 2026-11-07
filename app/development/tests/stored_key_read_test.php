<?php
require_once __DIR__ . '/../../lib/stored_key_file.php';
function check_key_read($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
foreach (array('../etc', '', 'invalid') as $bad) {
    $rejected = false;
    try { edm_stored_key_read('/tmp', $bad); }
    catch (Throwable $e) { $rejected = true; }
    check_key_read($rejected, 'Invalid UUID rejected');
}
$rejected = false;
try { edm_stored_key_read('/tmp', $uuid); }
catch (Throwable $e) { $rejected = true; }
check_key_read($rejected, 'Untrusted key storage rejected');
