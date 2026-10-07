<?php
/** Read back exports using the exact identity and period used by the redesigned page. */
if(PHP_SAPI!=='cli'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/excel_test_helpers.php';
$base=rtrim((string)getenv('SPP_HTTP_BASE'),'/');if(!preg_match('#^http://127\.0\.0\.1:\d+$#',$base))exit(1);
$dir=(string)getenv('SPP_QA_DIR');$fixture=json_decode(file_get_contents($dir.'/fixture.json'),true);
$identity=json_decode(file_get_contents($base.'/tests/browser_clone_identity.php'),true);if(($identity['database']??'')!==DB_NAME)throw new RuntimeException('Wrong HTTP database.');
function fw_get(string $path,array $cookies):string{
    global $base;$body=file_get_contents($base.'/'.$path,false,stream_context_create(['http'=>['header'=>'Cookie: '.http_build_query($cookies,'','; '),'ignore_errors'=>true,'timeout'=>60]]));
    if(!str_contains($http_response_header[0]??'',' 200 '))throw new RuntimeException('Export failed: '.($http_response_header[0]??''));return $body;
}
foreach([1,2,3,0] as $unit){
    unit_set_context($koneksi,$unit);$identity=$fixture['units'][$unit]['rows'][0];$id=(int)$identity['student_id'];
    $stmt=$koneksi->prepare("SELECT COUNT(*) n,COALESCE(SUM(b.total_jumlah),0) total FROM bayar b JOIN siswa s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id WHERE s.id=? AND b.TGL_BYR>='2026-10-01' AND b.TGL_BYR<'2026-10-08'");$stmt->bind_param('i',$id);$stmt->execute();$expected=$stmt->get_result()->fetch_assoc();$stmt->close();
    $query=http_build_query(['tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-07','student_id'=>$id,'unit'=>$unit===0?'all':'active']);
    $body=fw_get('laporan/export_excel.php?'.$query.'&download=1',$fixture['units'][$unit]['cookies']);$book=test_excel_read($body);
    try{
        if($book->getSheetNames()!==['Ringkasan','Pembayaran','Tabungan'])throw new RuntimeException('Workbook sheets changed.');
        $sheet=$book->getSheetByName('Pembayaran');$table=false;$count=0;$total=0;
        foreach($sheet->toArray(null,false,false,false) as $row){
            if(in_array('NIS',$row,true)&&in_array('Total Bayar',$row,true)){$table=true;$nisCol=array_search('NIS',$row,true);$totalCol=array_search('Total Bayar',$row,true);continue;}
            if($table&&is_numeric($row[0]??null)&&!empty($row[$nisCol])){if((string)$row[$nisCol]!==$identity['NO_INDUK'])throw new RuntimeException('Another student leaked into export.');$count++;$total+=(float)$row[$totalCol];}
        }
        if($count!==(int)$expected['n']||abs($total-(float)$expected['total'])>.001)throw new RuntimeException('Export identities/total mismatch.');
    }finally{$book->disconnectWorksheets();}
    $pdf=fw_get('laporan/export_pdf.php?'.$query.'&jenis_laporan[]=sudah_bayar&output=pdf',$fixture['units'][$unit]['cookies']);
    if(!str_starts_with($pdf,'%PDF'))throw new RuntimeException('PDF signature absent.');file_put_contents($dir.'/finance-'.$unit.'.pdf',$pdf);
    echo 'OK export unit '.$unit.': XLSX rows/identity/total and original PDF. '.PHP_EOL;
}
