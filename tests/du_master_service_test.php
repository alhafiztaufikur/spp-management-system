<?php
/** Writes only to an explicitly owned isolated restore; every fixture is rolled back. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/du_master_service.php';
require_once __DIR__.'/../includes/payment_obligations.php';
require_once __DIR__.'/../includes/legacy_billing_reconstruction.php';
require_once __DIR__.'/../includes/legacy_migration_verification.php';
$db=$koneksi;$checks=0;$units=[];
function dms_check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
foreach([1=>1,2=>7,3=>10] as $unit=>$grade){
    $_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);lb_cache($db,true);
    $before=lmr_snapshot($db,$unit);$db->begin_transaction();
    try{
        lbr_reconcile($db,1);$year=hp_one($db,"SELECT * FROM tahun_ajaran WHERE label='2026/2027'");
        dms_check($year['status']==='published','Fixture starts with published year');
        $existing=hp_rows($db,'SELECT * FROM tagihan_daftar_ulang ORDER BY id');
        $unknown=array_filter(ldu_plan($db)['positions'],fn($p)=>!$p['bill']&&!$p['proof']['known']&&$p['placement']&&(int)$p['placement']['kelas']===$grade);
        dms_check(count($unknown)>0,'Initial grade without source evidence remains unknown');
        // Other suites may seed active native pupils as well as Legacy owners.
        // Explicit current-year DU publication must serve both populations.
        $native=hp_rows($db,"SELECT s.NO_INDUK FROM siswa s WHERE s.is_active=1 AND s.KELAS=?
            AND NOT EXISTS(SELECT 1 FROM legacy_op_owners o WHERE o.student_id=s.id AND o.unit_id=s.unit_id)
            AND NOT EXISTS(SELECT 1 FROM tagihan_daftar_ulang t WHERE t.no_induk=s.NO_INDUK AND t.unit_id=s.unit_id AND t.tahun_ajaran_id=?)",[(string)$grade,$year['id']]);
        [$first,$last]=unit_level_bounds();$amounts=array_fill_keys(range($first,$last),'');$amounts[$grade]='3.500.000';
        $r=du_master_save_and_complete($db,(int)$year['id'],$year['label'],$amounts,1);
        dms_check($r['rates_saved']===1&&$r['created']===count($unknown)+count($native),'One partial form issues all ready Legacy and native pupils');
        foreach($native as $student){$nativeBill=du_find_bill($db,$student['NO_INDUK'],10,2026);dms_check($nativeBill&&lb_result_cents($nativeBill['nominal_tagihan'])===350000000&&$nativeBill['sisa']===3500000.0,'New native pupil receives the same explicit current-year tariff');}
        $master=hp_one($db,'SELECT * FROM Daftar_ulang WHERE tahun_ajaran_id=? AND kelas=?',[$year['id'],(string)$grade]);
        dms_check(lf_cents($master['Jumlah'])===350000000&&isset(ldu_master_bases($db,(int)$year['id'])[(int)$master['id']]),'Admin tariff has explicit provenance');
        foreach($existing as $b)dms_check(hp_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE id=?',[$b['id']])===$b,'Existing paid and unpaid snapshots preserved');
        $p=$unit===1?ldu_plan($db)['positions']['1|262701104']:reset($unknown);
        $bill=du_find_bill($db,$p['nis'],10,2026);
        dms_check($bill&&lb_result_cents($bill['nominal_tagihan'])===350000000&&$bill['sisa']===3500000.0,'New bill is selectable with full admin tariff');
        du_require_selectable_bill($db,(int)$bill['id'],$p['nis']);dms_check(true,'Cashier accepts the issued bill');
        if($unit===1){$student=hp_one($db,'SELECT s.KELAS,m.kode_rombel FROM siswa s JOIN master_kelas m ON m.id=s.master_kelas_id AND m.unit_id=s.unit_id WHERE s.NO_INDUK=?',[$p['nis']]);dms_check($student['KELAS']==='1'&&$student['kode_rombel']==='C','Abdullah stays in 1C');}
        $canonical=ldu_verify($db);dms_check($canonical['readers_verified']>0,'Master, cashier, history and report positions agree');
        dms_check(array_filter(payment_obligations($db,$p['nis'])['rows'],fn($r)=>$r['kind']==='du'&&$r['remaining']===3500000.0)!==[],'Popup includes only the new real obligation');
        $digest=lop_working_digest($db,$p['batch_id']);$audits=(int)hp_one($db,'SELECT COUNT(*) n FROM legacy_op_audit')['n'];
        $again=du_master_save_and_complete($db,(int)$year['id'],$year['label'],$amounts,1);
        dms_check($again['created']===0&&$again['rates_saved']===0&&$digest===lop_working_digest($db,$p['batch_id'])&&$audits===(int)hp_one($db,'SELECT COUNT(*) n FROM legacy_op_audit')['n'],'Second identical action creates no bills, duplicates or audit noise');
        foreach([['oops'],[$grade=>'3.600.000',$last=>'-1'],[$grade=>[]],[$grade=>'0']] as $bad){$rejected=false;$db->query('SAVEPOINT invalid_du');try{du_master_save_and_complete($db,(int)$year['id'],$year['label'],$bad,1);}catch(Throwable $e){$rejected=true;}$db->query('ROLLBACK TO SAVEPOINT invalid_du');dms_check($rejected&&lf_cents(hp_one($db,'SELECT Jumlah FROM Daftar_ulang WHERE id=?',[$master['id']])['Jumlah'])===350000000,'Invalid input is rejected before any partial rate save');}
        $db->query('SAVEPOINT paid_du');$db->query('SET @legacy_full_apply=1');
        lf_query($db,"INSERT INTO bayar(NO_INDUK,TGL_BYR,BULAN,TAHUN,user_id,total_jumlah) VALUES(?,'2026-10-26','Oktober','2026','1',500000)",[$p['nis']]);$payment=(int)$db->insert_id;
        lf_query($db,"INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,?,?,'2026/2027',500000)",[$payment,$bill['id'],$p['nis'],(string)$grade]);$db->query('SET @legacy_full_apply=0');lb_cache($db,true);
        du_master_save_and_complete($db,(int)$year['id'],$year['label'],[$grade=>'3.600.000'],1);
        $paid=du_find_bill_by_id($db,(int)$bill['id']);dms_check($paid['nominal_tagihan']===3500000.0&&$paid['terbayar']===500000.0&&$paid['sisa']===3000000.0,'Tariff change preserves paid snapshot and native remainder');ldu_verify($db);
        $db->query('ROLLBACK TO SAVEPOINT paid_du');lb_cache($db,true);
        $db->query('SAVEPOINT cancelled_du');lf_query($db,"UPDATE tagihan_daftar_ulang SET status='cancelled',cancel_reason='Fixture explicit cancellation' WHERE id=?",[$bill['id']]);
        du_master_save_and_complete($db,(int)$year['id'],$year['label'],[$grade=>'3.600.000'],1);dms_check(hp_one($db,'SELECT status FROM tagihan_daftar_ulang WHERE id=?',[$bill['id']])['status']==='cancelled','Cancelled obligation not recreated');
        $db->query('ROLLBACK TO SAVEPOINT cancelled_du');lb_cache($db,true);
        $db->query('SAVEPOINT closed_du');lf_query($db,"UPDATE tahun_ajaran SET status='closed' WHERE id=?",[$year['id']]);$rejected=false;try{du_master_save_and_complete($db,(int)$year['id'],$year['label'],[$grade=>'3.600.000'],1);}catch(DomainException $e){$rejected=true;}dms_check($rejected,'Closed year rejects save and issuance');$db->query('ROLLBACK TO SAVEPOINT closed_du');lb_cache($db,true);
        dms_check(lmr_snapshot($db,$unit)===$before,'Archives, identity, cash and savings unchanged after fixture rollback');
        $units[$unit]=['created'=>$r['created'],'rate_fixture_only'=>3500000,'verified_positions'=>$canonical['readers_verified']];
    }finally{$db->rollback();$db->query('SET @legacy_full_apply=0');lb_cache($db,true);}
}
echo json_encode(['checks'=>$checks,'units'=>$units],JSON_PRETTY_PRINT).PHP_EOL;
