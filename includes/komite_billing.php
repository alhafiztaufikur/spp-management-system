<?php

require_once __DIR__ . '/spp_billing.php';
require_once __DIR__ . '/spp_payment_status.php';

function komite_academic_year(string $month, string $year): string {
    $number = (int)$month;
    $start = $number >= 7 ? (int)$year : (int)$year - 1;
    return $start . '/' . ($start + 1);
}

/** Tagihan dibuat dari penempatan yang tersimpan, tanpa bergantung pada penerbitan SPP. */
function komite_sync_placement(mysqli $db, int $placementId): int {
    $stmt = $db->prepare('SELECT sta.*,ta.label,s.POMG FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk AND s.unit_id=sta.unit_id WHERE sta.id=? FOR UPDATE');
    $stmt->bind_param('i', $placementId); $stmt->execute();
    $placement = $stmt->get_result()->fetch_assoc(); $stmt->close();
    [$firstLevel, $lastLevel] = unit_level_bounds();
    if (!$placement || in_array($placement['status'],['lulus','dibatalkan'],true)
        || (int)$placement['kelas'] < $firstLevel || (int)$placement['kelas'] > $lastLevel) return 0;
    $periods = spp_academic_periods((string)$placement['label']);
    $start = (string)($placement['komite_mulai_bulan'] ?? '07');
    $startIndex = 0;
    foreach ($periods as $index => $period) if ($period['bulan'] === $start) { $startIndex = $index; break; }
    $rate = (float)$placement['komite_snapshot'];
    $sourcePlan=lb_monthly_plan($db,(string)$placement['no_induk'])['positions'];
    $opening=lb_student($db,(string)$placement['no_induk']);$manualRate=false;
    if($opening){
        if($placement['label']===$opening['migration_year'])$manualRate=abs($rate-(float)($opening['raw']['POMG']??$rate))>.001;
        elseif($rate<=.001){
            $manualYear=false;
            foreach(hp_rows($db,'SELECT before_data,after_data FROM siswa_audit_log WHERE siswa_id=?',[$opening['student_id']]) as $audit){$before=json_decode($audit['before_data']??'{}',true)??[];$after=json_decode($audit['after_data']??'{}',true)??[];if(($after['_tariff_sync']['tahun_ajaran']??'')===$placement['label']&&isset($before['POMG'],$after['POMG'])&&lb_amount($before['POMG'])!==lb_amount($after['POMG']))$manualYear=true;}
            $rates=[];foreach($sourcePlan as $proof)if($proof['category']==='komite'&&$proof['year']===$placement['label'])$rates[$proof['principal']]=true;
            if(!$manualYear&&count($rates)===1)$rate=(int)array_key_first($rates)/100;
            $manualRate=$manualYear;
        }
    }
    $insert = $db->prepare("INSERT IGNORE INTO tagihan_komite(tahun_ajaran_id,penempatan_id,no_induk,kelas_rombel_snapshot,bulan,tahun,nominal_tagihan) VALUES(?,?,?,?,?,?,?)");
    $created = 0;
    foreach ($periods as $index => $period) {
        if ($index < $startIndex) continue;
        $yearId = (int)$placement['tahun_ajaran_id'];
        $nis = (string)$placement['no_induk'];
        $class = (string)$placement['kelas_rombel_snapshot'];
        $month = (string)$period['bulan']; $year = (string)$period['tahun'];
        require_once __DIR__.'/legacy_full_read.php';if(lful_is_covered($db,$nis,'komite',$year.'-'.str_pad($month,2,'0',STR_PAD_LEFT)))continue;
        $proof=$sourcePlan['komite|'.$year.'-'.$month]??null;
        if($manualRate&&empty($proof['paid']))$proof=null;
        $planned=lmp_publication($proof,$year.'-'.$month,lb_result_cents($rate),0);
        $periodRate=$planned['principal']/100;
        $insert->bind_param('iissssd', $yearId, $placementId, $nis, $class, $month, $year, $periodRate);
        $insert->execute(); $created += $insert->affected_rows;
    }
    $insert->close();
    return $created;
}

