<?php
/** The actual November payment must agree across all four obligation reports. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]!==58470)throw new RuntimeException('Owned report clone required.');
require_once __DIR__.'/../includes/reports.php';
$qa=getenv('SPP_GLOBAL_QA_DIR');$fixture=json_decode(file_get_contents($qa.'/fixture.json'),true,512,JSON_THROW_ON_ERROR);$checks=[];
function gp_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;echo 'PASS '.$label.PHP_EOL;}
foreach([1,2,3] as $unit){unit_set_context($koneksi,$unit);$student=$fixture['samples'][$unit];$expected=0;
    foreach(hp_rows($koneksi,"SELECT p.items FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id WHERE o.student_id=? AND p.source_period='11 2026' AND p.cancelled=0",[$student['student_id']]) as $p)foreach(json_decode($p['items'],true) as $item)if($item['category']==='spp'){$parts=explode('.',(string)$item['amount']);$expected+=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');}
    if($unit===1)gp_check($expected===55500000,'Actual SD November source payment is Rp555.000');
    $query=['template'=>'status','kategori'=>'spp','q'=>$student['nis'],'tahun_ajaran'=>'2026/2027','bulan_awal'=>'11','bulan_akhir'=>'11','tahun_awal'=>'2026','tahun_akhir'=>'2026','siswa_status'=>'all'];
    foreach(['status','per-item','spp-tahunan','riwayat-tagihan'] as $template){$query['template']=$template;$r=report_build($koneksi,$template,report_filters($koneksi,$query));
        if($template==='spp-tahunan')gp_check(count(array_filter($r['rows'],static fn($row)=>($row['m11_2026']['sub']??'')===report_money($expected/100)))===1,"Unit $unit annual November cell equals source payment");
        else{$rows=$template==='riwayat-tagihan'?array_filter($r['rows'],static fn($row)=>($row['komponen_key']??'')==='spp'&&($row['periode_code']??'')==='2026-11'):$r['rows'];$key=$template==='per-item'?'total_bayar':'terbayar';gp_check((int)round(array_sum(array_column($rows,$key))*100)===$expected,"Unit $unit $template November payment equals source cents");}
    }
}
file_put_contents($qa.'/payment-case-tests.json',json_encode(['checks'=>count($checks),'passed'=>$checks],JSON_PRETTY_PRINT));echo 'OK: '.count($checks)." actual payment checks\n";
