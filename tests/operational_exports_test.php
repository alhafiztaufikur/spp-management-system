<?php
/** Real PDF/XLSX responses, reconciled against the unchanged scoped report model. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/global_report_matrix.php';require_once __DIR__.'/../includes/excel.php';require_once __DIR__.'/excel_test_helpers.php';
$root=getenv('SPP_OPERATIONS_QA');$fixture=json_decode(file_get_contents($root.'/ui-fixture.json'),true,512,JSON_THROW_ON_ERROR);$dir=$root.'/exports';if(!is_dir($dir))mkdir($dir,0700);
$session=$fixture['sessions']['kasir'];$checks=0;$manifest=[];
function export_assert(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function export_http(string $path,?array $post=null):array{
    global $session;$curl=curl_init(rtrim(getenv('SPP_TEST_BASE_URL'),'/').'/'.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIE=>'PHPSESSID='.$session['id'],CURLOPT_TIMEOUT=>90]);if($post!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);if($body===false)throw new RuntimeException($error);return [$code,$body];
}
foreach([1,2,3,0] as $unit){
    export_http('unit_switch.php',['unit_id'=>(string)$unit,'csrf_token'=>$session['csrf'],'next'=>'/laporan/global.php']);
    $_SESSION=['admin_role'=>'kasir','active_unit_id'=>$unit,'admin_nama'=>'Uji keluaran'];unit_set_context($koneksi,$unit);
    foreach(array_keys(report_registry()) as $template){
        $params=['template'=>$template,'unit'=>$unit===0?'all':'active','tanggal_awal'=>'2026-01-01','tanggal_akhir'=>'2026-12-31','tahun_ajaran'=>'2026/2027','bulan_awal'=>'10','bulan_akhir'=>'10','tahun_awal'=>'2026','tahun_akhir'=>'2026','siswa_status'=>'all','q'=>$fixture['nis']];
        if(in_array($template,['status','per-item'],true))$params['kategori']=['spp','komite','psb'];
        if($template==='penerimaan')$params['kategori']='semua';
        $filters=report_filters($koneksi,$params);$report=report_build($koneksi,$template,$filters);$matrix=global_report_matrix($report,$template,$unit);
        $doc=$matrix?global_report_matrix_excel($matrix,$report,$template,$unit):spp_excel_report_document($report,$template,$unit);
        $prefix=$unit.'-'.$template;
        [$code,$binary]=export_http('laporan/export_global.php?'.http_build_query($params+['format'=>'excel','download'=>'1']));export_assert($code===200&&str_starts_with($binary,'PK'),'XLSX failed '.$prefix);file_put_contents($dir.'/'.$prefix.'.xlsx',$binary);
        $zip=new ZipArchive();$zip->open($dir.'/'.$prefix.'.xlsx');$xml=new SimpleXMLElement($zip->getFromName('xl/worksheets/sheet1.xml'));$zip->close();
        foreach($xml->colBreaks->brk??[] as $break)export_assert((int)$break['id']>0,'Invalid column break '.$prefix);
        $book=test_excel_read($binary);$sheet=$book->getActiveSheet();$n=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        export_assert($sheet->getPageSetup()->getPaperSize()===9&&$sheet->getPageSetup()->getScale()===100&&!$sheet->getPageSetup()->getFitToPage(),'Print setup '.$prefix);
        foreach($doc['sheets'][0]['sections'] as $section){
            $header=null;$count=count($section['columns']);
            for($r=9;$r<=$sheet->getHighestDataRow();$r++){
                $same=true;foreach($section['columns'] as $i=>$column){$c=1+(int)floor($i*$n/$count);if($sheet->getCellByColumnAndRow($c,$r)->getValue()!==$column['label']){$same=false;break;}}
                if($same){$header=$r;break;}
            }
            export_assert($header!==null,'Headers '.$prefix.'/'.$section['title']);
            foreach($section['rows'] as $offset=>$row)foreach($section['columns'] as $i=>$column){
                $cell=$sheet->getCellByColumnAndRow(1+(int)floor($i*$n/$count),$header+1+$offset);$expected=array_key_exists($column['key'],$row)?$row[$column['key']]:'';$actual=$cell->getValue();
                if(in_array($column['type'],['money','money_optional','number','count'],true)&&$expected!==null&&$expected!=='')export_assert(is_numeric($actual)&&abs((float)$actual-(float)$expected)<.001,'Numeric value '.$prefix.'/'.$column['key']);
                elseif(!in_array($column['type'],['date','datetime'],true))export_assert((string)$actual===($expected===null?'—':spp_excel_text($expected)),'Text value '.$prefix.'/'.$column['key']);
                if(in_array($column['key'],['nis','nis_diknas','NO_INDUK','NO_induk_diknas'],true)&&$expected!==null&&$expected!=='')export_assert($cell->getDataType()==='s','NIS type '.$prefix);
            }
            foreach($section['column_totals']??[] as $key=>$total){$index=array_search($key,array_column($section['columns'],'key'),true);$cell=$sheet->getCellByColumnAndRow($index+1,$header+1+count($section['rows']));export_assert(abs((float)$cell->getValue()-(float)$total['value'])<.001,'Subtotal '.$prefix.'/'.$key);}
        }
        $book->disconnectWorksheets();
        [$code,$binary]=export_http('laporan/export_global.php?'.http_build_query($params+['format'=>'pdf','download'=>'1']));export_assert($code===200&&str_starts_with($binary,'%PDF-'),'PDF failed '.$prefix);file_put_contents($dir.'/'.$prefix.'.pdf',$binary);
        [$code,$preview]=export_http('laporan/export_global.php?'.http_build_query($params+['format'=>'preview']));export_assert($code===200&&str_contains($preview,'iframe'),'PDF preview '.$prefix);
        $manifest[$prefix]=['rows'=>count($matrix['rows']??$report['rows']),'title'=>$report['title']];echo 'PASS '.$prefix.' PDF/preview/XLSX source values'.PHP_EOL;
    }
}
export_http('unit_switch.php',['unit_id'=>'2','csrf_token'=>$session['csrf'],'next'=>'/laporan/index.php']);
foreach(['laporan/export_pdf.php?bulan=10&tahun=2026&output=pdf&q='.$fixture['nis'],'laporan/export_pdf.php?mode=selected&ids[]='.$fixture['payments'][2].'&output=pdf','laporan/export_excel.php?bulan=10&tahun=2026&q='.$fixture['nis'].'&download=1'] as $i=>$url){[$code,$body]=export_http($url);export_assert($code===200&&str_starts_with($body,$i===2?'PK':'%PDF-'),'General output '.$i);file_put_contents($dir.'/general-'.$i.($i===2?'.xlsx':'.pdf'),$body);}
file_put_contents($dir.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'PASS '.$checks.' export checks'.PHP_EOL;
