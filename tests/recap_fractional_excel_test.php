<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/units.php';require_once __DIR__.'/../includes/excel.php';
require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/global_report_matrix.php';
$out=(string)getenv('SPP_XLSX_TEST_OUTPUT');if(!$out||!is_dir($out)||str_starts_with(realpath($out),realpath(__DIR__.'/..')))throw new RuntimeException('Private output outside the repository required.');
$columns=[spp_excel_column('no','No','number'),spp_excel_column('name','Nama'),spp_excel_column('amount','Nominal','money')];
$doc=spp_excel_document('Presisi Pecahan','Pemeriksaan baca kembali',1,[['name'=>'Pecahan','sections'=>[spp_excel_section('Nominal',$columns,[['no'=>1,'name'=>'Pecahan sen','amount'=>100000.13]],[['label'=>'Total','value'=>100000.13]])]]]);
if(substr_count(spp_excel_preview_html($doc),'100.000,13')!==2)throw new RuntimeException('Fractional preview and subtotal differ.');
$book=spp_excel_workbook($doc);$file=$out.'/money-precision.xlsx';(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);$book->disconnectWorksheets();$read=\PhpOffice\PhpSpreadsheet\IOFactory::load($file);$cell=$read->getActiveSheet()->getCell('C11');
if($cell->getDataType()!=='n'||(int)round($cell->getValue()*100)!==10000013||!str_contains($cell->getStyle()->getNumberFormat()->getFormatCode(),'#,##0.00'))throw new RuntimeException('Fractional money lost in the real workbook.');
if((int)round($read->getActiveSheet()->getCell('C12')->getValue()*100)!==10000013||!str_contains($read->getActiveSheet()->getStyle('C12')->getNumberFormat()->getFormatCode(),'#,##0.00'))throw new RuntimeException('Fractional subtotal lost.');
$read->disconnectWorksheets();
$doc['presentation']='global';if(substr_count(spp_excel_preview_html($doc),'100.000,13')!==2)throw new RuntimeException('Fractional preview and subtotal differ.');
$book=spp_excel_workbook($doc);$file=$out.'/global-money-precision.xlsx';(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);$book->disconnectWorksheets();$read=\PhpOffice\PhpSpreadsheet\IOFactory::load($file);foreach(['C11','B12'] as $address)if((int)round($read->getActiveSheet()->getCell($address)->getValue()*100)!==10000013||!str_contains($read->getActiveSheet()->getStyle($address)->getNumberFormat()->getFormatCode(),'#,##0.00'))throw new RuntimeException('Global fractional value/format lost.');$read->disconnectWorksheets();echo "PASS fractional XLSX numeric cents and visible two-decimal format\n";
