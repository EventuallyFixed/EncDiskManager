<?php
require_once __DIR__ . '/../../lib/dependency_source_completeness.php';
function assert_source($condition, $label) {
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$names = array('sysfs_partitions', 'sysfs_holders', 'sysfs_slaves',
    'mountinfo', 'swaps', 'system_dependencies');
$report = array();
foreach ($names as $name) {
    $report[$name] = array('readable' => true, 'complete' => true);
}
assert_source(edm_dependency_sources_complete($report),
    'Complete synthetic source report accepted');
foreach ($names as $name) {
    foreach (array('missing', 'unreadable', 'incomplete', 'unknown') as $failure) {
        $bad = $report;
        if ($failure === 'missing') unset($bad[$name]);
        if ($failure === 'unreadable') $bad[$name]['readable'] = false;
        if ($failure === 'incomplete') $bad[$name]['complete'] = false;
        if ($failure === 'unknown') $bad[$name]['complete'] = null;
        assert_source(!edm_dependency_sources_complete($bad),
            'Reject ' . $failure . ' ' . $name);
    }
}
$bad = $report;
$bad['mountinfo']['extra'] = true;
assert_source(!edm_dependency_sources_complete($bad),
    'Reject unexpected source status field');
$bad = $report;
$bad['unrecognized'] = array('readable' => true, 'complete' => true);
assert_source(!edm_dependency_sources_complete($bad),
    'Reject unrecognized source report');
assert_source(!edm_dependency_sources_complete(null),
    'Reject absent source report');
