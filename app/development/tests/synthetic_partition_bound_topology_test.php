<?php
require_once __DIR__ . '/../../lib/synthetic_partition_bound_topology.php';
function check_bound_topology($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$leaf = array('children'=>array(), 'holders'=>array(), 'mounted'=>false,
    'swap'=>false, 'system'=>false, 'busy'=>false, 'raid'=>false, 'mapper'=>false);
$rootNode=$leaf; $rootNode['children']=array('sdb1');
$graph=array('complete'=>true,'nodes'=>array('sdb'=>$rootNode,'sdb1'=>$leaf));
$parts=array(array('name'=>'sdb1','dev'=>'8:17','partition_number'=>1));
$ids=array(
    'sdb'=>array('dev'=>'8:16','parent'=>null,'partition_number'=>null),
    'sdb1'=>array('dev'=>'8:17','parent'=>'sdb','partition_number'=>1)
);
$agree=function($a,$b,$g,$i) {
    return edm_synthetic_partition_bound_topology_agree($a,$b,$g,$i,'sdb','8:16');
};
check_bound_topology($agree($parts,$parts,$graph,$ids),
    'Agree on synthetic partition topology and per-node identity');
$bad=$ids; $bad['sdb1']['parent']='sdc';
check_bound_topology(!$agree($parts,$parts,$graph,$bad),
    'Reject conflicting synthetic parent identity');
$bad=$ids; $bad['sdb1']['dev']='8:18';
check_bound_topology(!$agree($parts,$parts,$graph,$bad),
    'Reject conflicting synthetic major minor');
$bad=$graph; $bad['nodes']['sdb']['children']=array();
check_bound_topology(!$agree($parts,$parts,$bad,$ids),
    'Reject missing topology edge');
$bad=$parts; $bad[0]['partition_number']=2;
check_bound_topology(!$agree($parts,$bad,$graph,$ids),
    'Reject disagreement between independent synthetic inventories');
$emptyGraph=array('complete'=>true,'nodes'=>array('sdb'=>$leaf));
$emptyIds=array('sdb'=>$ids['sdb']);
check_bound_topology($agree(array(),array(),$emptyGraph,$emptyIds),
    'Zero-partition synthetic consistency does not prove live completeness');
