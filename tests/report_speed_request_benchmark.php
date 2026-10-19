<?php
/** Five cold/warm end-to-end requests; only owned private QA artifacts are removed. */
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
require __DIR__.'/../includes/reports.php';
$root=getenv('SPP_QA_DIR');$fixture=json_decode(file_get_contents(getenv('SPP_SAVINGS_QA').'/rombel-fixture.json'),true);$results=[];
function rb_request(string $route,?array $form=null):array {
    global $fixture;$c=curl_init(getenv('SPP_TEST_BASE_URL').'/'.$route);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$fixture['sessions']['super_admin']['id'],CURLOPT_TIMEOUT=>120]);
    if($form!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);
    $start=microtime(true);$raw=curl_exec($c);if($raw===false)throw new RuntimeException(curl_error($c));$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$length=curl_getinfo($c,CURLINFO_HEADER_SIZE);curl_close($c);$headers=[];
    foreach(explode("\r\n",substr($raw,0,$length)) as $line)if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$headers[strtolower($key)]=trim($value);}
    if($code!==200&&$form===null)throw new RuntimeException('HTTP '.$code.' '.$route);return ['ms'=>round((microtime(true)-$start)*1000,2),'headers'=>$headers,'size'=>strlen($raw)-$length];
}
rb_request('unit_switch.php',['unit_id'=>1,'csrf_token'=>$fixture['sessions']['super_admin']['csrf'],'next'=>'/dashboard.php']);
$common=['unit'=>1,'tahun_ajaran'=>'2026/2027','bulan_awal'=>'10','bulan_akhir'=>'10','tahun'=>2026,'tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-18'];$cases=[];
foreach(global_report_templates() as $template)foreach(['pdf','excel'] as $format)$cases[$template.'-'.$format]='laporan/export_global.php?'.http_build_query(['template'=>$template,'format'=>$format,'download'=>1]+$common);
$cases['audit-pdf']='otorisasi_export_pdf.php?'.http_build_query(['q'=>$fixture['units'][1]['nis'],'output'=>'inline']);
$cases['audit-all-pdf']='otorisasi_export_pdf.php?output=inline';
$cases['rekap-pdf']='laporan/export_pdf.php?'.http_build_query(['jenis_laporan'=>['*'],'output'=>'inline']+$common);
$cases['rekap-excel']='laporan/export_excel.php?'.http_build_query(['jenis_laporan'=>['*'],'download'=>1]+$common);
$cases['global-wide-pdf']='laporan/export_global.php?'.http_build_query(['template'=>'status','format'=>'pdf','download'=>1,'kategori'=>['*'],'bulan_awal'=>['*'],'tahun_ajaran'=>['2026/2027','2027/2028'],'unit'=>'all']+$common);
$cases['global-wide-excel']=str_replace('format=pdf','format=excel',$cases['global-wide-pdf']);
if(isset($argv[1]))$cases=array_intersect_key($cases,array_fill_keys(explode(',',$argv[1]),true));
$resultPath=$root.'/request-benchmark.json';if(isset($argv[1])&&is_file($resultPath))$results=json_decode(file_get_contents($resultPath),true);
foreach($cases as $name=>$route){
    $results[$name]=[];
    $probe=rb_request($route);$key=$probe['headers']['x-report-fingerprint']??'';if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('No artifact key');
    for($i=0;$i<5;$i++){
        $cache=realpath($root.'/cache');if(!$cache||str_contains(strtolower(str_replace('\\','/',$cache)),'/www/'))throw new RuntimeException('Private cache required');
        foreach(glob($cache.'/*/'.$key.'.*')?:[] as $path)if(!is_link($path)&&in_array(pathinfo($path,PATHINFO_EXTENSION),['pdf','xlsx','json'],true))unlink($path);
        $cold=rb_request($route);$warm=rb_request($route);
        if(($cold['headers']['x-report-cache']??'')!=='MISS'||($warm['headers']['x-report-cache']??'')!=='HIT')throw new RuntimeException('Cold/warm mismatch');
        if($cold['headers']['x-report-fingerprint']!==$warm['headers']['x-report-fingerprint'])throw new RuntimeException('Unexpected data revision');
        if(!str_contains($cold['headers']['server-timing']??'','query;dur='))throw new RuntimeException('SQL/model phases not measured');
        $results[$name]['cold'][]=$cold;$results[$name]['warm'][]=$warm;
    }
    $median=static function($runs){$ms=array_column($runs,'ms');sort($ms);return $ms[2];};echo $name.': '.$median($results[$name]['cold']).' / '.$median($results[$name]['warm'])." ms\n";
    file_put_contents($resultPath,json_encode($results,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
}
file_put_contents($root.'/request-benchmark.json',json_encode($results,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'PASS five cold/warm requests for '.count($cases).' routes'.PHP_EOL;
