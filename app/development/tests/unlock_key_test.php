<?php
require_once __DIR__ . '/../../lib/unlock_key.php';
$dir = sys_get_temp_dir() . '/edm-unlock-key-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) exit(1);
$uuid = '11111111-1111-1111-1111-111111111111';
$other = '22222222-2222-2222-2222-222222222222';
$failed = false;
function key_check($condition, $label) {
    global $failed;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) $failed = true;
}
try {
    key_check(edm_unlock_key_read($dir, $uuid) === null, 'Missing key returns null');
    $candidate = null;
    edm_unlock_key_enrol($dir, $uuid, function ($file) use (&$candidate) {
        edm_registry_assert_file($file, 'Candidate key');
        $candidate = file_get_contents($file);
        return strlen($candidate) === 32;
    });
    key_check(strlen($candidate) === 32, 'Generated key is 256 bits');
    key_check(edm_unlock_key_read($dir, strtoupper($uuid)) === $candidate, 'Enrolled key published for matching UUID');
    $called = false;
    try {
        edm_unlock_key_enrol($dir, $uuid, function () use (&$called) {
            $called = true;
            return true;
        });
        key_check(false, 'Duplicate key enrolment rejected');
    } catch (RuntimeException $e) {
        key_check(!$called && edm_unlock_key_read($dir, $uuid) === $candidate, 'Duplicate key enrolment rejected');
    }
    try {
        edm_unlock_key_enrol($dir, $other, function () { return false; });
        key_check(false, 'Failed enrolment does not publish a key');
    } catch (RuntimeException $e) {
        key_check(edm_unlock_key_read($dir, $other) === null, 'Failed enrolment does not publish a key');
    }
    key_check(glob($dir . '/.unlock-key-*') === array(), 'Temporary keyfiles cleaned up');
    edm_unlock_key_enrol($dir, $other, function () { return true; });
    $otherKey = edm_unlock_key_read($dir, $other);
    key_check($otherKey !== $candidate, 'Each disk has an independent key');
    edm_unlock_key_remove($dir, $uuid);
    key_check(edm_unlock_key_read($dir, $uuid) === null && edm_unlock_key_read($dir, $other) === $otherKey, 'Local key removal preserves other key');
} finally {
    foreach (glob($dir . '/unlock-*.key') as $file) unlink($file);
    foreach (glob($dir . '/.unlock-key-*') as $file) unlink($file);
    rmdir($dir);
}
exit($failed ? 1 : 0);
