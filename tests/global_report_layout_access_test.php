<?php
/** Binary export permissions and inline print semantics, on a disposable database only. */
if(PHP_SAPI!=='cli'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/http_form_scope.php';
require_once __DIR__.'/excel_test_helpers.php';
$base=(string)getenv('SPP_HTTP_BASE');spp_test_assert_http_clone($base,DB_NAME);
$sessions=json_decode(file_get_contents(getenv('SPP_QA_DIR').'/sessions.json'),true,512,JSON_THROW_ON_ERROR);
$count=0;
foreach($sessions as $role=>$session)foreach(['1','2','3','all'] as $unit)foreach(['print','pdf','excel'] as $format){
    $params=['template'=>'status','unit'=>$unit,'kategori'=>['spp','komite'],'tahun_ajaran'=>'2026/2027','bulan_awal'=>'10','q'=>'NO_MATCH_LAYOUT_ACCESS','format'=>$format,'download'=>'1'];
    $body=file_get_contents($base.'/laporan/export_global.php?'.http_build_query($params),false,stream_context_create(['http'=>['header'=>'Cookie: PHPSESSID='.$session,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>120]]));
    if(!str_contains($http_response_header[0]??'',' 200 '))throw new RuntimeException('Export access '.$role.'/'.$unit.'/'.$format);
    if($format==='excel'){
        $book=test_excel_read($body);$sheet=$book->getActiveSheet();
        if($sheet->getPageSetup()->getPaperSize()!==9)throw new RuntimeException('Excel must use A4');
        if($sheet->getCell('C1')->getValue()!==unit_school_name($unit==='all'?0:(int)$unit))throw new RuntimeException('Excel unit identity');
        $book->disconnectWorksheets();
    }else{
        if(!str_starts_with($body,'%PDF-'))throw new RuntimeException('Print/download must return PDF');
        $headers=implode("\n",$http_response_header);
        if(!preg_match('/Content-Disposition: '.($format==='print'?'inline':'attachment').'/i',$headers))throw new RuntimeException('Wrong print/download disposition');
    }
    $count++;
}
echo 'PASS: '.$count.' binary exports, four roles/scopes, inline PDF print, attachment download, A4 Excel and school identity'.PHP_EOL;
