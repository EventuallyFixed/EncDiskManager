<?php
// Run: php development/tests/disk-action-availability.php
require_once __DIR__ . '/../../app/lib/disk_action_availability.php';

function expect_actions($description, $disk, $expected)
{
    $actual = edm_disk_available_actions($disk);
    foreach (array('unlock', 'mount', 'unmount', 'lock', 'encrypt') as $action) {
        $want = in_array($action, $expected, true);
        if ($actual[$action] !== $want) {
            fwrite(STDERR, "FAIL: $description ($action)\n");
            exit(1);
        }
    }
}

$base = array(
    'path' => '/dev/sdd1',
    'luks' => true,
    'mount' => '',
    'mapper_names' => array(),
    'mapper_mounts' => array()
);
expect_actions('locked LUKS', $base, array('unlock'));
$unlocked = $base;
$unlocked['mapper_names'] = array('edm_test');
expect_actions('unlocked LUKS', $unlocked, array('mount', 'lock'));
$mounted = $unlocked;
$mounted['mapper_mounts'] = array('/mnt/edm_test');
expect_actions('mounted LUKS', $mounted, array('unmount'));
$inconsistentMount = $base;
$inconsistentMount['mapper_mounts'] = array('/mnt/edm_test');
expect_actions('mount without mapper identity', $inconsistentMount, array());
$plain = $base;
$plain['luks'] = false;
expect_actions('unencrypted', $plain, array());
$ambiguous = $unlocked;
$ambiguous['mapper_names'][] = 'other';
expect_actions('multiple mappers', $ambiguous, array());
$directMount = $base;
$directMount['mount'] = '/mnt/other';
expect_actions('direct mount', $directMount, array());
expect_actions('disconnected', null, array());
echo "PASS: disk action availability states\n";
