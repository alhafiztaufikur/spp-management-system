<?php
/** Existing Legacy population plus partial native tariffs on a guarded restore. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/du_master_service.php';
require_once __DIR__.'/../includes/payment_obligations.php';
require_once __DIR__.'/../includes/legacy_billing_reconstruction.php';
require_once __DIR__.'/../includes/legacy_migration_verification.php';
$db=$koneksi;$checks=0;$units=[];
function dms_check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
foreach([1=>[1,83],2=>[7,103],3=>[10,21]] as $unit=>[$first,$expected]){
    $_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);lb_cache($db,true);$before=lmr_snapshot($db,$unit);$db->begin_transaction();
    try{
        lbr_reconcile($db,1);$year=hp_one($db,"SELECT * FROM tahun_ajaran WHERE label='2026/2027'");$existing=hp_rows($db,'SELECT * FROM tagihan_daftar_ulang ORDER BY id');
        $r=du_master_save_and_complete($db,(int)$year['id'],$year['label'],[$first=>'0'],1);
        dms_check($r['rates_saved']===0&&$r['created']===0&&$r['covered_psb']===$expected&&$r['conflicts']===0,'Policy covers the approved Legacy cohort without new DU bills');
        foreach($existing as $b)dms_check(hp_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE id=?',[$b['id']])===$b,'Existing paid/unpaid snapshots remain identical');
        $positions=array_filter(ldu_plan($db)['positions'],fn($p)=>$p['status']==='covered_psb');dms_check(count($positions)===$expected,'Legacy planner matches cohort');
        foreach($positions as $p)dms_check($p['bill']===null&&$p['principal_cents']===null&&$p['paid_cents']===0,'Covered position has no separate DU bill or receipt');
        $covered=reset($positions);dms_check(!array_filter(payment_obligations($db,$covered['nis'])['rows'],fn($row)=>$row['kind']==='du'),'Cashier omits covered DU demand');
        $audit=(int)hp_one($db,'SELECT COUNT(*) n FROM daftar_ulang_audit_log')['n'];$digest=lop_working_digest($db,reset($positions)['batch_id']);
        $again=du_master_save_and_complete($db,(int)$year['id'],$year['label'],[$first=>''],1);
        dms_check($again['created']===0&&$again['rates_saved']===0&&$audit===(int)hp_one($db,'SELECT COUNT(*) n FROM daftar_ulang_audit_log')['n']&&$digest===lop_working_digest($db,reset($positions)['batch_id']),'Repeated empty save produces no audit or financial changes');
        $grade=$first+1;$nis=(string)(9850000000+$unit);lf_query($db,'INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active) VALUES(?,?,?,1)',[$nis,'UJI TARIF PARSIAL DU',(string)$grade]);
        $rates=du_master_save_and_complete($db,(int)$year['id'],$year['label'],[$first=>'0',$grade=>'3.500.000'],1);$bill=du_find_bill($db,$nis,10,2026);
        dms_check($rates['rates_saved']===1&&$bill&&$bill['nominal_tagihan']===3500000.0&&$bill['sisa']===3500000.0,'Partial continuing-class rate publishes new native bill');
        du_require_selectable_bill($db,(int)$bill['id'],$nis);dms_check(true,'Cashier accepts normal continuing DU');
        foreach([[$first=>'1',$grade=>'3.600.000'],[$grade=>'-1'],[$grade=>[]],[$grade=>'0']] as $bad){$reject=false;try{du_master_save_and_complete($db,(int)$year['id'],$year['label'],$bad,1);}catch(DomainException $e){$reject=true;}dms_check($reject,'Invalid or first-class tariff is rejected');}
        $db->query('SET @legacy_full_apply=1');lf_query($db,"INSERT INTO bayar(NO_INDUK,TGL_BYR,BULAN,TAHUN,user_id,total_jumlah) VALUES(?,'2026-10-27','Oktober','2026','1',500000)",[$nis]);$payment=(int)$db->insert_id;
        lf_query($db,"INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,?,?,'2026/2027',500000)",[$payment,$bill['id'],$nis,(string)$grade]);$db->query('SET @legacy_full_apply=0');lb_cache($db,true);
        du_master_save_and_complete($db,(int)$year['id'],$year['label'],[$grade=>'3.600.000'],1);$paid=du_find_bill_by_id($db,(int)$bill['id']);
        dms_check($paid['nominal_tagihan']===3500000.0&&$paid['terbayar']===500000.0&&$paid['sisa']===3000000.0,'Rate changes preserve paid snapshot and native credit');
        lf_query($db,"UPDATE tagihan_daftar_ulang SET status='cancelled',cancel_reason='Uji pembatalan' WHERE id=?",[$bill['id']]);lb_cache($db,true);
        du_master_save_and_complete($db,(int)$year['id'],$year['label'],[$grade=>'3.600.000'],1);dms_check(hp_one($db,'SELECT status FROM tagihan_daftar_ulang WHERE id=?',[$bill['id']])['status']==='cancelled','Cancelled bill is not recreated');
        $v=ldu_verify($db);dms_check($v['covered_psb']===$expected,'Public reader verification includes policy snapshots');$units[$unit]=['covered_psb'=>$expected,'readers_verified'=>$v['readers_verified']];
    }finally{$db->rollback();$db->query('SET @legacy_full_apply=0');lb_cache($db,true);}
    dms_check(lmr_snapshot($db,$unit)===$before,'Rollback leaves identity, source archive, cash and savings intact');
}
echo json_encode(['checks'=>$checks,'units'=>$units],JSON_PRETTY_PRINT).PHP_EOL;
