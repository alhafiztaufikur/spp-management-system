<?php
/** Read HTTP outputs back; only a loopback audit clone and private artifacts are allowed. */
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/excel.php';
require_once __DIR__.'/../includes/global_report_excel.php';
require_once __DIR__.'/../includes/global_report_matrix.php';
require_once __DIR__.'/excel_test_helpers.php';
require_once __DIR__.'/http_form_scope.php';
$base=(string)getenv('SPP_HTTP_BASE');spp_test_assert_http_clone($base,DB_NAME);
$dir=(string)getenv('SPP_QA_DIR');$sessions=json_decode(file_get_contents($dir.'/sessions.json'),true,512,JSON_THROW_ON_ERROR);
function export_redesign_assert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function export_redesign_request(array $params):string{
    global $base,$sessions;
    $body=file_get_contents($base.'/laporan/export_global.php?'.http_build_query($params),false,stream_context_create(['http'=>['header'=>'Cookie: PHPSESSID='.$sessions['super_admin'],'ignore_errors'=>true,'timeout'=>120]]));
    export_redesign_assert(str_contains($http_response_header[0]??'',' 200 '),'Export failed: '.json_encode($params));return (string)$body;
}
$_SESSION=['admin_role'=>'super_admin','admin_nama'=>'Pengujian','active_unit_id'=>1];
if(in_array('--full-only',$argv,true)){
    $manifest=json_decode(file_get_contents($dir.'/pdf-manifest.json'),true,512,JSON_THROW_ON_ERROR);$baseline=json_decode(file_get_contents($dir.'/baseline.json'),true,512,JSON_THROW_ON_ERROR);
    foreach(['1/status-multi','2/status-multi','3/status-multi','0/status-multi','0/spp-tahunan'] as $key){
        $params=$baseline['cases'][$key];if(getenv('SPP_EXPORT_TEMPLATES')&&!in_array($params['template'],explode(',',getenv('SPP_EXPORT_TEMPLATES')),true))continue;$unit=(int)explode('/',$key)[0];unit_set_context($koneksi,$unit);
        $filters=report_filters($koneksi,$params);$report=report_build($koneksi,$params['template'],$filters);$matrix=global_report_matrix($report,$params['template'],$unit);$doc=global_report_matrix_excel($matrix,$report,$params['template'],$unit);
        $path=$dir.'/whole-'.str_replace('/','-',$key).'.pdf';$pdf=export_redesign_request($params+['format'=>'pdf','download'=>'1']);export_redesign_assert(str_starts_with($pdf,'%PDF-'),'Whole PDF signature');file_put_contents($path,$pdf);
        $entry=['path'=>$path,'report'=>$report,'doc'=>$doc,'matrix'=>$matrix,'orientation'=>global_report_print_orientation($matrix),'paper'=>'A4'];$existing=array_search($path,array_column($manifest,'path'),true);if($existing===false)$manifest[]=$entry;else $manifest[$existing]=$entry;
        echo 'PASS: full matrix PDF '.$key.PHP_EOL;
    }
    file_put_contents($dir.'/pdf-manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));exit;
}
$manifest=[];$xlsxCount=0;
foreach([1,2,3,0] as $unit){
    unit_set_context($koneksi,$unit);
    $student=(int)$koneksi->query('SELECT s.id FROM siswa s JOIN bayar b ON b.NO_INDUK=s.NO_INDUK AND b.unit_id=s.unit_id ORDER BY s.id LIMIT 1')->fetch_row()[0];
    $cases=[];foreach(global_report_templates() as $template)$cases[$template]=['template'=>$template,'unit'=>global_report_unit_value($unit),'student_id'=>$student,'tanggal_awal'=>'2000-01-01','tanggal_akhir'=>'2035-12-31','tahun_ajaran'=>'2026/2027','bulan_awal'=>'10','tahun_awal'=>'2026','tahun_akhir'=>'2026','siswa_status'=>'all','kategori'=>$template==='penerimaan'?'semua':'spp'];
    foreach(['status','per-item','penerimaan'] as $template){$cases[$template.'-multi']=$cases[$template];$cases[$template.'-multi']['kategori']=['spp','komite','psb'];}
    $cases['spp-years']=$cases['spp-tahunan'];$cases['spp-years']['tahun_ajaran']=array_slice(array_column(report_years($koneksi),'label'),0,2);
    $cases['status-periods']=$cases['status-multi'];$cases['status-periods']['bulan_awal']=['09','10'];
    foreach($cases as $name=>$params){
        if(getenv('SPP_EXPORT_TEMPLATES')&&!in_array($params['template'],explode(',',getenv('SPP_EXPORT_TEMPLATES')),true))continue;
        $filters=report_filters($koneksi,$params);$report=report_build($koneksi,$params['template'],$filters);$matrix=global_report_matrix($report,$params['template'],$unit);$doc=$matrix?global_report_matrix_excel($matrix,$report,$params['template'],$unit):spp_excel_report_document($report,$params['template'],$unit);
        if(in_array('--pdf-refresh',$argv,true)){$file=$dir.'/endpoint-'.$unit.'-'.$name;$pdf=export_redesign_request($params+['format'=>'pdf','download'=>'1']);export_redesign_assert(str_starts_with($pdf,'%PDF-'),'PDF signature');file_put_contents($file.'.pdf',$pdf);$manifest[]=['path'=>$file.'.pdf','report'=>$report,'doc'=>$doc,'matrix'=>$matrix,'orientation'=>$matrix?global_report_print_orientation($matrix):($params['template']==='tabungan-siswa'||$params['template']==='riwayat-tagihan'?'landscape':'portrait'),'paper'=>'A4'];continue;}
        $binary=export_redesign_request($params+['format'=>'excel','download'=>'1']);export_redesign_assert(str_starts_with($binary,"PK\x03\x04"),'XLSX signature');
        $file=$dir.'/endpoint-'.$unit.'-'.$name;file_put_contents($file.'.xlsx',$binary);
        $book=test_excel_read($binary);$sheet=$book->getActiveSheet();
        export_redesign_assert(in_array($doc['school'],[$sheet->getCell('B1')->getValue(),$sheet->getCell('C1')->getValue()],true),'School scope');
        $n=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());$identityLimit=$n;foreach($doc['sheets'][0]['sections'] as $candidate)if($candidate['detail']){$identityLimit=global_report_excel_identity_count($candidate);break;}$totalValueColumn=max(2,min($n,max(3,min(6,$identityLimit)))-1);$cursor=9;
        foreach($doc['sheets'][0]['sections'] as $section){
            export_redesign_assert($sheet->getCellByColumnAndRow(1,$cursor)->getValue()===$section['title'],'Section ordering');$cursor++;
            $count=count($section['columns']);if(!empty($section['header_groups'])){foreach($section['header_groups'] as $group){$keys=array_column($section['columns'],'key');$position=array_search($group['keys'][0],$keys,true)+1;export_redesign_assert($sheet->getCellByColumnAndRow($position,$cursor)->getValue()===$group['label'],'Horizontal group header');}$cursor++;}
            foreach($section['columns'] as $i=>$column)export_redesign_assert($sheet->getCellByColumnAndRow(1+(int)floor($i*$n/$count),$cursor)->getValue()===$column['label'],'Export columns');
            export_redesign_assert($sheet->getStyle('A'.$cursor)->getFill()->getStartColor()->getRGB()===[1=>'165C44',2=>'203E80',3=>'832A35',0=>'513080'][$unit],'Header palette');$cursor++;
            foreach($section['rows'] as $row){
                foreach($section['columns'] as $i=>$column){
                    $value=array_key_exists($column['key'],$row)?$row[$column['key']]:'';
                    $cell=$sheet->getCellByColumnAndRow(1+(int)floor($i*$n/$count),$cursor);
                    if(in_array($column['key'],['no','nis','nis_diknas','export_diknas','NO_INDUK','NO_induk_diknas'],true))export_redesign_assert($sheet->getStyle($cell->getCoordinate())->getAlignment()->getHorizontal()==='center','Centered No/NIS identity');
                    if(in_array($column['type'],['money','money_optional','number','count'],true)&&$value!==null&&$value!=='')export_redesign_assert($cell->getDataType()==='n'&&abs((float)$cell->getValue()-(float)$value)<.001,'Export numeric value');
                    elseif(in_array($column['type'],['date','datetime'],true)&&$value)export_redesign_assert(abs((float)$cell->getValue()-\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTimeImmutable($value,new DateTimeZone('Asia/Jakarta'))))<.00001,'Export date');
                    else export_redesign_assert((string)$cell->getValue()===($value===null?'—':spp_excel_text($value)),'Export text value');
                }$cursor++;
            }
            if(!$section['rows'])$cursor++;
            if(!empty($section['column_totals'])){foreach($section['columns'] as $i=>$column)if(isset($section['column_totals'][$column['key']]))export_redesign_assert((float)$sheet->getCellByColumnAndRow($i+1,$cursor)->getValue()===(float)$section['column_totals'][$column['key']]['value'],'Horizontal subtotals');$cursor++;}
            foreach($section['totals'] as $total){export_redesign_assert(abs((float)$sheet->getCellByColumnAndRow($totalValueColumn,$cursor)->getValue()-(float)$total['value'])<.001,'Export subtotal');$cursor++;}$cursor+=2;
        }$book->disconnectWorksheets();$xlsxCount++;if(in_array('--xlsx-only',$argv,true))continue;
        $pdf=export_redesign_request($params+['format'=>'pdf','download'=>'1']);export_redesign_assert(str_starts_with($pdf,'%PDF-'),'PDF signature');file_put_contents($file.'.pdf',$pdf);
        $manifest[]=['path'=>$file.'.pdf','report'=>$report,'doc'=>$doc,'matrix'=>$matrix,'orientation'=>$matrix?global_report_print_orientation($matrix):($params['template']==='tabungan-siswa'||$params['template']==='riwayat-tagihan'?'landscape':'portrait'),'paper'=>'A4'];
        foreach(['preview','print','excel'] as $format){if($format==='print'){$pdfPrint=export_redesign_request($params+['format'=>'print']);export_redesign_assert(str_starts_with($pdfPrint,'%PDF-'),'Print must open the actual PDF');continue;}$html=export_redesign_request($params+['format'=>$format]);export_redesign_assert(!preg_match('/Fatal error|Warning:/',$html),'Preview error');export_redesign_assert(str_contains($html,'data-preview-frame'),'Preview missing');export_redesign_assert(!str_contains($html,'class="preview-help"'),'Redundant preview help');export_redesign_assert(!str_contains(html_entity_decode($html,ENT_QUOTES,'UTF-8'),'<section class="matrix-print-band'),'Interactive previews must not render hidden print bands');}
    }
    echo 'PASS: scope '.$unit.', nine templates, multiple categories/years/months'.PHP_EOL;
}
if(in_array('--xlsx-only',$argv,true)){echo 'PASS: '.$xlsxCount.' HTTP XLSX readbacks, values/types, headers, metadata and compact totals'.PHP_EOL;exit;}
if(getenv('SPP_EXPORT_TEMPLATES')){$old=json_decode(file_get_contents($dir.'/pdf-manifest.json'),true,512,JSON_THROW_ON_ERROR);$map=array_column($manifest,null,'path');foreach($old as &$item)if(isset($map[$item['path']]))$item=$map[$item['path']];unset($item);$manifest=$old;}
file_put_contents($dir.'/pdf-manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
echo 'PASS: '.$xlsxCount.' HTTP XLSX readbacks, '.count($manifest).' PDF documents and '.($xlsxCount*3).' preview/print responses'.PHP_EOL;
