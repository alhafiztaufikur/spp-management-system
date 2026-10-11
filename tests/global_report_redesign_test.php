<?php
/** Read-only reconciliation; baseline and HTTP artifacts stay outside the repository. */
if (PHP_SAPI!=='cli' || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))) exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/excel.php';
require_once __DIR__.'/excel_test_helpers.php';
function redesign_assert(bool $ok,string $why):void { if(!$ok)throw new RuntimeException($why); }
$dir=(string)getenv('SPP_QA_DIR');redesign_assert(is_dir($dir),'Existing artifact directory required');
$_SESSION=['admin_role'=>'super_admin','admin_nama'=>'Pengujian','active_unit_id'=>1];
$cases=[];$models=[];
foreach([1,2,3,0] as $unit){
    unit_set_context($koneksi,$unit);
    foreach(global_report_templates() as $template){
        $cases[$unit.'/'.$template]=['template'=>$template,'unit'=>global_report_unit_value($unit),'tanggal_awal'=>'2026-09-01','tanggal_akhir'=>'2026-10-10','tahun_ajaran'=>'2026/2027','bulan_awal'=>'10','tahun_awal'=>'2026','tahun_akhir'=>'2026'];
        if($template==='penerimaan')$cases[$unit.'/'.$template]['kategori']='semua';
    }
    foreach(['status','per-item','penerimaan'] as $template){
        $cases[$unit.'/'.$template.'-multi']=$cases[$unit.'/'.$template];
        $cases[$unit.'/'.$template.'-multi']['kategori']=['spp','komite','psb'];
    }
    $cases[$unit.'/status-periods']=$cases[$unit.'/status'];
    $cases[$unit.'/status-periods']['kategori']=['spp','komite'];
    $cases[$unit.'/status-periods']['bulan_awal']=['09','10'];
    $years=array_column(report_years($koneksi),'label');
    $cases[$unit.'/status-periods']['tahun_ajaran']=array_slice($years,0,2);
    $cases[$unit.'/status-periods']['kelas']=['tingkat:'.($unit===2?'7':($unit===3?'10':'1'))];
    $cases[$unit.'/status-empty']=$cases[$unit.'/status'];$cases[$unit.'/status-empty']['q']='NO_MATCH_GLOBAL_REDESIGN';
}
foreach($cases as $key=>$params){
    unit_set_context($koneksi,$params['unit']==='all'?0:(int)$params['unit']);
    $filters=report_filters($koneksi,$params);$report=report_build($koneksi,$params['template'],$filters);
    $doc=spp_excel_report_document($report,$params['template'],report_query_unit_id());unset($doc['generated']);
    $models[$key]=['report'=>$report,'totals'=>report_money_totals($report,$params['template']),'doc'=>$doc];
}
$snapshot=['database'=>DB_NAME,'cases'=>$cases,'models'=>$models];
if(getenv('SPP_GLOBAL_CAPTURE_BASELINE')==='1'){
    file_put_contents($dir.'/baseline.json',json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
    echo 'OK baseline: '.count($models).' report/filter/scoped models'.PHP_EOL;exit;
}
$baseline=json_decode(file_get_contents($dir.'/baseline.json'),true,512,JSON_THROW_ON_ERROR);
redesign_assert($snapshot===$baseline,'Report rows, values, totals, columns or Excel models changed');
if(in_array('--models-only',$argv,true)){
    echo 'OK: '.count($models).' report models unchanged from the captured baseline'.PHP_EOL;exit;
}
// The shared writer and preview retain their existing presentation without opt-in.
$legacy=array_values($models)[0]['doc'];$legacy['generated']='10/10/2026 00:00:00 WIB';
$legacyBook=spp_excel_workbook($legacy);
redesign_assert($legacyBook->getActiveSheet()->getStyle('A10')->getFill()->getStartColor()->getRGB()===spp_excel_palette($legacy['unit'])[0],'Unrelated module header styling changed');
redesign_assert(!str_contains(spp_excel_preview_html($legacy),'excel-badge-nis'),'Unrelated module preview changed');
$legacyBook->disconnectWorksheets();
foreach($models as $key=>$model){
    $doc=$model['doc'];$doc['generated']='10/10/2026 00:00:00 WIB';$doc['presentation']='global';
    $book=spp_excel_workbook($doc);$file=$dir.'/'.str_replace('/','-',$key).'.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);$book->disconnectWorksheets();
    $read=test_excel_read(file_get_contents($file));$sheet=$read->getActiveSheet();$cursor=9;
    $n=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    foreach($doc['sheets'][0]['sections'] as $section){
        redesign_assert($sheet->getCellByColumnAndRow(1,$cursor)->getValue()===$section['title'],$key.' section title');$cursor++;
        $count=count($section['columns']);
        foreach($section['columns'] as $i=>$column)redesign_assert($sheet->getCellByColumnAndRow(1+(int)floor($i*$n/$count),$cursor)->getValue()===$column['label'],$key.' header');
        $cursor++;
        foreach($section['rows'] as $row){
            foreach($section['columns'] as $i=>$column){
                $cell=$sheet->getCellByColumnAndRow(1+(int)floor($i*$n/$count),$cursor);$value=$row[$column['key']]??null;
                if(!array_key_exists($column['key'],$row))$value='';
                if(in_array($column['type'],['money','money_optional','number','count'],true)&&$value!==null&&$value!=='')redesign_assert($cell->getDataType()==='n'&&abs((float)$cell->getValue()-(float)$value)<.001,$key.' numeric cell');
                elseif(in_array($column['type'],['date','datetime'],true)&&$value)redesign_assert(abs((float)$cell->getValue()-\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTimeImmutable($value,new DateTimeZone('Asia/Jakarta'))))<.00001,$key.' date cell');
                else redesign_assert((string)$cell->getValue()===($value===null?'—':spp_excel_text($value)),$key.' text cell');
            }$cursor++;
        }
        if(!$section['rows'])$cursor++;
        foreach($section['totals'] as $total){redesign_assert(abs((float)$sheet->getCellByColumnAndRow($n,$cursor)->getValue()-(float)$total['value'])<.001,$key.' subtotal');$cursor++;}
        $cursor+=2;
    }
    $read->disconnectWorksheets();
}
echo 'OK: '.count($models).' unchanged report models and XLSX files; every section, column, row, typed value and subtotal reconciled'.PHP_EOL;
