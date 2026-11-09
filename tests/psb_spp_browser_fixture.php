<?php
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/psb_spp.php';require_once __DIR__.'/../includes/kelas.php';
$db=$koneksi;if((int)$db->query('SELECT @@port')->fetch_row()[0]===3306)throw new RuntimeException('Separate server required');
$action=$argv[1]??'state';$file=$argv[2]??'';
if($action==='setup'){
    if(!str_starts_with(str_replace('\\','/',$file),'C:/laragon/backups/')||is_file($file))throw new RuntimeException('New private output required');
    $admin=$db->query("SELECT id,nama FROM admin WHERE role='admin' AND is_active=1 LIMIT 1")->fetch_assoc();
    if(!$admin){$password=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);$stmt=$db->prepare("INSERT INTO admin(username,password,nama,role,is_active) VALUES('qa_psb_admin',?,'QA PSB Administrator','admin',1)");$stmt->bind_param('s',$password);$stmt->execute();$admin=['id'=>$db->insert_id,'nama'=>'QA PSB Administrator'];}
    $super=$db->query("SELECT id,nama FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();$out=['database'=>getenv('SPP_DB_NAME'),'sessions'=>[],'units'=>[]];
    foreach(['super_admin'=>$super,'admin'=>$admin] as $role=>$actor){foreach([0,1,2,3] as $unit){session_id(bin2hex(random_bytes(16)));session_start();$_SESSION=['admin_id'=>(int)$actor['id'],'admin_nama'=>$actor['nama'],'admin_role'=>$role,'active_unit_id'=>$unit];$out['sessions'][$role][$unit]=session_id();session_write_close();}}
    $_SESSION=['admin_id'=>(int)$super['id'],'admin_role'=>'super_admin'];
    foreach([1,2,3] as $unit){$_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);[$first]=unit_level_bounds();$out['units'][$unit]=['psb'=>(int)hp_one($db,'SELECT id FROM master_kelas WHERE tingkat=0 AND is_active=1 LIMIT 1')['id'],'entry'=>(int)hp_one($db,"SELECT id FROM master_kelas WHERE tingkat=? AND is_active=1 AND is_placeholder=0 LIMIT 1",[$first])['id'],'first'=>$first];}
    file_put_contents($file,json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'Fixture sessions ready; no student or money created.'.PHP_EOL;exit;
}
if($action==='state'){
    $out=[];foreach([1,2,3] as $unit){unit_set_context($db,$unit);foreach(['998811'.(getenv('SPP_PSB_RUN')?:'23').'01','998811'.(getenv('SPP_PSB_RUN')?:'23').'02'] as $nis){$s=hp_one($db,'SELECT id,NO_INDUK,NAMA,KELAS FROM siswa WHERE NO_INDUK=?',[$nis]);if(!$s)continue;$out[$unit][$nis]=$s+['plan'=>pspp_find($db,(int)$s['id']),'bills'=>hp_rows($db,'SELECT id,bulan,tahun,nominal_tagihan,penempatan_id FROM tagihan_spp WHERE no_induk=? ORDER BY tahun,bulan',[$nis]),'payments'=>hp_rows($db,'SELECT id,U_SPP,U_KOMITE,KELAS,riwayat_kelas_snapshot,riwayat_tahun_ajaran,TGL_BYR FROM bayar WHERE NO_INDUK=?',[$nis])];}}
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
throw new RuntimeException('Unknown action');
