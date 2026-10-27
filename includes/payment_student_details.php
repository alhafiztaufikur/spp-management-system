<?php
require_once __DIR__.'/tagihan_tahunan.php';
require_once __DIR__.'/biaya_lain.php';

/** The same option payloads as the payment form, bounded to one resolved student. */
function payment_student_details(mysqli $db,string $nis,int $excludePaymentId=0): array {
    $psb=one_time_fee_status($db,$nis,$excludePaymentId)['psb'];
    $periods=['spp'=>[],'komite'=>[]];
    $stmt=$db->prepare('SELECT BULAN,TAHUN,SUM(U_SPP) spp,SUM(U_KOMITE) komite FROM bayar WHERE NO_INDUK=? AND id<>? GROUP BY BULAN,TAHUN');
    $stmt->bind_param('si',$nis,$excludePaymentId);$stmt->execute();
    foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row){
        $key=spp_sequence_month_code((string)$row['BULAN']).'-'.$row['TAHUN'];
        foreach(['spp','komite'] as $kind)$periods[$kind][$key]=($periods[$kind][$key]??0)+(float)$row[$kind];
    }$stmt->close();
    $stmt=$db->prepare("SELECT ta.label tahun_ajaran,sta.spp_perbulan_snapshot tarif,sta.spp_covered_by_psb covered_by_psb FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id AND ta.unit_id=sta.unit_id WHERE sta.no_induk=? AND sta.status='aktif' ORDER BY ta.label");
    $stmt->bind_param('s',$nis);$stmt->execute();$placements=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    $other=[];
    foreach(other_fee_open_bills($db,$nis,$excludePaymentId) as $bill){
        if((float)$bill['sisa']<=.001)continue;
        $other[]=['id'=>(int)$bill['id'],'master_id'=>(int)$bill['master_biaya_lain_id'],'nama'=>$bill['nama'],'nominal'=>(float)$bill['nominal'],'paid'=>(float)$bill['terbayar'],'sisa'=>(float)$bill['sisa'],'optional'=>false];
    }
    return ['psbKnown'=>$psb['known']?'1':'0','totalPsb'=>$psb['total'],'paidPsb'=>$psb['paid'],
        'paidSppPeriods'=>$periods['spp'],'paidKomitePeriods'=>$periods['komite'],'sppPlacements'=>$placements,
        'sppBilling'=>spp_payment_payload($db,$excludePaymentId,$nis)[$nis]??['tagihan'=>[]],
        'komiteBilling'=>komite_payment_payload($db,$excludePaymentId,$nis)[$nis]??[],
        'duBills'=>du_selectable_bills_payload($db,$excludePaymentId,0,$nis)[$nis]??[],
        'duCoveredPsb'=>du_psb_state($db,$nis,du_current_academic_year())['covered_psb']?'1':'0',
        'annualFees'=>annual_fee_payload_for_options($db,$excludePaymentId,$nis)[$nis]??[],
        'biayaLainBills'=>array_merge(other_fee_optional_choices($db),$other)];
}
