<?php
/** Read-only reconciliation of downloaded workbooks against the same scoped report source. */
if(PHP_SAPI!=='cli'||!preg_match('/^db_spp_audit_/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/excel.php';
require_once __DIR__.'/excel_test_helpers.php';
function readback_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$directory=(string)getenv('SPP_XLSX_TEST_OUTPUT');
$_SESSION=['admin_role'=>'super_admin','admin_nama'=>'Pengujian'];
foreach([1,2,3,0] as $unit){
    $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);
    foreach(array_keys(report_registry()) as $template){
        $params=['template'=>$template,'tanggal_awal'=>'2026-09-01','tanggal_akhir'=>'2026-10-06'];
        if($template==='riwayat-tagihan')$params['siswa_status']='all';
        if($template==='penerimaan')$params['kategori']='semua';
        $report=report_build($koneksi,$template,report_filters($koneksi,$params));
        $doc=spp_excel_report_document($report,$template,$unit);
        $book=test_excel_read(file_get_contents($directory.'/'.$unit.'-'.$template.'.xlsx'));
        $sheet=$book->getActiveSheet();
        readback_assert($sheet->getCell('B1')->getValue()===$doc['school'],"$unit/$template school");
        readback_assert(count($sheet->getDrawingCollection())===1,"$unit/$template logo");
        $cursor=9;$n=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        foreach($doc['sheets'][0]['sections'] as $section){
            readback_assert($sheet->getCellByColumnAndRow(1,$cursor)->getValue()===$section['title'],"$template section");$cursor++;
            $count=count($section['columns']);
            foreach($section['columns'] as $i=>$column)readback_assert($sheet->getCellByColumnAndRow(1+(int)floor($i*$n/$count),$cursor)->getValue()===$column['label'],"$template header {$column['label']}");
            $cursor++;
            foreach($section['rows'] as $row){
                foreach($section['columns'] as $i=>$column){
                    $cell=$sheet->getCellByColumnAndRow(1+(int)floor($i*$n/$count),$cursor);
                    $expected=array_key_exists($column['key'],$row)?$row[$column['key']]:'';
                    if(in_array($column['type'],['money','money_optional','number','count'],true)&&$expected!==null&&$expected!==''){
                        readback_assert($cell->getDataType()==='n'&&abs((float)$cell->getValue()-(float)$expected)<.001,"$unit/$template numeric row $cursor {$column['key']}");
                    }elseif(in_array($column['type'],['date','datetime'],true)&&$expected){
                        $serial=\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTimeImmutable($expected,new DateTimeZone('Asia/Jakarta')));
                        readback_assert($cell->getDataType()==='n'&&abs((float)$cell->getValue()-$serial)<.00001,"$unit/$template date");
                    }else{
                        readback_assert((string)$cell->getValue()===($expected===null?'—':spp_excel_text($expected)),"$unit/$template text row $cursor {$column['key']}");
                    }
                }$cursor++;
            }
            if(!$section['rows']){readback_assert(str_contains((string)$sheet->getCellByColumnAndRow(1,$cursor)->getValue(),'Tidak ada data'),"$template empty state");$cursor++;}
            foreach($section['totals'] as $total){readback_assert(abs((float)$sheet->getCellByColumnAndRow($n,$cursor)->getValue()-(float)$total['value'])<.001,"$unit/$template total {$total['label']}");$cursor++;}
            $cursor+=2;
        }
        $book->disconnectWorksheets();echo "PASS: $unit/$template headers, all rows, typed values and totals match source\n";
    }
    $book=test_excel_read(file_get_contents($directory.'/'.$unit.'-umum.xlsx'));
    readback_assert($book->getSheetNames()===['Ringkasan','Pembayaran','Tabungan'],"$unit general sheet names");
    $scope=unit_student_selection_where();
    $source=$koneksi->query("SELECT COUNT(*) count,COALESCE(SUM(b.total_jumlah),0) total FROM bayar b JOIN siswa s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id WHERE b.TGL_BYR>='2026-09-01 00:00:00' AND b.TGL_BYR<'2026-10-07 00:00:00' $scope")->fetch_assoc();
    $sheet=$book->getSheetByName('Pembayaran');$last=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());$actualRows=0;$actualSum=0;
    for($r=11;$r<=$sheet->getHighestDataRow();$r++)if($sheet->getCellByColumnAndRow(1,$r)->getDataType()==='n'){$actualRows++;$actualSum+=(float)$sheet->getCellByColumnAndRow($last-1,$r)->getValue();}
    readback_assert($actualRows===(int)$source['count']&&abs($actualSum-(float)$source['total'])<.001,"$unit general payment rows/total");
    $book->disconnectWorksheets();echo "PASS: $unit general sheets, payment count and source total\n";
}
