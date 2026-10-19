<?php
/** Combined Legacy + remote workflow regression. Only an explicitly verified isolated DB. */
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require __DIR__.'/../includes/legacy_import_http.php';require __DIR__.'/../includes/reports.php';require __DIR__.'/../includes/savings_workspace.php';require __DIR__.'/legacy_finance_support.php';
require __DIR__.'/../includes/savings_authorization.php';require_once __DIR__.'/../includes/history_operator_filter.php';
$db=$koneksi;$super=(int)lf_one($db,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1")['id'];$admin=(int)lf_one($db,"SELECT id FROM admin WHERE role='admin' AND is_active=1 LIMIT 1")['id'];
$_SESSION=['admin_id'=>$super,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($db,1);
lft_assert(class_kind(['tingkat'=>0,'kode_rombel'=>'Alumni2025'])==='alumni'&&class_label(['tingkat'=>0,'kode_rombel'=>'Alumni2025'])==='Alumni2025','Alumni class lost');
lft_assert(class_label(['jenis'=>'custom','nama_custom'=>'Tabungan Guru','tingkat'=>null])==='Tabungan Guru'&&class_label(['jenis'=>'out','tingkat'=>null])==='Out','Remote nonacademic class lost');
lft_assert(report_status_key('Ada Pembayaran')==='ada_pembayaran'&&report_status_key('Tunggakan Legacy')==='belum_bayar','Report statuses lost');
$db->begin_transaction();try{
    $ledger=savings_locked_ledger($db,'232400001');lft_assert($ledger['opening']===85000&&$ledger['balance']===85000,'Remote savings ledger omitted Legacy opening');
    $withdrawal=['id'=>PHP_INT_MAX,'jenis'=>'keluar','TANGGAL'=>'2026-10-19 09:00:00','MASUK'=>0,'KELUAR'=>500];
    lft_assert(savings_ledger_balance([$withdrawal],true,$ledger['opening'])===35000,'Withdrawal/correction did not carry Legacy opening once');
    $withdrawal['KELUAR']=851;$rejected=false;try{savings_ledger_balance([$withdrawal],true,$ledger['opening']);}catch(RuntimeException $e){$rejected=true;}lft_assert($rejected,'Withdrawal exceeded Legacy opening');
    $source=lful_payment_source($db);$ops=array_keys(lful_operator_options($db));$where=history_operator_where($db,$ops,'p.id','p.unit_id');
    lft_assert((int)lf_one($db,"SELECT COUNT(*) n FROM $source p WHERE p.id<-1000000000".$where)['n']===1,'Operational Legacy payments lost operator filter');
}finally{$db->rollback();}
$db->begin_transaction();try{
    lf_query($db,"INSERT INTO master_kelas(tingkat,kode_rombel,jenis,nama_custom,is_active) VALUES(NULL,'SYNC_TEST','custom','Tabungan Guru Sinkronisasi',1)");$class=(int)$db->insert_id;
    lf_query($db,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES('990000001','Pemilik Rekening Sinkronisasi','CUSTOM',?,1)",[$class]);
    lf_query($db,"INSERT INTO tabungan(NO_INDUK,SALDO) VALUES('990000001',10000)");
    lf_query($db,"INSERT INTO transaksi_m(NO_INDUK,TANGGAL,MASUK,user_id,keterangan) VALUES('990000001','2026-10-19 08:01:00',10000,?,'Uji gabungan')",[(string)$super]);$transaction=(int)$db->insert_id;
    $f=report_filters($db,['tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-31','siswa_status'=>'all']);
    $balance=report_savings_balance_data($db,$f);$custom=array_values(array_filter($balance['rows'],fn($r)=>$r['nis']==='990000001'));lft_assert(count($custom)===1&&$custom[0]['kelas']==='Tabungan Guru Sinkronisasi','Custom balance/class label lost');
    $rows=report_savings_transactions($db,'2026-10-01','2026-11-01',$f);lft_assert(count(array_filter($rows,fn($r)=>$r['NO_INDUK']==='990000001'))===1,'Custom savings transaction lost');
    $cash=$f;$cash['_school_savings_cash']=true;$rows=report_savings_transactions($db,'2026-10-01','2026-11-01',$cash);lft_assert(!array_filter($rows,fn($r)=>$r['NO_INDUK']==='990000001'),'Custom savings entered school cash');
    $detail=savings_transaction($db,'masuk',$transaction);lft_assert($detail['kelas_label']==='Tabungan Guru Sinkronisasi','Custom transaction detail lost');
    $operators=lful_operator_options($db);$legacy=array_key_first($operators);lft_assert($legacy!==null,'Published Legacy fixture missing');$mixed=$f;$mixed['_savings_operator']=true;$mixed['_multi']['operator']=[$legacy,(string)$super];
    $rows=report_savings_transactions($db,'2026-10-01','2026-11-01',$mixed);lft_assert((bool)array_filter($rows,fn($r)=>$r['NO_INDUK']==='990000001'),'Mixed native/Legacy operator omitted native receipt');
    $mixed['_multi']['operator']=[$legacy];$rows=report_savings_transactions($db,'2019-01-01','2027-01-01',$mixed);lft_assert((bool)array_filter($rows,fn($r)=>str_starts_with((string)$r['user_id'],'legacy:')),'Legacy operator filter lost history');
    $adjustments=array_filter($rows,fn($r)=>($r['legacy_origin']??'')==='adjustment');foreach($adjustments as $r)lft_assert((float)$r['masuk']===0.0&&(float)$r['keluar']===0.0,'Adjustment counted as cash');
}finally{$db->rollback();}
$_SESSION=['admin_id'=>$admin,'admin_role'=>'admin','active_unit_id'=>1];legacy_http_actor($db,'students',true);lft_reject(fn()=>legacy_http_actor($db,'full',true),'Admin gained full finance migration');
$_SESSION=['admin_id'=>$super,'admin_role'=>'super_admin','active_unit_id'=>1];legacy_http_actor($db,'full',true);
echo "PASS: Alumni/Custom/OUT, Legacy and remote report statuses, custom savings/cash exclusion, details, mixed operators, noncash adjustments, Admin student import and Super Admin-only finance.\n";
