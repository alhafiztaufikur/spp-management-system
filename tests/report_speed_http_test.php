<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
require __DIR__.'/../includes/auth.php';require __DIR__.'/../includes/reports.php';require __DIR__.'/../includes/global_report_matrix.php';require __DIR__.'/../includes/excel.php';
$root=getenv('SPP_QA_DIR');$fixture=json_decode(file_get_contents(getenv('SPP_SAVINGS_QA').'/rombel-fixture.json'),true);$checks=0;$expected=[];$timings=[];
function sh_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function sh_http(string $route,?array $form=null,string $role='super_admin'):array{
    global $fixture;$c=curl_init(getenv('SPP_TEST_BASE_URL').'/'.$route);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$fixture['sessions'][$role]['id'],CURLOPT_TIMEOUT=>90]);
    if($form!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);$start=microtime(true);$raw=curl_exec($c);if($raw===false)throw new RuntimeException(curl_error($c));$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$length=curl_getinfo($c,CURLINFO_HEADER_SIZE);curl_close($c);$headers=[];foreach(explode("\r\n",substr($raw,0,$length)) as $line)if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$headers[strtolower($key)]=trim($value);}
    $body=substr($raw,$length);sh_check(!preg_match('/Fatal error|Warning:|Parse error|SQLSTATE/',$body),'Runtime '.$route);return [$code,$body,$headers,round((microtime(true)-$start)*1000,2)];
}
function sh_switch(int $unit):void{global $fixture;[$status]=sh_http('unit_switch.php',['unit_id'=>$unit,'csrf_token'=>$fixture['sessions']['super_admin']['csrf'],'next'=>'/dashboard.php']);sh_check($status===303,'Switch scope');}
function sh_fields(string $html,string $action):array{$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);$form=$xp->query('//form[.//input[@name="action"][@value="'.$action.'"]]')->item(0);if(!$form)throw new RuntimeException('Missing correction form '.$action);$fields=[];foreach($form->getElementsByTagName('input') as $i)if($i->getAttribute('name')!=='')$fields[$i->getAttribute('name')]=$i->getAttribute('value');return $fields;}
$_SESSION=['admin_id'=>$fixture['sessions']['super_admin']['actor'],'admin_role'=>'super_admin','active_unit_id'=>1];sh_switch(1);unit_set_context($koneksi,1);
// Real entry/edit/archive lifecycle, confined to the private clone. Final deletion restores the balance.
$student=$fixture['units'][1];$balance=(float)$koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='".$student['nis']."'")->fetch_row()[0];
$live=['template'=>'tabungan-siswa','unit'=>1,'student_id'=>$student['student_id'],'student_unit'=>1,'tanggal_awal'=>date('Y-m-d'),'tanggal_akhir'=>date('Y-m-d'),'format'=>'pdf','download'=>0];$route='laporan/export_global.php?'.http_build_query($live);
[$code,$bytes,$before]=sh_http($route);[$code,$bytes,$hit]=sh_http($route);sh_check(($hit['x-report-cache']??'')==='HIT','Unchanged live data cached');
[$code,$html]=sh_http('tabungan/masuk.php?nis='.$student['nis']);$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);$post=[];foreach($xp->query('//form[@id="form-tabungan"]//input[@name]') as $input)$post[$input->getAttribute('name')]=$input->getAttribute('value');$post['no_induk']=$student['nis'];$post['nominal']='12345';$post['tanggal']=date('Y-m-d');$post['keterangan']='QA report freshness '.bin2hex(random_bytes(3));
[$code,$body,$headers]=sh_http('tabungan/proses.php',$post);sh_check($code===302&&str_contains($headers['location']??'','id='),'Real deposit created');parse_str(parse_url($headers['location'],PHP_URL_QUERY),$saved);$newId=(int)$saved['id'];
try{
    [$code,$bytes,$created]=sh_http($route);sh_check($created['x-report-fingerprint']!==$hit['x-report-fingerprint'],'New transaction invalidates PDF');file_put_contents($root.'/fresh-created.pdf',$bytes);
    [$code,$html]=sh_http('tabungan/riwayat.php?jenis=masuk&id='.$newId);$edit=sh_fields($html,'direct_edit');$edit['nominal']='23456';$edit['keterangan']='QA report freshness corrected';$edit['reason']='Pengujian cache setelah koreksi transaksi';[$code]=sh_http('tabungan/koreksi.php',$edit);sh_check($code===303,'Real correction applied');
    [$code,$bytes,$edited]=sh_http($route);sh_check($edited['x-report-fingerprint']!==$created['x-report-fingerprint'],'Correction invalidates PDF');file_put_contents($root.'/fresh-edited.pdf',$bytes);
}finally{
    [$code,$html]=sh_http('tabungan/riwayat.php?jenis=masuk&id='.$newId);$delete=sh_fields($html,'direct_hapus');$delete['reason']='Akhir pengujian cache pada database terpisah';[$code]=sh_http('tabungan/koreksi.php',$delete);sh_check($code===303,'QA deposit archived');
}
[$code,$bytes,$deleted]=sh_http($route);sh_check($deleted['x-report-fingerprint']!==$edited['x-report-fingerprint'],'Deletion invalidates PDF');file_put_contents($root.'/fresh-deleted.pdf',$bytes);
sh_check((float)$koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='".$student['nis']."'")->fetch_row()[0]===$balance,'QA balance restored after archive');
foreach([1,2,3,0] as $unit){
    $_SESSION['active_unit_id']=$unit?:1;unit_set_context($koneksi,$unit);if($unit)sh_switch($unit);
    foreach(global_report_templates() as $template){
        $query=['template'=>$template,'unit'=>$unit?:'all','tahun_ajaran'=>'2026/2027','bulan_awal'=>'10','bulan_akhir'=>'10','tahun'=>2026,'tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-18'];
        $filters=report_filters($koneksi,$query);if($template==='penerimaan')$filters['kategori']='semua';if($template==='riwayat-tagihan')$filters['siswa_status']='all';
        $report=report_build($koneksi,$template,$filters);$matrix=global_report_matrix($report,$template,$unit);$printMatrix=$matrix;
        if(!$printMatrix&&!in_array($template,['setoran','kas-tabungan'],true)){
            if($template==='riwayat-tagihan'){$students=report_billing_history_group_students($report['rows']);$printMatrix=global_report_billing_print_matrix($students,report_billing_history_component_columns($students),$unit);}
            else{$printMatrix=global_report_flat_print_matrix($report);$printMatrix['totals']=report_money_totals($report,$template);}
        }
        $excelDoc=$matrix?global_report_matrix_excel($matrix,$report,$template,$unit):spp_excel_report_document($report,$template,$unit);$excelMoney=[];
        foreach($excelDoc['sheets'] as $sheet)foreach($sheet['sections'] as $section){
            foreach($section['rows'] as $row)foreach($section['columns'] as $column){$v=$row[$column['key']]??null;
                if(in_array($column['type'],['money','money_optional'],true)&&$v!==null&&$v!=='')$excelMoney[]=report_money($v);
                elseif(!in_array($column['type'],['money','money_optional','number','count','date','datetime'],true)){preg_match_all('/Rp\s*-?[0-9.]+/',spp_excel_text($v),$matches);foreach($matches[0] as $money)$excelMoney[]=$money;}}
            foreach($section['column_totals']??[] as $total)$excelMoney[]=report_money($total['value']);foreach($section['totals'] as $total)$excelMoney[]=report_money($total['value']);
        }
        $expected[$unit][$template]=['report'=>$report,'matrix'=>$printMatrix,'bands'=>$printMatrix?global_report_matrix_bands($printMatrix,global_report_print_orientation($printMatrix)):[],'excel_money'=>$excelMoney];
        foreach(['pdf','excel'] as $format){$url='laporan/export_global.php?'.http_build_query($query+['format'=>$format,'download'=>1]);
            [$code,$bytes,$headers,$ms]=sh_http($url);sh_check($code===200&&str_starts_with($bytes,$format==='pdf'?'%PDF-':'PK'),'Valid '.$template.' '.$format);$timings[$unit][$template][$format]['first']=$ms;file_put_contents($root.'/global-'.$unit.'-'.$template.'.'.($format==='pdf'?'pdf':'xlsx'),$bytes);
            [$code,$again,$cached,$ms]=sh_http($url);sh_check(($cached['x-report-cache']??'')==='HIT'&&$again===$bytes,'Reused file exactly matches '.$template.' '.$format);$timings[$unit][$template][$format]['hit']=$ms;
        }
        [$code,$html]=sh_http('laporan/template.php?'.http_build_query($query));$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);foreach($xp->query('//select[@name="operator"]//option') as $option)sh_check(!str_contains($option->textContent,'(@'),'No username in Global operator choice');
    }
}
file_put_contents($root.'/global-expected.json',json_encode($expected,JSON_THROW_ON_ERROR));file_put_contents($root.'/http-verification.json',json_encode(['checks'=>$checks,'timings'=>$timings],JSON_PRETTY_PRINT));echo "PASS $checks Global HTTP/live-data checks\n";
