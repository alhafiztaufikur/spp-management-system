<?php
/** Read-only comparisons against the source backup taken before the rejected renderer change. */
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('SPP_QA_DIR');if(!$root||!is_dir($root))throw new RuntimeException('Private QA directory required.');
$backup='C:/laragon/backups/spp-management-system/report_speed_20261018_201823/source-before.zip';
$fixture=json_decode(file_get_contents(getenv('SPP_SAVINGS_QA').'/rombel-fixture.json'),true);
if(($argv[1]??'')==='--baseline'){
    ini_set('session.save_path',getenv('SPP_QA_SESSION_PATH'));session_id('restore-baseline-'.bin2hex(random_bytes(6)));session_start();
    $_SESSION=['admin_id'=>$fixture['sessions']['super_admin']['actor'],'admin_role'=>'super_admin','admin_nama'=>'Super Admin','active_unit_id'=>(int)$argv[3]];
    require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);
    $template=$argv[2];$unit=(int)$argv[3];$_GET=['template'=>$template,'unit'=>(string)$unit,'format'=>'pdf','download'=>'1','tahun_ajaran'=>'2026/2027','bulan_awal'=>'10','bulan_akhir'=>'10','tahun'=>'2026','tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-18'];
    $zip=new ZipArchive();$zip->open($backup);$code=$zip->getFromName('laporan/export_global.php');$zip->close();
    $start=strpos($code,'if($format===\'pdf\'){$options=');if($start===false)throw new RuntimeException('Baseline render marker missing');
    $code=substr($code,0,$start).'file_put_contents('.var_export($root.'/baseline-'.$unit.'-'.$template.'.html',true).',$html); $options=new \Dompdf\Options();$options->set("isRemoteEnabled",false);$options->set("isHtml5ParserEnabled",true);$options->setDefaultMediaType("print");$options->setChroot(realpath(__DIR__."/.."));$pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,"UTF-8");$pdf->setPaper("A4",$printOrientation);$pdf->render();file_put_contents('.var_export($root.'/baseline-'.$unit.'-'.$template.'.pdf',true).',$pdf->output());';
    $code=str_replace(['session_start();','__DIR__'],['',var_export(realpath(__DIR__.'/../laporan'),true)],preg_replace('/^<\?php/','',$code,1));
    chdir(__DIR__.'/../laporan');eval($code);exit;
}
require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
require __DIR__.'/../includes/reports.php';$zip=new ZipArchive();$zip->open($backup);$checks=0;
function restore_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
foreach(['includes/global_report_layout.php','includes/global_report_matrix.php','includes/transaction_report_pdf.php','includes/authorization_pdf.php'] as $file)restore_check(file_get_contents(__DIR__.'/../'.$file)===$zip->getFromName($file),'Exact original layout source '.$file);
$function=static function($text){$start=strpos($text,'function spp_excel_workbook(');return substr($text,$start,strpos($text,'/** Only matrix previews freeze',$start)-$start);};restore_check($function(file_get_contents(__DIR__.'/../includes/excel.php'))===$function($zip->getFromName('includes/excel.php')),'Exact original workbook cells/styles/print setup');$zip->close();
foreach(['laporan/export_global.php','laporan/export_pdf.php','otorisasi_export_pdf.php'] as $file)restore_check(!str_contains(file_get_contents(__DIR__.'/../'.$file),'report_canvas_pdf'),'No alternate report renderer '.$file);
function restore_http(string $route,?array $form=null):array{global $fixture;$c=curl_init(getenv('SPP_TEST_BASE_URL').'/'.$route);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$fixture['sessions']['super_admin']['id'],CURLOPT_TIMEOUT=>180]);if($form!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);$raw=curl_exec($c);if($raw===false)throw new RuntimeException(curl_error($c));$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$len=curl_getinfo($c,CURLINFO_HEADER_SIZE);curl_close($c);return [$code,substr($raw,$len),substr($raw,0,$len)];}
foreach([1,2,3] as $unit){
    restore_http('unit_switch.php',['unit_id'=>$unit,'csrf_token'=>$fixture['sessions']['super_admin']['csrf'],'next'=>'/dashboard.php']);
    foreach(global_report_templates() as $template){
        $pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--baseline',$template,(string)$unit],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);restore_check(proc_close($proc)===0&&$error===''&&$out==='','Render source backup '.$unit.'/'.$template.': '.substr($out.$error,0,1200));
        $query=['template'=>$template,'unit'=>$unit,'format'=>'pdf','download'=>'1','tahun_ajaran'=>'2026/2027','bulan_awal'=>'10','bulan_akhir'=>'10','tahun'=>2026,'tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-18'];
        [$code,$bytes,$headers]=restore_http('laporan/export_global.php?'.http_build_query($query));restore_check($code===200&&str_starts_with($bytes,'%PDF-'),'Restored PDF '.$unit.'/'.$template);file_put_contents($root.'/restored-'.$unit.'-'.$template.'.pdf',$bytes);
        [$code,$again,$headers]=restore_http('laporan/export_global.php?'.http_build_query($query));restore_check($again===$bytes&&str_contains($headers,'X-Report-Cache: HIT'),'Unchanged legacy PDF reused');
    }
}
file_put_contents($root.'/restoration-http.json',json_encode(['checks'=>$checks],JSON_PRETTY_PRINT));echo "PASS $checks original-source/HTTP/cache checks\n";
