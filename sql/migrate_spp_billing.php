<?php
/**
 * Backfill tagihan/alokasi SPP lama. Jalankan setelah add_spp_billing_and_deposit.sql:
 *   set SPP_DB_NAME=db_disposable && C:\xampp\php\php.exe sql\migrate_spp_billing.php --execute
 */
if (PHP_SAPI !== 'cli' || !in_array('--execute', $argv, true)) {
    fwrite(STDERR, "Script hanya berjalan melalui CLI dengan opsi --execute.\n"); exit(2);
}
require_once __DIR__ . '/readiness_migration_guard.php';
readiness_migration_assert_apply_allowed($argv, (string)getenv('SPP_DB_NAME'));
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/spp_billing.php';

$existing=(int)($koneksi->query('SELECT COUNT(*) c FROM spp_alokasi_batch')->fetch_assoc()['c']??0);
if($existing>0){fwrite(STDERR,"Migrasi dibatalkan: spp_alokasi_batch sudah berisi data.\n");exit(3);}

$koneksi->begin_transaction();
try {
    $koneksi->query("INSERT IGNORE INTO master_spp_tahun(tahun_ajaran_id,status,published_at)
      SELECT DISTINCT tahun_ajaran_id,'published',NOW() FROM siswa_tahun_ajaran WHERE kelas IN ('1','2','3','4','5','6')");
    $koneksi->query("INSERT IGNORE INTO master_spp_tarif(master_spp_tahun_id,tingkat,nominal_dasar)
      SELECT mst.id,CAST(sta.kelas AS UNSIGNED),MAX(sta.spp_perbulan_snapshot)
      FROM siswa_tahun_ajaran sta JOIN master_spp_tahun mst ON mst.tahun_ajaran_id=sta.tahun_ajaran_id
      WHERE sta.kelas IN ('1','2','3','4','5','6') AND sta.spp_perbulan_snapshot>0
      GROUP BY mst.id,CAST(sta.kelas AS UNSIGNED)");

    $years=$koneksi->query("SELECT mst.id master_id,ta.id year_id,ta.label FROM master_spp_tahun mst JOIN tahun_ajaran ta ON ta.id=mst.tahun_ajaran_id")->fetch_all(MYSQLI_ASSOC);
    $placements=$koneksi->prepare("SELECT sta.*,s.potongan_spp_nominal FROM siswa_tahun_ajaran sta JOIN siswa s ON s.NO_INDUK=sta.no_induk WHERE sta.tahun_ajaran_id=? AND sta.kelas IN ('1','2','3','4','5','6')");
    $insertBill=$koneksi->prepare("INSERT IGNORE INTO tagihan_spp(master_spp_tahun_id,tahun_ajaran_id,penempatan_id,no_induk,tingkat_snapshot,master_kelas_id,kelas_rombel_snapshot,bulan,tahun,tarif_dasar_snapshot,potongan_nominal_snapshot,nominal_tagihan,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $billsCreated=0;
    foreach($years as $year){
        $yearId=(int)$year['year_id'];$placements->bind_param('i',$yearId);$placements->execute();$rows=$placements->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach($rows as $row){
            $covered=(int)$row['spp_covered_by_psb']===1;$base=$covered?0:(float)$row['spp_perbulan_snapshot'];$discount=(float)$row['potongan_spp_nominal'];$net=spp_net_tariff($base,$discount);
            $status=$covered?'covered_psb':($net['net']<=.001?'waived':'open');$masterId=(int)$year['master_id'];$placementId=(int)$row['id'];$nis=(string)$row['no_induk'];$level=(int)$row['kelas'];$classId=(int)($row['master_kelas_id']??0);$classParam=$classId?:null;$classLabel=(string)$row['kelas_rombel_snapshot'];
            foreach(spp_academic_periods((string)$year['label']) as $period){$month=$period['bulan'];$calendarYear=$period['tahun'];$insertBill->bind_param('iiisiisssddds',$masterId,$yearId,$placementId,$nis,$level,$classParam,$classLabel,$month,$calendarYear,$base,$net['discount'],$net['net'],$status);$insertBill->execute();$billsCreated+=$insertBill->affected_rows===1?1:0;}
        }
    }
    $placements->close();$insertBill->close();

    $payments=$koneksi->query("SELECT id,NO_INDUK,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,U_SPP FROM bayar WHERE U_SPP>0 ORDER BY TGL_BYR,id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    $findBill=$koneksi->prepare("SELECT ts.id,ts.nominal_tagihan,COALESCE(SUM(CASE WHEN ab.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0) paid FROM tagihan_spp ts LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id WHERE ts.no_induk=? AND ts.bulan=? AND ts.tahun=? GROUP BY ts.id LIMIT 1 FOR UPDATE");
    $insertBatch=$koneksi->prepare("INSERT INTO spp_alokasi_batch(no_induk,bayar_id,tanggal,user_id,uang_baru) VALUES(?,?,?,?,?)");
    $insertAllocation=$koneksi->prepare("INSERT INTO spp_alokasi(batch_id,tagihan_spp_id,nominal_dari_bayar) VALUES(?,?,?)");
    $allocated=0;
    foreach($payments as $payment){
        $nis=(string)$payment['NO_INDUK'];$month=str_pad((string)(int)$payment['BULAN'],2,'0',STR_PAD_LEFT);$calendarYear=(string)$payment['TAHUN'];$amount=(float)$payment['U_SPP'];
        $findBill->bind_param('sss',$nis,$month,$calendarYear);$findBill->execute();$bill=$findBill->get_result()->fetch_assoc();
        $remaining=$bill?max(0,(float)$bill['nominal_tagihan']-(float)$bill['paid']):0;
        if(!$bill || $remaining<=.001 || abs($amount-$remaining)>.001) throw new RuntimeException('Pembayaran lama tidak cocok dengan satu sisa tagihan; periksa sebelum migrasi. ID '.$payment['id']);
        $paymentId=(int)$payment['id'];$date=(string)$payment['TGL_BYR'];$user=(string)$payment['user_id'];
        $insertBatch->bind_param('sissd',$nis,$paymentId,$date,$user,$amount);$insertBatch->execute();$batchId=(int)$koneksi->insert_id;
        $billId=(int)$bill['id'];$insertAllocation->bind_param('iid',$batchId,$billId,$amount);$insertAllocation->execute();$allocated++;
    }
    $findBill->close();$insertBatch->close();$insertAllocation->close();
    $koneksi->commit();
    echo "Migrasi selesai. Tagihan dibuat: $billsCreated; pembayaran dialokasikan: $allocated.\n";
} catch(Throwable $e){$koneksi->rollback();fwrite(STDERR,'Migrasi gagal: '.$e->getMessage()."\n");exit(1);}
