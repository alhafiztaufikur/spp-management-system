<?php
require_once __DIR__.'/tagihan_sekali.php';
require_once __DIR__.'/daftar_ulang.php';
/** Current balance, with the DU year fixed to the receipt period (not print date). */
function payment_receipt_remaining(mysqli $db,array $payment): array {
    if(array_key_exists('student_id',$payment)&&empty($payment['student_id']))return [['Sisa PSB',null,'Tidak berlaku'],['Sisa DU',null,'Tidak berlaku']];
    $nis=(string)$payment['NO_INDUK'];$psb=one_time_fee_status($db,$nis);
    $lines=[['Sisa PSB',!empty($psb['psb']['known'])?$psb['psb']['remaining']:null,'Belum dapat ditentukan']];
    $year=lb_payment_year((string)($payment['source_period']??''));
    if(!preg_match('~^\d{4}/\d{4}$~D',$year)){
        $month=(int)(function_exists('report_month_code')?report_month_code((string)($payment['BULAN']??'')):($payment['BULAN']??0));$calendar=(int)($payment['TAHUN']??0);
        $year=$month>=1&&$month<=12&&$calendar>=2000?du_academic_year_label($month,$calendar):'';
    }
    $billId=(int)($payment['tagihan_daftar_ulang_id']??0);
    $bill=$billId?du_find_bill_by_id($db,$billId):($year!==''?lf_one($db,'SELECT id FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_snapshot=? AND status=\'open\'',[$nis,$year]):null);
    if($bill&&!isset($bill['sisa']))$bill=du_find_bill_by_id($db,(int)$bill['id']);
    if($bill&&$bill['status']==='open')$lines[]=['Sisa DU',(float)$bill['sisa']];
    else {$proof=$year!==''?lb_du_source($db,$nis,$year):null;
        $remaining=$proof&&!empty($proof['known'])?max(0,$proof['total']-lb_du_credit($db,$nis,$year)-lb_native_du_cents($db,$nis,$year)/100):null;
        $lines[]=['Sisa DU',$remaining,'-'];
    }return $lines;
}
