<?php
/** Financial lifecycle on a separately restored audit server; every fixture is rolled back. */
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/psb_spp.php';require_once __DIR__.'/../includes/kelas.php';
require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/payment_student_details.php';
require_once __DIR__.'/../includes/payment_obligations.php';require_once __DIR__.'/../includes/payment_history_context.php';
$db=$koneksi;$actual=$db->query('SELECT @@port port,@@server_uuid uuid')->fetch_assoc();
if((int)$actual['port']===3306||$actual['uuid']==='e6e5c8d8-b81c-11f1-9be5-745d2224b37e')throw new RuntimeException('Separate server required.');
$actor=$db->query("SELECT id,nama FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
$checks=0;$check=static function($ok,$why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$reject=static function(callable $f,string $why)use($check){try{$f();}catch(Throwable $e){$check(true,$why);return;}$check(false,$why);};
$db->begin_transaction();
try{
    foreach([1,2,3] as $unit){
        $_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);sct_invalidate($db);lb_cache($db,true);
        $_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']='2186/2187';$nis='9988110099';[$first,$last]=unit_level_bounds();
        $psb=hp_one($db,"SELECT * FROM master_kelas WHERE tingkat=0 AND is_active=1 LIMIT 1");
        $class=hp_one($db,"SELECT * FROM master_kelas WHERE tingkat=? AND is_active=1 AND is_placeholder=0 ORDER BY id LIMIT 1",[$first]);
        lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,PSB,asal_psb,is_active) VALUES(?,'QA PSB SPP','0',?,0,15000,5000000,1,1)",[$nis,$psb['id']]);$id=(int)$db->insert_id;
        $result=pspp_save($db,$id,'325000,50','15000','2187/2188');$plan=$result['plan'];
        $check($plan['label']==='2187/2188'&&(int)$plan['tingkat']===$first,'Next-year first grade is persisted in each unit');
        $check(!hp_one($db,'SELECT id FROM siswa_tahun_ajaran WHERE no_induk=?',[$nis]),'No fake placement');
        $ids=[];foreach(['tagihan_spp','tagihan_komite'] as $t){$ids[$t]=array_column(hp_rows($db,"SELECT id FROM $t WHERE no_induk=? ORDER BY id",[$nis]),'id');$check(count($ids[$t])===12,'Twelve paired periods');}
        $version=pspp_version($db,pspp_find($db,$id));$retry=pspp_save($db,$id,'325000,50','15000','2187/2188',$version);$check($retry['changed']===0,'Replay has zero changes');
        $reject(fn()=>pspp_save($db,$id,'350000','15000','2188/2189',$version),'Destination cannot shift');
        $reject(fn()=>lf_query($db,'UPDATE tagihan_spp SET rencana_masuk_id=NULL WHERE id=?',[$ids['tagihan_spp'][0]]),'Database rejects missing basis');
        $reject(fn()=>lf_query($db,"UPDATE tagihan_komite SET tahun='2189' WHERE id=?",[$ids['tagihan_komite'][0]]),'Database rejects wrong period');
        $reject(fn()=>lf_query($db,"UPDATE tagihan_komite SET bulan='99' WHERE id=?",[$ids['tagihan_komite'][0]]),'Database rejects invalid month');
        $reject(fn()=>pspp_assert_period($db,$nis,'07','2186'),'PSB may pay only planned periods');
        pspp_assert_period($db,$nis,'07','2187');
        $details=payment_student_details($db,$nis);$check($details['admissionPlan']['oldest_period']===['bulan'=>'07','tahun'=>'2187'],'Cashier defaults to oldest planned period');
        $check(!array_filter(payment_obligations($db,$nis)['rows'],static fn($r)=>in_array($r['kind'],['spp','komite'],true)),'Future bills are not arrears');
        $reject(fn()=>komite_validate_amount($db,$nis,'07','2187',0,true),'SPP requires Komite');
        $reject(fn()=>komite_validate_spp_pair($db,$nis,'07','2187',15000,false),'Komite requires SPP');
        $reject(fn()=>spp_allocate_payment($db,$nis,null,'08','2187',325000.50,'2186-11-09 10:00:00','Tunai',(string)$actor['id']),'Oldest first');
        $reject(fn()=>spp_allocate_payment($db,$nis,null,'07','2187',325000,'2186-11-09 10:00:00','Tunai',(string)$actor['id']),'Exact SPP amount');
        lf_query($db,"INSERT INTO bayar(NO_INDUK,KELAS,U_SPP,U_KOMITE,BULAN,TAHUN,TGL_BYR,total_jumlah,user_id,payment_link_version) VALUES(?,'0',325000.50,15000,'07','2187','2186-11-09 10:00:00',340000.50,?,1)",[$nis,(string)$actor['id']]);$payment=(int)$db->insert_id;
        $bill=komite_validate_amount($db,$nis,'07','2187',15000,true);komite_save_payment($db,$payment,$bill,15000);
        spp_allocate_payment($db,$nis,$payment,'07','2187',325000.50,'2186-11-09 10:00:00','Tunai',(string)$actor['id']);
        $reject(fn()=>pspp_save($db,$id,'400000','20000','2187/2188',$version),'Stale tariff form rejected after payment');
        $paidBill=hp_one($db,'SELECT * FROM tagihan_spp WHERE id=?',[$ids['tagihan_spp'][0]]);
        pspp_save($db,$id,'400000','20000','2187/2188',pspp_version($db,pspp_find($db,$id)));
        $check(hp_one($db,'SELECT * FROM tagihan_spp WHERE id=?',[$paidBill['id']])===$paidBill,'Paid SPP snapshot preserved');
        $check((float)sct_rule($db,$nis,'07','2187')['nominal_akhir']===325000.50,'Paid individual rule preserved');
        $check(spp_published_period_status($db,$nis,'07','2187')['status']==='already_paid','Paid July not requested twice');
        $master=spp_master_ensure_year($db,'2187/2188');$m=(int)$master['id'];
        $check(spp_master_rates_editable(spp_master_state($db,$m)),'PSB publication leaves regular tariff setup available');
        spp_master_save_rates($db,$m,array_fill($first+1,$last-$first,500000));
        $context=sct_context_for_student($db,$m,$id);
        sct_save($db,$m,$id,'2187-08','425000.50','QA shared individual tariff',$context['version']);
        $check((float)sct_rule($db,$nis,'08','2187')['nominal_akhir']===425000.50,'Master SPP uses the same individual tariff');
        $filters=report_filters($db,['template'=>'spp-tahunan','tahun_ajaran'=>'2187/2188','student_id'=>$id,'siswa_status'=>'all','kategori'=>'spp']);
        $before=[];
        foreach(['spp','komite'] as $kind){$rows=report_monthly_evidence($db,$kind,report_academic_months('2187/2188'),$filters);$check(count($rows)===1,'PSB appears in monthly report');$before[$kind]=$rows[0]['total_bayar'];$check($before[$kind]===($kind==='spp'?325000.50:15000.0),'PSB receipts appear once in obligation report');}
        $historyFilters=report_filters($db,['template'=>'riwayat-tagihan','student_id'=>$id,'siswa_status'=>'all']);$historyFilters['_billing_as_of']='';
        $history=report_billing_history_data($db,$historyFilters);
        $check(count(array_filter($history['rows'],static fn($r)=>in_array($r['komponen_key']??'', ['spp','komite'],true)))===24,'Billing history includes unplaced Komite');
        $_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']='2187/2188';$check(pspp_find($db,$id)['label']==='2187/2188','July rollover does not shift plan');
        lf_query($db,'UPDATE siswa SET KELAS=?,master_kelas_id=?,SPP_PERBULAN=425000.50,POMG=20000 WHERE id=?',[$first,$class['id'],$id]);
        $sync=null;$pid=class_sync_student_current_year($db,$nis,(int)$class['id'],425000.50,20000,true,$sync);
        $check($pid>0,'Official placement exists');$check(pspp_bind_placement($db,$pid)===0,'Binding replay is zero');
        foreach(['tagihan_spp','tagihan_komite'] as $t){$check($ids[$t]===array_column(hp_rows($db,"SELECT id FROM $t WHERE no_induk=? ORDER BY id",[$nis]),'id'),'Bill IDs unchanged after placement');$check(!hp_one($db,"SELECT id FROM $t WHERE no_induk=? AND penempatan_id IS NULL",[$nis]),'Existing bills bound in place');}
        foreach(['spp','komite'] as $kind){$rows=report_monthly_evidence($db,$kind,report_academic_months('2187/2188'),$filters);$check(count($rows)===1&&$rows[0]['total_bayar']===$before[$kind]&&$rows[0]['kelas']===class_label($class),'Report follows rombel without duplicate cash');}
        $check((int)hp_one($db,'SELECT spp_covered_by_psb FROM siswa_tahun_ajaran WHERE id=?',[$pid])['spp_covered_by_psb']===0,'No new free SPP coverage');
        $check((string)hp_one($db,'SELECT KELAS FROM bayar WHERE id=?',[$payment])['KELAS']==='0','Cash keeps original PSB context');
        $reject(fn()=>spp_allocate_payment($db,$nis,null,'07','2187',325000.50,'2187-07-02','Tunai',(string)$actor['id']),'Already paid period rejected');
        $check((float)komite_bill($db,$nis,'07','2187')['remaining']===0.0,'Komite credit follows placement');
        $check(du_psb_state($db,$nis,'2187/2188')['covered_psb'],'DU coverage remains separate from paid SPP');
        $allocations=hp_rows($db,'SELECT a.* FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id AND b.unit_id=a.unit_id WHERE b.no_induk=? ORDER BY a.id',[$nis]);
        $nextClass=hp_one($db,'SELECT id FROM master_kelas WHERE tingkat=? AND is_active=1 AND is_placeholder=0 LIMIT 1',[$first+1]);
        $promoted=promotion_apply_student($db,$nis,(int)$nextClass['id'],promotion_context($db,(int)$plan['tahun_ajaran_id']),$pid,$first);
        $check((int)$promoted['target_placement_id']!==$pid,'Later promotion creates its own academic placement');
        $check((int)pspp_find($db,$id)['penempatan_id']===$pid,'Admission plan stays bound to original year');
        $check($allocations===hp_rows($db,'SELECT a.* FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id AND b.unit_id=a.unit_id WHERE b.no_induk=? ORDER BY a.id',[$nis]),'Promotion preserves payment allocation IDs and amounts');
        $check((int)pspp_find($db,$id)['unit_id']===$unit,'Same NIS remains isolated per unit');
        $_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']='2186/2187';$zeroNis='9988110088';
        lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB,is_active) VALUES(?,'QA explicit zero','0',?,1000,1)",[$zeroNis,$psb['id']]);$zeroId=(int)$db->insert_id;
        pspp_save($db,$zeroId,'0','0','2187/2188');
        $check(spp_published_period_status($db,$zeroNis,'07','2187')['code']==='waived','Explicit zero is waived, never an unknown empty amount');
        $check(payment_student_details($db,$zeroNis)['admissionPlan']['oldest_period']===null,'Zero pair does not request payment');
    }
    unit_set_context($db,0);$check(count(hp_rows($db,'SELECT id FROM psb_spp_plans WHERE student_id IN (SELECT id FROM siswa WHERE NO_INDUK=?)',['9988110099']))===3,'All Units reads all three identities separately');
}finally{$db->rollback();unset($_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']);}
echo "PASS $checks PSB SPP lifecycle, payment, schema, report, rollover and unit checks; rolled back.\n";
