<?php
require_once __DIR__ . '/../../lib/registry_transactions.php';

function assert_case($condition, $description) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $description . "\n");
        exit(1);
    }
    echo "PASS: " . $description . "\n";
}
function expect_failure($callback, $description) {
    try {
        $callback();
    } catch (RuntimeException $exception) {
        assert_case(true, $description);
        return;
    }
    assert_case(false, $description);
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$badVerifier = function ($uuid) { return false; };
expect_failure(function () use ($uuid) {
    edm_registry_change_details('/invalid/disks.json', $uuid, 'Name', 'folder', true);
}, 'Precomputed true cannot authorise edits');
expect_failure(function () use ($uuid) {
    edm_registry_delete_registration('/invalid/disks.json', $uuid, true);
}, 'Precomputed true cannot authorise deletion');
expect_failure(function () use ($uuid, $badVerifier) {
    edm_registry_change_details('/invalid/disks.json', $uuid, 'Name', 'folder', $badVerifier);
}, 'Untrusted registry path is rejected before mutation');
echo "Live verifier execution requires an isolated root-owned registry fixture.\n";