function komite_set_start_month(mysqli $db, int $placementId, string $month): bool {
    if (!in_array($month,['01','02','03','04','05','06','07','08','09','10','11','12'],true)) throw new RuntimeException('Bulan mulai tagihan Komite tidak valid.');
    $stmt=$db->prepare('SELECT sta.id,sta.no_induk,sta.komite_mulai_bulan,ta.label FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id WHERE sta.id=? FOR UPDATE');
    $stmt->bind_param('i',$placementId);$stmt->execute();$placement=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$placement) throw new RuntimeException('Penempatan siswa tidak ditemukan.');
    // Start controls filling gaps; it does not erase existing obligations or credit.
    $stmt=$db->prepare('UPDATE siswa_tahun_ajaran SET komite_mulai_bulan=? WHERE id=?');
    $stmt->bind_param('si',$month,$placementId);$stmt->execute();$stmt->close();
    komite_sync_placement($db,$placementId);
    $changed=$placement['komite_mulai_bulan']!==$month;
    if($changed)spp_write_audit($db,null,$placement['no_induk'],'ubah_mulai_komite',['penempatan_id'=>$placementId,'mulai'=>$placement['komite_mulai_bulan']],['penempatan_id'=>$placementId,'mulai'=>$month],1);
    return $changed;
}

function komite_sync_student_rate(mysqli $db, string $noInduk, float $rate, int $placementId): array {
    if ($rate < 0 || !is_finite($rate)) throw new RuntimeException('Tarif Komite tidak valid.');
    $stmt = $db->prepare("SELECT id FROM siswa_tahun_ajaran WHERE id=? AND no_induk=? AND status='aktif' LIMIT 1 FOR UPDATE");
    $stmt->bind_param('is', $placementId, $noInduk); $stmt->execute();
    $placement = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$placement) throw new RuntimeException('Penempatan aktif siswa untuk perubahan tarif Komite tidak ditemukan.');
    $changed=0;
    foreach(lf_rows($db,"SELECT tk.id,tk.bulan,tk.tahun FROM tagihan_komite tk WHERE tk.penempatan_id=? AND tk.no_induk=? AND tk.status='open' AND NOT EXISTS(SELECT 1 FROM bayar_komite bk WHERE bk.tagihan_komite_id=tk.id)",[$placementId,$noInduk]) as $bill){
        if(lb_monthly_credit($db,$noInduk,'komite',$bill['bulan'],$bill['tahun'])>.001)continue;
        lf_query($db,'UPDATE tagihan_komite SET nominal_tagihan=? WHERE id=?',[$rate,$bill['id']]);$changed+=$db->affected_rows;
    }
    $stmt = $db->prepare("UPDATE siswa_tahun_ajaran SET komite_snapshot=? WHERE id=? AND no_induk=? AND status='aktif'");
    $stmt->bind_param('dis', $rate, $placementId, $noInduk); $stmt->execute(); $stmt->close();
    return ['updated'=>$changed];
}

/** Shared current position for cashier, payload and obligation summary. */
function komite_bill_position(mysqli $db,array $bill): array {
    $bill['native_paid']=(float)($bill['native_paid']??$bill['paid']);
    $bill['legacy_paid']=lb_monthly_credit($db,$bill['no_induk'],'komite',(string)$bill['bulan'],(string)$bill['tahun']);
    $bill['paid']=$bill['native_paid']+$bill['legacy_paid'];
    $bill['remaining']=max(0,(float)$bill['nominal_tagihan']-$bill['paid']);
    return $bill;
}

