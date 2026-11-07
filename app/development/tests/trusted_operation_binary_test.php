<?php
require_once __DIR__ . '/../../lib/trusted_operation_binary.php';
function check_binary($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
check_binary(!edm_trusted_operation_binary('/bin/sh'), 'Shell binary not allowed');
check_binary(!edm_trusted_operation_binary('/usr/bin/mount'),
    'Unreviewed mount path not allowed');
check_binary(!edm_trusted_operation_binary('/bin/../bin/mount'),
    'Path traversal not allowed');
check_binary(!edm_trusted_operation_binary('/tmp/mount'),
    'Untrusted directory not allowed');
