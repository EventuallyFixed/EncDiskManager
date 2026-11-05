<?php
require_once __DIR__ . '/../../lib/disk_actions.php';

$cases = array(
    'plain unmounted' => array(false, false, false, true, array('encrypt')),
    'plain mounted' => array(false, false, true, true, array()),
    'LUKS locked' => array(true, false, false, true, array('unlock', 'encrypt')),
    'LUKS unlocked' => array(true, true, false, true, array('mount', 'lock')),
    'LUKS mounted' => array(true, true, true, true, array('unmount')),
    'unknown state' => array(true, false, false, false, array()),
    'inconsistent state' => array(true, false, true, true, array())
);

$failed = 0;
foreach ($cases as $name => $case) {
    $actual = edm_disk_action_policy($case[0], $case[1], $case[2], $case[3]);
    $enabled = array_keys(array_filter($actual));
    if ($enabled !== $case[4]) {
        fwrite(STDERR, 'FAIL: ' . $name . ' expected ' .
            json_encode($case[4]) . ' got ' . json_encode($enabled) . "\n");
        $failed++;
    } else {
        echo 'PASS: ' . $name . "\n";
    }
}
exit($failed ? 1 : 0);