function komite_bill(mysqli $db, string $noInduk, string $month, string $year, bool $forUpdate=false, int $excludePaymentId=0): ?array {
    $exclude = $excludePaymentId > 0 ? ' AND (bk.bayar_id IS NULL OR bk.bayar_id<>' . $excludePaymentId . ')' : '';
    $paidSql = $forUpdate ? '0' : "COALESCE((SELECT SUM(bk.nominal) FROM bayar_komite bk WHERE bk.tagihan_komite_id=tk.id" . $exclude . "),0)";
    $sql = "SELECT tk.*,{$paidSql} paid FROM tagihan_komite tk WHERE tk.no_induk=? AND tk.bulan=? AND tk.tahun=? AND tk.status='open' LIMIT 1";
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt=$db->prepare($sql); $stmt->bind_param('sss',$noInduk,$month,$year);$stmt->execute();
    $bill=$stmt->get_result()->fetch_assoc();$stmt->close();
    if ($bill && $forUpdate) {
        // A scalar subquery can retain an older repeatable-read snapshot even
        // after waiting for the bill lock. Read payment rows as current locks.
        $paidRows = $db->prepare('SELECT nominal FROM bayar_komite WHERE tagihan_komite_id=?'
            . ($excludePaymentId > 0 ? ' AND (bayar_id IS NULL OR bayar_id<>?)' : '') . ' FOR UPDATE');
        $billId = (int)$bill['id'];
        if ($excludePaymentId > 0) $paidRows->bind_param('ii', $billId, $excludePaymentId);
        else $paidRows->bind_param('i', $billId);
        $paidRows->execute();
        $bill['paid'] = 0.0;
        foreach ($paidRows->get_result()->fetch_all(MYSQLI_ASSOC) as $payment) $bill['paid'] += (float)$payment['nominal'];
        $paidRows->close();
    }
    return $bill?komite_bill_position($db,$bill):null;
}

function komite_require_bill(mysqli $db, string $noInduk, string $month, string $year, bool $forUpdate=false, int $excludePaymentId=0): array {
    $bill=komite_bill($db,$noInduk,$month,$year,$forUpdate,$excludePaymentId);
    if (!$bill) throw new RuntimeException('Tagihan Komite '.spp_month_label($month).' '.$year.' belum tersedia untuk siswa ini. Periksa penempatan kelas.');
    return $bill;
}

function komite_validate_amount(mysqli $db, string $noInduk, string $month, string $year, float $amount, bool $sppPayment, int $excludePaymentId=0): ?array {
    if($amount>.001||$sppPayment){
        foreach(hp_rows($db,"SELECT bulan,tahun FROM tagihan_komite WHERE no_induk=? AND status='open' AND CONCAT(tahun,'-',LPAD(bulan,2,'0'))<? ORDER BY tahun,bulan",[$noInduk,$year.'-'.str_pad($month,2,'0',STR_PAD_LEFT)]) as $period){
            $older=komite_bill($db,$noInduk,$period['bulan'],$period['tahun'],true,$excludePaymentId);
            if($older&&$older['remaining']>.001){$label=spp_month_label($period['bulan']).' '.$period['tahun'];throw new SppPaymentException(['code'=>'komite_prior_unpaid','severity'=>'error','title'=>'Ada Komite yang lebih lama','message'=>'Lunasi Komite '.$label.' lebih dahulu, sebesar Rp '.number_format($older['remaining'],0,',','.').'.','amount_label'=>'Sisa Komite Rp '.number_format($older['remaining'],0,',','.'),'target'=>'komite-input']);}
        }
    }
    $bill=komite_bill($db,$noInduk,$month,$year,true,$excludePaymentId);
    if(!$bill&&$sppPayment&&$amount<=.001){
        $decision=hp_one($db,"SELECT c.disposition,ob.remaining FROM legacy_full_coverage c JOIN siswa s ON s.id=c.student_id AND s.unit_id=c.unit_id LEFT JOIN legacy_op_owners o ON o.student_id=s.id AND o.unit_id=s.unit_id LEFT JOIN legacy_op_obligations ob ON ob.owner_id=o.id AND ob.unit_id=o.unit_id AND ob.category=c.category AND ob.period=c.period WHERE s.NO_INDUK=? AND c.category='komite' AND c.period=?",[$noInduk,$year.'-'.str_pad($month,2,'0',STR_PAD_LEFT)]);
        if($decision&&($decision['disposition']==='not_applicable'||($decision['disposition']==='settled'&&$decision['remaining']!==null&&(float)$decision['remaining']<=.001)))return null;
    }
    if (($amount > .001 || $sppPayment) && !$bill) $bill=komite_require_bill($db,$noInduk,$month,$year,true,$excludePaymentId);
    $due=$bill ? (float)$bill['remaining'] : 0.0;
    if ($amount > .001 && ($due <= .001 || abs($amount-$due)>.001)) {
        $label=spp_month_label($month).' '.$year;
        throw new SppPaymentException(['code'=>'komite_amount','severity'=>'error','title'=>'Nominal belum sesuai',
            'message'=>'Komite '.$label.' harus lunas Rp '.number_format($due,0,',','.').'. Periksa angka yang diisi.',
            'amount_label'=>'Sisa Komite Rp '.number_format($due,0,',','.'),'target'=>'komite-input']);
    }
    if ($sppPayment && $due > .001 && abs($amount-$due)>.001) {
        $label = spp_month_label($month).' '.$year;
        throw new SppPaymentException(['code'=>'komite_required','severity'=>'error','title'=>'Komite belum dibayar',
            'message'=>'SPP '.$label.' harus dibayar bersama Komite bulan yang sama. Sisa Komite Rp '.number_format($due,0,',','.').'.',
            'amount_label'=>'Sisa Komite Rp '.number_format($due,0,',','.'),'target'=>'komite-input']);
    }
    return $bill;
}

