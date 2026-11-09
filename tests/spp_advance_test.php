<?php
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/spp_advance.php';require_once __DIR__.'/../includes/kelas.php';
require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/payment_obligations.php';
$db=$koneksi;$identity=$db->query('SELECT @@port port,@@server_uuid uuid')->fetch_assoc();
if((int)$identity['port']===3306||$identity['uuid']==='e6e5c8d8-b81c-11f1-9be5-745d2224b37e')throw new RuntimeException('Separate restored server required.');
$checks=0;$check=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$reject=static function(callable $f,$message)use($check){try{$f();}catch(Throwable $e){$check(true,$message);return;}$check(false,$message);};
$pay=static function(string $nis,string $month,string $year,float $spp,float $komite)use($db):int{
    $bill=komite_validate_amount($db,$nis,$month,$year,$komite,$spp>.001);komite_validate_spp_pair($db,$nis,$month,$year,$komite,$spp>.001);
    $student=hp_one($db,'SELECT KELAS FROM siswa WHERE NO_INDUK=?',[$nis]);
    lf_query($db,'INSERT INTO bayar(NO_INDUK,KELAS,U_SPP,U_KOMITE,BULAN,TAHUN,TGL_BYR,total_jumlah,user_id,payment_link_version) VALUES(?,?,?,?,?,?,?,?,?,1)',[$nis,$student['KELAS'],$spp,$komite,$month,$year,'2186-11-10 10:00:00',$spp+$komite,(string)$_SESSION['admin_id']]);$id=(int)$db->insert_id;
    komite_save_payment($db,$id,$bill,$komite);if($spp>.001)spp_allocate_payment($db,$nis,$id,$month,$year,$spp,'2186-11-10 10:00:00','Tunai',(string)$_SESSION['admin_id']);return $id;
};
$db->begin_transaction();
try{
    foreach([1,2,3,4] as $scope){unit_set_context($db,$scope);$db->query('UPDATE siswa SET is_active=0');}
    $super=hp_one($db,"SELECT id,nama FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1");
    $admin=hp_one($db,"SELECT id,nama FROM admin WHERE role='admin' AND is_active=1 LIMIT 1");
    if(!$admin){lf_query($db,"INSERT INTO admin(username,password,nama,role,is_active) VALUES('qa_advance_admin',?,'QA advance admin','admin',1)",[password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT)]);$admin=['id'=>$db->insert_id,'nama'=>'QA advance admin'];}
    foreach(['super_admin'=>$super,'admin'=>$admin] as $role=>$actor)foreach([1,2,3] as $unit){
        $_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>$role,'active_unit_id'=>$unit];unit_set_context($db,$unit);sct_invalidate($db);lb_cache($db,true);$_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']='2186/2187';
        [$first,$last]=unit_level_bounds();$grade=$unit===1?4:$last-1;$nis='998810'.($role==='admin'?'2':'1').'001';$class=hp_one($db,'SELECT * FROM master_kelas WHERE tingkat=? AND is_active=1 AND is_placeholder=0 LIMIT 1',[$grade]);$target=hp_one($db,'SELECT * FROM master_kelas WHERE tingkat=? AND is_active=1 AND is_placeholder=0 LIMIT 1',[$grade+1]);
        lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,'QA advance',?,?,100000,15000,1)",[$nis,$grade,$class['id']]);$id=(int)$db->insert_id;
        $current=spp_master_ensure_year($db,'2186/2187');$cm=(int)$current['id'];$rates=array_fill($first+1,$last-$first,100000);
        $sync=null;$origin=class_sync_student_current_year($db,$nis,(int)$class['id'],100000,15000,true,$sync);
        if(spp_master_rates_editable(spp_master_state($db,$cm)))spp_master_save_rates($db,$cm,$rates);
        spp_publish_students($db,$cm,[$nis]);
        $future=spp_master_ensure_year($db,'2187/2188');$fm=(int)$future['id'];$futureRates=array_fill($first+1,$last-$first,325000.50);
        if(spp_master_rates_editable(spp_master_state($db,$fm)))spp_master_save_rates($db,$fm,$futureRates);
        $probe=spa_roster($db,'2187/2188')[$id]??[];
        if(!empty($probe['issue_reason']))throw new RuntimeException(json_encode(['roster'=>$probe,'origin'=>hp_one($db,'SELECT * FROM siswa_tahun_ajaran WHERE id=?',[$origin]),'year'=>du_current_academic_year()],JSON_THROW_ON_ERROR));
        $issued=spa_complete_year($db,$fm);$check($issued['students']>=1,'Whole roster published');
        $check(!hp_one($db,'SELECT id FROM siswa_tahun_ajaran WHERE no_induk=? AND tahun_ajaran_id=?',[$nis,$future['tahun_ajaran_id']]),'No temporary academic placement');
        foreach(['spp','komite'] as $kind){$rows=hp_rows($db,"SELECT * FROM tagihan_$kind WHERE no_induk=? AND tahun_ajaran_id=?",[$nis,$future['tahun_ajaran_id']]);$check(count($rows)===12,'Twelve future periods in each component');$check(count(array_filter($rows,static fn($r)=>(int)$r['penempatan_asal_id']===$origin&&$r['penempatan_id']===null))===12,'All future periods keep valid origin');}
        $check(spa_complete_year($db,$fm)['created']===0,'Replay creates zero SPP');
        if($unit===1&&$role==='super_admin'){
            $db->query('SAVEPOINT advance_edges');
            lf_query($db,"DELETE FROM tagihan_spp WHERE no_induk=? AND tahun='2188' AND bulan='06'",[$nis]);
            $check(!spa_roster($db,'2187/2188')[$id]['billing_complete'],'One missing month is incomplete');
            $check(spa_complete_year($db,$fm)['created']===1,'Replay fills precisely the missing month');
            $check(spa_roster($db,'2187/2188')[$id]['billing_complete'],'Completion includes both monthly components');
            $db->query('ROLLBACK TO SAVEPOINT advance_edges');
            $db->query('SAVEPOINT advance_conflict');
            lf_query($db,"INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES(?,?,?,?,?,'aktif')",[$future['tahun_ajaran_id'],$nis,$grade,$class['id'],class_label($class)]);$wrong=(int)$db->insert_id;
            $reject(fn()=>spa_bind_placement($db,$wrong),'Mismatched grade cannot absorb advance payments');
            $db->query('ROLLBACK TO SAVEPOINT advance_conflict');
            $db->query('SAVEPOINT advance_failure');
            lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES('9988100999','QA incomplete placement',?,?,1)",[$grade,$class['id']]);
            $reject(fn()=>spa_complete_year($db,$fm),'Invalid student prevents silent partial issuance');
            $db->query('ROLLBACK TO SAVEPOINT advance_failure');
            $check(spa_complete_year($db,$fm)['created']===0,'Failed issuance leaves no duplicate invoices');
        }
        $reject(fn()=>spa_complete_year($db,(int)spp_master_ensure_year($db,'2188/2189')['id']),'No two-year projection');
        $check(spp_published_period_status($db,$nis,'07','2187')['status']==='prior_unpaid','Current debt blocks future SPP');
        $reject(fn()=>komite_validate_amount($db,$nis,'07','2187',15000,true),'Current Komite debt blocks future payment');
        foreach(spp_academic_periods('2186/2187') as $p)$pay($nis,$p['bulan'],$p['tahun'],100000,15000);
        $check(spp_published_period_status($db,$nis,'07','2187')['status']==='payable','Future becomes payable only after current full year paid');
        $check(!array_filter(payment_obligations($db,$nis)['rows'],static fn($r)=>in_array($r['kind'],['spp','komite'],true)),'Future unpaid bills not current arrears');
        $reject(fn()=>komite_validate_amount($db,$nis,'07','2187',0,true),'SPP still requires Komite');
        $reject(fn()=>komite_validate_spp_pair($db,$nis,'07','2187',15000,false),'Komite still requires SPP');
        $reject(fn()=>spp_allocate_payment($db,$nis,null,'08','2187',325000.50,'2186-11-10','Tunai',(string)$actor['id']),'Future months stay chronological');
        $payment=$pay($nis,'07','2187',325000.50,15000);
        $check(spp_published_period_status($db,$nis,'07','2187')['status']==='already_paid','Duplicate period protected');
        $context=sct_context_for_student($db,$fm,$id);sct_save($db,$fm,$id,'2187-07','400000','QA individual future tariff',$context['version']);
        $check((float)hp_one($db,"SELECT nominal_tagihan FROM tagihan_spp WHERE no_induk=? AND tahun='2187' AND bulan='07'",[$nis])['nominal_tagihan']===325000.50,'Paid tariff preserved');
        $check((float)sct_rule($db,$nis,'08','2187')['nominal_akhir']===400000.0,'Individual future tariff stored without placement');
        $reject(fn()=>sct_save($db,$fm,$id,'2187-08','450000','QA stale future tariff',$context['version']),'Stale tariff request rejected');
        komite_sync_student_rate($db,$nis,20000,$origin);
        $check((float)komite_bill($db,$nis,'07','2187')['nominal_tagihan']===15000.0,'Paid future Komite protected');
        $check((float)komite_bill($db,$nis,'08','2187')['nominal_tagihan']===20000.0,'Master Siswa Komite syncs future unpaid bill');
        $filters=report_filters($db,['template'=>'spp-tahunan','tahun_ajaran'=>'2187/2188','student_id'=>$id,'siswa_status'=>'all','kategori'=>'spp']);$before=[];
        foreach(['spp','komite'] as $kind){$rows=report_monthly_evidence($db,$kind,report_academic_months('2187/2188'),$filters);$check(count($rows)===1,'Unplaced advance appears in target report');$before[$kind]=$rows[0]['total_bayar'];$check($before[$kind]===($kind==='spp'?325000.50:15000.0),'Target report counts cash once');}
        $historyFilter=report_filters($db,['template'=>'riwayat-tagihan','student_id'=>$id,'siswa_status'=>'all']);$historyFilter['_billing_as_of']='';$history=report_billing_history_data($db,$historyFilter);
        $check(count(array_filter($history['rows'],static fn($r)=>in_array($r['komponen_key']??'',['spp','komite'],true)))===48,'History retains current and future obligations');
        $visibleFilter=report_filters($db,['template'=>'riwayat-tagihan','student_id'=>$id,'siswa_status'=>'all']);$visibleHistory=report_billing_history_data($db,$visibleFilter);
        $check(count(array_filter($visibleHistory['rows'],static fn($r)=>in_array($r['komponen_key']??'',['spp','komite'],true)&&($r['periode_code']??'')==='2187-07'))===2,'Default history retains paid future month');
        $ids=hp_rows($db,'SELECT id,bulan,tahun FROM tagihan_spp WHERE no_induk=? ORDER BY id',[$nis]);$alloc=hp_rows($db,'SELECT a.* FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id WHERE b.no_induk=? ORDER BY a.id',[$nis]);
        $reject(fn()=>lf_query($db,"UPDATE tagihan_spp SET penempatan_asal_id=NULL WHERE no_induk=? AND tahun='2187' AND bulan='08'",[$nis]),'Database rejects removed origin');
        $reject(fn()=>lf_query($db,"UPDATE tagihan_komite SET bulan='99' WHERE no_induk=? AND tahun='2187' AND bulan='08'",[$nis]),'Database rejects malformed future period');
        $db->query('SAVEPOINT correction_order');$old=hp_one($db,"SELECT id FROM bayar WHERE NO_INDUK=? AND TAHUN='2186' AND BULAN='07'",[$nis]);spp_reverse_payment_allocation($db,(int)$old['id']);$reject(fn()=>spp_assert_paid_order($db,$nis),'Cannot reopen prior debt after future payment');$db->query('ROLLBACK TO SAVEPOINT correction_order');
        $_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']='2187/2188';$check(count(spa_roster($db,'2187/2188'))>=1,'Future invoices remain readable across rollover');
        $promoted=promotion_apply_student($db,$nis,(int)$target['id'],promotion_context($db,(int)$current['tahun_ajaran_id']),$origin,$grade);$pid=(int)$promoted['target_placement_id'];
        $check(spa_bind_placement($db,$pid)===0,'Placement bind replay is zero');
        $check($ids===hp_rows($db,'SELECT id,bulan,tahun FROM tagihan_spp WHERE no_induk=? ORDER BY id',[$nis]),'Promotion preserves bill IDs');
        $check($alloc===hp_rows($db,'SELECT a.* FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id WHERE b.no_induk=? ORDER BY a.id',[$nis]),'Promotion preserves payment allocation IDs');
        foreach(['spp','komite'] as $kind){$check(!hp_one($db,"SELECT id FROM tagihan_$kind WHERE no_induk=? AND tahun_ajaran_id=? AND penempatan_id IS NULL",[$nis,$future['tahun_ajaran_id']]),'Target bills all bound');$rows=report_monthly_evidence($db,$kind,report_academic_months('2187/2188'),$filters);$check(count($rows)===1&&$rows[0]['total_bayar']===$before[$kind]&&$rows[0]['kelas']===class_label($target),'Rombel report retains exact paid totals');}
        $check((int)hp_one($db,'SELECT KELAS FROM bayar WHERE id=?',[$payment])['KELAS']===$grade,'Original cash class stays unchanged');
        $pay($nis,'08','2187',400000,20000);$check(spp_published_period_status($db,$nis,'09','2187')['status']==='payable','Payment continues after promotion');
    }
    unit_set_context($db,0);$check(count(hp_rows($db,"SELECT id FROM siswa WHERE NO_INDUK='9988101001'"))===3,'Same NIS isolated across three units');$reject(fn()=>spa_complete_year($db,1),'All Units cannot issue');
}finally{$db->rollback();unset($_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']);}
echo "PASS $checks advance lifecycle, roles, units, tariffs, ordering, reports and promotion checks; rolled back.\n";
