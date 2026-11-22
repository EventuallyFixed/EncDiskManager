<?php
require_once __DIR__ . '/../../lib/operation_preview_decision.php';
function check_decision($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$csrf = str_repeat('a', 64);
$post = array('csrf_token' => $csrf, 'action' => 'unlock',
    'luks_uuid' => $uuid);
$calls = 0;
$allow = function ($request) use (&$calls, $uuid) {
    ++$calls;
    return $request['luks_uuid'] === $uuid;
};
$decision = function ($method, $https, $authenticated, $form, $inspector)
    use ($csrf) {
    return edm_operation_preview_decision($method, $https, $authenticated,
        $csrf, $form, $inspector);
};
foreach (array(
    array('GET', true, true, $post, 'GET'),
    array('POST', false, true, $post, 'HTTP'),
    array('POST', true, false, $post, 'Unauthenticated'),
    array('POST', true, true, array('action' => 'unlock',
        'luks_uuid' => $uuid), 'Missing CSRF'),
    array('POST', true, true, array('csrf_token' => str_repeat('b', 64),
        'action' => 'unlock', 'luks_uuid' => $uuid), 'Wrong CSRF')
) as $case) {
    $result = $decision($case[0], $case[1], $case[2], $case[3], $allow);
    check_decision($result['status'] === 403
        && $result['body'] === array('eligible' => false,
            'execution_enabled' => false) && $calls === 0,
        $case[4] . ' rejected without inspection');
}
foreach (array(
    $post + array('device' => '/dev/sda'),
    array('csrf_token' => $csrf, 'action' => 'encrypt',
        'luks_uuid' => $uuid),
    array('csrf_token' => $csrf, 'action' => array('unlock'),
        'luks_uuid' => $uuid)
) as $bad) {
    $result = $decision('POST', true, true, $bad, $allow);
    check_decision($result['status'] === 400 && $calls === 0
        && $result['body']['execution_enabled'] === false,
        'Malformed authenticated request rejected without inspection');
}
$result = $decision('POST', true, true, $post, $allow);
check_decision($result['status'] === 200 && $calls === 1
    && $result['body'] === array('eligible' => true,
        'execution_enabled' => false), 'Authorized preview reports eligibility without execution');
$result = $decision('POST', true, true, $post, function () { return false; });
check_decision($result['status'] === 200
    && $result['body']['eligible'] === false
    && $result['body']['execution_enabled'] === false,
    'Ineligible disk returns safe response');
$result = $decision('POST', true, true, $post, function () {
    throw new RuntimeException('private device path');
});
check_decision($result['status'] === 503
    && $result['body'] === array('eligible' => false,
        'execution_enabled' => false), 'Discovery failure hides exception details');
