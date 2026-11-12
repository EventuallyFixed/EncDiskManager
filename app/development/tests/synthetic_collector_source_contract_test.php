<?php
require_once __DIR__ . '/../../lib/synthetic_collector_source_contract.php';
function check_source_contract($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$view = array('readable'=>true, 'complete'=>true,
    'object_token'=>'usb-8:16-gen1', 'epoch'=>'snapshot-1');
$good = array('class_block'=>$view, 'disk_tree'=>$view);
check_source_contract(edm_synthetic_collector_sources_agree($good),
    'Accept matching complete synthetic source contracts');
foreach (array('class_block', 'disk_tree') as $source) {
    foreach (array('readable', 'complete') as $flag) {
        $bad=$good; $bad[$source][$flag]=false;
        check_source_contract(!edm_synthetic_collector_sources_agree($bad),
            'Reject incomplete or unreadable synthetic source');
    }
    $bad=$good; unset($bad[$source]);
    check_source_contract(!edm_synthetic_collector_sources_agree($bad),
        'Reject omitted independent source');
    $bad=$good; $bad[$source]['object_token']='usb-8:16-gen2';
    check_source_contract(!edm_synthetic_collector_sources_agree($bad),
        'Reject replaced kernel object token');
    $bad=$good; $bad[$source]['epoch']='snapshot-2';
    check_source_contract(!edm_synthetic_collector_sources_agree($bad),
        'Reject mismatched synthetic collection epoch');
}
$bad=$good; $bad['disk_tree']['object_token']='';
check_source_contract(!edm_synthetic_collector_sources_agree($bad),
    'Reject absent kernel object identity');
$bad=$good; $bad['class_block']['extra']='ignored';
check_source_contract(!edm_synthetic_collector_sources_agree($bad),
    'Reject unknown source contract fields');
