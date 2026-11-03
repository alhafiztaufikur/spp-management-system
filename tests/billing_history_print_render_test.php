<?php
/** Synthetic rendering fixture only: no connection to application data. */
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/billing_history_print.php';
require_once __DIR__.'/../includes/global_report_layout.php';
$dir=getenv('SPP_BILLING_PRINT_ARTIFACTS');
if(PHP_SAPI!=='cli'||!$dir||!is_dir($dir)||str_starts_with(strtolower(str_replace('\\','/',realpath($dir))),strtolower(str_replace('\\','/',realpath(__DIR__.'/..')))))throw new RuntimeException('An existing external artifacts directory is required.');
$filters=['_billing_years'=>['*'],'_multi'=>['komponen_tagihan'=>['*']]];$items=[];
for($i=1;$i<=501;$i++){
    $row=['unit_id'=>$i>400?2:1,'nis'=>(string)(900000000+$i),'nama'=>($i===1?'Nama siswa sangat panjang untuk memeriksa pembungkusan teks dalam kolom cetak':'Fixture').' '.str_pad((string)$i,3,'0',STR_PAD_LEFT),'kelas'=>$i>250?'4B':'4A','tingkat'=>4,'master_kelas_id'=>$i>250?2:1,'komponen_key'=>'spp','komponen'=>'SPP','tahun_ajaran'=>$i>300?'2025/2026':'2026/2027','periode_code'=>'2026-11','sisa'=>$i===1?123456789012.25:100,'status'=>'Belum Bayar'];
    if($i===2)$row['sisa']=null;
    $items[]=$row;
    if($i===1)foreach(['2026-07','2026-08','2026-09','2026-10'] as $period){$r=$row;$r['periode_code']=$period;$r['sisa']=100;$items[]=$r;}
    if($i===3){$r=$row;$r['komponen_key']='buku';$r['komponen']='Buku';$r['sisa']=null;$items[]=$r;}
}
$view=billing_history_project($items,$filters,[],0);$before=serialize($view);
$prefix='<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:10mm 10mm 14mm}body{margin:0;font-family:Arial;font-size:9pt}'.billing_history_print_styles(true).'</style></head><body>';
$chunks=array_chunk($view['rows'],80);$documents=[];
foreach($chunks as $index=>$chunk)$documents[]=$prefix.billing_history_print_table($view,$chunk,$index*80,true,$index===count($chunks)-1).'</body></html>';
$pdf=global_report_render_pdf_fragments($documents,'landscape');
if(serialize($view)!==$before)throw new RuntimeException('Print rendering modified the fixture model.');
file_put_contents($dir.'/fixture-fragmented.pdf',$pdf);
file_put_contents($dir.'/fixture-expected.json',json_encode(['rows'=>array_map(static fn($r)=>['nis'=>$r['nis'],'amounts'=>$r['amounts'],'total'=>$r['total']],$view['rows']),'sections'=>count($view['sections']),'fragments'=>count($chunks),'grand'=>$view['grand']],JSON_THROW_ON_ERROR));
echo 'PASS synthetic 501-student PDF: '.count($chunks).' fragments; long names, large amounts, unknown values, extra component and multiple contexts.'.PHP_EOL;
