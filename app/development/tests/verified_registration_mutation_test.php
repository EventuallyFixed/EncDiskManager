<?php
require_once __DIR__ . '/../../lib/verified_registration_mutation.php';
function check_mutation($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
foreach (array(
    array('action'=>'delete','luks_uuid'=>$uuid,'confirmation'=>'DELETE invalid'),
    array('action'=>'edit','luks_uuid'=>$uuid,'name'=>'x','mount_folder'=>'../etc'),
    array('action'=>'format','luks_uuid'=>$uuid)
) as $bad) {
    $rejected = false;
    try { edm_verified_registration_mutation('/tmp/edm-not-trusted', $bad); }
    catch (Throwable $e) { $rejected = true; }
    check_mutation($rejected, 'Invalid mutation rejected');
}
