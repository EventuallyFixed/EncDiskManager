<?php
require_once __DIR__ . '/../../lib/synthetic_dependency_identity_agreement.php';
function check_identity_agreement($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
check_identity_agreement(edm_synthetic_dependency_identity_agrees(
    array('8:17', '8:16'), array('8:16', '8:17')),
    'Accept matching synthetic device identities independent of order');
check_identity_agreement(edm_synthetic_dependency_identity_agrees(array(), array()),
    'Empty synthetic views agree but do not prove completeness');
check_identity_agreement(!edm_synthetic_dependency_identity_agrees(
    array('8:17'), array('8:18')),
    'Reject cross-view major minor disagreement');
check_identity_agreement(!edm_synthetic_dependency_identity_agrees(
    array('8:17'), array()),
    'Reject omitted identity in second view');
foreach (array(
    array('08:17'), array('8:017'), array('8:17', '8:17'),
    array('../sdb1'), array(817), array('8:17', null),
    array('8:17' => '8:17')
) as $invalid) {
    check_identity_agreement(!edm_synthetic_dependency_identity_agrees($invalid, array('8:17')),
        'Reject malformed ambiguous or duplicate synthetic identity');
}
