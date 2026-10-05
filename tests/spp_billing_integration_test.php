<?php
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_(?:audit|test)_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    error_log('FAILED: tes mutasi memerlukan CLI, clone db_spp_audit_* atau db_spp_test_*, dan SPP_TEST_ALLOW_MUTATION=1.');
    exit(1);
}

require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/spp_billing.php';require_once __DIR__.'/../includes/kelas.php';
function spp_it_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
if(!spp_billing_schema_ready($koneksi))throw new RuntimeException('Schema SPP baru belum tersedia.');
$nis=(string)random_int(9300000000,9399999999);$label='2196/2197';
$koneksi->begin_transaction();
try{
  $class=$koneksi->query("SELECT id,tingkat,kode_rombel,is_placeholder FROM master_kelas WHERE tingkat BETWEEN 1 AND 6 ORDER BY tingkat,id LIMIT 1")->fetch_assoc();if(!$class)throw new RuntimeException('Master kelas uji tidak tersedia.');$level=(string)$class['tingkat'];$classId=(int)$class['id'];$classLabel=class_label($class);
  $name='UJI SPP '.$nis;$stmt=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,potongan_spp_nominal,is_active) VALUES(?,?,?,?,250000,0,1)');$stmt->bind_param('sssi',$nis,$name,$level,$classId);$stmt->execute();$stmt->close();
  $master=spp_master_ensure_year($koneksi,$label,true);$masterId=(int)$master['id'];$yearId=(int)$master['tahun_ajaran_id'];$status='aktif';$stmt=$koneksi->prepare('INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,status) VALUES(?,?,?,?,?,250000,?)');$stmt->bind_param('ississ',$yearId,$nis,$level,$classId,$classLabel,$status);$stmt->execute();$placementId=(int)$koneksi->insert_id;$stmt->close();
  spp_master_save_rates($koneksi,$masterId,[1=>250000,2=>250000,3=>250000,4=>250000,5=>250000,6=>250000]);
  $publish=spp_publish_students($koneksi,$masterId,[$nis]);spp_it_assert($publish['created']===12,'Penerbitan pertama tidak membuat 12 tagihan.');$again=spp_publish_students($koneksi,$masterId,[$nis]);spp_it_assert($again['created']===0&&$again['existing']===12,'Penerbitan ulang tidak idempoten.');
  try{spp_allocate_payment($koneksi,$nis,null,'08','2196',250000,'2196-07-02 08:00:00','Tunai','test');throw new RuntimeException('Bulan kedua dapat mendahului tunggakan.');}catch(RuntimeException $e){if(str_contains($e->getMessage(),'dapat mendahului'))throw $e;}
  try{spp_allocate_payment($koneksi,$nis,null,'07','2196',500000,'2196-07-02 08:00:00','Tunai','test');throw new RuntimeException('Satu pembayaran melunasi dua bulan.');}catch(RuntimeException $e){if(str_contains($e->getMessage(),'melunasi dua bulan'))throw $e;}
  $a=spp_allocate_payment($koneksi,$nis,null,'07','2196',250000,'2196-07-02 08:00:00','Tunai','test');spp_it_assert($a['bill_count']===1,'Pembayaran satu bulan gagal.');
  $a2=spp_allocate_payment($koneksi,$nis,null,'08','2196',250000,'2196-07-02 08:01:00','Tunai','test');spp_it_assert($a2['bill_count']===1,'Pembayaran bulan berikutnya gagal.');
  $rateChange=spp_master_save_rates($koneksi,$masterId,[1=>300000,2=>250000,3=>250000,4=>250000,5=>250000,6=>250000]);spp_it_assert($rateChange['bills_updated']===10&&$rateChange['bills_locked']===2,'Perubahan tarif tidak memisahkan tagihan terkunci dan belum beralokasi.');
  $placementRate=(float)$koneksi->query('SELECT spp_perbulan_snapshot FROM siswa_tahun_ajaran WHERE id='.$placementId)->fetch_row()[0];
  $activeRate=(float)$koneksi->query("SELECT SPP_PERBULAN FROM siswa WHERE NO_INDUK='{$nis}'")->fetch_row()[0];
  spp_it_assert($placementRate===250000.0&&$activeRate===300000.0,
    'Tarif aktif tidak mengikuti master baru atau snapshot penempatan berbayar berubah.');
  $discountChange=spp_sync_student_discount($koneksi,$nis,30000,$placementId);spp_it_assert($discountChange['updated']===10&&$discountChange['locked']===2,'Perubahan potongan tidak menjaga snapshot tagihan berbayar.');
  $amounts=$koneksi->query("SELECT bulan,nominal_tagihan FROM tagihan_spp WHERE no_induk='{$nis}' ORDER BY CAST(tahun AS UNSIGNED),CAST(bulan AS UNSIGNED) LIMIT 3")->fetch_all(MYSQLI_ASSOC);spp_it_assert((float)$amounts[0]['nominal_tagihan']===250000.0&&(float)$amounts[1]['nominal_tagihan']===250000.0&&(float)$amounts[2]['nominal_tagihan']===270000.0,'Snapshot tarif lama atau tarif efektif baru berubah tidak tepat.');
  try{spp_allocate_payment($koneksi,$nis,null,'09','2196',100000,'2196-07-03 08:00:00','Tunai','test');throw new RuntimeException('Pembayaran SPP kurang dari sebulan diterima.');}catch(RuntimeException $e){if(str_contains($e->getMessage(),'kurang dari sebulan diterima'))throw $e;}
  $c=spp_allocate_payment($koneksi,$nis,null,'09','2196',270000,'2196-07-04 08:00:00','Tunai','test');spp_it_assert($c['bill_count']===1,'Alokasi uang langsung salah.');
  try{spp_allocate_payment($koneksi,$nis,null,'10','2196',320000,'2196-07-05 08:00:00','VA','test');throw new RuntimeException('Kelebihan SPP diterima otomatis.');}catch(RuntimeException $e){if(str_contains($e->getMessage(),'diterima otomatis'))throw $e;}
  $d=spp_allocate_payment($koneksi,$nis,null,'10','2196',270000,'2196-07-05 08:00:00','VA','test');spp_it_assert($d['bill_count']===1,'Pembayaran bulan keempat salah.');
  $koneksi->rollback();echo "OK: satu bulan per transaksi, urutan tunggakan, nominal tepat, tarif snapshot, dan pembayaran langsung.\n";
}catch(Throwable $e){$koneksi->rollback();throw $e;}
