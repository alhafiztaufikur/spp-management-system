<?php
/** Presentation invariants and typed readback on a disposable audit clone. */
if(PHP_SAPI!=='cli'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/global_report_matrix.php';
require_once __DIR__.'/../includes/excel.php';
require_once __DIR__.'/excel_test_helpers.php';
function matrix_assert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$dir=(string)getenv('SPP_QA_DIR');$baseline=json_decode(file_get_contents($dir.'/baseline.json'),true,512,JSON_THROW_ON_ERROR);
$_SESSION=['admin_role'=>'super_admin','active_unit_id'=>1,'admin_nama'=>'Pengujian'];
$columns=[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['tagihan','Tagihan','money'],['terbayar','Terbayar','money'],['sisa','Sisa','money'],['status','Status','status']];
$base=['nis'=>'SAME','nama'=>'Siswa','kelas'=>'1A','unit_id'=>1,'tagihan'=>0,'terbayar'=>0,'sisa'=>0,'status'=>'Tidak Ditagihkan'];
$a=['title'=>'SPP','subtitle'=>'Oktober','columns'=>$columns,'rows'=>[$base,array_replace($base,['tagihan'=>10]),array_replace($base,['kelas'=>'2A','tagihan'=>20]),array_replace($base,['unit_id'=>2,'tagihan'=>30]),array_replace($base,['nis'=>'ONLY-A','tagihan'=>50])]];
$b=$a;$b['title']='Komite';$b['rows']=[$base,array_replace($base,['tagihan'=>40]),array_replace($base,['tagihan'=>45])];
$source=['title'=>'Uji','subtitle'=>'','columns'=>$columns,'sections'=>[$a,$b],'rows'=>array_merge($a['rows'],$b['rows'])];
$matrix=global_report_matrix($source,'status',0);
matrix_assert(count($matrix['rows'])===6,'Unit, historical class and continuation rows must remain distinct');
matrix_assert($matrix['rows'][0]['g0_tagihan']===0&&$matrix['rows'][0]['g0_status']==='Tidak Ditagihkan','Zero/unbilled must remain exact');
matrix_assert($matrix['rows'][2]['g1_tagihan']===null&&$matrix['rows'][5]['g0_tagihan']===null,'Missing group is distinct from zero');
matrix_assert($matrix['rows'][1]['g0_tagihan']===10&&$matrix['rows'][1]['g1_tagihan']===40&&$matrix['rows'][5]['g1_tagihan']===45,'Duplicate rows must not overwrite or aggregate');
matrix_assert(global_report_matrix($source,'saldo-tabungan',0)===null,'Special reports must not pivot');
$checked=0;$workbooks=0;
foreach($baseline['models'] as $key=>$model){
    [$unit,$template]=explode('/',$key);$template= $baseline['cases'][$key]['template'];$unit=(int)$unit;
    $report=$model['report'];$before=$report;$matrix=global_report_matrix($report,$template,$unit);
    matrix_assert($report===$before,'Adapter mutated source');if(!$matrix)continue;
    $parts=$report['sections']??[$report];$seen=[];
    foreach($matrix['rows'] as $row)foreach($row['_sources'] as $group=>$index){
        matrix_assert(!isset($seen[$group][$index]),'Source row mapped twice');$seen[$group][$index]=true;
        $source=$parts[$group]['rows'][$index];
        matrix_assert($row['nis']===$source['nis']&&$row['kelas']===($source['kelas_asli']??$source['kelas']),'Identity changed');
        foreach($matrix['groups'] as $definition)if($definition['source']===$group)foreach($definition['columns'] as $column)matrix_assert($row[$column[0]]===($source[$column['source']]??null),'Mapped value changed: '.$key.' '.$column[0]);
    }
    foreach($parts as $group=>$part){matrix_assert(count($seen[$group]??[])===count($part['rows']),'Source rows lost: '.$key);$totals=array_column(report_money_totals($part,$template),null,'key');foreach($matrix['groups'] as $definition)if($definition['source']===$group)foreach($definition['columns'] as $column)if(isset($totals[$column['source']]))matrix_assert($matrix['subtotals'][$column[0]]===$totals[$column['source']],'Subtotal changed');}
    matrix_assert($matrix['totals']===$model['totals'],'Grand totals changed');
    foreach(['portrait','landscape'] as $orientation){$bandKeys=[];foreach(global_report_matrix_bands($matrix,$orientation) as $band){ $bandWidth=0;foreach(array_slice($matrix['columns'],0,$matrix['identity_count']) as $column)$bandWidth+=global_report_column_width($column,true,$matrix['rows'])+10;foreach($band as $group)foreach($group['columns'] as $column){$bandKeys[]=$column[0];$bandWidth+=global_report_column_width($column,true,$matrix['rows'])+10;}matrix_assert($bandWidth<=($orientation==='landscape'?785:538),'Print band exceeds usable A4 width'); }matrix_assert($bandKeys===array_column(array_slice($matrix['columns'],$matrix['identity_count']),0),'Print bands lose or reorder columns');}
    $doc=global_report_matrix_excel($matrix,$report,$template,$unit);$book=spp_excel_workbook($doc);$file=$dir.'/matrix-'.str_replace('/','-',$key).'.xlsx';(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);$book->disconnectWorksheets();
    $read=test_excel_read(file_get_contents($file));$sheet=$read->getActiveSheet();$section=$doc['sheets'][0]['sections'][0];
    $coordinate=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::class;
    matrix_assert($sheet->getFreezePane()===$coordinate::stringFromColumnIndex($section['identity_count']+1).'12','Freeze identity and both headers');
    foreach($section['rows'] as $i=>$row)foreach($section['columns'] as $j=>$column){
        $cell=$sheet->getCellByColumnAndRow($j+1,$i+12);$value=$row[$column['key']]??null;
        if(in_array($column['type'],['money','money_optional','number'],true)&&$value!==null)matrix_assert($cell->getDataType()==='n'&&(float)$cell->getValue()===(float)$value,'Numeric matrix Excel value');
        else matrix_assert((string)$cell->getValue()===($value===null?'—':spp_excel_text($value)),'Text matrix Excel value');
    }
    $subtotalRow=12+max(1,count($section['rows']));foreach($section['columns'] as $j=>$column)if(isset($section['column_totals'][$column['key']]))matrix_assert((float)$sheet->getCellByColumnAndRow($j+1,$subtotalRow)->getValue()===(float)$section['column_totals'][$column['key']]['value'],'Excel subtotal placement');
    $read->disconnectWorksheets();$checked++;$workbooks++;
}
// Exercise all categories and periods against real model data, without modifying it.
foreach([1,2,3,0] as $unit){unit_set_context($koneksi,$unit);foreach(['status','penerimaan','per-item','spp-tahunan'] as $template){
    $params=['template'=>$template,'kategori'=>['*'],'tahun_ajaran'=>array_slice(array_column(report_years($koneksi),'label'),0,2),'bulan_awal'=>$template==='status'?['09','10']:'07','bulan_akhir'=>'06','tahun_awal'=>'2026','tahun_akhir'=>'2027','tanggal_awal'=>'2026-09-01','tanggal_akhir'=>'2026-10-10','siswa_status'=>'all'];
    $filters=report_filters($koneksi,$params);$report=report_build($koneksi,$template,$filters);$before=$report;$matrix=global_report_matrix($report,$template,$unit);matrix_assert($report===$before,'All-component adapter mutation');
    $sourceCount=array_sum(array_map(static fn($part)=>count($part['rows']),$report['sections']??[$report]));$mappedCount=array_sum(array_map(static fn($row)=>count($row['_sources']),$matrix['rows']));matrix_assert($sourceCount===$mappedCount,'All-component rows lost');$checked++;
}}
$legacy=array_values($baseline['models'])[0]['doc'];$legacy['generated']='11/10/2026 00:00:00 WIB';$book=spp_excel_workbook($legacy);matrix_assert($book->getActiveSheet()->getFreezePane()==='B11','Unrelated Excel layout changed');$book->disconnectWorksheets();
echo 'PASS: '.$checked.' matrix cases, '.$workbooks.' typed XLSX readbacks, source values/subtotals conserved; duplicate identities, class history, missing/zero/unbilled, all components/periods and print bands'.PHP_EOL;
