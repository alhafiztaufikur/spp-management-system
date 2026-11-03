<?php
/** Large print fixture, with no application database connection. */
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/global_report_print.php';
require_once __DIR__.'/../includes/global_report_layout.php';
$dir=(string)getenv('SPP_GLOBAL_PRINT_ARTIFACTS');
if(PHP_SAPI!=='cli'||!is_dir($dir)||str_starts_with(strtolower(str_replace('\\','/',realpath($dir))),strtolower(str_replace('\\','/',realpath(__DIR__.'/..')))))throw new RuntimeException('Private external artifact directory required.');
$columns=[['no','No','number'],['unit','Unit'],['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas']];$months=[];
foreach(['Juli','Agustus','September','Oktober','November','Desember','Januari','Februari','Maret','April','Mei','Juni'] as $i=>$month)$months[]=['m'.$i,$month.' '.($i<6?'2026':'2027'),'html'];
$financial=[['tagihan','Total Tagihan','money'],['bayar','Total Bayar','money'],['sisa','Tunggakan','money_optional']];$columns=array_merge($columns,$months,$financial);$rows=[];$sub=[];
for($i=1;$i<=270;$i++){
    $row=['no'=>$i,'unit'=>['SD','SMP','SMA'][$i%3],'nis'=>'008880'.str_pad((string)$i,4,'0',STR_PAD_LEFT),'nama'=>($i===1?'Nama Siswa Panjang Dengan Beberapa Kata Untuk Memeriksa Pembungkusan':'Fixture').' '.$i,'kelas'=>$i%2?'4A':'4B','tagihan'=>$i===1?123456789012.25:100,'bayar'=>$i===2?-100.25:25,'sisa'=>$i===3?null:75];
    foreach($months as $m)$row[$m[0]]=['text'=>$i===3?'Belum diketahui':'Cicilan','sub'=>'Rp 25'];
    foreach($financial as $c)if($row[$c[0]]!==null)$sub[$c[0]]=['value'=>($sub[$c[0]]['value']??0)+$row[$c[0]]];$rows[]=$row;
}
$matrix=['columns'=>$columns,'identity_count'=>5,'groups'=>[['label'=>'SPP Juli 2026–Juni 2027','subtitle'=>'SD, SMP, SMA','columns'=>array_merge($months,$financial)]],'rows'=>$rows,'subtotals'=>$sub];$before=serialize($matrix);$matrix['_clean_layout']=global_report_print_layout($matrix);
$prefix='<!doctype html><html><meta charset="utf-8"><style>@page{margin:10mm 10mm 14mm}body{margin:0}'.global_report_clean_styles(true).'</style><body class="global-clean-report">';$blocks=array_chunk($rows,80);$docs=[];
foreach($blocks as $i=>$block)$docs[]=$prefix.global_report_clean_table($matrix,$block,$i*80,$i===count($blocks)-1).'</body></html>';
$bytes=global_report_render_pdf_fragments($docs,$matrix['_clean_layout']['orientation']);unset($matrix['_clean_layout']);if(serialize($matrix)!==$before)throw new RuntimeException('Print changed the source matrix.');
file_put_contents($dir.'/fixture-global-fragmented.pdf',$bytes);file_put_contents($dir.'/fixture-global-expected.json',json_encode(['rows'=>$rows,'subtotals'=>$sub,'layout'=>global_report_print_layout($matrix)],JSON_THROW_ON_ERROR));
echo 'PASS 270 rows, 20 columns, four bounded fragments; source matrix unchanged.'.PHP_EOL;
