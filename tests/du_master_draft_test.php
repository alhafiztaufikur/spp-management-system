<?php
/** Partial draft-year publication with native pupils on a UUID-guarded fixture server. */
if(PHP_SAPI!=='cli'||(int)getenv('SPP_DB_PORT')===3306||!getenv('SPP_MIGRATION_TEST_UUID'))throw new RuntimeException('Owned isolated server required');
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/../includes/du_master_service.php';require_once __DIR__.'/legacy_finance_support.php';
$db=$koneksi;if($db->query('SELECT @@server_uuid')->fetch_row()[0]!==getenv('SPP_MIGRATION_TEST_UUID'))throw new RuntimeException('Wrong server');
unit_set_context($db,3);$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>3];$checks=0;
function dmd_check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$db->begin_transaction();try{
    $year=spp_master_ensure_year($db,'2026/2027');$yearId=(int)$year['tahun_ajaran_id'];$student=lft_seed($db,1,3);lf_query($db,"UPDATE siswa SET KELAS='11' WHERE id=?",[$student['student']]);
    dmd_check(hp_one($db,'SELECT status FROM tahun_ajaran WHERE id=?',[$yearId])['status']==='draft','Year starts in Draft');
    $r=du_master_save_and_complete($db,$yearId,'2026/2027',[10=>'0',11=>'3.500.000',12=>''],1);
    dmd_check($r['rates_saved']===1&&$r['created']===1,'One class tariff publishes without requiring other classes');
    dmd_check(hp_one($db,'SELECT status FROM tahun_ajaran WHERE id=?',[$yearId])['status']==='published','Official publication opens Draft year');
    $bill=du_find_bill($db,$student['nis'],10,2026);dmd_check($bill&&$bill['nominal_tagihan']===3500000.0,'Native pupil receives the submitted tariff');
    du_require_selectable_bill($db,(int)$bill['id'],$student['nis']);dmd_check(true,'Cashier can select the new bill');
    $audit=hp_one($db,"SELECT after_data FROM daftar_ulang_audit_log WHERE tahun_ajaran_id=? AND aksi='terbitkan_tagihan' ORDER BY id DESC LIMIT 1",[$yearId]);
    dmd_check(json_decode($audit['after_data'],true)['basis']==='admin_tariff_students','Admin publication is not mislabeled as Legacy evidence');
    $again=du_master_save_and_complete($db,$yearId,'2026/2027',[10=>'0',11=>'3.500.000',12=>''],1);
    dmd_check($again['created']===0&&$again['rates_saved']===0&&$again['existing']===1,'Repeated native publication preserves one bill');
    $exempt=lft_seed($db,1,3);lf_query($db,"UPDATE siswa SET KELAS='11' WHERE id=?",[$exempt['student']]);lf_query($db,'UPDATE siswa SET DAFTAR_ULANG=3500000,potong_du=3500000,tot_du=0 WHERE id=?',[$exempt['student']]);
    du_master_save_and_complete($db,$yearId,'2026/2027',[11=>'3.500.000'],1);
    $free=du_find_bill($db,$exempt['nis'],10,2026);dmd_check($free&&$free['nominal_tagihan']===0.0&&(float)$free['sisa']===0.0,'Explicit native exemption is preserved');
    $inactive=lft_seed($db,1,3);lf_query($db,'UPDATE siswa SET is_active=0 WHERE id=?',[$inactive['student']]);
    du_master_save_and_complete($db,$yearId,'2026/2027',[11=>'3.500.000'],1);
    dmd_check(!hp_one($db,'SELECT id FROM tagihan_daftar_ulang WHERE no_induk=?',[$inactive['nis']]),'Inactive pupil is excluded from publication');
    $covered=lft_seed($db,1,3);$coverage=du_master_save_and_complete($db,$yearId,'2026/2027',[10=>'0'],1);
    dmd_check($coverage['covered_psb']===1&&$coverage['unknown']===0&&du_psb_state($db,$covered['nis'],'2026/2027')['covered_psb'],'Master save creates first-class placement and coverage without false missing rate');
    dmd_check(!hp_one($db,'SELECT id FROM tagihan_daftar_ulang WHERE no_induk=?',[$covered['nis']]),'Master does not create a zero first-class bill');
}finally{$db->rollback();lb_cache($db,true);}
echo "PASS $checks isolated Draft/native DU checks; fixtures rolled back.\n";
