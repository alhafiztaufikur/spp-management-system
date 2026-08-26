<?php
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/support/assert_audit_database.php';require_once __DIR__.'/../includes/kelas.php';
test_require_audit_database($koneksi);
function class_test_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$failure=null;
try{
  $koneksi->begin_transaction();$suffix=(string)random_int(1000,9999);$codeA='T'.$suffix.'A';$codeB='T'.$suffix.'B';
  $stmt=$koneksi->prepare('INSERT INTO master_kelas(tingkat,kode_rombel,is_placeholder,is_active) VALUES(1,?,0,1)');$stmt->bind_param('s',$codeA);$stmt->execute();$classA=(int)$koneksi->insert_id;$stmt->bind_param('s',$codeB);$stmt->execute();$classB=(int)$koneksi->insert_id;$stmt->close();
  $nis=(string)random_int(9800000000,9899999999);$name='UJI SNAPSHOT KELAS';$level='1';$spp=250000.0;$komite=100000.0;$stmt=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG) VALUES(?,?,?,?,?,?)');$stmt->bind_param('sssidd',$nis,$name,$level,$classA,$spp,$komite);$stmt->execute();$stmt->close();
  $placement=class_sync_student_current_year($koneksi,$nis,$classA,$spp,$komite,true);class_test_assert((int)$placement>0,'Penempatan awal tidak terbentuk.');
  $label=du_current_academic_year();$month=date('m');$year=date('Y');$date=date('Y-m-d H:i:s');$stmt=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_SPP,TGL_BYR,BULAN,TAHUN,th_ajaran,total_jumlah,payment_link_version) VALUES(?,?,?,?,?,?,?,?,?,?,1)");$classLabel='1'.$codeA;$paid=100000.0;$stmt->bind_param('ssisdssssd',$nis,$level,$classA,$classLabel,$paid,$date,$month,$year,$label,$paid);$stmt->execute();$paymentId=(int)$koneksi->insert_id;$stmt->close();$stmt=$koneksi->prepare('INSERT INTO bayar_spp_periode(bayar_id,no_induk,bulan,tahun) VALUES(?,?,?,?)');$stmt->bind_param('isss',$paymentId,$nis,$month,$year);$stmt->execute();$stmt->close();
  class_sync_student_current_year($koneksi,$nis,$classB,$spp,$komite,true);$stmt=$koneksi->prepare('SELECT master_kelas_id,spp_perbulan_snapshot,komite_snapshot FROM siswa_tahun_ajaran WHERE id=?');$stmt->bind_param('i',$placement);$stmt->execute();$snapshot=$stmt->get_result()->fetch_assoc();$stmt->close();
  class_test_assert((int)$snapshot['master_kelas_id']===$classA,'Kelas histori berubah setelah pembayaran.');
  $blocked=false;try{class_validate_tariff_snapshot_change($koneksi,$nis,$spp,$spp+50000,$komite,$komite);}catch(RuntimeException $e){$blocked=true;}class_test_assert($blocked,'Perubahan tarif SPP berbayar tidak ditolak.');
}catch(Throwable $error){$failure=$error;}finally{try{$koneksi->rollback();}catch(Throwable $ignored){}}
if($failure){fwrite(STDERR,'FAILED: '.$failure->getMessage().PHP_EOL);exit(1);}echo "OK: snapshot kelas dan tarif tahun ajaran terkunci setelah pembayaran.\n";
