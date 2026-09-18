<?php

require_once __DIR__ . '/spp_billing.php';

function komite_academic_year(string $month, string $year): string {
    $number = (int)$month;
    $start = $number >= 7 ? (int)$year : (int)$year - 1;
    return $start . '/' . ($start + 1);
}

/** Tagihan dibuat dari penempatan yang tersimpan, tanpa bergantung pada penerbitan SPP. */
function komite_sync_placement(mysqli $db, int $placementId): int {
    $stmt = $db->prepare('SELECT sta.*,ta.label,s.POMG FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk WHERE sta.id=? FOR UPDATE');
    $stmt->bind_param('i', $placementId); $stmt->execute();
    $placement = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$placement || $placement['status']!=='aktif' || !in_array((string)$placement['kelas'], ['1','2','3','4','5','6'], true)) return 0;
    $periods = spp_academic_periods((string)$placement['label']);
    $start = (string)($placement['komite_mulai_bulan'] ?? '07');
    $startIndex = 0;
    foreach ($periods as $index => $period) if ($period['bulan'] === $start) { $startIndex = $index; break; }
    $rate = (float)$placement['POMG'];
    $insert = $db->prepare("INSERT IGNORE INTO tagihan_komite(tahun_ajaran_id,penempatan_id,no_induk,kelas_rombel_snapshot,bulan,tahun,nominal_tagihan) VALUES(?,?,?,?,?,?,?)");
    $created = 0;
    foreach ($periods as $index => $period) {
        if ($index < $startIndex) continue;
        $yearId = (int)$placement['tahun_ajaran_id'];
        $nis = (string)$placement['no_induk'];
        $class = (string)$placement['kelas_rombel_snapshot'];
        $month = (string)$period['bulan']; $year = (string)$period['tahun'];
        $insert->bind_param('iissssd', $yearId, $placementId, $nis, $class, $month, $year, $rate);
        $insert->execute(); $created += $insert->affected_rows;
    }
    $insert->close();
    return $created;
}

function komite_set_start_month(mysqli $db, int $placementId, string $month): void {
    if (!in_array($month,['01','02','03','04','05','06','07','08','09','10','11','12'],true)) throw new RuntimeException('Bulan mulai tagihan Komite tidak valid.');
    $stmt=$db->prepare('SELECT sta.id,sta.no_induk,ta.label FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id WHERE sta.id=? FOR UPDATE');
    $stmt->bind_param('i',$placementId);$stmt->execute();$placement=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$placement) throw new RuntimeException('Penempatan siswa tidak ditemukan.');
    $periods=spp_academic_periods((string)$placement['label']);$startIndex=0;
    foreach ($periods as $i=>$period) if ($period['bulan']===$month) { $startIndex=$i;break; }
    foreach ($periods as $i=>$period) {
        if ($i >= $startIndex) break;
        $bill=komite_bill($db,(string)$placement['no_induk'],$period['bulan'],$period['tahun'],true);
        if (!$bill) continue;
        if ((float)$bill['paid']>.001) throw new RuntimeException('Bulan mulai Komite tidak dapat melewati bulan yang sudah dibayar.');
        $billId=(int)$bill['id'];$stmt=$db->prepare('DELETE FROM tagihan_komite WHERE id=?');
        $stmt->bind_param('i',$billId);$stmt->execute();$stmt->close();
    }
    $stmt=$db->prepare('UPDATE siswa_tahun_ajaran SET komite_mulai_bulan=? WHERE id=?');
    $stmt->bind_param('si',$month,$placementId);$stmt->execute();$stmt->close();
    komite_sync_placement($db,$placementId);
}

function komite_sync_student_rate(mysqli $db, string $noInduk, float $rate): array {
    if ($rate < 0 || !is_finite($rate)) throw new RuntimeException('Tarif Komite tidak valid.');
    $stmt = $db->prepare("UPDATE tagihan_komite tk SET tk.nominal_tagihan=? WHERE tk.no_induk=? AND tk.status='open' AND NOT EXISTS(SELECT 1 FROM bayar_komite bk WHERE bk.tagihan_komite_id=tk.id)");
    $stmt->bind_param('ds', $rate, $noInduk); $stmt->execute(); $changed=$stmt->affected_rows; $stmt->close();
    $stmt = $db->prepare('UPDATE siswa_tahun_ajaran SET komite_snapshot=? WHERE no_induk=? AND status=\'aktif\'');
    $stmt->bind_param('ds', $rate, $noInduk); $stmt->execute(); $stmt->close();
    return ['updated'=>$changed];
}

function komite_bill(mysqli $db, string $noInduk, string $month, string $year, bool $forUpdate=false): ?array {
    $sql = "SELECT tk.*,COALESCE((SELECT SUM(bk.nominal) FROM bayar_komite bk WHERE bk.tagihan_komite_id=tk.id),0) paid FROM tagihan_komite tk WHERE tk.no_induk=? AND tk.bulan=? AND tk.tahun=? AND tk.status='open' LIMIT 1";
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt=$db->prepare($sql); $stmt->bind_param('sss',$noInduk,$month,$year);$stmt->execute();
    $bill=$stmt->get_result()->fetch_assoc();$stmt->close();
    if ($bill) { $bill['paid']=(float)$bill['paid'];$bill['remaining']=max(0,(float)$bill['nominal_tagihan']-$bill['paid']); }
    return $bill ?: null;
}

