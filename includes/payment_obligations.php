<?php
require_once __DIR__.'/tagihan_sekali.php';
require_once __DIR__.'/komite_billing.php';
require_once __DIR__.'/daftar_ulang.php';
/** Read-only summary. Informational obligations never waive SPP/Komite validation. */
function payment_obligations(mysqli $db,string $nis): array {
    $rows=[];$oldest=null;$cutoff=(int)date('Ym');
    $append=static function(string $label,float $amount,?array $period=null,string $kind='other')use(&$rows,&$oldest){if($amount<=.001)return;$rows[]=['label'=>$label,'remaining'=>$amount,'kind'=>$kind];if($period&&(!$oldest||((int)$period['tahun']*100+(int)$period['bulan'])<((int)$oldest['tahun']*100+(int)$oldest['bulan'])))$oldest=$period;};
    $psb=one_time_fee_status($db,$nis);$append('Uang PSB',$psb['psb']['remaining'],null,'psb');
    $queries=[
      'du'=>"SELECT t.id,t.tahun_ajaran_snapshot label FROM tagihan_daftar_ulang t WHERE t.no_induk=? AND t.status='open' ORDER BY t.tahun_ajaran_snapshot",
      'other'=>"SELECT t.nama_snapshot label,t.nominal_tagihan-COALESCE(SUM(d.nominal_snapshot),0) remaining FROM tagihan_biaya_lain t LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id WHERE t.no_induk=? AND t.status='open' GROUP BY t.id ORDER BY t.nama_snapshot",
      'komite'=>"SELECT t.no_induk,t.bulan,t.tahun,t.nominal_tagihan,COALESCE(SUM(d.nominal),0) paid FROM tagihan_komite t LEFT JOIN bayar_komite d ON d.tagihan_komite_id=t.id AND d.unit_id=t.unit_id WHERE t.no_induk=? AND t.status='open' GROUP BY t.id ORDER BY t.tahun,t.bulan",
    ];
    foreach($queries as $kind=>$sql){foreach(hp_rows($db,$sql,[$nis]) as $item){
        if($kind==='komite'){if((int)$item['tahun']*100+(int)$item['bulan']>=$cutoff)continue;$position=komite_bill_position($db,$item);$label='Komite '.spp_month_label($item['bulan']).' '.$item['tahun'];$append($label,$position['remaining'],['bulan'=>$item['bulan'],'tahun'=>$item['tahun'],'label'=>spp_month_label($item['bulan']).' '.$item['tahun']],'komite');}
        elseif($kind==='du'){$bill=du_find_bill_by_id($db,(int)$item['id']);if($bill&&$bill['tahun_ajaran']<=du_current_academic_year())$append('Daftar Ulang TA '.$item['label'],$bill['sisa'],null,$kind);}
        else $append($item['label'],(float)$item['remaining'],null,$kind);
    }}
    foreach(spp_open_bills($db,$nis) as $bill)if((int)$bill['tahun']*100+(int)$bill['bulan']<$cutoff){$period=['bulan'=>$bill['bulan'],'tahun'=>$bill['tahun'],'label'=>spp_month_label($bill['bulan']).' '.$bill['tahun']];$append('SPP '.$period['label'],(float)$bill['remaining'],$period,'spp');}
    return ['rows'=>$rows,'oldest_period'=>$oldest];
}
