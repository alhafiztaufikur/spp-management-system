<?php
if(PHP_SAPI!=='cli' || getenv('SPP_TEST_ALLOW_MUTATION')!=='1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))throw new RuntimeException('Clone uji wajib.');
require_once __DIR__.'/../koneksi.php';
function activity_fingerprints(mysqli $db,array $names):array {
    $result=[];
    foreach($names as $table){$hash=hash_init('sha256');foreach($db->query('SELECT * FROM `'.$table.'`') as $row)hash_update($hash,json_encode($row,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));$result[$table]=hash_final($hash);}
    return $result;
}
$tables=array_column($koneksi->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'pembayaran_aktivitas_data' ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC),'TABLE_NAME');
$before=activity_fingerprints($koneksi,$tables);
for($run=1;$run<=2;$run++){
    $process=proc_open([PHP_BINARY,__DIR__.'/../sql/add_payment_activity.php','--apply'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);
    if(proc_close($process)!==0)throw new RuntimeException($out.$err);
    $journal=activity_fingerprints($koneksi,['pembayaran_aktivitas_data']);
    if($run===1)$first=$journal;elseif($first!==$journal)throw new RuntimeException('Migrasi ulang mengubah/menggandakan jurnal.');
    if(activity_fingerprints($koneksi,$tables)!==$before)throw new RuntimeException('Migrasi mengubah data tabel sebelumnya.');
}
echo 'OK: migrasi dua kali identik; '.count($tables)." tabel lama tetap identik.\n";
require_once __DIR__.'/../includes/payment_activity.php';unit_set_context($koneksi,0);
$counts=$koneksi->query('SELECT unit_id,COUNT(*) count FROM pembayaran_aktivitas GROUP BY unit_id')->fetch_all(MYSQLI_ASSOC);
if(array_column($counts,'unit_id')!==['1','2','3'])throw new RuntimeException('Jurnal multiunit tidak lengkap.');
echo json_encode($counts),PHP_EOL;
// A current operator on an edited legacy payment is never sufficient proof of its creator.
$bad=(int)$koneksi->query("SELECT COUNT(*) FROM pembayaran_aktivitas a JOIN bayar b ON b.id=a.payment_id AND b.unit_id=a.unit_id
    WHERE a.action='created' AND a.reconstructed=1 AND b.updated_at IS NOT NULL
    AND NOT EXISTS(SELECT 1 FROM keuangan_request f JOIN bayar origin ON origin.id=f.referensi_id
        WHERE f.unit_id=b.unit_id AND f.aksi='pembayaran' AND (f.referensi_id=b.id OR
        (b.payment_batch_count=12 AND b.payment_batch_token IS NOT NULL AND b.payment_batch_token=origin.payment_batch_token)))
    AND NOT EXISTS(SELECT 1 FROM transaksi_otorisasi r WHERE r.unit_id=b.unit_id
        AND CAST(JSON_UNQUOTE(JSON_EXTRACT(r.before_snapshot,'$.payment.id')) AS UNSIGNED)=b.id
        AND JSON_EXTRACT(r.before_snapshot,'$.payment.updated_at')=CAST('null' AS JSON))")->fetch_row()[0];
if($bad)throw new RuntimeException('Pembuat lama direkonstruksi tanpa bukti yang memadai.');
echo "OK: operator lama hanya direkonstruksi dari bukti yang memadai.\n";
