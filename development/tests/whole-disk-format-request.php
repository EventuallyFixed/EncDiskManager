<?php
/**
 * Request validation tests for whole-disk formatting.
 * Run: php development/tests/whole-disk-format-request.php
 */
require_once __DIR__ . '/../../app/lib/whole_disk_format_request.php';

function assert_format_request($expected, $post, $label)
{
    $actual = edm_whole_disk_format_request('POST', true, true,
        'csrf-test-token', $post);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

$valid = array(
    'csrf_token' => 'csrf-test-token',
    'disk' => '/dev/sdd',
    'warning_accepted' => 'yes',
    'confirmation_phrase' => 'ERASE ALL DATA',
    'passphrase' => 'example test passphrase',
    'passphrase_confirm' => 'example test passphrase'
);
assert_format_request('/dev/sdd', $valid, 'valid request');

foreach (array(
    'disk' => '/dev/sdd1',
    'warning_accepted' => 'no',
    'confirmation_phrase' => 'ERASE DATA',
    'passphrase' => '',
    'passphrase_confirm' => 'different',
    'csrf_token' => 'invalid'
) as $field => $value) {
    $modified = $valid;
    $modified[$field] = $value;
    assert_format_request(false, $modified, 'reject invalid ' . $field);
}
$extra = $valid;
$extra['action'] = 'override';
assert_format_request(false, $extra, 'reject extra fields');
$missing = $valid;
unset($missing['passphrase_confirm']);
assert_format_request(false, $missing, 'reject missing fields');
$tooLong = $valid;
$tooLong['passphrase'] = str_repeat('a', 4097);
$tooLong['passphrase_confirm'] = $tooLong['passphrase'];
assert_format_request(false, $tooLong, 'reject oversized passphrase');
foreach (array(
    array('GET', true, true),
    array('POST', false, true),
    array('POST', true, false)
) as $case) {
    if (edm_whole_disk_format_request($case[0], $case[1], $case[2],
        'csrf-test-token', $valid) !== false) {
        fwrite(STDERR, "FAIL: reject unauthorized request\n");
        exit(1);
    }
}
echo "PASS: whole-disk format request validation\n";