/** Komite dan SPP wajib bersama hanya bila keduanya masih terutang pada bulan yang sama. */
function komite_validate_spp_pair(mysqli $db, string $noInduk, string $month, string $year, float $komiteAmount, bool $sppPayment): void {
    if ($komiteAmount <= .001 || $sppPayment) return;
    $stmt=$db->prepare('SELECT id,nominal_tagihan,status FROM tagihan_spp WHERE no_induk=? AND bulan=? AND tahun=? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('sss',$noInduk,$month,$year);$stmt->execute();$bill=$stmt->get_result()->fetch_assoc();$stmt->close();
    $label=spp_month_label($month).' '.$year;
    if (!$bill) return; // Hanya Komite terutang; penerbitan SPP tetap terpisah.
    if ((float)$bill['nominal_tagihan'] <= .001) return;
    if ($bill['status'] !== 'open') return;
    $status=spp_published_period_status($db,$noInduk,$month,$year);
    if (($status['status'] ?? '') === 'already_paid') return;
    if (($status['status'] ?? '') === 'prior_unpaid') throw new SppPaymentException($status);
    $due=(float)($status['selected']['remaining'] ?? 0);
    throw new SppPaymentException(['code'=>'spp_required','severity'=>'error','title'=>'SPP belum dibayar',
        'message'=>'Komite '.$label.' harus dibayar bersama SPP bulan yang sama. Sisa SPP Rp '.number_format($due,0,',','.').'.',
        'amount_label'=>'Sisa SPP Rp '.number_format($due,0,',','.'),'target'=>'spp-input']);
}

/** Mendeteksi pembayaran satu sisi agar edit/hapus tidak menciptakan ketimpangan baru. */
function komite_pair_gap(mysqli $db, string $noInduk, string $month, string $year): string {
    $stmt=$db->prepare("SELECT ts.nominal_tagihan,ts.status,COALESCE(SUM(CASE WHEN ab.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0) paid FROM tagihan_spp ts LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id WHERE ts.no_induk=? AND ts.bulan=? AND ts.tahun=? GROUP BY ts.id LIMIT 1");
    $stmt->bind_param('sss',$noInduk,$month,$year);$stmt->execute();$spp=$stmt->get_result()->fetch_assoc();$stmt->close();
    $komite=komite_bill($db,$noInduk,$month,$year);
    if (!$spp || !$komite || $spp['status']!=='open' || (float)$spp['nominal_tagihan']<=.001 || (float)$komite['nominal_tagihan']<=.001) return '';
    $sppPaid=(float)$spp['paid']+lb_spp_credit($db,$noInduk,$month,$year)+.001>=(float)$spp['nominal_tagihan'];
    $komitePaid=(float)$komite['paid']+.001>=(float)$komite['nominal_tagihan'];
    return $sppPaid===$komitePaid ? '' : ($sppPaid?'spp_only':'komite_only');
}

function komite_save_payment(mysqli $db, int $paymentId, ?array $bill, float $amount): void {
    if ($amount <= .001) return;
    if (!$bill) throw new RuntimeException('Tagihan Komite tidak ditemukan.');
    $billId=(int)$bill['id'];
    $stmt=$db->prepare('INSERT INTO bayar_komite(bayar_id,tagihan_komite_id,nominal) VALUES(?,?,?)');
    $stmt->bind_param('iid',$paymentId,$billId,$amount);$stmt->execute();$stmt->close();
}

function komite_payment_payload(mysqli $db, int $excludePaymentId=0, ?string $noInduk=null): array {
    $result=[];$filter=$noInduk===null?'':" AND tk.no_induk='".$db->real_escape_string($noInduk)."'";
    $stmt=$db->prepare(hp_sql($db,"SELECT tk.no_induk,tk.bulan,tk.tahun,tk.nominal_tagihan,COALESCE(SUM(CASE WHEN bk.bayar_id IS NULL OR bk.bayar_id<>? THEN bk.nominal ELSE 0 END),0) paid FROM tagihan_komite tk LEFT JOIN bayar_komite bk ON bk.tagihan_komite_id=tk.id WHERE tk.status='open' $filter GROUP BY tk.id"));
    $stmt->bind_param('i',$excludePaymentId);$stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $key=$row['bulan'].'-'.$row['tahun'];
        $position=komite_bill_position($db,$row);
        $result[$row['no_induk']][$key]=['total'=>(float)$row['nominal_tagihan'],'paid'=>$position['paid']];
    }
    $stmt->close();return $result;
}

