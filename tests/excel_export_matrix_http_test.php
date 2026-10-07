<?php
/** Read-only HTTP matrix: every available category, empty states, and debt detail by unit. */
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_/D',(string)getenv('SPP_DB_NAME')))exit(1);
ob_start();
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/excel.php';require_once __DIR__.'/excel_test_helpers.php';require_once __DIR__.'/http_form_scope.php';
$base=rtrim((string)getenv('SPP_HTTP_BASE'),'/');spp_test_assert_http_clone($base,DB_NAME);
$out=(string)getenv('SPP_XLSX_TEST_OUTPUT');$sid='xlsxmatrix'.bin2hex(random_bytes(10));
$admin=$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
function xmatrix_get(string $url,string $sid):string{
    $data=file_get_contents($url,false,stream_context_create(['http'=>['header'=>'Cookie: '.session_name().'='.$sid,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>60]]));
    if(!str_contains($http_response_header[0]??'','200'))throw new RuntimeException('HTTP failed: '.$url);
    if(!str_contains(strtolower(implode(' ',$http_response_header)),'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))throw new RuntimeException('Incorrect MIME');
    return (string)$data;
}
function xmatrix_check(string $data,array $report,string $template):void{
    $book=test_excel_read($data);$sheet=$book->getActiveSheet();
    if(count($sheet->getDrawingCollection())!==1)throw new RuntimeException('Missing logo');
    $values=[];foreach($sheet->getCellCollection()->getCoordinates() as $coord)$values[]=$sheet->getCell($coord)->getValue();
    foreach(report_money_totals($report,$template) as $total){
        if($template==='riwayat-tagihan'&&$total['key']!=='tagihan')continue;
        if(!in_array($total['label'],$values,true))throw new RuntimeException('Missing total label: '.$total['label']);
        $found=false;foreach($values as $value)if(is_numeric($value)&&abs((float)$value-(float)$total['value'])<.001)$found=true;
        if(!$found)throw new RuntimeException('Missing typed total');
    }
    if(!in_array($template,['setoran','kas-tabungan'],true)&&($sheet->getFreezePane()!== 'B11'||$sheet->getAutoFilter()->getRange()===''))throw new RuntimeException('Missing filter/freeze');
    $book->disconnectWorksheets();
}
try{foreach(array_map('intval',explode(',',getenv('SPP_XLSX_UNITS')?:'1,2,3,0')) as $unit){
    session_id($sid);session_start();$_SESSION=['admin_id'=>(int)$admin['id'],'admin_role'=>'super_admin','active_unit_id'=>$unit];session_write_close();unit_set_context($koneksi,$unit);
    $baseParams=['tanggal_awal'=>'2026-09-01','tanggal_akhir'=>'2026-10-06','tahun_ajaran'=>'2026/2027','siswa_status'=>'all'];$cases=0;
    foreach(['status','per-item','penerimaan'] as $template){
        foreach(report_categories($koneksi) as $category=>$label){
            $params=['template'=>$template,'kategori'=>$category]+$baseParams;
            $report=report_build($koneksi,$template,report_filters($koneksi,$params));
            $data=xmatrix_get($base.'/laporan/export_global.php?'.http_build_query($params+['format'=>'excel','download'=>1]),$sid);xmatrix_check($data,$report,$template);$cases++;
        }
    }
    foreach(array_keys(report_registry()) as $template){
        $params=['template'=>$template,'tanggal_awal'=>'1900-01-01','tanggal_akhir'=>'1900-01-01','tahun_ajaran'=>'1900/1901','q'=>'QA_NIS_TIDAK_ADA','kelas'=>'rombel:999999999','siswa_status'=>'all','kategori'=>$template==='penerimaan'?'semua':'spp'];
        $report=report_build($koneksi,$template,report_filters($koneksi,$params));$data=xmatrix_get($base.'/laporan/export_global.php?'.http_build_query($params+['format'=>'excel','download'=>1]),$sid);xmatrix_check($data,$report,$template);$cases++;
    }
    foreach(['siswa/export_excel.php?q=QA_NIS_TIDAK_ADA','laporan/export_excel.php?tanggal_awal=1900-01-01&tanggal_akhir=1900-01-01'] as $url){$data=xmatrix_get($base.'/'.$url.'&download=1',$sid);$book=test_excel_read($data);$book->disconnectWorksheets();$cases++;}
    $params=['template'=>'tunggakan-siswa']+$baseParams;$filters=report_filters($koneksi,$params);$report=report_build($koneksi,'tunggakan-siswa',$filters);
    $rombel=$report['rows'][0]['master_kelas_id']??0;if(!$rombel)throw new RuntimeException('Missing debt rombel fixture');
    $query=$params+['kelas'=>'rombel:'.$rombel,'view'=>'detail','format'=>'excel','download'=>1];
    $data=xmatrix_get($base.'/laporan/export_global.php?'.http_build_query($query),$sid);$book=test_excel_read($data);
    $source=report_student_debt_groups($koneksi,report_filters($koneksi,$query),'',[],(string)$report['as_of_date']);$sum=array_sum(array_column($source,'total_tunggakan'));$sheet=$book->getActiveSheet();
    if(abs((float)$sheet->getCell('F'.(11+count($source)))->getValue()-$sum)>.001)throw new RuntimeException('Debt detail total differs from source');
    if($out)file_put_contents($out.'/'.$unit.'-debt-detail.xlsx',$data);$book->disconnectWorksheets();$cases++;
    echo "PASS: unit $unit / $cases category, empty and debt-detail XLSX cases\n";
}}finally{session_id($sid);session_start();$_SESSION=[];session_destroy();session_write_close();}
