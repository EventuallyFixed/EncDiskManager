<?php
/**
 * HTTP smoke test for the read-only operation preview without NAS hardware.
 * Run from the repository root: php app/development/tests/operation_preview_http_test.php
 * Requires PHP CLI with proc_open and allow_url_fopen. Never authenticates,
 * changes a disk, or invokes the privileged helper.
 */
function check_preview_http($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
if (!function_exists('proc_open') || !ini_get('allow_url_fopen')) {
    echo "SKIP: Local HTTP server or URL stream support unavailable\n";
    exit(0);
}
$socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { echo "SKIP: Cannot bind local test port\n"; exit(0); }
$address = stream_socket_get_name($socket, false);
$port = (int)substr(strrchr($address, ':'), 1);
fclose($socket);
$web = realpath(__DIR__ . '/../../web');
$command = array(PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $web);
$descriptors = array(
    0 => array('pipe', 'r'),
    1 => array('file', '/dev/null', 'a'),
    2 => array('file', '/dev/null', 'a')
);
$process = @proc_open($command, $descriptors, $pipes, null, null,
    array('bypass_shell' => true));
if (!is_resource($process)) { echo "SKIP: Cannot start local PHP server\n"; exit(0); }
fclose($pipes[0]);
function preview_http_request($port, $method, $body = '') {
    $context = stream_context_create(array('http' => array(
        'method' => $method, 'ignore_errors' => true, 'timeout' => 2,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $body
    )));
    $response = @file_get_contents('http://127.0.0.1:' . $port .
        '/operation-preview.php', false, $context);
    $headers = isset($http_response_header) ? $http_response_header : array();
    if ($response === false || !$headers
        || !preg_match('/^HTTP\/\S+\s+(\d{3})/', $headers[0], $match))
        return null;
    return array('status' => (int)$match[1], 'body' => json_decode($response, true),
        'headers' => $headers);
}
try {
    $ready = false;
    for ($attempt = 0; $attempt < 30; ++$attempt) {
        $probe = preview_http_request($port, 'GET');
        if ($probe !== null) { $ready = true; break; }
        usleep(100000);
    }
    check_preview_http($ready, 'Local preview endpoint responds');
    // CI deliberately does not provision a root-owned NAS application tree.
    // Startup recovery rejects that host configuration before the HTTP
    // authorization policy can execute. That is fail-closed (503), not 403.
    // Actual 403 behavior is covered by operation_preview_decision_test.php.
    $environmentReady = $probe['status'] !== 503;
    check_preview_http($probe['status'] === 403 || $probe['status'] === 503,
        'Unauthenticated preview denies access on non-NAS test host');
    $uuid = '12345678-1234-1234-1234-123456789abc';
    $cases = array(
        array('GET', ''),
        array('POST', ''),
        array('POST', http_build_query(array('action' => 'unlock',
            'luks_uuid' => $uuid, 'csrf_token' => str_repeat('a', 64)))),
        array('POST', http_build_query(array('action' => 'encrypt',
            'luks_uuid' => $uuid, 'csrf_token' => str_repeat('a', 64)))),
        array('POST', http_build_query(array('action' => 'unlock',
            'luks_uuid' => $uuid, 'device' => '/dev/sda',
            'csrf_token' => str_repeat('a', 64))))
    );
    // A missing administrator session must never result in an eligible
    // preview, even if NAS-only startup checks reject the host first.
    foreach ($cases as $i => $case) {
        $result = preview_http_request($port, $case[0], $case[1]);
        check_preview_http(is_array($result)
            && ($result['status'] === 403 || (!$environmentReady && $result['status'] === 503))
            && $result['body'] === array('eligible' => false,
                'execution_enabled' => false),
            'Unauthenticated HTTP request fails closed: ' . ($i + 1));
        $headerText = implode("\n", $result['headers']);
        check_preview_http(stripos($headerText, 'Cache-Control: no-store') !== false
            && stripos($headerText, 'X-Content-Type-Options: nosniff') !== false
            && stripos($headerText, 'X-Frame-Options: DENY') !== false,
            'Security headers present: ' . ($i + 1));
    }
} finally {
    proc_terminate($process);
    proc_close($process);
}