function komite_receipt_summary(mysqli $db, int $paymentId): ?array {
    $stmt=$db->prepare('SELECT tk.no_induk,tk.bulan,tk.tahun,tk.nominal_tagihan,COALESCE((SELECT SUM(all_bk.nominal) FROM bayar_komite all_bk WHERE all_bk.tagihan_komite_id=tk.id),0) paid FROM bayar_komite bk JOIN tagihan_komite tk ON tk.id=bk.tagihan_komite_id WHERE bk.bayar_id=? LIMIT 1');
    $stmt->bind_param('i',$paymentId);$stmt->execute();$result=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$result) return null;
    $result['paid']=(float)$result['paid']+lb_monthly_credit($db,$result['no_induk'],'komite',$result['bulan'],$result['tahun']);
    $result['label']=spp_month_label((string)$result['bulan']).' '.$result['tahun'];
    $result['remaining']=max(0,(float)$result['nominal_tagihan']-(float)$result['paid']);
    return $result;
}

/** Pembayaran SPP baru tidak boleh ditinggal tanpa Komite lunas saat Komite diedit/dihapus. */
function komite_assert_spp_pairs(mysqli $db, string $noInduk): void {
    $stmt=$db->prepare("SELECT tk.bulan,tk.tahun,tk.nominal_tagihan,COALESCE(SUM(bk.nominal),0) paid FROM tagihan_komite tk LEFT JOIN bayar_komite bk ON bk.tagihan_komite_id=tk.id WHERE tk.no_induk=? AND tk.status='open' AND EXISTS(SELECT 1 FROM tagihan_spp ts JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id JOIN spp_alokasi_batch ab ON ab.id=a.batch_id AND ab.status='active' AND ab.komite_required=1 WHERE ts.no_induk=tk.no_induk AND ts.unit_id=tk.unit_id AND ts.bulan=tk.bulan AND ts.tahun=tk.tahun) GROUP BY tk.id HAVING tk.nominal_tagihan-paid>.001");
    $stmt->bind_param('s',$noInduk);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    foreach($rows as $row)if((float)$row['nominal_tagihan']-(float)$row['paid']-lb_monthly_credit($db,$noInduk,'komite',$row['bulan'],$row['tahun'])>.001) throw new RuntimeException('Komite '.spp_month_label((string)$row['bulan']).' '.$row['tahun'].' tidak boleh ditinggalkan karena SPP bulan itu sudah dibayar.');
}
