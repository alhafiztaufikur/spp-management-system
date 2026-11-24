<?php
require_once __DIR__.'/tagihan_sekali.php';
require_once __DIR__.'/komite_billing.php';
require_once __DIR__.'/daftar_ulang.php';
/** Read-only summary. Informational obligations never waive SPP/Komite validation. */
function payment_obligations(mysqli $db,string $nis,?array $otherBills=null): array {
    $rows=[];$oldest=null;$cutoff=(int)date('Ym');
    $append=static function(string $label,float $amount,?array $period=null,string $kind='other')use(&$rows,&$oldest){if($amount<=.001)return;$rows[]=['label'=>$label,'remaining'=>$amount,'kind'=>$kind];if($period&&(!$oldest||((int)$period['tahun']*100+(int)$period['bulan'])<((int)$oldest['tahun']*100+(int)$oldest['bulan'])))$oldest=$period;};
    $psb=one_time_fee_status($db,$nis);
    if ($psb['psb']['remaining'] !== null) $append('Uang PSB',$psb['psb']['remaining'],null,'psb');
    $du = hp_rows($db,"SELECT id,tahun_ajaran_snapshot label FROM tagihan_daftar_ulang
        WHERE no_induk=? AND status='open' AND tahun_ajaran_snapshot<=? ORDER BY tahun_ajaran_snapshot",
        [$nis,du_current_academic_year()]);
    foreach ($du as $item) {
        $bill=du_find_bill_by_id($db,(int)$item['id']);
        if ($bill) $append('Daftar Ulang TA '.$item['label'],$bill['sisa'],null,'du');
    }
    $komite = hp_rows($db,"SELECT t.no_induk,t.bulan,t.tahun,t.nominal_tagihan,COALESCE(SUM(d.nominal),0) paid
        FROM tagihan_komite t LEFT JOIN bayar_komite d ON d.tagihan_komite_id=t.id AND d.unit_id=t.unit_id
        WHERE t.no_induk=? AND t.status='open' AND CAST(t.tahun AS SIGNED)*100+CAST(t.bulan AS SIGNED)<CAST(? AS SIGNED)
        GROUP BY t.id ORDER BY t.tahun,t.bulan",[$nis,$cutoff]);
    foreach ($komite as $item) {
        $position=komite_bill_position($db,$item);
        $label=spp_month_label($item['bulan']).' '.$item['tahun'];
        $append('Komite '.$label,$position['remaining'],['bulan'=>$item['bulan'],'tahun'=>$item['tahun'],'label'=>$label],'komite');
    }
    require_once __DIR__.'/biaya_lain.php';foreach($otherBills ?? other_fee_open_bills($db,$nis) as $bill)$append($bill['nama'],(float)$bill['sisa'],null,'other');
    foreach(spp_open_bills($db,$nis) as $bill)if((int)$bill['tahun']*100+(int)$bill['bulan']<$cutoff){$period=['bulan'=>$bill['bulan'],'tahun'=>$bill['tahun'],'label'=>spp_month_label($bill['bulan']).' '.$bill['tahun']];$append('SPP '.$period['label'],(float)$bill['remaining'],$period,'spp');}
    return ['rows'=>$rows,'oldest_period'=>$oldest];
}
