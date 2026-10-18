<?php
/** Disposable-instance fixture only. Never inserts into an operational database. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/savings_authorization.php';
$root=getenv('SPP_REFINEMENT_QA');if(!$root||!is_dir($root))throw new RuntimeException('Private QA root required');
if(($argv[1]??'')==='state'){
    $f=json_decode(file_get_contents($root.'/fixture.json'),true,512,JSON_THROW_ON_ERROR);$result=[];
    foreach($f['students'] as $unit=>$item){unit_set_context($koneksi,(int)$unit);$id=$item['in'];$out=$item['out'];$nis=$koneksi->real_escape_string($item['nis']);
        $result[$unit]=['in'=>$koneksi->query("SELECT MASUK amount,user_id FROM transaksi_m WHERE id=$id")->fetch_assoc(),
            'out'=>$koneksi->query("SELECT KELUAR amount,user_id FROM transaksi_k WHERE id=$out")->fetch_assoc(),
            'balance'=>$koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()[0],
            'events'=>$koneksi->query("SELECT action,COUNT(*) n FROM tabungan_aktivitas WHERE transaction_id IN ($id,$out) GROUP BY action")->fetch_all(MYSQLI_ASSOC),
            'requests'=>$koneksi->query("SELECT jenis,action,status FROM tabungan_otorisasi WHERE transaction_id IN ($id,$out) ORDER BY id")->fetch_all(MYSQLI_ASSOC)];
    }echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}
unit_set_context($koneksi,0);$actors=[];
foreach(['super_admin','admin','kasir','bendahara'] as $role){$r=$koneksi->query("SELECT id,nama,unit_id FROM admin WHERE role='$role' AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();if(!$r)throw new RuntimeException('Missing role fixture');$actors[$role]=$r;}
$f=['students'=>[],'sessions'=>[]];$suffix=bin2hex(random_bytes(3));$nis='QR'.$suffix;$owner=(int)$actors['super_admin']['id'];
foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);$class=$koneksi->query('SELECT id,tingkat FROM master_kelas WHERE is_active=1 AND is_placeholder=0 ORDER BY tingkat,id LIMIT 1')->fetch_assoc();
    $name='Siswa Uji Refine '.$unit;$level=(string)$class['tingkat'];$cid=(int)$class['id'];$emptyNis=$nis.'E';$emptyName='Siswa Tanpa Pembayaran '.$unit;
    foreach([[$nis,$name],[$emptyNis,$emptyName]] as [$n,$label]){$s=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB) VALUES(?,?,?,?,100000)');$s->bind_param('sssi',$n,$label,$level,$cid);$s->execute();$sid=(int)$koneksi->insert_id;if($n===$nis)$studentId=$sid;else $emptyId=$sid;$s->close();}
    $koneksi->query("INSERT INTO tabungan(NO_INDUK,SALDO) VALUES('$nis',90000)");
    $koneksi->query("INSERT INTO transaksi_m(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES('$nis','2026-10-01 08:00:00',100000,0,'$owner','Setoran uji')");$in=(int)$koneksi->insert_id;
    $koneksi->query("INSERT INTO transaksi_k(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES('$nis','2026-10-02 08:00:00',0,10000,'$owner','Penarikan uji')");$out=(int)$koneksi->insert_id;
    $pid=0;for($i=0;$i<55;$i++){$time='2026-10-03 08:'.str_pad((string)$i,2,'0',STR_PAD_LEFT).':00';$s=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,U_LAIN,TGL_BYR,BULAN,TAHUN,sistem_pembayaran,total_jumlah,user_id,payment_link_version) VALUES(?,?,1000,?,'10','2026','Tunai',1000,?,1)");$s->bind_param('sssi',$nis,$level,$time,$owner);$s->execute();$pid=(int)$koneksi->insert_id;$s->close();}
    $f['students'][$unit]=['nis'=>$nis,'name'=>$name,'sid'=>$studentId,'empty_nis'=>$emptyNis,'empty_id'=>$emptyId,'in'=>$in,'out'=>$out,'pid'=>$pid,'owner'=>$owner];
}
if(!is_dir($root.'/sessions'))mkdir($root.'/sessions',0700,true);
session_save_path(getenv('SPP_QA_SESSION_PATH')?:$root.'/sessions');$paths=database_access_paths(DB_NAME,(int)getenv('SPP_DB_PORT'));$generation=is_file($paths['generation'])?trim(file_get_contents($paths['generation'])):'0';
foreach([0,1,2,3] as $unit)foreach($actors as $role=>$actor){
    session_id('refine'.bin2hex(random_bytes(16)));session_start();$token=bin2hex(random_bytes(32));
    $_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>$role,'admin_unit_id'=>$actor['unit_id'],'active_unit_id'=>$unit,'database_generation'=>$generation,
        'csrf_savings'=>$token,'csrf_transaction_authorization'=>$token,'csrf_unit_switch'=>$token];
    $f['sessions'][$unit][$role]=['id'=>session_id(),'csrf'=>$token];session_write_close();
}
file_put_contents($root.'/fixture.json',json_encode($f,JSON_THROW_ON_ERROR));echo "Disposable refinement fixture ready.\n";
