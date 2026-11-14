<?php
// Whole-disk UI is display-only until the authorized encryption flow exists.
$source = file_get_contents(__DIR__ . '/../../web/index.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: Cannot read web interface\n");
    exit(1);
}
$checks = array(
    "Whole USB disks" => 'Whole-disk inventory section exists',
    "edm_discover_whole_usb_disks()" => 'Whole-disk inventory is sourced from server discovery',
    'disabled title="Whole-disk encryption is not yet available"' =>
        'Whole-disk encryption action is explicitly disabled'
);
foreach ($checks as $needle => $label) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
