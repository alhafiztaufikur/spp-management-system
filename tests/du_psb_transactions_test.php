<?php
/** Only an owned isolated test database; fixture changes always roll back. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';
require_once __DIR__.'/../includes/du_master_service.php';
require_once __DIR__.'/../includes/registration_combined.php';
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/psb_placement.php';
require_once __DIR__.'/../includes/receipt_balances.php';
require_once __DIR__.'/../includes/legacy_migration_verification.php';
$db=$koneksi;$identity=$db->query('SELECT @@server_uuid uuid,@@port port,@@event_scheduler scheduler')->fetch_assoc();
if(!$identity||$identity['uuid']!==getenv('SPP_MIGRATION_TEST_UUID')||(int)$identity['port']===3306||$identity['scheduler']!=='OFF')throw new RuntimeException('Owned isolated server with events OFF required.');
if(!du_psb_schema_ready($db))throw new RuntimeException('Install the guarded schema first.');
$checks=0;function dpt_check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$year=du_current_academic_year();
foreach([1=>1,2=>7,3=>10] as $unit=>$first){
    $_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);lb_cache($db,true);$db->begin_transaction();
    try{
        $y=spp_master_ensure_year($db,$year);$yearId=(int)$y['tahun_ajaran_id'];
        foreach([0=>$unit===1?'PSB':'PSB',$first=>'ZQA',$first+1=>'ZQA'] as $level=>$rombel){
            $c=hp_one($db,'SELECT id FROM master_kelas WHERE tingkat=? AND kode_rombel=?',[$level,$rombel]);
            if(!$c){lf_query($db,'INSERT INTO master_kelas(tingkat,kode_rombel,is_active,is_placeholder) VALUES(?,?,1,0)',[$level,$rombel]);$c=['id'=>(int)$db->insert_id];}$classes[$level]=(int)$c['id'];
        }
        $admit=(string)(9860000000+$unit);lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB,asal_psb,is_active) VALUES(?,?,'0',?,9000000,1,1)",[$admit,'UJI PENEMPATAN PSB',$classes[0]]);
        $admitted=psb_placement_apply($db,$admit,$classes[$first],$year,['id'=>1,'nama'=>'Super Admin']);
        dpt_check(du_psb_state($db,$admit,$year)['covered_psb']&&hp_one($db,'SELECT du_covered_by_psb FROM siswa_tahun_ajaran WHERE id=?',[$admitted['target_placement_id']])['du_covered_by_psb']==1,'Existing PSB placement flow saves coverage while PSB remains outstanding');
        $nis=(string)(9800000000+$unit);lf_query($db,'INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active,PSB,asal_psb) VALUES(?,?,?,1,9000000,1)',[$nis,'UJI DU TERCAKUP PSB',(string)$first]);
        lf_query($db,"INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,status) VALUES(?,?,?,'aktif')",[$yearId,$nis,(string)$first]);$placement=(int)$db->insert_id;
        dpt_check(du_create_bill_for_placement($db,$placement)===null,'First placement needs no DU tariff or published year');
        $p=hp_one($db,'SELECT * FROM siswa_tahun_ajaran WHERE id=?',[$placement]);dpt_check((int)$p['du_covered_by_psb']===1,'Policy snapshot saved with PSB still unpaid');
        dpt_check(!hp_one($db,'SELECT id FROM tagihan_daftar_ulang WHERE no_induk=?',[$nis]),'No synthetic zero DU bill');
        $audit=hp_one($db,"SELECT COUNT(*) n FROM daftar_ulang_audit_log WHERE aksi='du_tercakup_psb'")['n'];
        du_create_bill_for_placement($db,$placement);dpt_check(hp_one($db,"SELECT COUNT(*) n FROM daftar_ulang_audit_log WHERE aksi='du_tercakup_psb'")['n']===$audit,'Repeated placement does not duplicate audit');
        foreach(['','0'] as $amount)dpt_check(du_master_rate_cents($amount,$first)===null,'First class accepts blank and zero');
        $before=lmr_database_checksums($db);$rejected=false;try{du_master_save_and_complete($db,$yearId,$year,[$first+1=>'3.500.000',$first=>'1'],1);}catch(DomainException $e){$rejected=str_contains($e->getMessage(),'tercakup PSB');}dpt_check($rejected&&lmr_database_checksums($db)===$before,'Positive first-class rate rejected before partial writes');
        $readBefore=lmr_database_checksums($db);$page=rc_page($db,['q'=>$nis,'year'=>$year,'status_filter'=>'covered_psb']);dpt_check($page['total']===1&&rc_tariff_evidence($db,$page['rows'][0])['status']==='Tercakup PSB','History coverage and filter are distinct from paid');
        dpt_check(rc_receipts($db,$page['rows'][0])===[],'Policy is not a cash receipt');
        $f=report_filters($db,['template'=>'per-item','kategori'=>'daftar_ulang','tahun_ajaran'=>$year,'siswa_status'=>'active']);$rows=report_annual_evidence($db,$f)['rows'];$row=array_values(array_filter($rows,fn($r)=>$r['nis']===$nis))[0]??null;
        dpt_check($row&&$row['status']==='Tercakup PSB'&&$row['tagihan']===null&&$row['sisa']===0.0&&$row['terbayar']===0.0,'Report excludes coverage from DU arrears and cash');
        $receipt=payment_receipt_remaining($db,['NO_INDUK'=>$nis,'BULAN'=>'Oktober','TAHUN'=>'2026']);dpt_check($receipt[1]===['DU tahun pertama',null,'Tercakup PSB']&&$receipt[0][1]===9000000.0,'Receipt uses coverage label while PSB remains payable');
        dpt_check(lmr_database_checksums($db)===$readBefore,'GET readers do not write markers or audit');
        // Keep an intentionally conflicting, paid native bill for correction through existing UI.
        lf_query($db,'INSERT INTO Daftar_ulang(tahun_ajaran_id,th_ajaran,kelas,Jumlah) VALUES(?,?,?,1000000)',[$yearId,$year,(string)$first]);$master=(int)$db->insert_id;
        lf_query($db,'INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,master_daftar_ulang_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?,?,?,?,?,?,1000000,1000000)',[$yearId,$placement,$master,$nis,(string)$first,$year]);$bill=(int)$db->insert_id;
        $db->query('SET @legacy_full_apply=1');lf_query($db,"INSERT INTO bayar(NO_INDUK,TGL_BYR,BULAN,TAHUN,user_id,total_jumlah) VALUES(?,'2026-10-27','Oktober','2026','1',100000)",[$nis]);$cash=(int)$db->insert_id;
        lf_query($db,'INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,?,?,?,100000)',[$cash,$bill,$nis,(string)$first,$year]);$db->query('SET @legacy_full_apply=0');$cashBefore=hp_one($db,'SELECT * FROM bayar WHERE id=?',[$cash]);
        lb_cache($db,true);$conflict=du_psb_reconcile($db,$year,1);dpt_check($conflict['conflicts']>=1&&!du_psb_state($db,$nis,$year)['covered_psb'],'Existing separate bill is a conflict');
        $billBefore=hp_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE id=?',[$bill]);du_psb_reconcile($db,$year,1);dpt_check(hp_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE id=?',[$bill])===$billBefore,'Conflict reconciliation preserves bill exactly');
        lf_query($db,"UPDATE tagihan_daftar_ulang SET status='cancelled',cancel_reason='Uji koreksi' WHERE id=?",[$bill]);lb_cache($db,true);dpt_check(du_psb_state($db,$nis,$year)['conflict'],'Cancelled bill is preserved as evidence');
        dpt_check(hp_one($db,'SELECT * FROM bayar WHERE id=?',[$cash])===$cashBefore,'Policy and cancellation preserve cash exactly');
        $db->query('SAVEPOINT cancelled_psb_du');lf_query($db,"UPDATE tahun_ajaran SET status='published' WHERE id=?",[$yearId]);
        lf_query($db,"INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,master_daftar_ulang_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan,status,cancel_reason) VALUES(?,?,?,?,?,?,1000000,1000000,'cancelled','Koreksi pendaftaran awal PSB: uji histori')",[$yearId,$admitted['target_placement_id'],$master,$admit,(string)$first,$year]);$cancelled=(int)$db->insert_id;lb_cache($db,true);
        $cancelledBefore=hp_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE id=?',[$cancelled]);$existingId=du_create_bill_for_placement($db,(int)$admitted['target_placement_id']);
        dpt_check($existingId===$cancelled&&hp_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE id=?',[$cancelled])===$cancelledBefore,'PSB placement retry never reopens a conflicting cancelled first-class DU bill');
        $db->query('ROLLBACK TO SAVEPOINT cancelled_psb_du');lb_cache($db,true);
        // Promotion creates a new snapshot, never copies coverage from the preceding year.
        $next=((int)substr($year,0,4)+1).'/'.((int)substr($year,5,4)+1);$ny=spp_master_ensure_year($db,$next);$nextId=(int)$ny['tahun_ajaran_id'];
        lf_query($db,"UPDATE tahun_ajaran SET status='published' WHERE id=?",[$nextId]);
        lf_query($db,'INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,status) VALUES(?,?,?,\'aktif\')',[$nextId,$nis,(string)($first+1)]);$promoted=(int)$db->insert_id;
        lf_query($db,'INSERT INTO Daftar_ulang(tahun_ajaran_id,th_ajaran,kelas,Jumlah) VALUES(?,?,?,1200000)',[$nextId,$next,(string)($first+1)]);
        $nextBill=du_create_bill_for_placement($db,$promoted);dpt_check($nextBill>0&&(float)hp_one($db,'SELECT nominal_tagihan FROM tagihan_daftar_ulang WHERE id=?',[$nextBill])['nominal_tagihan']===1200000.0,'Promotion receives normal next-year DU');
        dpt_check((int)hp_one($db,'SELECT du_covered_by_psb FROM siswa_tahun_ajaran WHERE id=?',[$promoted])['du_covered_by_psb']===0,'New placement does not inherit policy flag');
        $move=promotion_apply_student($db,$admit,$classes[$first+1],promotion_context($db,$yearId),$admitted['target_placement_id'],$first);
        dpt_check((int)hp_one($db,'SELECT du_covered_by_psb FROM siswa_tahun_ajaran WHERE id=?',[$move['target_placement_id']])['du_covered_by_psb']===0&&du_create_bill_for_placement($db,(int)$move['target_placement_id'])>0,'Existing promotion flow issues regular DU without carrying coverage');
        dpt_check(du_psb_state($db,$admit,$year)['covered_psb'],'Promotion preserves previous-year coverage history');
    }finally{$db->rollback();lb_cache($db,true);}
}
echo "PASS $checks isolated DU/PSB transaction checks; fixtures rolled back.\n";
