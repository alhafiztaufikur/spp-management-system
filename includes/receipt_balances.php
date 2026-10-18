<?php
require_once __DIR__.'/tagihan_sekali.php';
require_once __DIR__.'/daftar_ulang.php';
function payment_receipt_remaining(mysqli $db,array $payment): array {
    $psb=one_time_fee_status($db,(string)$payment['NO_INDUK']);$lines=[['Sisa PSB',$psb['psb']['remaining']]];
    $billId=(int)($payment['tagihan_daftar_ulang_id']??0);
    $bill=$billId?du_find_bill_by_id($db,$billId):du_find_bill($db,$payment['NO_INDUK'],(int)(function_exists('report_month_code')?report_month_code((string)$payment['BULAN']):$payment['BULAN']),(int)$payment['TAHUN']);
    if($bill&&$bill['status']==='open')$lines[]=['Sisa DU',(float)$bill['sisa']];
    else $lines[]=['Sisa DU',null];
    return $lines;
}
