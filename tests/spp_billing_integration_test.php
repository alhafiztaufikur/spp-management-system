<?php
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/spp_billing.php';require_once __DIR__.'/../includes/kelas.php';
function spp_it_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
if(!spp_billing_schema_ready($koneksi))throw new RuntimeException('Schema SPP baru belum tersedia.');
$nis=(string)random_int(9300000000,9399999999);$label='2196/2197';
$koneksi->begin_transaction();
try{
  $class=$koneksi->query("SELECT id,tingkat,kode_rombel,is_placeholder FROM master_kelas WHERE tingkat BETWEEN 1 AND 6 ORDER BY tingkat,id LIMIT 1")->fetch_assoc();if(!$class)throw new RuntimeException('Master kelas uji tidak tersedia.');$level=(string)$class['tingkat'];$classId=(int)$class['id'];$classLabel=class_label($class);
  $name='UJI SPP '.$nis;$stmt=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,potongan_spp_persen,is_active) VALUES(?,?,?,?,250000,0,1)');$stmt->bind_param('sssi',$nis,$name,$level,$classId);$stmt->execute();$stmt->close();
  $master=spp_master_ensure_year($koneksi,$label,true);$masterId=(int)$master['id'];$yearId=(int)$master['tahun_ajaran_id'];$status='aktif';$stmt=$koneksi->prepare('INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,status) VALUES(?,?,?,?,?,250000,?)');$stmt->bind_param('ississ',$yearId,$nis,$level,$classId,$classLabel,$status);$stmt->execute();$stmt->close();
  spp_master_save_rates($koneksi,$masterId,[1=>250000,2=>250000,3=>250000,4=>250000,5=>250000,6=>250000]);
  $publish=spp_publish_students($koneksi,$masterId,[$nis]);spp_it_assert($publish['created']===12,'Penerbitan pertama tidak membuat 12 tagihan.');$again=spp_publish_students($koneksi,$masterId,[$nis]);spp_it_assert($again['created']===0&&$again['existing']===12,'Penerbitan ulang tidak idempoten.');
  $a=spp_allocate_payment($koneksi,$nis,null,500000,false,'2196-07-02 08:00:00','Tunai','test');spp_it_assert($a['bill_count']===2&&$a['deposit_created']===0.0,'Pembayaran dua bulan tidak dialokasikan dengan benar.');
  $b=spp_allocate_payment($koneksi,$nis,null,100000,false,'2196-07-03 08:00:00','Tunai','test');spp_it_assert($b['bill_count']===0&&$b['deposit_created']===100000.0,'Pembayaran kurang dari sebulan tidak menjadi titipan.');
  $c=spp_allocate_payment($koneksi,$nis,null,150000,true,'2196-07-04 08:00:00','Tunai','test');spp_it_assert($c['bill_count']===1&&$c['deposit_used']===100000.0&&$c['balance_after']===0.0,'Gabungan titipan dan uang baru salah.');
  $d=spp_allocate_payment($koneksi,$nis,null,300000,false,'2196-07-05 08:00:00','VA','test');spp_it_assert($d['bill_count']===1&&$d['deposit_created']===50000.0,'Kelebihan pembayaran tidak menjadi titipan.');
  spp_it_assert(abs(spp_deposit_balance($koneksi,$nis)-50000)<.001,'Saldo akhir titipan salah.');
  $koneksi->rollback();echo "OK: penerbitan idempoten, alokasi tertua, pembayaran kurang/lebih, dan penggunaan titipan tervalidasi.\n";
}catch(Throwable $e){$koneksi->rollback();throw $e;}
