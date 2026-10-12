<?php
/** Populated savings reports supplement the per-student export fixtures. Read-only. */
if(PHP_SAPI!=='cli'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/excel.php';require_once __DIR__.'/excel_test_helpers.php';
$_SESSION=['admin_role'=>'super_admin','active_unit_id'=>1,'admin_nama'=>'Pengujian'];$rows=0;
foreach([1,2,3,0] as $unit){
    unit_set_context($koneksi,$unit);
    $filters=report_filters($koneksi,['tanggal_awal'=>'2000-01-01','tanggal_akhir'=>'2035-12-31','siswa_status'=>'all']);
    $report=report_build($koneksi,'tabungan-siswa',$filters);$doc=spp_excel_report_document($report,'tabungan-siswa',$unit);$doc['presentation']='global';
    $book=spp_excel_workbook($doc);$path=getenv('SPP_QA_DIR').'/endpoint-'.$unit.'-tabungan-siswa-populated.xlsx';(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);$book->disconnectWorksheets();
    $read=test_excel_read(file_get_contents($path));$sheet=$read->getActiveSheet();
    foreach($doc['sheets'][0]['sections'][0]['columns'] as $index=>$column){
        foreach($report['rows'] as $offset=>$row){
            $cell=$sheet->getCellByColumnAndRow($index+1,11+$offset);
            if(in_array($column['key'],['no','nis','nama'],true)&&$sheet->getStyle($cell->getCoordinate())->getAlignment()->getHorizontal()!=='center')throw new RuntimeException('Savings identity not centered');
            if($column['type']==='datetime'&&(!is_numeric($cell->getValue())||!str_contains($sheet->getStyle($cell->getCoordinate())->getNumberFormat()->getFormatCode(),"\n")))throw new RuntimeException('Savings timestamp not typed/two-line');
        }
    }
    $rows+=count($report['rows']);$read->disconnectWorksheets();
}
if(!$rows)throw new RuntimeException('Populated savings fixtures missing');
echo "PASS: four populated savings XLSX readbacks, $rows rows, centered identities and numeric two-line timestamps\n";
