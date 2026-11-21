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
$signed=[1=>['principal_name'=>'Hairul Laka','school_name'=>"SD Al-Qur'an (SDA) Mutiara Hikmah"]];
$cases['parent-signed']=report_parent_letters_html([$student],'2026-10-16',[],$signed);
$signed[1]['principal_name']=str_repeat('W',150);
$cases['parent-long-name']=report_parent_letters_html([$student],'2026-10-16',[],$signed);
$notice=$student;$notice['items']=[
    ['komponen'=>'SPP','komponen_key'=>'spp','periode'=>'November 2026','periode_code'=>'2026-11','sisa'=>150000.25],
    ['komponen'=>'Uang Komite','komponen_key'=>'komite','periode'=>'Oktober 2026','periode_code'=>'2026-10','sisa'=>25000],
    ['komponen'=>'SPP','komponen_key'=>'spp','periode'=>'Oktober 2026','periode_code'=>'2026-10','sisa'=>100000],
    ['komponen'=>'SPP','komponen_key'=>'spp','periode'=>'Desember 2025','periode_code'=>'2025-12','sisa'=>24999.75],
];
$signed[1]['finance_name']=str_repeat('F',150);
$manual=[unit_student_key($student)=>['format'=>'rich_text','html'=>'<p><strong>Pesan manual tetap tercetak.</strong></p>']];
$cases['parent-reminder-long-names']=report_parent_letters_html([$notice],'2026-11-21',$manual,$signed,3);
$standardSigners=[1=>array_replace($signed[1],['principal_name'=>'Kepala Sekolah Uji','finance_name'=>'Ervina Safitri, A.Md.'])];
$cases['parent-reminder-previous']=report_parent_letters_html([$notice],'2026-11-21',$manual,$standardSigners,2);
if(str_contains($cases['parent-reminder-previous'],'paling lambat tanggal 10'))throw new RuntimeException('Version 2 draft changed.');
$cases['parent-reminder']=report_parent_letters_html([$notice],'2026-11-21',$manual,$standardSigners,4);
if(!str_contains($cases['parent-reminder'],'.parent-reminder .kop h1{font-size:14pt}')||!str_contains($cases['parent-reminder'],'text-align:justify'))throw new RuntimeException('New letter presentation missing');
foreach(['SPP 2 bulan (Okt, Nov) 2026','SPP 1 bulan (Des) 2025','250.000,25','300.000','21 November 2026','Pesan manual tetap tercetak.',
    'paling lambat tanggal 10','surat informasi kewajiban ini dapat diabaikan','Kepala SD Al-Qur’an (SDA) Mutiara Hikmah','<strong>Ervina Safitri, A.Md.</strong>'] as $text){
    if(!str_contains($cases['parent-reminder'],$text))throw new RuntimeException('New letter missing '.$text);
}
$manyParagraphs=implode('',array_fill(0,32,'<p>Pesan pengingat dengan beberapa baris.</p>'));
$cases['parent-reminder-long']=report_parent_letters_html([$long,$notice],'2026-11-21',
    [unit_student_key($student)=>['format'=>'rich_text','html'=>$manyParagraphs]],$signed,3);
foreach([10,35] as $count){$rows=[];for($i=0;$i<$count;$i++)$rows[]=['kelas'=>'Rombel Contoh '.($i+1),'jumlah_siswa'=>5,'total_tunggakan'=>1234567];$cases['principal-'.$count]=report_principal_letter_html($rows,'2026-10-16');}
foreach($cases as $name=>$html){
    $options=new \Dompdf\Options();$options->setDefaultMediaType('print');$pdf=new \Dompdf\Dompdf($options);
    $pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();$pages=$pdf->getCanvas()->get_page_count();
    if($name==='parent-reminder'&&$pages!==1)throw new RuntimeException('Standard reminder should fit on one A4 page.');
    file_put_contents($dir.'/letter-'.$name.'.pdf',$pdf->output());echo 'PASS letter '.$name.': '.$pages.' pages'.PHP_EOL;
}
