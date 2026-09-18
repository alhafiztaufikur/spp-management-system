<?php
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/kelas.php';

function komite_test_assert(bool $value,string $message):void { if(!$value) throw new RuntimeException($message); }
function komite_test_reject(callable $callback,string $message):void {
    try {$callback();} catch(RuntimeException $e) {return;}
    throw new RuntimeException($message);
}

$nis=(string)random_int(9400000000,9499999999);
$koneksi->begin_transaction();
try {
    $class=$koneksi->query('SELECT id,tingkat,kode_rombel,is_placeholder FROM master_kelas WHERE tingkat=1 ORDER BY id LIMIT 1')->fetch_assoc();
    $classId=(int)$class['id'];$level='1';$name='UJI KOMITE '.$nis;$classLabel=class_label($class);
    $stmt=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,POMG,is_active) VALUES(?,?,?,?,15000,1)');
    $stmt->bind_param('sssi',$nis,$name,$level,$classId);$stmt->execute();$stmt->close();
    $year=spp_master_ensure_year($koneksi,'2195/2196',true);$yearId=(int)$year['tahun_ajaran_id'];$status='aktif';
    $stmt=$koneksi->prepare('INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,15000,?)');
    $stmt->bind_param('ississ',$yearId,$nis,$level,$classId,$classLabel,$status);$stmt->execute();$placementId=(int)$koneksi->insert_id;$stmt->close();
    komite_set_start_month($koneksi,$placementId,'10');
    $count=(int)$koneksi->query("SELECT COUNT(*) total FROM tagihan_komite WHERE no_induk='$nis'")->fetch_assoc()['total'];
    komite_test_assert($count===9,'Pindahan Oktober harus mendapat sembilan tagihan, bukan dua belas.');
    komite_test_assert(komite_bill($koneksi,$nis,'09','2195')===null,'Komite sebelum bulan masuk terbit.');
    komite_test_reject(fn()=>komite_validate_amount($koneksi,$nis,'10','2195',7500,false),'Komite parsial diterima.');
    $bill=komite_validate_amount($koneksi,$nis,'10','2195',15000,true);
    $stmt=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,BULAN,TAHUN,TGL_BYR,U_KOMITE,total_jumlah,payment_link_version) VALUES(?,?,'10','2195','2195-10-01 08:00:00',15000,15000,1)");
    $stmt->bind_param('ss',$nis,$level);$stmt->execute();$paymentId=(int)$koneksi->insert_id;$stmt->close();
    komite_save_payment($koneksi,$paymentId,$bill,15000);
    $rate=komite_sync_student_rate($koneksi,$nis,20000);
    komite_test_assert($rate['updated']===8,'Perubahan tarif tidak hanya memperbarui tagihan belum dibayar.');
    komite_test_assert((float)komite_bill($koneksi,$nis,'10','2195')['nominal_tagihan']===15000.0,'Tagihan yang sudah dibayar berubah.');
    komite_test_assert((float)komite_bill($koneksi,$nis,'11','2195')['nominal_tagihan']===20000.0,'Tarif baru tidak diterapkan.');
    komite_validate_amount($koneksi,$nis,'10','2195',0,true);
    komite_test_reject(fn()=>komite_validate_amount($koneksi,$nis,'11','2195',0,true),'SPP diterima tanpa Komite bulan yang sama.');
    $koneksi->query("UPDATE siswa_tahun_ajaran SET status='lulus' WHERE id=$placementId");
    $koneksi->query("UPDATE siswa SET is_active=0 WHERE NO_INDUK='$nis'");
    komite_test_assert(komite_sync_placement($koneksi,$placementId)===0,'Lulusan mendapat tagihan Komite baru.');
    komite_test_assert((float)komite_bill($koneksi,$nis,'11','2195')['remaining']===20000.0,'Tunggakan Komite lulusan hilang.');
    komite_validate_amount($koneksi,$nis,'11','2195',20000,false);
    komite_sync_student_rate($koneksi,$nis,0);
    komite_validate_amount($koneksi,$nis,'11','2195',0,true);
    $koneksi->rollback();
    echo "OK: Komite bulanan, pindahan, lulusan, lunas penuh, tarif snapshot, dan kewajiban bersama SPP.\n";
} catch(Throwable $e) {$koneksi->rollback();throw $e;}
