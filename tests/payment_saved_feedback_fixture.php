<?php
/** Native obligations for the post-save flow, only on a verified separate server. */
require_once __DIR__.'/http_form_scope.php';
spp_test_assert_database_target();
session_start();
require_once __DIR__.'/../koneksi.php';
spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/spp_billing.php';
require_once __DIR__.'/../includes/komite_billing.php';
require_once __DIR__.'/../includes/daftar_ulang.php';
$file=(string)getenv('SPP_PAYMENT_SAVED_FIXTURE');
if(!$file||str_starts_with(strtolower(str_replace('\\','/',$file)),strtolower(str_replace('\\','/',dirname(__DIR__))).'/'))throw new RuntimeException('Private fixture output required.');
if(($argv[1]??'')==='scope-mismatch'){
    $fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);unit_set_context($koneksi,1);
    $nis=$fixture['cases']['psb']['payload']['no_induk'];$q=$koneksi->prepare('SELECT id FROM bayar WHERE NO_INDUK=? ORDER BY id LIMIT 1');$q->bind_param('s',$nis);$q->execute();$id=(int)$q->get_result()->fetch_row()[0];$q->close();
    session_write_close();session_id($fixture['sessions'][2]);session_start();
    $_SESSION['flash']=['type'=>'success','msg'=>'Data pembayaran berhasil disimpan!','print_payment'=>['source'=>'input','id'=>$id,'unit_id'=>1,'actor_id'=>(int)$_SESSION['admin_id']]];
    session_write_close();echo 'Prepared a saved result from a different active unit.';exit;
}
if(($argv[1]??'')==='state'){
    $fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$rows=[];
    foreach($fixture['cases'] as $key=>$case){
        unit_set_context($koneksi,$case['unit']);
        $q=$koneksi->prepare('SELECT id,U_PSB,U_SPP,U_KOMITE,total_jumlah FROM bayar WHERE NO_INDUK=? ORDER BY id');$q->bind_param('s',$case['payload']['no_induk']);$q->execute();$rows[$key]=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    }
    echo json_encode($rows,JSON_THROW_ON_ERROR);exit;
}
if(($argv[1]??'')!=='setup'||file_exists($file))throw new RuntimeException('Use setup once on a fresh owned clone.');
$actor=$koneksi->query("SELECT id,nama FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
if(!$actor)throw new RuntimeException('Active super admin required.');
$_SESSION=['admin_id'=>(int)$actor['id'],'admin_role'=>'super_admin','admin_nama'=>$actor['nama'],'active_unit_id'=>1];
$fixture=['sessions'=>[],'cases'=>[]];$start=2000;
while($koneksi->query("SELECT id FROM tahun_ajaran WHERE label='$start/".($start+1)."'")->num_rows)$start++;
if($start>=(int)substr(du_current_academic_year(),0,4))throw new RuntimeException('Free historical fixture year required.');
$year=$start.'/'.($start+1);$start=(string)$start;
foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);$_SESSION['active_unit_id']=$unit;
    $level=[1=>2,2=>8,3=>11][$unit];
    $class=$koneksi->query("SELECT id,kode_rombel FROM master_kelas WHERE tingkat=$level AND is_placeholder=0 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
    if(!$class)throw new RuntimeException('Fixture class unavailable.');
    $koneksi->begin_transaction();
    try{
        $master=spp_master_ensure_year($koneksi,$year,true);
        [$first,$last]=unit_level_bounds();spp_master_save_rates($koneksi,(int)$master['id'],array_fill($first,$last-$first+1,250000));
        $rates=spp_master_rates($koneksi,(int)$master['id']);$rate=(float)($rates[$level]??0);
        if($unit===1&&$rate<=0)throw new RuntimeException('Published SPP tariff required.');
        $yearId=(int)$master['tahun_ajaran_id'];$classId=(int)$class['id'];$classLabel=$level.$class['kode_rombel'];
        $koneksi->query("UPDATE tahun_ajaran SET status='published' WHERE id=$yearId");
        $kinds=$unit===1?['psb','spp','komite','du','other','mixed','browser','nojs']:['unit'.$unit];
        foreach($kinds as $kind){
            $nis=(string)random_int(9500000000,9599999999);$name='SAVED FEEDBACK QA '.strtoupper($kind).' '.$nis;
            $spp=in_array($kind,['spp','mixed'],true)?$rate:0;$komite=in_array($kind,['komite','mixed'],true)?15000:0;
            $psb=in_array($kind,['psb','mixed','browser','nojs','unit2','unit3'],true)?50000:0;$du=in_array($kind,['du','mixed'],true)?100000:0;$other=in_array($kind,['other','mixed'],true)?20000:0;
            $levelString=(string)$level;
            $q=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,PSB,is_active) VALUES(?,?,?,?,?,?,500000,1)');$q->bind_param('sssidd',$nis,$name,$levelString,$classId,$spp,$komite);$q->execute();$studentId=(int)$koneksi->insert_id;$q->close();
            $q=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,?,?,'aktif')");$q->bind_param('issisdd',$yearId,$nis,$levelString,$classId,$classLabel,$spp,$komite);$q->execute();$placementId=(int)$koneksi->insert_id;$q->close();
            if($spp>0)spp_publish_students($koneksi,(int)$master['id'],[$nis]);
            if($komite>0)komite_sync_placement($koneksi,$placementId);
            $payload=['aksi'=>'input','_operational_unit'=>$unit,'no_induk'=>$nis,'tanggal_bayar'=>date('Y-m-d'),'bulan_bayar'=>'07','tahun_bayar'=>$start,'sistem_pembayaran'=>'Tunai','payment_plan'=>'monthly','spp_action'=>'bayar','uang_psb'=>(string)$psb,'uang_spp'=>(string)$spp,'uang_komite'=>(string)$komite,'uang_du'=>(string)$du];
            if($du>0){
                $q=$koneksi->prepare('INSERT INTO Daftar_ulang(tahun_ajaran_id,th_ajaran,kelas,Jumlah) VALUES(?,?,?,1000000) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');$q->bind_param('iss',$yearId,$year,$levelString);$q->execute();$q->close();
                $bill=du_create_bill_for_placement($koneksi,$placementId,false);if(!$bill)throw new RuntimeException('DU fixture not issued.');$payload['tagihan_daftar_ulang_id']=$bill;
            }
            if($other>0){
                $q=$koneksi->prepare("INSERT INTO master_biaya_lain(nama,nominal,jenis_pembayaran,is_active) VALUES(?,20000,'berulang',1)");$q->bind_param('s',$name);$q->execute();$feeId=(int)$koneksi->insert_id;$q->close();
                $payload['biaya_lain_tagihan_id']=['master:'.$feeId];$payload['biaya_lain_nominal']=['20000'];$payload['biaya_lain_keterangan']=['Uji popup hasil input'];
            }
            $fixture['cases'][$kind]=['unit'=>$unit,'name'=>$name,'student_id'=>$studentId,'total'=>$psb+$spp+$komite+$du+$other,'payload'=>$payload];
        }
        $koneksi->commit();
    }catch(Throwable $error){$koneksi->rollback();throw $error;}
}
session_write_close();
foreach([1,2,3] as $unit){
    $id='savedqa'.bin2hex(random_bytes(12));session_id($id);session_start();
    $_SESSION=['admin_id'=>(int)$actor['id'],'admin_role'=>'super_admin','admin_nama'=>$actor['nama'],'admin_unit_id'=>null,'active_unit_id'=>$unit];session_write_close();$fixture['sessions'][$unit]=$id;
}
file_put_contents($file,json_encode($fixture,JSON_THROW_ON_ERROR));echo 'Created guarded fixtures for 10 payment flows.'.PHP_EOL;
