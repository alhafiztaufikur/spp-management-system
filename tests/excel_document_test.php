<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/units.php';require_once __DIR__.'/../includes/date_format.php';require_once __DIR__.'/../includes/excel.php';
function excel_expect(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$out=(string)getenv('SPP_XLSX_TEST_OUTPUT');if(!$out||!is_dir($out))throw new RuntimeException('SPP_XLSX_TEST_OUTPUT must be an existing directory outside the repository.');
foreach([0,1,2,3] as $unit){
    $cols=[spp_excel_column('no','No','number'),spp_excel_column('nis','NIS'),spp_excel_column('nama','Nama Siswa'),spp_excel_column('money','Nominal','money'),spp_excel_column('date','Tanggal','datetime'),spp_excel_column('status','Status','html')];
    $rows=[];foreach(['=1+1','+SUM(A1:A2)','-danger','@user','<b>& nama panjang '.str_repeat('contoh ',12)] as $i=>$name)$rows[]=['no'=>$i+1,'nis'=>'0000123','nama'=>$name,'money'=>$i===0?-125000:($i===1?0:150000),'date'=>'2026-10-06 13:00:00','status'=>['text'=>'Cicilan','sub'=>'Rp 150.000']];
    $doc=spp_excel_document('Contoh XLSX','Uji struktur dan nilai',$unit,[['name'=>'Laporan','sections'=>[spp_excel_section('Rincian',$cols,$rows,[['label'=>'Total','value'=>325000]])],'signatures'=>true]]);
    $book=spp_excel_workbook($doc);$path=$out.'/typed-unit-'.$unit.'.xlsx';(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);$book->disconnectWorksheets();
    $read=\PhpOffice\PhpSpreadsheet\IOFactory::load($path);$s=$read->getActiveSheet();
    excel_expect($s->getCell('B11')->getValue()==='0000123','NIS must remain text');excel_expect($s->getCell('C11')->getDataType()==='s'&&$s->getCell('C11')->getValue()==='=1+1','Formula-like user text must stay literal');excel_expect($s->getCell('D11')->getValue()==-125000,'Money must be numeric');excel_expect($s->getCell('D12')->getValue()==0,'Zero must be numeric');excel_expect($s->getCell('E11')->getDataType()==='n','Date must be numeric');excel_expect($s->getCell('F11')->getValue()==="Cicilan\nRp 150.000",'Combined status must preserve both parts');excel_expect(count($s->getDrawingCollection())===1,'Logo must exist');excel_expect($s->getFreezePane()==='B11'&&$s->getAutoFilter()->getRange()==='A10:F15','Freeze and filter range');$zip=new ZipArchive();$zip->open($path);excel_expect(str_contains($zip->getFromName('xl/worksheets/sheet1.xml'),'showGridLines="false"'),'Gridlines must be hidden');$zip->close();excel_expect($s->getStyle('A10')->getFill()->getStartColor()->getRGB()===spp_excel_palette($unit)[0],'Unit accent');excel_expect(str_contains(spp_excel_preview_html($doc),'&lt;b&gt;&amp;'),'Preview text must be escaped');$read->disconnectWorksheets();
    echo "PASS: unit $unit typed values, formula protection, palette, logo, filter, freeze and preview\n";
}
