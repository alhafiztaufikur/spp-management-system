<?php
/** Transactions run only on a proven disposable server; every fixture rolls back. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';
require_once __DIR__.'/../includes/du_master_service.php';
require_once __DIR__.'/../includes/receipt_balances.php';
require_once __DIR__.'/../includes/payment_obligations.php';
require_once __DIR__.'/../includes/promotion.php';
require_once __DIR__.'/../includes/registration_combined.php';
require_once __DIR__.'/../includes/reports.php';
$db=$koneksi;$id=$db->query('SELECT @@server_uuid uuid,@@port port,@@event_scheduler scheduler')->fetch_assoc();
if($id['uuid']!==getenv('SPP_MIGRATION_TEST_UUID')||(int)$id['port']===3306||$id['scheduler']!=='OFF'||!du_advance_schema_ready($db))throw new RuntimeException('Verified isolated upgraded schema required.');
$checks=0;function adv_check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function adv_reject(callable $action,string $why):void{$rejected=false;try{$action();}catch(DomainException|DaftarUlangSelectionException $e){$rejected=true;}adv_check($rejected,$why);}
function adv_count(mysqli $db,string $table):int{return (int)hp_one($db,"SELECT COUNT(*) n FROM $table")['n'];}
$current=du_current_academic_year();$next=du_next_year();$auditDb=str_starts_with(DB_NAME,'db_spp_audit_');
foreach([1=>[1,6],2=>[7,9],3=>[10,12]] as $unit=>[$first,$last]){
    $_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);lb_cache($db,true);$db->begin_transaction();
    try{
        $year=class_ensure_academic_year($db,$current);$future=class_ensure_academic_year($db,$next);
        foreach([$first,$first+1,$last] as $grade){
            lf_query($db,"INSERT INTO master_kelas(tingkat,kode_rombel,is_active,is_placeholder) VALUES(?,'ZAV',1,0) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",[$grade]);$classes[$grade]=(int)$db->insert_id;
        }
        foreach(['normal'=>$first,'final'=>$last,'inactive'=>$first,'nonacademic'=>0] as $kind=>$grade){
            $nis=(string)(9700000000+$unit*100+count($students??[]));$students[$kind]=$nis;
            lf_query($db,'INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active,PSB,asal_psb) VALUES(?,?,?,?,?,9000000,1)',[$nis,'UJI DU AWAL '.$kind,(string)$grade,$classes[$grade]??null,$kind==='inactive'?0:1]);
            if($grade)lf_query($db,"INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,status) VALUES(?,?,?,?,'aktif')",[$year,$nis,(string)$grade,$classes[$grade]]);
        }
        $nis=$students['normal'];$source=hp_one($db,'SELECT * FROM siswa_tahun_ajaran WHERE no_induk=? AND tahun_ajaran_id=?',[$nis,$year]);
        du_psb_reconcile($db,$current,1);adv_check(du_psb_state($db,$nis,$current)['covered_psb'],'Unpaid PSB covers only the initial year');
        $before=['placements'=>adv_count($db,'siswa_tahun_ajaran'),'spp'=>adv_count($db,'tagihan_spp'),'komite'=>adv_count($db,'tagihan_komite')];$student=hp_one($db,'SELECT * FROM siswa WHERE NO_INDUK=?',[$nis]);
        $r=du_master_save_and_complete($db,$future,$next,[$first=>'0',$first+1=>'3.500.000'],1);
        $raw=hp_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_id=?',[$nis,$future]);$bill=du_find_bill_by_id($db,(int)($raw['id']??0));
        adv_check($bill&&$bill['kelas']===(string)($first+1)&&$bill['sisa']===3500000.0,'Next grade receives its own full rate');
        adv_check($raw['penempatan_id']===null&&(int)$raw['penempatan_asal_id']===(int)$source['id'],'Future bill has source evidence and no fabricated destination');
        $e=hp_one($db,"SELECT after_data FROM daftar_ulang_audit_log WHERE tahun_ajaran_id=? AND aksi='terbitkan_awal' AND JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.no_induk'))=?",[$future,$nis]);$e=json_decode($e['after_data'],true);
        adv_check($e['rule']===LDU_VERSION&&$e['actor']===1&&$e['basis']==='admin_advance_tariff','Early invoice records the current DU rule, actor and tariff basis');
        foreach($before as $key=>$n)adv_check(adv_count($db,['placements'=>'siswa_tahun_ajaran','spp'=>'tagihan_spp','komite'=>'tagihan_komite'][$key])===$n,'Early issuance does not alter '.$key);
        adv_check($student===hp_one($db,'SELECT * FROM siswa WHERE NO_INDUK=?',[$nis]),'Current class and PSB fields stay intact');
        foreach(['final','inactive','nonacademic'] as $kind)adv_check(!hp_one($db,'SELECT id FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_id=?',[$students[$kind],$future]),'Excluded '.$kind);
        adv_check((int)$db->query(du_invalid_relation_sql($db))->fetch_row()[0]===0,'Integrity accepts legitimate early invoices');
        $orphanRejected=false;try{lf_query($db,'UPDATE tagihan_daftar_ulang SET penempatan_id=NULL,penempatan_asal_id=NULL WHERE id=?',[$bill['id']]);}catch(mysqli_sql_exception $e){$orphanRejected=$e->getCode()===1644;}
        adv_check($orphanRejected,'Database rejects a bill with neither placement nor projection basis');
        $wrong=hp_one($db,'SELECT id FROM siswa_tahun_ajaran WHERE no_induk=? AND tahun_ajaran_id=?',[$students['final'],$year]);
        lf_query($db,'UPDATE tagihan_daftar_ulang SET penempatan_asal_id=? WHERE id=?',[$wrong['id'],$bill['id']]);
        adv_reject(fn()=>du_require_selectable_bill($db,(int)$bill['id'],$nis),'Wrong source identity is not payable');
        adv_check((int)$db->query(du_invalid_relation_sql($db))->fetch_row()[0]>0,'Integrity detects wrong projection identity');
        lf_query($db,'UPDATE tagihan_daftar_ulang SET penempatan_asal_id=? WHERE id=?',[$source['id'],$bill['id']]);
        $audit=adv_count($db,'daftar_ulang_audit_log');$again=du_master_save_and_complete($db,$future,$next,[$first+1=>''],1);
        adv_check($again['created']===0&&$again['rates_saved']===0&&adv_count($db,'daftar_ulang_audit_log')===$audit,'Repeated partial save produces no bills or audit');
        $payload=du_selectable_bills_payload($db,0,0,$nis)[$nis];$selected=array_values(array_filter($payload,fn($b)=>$b['id']===(int)$bill['id']))[0];
        adv_check($selected['is_future']&&!$selected['is_current']&&!$selected['is_arrear'],'Cashier distinguishes future from current arrears');
        adv_check(!array_filter(payment_obligations($db,$nis)['rows'],fn($b)=>$b['kind']==='du'),'Future DU is not a current arrears warning');
        du_require_selectable_bill($db,(int)$bill['id'],$nis,0,true);du_assert_payment_amount($bill,500000);adv_check(true,'Future issued invoice is payable');
        adv_reject(fn()=>du_require_selectable_bill($db,(int)$bill['id'],$students['final']), 'Wrong pupil rejected');adv_reject(fn()=>du_assert_payment_amount($bill,3500001),'Overpayment rejected');
        $db->query('SET @legacy_full_apply=1');lf_query($db,'INSERT INTO bayar(NO_INDUK,TGL_BYR,BULAN,TAHUN,user_id,total_jumlah) VALUES(?,NOW(),?,?,?,500000)',[$nis,date('F'),date('Y'),'1']);$payment=(int)$db->insert_id;
        lf_query($db,'INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,?,?,?,500000)',[$payment,$bill['id'],$nis,(string)($first+1),$next]);$db->query('SET @legacy_full_apply=0');lb_cache($db,true);
        $paid=du_find_bill_by_id($db,(int)$bill['id']);adv_check($paid['sisa']===3000000.0&&$paid['terbayar']===500000.0,'Early instalment uses target-year credit');
        adv_reject(fn()=>du_assert_bill_snapshot($paid,3500000,0),'Stale balance rejected');
        du_master_save_and_complete($db,$future,$next,[$first+1=>'3.600.000'],1);adv_check(du_find_bill_by_id($db,(int)$bill['id'])['nominal_tagihan']===3500000.0,'Paid snapshot survives tariff changes');
        $remaining=payment_receipt_remaining($db,['NO_INDUK'=>$nis,'tagihan_daftar_ulang_id'=>$bill['id'],'BULAN'=>10,'TAHUN'=>2026]);adv_check($remaining[1][1]===3000000.0&&$remaining[1][0]==='Sisa DU','Receipt prioritizes future bill over current PSB coverage with the short balance label');
        $report=report_annual_evidence($db,report_filters($db,['template'=>'per-item','kategori'=>'daftar_ulang','tahun_ajaran'=>$next]));$row=array_values(array_filter($report['rows'],fn($x)=>$x['nis']===$nis))[0];adv_check($row['kelas']===(string)($first+1)&&$row['terbayar']===500000.0,'Annual report uses target class/year');
        $cash=report_payment_components($db,report_filters($db,['template'=>'penerimaan','q'=>$nis,'kelas'=>'tingkat:'.($first+1),'tanggal_awal'=>date('Y-m-d'),'tanggal_akhir'=>date('Y-m-d')]));
        adv_check(count($cash)===1&&$cash[0]['source_period']===$next&&$cash[0]['kelas']===(string)($first+1),'Cash report filters by DU obligation class, not current cash-header class');
        $page=rc_page($db,['q'=>$nis,'year'=>$next]);adv_check($page['rows']&&$page['rows'][0]['kelas']===(string)($first+1),'DU history reads unplaced future invoice');
        if($auditDb){
            $_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']=$next;du_psb_invalidate($db);lb_cache($db,true);
            adv_check(du_find_bill_by_id($db,(int)$bill['id'])['sisa']===3000000.0,'Rollover keeps pending bill visible');
            du_master_save_and_complete($db,$future,$next,[],1);
            adv_check(!hp_one($db,'SELECT id FROM siswa_tahun_ajaran WHERE no_induk=? AND tahun_ajaran_id=?',[$nis,$future]),'Rollover never fabricates placement from old live grade');
            $p=du_selectable_bills_payload($db,0,0,$nis)[$nis][0];adv_check($p['is_current']&&!$p['is_future'],'Future classification becomes current at rollover');
            unset($_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']);du_psb_invalidate($db);lb_cache($db,true);
        }
        $ctx=promotion_context($db,$year,$next);
        $db->query('SAVEPOINT advance_conflict');
        $custom=promotion_apply_student($db,$nis,$classes[$last],$ctx,(int)$source['id'],$first,false,true);
        $conflict=du_find_bill_by_id($db,(int)$bill['id']);
        adv_check($conflict['penempatan_id']===null&&$conflict['terbayar']===500000.0,'Custom class mismatch preserves bill and money without binding');
        adv_reject(fn()=>du_require_selectable_bill($db,(int)$bill['id'],$nis),'Conflicting destination cannot collect more money');
        $audits=adv_count($db,'daftar_ulang_audit_log');du_bind_advance_placement($db,$custom['target_placement_id']);
        adv_check(adv_count($db,'daftar_ulang_audit_log')===$audits,'Repeated conflict inspection does not duplicate audit');
        $db->query('ROLLBACK TO SAVEPOINT advance_conflict');lb_cache($db,true);du_psb_invalidate($db);
        $promoted=promotion_apply_student($db,$nis,$classes[$first+1],$ctx,(int)$source['id'],$first);
        $bound=du_find_bill_by_id($db,(int)$bill['id']);adv_check((int)$bound['penempatan_id']===$promoted['target_placement_id']&&$bound['sisa']===3000000.0,'Promotion binds same paid bill');
        adv_check(du_create_bill_for_placement($db,$promoted['target_placement_id'])===(int)$bill['id'],'Promotion cannot create a second invoice');
        $a=adv_count($db,'daftar_ulang_audit_log');adv_check(du_bind_advance_placement($db,$promoted['target_placement_id'])===0&&$a===adv_count($db,'daftar_ulang_audit_log'),'Binding replay is a no-op');
        lf_query($db,"UPDATE tagihan_daftar_ulang SET status='cancelled',cancel_reason='Fixture correction' WHERE id=?",[$bill['id']]);adv_reject(fn()=>du_require_selectable_bill($db,(int)$bill['id'],$nis),'Cancelled invoice cannot be collected');
        du_master_save_and_complete($db,$future,$next,[],1);adv_check(hp_one($db,'SELECT status FROM tagihan_daftar_ulang WHERE id=?',[$bill['id']])['status']==='cancelled','Repeated publication preserves cancellation');
        $beyond=du_next_year($next);$by=class_ensure_academic_year($db,$beyond);adv_reject(fn()=>du_master_save_and_complete($db,$by,$beyond,[$first+1=>'1'],1),'Beyond next year rejected');
        lf_query($db,"UPDATE tahun_ajaran SET status='closed' WHERE id=?",[$future]);adv_reject(fn()=>du_master_save_and_complete($db,$future,$next,[],1),'Closed year rejected');
    }finally{unset($_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']);$db->rollback();$db->query('SET @legacy_full_apply=0');lb_cache($db,true);du_psb_invalidate($db);unset($students);}
}
echo json_encode(['checks'=>$checks,'rollover_tested'=>$auditDb,'units'=>3],JSON_PRETTY_PRINT).PHP_EOL;
