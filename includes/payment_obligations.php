<?php
require_once __DIR__.'/tagihan_sekali.php';
/** Read-only summary. Informational obligations never waive SPP/Komite validation. */
function payment_obligations(mysqli $db,string $nis): array {
    $rows=[];$oldest=null;$cutoff=(int)date('Ym');
    $append=static function(string $label,float $amount,?array $period=null,string $kind='other')use(&$rows,&$oldest){if($amount<=.001)return;$rows[]=['label'=>$label,'remaining'=>$amount,'kind'=>$kind];if($period&&(!$oldest||((int)$period['tahun']*100+(int)$period['bulan'])<((int)$oldest['tahun']*100+(int)$oldest['bulan'])))$oldest=$period;};
    $psb=one_time_fee_status($db,$nis);$append('Uang PSB',$psb['psb']['remaining'],null,'psb');
    $queries=[
      'du'=>"SELECT t.tahun_ajaran_snapshot label,t.nominal_tagihan-COALESCE(SUM(d.jumlah),0) remaining FROM tagihan_daftar_ulang t LEFT JOIN bayar_du d ON d.tagihan_daftar_ulang_id=t.id WHERE t.no_induk=? AND t.status='open' GROUP BY t.id ORDER BY t.tahun_ajaran_snapshot",
      'other'=>"SELECT t.nama_snapshot label,t.nominal_tagihan-COALESCE(SUM(d.nominal_snapshot),0) remaining FROM tagihan_biaya_lain t LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id WHERE t.no_induk=? AND t.status='open' GROUP BY t.id ORDER BY t.nama_snapshot",
      'komite'=>"SELECT t.bulan,t.tahun,t.nominal_tagihan-COALESCE(SUM(d.nominal),0) remaining FROM tagihan_komite t LEFT JOIN bayar_komite d ON d.tagihan_komite_id=t.id WHERE t.no_induk=? AND t.status='open' GROUP BY t.id ORDER BY t.tahun,t.bulan",
    ];
    foreach($queries as $kind=>$sql){$s=$db->prepare($sql);$s->bind_param('s',$nis);$s->execute();$items=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();foreach($items as $item){
        if($kind==='komite'){if((int)$item['tahun']*100+(int)$item['bulan']>=$cutoff)continue;$label='Komite '.spp_month_label($item['bulan']).' '.$item['tahun'];$append($label,(float)$item['remaining'],['bulan'=>$item['bulan'],'tahun'=>$item['tahun'],'label'=>spp_month_label($item['bulan']).' '.$item['tahun']],'komite');}
        else $append(($kind==='du'?'Daftar Ulang TA ':'').$item['label'],(float)$item['remaining'],null,$kind);
    }}
    foreach(spp_open_bills($db,$nis) as $bill)if((int)$bill['tahun']*100+(int)$bill['bulan']<$cutoff){$period=['bulan'=>$bill['bulan'],'tahun'=>$bill['tahun'],'label'=>spp_month_label($bill['bulan']).' '.$bill['tahun']];$append('SPP '.$period['label'],(float)$bill['remaining'],$period,'spp');}
    return ['rows'=>$rows,'oldest_period'=>$oldest];
}
