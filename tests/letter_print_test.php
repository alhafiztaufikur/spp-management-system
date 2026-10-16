<?php
/** Fictional letters: no database reads or writes. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../vendor/autoload.php';require_once __DIR__.'/../includes/date_format.php';require_once __DIR__.'/../includes/report_letters.php';require_once __DIR__.'/../includes/units.php';
$dir=getenv('SPP_BOOK_PDF_ARTIFACTS');
if(!$dir||!is_dir($dir)||str_starts_with(strtolower(str_replace('\\','/',$dir)),strtolower(str_replace('\\','/',realpath(__DIR__.'/..')))))throw new RuntimeException('Private artifact directory required.');
$GLOBALS['app_unit_id']=1;
$student=['unit_id'=>1,'nama'=>'Siswa Contoh Nusantara','nis'=>'0000123456','nis_diknas'=>'001234567890','kelas'=>'1A','total_tunggakan'=>300000,'items'=>[['komponen'=>'SPP','periode'=>'Oktober 2026','sisa'=>250000],['komponen'=>'Komite','periode'=>'Oktober 2026','sisa'=>50000]]];
$long=$student;$long['items']=array_fill(0,35,['komponen'=>'Biaya tambahan dengan keterangan panjang','periode'=>'Oktober 2026','sisa'=>123456789]);$long['total_tunggakan']=35*123456789;
$cases=['parent'=>report_parent_letters_html([$student],'2026-10-16'),'parent-long'=>report_parent_letters_html([$long],'2026-10-16')];
foreach([10,35] as $count){$rows=[];for($i=0;$i<$count;$i++)$rows[]=['kelas'=>'Rombel Contoh '.($i+1),'jumlah_siswa'=>5,'total_tunggakan'=>1234567];$cases['principal-'.$count]=report_principal_letter_html($rows,'2026-10-16');}
foreach($cases as $name=>$html){$options=new \Dompdf\Options();$options->setDefaultMediaType('print');$pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();file_put_contents($dir.'/letter-'.$name.'.pdf',$pdf->output());echo 'PASS letter '.$name.': '.$pdf->getCanvas()->get_page_count().' pages'.PHP_EOL;}
