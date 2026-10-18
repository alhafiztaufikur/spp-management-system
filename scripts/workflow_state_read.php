<?php
/** Read-only release evidence; writes neither schema nor school data. Redirect JSON to a private file. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';
$koneksi->query('SET SESSION TRANSACTION READ ONLY');$koneksi->begin_transaction();
$ignored=['siswa_data'=>['potongan_psb_nominal'],'bayar_data'=>['riwayat_tahun_ajaran','riwayat_kelas_snapshot'],'master_biaya_lain_data'=>['jenis_pembayaran']];$hashes=[];
foreach($koneksi->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME NOT IN ('admin','workflow_migration_log') ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC) as $table){
    $name=$table['TABLE_NAME'];$rows=$koneksi->query("SELECT * FROM `$name`")->fetch_all(MYSQLI_ASSOC);$encoded=[];
    foreach($rows as $row){foreach($ignored[$name]??[] as $column)unset($row[$column]);$encoded[]=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}sort($encoded,SORT_STRING);$hashes[$name]=hash('sha256',implode("\n",$encoded));
}
$identity=$koneksi->query('SELECT @@server_uuid server_uuid,@@port port')->fetch_assoc();
$payments=$koneksi->query('SELECT COUNT(*) count,COALESCE(SUM(total_jumlah),0) amount FROM bayar_data')->fetch_assoc();$savings=$koneksi->query('SELECT COUNT(*) count,COALESCE(SUM(SALDO),0) amount FROM tabungan_data')->fetch_assoc();
$password=$koneksi->query("SELECT password FROM admin WHERE username='superadmin'")->fetch_row()[0]??null;
$accounts=$koneksi->query('SELECT role,is_active,COUNT(*) count FROM admin GROUP BY role,is_active ORDER BY role,is_active')->fetch_all(MYSQLI_ASSOC);$koneksi->rollback();
echo json_encode(['identity'=>$identity,'payments'=>$payments,'savings'=>$savings,'financial_and_master_hashes'=>$hashes,'super_password_digest'=>$password===null?null:hash('sha256',$password),'accounts'=>$accounts],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
