<?php
/** Read-only equivalence checks on an explicitly selected private audit server. */
if(PHP_SAPI!=='cli'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))||(int)getenv('SPP_DB_PORT')===3306)exit(1);
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1,'admin_nama'=>'Pengujian PSB'];
require_once __DIR__.'/../koneksi.php';
if(!$expected=getenv('SPP_MIGRATION_TEST_UUID'))throw new RuntimeException('Explicit audit UUID required.');
if($koneksi->query('SELECT @@server_uuid')->fetch_row()[0]!==$expected)throw new RuntimeException('Wrong audit server.');
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/global_report_matrix.php';
$dir=(string)getenv('SPP_PSB_ARTIFACTS');
if(!$dir||str_starts_with(strtolower(str_replace('\\','/',$dir)),strtolower(str_replace('\\','/',realpath(__DIR__.'/..')))))throw new RuntimeException('External artifacts directory required.');
if(!is_dir($dir))mkdir($dir,0700,true);
$checks=0;$models=[];
function psb_check(bool $value,string $message):void{global $checks;$checks++;if(!$value)throw new RuntimeException($message);}
function psb_positions(array $report):array{return array_map(static fn($row)=>array_intersect_key($row,array_flip(['nis','unit_id','kelas','tagihan','terbayar','sisa','_status','_incomplete'])),$report['rows']);}
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
try{
    foreach([1,2,3,0] as $unit){
        unit_set_context($koneksi,$unit);
        $filters=report_filters($koneksi,['template'=>'psb','siswa_status'=>'all']);
        $report=report_build($koneksi,'psb',$filters);
        $reference=report_build($koneksi,'per-item',report_filters($koneksi,['template'=>'per-item','kategori'=>'psb','siswa_status'=>'all']));
        psb_check(psb_positions($report)===psb_positions($reference),'PSB positions differ from official report, unit '.$unit);
        psb_check(array_column(array_filter($report['columns'],static fn($c)=>in_array($c[2]??'', ['money','money_optional'],true)),1)===['JUMLAH','U_PSB','SISA'],'Exactly three money columns');
        foreach($report['rows'] as $row){
            psb_check($row['sisa']===null||abs($row['sisa']-max(0,$row['tagihan']-$row['terbayar']))<.001,'Known remaining follows the official position');
            if($row['_incomplete'])psb_check($row['tagihan']===null&&$row['sisa']===null,'Unproven obligations must stay unknown');
        }
        $matrix=global_report_matrix($report,'psb',$unit);
        psb_check(count($matrix['rows'])===count($report['rows']),'No student rows lost');
        foreach($matrix['rows'] as $index=>$row)foreach(['tagihan','terbayar','sisa'] as $key)psb_check($row[$key]===$report['rows'][$index][$key],'Presentation changes amount');
        psb_check(global_report_print_orientation($matrix)==='portrait','PSB paper orientation');
        $caseQueries=[['siswa_status'=>'active'],['siswa_status'=>'archived'],['status'=>['cicilan','lunas']],['kategori'=>['spp','komite'],'tanggal_awal'=>'2000-01-01','tanggal_akhir'=>'2000-01-01','tahun_ajaran'=>['2020/2021','2026/2027']]];
        if($report['rows'])$caseQueries[]=['q'=>(string)$report['rows'][0]['nis']];
        if($report['rows'])$caseQueries[]=['kelas'=>['tingkat:'.(int)$report['rows'][0]['tingkat']]];
        foreach($caseQueries as $query){
            $f=report_filters($koneksi,array_replace(['template'=>'psb','siswa_status'=>'all'],$query));
            $actual=report_build($koneksi,'psb',$f);
            $refFilters=$f;$refFilters['_template']='per-item';$refFilters['kategori']='psb';unset($refFilters['_multi']['kategori'],$refFilters['_multi']['tahun_ajaran']);
            $ref=report_build($koneksi,'per-item',$refFilters);
            psb_check(psb_positions($actual)===psb_positions($ref),'Filter equivalence, unit '.$unit.' '.json_encode($query));
        }
        $active=report_build($koneksi,'psb',report_filters($koneksi,['template'=>'psb']));
        $models[(string)$unit]=['report'=>$active,'totals'=>report_money_totals($active,'psb')];
        file_put_contents($dir.'/model-progress.json',json_encode(['unit'=>$unit,'checks'=>$checks]));
        echo 'PASS unit '.$unit.': '.count($report['rows']).' positions, default/activity/status/class/search filters and category/date isolation'.PHP_EOL;
    }
    $same=[];foreach([1,2] as $u)$same[]=['unit_id'=>$u,'unit'=>unit_label($u),'nis'=>'00001234','nis_diknas'=>'0000009876','nama'=>'Nama sangat panjang <uji> Nusantara Indonesia','kelas'=>'1A','tagihan'=>$u===1?100.0:null,'terbayar'=>125.0,'sisa'=>$u===1?0.0:null,'_status'=>$u===1?'Lunas':'Perlu Rekonsiliasi','_incomplete'=>$u!==1];
    $fixture=['title'=>'Pembayaran PSB','subtitle'=>'Fixture','columns'=>[['kelas','Kelas','kelas'],['nis','No. Induk','nis'],['nama','Nama Siswa'],['tagihan','JUMLAH','money_optional'],['terbayar','U_PSB','money'],['sisa','SISA','money_optional']],'rows'=>$same];
    $matrix=global_report_matrix($fixture,'psb',0);
    psb_check(count($matrix['rows'])===2,'Same NIS across schools stays separate');
    psb_check($matrix['rows'][1]['tagihan']===null&&$matrix['rows'][1]['sisa']===null,'Nulls preserved in matrix');
    psb_check($matrix['subtotals']['tagihan']['value']===100.0&&$matrix['subtotals']['terbayar']['value']===250.0,'Known totals and credits remain distinct');
    $html=report_psb_print_document($fixture,0,'Fixture','03/11/2026','QA');
    psb_check(str_contains($html,'&lt;uji&gt;')&&!str_contains($html,'<uji>'),'Print escaping');
    psb_check(str_contains($html,'SD · 1A')&&str_contains($html,'SMP · 1A'),'Print school/class groups');
    psb_check(str_contains($html,'00001234')&&str_contains($html,'>—</td>'),'Leading zeros and unknown amounts in print');
    require_once __DIR__.'/../includes/excel.php';
    $doc=global_report_matrix_excel($matrix,$fixture,'psb',0);$book=spp_excel_workbook($doc);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($dir.'/fixture.xlsx');$book->disconnectWorksheets();
    $read=\PhpOffice\PhpSpreadsheet\IOFactory::load($dir.'/fixture.xlsx');$sheet=$read->getActiveSheet();$section=$doc['sheets'][0]['sections'][0];
    foreach($section['rows'] as $i=>$row)foreach($section['columns'] as $j=>$column){
        $cell=$sheet->getCellByColumnAndRow($j+1,$i+12);$value=$row[$column['key']]??null;
        if(in_array($column['type'],['money','money_optional','number'],true)&&$value!==null)psb_check($cell->getDataType()==='n'&&(float)$cell->getValue()===(float)$value,'Numeric XLSX roundtrip');
        else psb_check((string)$cell->getValue()===($value===null?'—':spp_excel_text($value)),'Text/null XLSX roundtrip');
    }
    $read->disconnectWorksheets();
    require_once __DIR__.'/../includes/pdf.php';require_pdf_library();
    $fixture['rows']=[];for($i=0;$i<150;$i++){$r=$same[$i%2];$r['nama'].=' '.$i;$r['tagihan']=123456789012.25;$r['terbayar']=100.0;$r['sisa']=$r['tagihan']-100;$fixture['rows'][]=$r;}
    $html=report_psb_print_document($fixture,0,'Multi-page fixture','03/11/2026','QA',true);
    $options=new \Dompdf\Options();$options->setDefaultMediaType('print');$pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();
    psb_check($pdf->getCanvas()->get_page_count()>1,'Long print spans pages');file_put_contents($dir.'/multipage.pdf',$pdf->output());file_put_contents($dir.'/multipage.html',$html);
    file_put_contents($dir.'/models.json',json_encode(['checks'=>$checks,'models'=>$models],JSON_THROW_ON_ERROR));
    echo 'PASS '.$checks.' PSB model, identity, unknown/overpayment, typed Excel and multipage PDF checks.'.PHP_EOL;
}finally{$koneksi->rollback();}