function komite_require_bill(mysqli $db, string $noInduk, string $month, string $year, bool $forUpdate=false): array {
    $bill=komite_bill($db,$noInduk,$month,$year,$forUpdate);
    if (!$bill) throw new RuntimeException('Tagihan Komite '.spp_month_label($month).' '.$year.' belum tersedia untuk siswa ini. Periksa penempatan kelas.');
    return $bill;
}

function komite_validate_amount(mysqli $db, string $noInduk, string $month, string $year, float $amount, bool $sppPayment): ?array {
    $bill=komite_bill($db,$noInduk,$month,$year,true);
    if (($amount > .001 || $sppPayment) && !$bill) $bill=komite_require_bill($db,$noInduk,$month,$year,true);
    $due=$bill ? (float)$bill['remaining'] : 0.0;
    if ($amount > .001 && ($due <= .001 || abs($amount-$due)>.001)) {
        throw new RuntimeException('Komite '.spp_month_label($month).' '.$year.' harus dibayar tepat Rp '.number_format($due,0,',','.').'.');
    }
    if ($sppPayment && $due > .001 && abs($amount-$due)>.001) {
        throw new RuntimeException('SPP dan Komite bulan ini dibayar bersama. Komite '.spp_month_label($month).' '.$year.' masih Rp '.number_format($due,0,',','.').'.');
    }
    return $bill;
}

function komite_save_payment(mysqli $db, int $paymentId, ?array $bill, float $amount): void {
    if ($amount <= .001) return;
    if (!$bill) throw new RuntimeException('Tagihan Komite tidak ditemukan.');
    $billId=(int)$bill['id'];
    $stmt=$db->prepare('INSERT INTO bayar_komite(bayar_id,tagihan_komite_id,nominal) VALUES(?,?,?)');
    $stmt->bind_param('iid',$paymentId,$billId,$amount);$stmt->execute();$stmt->close();
}

function komite_payment_payload(mysqli $db, int $excludePaymentId=0): array {
    $result=[];
    $stmt=$db->prepare("SELECT tk.no_induk,tk.bulan,tk.tahun,tk.nominal_tagihan,COALESCE(SUM(CASE WHEN bk.bayar_id<>? THEN bk.nominal ELSE 0 END),0) paid FROM tagihan_komite tk LEFT JOIN bayar_komite bk ON bk.tagihan_komite_id=tk.id WHERE tk.status='open' GROUP BY tk.id");
    $stmt->bind_param('i',$excludePaymentId);$stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $key=$row['bulan'].'-'.$row['tahun'];
        $result[$row['no_induk']][$key]=['total'=>(float)$row['nominal_tagihan'],'paid'=>(float)$row['paid']];
    }
    $stmt->close();return $result;
}

function komite_receipt_summary(mysqli $db, int $paymentId): ?array {
    $stmt=$db->prepare('SELECT tk.bulan,tk.tahun,tk.nominal_tagihan,COALESCE((SELECT SUM(all_bk.nominal) FROM bayar_komite all_bk WHERE all_bk.tagihan_komite_id=tk.id),0) paid FROM bayar_komite bk JOIN tagihan_komite tk ON tk.id=bk.tagihan_komite_id WHERE bk.bayar_id=? LIMIT 1');
    $stmt->bind_param('i',$paymentId);$stmt->execute();$result=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$result) return null;
    $result['label']=spp_month_label((string)$result['bulan']).' '.$result['tahun'];
    $result['remaining']=max(0,(float)$result['nominal_tagihan']-(float)$result['paid']);
    return $result;
}

/** Pembayaran SPP baru tidak boleh ditinggal tanpa Komite lunas saat Komite diedit/dihapus. */
function komite_assert_spp_pairs(mysqli $db, string $noInduk): void {
    $stmt=$db->prepare("SELECT tk.bulan,tk.tahun,tk.nominal_tagihan,COALESCE(SUM(bk.nominal),0) paid FROM tagihan_komite tk LEFT JOIN bayar_komite bk ON bk.tagihan_komite_id=tk.id WHERE tk.no_induk=? AND tk.status='open' AND EXISTS(SELECT 1 FROM tagihan_spp ts JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id JOIN spp_alokasi_batch ab ON ab.id=a.batch_id AND ab.status='active' AND ab.komite_required=1 WHERE ts.no_induk=tk.no_induk AND ts.bulan=tk.bulan AND ts.tahun=tk.tahun) GROUP BY tk.id HAVING tk.nominal_tagihan-paid>.001 LIMIT 1");
    $stmt->bind_param('s',$noInduk);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if ($row) throw new RuntimeException('Komite '.spp_month_label((string)$row['bulan']).' '.$row['tahun'].' tidak boleh ditinggalkan karena SPP bulan itu sudah dibayar.');
}
