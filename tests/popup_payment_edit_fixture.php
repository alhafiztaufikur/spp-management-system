<?php
/** Private fixtures for popup/payment-edit HTTP regressions on an owned clone. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/transaction_authorization.php';
$actual=$koneksi->query('SELECT @@server_uuid uuid,@@port port,@@event_scheduler scheduler')->fetch_assoc();
if($actual['uuid']!==getenv('SPP_MIGRATION_TEST_UUID')||(int)$actual['port']===3306||$actual['scheduler']!=='OFF')throw new RuntimeException('Owned isolated server required.');
$file=(string)getenv('SPP_POPUP_EDIT_FIXTURE');if(!$file)throw new RuntimeException('Private fixture path required.');
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>0];unit_set_context($koneksi,0);
if(($argv[1]??'')==='state'){
    $fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$states=[];
    foreach($fixture['units'] as $unit=>$data){
        unit_set_context($koneksi,(int)$unit);$id=(int)$data['payment_id'];
        $states[$unit]=['snapshot'=>transaction_authorization_snapshot($koneksi,$id)['data'],
            'payments'=>(int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='".$koneksi->real_escape_string($data['nis'])."'")->fetch_row()[0],
            'events'=>$koneksi->query('SELECT action,event_key,before_snapshot,after_snapshot FROM pembayaran_aktivitas WHERE payment_id='.$id.' ORDER BY id')->fetch_all(MYSQLI_ASSOC)];
    }
    echo json_encode($states,JSON_THROW_ON_ERROR);exit;
}
if(is_file($file))throw new RuntimeException('Existing fixture must not be replaced.');
$fixture=['units'=>[]];$suffix=bin2hex(random_bytes(3));
foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);$class=$koneksi->query('SELECT id,tingkat FROM master_kelas WHERE is_active=1 AND is_placeholder=0 AND tingkat>0 ORDER BY tingkat,id LIMIT 1')->fetch_assoc();
    $nis='QPE'.$suffix.$unit;$name='QA Edit Payment '.$unit.' '.$suffix;$level=(string)$class['tingkat'];$cid=(int)$class['id'];
    $s=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB,is_active,legacy_pending) VALUES(?,?,?,?,100000,1,0)');$s->bind_param('sssi',$nis,$name,$level,$cid);$s->execute();$studentId=(int)$koneksi->insert_id;$s->close();
    $s=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,U_PSB,TGL_BYR,BULAN,TAHUN,sistem_pembayaran,total_jumlah,payment_link_version,user_id) VALUES(?,?,?,100,NOW(),'11','2026','Tunai',100,1,1)");$s->bind_param('ssi',$nis,$level,$cid);$s->execute();$pid=(int)$koneksi->insert_id;$s->close();
    $koneksi->begin_transaction();payment_activity_record($koneksi,$pid,'created',1,null,transaction_authorization_snapshot($koneksi,$pid)['data'],'created:'.$pid);$koneksi->commit();
    $fixture['units'][$unit]=['payment_id'=>$pid,'student_id'=>$studentId,'nis'=>$nis];
}
file_put_contents($file,json_encode($fixture,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo "PASS: native edit fixtures created only on the verified clone.\n";
