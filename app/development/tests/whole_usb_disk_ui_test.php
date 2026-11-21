<?php
// Whole USB disks share the inventory table with their partitions.
$source = file_get_contents(__DIR__ . '/../../web/index.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: Cannot read web interface\n");
    exit(1);
}
$checks = array(
    'data-whole-disk=' => 'Physical USB disks have inventory rows',
    'data-parent-disk=' => 'Partitions identify their physical parent',
    'edm_discover_whole_usb_disks()' => 'Whole-disk inventory uses server discovery',
    'whole-disk-preview-button' => 'Whole-disk action remains in the inventory',
    'function reconcileWholeDisks(snapshot)' => 'Live refresh updates physical disks'
);
if (strpos($source, 'aria-labelledby="whole-usb-disks-title"') !== false) {
    fwrite(STDERR, "FAIL: Obsolete separate whole-disk section remains\n");
    exit(1);
}
foreach ($checks as $needle => $label) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
