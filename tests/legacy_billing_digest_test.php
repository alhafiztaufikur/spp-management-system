<?php
/** Regression for the audited SPP correction that previously left a stale manifest. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/../includes/legacy_migration_verification.php';
$db=$koneksi;unit_set_context($db,1);$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
$before=lmr_snapshot($db,1);$db->begin_transaction();$checks=0;
function bdt_check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
try{
    $batch=hp_one($db,"SELECT id,manifest FROM legacy_full_batches WHERE state='completed'");
    bdt_check(json_decode($batch['manifest'],true)['working_digest']===lop_working_digest($db,$batch['id']),'Fixture begins with a valid committed digest');
    $master=hp_one($db,"SELECT m.id FROM master_spp_tahun m JOIN tahun_ajaran y ON y.id=m.tahun_ajaran_id AND y.unit_id=m.unit_id WHERE y.label='2026/2027'");
    $state=spp_master_state($db,(int)$master['id']);$rates=spp_master_rates($db,(int)$master['id']);$expected=spp_master_rate_version($state,$rates);
    // A fresh migration can preserve individual rates without setting class rates.
    // Supply valid administrative fixture rates only inside this rolled-back test.
    foreach(range(1,6) as $grade)if(($rates[$grade]??0)<=0)$rates[$grade]=1000;
    $rates[1]+=123;
    $paid=null;foreach(hp_rows($db,'SELECT * FROM tagihan_spp WHERE master_spp_tahun_id=? AND tingkat_snapshot=1 AND bulan=?',[$master['id'],'07']) as $b)if(lb_spp_credit($db,$b['no_induk'],$b['bulan'],$b['tahun'])>0){$paid=$b;break;}
    $result=spp_master_correct_published_rates($db,(int)$master['id'],$rates,$expected,true);
    bdt_check($result['bills_updated']>0&&$result['bills_locked']>0,'Only eligible unpaid SPP snapshots change');
    if($paid)bdt_check(hp_one($db,'SELECT * FROM tagihan_spp WHERE id=?',[$paid['id']])===$paid,'Paid snapshot survives the rate correction');
    lb_cache($db,true);lop_check_result($db,$batch['id']);
    bdt_check(json_decode(hp_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=?',[$batch['id']])['manifest'],true)['working_digest']===lop_working_digest($db,$batch['id']),'Official correction refreshes the exact digest inside its transaction');
    bdt_check(lmr_snapshot($db,1)===$before,'Archives, identity, cash and savings stay unchanged');
    $db->query('SAVEPOINT invalid_fingerprint');$ob=hp_one($db,"SELECT id FROM legacy_op_obligations WHERE category='spp' LIMIT 1");
    lf_query($db,'UPDATE legacy_op_obligations SET principal=principal+1,version=version+1 WHERE id=?',[$ob['id']]);$rejected=false;
    try{lop_check_result($db,$batch['id']);}catch(DomainException $e){$rejected=true;}
    bdt_check($rejected,'Unexplained drift is still rejected, never accepted as a new baseline');$db->query('ROLLBACK TO SAVEPOINT invalid_fingerprint');
}finally{$db->rollback();lb_cache($db,true);}
echo "PASS $checks isolated billing digest checks; fixture rolled back.\n";
