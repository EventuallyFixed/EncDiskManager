<?php
require_once __DIR__ . '/../../lib/synthetic_partition_identity_binding.php';
function check_partition_binding($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$nodes = array(
    'sdb' => array('dev'=>'8:16','parent'=>null,'partition_number'=>null),
    'sdb1' => array('dev'=>'8:17','parent'=>'sdb','partition_number'=>1)
);
$parts = array(array('name'=>'sdb1','dev'=>'8:17','partition_number'=>1));
check_partition_binding(edm_synthetic_partition_identity_bound($nodes,'sdb','8:16',$parts),
    'Bind partition parent number and major minor');
check_partition_binding(edm_synthetic_partition_identity_bound(
    array('sdb'=>$nodes['sdb']),'sdb','8:16',array()),
    'Allow explicitly zero-partition synthetic disk without proving completeness');
$cases = array();
$n=$nodes; $n['sdb1']['parent']='sdc'; $cases[]=$n;
$n=$nodes; $n['sdb1']['dev']='8:18'; $cases[]=$n;
$n=$nodes; $n['sdb1']['dev']='8:16'; $cases[]=$n;
$n=$nodes; $n['sdb1']['partition_number']=2; $cases[]=$n;
$n=$nodes; $n['sdb']['parent']='sda'; $cases[]=$n;
$n=$nodes; $n['sdb']['partition_number']=0; $cases[]=$n;
$n=$nodes; $n['sdb2']=array('dev'=>'8:18','parent'=>'sdb','partition_number'=>2); $cases[]=$n;
foreach ($cases as $bad) {
    check_partition_binding(!edm_synthetic_partition_identity_bound($bad,'sdb','8:16',$parts),
        'Reject inconsistent synthetic partition identity graph');
}
$n=$nodes; $n['sdb2']=array('dev'=>'8:18','parent'=>'sdb','partition_number'=>1);
$p=$parts; $p[]=array('name'=>'sdb2','dev'=>'8:18','partition_number'=>1);
check_partition_binding(!edm_synthetic_partition_identity_bound($n,'sdb','8:16',$p),
    'Reject duplicate partition number');
check_partition_binding(!edm_synthetic_partition_identity_bound($nodes,'sdb','8:17',$parts),
    'Reject root major minor mismatch');
